<?php
/**
 * Keeping this shop's copy of its sudemi orders current (the heartbeat's change feed, 1.3.0).
 *
 * sudemi is the source of truth for an order: it can be shipped, refunded, cancelled or delivered
 * on the sudemi portal, through the seller API, by sudemi support or by a deadline running out, and
 * none of those pass through this shop. Each heartbeat therefore sends the cursor of the last
 * answer, and sudemi lists this shop's orders changed since then. Each entry is applied once, when
 * its revision is higher than the one held here (Orders::applyChange).
 *
 * A core older than 2026-11-09 sends no changed_orders: then nothing is stored and the extension
 * works as 1.2 did. A daily GET /orders?from=<yesterday> is a cheap safety net for anything lost.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class Changes
{
    /** {"install": "<install id>", "cursor": "<opaque>"}: kept per install, started again after re-pairing. */
    const SETTING_CURSOR = 'sudemi_changes_cursor';
    const SETTING_SAFETY = 'sudemi_changes_safety_at';

    /** At most this many extra heartbeats in one run while sudemi says changes_more. */
    const MAX_ROUNDS = 5;
    const SAFETY_EVERY = 86400;
    const SAFETY_PAGES = 10;

    private $store;
    private $client;
    private $logger;
    private $orders;

    public function __construct($store, Client $client, Logger $logger, Orders $orders)
    {
        $this->store = $store;
        $this->client = $client;
        $this->logger = $logger;
        $this->orders = $orders;
    }

    /** The cursor to send: '' to start (nothing stored, or stored for another install). */
    public function cursor()
    {
        $raw = $this->store->read(self::SETTING_CURSOR);
        $stored = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($stored) || !isset($stored['install'], $stored['cursor'])
            || (string) $stored['install'] !== (string) $this->client->installId()) {
            return '';
        }

        return (string) $stored['cursor'];
    }

    /**
     * Applies a heartbeat answer's changed_orders and keeps its cursor.
     *
     * @return bool whether sudemi has more right away (changes_more)
     */
    public function apply(array $body)
    {
        if (!array_key_exists('changed_orders', $body) || !is_array($body['changed_orders'])) {
            // A core without the feed: nothing to keep, the 1.2 behaviour stays.
            return false;
        }
        if (!empty($body['changes_cursor_reset'])) {
            $this->logger->info('sudemi started the order change feed again (the last 7 days).');
        }
        $applied = 0;
        foreach ($body['changed_orders'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            try {
                if ($this->orders->applyChange($entry)) {
                    ++$applied;
                }
            } catch (\Exception $e) {
                $this->logger->error('Could not apply a change to sudemi order '
                    . (isset($entry['order_id']) ? (string) $entry['order_id'] : '?') . ': ' . $e->getMessage());
            }
        }
        if ($applied > 0) {
            $this->logger->info(sprintf('Applied %d order change(s) from sudemi.', $applied));
        }
        if (isset($body['changes_cursor']) && is_string($body['changes_cursor']) && $body['changes_cursor'] !== '') {
            $this->store->write(self::SETTING_CURSOR, json_encode(array(
                'install' => (string) $this->client->installId(),
                'cursor' => $body['changes_cursor'],
            )));
        }

        return !empty($body['changes_more']);
    }

    /**
     * Once a day: this shop's orders placed since yesterday, applied like feed entries. Only once
     * the feed is known to work (a cursor is stored), so an older core is not asked for fields it
     * does not have.
     *
     * @return int how many orders were newer than the copy here
     */
    public function safetyNet($force = false)
    {
        if ($this->cursor() === '') {
            return 0;
        }
        $last = (int) $this->store->read(self::SETTING_SAFETY);
        if (!$force && time() - $last < self::SAFETY_EVERY) {
            return 0;
        }
        $this->store->write(self::SETTING_SAFETY, time());
        $from = gmdate('Y-m-d', time() - 86400);
        $offset = 0;
        $applied = 0;
        for ($page = 0; $page < self::SAFETY_PAGES; ++$page) {
            $response = $this->client->sendSigned('GET', '/integration/v1/orders'
                . Client::query(array('from' => $from, 'sort' => 'placed', 'dir' => 'asc', 'limit' => 100, 'offset' => $offset)));
            if (!is_array($response) || (int) $response['status'] !== 200 || !isset($response['body']['orders'])) {
                break;
            }
            foreach ($response['body']['orders'] as $row) {
                if (!is_array($row) || !isset($row['revision'])) {
                    continue;
                }
                try {
                    if ($this->orders->applyChange(Orders::changeFromRow($row))) {
                        ++$applied;
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Could not apply sudemi order ' . (isset($row['order_id']) ? (string) $row['order_id'] : '?')
                        . ': ' . $e->getMessage());
                }
            }
            if (!isset($response['body']['next_offset']) || $response['body']['next_offset'] === null) {
                break;
            }
            $offset = (int) $response['body']['next_offset'];
        }
        if ($applied > 0) {
            $this->logger->info(sprintf('Daily check: %d sudemi order(s) brought up to date.', $applied));
        }

        return $applied;
    }

    public function forget()
    {
        $this->store->delete(self::SETTING_CURSOR);
        $this->store->delete(self::SETTING_SAFETY);
    }
}
