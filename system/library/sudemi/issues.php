<?php
/**
 * What the extension left out of the catalogue or could not read, reported to sudemi and to the
 * shop (owner 2026-11-19: "a product or a sync issue occurs, we need to report it to shop and to
 * sudemi as well").
 *
 * Every catalogue push (full sync and change push) carries the issues found while building it as
 * a top-level `issues` list: [{externalId, code, severity, field, message, details}]. sudemi's
 * answer (its `rejected` items and its own `issues`) is kept next to them (setting
 * sudemi_catalogue_issues) and the extension page shows both, in the admin's language.
 *
 * Pure apart from the setting store passed in.
 */

namespace Sudemi;

class Issues
{
    const REQUIRED_FILE_UPLOAD = 'required_file_upload';
    const OPTION_WITHOUT_VALUES = 'option_without_values';
    const VARIANT_CAP = 'variant_cap';
    const MISSING_PRICE = 'missing_price';
    const ADDON_NEGATIVE_PRICE = 'addon_negative_price';
    const TOO_MANY_REQUIRED_TEXTS = 'too_many_required_texts';
    const TOO_MANY_OPTIONAL_TEXTS = 'too_many_optional_texts';
    /**
     * Shop-wide, not about a product: waiting sudemi orders are in a currency this shop does not
     * have switched on, so they cannot be booked (Orders::reportCurrencyProblems). Sent on its own
     * on every heartbeat while it holds, never kept on the catalogue issues list.
     */
    const ORDER_CURRENCY_UNAVAILABLE = 'order_currency_unavailable';

    /** The codes the extension page has plain words for (text_issue_<code>). */
    const CODES = [self::REQUIRED_FILE_UPLOAD, self::OPTION_WITHOUT_VALUES, self::VARIANT_CAP, self::MISSING_PRICE,
        self::ADDON_NEGATIVE_PRICE, self::TOO_MANY_REQUIRED_TEXTS, self::TOO_MANY_OPTIONAL_TEXTS, 'rejected'];

    const SETTING = 'sudemi_catalogue_issues';
    const MAX_KEPT = 300;

    /** One issue in the wire shape. */
    public static function make($externalId, $code, $severity, $field, $message, array $details = [])
    {
        return [
            'externalId' => (string) $externalId,
            'code' => (string) $code,
            'severity' => $severity === 'error' ? 'error' : 'warning',
            'field' => (string) $field,
            'message' => (string) $message,
            'details' => (object) $details,
        ];
    }

    /** The issue for a product the extension does not offer (Options::plan()'s problem). */
    public static function forProblem($productId, array $problem)
    {
        $file = isset($problem['reason']) && $problem['reason'] === 'type';

        return self::make(
            $productId,
            $file ? self::REQUIRED_FILE_UPLOAD : self::OPTION_WITHOUT_VALUES,
            'error',
            isset($problem['option']) ? (string) $problem['option'] : 'options',
            $file
                ? sprintf('Not listed: the required option "%s" is of type %s, which a buyer\'s AI assistant cannot fill in.', $problem['option'], $problem['type'])
                : sprintf('Not listed: the required option "%s" has no values to choose from.', $problem['option']),
            ['type' => isset($problem['type']) ? (string) $problem['type'] : '']
        );
    }

    /** The push body with its issues (only when there are any; sudemi ignores a field it does not know). */
    public static function attach(array $body, array $issues)
    {
        if (!empty($issues)) {
            $body['issues'] = array_values($issues);
        }

        return $body;
    }

    /** The kept list after one push: the entries of the products in it replaced by this push and sudemi's answer. */
    public static function merge(array $kept, array $listings, array $issues, array $answer, $now)
    {
        $touched = [];
        foreach ($listings as $listing) {
            if (isset($listing['externalId'])) {
                $touched[(string) $listing['externalId']] = true;
            }
        }
        foreach ($issues as $issue) {
            $touched[(string) $issue['externalId']] = true;
        }
        $kept = array_values(array_filter($kept, function ($entry) use ($touched) {
            return is_array($entry) && isset($entry['externalId']) && !isset($touched[(string) $entry['externalId']]);
        }));
        foreach ($issues as $issue) {
            $kept[] = ['externalId' => (string) $issue['externalId'], 'source' => 'extension', 'code' => (string) $issue['code'],
                'severity' => (string) $issue['severity'], 'field' => (string) $issue['field'], 'message' => (string) $issue['message'], 'at' => (int) $now];
        }
        foreach (['rejected', 'issues'] as $list) {
            foreach (isset($answer[$list]) && is_array($answer[$list]) ? $answer[$list] : [] as $item) {
                if (!is_array($item) || !isset($item['externalId'])) {
                    continue;
                }
                $kept[] = ['externalId' => (string) $item['externalId'], 'source' => 'sudemi',
                    'code' => isset($item['code']) ? (string) $item['code'] : ($list === 'rejected' ? 'rejected' : 'issue'),
                    'severity' => $list === 'rejected' ? 'error' : (isset($item['severity']) ? (string) $item['severity'] : 'warning'),
                    'field' => isset($item['field']) ? (string) $item['field'] : '',
                    'message' => isset($item['reason']) ? (string) $item['reason'] : (isset($item['message']) ? (string) $item['message'] : ''),
                    'at' => (int) $now];
            }
        }

        return array_slice($kept, -self::MAX_KEPT);
    }

    /** The most bytes kept in one setting row (a TEXT column holds 64 KB). */
    const MAX_BYTES = 50000;

    /**
     * A list as stored: JSON with invalid UTF-8 replaced and \u escapes (fits any database
     * character set, OpenCart 3's utf8 tables too), the oldest entries dropped until it fits
     * $maxBytes. Null when it cannot be encoded.
     */
    public static function encode(array $list, $maxBytes = self::MAX_BYTES, $assoc = false)
    {
        while (true) {
            $json = json_encode($assoc ? $list : array_values($list), JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                return null;
            }
            if (strlen($json) <= $maxBytes || empty($list)) {
                return $json;
            }
            // Drop the oldest tenth (at least one) and try again.
            $list = array_slice($list, max(1, (int) floor(count($list) / 10)), null, $assoc);
        }
    }

    /**
     * Keeps what was sent and sudemi's answer in the setting store (a full sync's first push
     * starts afresh).
     *
     * @return bool false when it could not be saved (the list stays as it was, never emptied)
     */
    public static function remember($store, array $listings, array $issues, $answer, $fresh = false)
    {
        if ($store === null) {
            return true;
        }
        $kept = $fresh ? [] : self::all($store);

        return self::write($store, self::SETTING, self::merge($kept, $listings, $issues, is_array($answer) ? $answer : [], time()));
    }

    private static function write($store, $key, array $list, $assoc = false)
    {
        $json = self::encode($list, self::MAX_BYTES, $assoc);
        if ($json === null) {
            return false;
        }
        try {
            $store->write($key, $json);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /** Issues not sent yet (no listing push carried them, or it failed): external id => issues. */
    const SETTING_QUEUE = 'sudemi_pending_issues';

    /** The most issues in one request. */
    const MAX_PER_REQUEST = 500;

    /**
     * Keeps issues for the next listing push or the heartbeat, never a request of their own while
     * a product is saved. The newest issues of a product replace older ones; they show on the
     * extension page at once.
     *
     * @return bool false when they could not be saved
     */
    public static function queue($store, array $issues)
    {
        if ($store === null || empty($issues)) {
            return true;
        }
        $queued = self::queuedMap($store);
        foreach (self::byProduct($issues) as $id => $list) {
            unset($queued[$id]);
            $queued[$id] = $list;
        }

        return self::write($store, self::SETTING_QUEUE, $queued, true) && self::remember($store, [], $issues, null);
    }

    /** Issues grouped by external id (details as plain arrays, as stored). */
    public static function byProduct(array $issues)
    {
        $out = [];
        foreach ($issues as $issue) {
            $issue['details'] = (array) $issue['details'];
            $out[(string) $issue['externalId']][] = $issue;
        }

        return $out;
    }

    /** Drops what is waiting for these products: a newer push carries their current state. */
    public static function unqueue($store, array $externalIds)
    {
        if ($store === null) {
            return;
        }
        $queued = self::queuedMap($store);
        $before = count($queued);
        foreach ($externalIds as $id) {
            unset($queued[(string) $id]);
        }
        if (count($queued) !== $before) {
            self::storeQueue($store, $queued);
        }
    }

    /** Waiting issues, oldest first, whole products up to $limit issues: [external id => issues]. */
    public static function queued($store, $limit = self::MAX_PER_REQUEST)
    {
        $out = [];
        $count = 0;
        if ($store === null || $limit <= 0) {
            return $out;
        }
        foreach (self::queuedMap($store) as $id => $list) {
            if ($count > 0 && $count + count($list) > $limit) {
                break;
            }
            $out[$id] = $list;
            $count += count($list);
        }

        return $out;
    }

    /** The issues of queued() as one wire list. */
    public static function flat(array $byProduct)
    {
        $out = [];
        foreach ($byProduct as $list) {
            foreach ($list as $issue) {
                $issue['details'] = (object) (isset($issue['details']) ? (array) $issue['details'] : []);
                $out[] = $issue;
            }
        }

        return $out;
    }

    /** Removes what was sent, unless a newer list for the product came in meanwhile. */
    public static function sent($store, array $byProduct)
    {
        if ($store === null || empty($byProduct)) {
            return;
        }
        $queued = self::queuedMap($store);
        foreach ($byProduct as $id => $list) {
            if (isset($queued[$id]) && $queued[$id] == $list) {
                unset($queued[$id]);
            }
        }
        self::storeQueue($store, $queued);
    }

    /**
     * Sends waiting issues on their own (from the heartbeat): one request of at most
     * MAX_PER_REQUEST, no products (sudemi removes no listing for that). Kept on failure; dropped
     * when sudemi refuses them for good (the next full sync sends them again).
     *
     * @param object $client Client (postSigned)
     */
    public static function drain($store, $client)
    {
        $batch = self::queued($store);
        if (empty($batch)) {
            return false;
        }
        $issues = self::flat($batch);
        $answer = $client->postSigned('/integration/v1/products', self::attach(['products' => []], $issues));
        if ($answer !== null && $answer['status'] === 200) {
            self::sent($store, $batch);
            self::remember($store, [], $issues, isset($answer['body']) ? $answer['body'] : null);

            return true;
        }
        if ($answer !== null && in_array((int) $answer['status'], [400, 413, 422], true)) {
            self::sent($store, $batch);
        }

        return false;
    }

    private static function queuedMap($store)
    {
        $queued = json_decode((string) $store->read(self::SETTING_QUEUE), true);

        return is_array($queued) ? $queued : [];
    }

    private static function storeQueue($store, array $queued)
    {
        if (empty($queued)) {
            $store->delete(self::SETTING_QUEUE);

            return true;
        }

        return self::write($store, self::SETTING_QUEUE, $queued, true);
    }

    public static function all($store)
    {
        $kept = $store === null ? null : json_decode((string) $store->read(self::SETTING), true);

        return is_array($kept) ? $kept : [];
    }

    /** The product id of an external id ("12" or "12:34-56"). */
    public static function productId($externalId)
    {
        return (int) preg_split('/[^0-9]/', (string) $externalId, 2)[0];
    }
}
