<?php
/**
 * The shop's own OpenCart shipping rules, as sudemi charges a parcel by them
 * (POST /integration/v1/shipping/rules, PROTOCOL.md "The shop's own shipping rules").
 *
 * A product's shipping[{country, costMinor}] is what ONE item costs on its own. A parcel of several
 * items is charged by the rule sent here: per country one rule, built from the shop's standard
 * OpenCart methods the way its own checkout would charge (owner: never undercharge the shop, never
 * overcharge the buyer):
 *
 * - Flat rate: baseMinor = cost (+ tax), per order whatever the quantity.
 * - Per item (OpenCart: cost x quantity): baseMinor = cost (+ tax), extraItemMinor = cost (+ tax).
 * - Weight based: weight bands, upTo in grams (converted from the store's weight class), the price
 *   with tax; nothing above the last band (aboveLast "none"), as OpenCart offers nothing there.
 *   Several geo zones with weight rates for one country, or a flat rate next to them: the buyer
 *   could pick the cheapest quote in OpenCart's checkout, so each band is the lowest of them.
 * - Free shipping with a minimum total: freeOverMinor on that rule (see Shipping::goodsTaxFactor
 *   for the tax), with total 0: baseMinor 0.
 * - Per item next to flat or weight rates cannot be said in one rule (cost x quantity against a
 *   fixed price): the one cheaper for one item is sent, as for the products' costMinor, and the
 *   choice is reported (shipping_rule_approximated). Never a price the shop does not charge.
 * - Pickup and any other shipping extension: reported when it is the only method for a country,
 *   never priced.
 *
 * Everything here is pure (no database), so tests/shipping-rules-test.php runs it without OpenCart.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

require_once __DIR__ . '/currency.php';

class ShippingRules
{
    const APPROXIMATED = 'shipping_rule_approximated';
    const CALCULATED = 'shipping_rate_calculated';

    /** The standard methods this class reads; any other shipping extension is reported. */
    const STANDARD = ['flat', 'item', 'weight', 'free', 'pickup'];

    /**
     * Shipping extensions that ask a carrier for a live rate (code, or code part between
     * underscores): reported as shipping_rate_calculated, anything else unknown as approximated.
     */
    const CARRIERS = [
        'ups', 'fedex', 'usps', 'dhl', 'auspost', 'royal', 'royalmail', 'parcelforce', 'citylink',
        'canadapost', 'ecship', 'postnl', 'dpd', 'gls', 'sendcloud', 'shipstation', 'easypost',
        'myparcel', 'packlink', 'shippo', 'aramex', 'tnt', 'inpost', 'colissimo', 'chronopost',
        'correos', 'brt', 'bpost', 'posti', 'omniva', 'venipak', 'speedy', 'econt', 'sameday',
        'yurtici', 'aras', 'mng', 'ptt', 'hermes', 'evri', 'postnord', 'bring', 'budbee',
    ];

    /**
     * An OpenCart weight value in the store's weight class to grams, rounded down: a parcel
     * sudemi weighs at this many grams is never heavier than OpenCart's band limit, so it never
     * gets a cheaper band than the shop's checkout would give it.
     *
     * OpenCart converts with to = from * to.value / from.value (system/library/cart/weight.php);
     * $kgValue is the kilogram class's value, $storeValue the store weight class's value.
     *
     * @return int|null null when the classes are unknown (nothing to convert with)
     */
    public static function toGrams($weight, $storeValue, $kgValue)
    {
        $per = self::gramsPerUnit($storeValue, $kgValue);

        return $per === null ? null : (int) floor((float) $weight * $per + 1e-6);
    }

    /** Grams per unit of the store's weight class, or null when it cannot be known. */
    public static function gramsPerUnit($storeValue, $kgValue)
    {
        $storeValue = (float) $storeValue;
        $kgValue = (float) $kgValue;
        if ($storeValue <= 0 || $kgValue <= 0) {
            return null;
        }

        return 1000.0 * $kgValue / $storeValue;
    }

    /**
     * One geo zone's "weight:cost,weight:cost" rate (shipping_weight_<geo_zone>_rate) as bands.
     *
     * Read the way OpenCart's checkout reads it (extension/opencart/catalog/model/shipping/weight):
     * in the order typed, the first entry whose weight is at least the cart weight wins, so an
     * entry not heavier than one before it is never reached and is left out. An entry OpenCart
     * cannot price (no cost, a weight or cost that is not a number) cannot be copied: the zone is
     * then sent as one band up to its heaviest weight at its highest price (never undercharged)
     * and reported.
     *
     * @param string     $rates        the setting as typed
     * @param float|null $gramsPerUnit grams per unit of the store's weight class (null: unknown)
     * @param callable   $price        cost in the store currency (major) -> priceMinor with tax
     *
     * @return array{bands: array<int, array{upTo: int|null, priceMinor: int}>, approximated: string|null}
     */
    public static function weightBands($rates, $gramsPerUnit, callable $price)
    {
        $entries = [];
        $broken = false;
        $maxWeight = null;
        $maxCost = null;
        foreach (explode(',', (string) $rates) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $parts = explode(':', $raw);
            $weight = trim($parts[0]);
            $cost = isset($parts[1]) ? trim($parts[1]) : '';
            if (!is_numeric($weight) || (float) $weight < 0) {
                $broken = true;
                continue;
            }
            $weight = (float) $weight;
            if ($maxWeight !== null && $weight <= $maxWeight) {
                continue;
            }
            $maxWeight = $weight;
            if ($cost === '' || !is_numeric($cost) || (float) $cost < 0 || count($parts) > 2) {
                $broken = true;
                continue;
            }
            $maxCost = $maxCost === null ? (float) $cost : max($maxCost, (float) $cost);
            $entries[] = [$weight, (float) $cost];
        }
        if (empty($entries)) {
            return ['bands' => [], 'approximated' => $broken ? 'weight based rate has no entry OpenCart can price; left out' : null];
        }
        if ($gramsPerUnit === null) {
            // Not convertible to grams: the highest rate for any weight, never undercharged.
            return ['bands' => [['upTo' => null, 'priceMinor' => (int) $price($maxCost)]],
                'approximated' => 'store weight class cannot be converted to grams; highest weight based rate used for every weight'];
        }
        if ($broken) {
            return ['bands' => [['upTo' => self::grams($maxWeight, $gramsPerUnit), 'priceMinor' => (int) $price($maxCost)]],
                'approximated' => 'weight based rate has entries OpenCart cannot price (no cost or not a number); highest rate used up to the heaviest weight'];
        }
        $bands = [];
        foreach ($entries as $entry) {
            $bands[] = ['upTo' => self::grams($entry[0], $gramsPerUnit), 'priceMinor' => (int) $price($entry[1])];
        }

        return ['bands' => self::mergeBands([$bands]), 'approximated' => null];
    }

    private static function grams($weight, $gramsPerUnit)
    {
        return (int) floor($weight * $gramsPerUnit + 1e-6);
    }

    /**
     * The lowest price over several band lists at every weight (the buyer picks the cheapest quote
     * in OpenCart's checkout). Each list is ascending; above its last band it offers nothing, and
     * upTo null is no limit. The result is ascending, with neighbouring equal prices joined and a
     * band that covers no gram (the same upTo twice after rounding) dropped.
     *
     * @param array<int, array<int, array{upTo: int|null, priceMinor: int}>> $lists
     * @return array<int, array{upTo: int|null, priceMinor: int}>
     */
    public static function mergeBands(array $lists)
    {
        $points = [];
        $unlimited = false;
        foreach ($lists as $list) {
            foreach ($list as $band) {
                if ($band['upTo'] === null) {
                    $unlimited = true;
                } else {
                    $points[(int) $band['upTo']] = true;
                }
            }
        }
        $points = array_keys($points);
        sort($points);
        if ($unlimited) {
            $points[] = null;
        }
        $out = [];
        foreach ($points as $point) {
            $best = null;
            foreach ($lists as $list) {
                foreach ($list as $band) {
                    if ($band['upTo'] === null || ($point !== null && (int) $band['upTo'] >= $point)) {
                        $best = $best === null ? (int) $band['priceMinor'] : min($best, (int) $band['priceMinor']);
                        break;
                    }
                }
            }
            if ($best === null) {
                continue;
            }
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['priceMinor'] === $best) {
                $out[$last]['upTo'] = $point;
            } else {
                $out[] = ['upTo' => $point, 'priceMinor' => $best];
            }
        }

        return $out;
    }

    /**
     * The rule for one country, and what could not be copied.
     *
     * @param array $m what the shop offers there, amounts in minor units with tax:
     *                 flat       int|null         the flat rate
     *                 item       {first, extra}|null  per item: one item, every further item
     *                 weight     list of weightBands() results (one per geo zone covering it)
     *                 free       {always: bool, overMinor: int|null}|null  free shipping;
     *                            overMinor null = a minimum total that cannot be copied
     *                 pickup     bool
     *                 others     list of other enabled shipping extension codes
     *
     * @return array{rule: array|null, issues: array<int, array{code: string, method: string, message: string}>}
     */
    public static function forCountry($country, array $m)
    {
        $issues = [];
        $free = isset($m['free']) ? $m['free'] : null;
        if ($free !== null && !empty($free['always'])) {
            return ['rule' => ['country' => $country, 'baseMinor' => 0], 'issues' => []];
        }

        $lists = [];
        $weighted = false;
        foreach (isset($m['weight']) ? $m['weight'] : [] as $zone) {
            if (!empty($zone['approximated'])) {
                $issues[] = self::issue(self::APPROXIMATED, 'weight', $zone['approximated']);
            }
            if (!empty($zone['bands'])) {
                $lists[] = $zone['bands'];
                $weighted = true;
            }
        }
        $flat = isset($m['flat']) ? $m['flat'] : null;
        if ($flat !== null) {
            $lists[] = [['upTo' => null, 'priceMinor' => (int) $flat]];
        }
        $bands = empty($lists) ? null : self::mergeBands($lists);
        $bandsMethod = $weighted ? 'weight' : 'flat';
        $item = isset($m['item']) ? $m['item'] : null;

        $use = $bands === null ? ($item === null ? null : 'item') : 'bands';
        if ($item !== null && $bands !== null) {
            $last = $bands[count($bands) - 1];
            $highest = max(array_map(function ($b) { return $b['priceMinor']; }, $bands));
            if ($last['upTo'] === null && $highest <= (int) $item['first']) {
                // Per item is never cheaper (cost x quantity >= one item >= every band): exact.
                $use = 'bands';
            } else {
                $use = (int) $item['first'] < (int) $bands[0]['priceMinor'] ? 'item' : 'bands';
                $issues[] = self::issue(self::APPROXIMATED, $use === 'item' ? 'item' : $bandsMethod, sprintf(
                    'per item rate next to a %s rate: one rule per country, the %s rate is used (cheaper for one item); '
                    . 'OpenCart lets the buyer pick the cheaper of both for each cart',
                    $use === 'item' ? $bandsMethod : 'per item',
                    $use === 'item' ? 'per item' : $bandsMethod
                ));
            }
        }

        if ($use === null) {
            if ($free !== null) {
                $issues[] = self::issue(self::APPROXIMATED, 'free', 'only free shipping with a minimum total delivers here; '
                    . 'sudemi cannot offer delivery only above an amount, so this country is not offered');
            }
            if (!empty($m['pickup'])) {
                $issues[] = self::issue(self::APPROXIMATED, 'pickup', 'only store pickup is offered here; not a delivery, so this country is not offered');
            }
            foreach (isset($m['others']) ? $m['others'] : [] as $code) {
                $issues[] = self::isCarrier($code)
                    ? self::issue(self::CALCULATED, (string) $code, 'shipping extension "' . $code . '" calculates its rates (carrier); '
                        . 'no fixed rate to send, so this country is not offered unless set on sudemi')
                    : self::issue(self::APPROXIMATED, (string) $code, 'shipping extension "' . $code . '" is not a standard OpenCart method; '
                        . 'its rates cannot be read, so this country is not offered unless set on sudemi');
            }

            return ['rule' => null, 'issues' => $issues];
        }

        $rule = ['country' => $country];
        if ($use === 'item') {
            $rule['baseMinor'] = (int) $item['first'];
            $rule['extraItemMinor'] = (int) $item['extra'];
        } elseif (count($bands) === 1 && $bands[0]['upTo'] === null) {
            $rule['baseMinor'] = (int) $bands[0]['priceMinor'];
        } else {
            $rule['bandBasis'] = 'weight';
            $rule['bands'] = $bands;
            if ($bands[count($bands) - 1]['upTo'] !== null) {
                $rule['aboveLast'] = 'none';
            }
        }
        if ($free !== null) {
            if (isset($free['overMinor']) && $free['overMinor'] !== null) {
                $rule['freeOverMinor'] = (int) $free['overMinor'];
            } else {
                $issues[] = self::issue(self::APPROXIMATED, 'free', 'free shipping minimum total left out: products use a tax with a '
                    . 'fixed amount, so the total without tax cannot be told from the price with tax; the shipping rate is charged instead');
            }
        }

        return ['rule' => $rule, 'issues' => $issues];
    }

    /**
     * The request body: the rules, and the issues with the countries they concern grouped (one
     * issue per code, method and message, countries sorted).
     *
     * @param array<string, array> $byCountry ISO code => forCountry() input
     */
    public static function payload($currency, array $byCountry)
    {
        ksort($byCountry);
        $rules = [];
        $grouped = [];
        foreach ($byCountry as $country => $m) {
            $result = self::forCountry((string) $country, $m);
            if ($result['rule'] !== null) {
                $rules[] = $result['rule'];
            }
            foreach ($result['issues'] as $issue) {
                $key = $issue['code'] . "\n" . $issue['method'] . "\n" . $issue['message'];
                if (!isset($grouped[$key])) {
                    $grouped[$key] = $issue + ['countries' => []];
                }
                $grouped[$key]['countries'][] = (string) $country;
            }
        }
        $issues = [];
        foreach ($grouped as $g) {
            $countries = array_values(array_unique($g['countries']));
            sort($countries);
            $issues[] = [
                'code' => $g['code'],
                'severity' => 'warning',
                'field' => 'shipping',
                'message' => $g['message'],
                'details' => ['countries' => $countries, 'method' => $g['method']],
            ];
        }
        $body = ['currency' => (string) $currency, 'rules' => $rules];
        $body['issues'] = $issues;

        return $body;
    }

    public static function isCarrier($code)
    {
        foreach (explode('_', strtolower((string) $code)) as $part) {
            if (in_array($part, self::CARRIERS, true)) {
                return true;
            }
        }

        return in_array(strtolower(str_replace('_', '', (string) $code)), self::CARRIERS, true);
    }

    /** A further item's price with tax, rounded up: OpenCart taxes cost x quantity at once. */
    public static function extraMinor($onePlusTax, $twoPlusTax, $currency)
    {
        $factor = 10 ** Currency::decimals($currency);

        return max(0, (int) ceil(((float) $twoPlusTax - (float) $onePlusTax) * $factor - 1e-6));
    }

    /** The free shipping minimum total as goods with tax, rounded up (never free below it). */
    public static function freeOverMinor($totalExTax, $taxFactor, $currency)
    {
        $factor = 10 ** Currency::decimals($currency);

        return (int) ceil((float) $totalExTax * (float) $taxFactor * $factor - 1e-6);
    }

    private static function issue($code, $method, $message)
    {
        return ['code' => $code, 'method' => (string) $method, 'message' => $message];
    }
}

/**
 * Sends the rules (POST /integration/v1/shipping/rules) from the cron entry or the admin's
 * background step, never inside an admin request: a shipping setting saved, a geo zone or tax
 * edited and every committed full sync only set a flag (SETTING_DUE). Also checked every hour (a
 * product given another tax class changes the free shipping minimum) and sent at least once a
 * day; unchanged rules are not sent again in between.
 */
class ShippingRulesSender
{
    const SETTING_DUE = 'sudemi_shipping_rules_due';
    /** {hash, sent, checked, issues, ignored, status}: the last rules sent and sudemi's answer. */
    const SETTING_STATE = 'sudemi_shipping_rules_state';
    const CHECK_SECONDS = 3600;
    const RESEND_SECONDS = 86400;
    const PATH = '/integration/v1/shipping/rules';

    private $store;
    private $client;
    private $logger;
    /** @var callable(): array the request body (Shipping::rulesPayload) */
    private $build;

    public function __construct($store, $client, $logger, callable $build)
    {
        $this->store = $store;
        $this->client = $client;
        $this->logger = $logger;
        $this->build = $build;
    }

    /** Asks for the rules to be sent by the next cron run (cheap: one setting). */
    public static function request($store)
    {
        if ($store !== null && !$store->read(self::SETTING_DUE)) {
            $store->write(self::SETTING_DUE, 1);
        }
    }

    public function state()
    {
        $raw = $this->store->read(self::SETTING_STATE);
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** Whether a send (or the hourly check) is due. */
    public function due($now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $state = $this->state();

        return (bool) $this->store->read(self::SETTING_DUE)
            || !isset($state['checked']) || $now - (int) $state['checked'] >= self::CHECK_SECONDS;
    }

    /**
     * @return string 'idle' (nothing due, or not connected), 'same' (checked, unchanged),
     *                'sent', or 'failed' (kept due when it may get through later)
     */
    public function sendIfDue($now = null)
    {
        $now = $now === null ? time() : (int) $now;
        if (!$this->client->isConnected() || !$this->due($now)) {
            return 'idle';
        }
        $asked = (bool) $this->store->read(self::SETTING_DUE);
        $state = $this->state();
        $body = call_user_func($this->build);
        $hash = hash('sha256', (string) json_encode($body));
        if (!$asked && isset($state['hash'], $state['sent']) && $state['hash'] === $hash
            && $now - (int) $state['sent'] < self::RESEND_SECONDS) {
            $state['checked'] = $now;
            $this->save($state);

            return 'same';
        }
        // Cleared before sending: a change saved while this runs asks again.
        $this->store->delete(self::SETTING_DUE);
        $response = $this->client->postSigned(self::PATH, $body);
        $status = is_array($response) ? (int) $response['status'] : 0;
        if ($status === 200) {
            $answer = isset($response['body']) && is_array($response['body']) ? $response['body'] : [];
            $ignored = isset($answer['ignored']) && is_array($answer['ignored']) ? array_values($answer['ignored']) : [];
            foreach ($ignored as $entry) {
                if (is_array($entry) && isset($entry['country'])) {
                    $this->logger->warn('sudemi did not take the shipping rule for ' . $entry['country'] . ': '
                        . (isset($entry['reason']) ? (string) $entry['reason'] : 'no reason given'));
                }
            }
            $this->save([
                'hash' => $hash,
                'sent' => $now,
                'checked' => $now,
                'rules' => count($body['rules']),
                'issues' => $body['issues'],
                'ignored' => array_slice($ignored, 0, 50),
            ]);

            return 'sent';
        }
        if ($status === 0 || $status === 429 || $status >= 500) {
            // Not answered: the next cron run tries again.
            self::request($this->store);
        } else {
            // Refused (an older sudemi without this call answers 404): the hourly check retries.
            $this->logger->warn('sudemi did not accept the shipping rules (' . $status . ').');
        }
        $state['checked'] = $now;
        $this->save($state);

        return 'failed';
    }

    /** A new connection starts without rules: the first full sync's commit sends them. */
    public function forget()
    {
        $this->store->delete(self::SETTING_STATE);
        $this->store->delete(self::SETTING_DUE);
    }

    private function save(array $state)
    {
        $this->store->write(self::SETTING_STATE, json_encode($state));
    }
}
