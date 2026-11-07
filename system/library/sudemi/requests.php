<?php
/**
 * Return requests from buyers, and this shop's answers to them.
 *
 * After delivery a buyer may ask to return an order (EU right of withdrawal). The shop has 7 days
 * to accept, or to reject with a reason the buyer is shown; an unanswered request goes to sudemi.
 * Accepting moves no money: the shop refunds once the item is back, through the extension's
 * existing refund path (order status Refunded), and that refund closes the request.
 *
 * sudemi cannot call this shop, so the open requests arrive on the heartbeat (open_requests): the
 * full list on every beat, absent when there are none. The copy kept here is replaced on every
 * successful beat, never merged, so it cannot drift from sudemi's.
 *
 * Every answer returns ['ok' => bool, 'outcome' => one of the OUTCOME_* codes]. The admin page
 * turns the outcome into a sentence in the admin's language: core's own message is English and
 * meant for logs.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class Requests
{
    const SETTING = 'sudemi_open_requests';

    /** At most this many are kept; sudemi sends at most 200. */
    const LIMIT = 200;
    const REASON_MAX = 1000;

    const OUTCOME_OK = 'ok';
    /** Answered before (on sudemi, by sudemi after 7 days, or from another tab). */
    const OUTCOME_ALREADY = 'already_resolved';
    /** No open request on this order (404). */
    const OUTCOME_NOT_FOUND = 'not_found';
    /** sudemi refused the answer as malformed (400), normally a rejection without a reason. */
    const OUTCOME_INVALID = 'invalid';
    /** A rejection without a reason, caught here before anything is sent. */
    const OUTCOME_REASON_REQUIRED = 'reason_required';
    /** This site is not the connected shop (409 site_mismatch). */
    const OUTCOME_SITE = 'site_mismatch';
    /** Not connected, sudemi unreachable, or an answer that made no sense. */
    const OUTCOME_UNAVAILABLE = 'unavailable';

    private $store;
    /** @var object|null anything with sendSigned($method, $path, array $body) -- \Sudemi\Client in the shop */
    private $client;

    public function __construct($store, $client = null)
    {
        $this->store = $store;
        $this->client = $client;
    }

    /**
     * Replaces the stored list with the heartbeat's open_requests (an empty or missing list clears
     * it). Only well-formed entries are kept: this is data from the network that ends up in the
     * admin page and in a URL.
     *
     * @param object     $store the setting store
     * @param array|null $list  the open_requests block of a 200 heartbeat answer
     */
    public static function remember($store, $list)
    {
        $kept = array();
        foreach (is_array($list) ? $list : array() as $entry) {
            $clean = self::clean($entry);
            if ($clean !== null) {
                $kept[] = $clean;
            }
            if (count($kept) >= self::LIMIT) {
                break;
            }
        }
        if (empty($kept)) {
            $store->delete(self::SETTING);

            return;
        }
        $store->write(self::SETTING, json_encode($kept));
    }

    /** Forgets every stored request (disconnecting). */
    public static function forget($store)
    {
        $store->delete(self::SETTING);
    }

    /** The open requests as the last beat reported them, soonest deadline first. */
    public function all()
    {
        $raw = $this->store->read(self::SETTING);
        $list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : array());
        $kept = array();
        foreach (is_array($list) ? $list : array() as $entry) {
            $clean = self::clean($entry);
            if ($clean !== null) {
                $kept[] = $clean;
            }
        }
        usort($kept, function ($a, $b) {
            return strcmp((string) $a['respond_by'], (string) $b['respond_by']);
        });

        return $kept;
    }

    /** The stored request on one sudemi order, or null. */
    public function find($orderId)
    {
        foreach ($this->all() as $entry) {
            if ($entry['order_id'] === strtolower((string) $orderId)) {
                return $entry;
            }
        }

        return null;
    }

    /** Accepts the return: the buyer sends the item back, then the shop refunds. */
    public function accept($orderId)
    {
        // An object, not "[]": the endpoint reads an optional JSON object.
        return $this->answer($orderId, 'accept', array('reason' => null));
    }

    /** Rejects the return; the reason is required and shown to the buyer. */
    public function reject($orderId, $reason)
    {
        $reason = self::text($reason, self::REASON_MAX);
        if ($reason === '') {
            return self::result(self::OUTCOME_REASON_REQUIRED);
        }

        return $this->answer($orderId, 'reject', array('reason' => $reason));
    }

    /**
     * Sent once, never retried (a person is waiting in the admin). On ok, already_resolved and
     * not_found the request is no longer open on sudemi, so it leaves the stored list at once
     * rather than at the next beat.
     */
    private function answer($orderId, $verb, array $body)
    {
        $orderId = strtolower((string) $orderId);
        if (!self::isId($orderId)) {
            return self::result(self::OUTCOME_NOT_FOUND);
        }
        if ($this->client === null) {
            return self::result(self::OUTCOME_UNAVAILABLE);
        }
        $response = $this->client->sendSigned('POST',
            '/integration/v1/orders/' . rawurlencode($orderId) . '/request/' . $verb, $body);
        $outcome = self::outcome($response);
        if (in_array($outcome, array(self::OUTCOME_OK, self::OUTCOME_ALREADY, self::OUTCOME_NOT_FOUND), true)) {
            $this->drop($orderId);
        }

        return self::result($outcome);
    }

    private static function outcome($response)
    {
        if (!is_array($response) || !isset($response['status'])) {
            return self::OUTCOME_UNAVAILABLE;
        }
        $status = (int) $response['status'];
        $body = isset($response['body']) && is_array($response['body']) ? $response['body'] : array();
        if ($status === 200 && isset($body['status']) && $body['status'] === 'ok') {
            return self::OUTCOME_OK;
        }
        if ($status === 200 && isset($body['status']) && $body['status'] === 'already_resolved') {
            return self::OUTCOME_ALREADY;
        }
        if ($status === 404) {
            return self::OUTCOME_NOT_FOUND;
        }
        if ($status === 400) {
            return self::OUTCOME_INVALID;
        }
        if ($status === 409 && isset($body['error']) && $body['error'] === 'site_mismatch') {
            return self::OUTCOME_SITE;
        }

        return self::OUTCOME_UNAVAILABLE;
    }

    private function drop($orderId)
    {
        $left = array();
        foreach ($this->all() as $entry) {
            if ($entry['order_id'] !== $orderId) {
                $left[] = $entry;
            }
        }
        self::remember($this->store, $left);
    }

    private static function clean($entry)
    {
        if (!is_array($entry) || !isset($entry['order_id']) || !self::isId($entry['order_id'])) {
            return null;
        }
        $kind = isset($entry['kind']) && is_string($entry['kind']) && preg_match('/^[A-Z_]{1,20}$/', $entry['kind'])
            ? $entry['kind'] : 'RETURN';
        $reason = isset($entry['reason']) && is_string($entry['reason']) ? self::text($entry['reason'], 2000) : '';

        return array(
            'request_id' => isset($entry['request_id']) && self::isId($entry['request_id']) ? strtolower($entry['request_id']) : '',
            'order_id' => strtolower($entry['order_id']),
            'kind' => $kind,
            'reason' => $reason,
            'requested_at' => self::instant(isset($entry['requested_at']) ? $entry['requested_at'] : null),
            'respond_by' => self::instant(isset($entry['respond_by']) ? $entry['respond_by'] : null),
        );
    }

    /** An ISO instant as sudemi sends it, or '' for anything else. */
    private static function instant($value)
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:\d{2})$/', $value)
            ? $value : '';
    }

    /** Trimmed, line endings normalised, control characters other than newline and tab removed, capped. */
    private static function text($value, $max)
    {
        if (!is_string($value)) {
            return '';
        }
        $value = str_replace(array("\r\n", "\r"), "\n", $value);
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));

        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    public static function isId($id)
    {
        return is_string($id)
            && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id) === 1;
    }

    private static function result($outcome)
    {
        return array('ok' => $outcome === self::OUTCOME_OK, 'outcome' => $outcome);
    }
}
