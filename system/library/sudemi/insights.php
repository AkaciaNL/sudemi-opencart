<?php
/**
 * Statistics and listings as sudemi reports them (protocol section 4e), shaped for the admin pages.
 *
 * Pure PHP, no OpenCart: the pages hand in what sudemi answered and get back safe arrays and the
 * chart as inline SVG (no outside scripts in the admin). Anything missing or malformed in an
 * answer becomes 0 or empty, so a page never breaks on a partial answer.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class Insights
{
    /** The periods the dashboard offers, in days. */
    const PERIODS = array(7, 30, 90);
    const DEFAULT_PERIOD = 30;

    /** The metrics the chart can show, in the order of its buttons. */
    const METRICS = array('impressions', 'detail_views', 'orders', 'sales_minor');

    /** Why a product is not listed (protocol 4e). Anything else is shown as "not listed". */
    const REASONS = array('disabled_by_shop', 'not_in_shop', 'out_of_stock', 'no_shipping', 'suspended',
        'payouts_not_ready', 'shop_not_reporting');

    const LISTING_FILTERS = array('all', 'listed', 'not_listed');
    const LISTING_PAGE_SIZE = 50;

    /** One of PERIODS, from the query string; the default otherwise. */
    public static function period($days)
    {
        $days = is_numeric($days) ? (int) $days : 0;

        return in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_PERIOD;
    }

    /** [from, to] as UTC dates (YYYY-MM-DD), to = today, both inclusive. */
    public static function range($days, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $to = gmdate('Y-m-d', $now);
        $from = gmdate('Y-m-d', $now - (self::period($days) - 1) * 86400);

        return array($from, $to);
    }

    /** sudemi's GET /stats answer with every field present and of the right type. */
    public static function stats($body)
    {
        $body = is_array($body) ? $body : array();
        $int = function ($a, $k) {
            return isset($a[$k]) && is_numeric($a[$k]) ? (int) $a[$k] : 0;
        };
        $str = function ($a, $k) {
            return isset($a[$k]) && is_scalar($a[$k]) ? (string) $a[$k] : '';
        };

        $t = isset($body['totals']) && is_array($body['totals']) ? $body['totals'] : array();
        $totals = array();
        foreach (array('impressions', 'detail_views', 'cart_adds', 'orders', 'units', 'sales_minor', 'refunded_minor',
                     'commission_minor', 'net_minor', 'average_order_minor', 'conversion_bps') as $k) {
            $totals[$k] = $int($t, $k);
        }

        $daily = array();
        foreach ((isset($body['daily']) && is_array($body['daily']) ? $body['daily'] : array()) as $d) {
            if (!is_array($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $str($d, 'day'))) {
                continue;
            }
            $daily[] = array('day' => $str($d, 'day'), 'impressions' => $int($d, 'impressions'),
                'detail_views' => $int($d, 'detail_views'), 'cart_adds' => $int($d, 'cart_adds'),
                'orders' => $int($d, 'orders'), 'sales_minor' => $int($d, 'sales_minor'));
        }
        usort($daily, function ($a, $b) {
            return strcmp($a['day'], $b['day']);
        });

        $top = array();
        foreach ((isset($body['top_products']) && is_array($body['top_products']) ? $body['top_products'] : array()) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $top[] = array('external_id' => $str($p, 'external_id'), 'title' => $str($p, 'title'),
                'impressions' => $int($p, 'impressions'), 'detail_views' => $int($p, 'detail_views'),
                'cart_adds' => $int($p, 'cart_adds'), 'orders' => $int($p, 'orders'), 'units' => $int($p, 'units'),
                'sales_minor' => $int($p, 'sales_minor'));
        }

        $statuses = array();
        foreach ((isset($body['orders_by_status']) && is_array($body['orders_by_status']) ? $body['orders_by_status'] : array()) as $k => $v) {
            if (is_string($k) && preg_match('/^[A-Z_]{2,40}$/', $k) && is_numeric($v) && (int) $v > 0) {
                $statuses[$k] = (int) $v;
            }
        }
        arsort($statuses);

        $countries = array();
        foreach ((isset($body['countries']) && is_array($body['countries']) ? $body['countries'] : array()) as $c) {
            if (is_array($c) && preg_match('/^[A-Z]{2}$/', $str($c, 'country'))) {
                $countries[] = array('country' => $str($c, 'country'), 'orders' => $int($c, 'orders'),
                    'sales_minor' => $int($c, 'sales_minor'));
            }
        }

        $l = isset($body['listings']) && is_array($body['listings']) ? $body['listings'] : array();

        return array(
            'currency' => preg_match('/^[A-Z]{3}$/', $str($body, 'currency')) ? $str($body, 'currency') : 'EUR',
            'from' => $str($body, 'from'),
            'to' => $str($body, 'to'),
            'totals' => $totals,
            'daily' => $daily,
            'top_products' => $top,
            'orders_by_status' => $statuses,
            'countries' => $countries,
            'listings' => array('total' => $int($l, 'total'), 'listed' => $int($l, 'listed'), 'not_listed' => $int($l, 'not_listed')),
        );
    }

    /** One listing of GET /listings, every field present. */
    public static function listing($item)
    {
        $item = is_array($item) ? $item : array();
        $int = function ($k) use ($item) {
            return isset($item[$k]) && is_numeric($item[$k]) ? (int) $item[$k] : 0;
        };
        $str = function ($k) use ($item) {
            return isset($item[$k]) && is_scalar($item[$k]) ? (string) $item[$k] : '';
        };
        $reasons = array();
        foreach ((isset($item['not_listed_reasons']) && is_array($item['not_listed_reasons']) ? $item['not_listed_reasons'] : array()) as $r) {
            if (is_string($r)) {
                $reasons[] = in_array($r, self::REASONS, true) ? $r : 'other';
            }
        }
        $listed = !empty($item['listed']);
        if (!$listed && empty($reasons)) {
            $reasons[] = 'other';
        }

        return array(
            'external_id' => $str('external_id'),
            'title' => $str('title'),
            'sku' => $str('sku'),
            'price_minor' => $int('price_minor'),
            'currency' => preg_match('/^[A-Z]{3}$/', $str('currency')) ? $str('currency') : 'EUR',
            'quantity' => $int('quantity'),
            'in_stock' => !empty($item['in_stock']),
            'enabled' => !array_key_exists('enabled', $item) || !empty($item['enabled']),
            'listed' => $listed,
            'reasons' => array_values(array_unique($reasons)),
            'images' => $int('images'),
            'shipping_countries' => $int('shipping_countries'),
            'updated_at' => $str('updated_at'),
            'impressions_30d' => $int('impressions_30d'),
            'detail_views_30d' => $int('detail_views_30d'),
            'orders_30d' => $int('orders_30d'),
        );
    }

    /** The OpenCart product id an external id names ("53"), or 0. */
    public static function productId($externalId)
    {
        return preg_match('/^[1-9]\d{0,9}$/', (string) $externalId) ? (int) $externalId : 0;
    }

    /** Orders per 100 product views, one decimal ("6.4"), from basis points. */
    public static function conversion($bps)
    {
        return number_format(max(0, (int) $bps) / 100, 1, '.', '');
    }

    /** A count with thousands separators. */
    public static function count($n)
    {
        return number_format((int) $n, 0, '.', ',');
    }

    /**
     * The daily chart of one metric as inline SVG: an area and a line over every day of the period,
     * a dot per day with its value as a tooltip, three gridlines and the first, middle and last date.
     * Every text in it is escaped here.
     *
     * @param array    $daily  rows of stats()['daily']
     * @param string   $metric one of METRICS
     * @param callable $label  value -> the text a tooltip and the axis show
     * @param string   $title  the chart's accessible name
     */
    public static function chart(array $daily, $metric, $label, $title)
    {
        $metric = in_array($metric, self::METRICS, true) ? $metric : 'impressions';
        $w = 760;
        $h = 220;
        $left = 56;
        $right = 12;
        $top = 12;
        $bottom = 30;
        $pw = $w - $left - $right;
        $ph = $h - $top - $bottom;
        $e = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };

        $values = array();
        foreach ($daily as $d) {
            $values[] = isset($d[$metric]) ? max(0, (int) $d[$metric]) : 0;
        }
        $n = count($values);
        $max = $n ? max($values) : 0;
        $scale = self::niceMax($max);

        $svg = '<svg class="sudemi-chart-svg" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . $e($title) . '">';
        // Gridlines with their values.
        foreach (array(0, 0.5, 1) as $f) {
            $y = round($top + $ph - $f * $ph, 1);
            $svg .= '<line class="sudemi-grid" x1="' . $left . '" y1="' . $y . '" x2="' . ($w - $right) . '" y2="' . $y . '"/>';
            $svg .= '<text class="sudemi-axis" x="' . ($left - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . $e(call_user_func($label, (int) round($scale * $f))) . '</text>';
        }
        if ($n === 0) {
            return $svg . '</svg>';
        }

        $x = function ($i) use ($n, $left, $pw) {
            return round($n === 1 ? $left + $pw / 2 : $left + $i * $pw / ($n - 1), 1);
        };
        $y = function ($v) use ($scale, $top, $ph) {
            return round($top + $ph - ($scale > 0 ? $v / $scale : 0) * $ph, 1);
        };
        $points = array();
        foreach ($values as $i => $v) {
            $points[] = $x($i) . ',' . $y($v);
        }
        $base = $top + $ph;
        $svg .= '<path class="sudemi-area" d="M' . $x(0) . ',' . $base . ' L' . implode(' L', $points) . ' L' . $x($n - 1) . ',' . $base . ' Z"/>';
        $svg .= '<polyline class="sudemi-line" points="' . implode(' ', $points) . '"/>';
        foreach ($values as $i => $v) {
            $svg .= '<circle class="sudemi-dot" cx="' . $x($i) . '" cy="' . $y($v) . '" r="' . ($n > 45 ? 2 : 3) . '"><title>'
                . $e($daily[$i]['day'] . ': ' . call_user_func($label, $v)) . '</title></circle>';
        }
        // Dates: first, middle, last.
        $marks = array_unique(array(0, (int) floor(($n - 1) / 2), $n - 1));
        foreach ($marks as $i) {
            $anchor = $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle');
            $svg .= '<text class="sudemi-axis" x="' . $x($i) . '" y="' . ($h - 8) . '" text-anchor="' . $anchor . '">'
                . $e(substr($daily[$i]['day'], 5)) . '</text>';
        }

        return $svg . '</svg>';
    }

    /** A round top for the chart's scale: 1, 2, 2.5 or 5 times a power of ten, at least the maximum. */
    public static function niceMax($max)
    {
        $max = (int) $max;
        if ($max <= 0) {
            return 4;
        }
        $power = pow(10, floor(log10($max)));
        foreach (array(1, 2, 2.5, 5, 10) as $step) {
            if ($step * $power >= $max) {
                return (int) ceil($step * $power);
            }
        }

        return (int) ceil(10 * $power);
    }
}
