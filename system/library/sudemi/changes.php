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

    /** Feed entries that could not be applied, read again from their order pages on later beats. */
    const SETTING_RETRY = 'sudemi_changes_retry';
    const RETRY_MAX = 100;
    const RETRY_DAYS = 7;
    const RETRY_PER_BEAT = 10;
    const RETRY_KEEP = 200;

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
        $retry = $this->retryList();
        $fresh = array();
        foreach ($body['changed_orders'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = isset($entry['order_id']) ? strtolower((string) $entry['order_id']) : '';
            try {
                if ($this->orders->applyChange($entry)) {
                    ++$applied;
                }
                unset($retry[$id]);
            } catch (\Throwable $e) {
                // Kept for a later beat (the order page is read then); the cursor still moves on.
                $retry = $this->failed($retry, $id, $e);
                $fresh[$id] = true;
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
        $this->saveRetryList($this->retryFailed($retry, $fresh));

        return !empty($body['changes_more']);
    }

    /**
     * Orders whose feed entry could not be applied, as {order id: {attempts, first, error}}. The
     * cursor never waits for them: each is read from its order page on the next beats instead.
     */
    public function retryList()
    {
        $raw = $this->store->read(self::SETTING_RETRY);
        $list = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($list) ? $list : array();
    }

    private function saveRetryList(array $list)
    {
        if ($list) {
            $this->store->write(self::SETTING_RETRY, json_encode($list));
        } else {
            $this->store->delete(self::SETTING_RETRY);
        }
    }

    /** Counts a failure for an order (new ones start at 1) and logs it; a full list loses its oldest, loudly. */
    private function failed(array $list, $id, $e)
    {
        if (!preg_match('/^' . Orders::UUID . '$/', $id)) {
            $this->logger->error('Could not apply a change to sudemi order ' . ($id !== '' ? $id : '?') . ': ' . $e->getMessage());

            return $list;
        }
        $row = isset($list[$id]) ? $list[$id] : array('attempts' => 0, 'first' => time());
        $row['attempts'] = (int) $row['attempts'] + 1;
        $row['error'] = substr((string) $e->getMessage(), 0, 200);
        unset($list[$id]);
        $list[$id] = $row;
        $this->logger->warn(sprintf('Could not apply a change to sudemi order %s (%s); will read it again on the next heartbeat (attempt %d of %d).',
            $id, $row['error'], $row['attempts'], self::RETRY_MAX));
        while (count($list) > self::RETRY_KEEP) {
            $oldest = array_key_first($list);
            $this->logger->error(sprintf('Gave up on sudemi order %s: too many orders waiting to be read again. Check it on the sudemi orders page.', $oldest));
            unset($list[$oldest]);
        }

        return $list;
    }

    /**
     * Reads the orders on the retry list (not the ones that just failed in this beat) from their
     * order pages and applies them. One that keeps failing is given up after RETRY_MAX attempts or
     * RETRY_DAYS days, with an error in the log, never silently.
     */
    private function retryFailed(array $list, array $skip)
    {
        $done = 0;
        foreach ($list as $id => $row) {
            if (isset($skip[$id])) {
                continue;
            }
            if ($done >= self::RETRY_PER_BEAT) {
                break;
            }
            ++$done;
            try {
                $detail = $this->orders->detail($id);
                if ($detail === null) {
                    throw new \RuntimeException('order page not readable');
                }
                $this->orders->applyChange(Orders::changeFromDetail($detail), $detail);
                unset($list[$id]);
                $this->logger->info('sudemi order ' . $id . ' was read again and brought up to date.');
                continue;
            } catch (\Throwable $e) {
                $list[$id]['attempts'] = (int) $row['attempts'] + 1;
                $list[$id]['error'] = substr((string) $e->getMessage(), 0, 200);
            }
            if ($list[$id]['attempts'] >= self::RETRY_MAX || time() - (int) $row['first'] > self::RETRY_DAYS * 86400) {
                $this->logger->error(sprintf('Gave up on sudemi order %s after %d attempts (%s). Check it on the sudemi orders page.',
                    $id, $list[$id]['attempts'], $list[$id]['error']));
                unset($list[$id]);
            }
        }

        return $list;
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
        $retry = $this->retryList();
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
                $id = isset($row['order_id']) ? strtolower((string) $row['order_id']) : '';
                try {
                    if ($this->orders->applyChange(Orders::changeFromRow($row))) {
                        ++$applied;
                    }
                    unset($retry[$id]);
                } catch (\Throwable $e) {
                    $retry = $this->failed($retry, $id, $e);
                }
            }
            if (!isset($response['body']['next_offset']) || $response['body']['next_offset'] === null) {
                break;
            }
            $offset = (int) $response['body']['next_offset'];
        }
        $this->saveRetryList($retry);
        if ($applied > 0) {
            $this->logger->info(sprintf('Daily check: %d sudemi order(s) brought up to date.', $applied));
        }

        return $applied;
    }

    public function forget()
    {
        $this->store->delete(self::SETTING_CURSOR);
        $this->store->delete(self::SETTING_SAFETY);
        $this->store->delete(self::SETTING_RETRY);
    }
}
