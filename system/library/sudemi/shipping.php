<?php
/**
 * What a product costs to ship to each country, read from this shop's own OpenCart shipping
 * methods, plus the delivery times the merchant set for sudemi; and product prices with tax.
 *
 * sudemi shows buyers the total including shipping and refuses a checkout to a country with no
 * shipping price, so a product without rates is never offered. Per destination country the
 * cheapest of OpenCart's standard methods that applies to one item counts:
 *
 * - Flat rate (cost), Per item (cost x 1), Weight based (the first "weight:cost" band of the
 *   country's geo zone that fits the product weight), Free shipping only when its minimum total
 *   is 0; each only when enabled and its geo zone covers the whole country (geo zone 0 = all);
 * - plus the method's tax class, for that country, because buyers see prices with tax;
 * - pickup and any other extension are not fixed delivery prices and are ignored.
 *
 * That is what ONE item costs. What a parcel of several items costs follows the shop's own rule
 * per country, built by rulesPayload() (ShippingRules, 1.4.7).
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

require_once __DIR__ . '/currency.php';

class Shipping
{
    const HANDLING_DAYS = 'sudemi_handling_days';
    const TRANSIT_DAYS = 'sudemi_transit_days';
    const DEFAULT_HANDLING_DAYS = 2;
    const DEFAULT_TRANSIT_DAYS = 5;

    /** sudemi's buyer markets: EU, EEA, Switzerland, United Kingdom, Turkiye. */
    const MARKETS = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV',
        'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'NO', 'IS', 'LI', 'CH', 'GB', 'TR',
    ];

    private $db;
    private $config;
    private $prefix;
    private $countries = null;

    public function __construct($db, $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->prefix = DB_PREFIX;
    }

    public function handlingDays()
    {
        return $this->days(self::HANDLING_DAYS, self::DEFAULT_HANDLING_DAYS);
    }

    public function transitDays()
    {
        return $this->days(self::TRANSIT_DAYS, self::DEFAULT_TRANSIT_DAYS);
    }

    private function days($key, $default)
    {
        $value = $this->config->get($key);

        return ($value === null || $value === '' || !is_numeric($value)) ? $default : max(0, min(60, (int) $value));
    }

    /**
     * @param float $weightKg the product's weight in kilograms
     * @return array<int, array{country: string, costMinor: int}>
     */
    public function ratesFor($weightKg, $shippingRequired = true)
    {
        if (!$shippingRequired) {
            return [];
        }
        $rates = [];
        foreach ($this->countries() as $iso => $countryId) {
            $best = null;
            foreach ($this->candidates($countryId, $weightKg) as $cost) {
                if ($best === null || $cost < $best) {
                    $best = $cost;
                }
            }
            if ($best !== null) {
                $rates[] = ['country' => $iso, 'costMinor' => Currency::toMinor($best, $this->config->get('config_currency'))];
            }
        }

        return $rates;
    }

    /** A price with the tax OpenCart would add for a customer in the store's own country. */
    public function withStoreTax($price, $taxClassId)
    {
        return (float) $price + $this->tax((float) $price, (int) $taxClassId, (int) $this->config->get('config_country_id'));
    }

    /**
     * The tax class of the shop's shipping method a shipping price with tax came from, for a
     * country: the one class when every method that delivers there uses the same, else the
     * method (or weight band) whose price with tax is this price. 0 when none matches.
     *
     * @param float $grossMajor the shipping price with tax, in the store's currency
     */
    public function taxClassFor($countryId, $grossMajor)
    {
        $methods = [];
        if ($this->config->get('shipping_flat_status') && $this->covers((int) $this->config->get('shipping_flat_geo_zone_id'), $countryId)) {
            $methods[] = [(float) $this->config->get('shipping_flat_cost'), (int) $this->config->get('shipping_flat_tax_class_id')];
        }
        if ($this->config->get('shipping_item_status') && $this->covers((int) $this->config->get('shipping_item_geo_zone_id'), $countryId)) {
            $methods[] = [(float) $this->config->get('shipping_item_cost'), (int) $this->config->get('shipping_item_tax_class_id')];
        }
        if ($this->config->get('shipping_weight_status')) {
            foreach ($this->geoZonesCovering($countryId) as $geoZoneId) {
                if (!$this->config->get('shipping_weight_' . $geoZoneId . '_status')) {
                    continue;
                }
                foreach (explode(',', (string) $this->config->get('shipping_weight_' . $geoZoneId . '_rate')) as $band) {
                    $parts = explode(':', trim($band));
                    if (count($parts) === 2 && is_numeric($parts[1])) {
                        $methods[] = [(float) $parts[1], (int) $this->config->get('shipping_weight_tax_class_id')];
                    }
                }
            }
        }
        $classes = array_unique(array_map(function ($m) { return $m[1]; }, $methods));
        if (count($classes) === 1) {
            return (int) reset($classes);
        }
        foreach ($methods as $m) {
            if (abs($m[0] + $this->tax($m[0], $m[1], $countryId) - (float) $grossMajor) < 0.006) {
                return $m[1];
            }
        }

        return 0;
    }

    /**
     * The body of POST /integration/v1/shipping/rules: the shop's own rule per country, built by
     * ShippingRules from the same methods, geo zones and taxes as ratesFor().
     */
    public function rulesPayload()
    {
        require_once __DIR__ . '/shipping_rules.php';
        $currency = (string) Currency::normalise($this->config->get('config_currency'));
        $byCountry = [];
        foreach ($this->countries() as $iso => $countryId) {
            $byCountry[$iso] = $this->ruleInput($countryId, $currency);
        }

        return ShippingRules::payload($currency, $byCountry);
    }

    /** What the shop offers in one country, as ShippingRules::forCountry() takes it. */
    private function ruleInput($countryId, $currency)
    {
        $gross = function ($cost, $taxClassId) use ($countryId) {
            return (float) $cost + $this->tax((float) $cost, (int) $taxClassId, $countryId);
        };
        $minor = function ($cost, $taxClassId) use ($gross, $currency) {
            return Currency::toMinor($gross($cost, $taxClassId), $currency);
        };
        $in = ['flat' => null, 'item' => null, 'weight' => [], 'free' => null, 'pickup' => false, 'others' => []];
        if ($this->config->get('shipping_flat_status') && $this->covers((int) $this->config->get('shipping_flat_geo_zone_id'), $countryId)) {
            $in['flat'] = $minor($this->config->get('shipping_flat_cost'), $this->config->get('shipping_flat_tax_class_id'));
        }
        if ($this->config->get('shipping_item_status') && $this->covers((int) $this->config->get('shipping_item_geo_zone_id'), $countryId)) {
            $cost = (float) $this->config->get('shipping_item_cost');
            $class = (int) $this->config->get('shipping_item_tax_class_id');
            // OpenCart taxes cost x quantity at once: a fixed-amount tax is charged once per order.
            $in['item'] = [
                'first' => $minor($cost, $class),
                'extra' => ShippingRules::extraMinor($gross($cost, $class), $gross(2 * $cost, $class), $currency),
            ];
        }
        if ($this->config->get('shipping_weight_status')) {
            $perUnit = $this->gramsPerStoreUnit();
            $class = (int) $this->config->get('shipping_weight_tax_class_id');
            foreach ($this->geoZonesCovering($countryId) as $geoZoneId) {
                if (!$this->config->get('shipping_weight_' . $geoZoneId . '_status')) {
                    continue;
                }
                $in['weight'][] = ShippingRules::weightBands(
                    (string) $this->config->get('shipping_weight_' . $geoZoneId . '_rate'),
                    $perUnit,
                    function ($cost) use ($minor, $class) {
                        return $minor($cost, $class);
                    }
                );
            }
        }
        if ($this->config->get('shipping_free_status') && $this->covers((int) $this->config->get('shipping_free_geo_zone_id'), $countryId)) {
            $total = (float) $this->config->get('shipping_free_total');
            if ($total <= 0) {
                $in['free'] = ['always' => true, 'overMinor' => 0];
            } else {
                $factor = $this->goodsTaxFactor();
                $in['free'] = ['always' => false,
                    'overMinor' => $factor === null ? null : ShippingRules::freeOverMinor($total, $factor, $currency)];
            }
        }
        if ($this->config->get('shipping_pickup_status') && $this->covers((int) $this->config->get('shipping_pickup_geo_zone_id'), $countryId)) {
            $in['pickup'] = true;
        }
        $in['others'] = $this->otherExtensions();

        return $in;
    }

    private $gramsPerUnit = false;

    /**
     * Grams per unit of the store's weight class (config_weight_class_id), the class OpenCart
     * compares a cart's weight in. The kilogram class is found by its unit in any language ("kg",
     * or "g" for grams). Null when it cannot be known.
     */
    public function gramsPerStoreUnit()
    {
        if ($this->gramsPerUnit !== false) {
            return $this->gramsPerUnit;
        }
        require_once __DIR__ . '/shipping_rules.php';
        $query = $this->db->query(
            'SELECT wc.weight_class_id, wc.value, wcd.unit FROM ' . $this->prefix . 'weight_class wc
               LEFT JOIN ' . $this->prefix . 'weight_class_description wcd ON (wc.weight_class_id = wcd.weight_class_id)'
        );
        $store = null;
        $kg = null;
        $storeId = (int) $this->config->get('config_weight_class_id');
        foreach ($query->rows as $row) {
            $value = (float) $row['value'];
            if ((int) $row['weight_class_id'] === $storeId) {
                $store = $value;
            }
            $unit = strtolower(trim((string) $row['unit']));
            if ($kg === null && $unit === 'kg') {
                $kg = $value;
            } elseif ($kg === null && $unit === 'g' && $value > 0) {
                $kg = $value / 1000;
            }
        }
        $this->gramsPerUnit = ShippingRules::gramsPerUnit($store, $kg);

        return $this->gramsPerUnit;
    }

    /**
     * Product price with tax / without, at most, over the tax classes of the shop's enabled
     * products (the store country's rates, as product prices are sent with them; withStoreTax).
     *
     * OpenCart's Free Shipping compares the cart sub-total WITHOUT tax with its minimum total;
     * sudemi compares the goods WITH tax. The minimum x the highest factor means goods with tax
     * reach it only when goods without tax reach the minimum: never free when the shop would not
     * make it free. With products at a lower rate in the cart the buyer may need a little more
     * than in the shop. Null when a tax adds a fixed amount (no factor holds).
     */
    public function goodsTaxFactor()
    {
        $classes = $this->db->query('SELECT DISTINCT tax_class_id FROM ' . $this->prefix . 'product WHERE status = 1');
        $countryId = (int) $this->config->get('config_country_id');
        $highest = 1.0;
        foreach ($classes->rows as $row) {
            $class = (int) $row['tax_class_id'];
            if ($class <= 0) {
                continue;
            }
            // tax(x) = x * p + f: f from tax(0), p from tax(100) - f.
            $fixed = $this->tax(0.0, $class, $countryId);
            if ($fixed > 0) {
                return null;
            }
            $highest = max($highest, 1 + $this->tax(100.0, $class, $countryId) / 100);
        }

        return $highest;
    }

    private $others = null;

    /** Enabled shipping extensions other than OpenCart's standard ones (their rates cannot be read). */
    private function otherExtensions()
    {
        if ($this->others !== null) {
            return $this->others;
        }
        $this->others = [];
        $query = $this->db->query("SELECT DISTINCT `code` FROM " . $this->prefix . "extension WHERE `type` = 'shipping'");
        foreach ($query->rows as $row) {
            $code = (string) $row['code'];
            if (!in_array($code, ShippingRules::STANDARD, true) && $this->config->get('shipping_' . $code . '_status')) {
                $this->others[] = $code;
            }
        }
        sort($this->others);

        return $this->others;
    }

    private function candidates($countryId, $weightKg)
    {
        $out = [];
        if ($this->config->get('shipping_flat_status') && $this->covers((int) $this->config->get('shipping_flat_geo_zone_id'), $countryId)) {
            $cost = (float) $this->config->get('shipping_flat_cost');
            $out[] = $cost + $this->tax($cost, (int) $this->config->get('shipping_flat_tax_class_id'), $countryId);
        }
        if ($this->config->get('shipping_item_status') && $this->covers((int) $this->config->get('shipping_item_geo_zone_id'), $countryId)) {
            $cost = (float) $this->config->get('shipping_item_cost');
            $out[] = $cost + $this->tax($cost, (int) $this->config->get('shipping_item_tax_class_id'), $countryId);
        }
        if ($this->config->get('shipping_free_status') && (float) $this->config->get('shipping_free_total') <= 0
            && $this->covers((int) $this->config->get('shipping_free_geo_zone_id'), $countryId)) {
            $out[] = 0.0;
        }
        if ($this->config->get('shipping_weight_status')) {
            foreach ($this->geoZonesCovering($countryId) as $geoZoneId) {
                if (!$this->config->get('shipping_weight_' . $geoZoneId . '_status')) {
                    continue;
                }
                $cost = $this->weightBand((string) $this->config->get('shipping_weight_' . $geoZoneId . '_rate'), $weightKg);
                if ($cost !== null) {
                    $out[] = $cost + $this->tax($cost, (int) $this->config->get('shipping_weight_tax_class_id'), $countryId);
                }
            }
        }

        return $out;
    }

    /** "5:10.00,10:12.50" -> the cost of the first band whose upper weight is >= the weight. */
    private function weightBand($rates, $weightKg)
    {
        foreach (explode(',', $rates) as $band) {
            $parts = explode(':', trim($band));
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && (float) $parts[0] >= $weightKg) {
                return (float) $parts[1];
            }
        }

        return null;
    }

    /** True when the geo zone contains the whole country (zone 0 = every zone of it). */
    private function covers($geoZoneId, $countryId)
    {
        if ($geoZoneId === 0) {
            return true;
        }
        $query = $this->db->query(
            'SELECT 1 FROM ' . $this->prefix . 'zone_to_geo_zone
              WHERE geo_zone_id = ' . (int) $geoZoneId . ' AND country_id = ' . (int) $countryId . ' AND zone_id = 0
              LIMIT 1'
        );

        return !empty($query->row);
    }

    private function geoZonesCovering($countryId)
    {
        $query = $this->db->query(
            'SELECT DISTINCT geo_zone_id FROM ' . $this->prefix . 'zone_to_geo_zone
              WHERE country_id = ' . (int) $countryId . ' AND zone_id = 0'
        );

        return array_map(function ($row) { return (int) $row['geo_zone_id']; }, $query->rows);
    }

    private function tax($amount, $taxClassId, $countryId)
    {
        if (!$taxClassId) {
            return 0.0;
        }
        $query = $this->db->query(
            'SELECT tr2.rate, tr2.type FROM ' . $this->prefix . 'tax_rule tr1
               JOIN ' . $this->prefix . 'tax_rate tr2 ON tr1.tax_rate_id = tr2.tax_rate_id
               JOIN ' . $this->prefix . 'zone_to_geo_zone z2gz ON tr2.geo_zone_id = z2gz.geo_zone_id
              WHERE tr1.tax_class_id = ' . (int) $taxClassId . ' AND z2gz.country_id = ' . (int) $countryId . '
                AND z2gz.zone_id = 0
              GROUP BY tr2.tax_rate_id, tr2.rate, tr2.type'
        );
        $tax = 0.0;
        foreach ($query->rows as $row) {
            $tax += $row['type'] === 'F' ? (float) $row['rate'] : $amount * (float) $row['rate'] / 100;
        }

        return $tax;
    }

    private function countries()
    {
        if ($this->countries !== null) {
            return $this->countries;
        }
        $codes = "'" . implode("','", self::MARKETS) . "'";
        $query = $this->db->query(
            'SELECT country_id, iso_code_2 FROM ' . $this->prefix . 'country
              WHERE status = 1 AND iso_code_2 IN (' . $codes . ')'
        );
        $this->countries = [];
        foreach ($query->rows as $row) {
            $this->countries[(string) $row['iso_code_2']] = (int) $row['country_id'];
        }

        return $this->countries;
    }
}
