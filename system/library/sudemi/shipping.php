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
