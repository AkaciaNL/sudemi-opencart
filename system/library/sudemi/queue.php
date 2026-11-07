<?php
/**
 * Price changes that have not reached sudemi yet.
 *
 * A delta push happens while someone is saving a product in the admin: it gets one attempt and
 * a short timeout, because holding that screen open for a retry would make a sudemi outage look
 * like an OpenCart fault. What it must not do is give up. A change dropped there is a wrong
 * price on a marketplace until the next full sync, so a failed push is written down here and the
 * heartbeat sends it a few minutes later.
 *
 * Keyed by product id, so twenty saves of one product while sudemi is unreachable leave one
 * entry carrying the newest price rather than twenty carrying a history nobody wants.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class Queue
{
    const SETTING = 'sudemi_pending_prices';

    /** A cap, because an unbounded setting row is a way to make an admin page unloadable. */
    const MAX_ENTRIES = 2000;

    /** Heartbeat::SETTING_CHANGES, named here so the queue does not need the heartbeat loaded. */
    const SETTING_CHANGES = 'sudemi_change_counter';

    private $store;
    private $client;
    private $logger;

    public function __construct($store, Client $client, $logger = null)
    {
        $this->store = $store;
        $this->client = $client;
        $this->logger = $logger;
    }

    public function enqueue(array $update)
    {
        if (empty($update['externalId'])) {
            return;
        }
        $pending = $this->all();
        $pending[(string) $update['externalId']] = $update;

        if (count($pending) > self::MAX_ENTRIES) {
            // Past this point it has stopped being a retry buffer and become a backlog, which a
            // full sync fixes more cheaply than draining would. The revision mismatch asks for
            // that sync on the next beat.
            $pending = array_slice($pending, -self::MAX_ENTRIES, null, true);
        }

        $this->store->write(self::SETTING, json_encode($pending));
    }

    public function all()
    {
        $raw = $this->store->read(self::SETTING);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function count()
    {
        return count($this->all());
    }

    /**
     * Sends what is waiting and keeps whatever still does not get through.
     *
     * @return int how many updates sudemi accepted
     */
    public function drain($batchSize = 200)
    {
        $pending = $this->all();
        if (empty($pending)) {
            return 0;
        }

        $batch = array_slice($pending, 0, $batchSize, true);
        $response = $this->client->postSigned(
            '/integration/v1/products/prices',
            ['updates' => array_values($batch)]
        );

        $status = is_array($response) ? (int) $response['status'] : 0;
        if (in_array($status, [400, 403, 413, 422], true)) {
            // A definitive refusal of the whole batch (malformed, a suspended shop, too large).
            // Sending the same batch again on every beat can never succeed, so it is dropped, and
            // the change counter moves so the revision disagrees and sudemi asks for a full sync,
            // which sends every price afresh once sudemi accepts catalogue data again.
            $this->removeSent($batch);
            $this->store->write(self::SETTING_CHANGES, (int) $this->store->read(self::SETTING_CHANGES) + 1);
            if ($this->logger !== null) {
                $this->logger->error('sudemi refused ' . count($batch) . ' queued price update(s) (HTTP '
                    . $status . '). They were dropped; a full sync will send the prices again.');
            }

            return 0;
        }
        if ($status !== 200) {
            // Still unreachable, or not answered (5xx, 429). Nothing is dropped and the next beat
            // tries again.
            return 0;
        }

        $this->removeSent($batch);

        return isset($response['body']['applied']) ? (int) $response['body']['applied'] : 0;
    }

    /**
     * Clears what was sent, but only entries still holding the value that was sent: a save that
     * queued a newer price for the same product while the request was in flight keeps it queued.
     */
    private function removeSent(array $batch)
    {
        $remaining = $this->all();
        foreach ($batch as $key => $sent) {
            if (array_key_exists($key, $remaining) && $remaining[$key] == $sent) {
                unset($remaining[$key]);
            }
        }
        if (empty($remaining)) {
            $this->store->delete(self::SETTING);
        } else {
            $this->store->write(self::SETTING, json_encode($remaining));
        }
    }

    public function forget()
    {
        $this->store->delete(self::SETTING);
    }
}
