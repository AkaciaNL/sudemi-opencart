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

    private $store;
    private $client;

    public function __construct($store, Client $client)
    {
        $this->store = $store;
        $this->client = $client;
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

        if ($response === null || $response['status'] !== 200) {
            // Still unreachable. Nothing is dropped and the next beat tries again.
            return 0;
        }

        // Only what was sent is cleared: anything a save added while this request was in flight
        // stays queued rather than being discarded unsent.
        $remaining = array_diff_key($this->all(), $batch);
        if (empty($remaining)) {
            $this->store->delete(self::SETTING);
        } else {
            $this->store->write(self::SETTING, json_encode($remaining));
        }

        return isset($response['body']['applied']) ? (int) $response['body']['applied'] : 0;
    }

    public function forget()
    {
        $this->store->delete(self::SETTING);
    }
}
