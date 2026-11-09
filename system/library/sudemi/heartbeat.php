<?php
/**
 * Telling sudemi this shop is still here, and still means its prices.
 *
 * sudemi cannot call this shop: it sits behind this host's firewall and accepts no connection
 * from outside. So everything starts here, which makes this the only two-way channel the
 * integration has - the request says what this shop believes its catalogue to be, and the reply
 * is where sudemi asks for whatever it needs.
 *
 * Why it exists: from sudemi's side a shop with nothing to report and a shop whose server died
 * last Tuesday look identical, and only one of them still has prices worth quoting. If silence
 * were read as agreement, a dead shop would keep advertising last week's prices and taking
 * orders at them. So this shop says so, and sudemi stops listing it when it stops saying so.
 * The listings return on the next beat; nothing is deleted.
 *
 * The revision catches the quiet failure. A delta push can be lost - sudemi restarting, a DNS
 * blip, a proxy - and liveness notices nothing, because the shop is healthy and the beats keep
 * arriving. Stating what this catalogue is lets sudemi disagree, and a disagreement costs one
 * full sync instead of weeks of a wrong price.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

require_once __DIR__ . '/withdrawal.php';
require_once __DIR__ . '/requests.php';

class Heartbeat
{
    const SETTING_STATUS = 'sudemi_status';
    const SETTING_INTERVAL = 'sudemi_heartbeat_seconds';
    const SETTING_CHANGES = 'sudemi_change_counter';
    const SETTING_CRON_TOKEN = 'sudemi_cron_token';

    /** What this shop asks for. sudemi may hold it to something slower, and says so if it does. */
    const DEFAULT_INTERVAL = 300;

    private $store;
    private $client;
    private $logger;
    private $queue;
    private $catalogue;
    private $pairing;
    private $sync;
    private $orders;

    public function __construct($store, Client $client, Logger $logger, Queue $queue,
                                $catalogue, Pairing $pairing, Sync $sync, $orders = null)
    {
        $this->orders = $orders;
        $this->store = $store;
        $this->client = $client;
        $this->logger = $logger;
        $this->queue = $queue;
        $this->catalogue = $catalogue;
        $this->pairing = $pairing;
        $this->sync = $sync;
    }

    /**
     * Runs a beat, draining anything that is waiting first.
     *
     * Drained first so the revision this beat reports is the one that holds once the backlog has
     * gone in: reporting a revision sudemi cannot match yet would ask for a full sync the queue
     * was about to make unnecessary.
     *
     * @return bool whether sudemi answered
     */
    public function run()
    {
        if (!$this->client->isConnected()) {
            return false;
        }

        $drained = $this->queue->drain();

        $response = $this->client->postSigned('/integration/v1/heartbeat', [
            'catalogueRevision' => $this->catalogue->revision($this->changes()),
            'productCount' => $this->catalogue->productCount(),
            'intervalSeconds' => self::DEFAULT_INTERVAL,
            'pluginVersion' => Client::VERSION,
        ]);

        if ($response === null) {
            $this->remember(['reachable' => false, 'checked' => time()]);

            return false;
        }

        if ($response['status'] === 401) {
            // Revoked from the portal, or this shop was re-paired elsewhere. Said plainly,
            // because the fix is to connect again and no amount of retrying will do it.
            $this->remember([
                'reachable' => true,
                'connected' => false,
                'message' => 'sudemi no longer recognises this shop. Connect it again.',
                'checked' => time(),
            ]);

            return true;
        }

        $paired = Client::siteMismatch($response);
        if ($paired !== null) {
            // This site holds the shop's install id and key but is not the shop sudemi knows: a
            // staging copy, a restored backup, or the shop moved. Refused on purpose, so retrying
            // cannot help. The admin page words it in the admin's language (problem/paired).
            $this->remember([
                'reachable' => true,
                'connected' => false,
                'problem' => 'site_mismatch',
                'paired' => $paired,
                'message' => 'sudemi refused requests from this site: this shop is connected to sudemi under a different address.',
                'checked' => time(),
            ]);

            return true;
        }

        if ($response['status'] !== 200) {
            $this->remember([
                'reachable' => true,
                'message' => 'sudemi answered ' . (int) $response['status'] . '.',
                'checked' => time(),
            ]);

            return true;
        }

        $body = $response['body'];

        // A fresh ownership token. sudemi re-proves the domain on a schedule because a listing
        // names it to buyers as this shop's identity, and domains change hands.
        if (!empty($body['domain_challenge']['token'])) {
            $this->pairing->storeChallenge((string) $body['domain_challenge']['token']);
        }

        if (!empty($body['heartbeat_seconds'])) {
            $this->store->write(self::SETTING_INTERVAL, (int) $body['heartbeat_seconds']);
        }

        $this->remember([
            'reachable' => true,
            'connected' => true,
            'listed' => !empty($body['catalogue_listed']),
            'reason' => isset($body['not_listed_reason']) ? (string) $body['not_listed_reason'] : '',
            'trusted_until' => isset($body['trusted_until']) ? (string) $body['trusted_until'] : '',
            'pending' => $this->queue->count(),
            'drained' => (int) $drained,
            'checked' => time(),
        ]);

        // Return requests this shop has to answer: the full list on every beat (absent when there
        // are none), so the stored copy is replaced, and cleared when the key is missing.
        Requests::remember($this->store, isset($body['open_requests']) ? $body['open_requests'] : array());

        // Orders whose buyers are waiting on an answer from this shop. Before the full sync
        // below, because each one is money held on someone's card against a deadline, while a
        // catalogue disagreement costs nothing by waiting another five minutes.
        if (!empty($body['pending_orders']) && $this->orders !== null) {
            $this->orders->handlePending($body['pending_orders']);
        }

        // Orders this shop accepted while the buyer's payment still covered other shops: now
        // paid (ship them) or released after all (put the stock back).
        if (!empty($body['settled_orders']) && $this->orders !== null) {
            $this->orders->handleSettled($body['settled_orders']);
        }

        if ($this->orders !== null) {
            // Local orders still unconfirmed that this beat did not mention: an accept whose
            // answer was lost or not given (5xx, 429) is asked again, so a paid order is never
            // left unconfirmed and a released one gets its stock back. Then the shipped and
            // delivered reports (and shop cancellations) that did not get through before.
            $skip = array();
            foreach (array('pending_orders', 'settled_orders') as $list) {
                if (!empty($body[$list]) && is_array($body[$list])) {
                    foreach ($body[$list] as $entry) {
                        if (is_array($entry) && !empty($entry['order_id'])) {
                            $skip[] = (string) $entry['order_id'];
                        }
                    }
                }
            }
            $this->orders->reconcile($skip);
            $this->orders->drainCalls();
        }

        // sudemi and this shop disagree about what the catalogue is, so something did not
        // arrive. A full sync is the only answer that does not involve guessing which product.
        // Asked for here, never run here: a full sync of a large catalogue takes minutes, and a
        // beat also runs after admin page loads. The cron entry runs it (or, without cron, small
        // steps after admin page loads; see Sync::work). While one is being sent, sudemi asks
        // because it has not arrived yet: not asked again.
        if (!empty($body['full_sync_requested']) && !$this->sync->inProgress()) {
            if (!$this->store->read(Withdrawal::SETTING_RESYNC_DUE)) {
                $this->logger->info('sudemi asked for a full sync.');
            }
            $this->store->write(Withdrawal::SETTING_RESYNC_DUE, 1);
        }

        return true;
    }

    /** True when the last beat is older than the interval sudemi is holding this shop to. */
    public function isDue()
    {
        $status = $this->status();
        $last = isset($status['checked']) ? (int) $status['checked'] : 0;

        return (time() - $last) >= $this->interval();
    }

    public function interval()
    {
        $configured = (int) $this->store->read(self::SETTING_INTERVAL);

        return $configured > 0 ? $configured : self::DEFAULT_INTERVAL;
    }

    public function status()
    {
        $raw = $this->store->read(self::SETTING_STATUS);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A whole full sync now (the "send the catalogue now" button). Its commit carries the
     * revision taken before the sync read the catalogue, and a heartbeat with it follows at
     * once; see Sync::commit().
     *
     * @return bool|string what Sync::runFull returned
     */
    public function syncAnchored()
    {
        return $this->sync->runFull();
    }

    /**
     * How many product changes this shop has seen since the extension was installed.
     *
     * Monotonic and never reset: the value is meaningless, only its movement matters. Reset it
     * and an old revision could be reproduced, which is exactly the agreement we must not fake.
     */
    public function changes()
    {
        return (int) $this->store->read(self::SETTING_CHANGES);
    }

    public function noteChange()
    {
        $this->store->write(self::SETTING_CHANGES, $this->changes() + 1);
    }

    /**
     * The secret in this shop's cron URL.
     *
     * The cron entry point has to be reachable without a session, so the token is what stops a
     * stranger triggering a full catalogue walk whenever they like.
     */
    public function cronToken()
    {
        $token = $this->store->read(self::SETTING_CRON_TOKEN);
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(16));
            $this->store->write(self::SETTING_CRON_TOKEN, $token);
        }

        return $token;
    }

    public function forget()
    {
        $this->store->delete(self::SETTING_STATUS);
        $this->store->delete(self::SETTING_INTERVAL);
        $this->store->delete(self::SETTING_CHANGES);
        // The cron token stays: it is only the secret that lets the crontab line trigger a run
        // (which does nothing while the shop is not connected), so keeping it means the line the
        // shop owner set up keeps working after Disconnect and a new Connect.
        Requests::forget($this->store);
    }

    private function remember(array $status)
    {
        $this->store->write(self::SETTING_STATUS, json_encode($status));
    }
}
