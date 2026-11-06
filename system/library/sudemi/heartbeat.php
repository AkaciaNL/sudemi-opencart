<?php
/**
 * Telling sudemi this shop is still here, and still means its prices.
 *
 * sudemi cannot call this shop: it sits behind this host's firewall and accepts no connection
 * from outside. So everything starts here, which makes this the only two-way channel the
 * integration has — the request says what this shop believes its catalogue to be, and the reply
 * is where sudemi asks for whatever it needs.
 *
 * Why it exists: from sudemi's side a shop with nothing to report and a shop whose server died
 * last Tuesday look identical, and only one of them still has prices worth quoting. If silence
 * were read as agreement, a dead shop would keep advertising last week's prices and taking
 * orders at them. So this shop says so, and sudemi stops listing it when it stops saying so.
 * The listings return on the next beat; nothing is deleted.
 *
 * The revision catches the quiet failure. A delta push can be lost — sudemi restarting, a DNS
 * blip, a proxy — and liveness notices nothing, because the shop is healthy and the beats keep
 * arriving. Stating what this catalogue is lets sudemi disagree, and a disagreement costs one
 * full sync instead of weeks of a wrong price.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

require_once __DIR__ . '/withdrawal.php';

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

        // sudemi and this shop disagree about what the catalogue is, so something did not
        // arrive. A full sync is the only answer that does not involve guessing which product.
        if (!empty($body['full_sync_requested'])) {
            $this->logger->info('sudemi asked for a full sync.');
            $this->store->delete(Withdrawal::SETTING_RESYNC_DUE);
            $this->sync->runFull();
        } elseif ($this->store->read(Withdrawal::SETTING_RESYNC_DUE)) {
            // A product's right-of-withdrawal setting changed: price pushes do not carry it.
            $this->store->delete(Withdrawal::SETTING_RESYNC_DUE);
            $this->sync->runFull();
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
        $this->store->delete(self::SETTING_CRON_TOKEN);
    }

    private function remember(array $status)
    {
        $this->store->write(self::SETTING_STATUS, json_encode($status));
    }
}
