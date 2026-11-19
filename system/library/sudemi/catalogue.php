<?php
/**
 * Reads this shop's catalogue out of OpenCart.
 *
 * Separated from {@see Sync} so that the thing which talks to sudemi holds no SQL and the thing
 * which holds SQL makes no network calls. Every query here is parameterised through OpenCart's
 * own escaping; none of it interpolates a request value.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

require_once __DIR__ . '/currency.php';

require_once __DIR__ . '/withdrawal.php';

require_once __DIR__ . '/options.php';
require_once __DIR__ . '/issues.php';

class Catalogue
{
    private $db;
    private $config;
    private $prefix;
    private $shipping;
    private $weightValues = null;
    private $options;

    /** Products with more option combinations than are sent: product_id => how many (see takeCapped()). */
    private $capped = [];

    /** What was left out while building the listings, for the push's issues (Issues); see takeIssues(). */
    private $issues = [];

    /** The issues found since the last call, emptied. */
    public function takeIssues()
    {
        $issues = array_values($this->issues);
        $this->issues = [];

        return $issues;
    }

    public function __construct($db, $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->prefix = DB_PREFIX;
        $this->shipping = new Shipping($db, $config);
        $this->options = new Options($db, $config);
    }

    /**
     * A name as text. OpenCart stores what the admin typed HTML-escaped (its Request class runs
     * htmlspecialchars over all input), so "Salt & Pepper" is "Salt &amp; Pepper" in the database;
     * sudemi wants the text itself and escapes it where it shows it.
     */
    public static function text($value)
    {
        return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * A description as plain text. Decoded first, because the stored HTML is itself escaped
     * ("&lt;p&gt;", on which strip_tags does nothing); then the tags go, and the entities the
     * HTML held ("&amp;", "&nbsp;") are decoded too. A no-break space becomes a plain space.
     */
    public static function plain($value)
    {
        $text = strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\u{00A0}", ' ', $text));
    }

    /** Settings the Shop profile page saves (1.4.0); they win over the store's own values. */
    const PROFILE_DESCRIPTION = 'sudemi_profile_description';
    const PROFILE_SUPPORT_EMAIL = 'sudemi_profile_support_email';
    const PROFILE_RETURNS_DAYS = 'sudemi_profile_returns_days';

    public function shop()
    {
        $description = (string) $this->config->get(self::PROFILE_DESCRIPTION);
        $email = (string) $this->config->get(self::PROFILE_SUPPORT_EMAIL);
        $returns = (int) $this->config->get(self::PROFILE_RETURNS_DAYS);
        $shop = [
            'displayName' => self::text($this->config->get('config_name')),
            // What the shop owner wrote for sudemi on the Shop profile page, else the store's meta
            // description: the hourly push must not undo an edit made on that page.
            'description' => $description !== '' ? $description : self::plain($this->config->get('config_meta_description')),
            'languages' => [substr((string) $this->config->get('config_language'), 0, 2)],
            'currency' => $this->config->get('config_currency'),
            'supportEmail' => $email !== '' ? $email : $this->config->get('config_email'),
            'shipsTo' => [],
        ];
        if ($returns > 0) {
            $shop['returnsWindowDays'] = $returns;
        }

        return $shop;
    }

    public function categories()
    {
        $languageId = (int) $this->config->get('config_language_id');
        $query = $this->db->query(
            'SELECT c.category_id, c.parent_id, cd.name, cd.description
               FROM ' . $this->prefix . 'category c
               LEFT JOIN ' . $this->prefix . 'category_description cd
                 ON (c.category_id = cd.category_id)
              WHERE cd.language_id = ' . $languageId . ' AND c.status = 1'
        );

        $categories = [];
        foreach ($query->rows as $row) {
            $categories[] = [
                'externalId' => (string) $row['category_id'],
                'parentExternalId' => !empty($row['parent_id']) ? (string) $row['parent_id'] : null,
                'name' => self::text($row['name']),
                'description' => self::plain($row['description']),
            ];
        }

        return $categories;
    }

    /**
     * A page of products, as sudemi stores them.
     *
     * Images are sent as URLs on this shop's own origin, never as bytes: sudemi fetches them
     * through its egress guard and refuses anything not on the verified shop, which stops a
     * payload walking it around an internal network one image at a time.
     *
     * A product with required options (1.4.3) is one listing per combination of their values
     * (see Options); a product whose required option is typed rather than chosen has none.
     *
     * @param int|null   $productId only this product; null for a page of all
     * @param int[]|null $ids       only these products (a page of a full sync, a change push)
     */
    public function products($offset, $limit, $productId = null, $ids = null)
    {
        if (is_array($ids)) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (empty($ids)) {
                return [];
            }
        }
        $languageId = (int) $this->config->get('config_language_id');
        $offset = max(0, (int) $offset);
        $limit = max(1, min(500, (int) $limit));

        $query = $this->db->query(
            'SELECT p.product_id, p.model, ' . $this->legacyCodeColumns() . ' p.price, p.quantity, p.weight,
                    p.weight_class_id, p.tax_class_id, p.shipping, p.image, pd.name, pd.description,
                    p.subtract, p.minimum, p.stock_status_id, p.date_available, p.length, p.width, p.height, p.length_class_id,
                    m.name AS manufacturer' . $this->options->variantColumns('p') . $this->withdrawalColumn() . '
               FROM ' . $this->prefix . 'product p
               LEFT JOIN ' . $this->prefix . 'manufacturer m ON (p.manufacturer_id = m.manufacturer_id)' . $this->withdrawalJoin() . '
               LEFT JOIN ' . $this->prefix . 'product_description pd
                 ON (p.product_id = pd.product_id)
              WHERE pd.language_id = ' . $languageId . ' AND p.status = 1' . ($productId !== null ? ' AND p.product_id = ' . (int) $productId : '')
                . (is_array($ids) ? ' AND p.product_id IN (' . implode(',', $ids) . ')' : '') . '
              ORDER BY p.product_id ASC
              LIMIT ' . $offset . ', ' . $limit
        );

        $codes = $this->codes(array_map(function ($row) { return (int) $row['product_id']; }, $query->rows));
        $groups = $this->variantGroups(array_map(function ($row) { return (int) $row['product_id']; }, $query->rows));
        $plans = $this->options->plans($query->rows);
        $products = [];
        foreach ($query->rows as $row) {
            $productId = (int) $row['product_id'];
            $plan = $plans[$productId];
            if ($plan['problem'] !== null) {
                // A required option the buyer types (text, a file, a date): not offered, and the
                // extension page says so (optionReport()); sudemi is told with the push (issues).
                $issue = Issues::forProblem($productId, $plan['problem']);
                $this->issues[$productId . '|' . $issue['code']] = $issue;
                continue;
            }
            // OpenCart 4.1 moved SKU/EAN/UPC/MPN from product columns to product_code rows.
            foreach (['sku', 'ean', 'upc', 'mpn'] as $field) {
                if (!isset($row[$field]) || $row[$field] === '') {
                    $row[$field] = isset($codes[$productId][strtoupper($field)]) ? $codes[$productId][strtoupper($field)] : '';
                }
            }
            $listing = [
                'externalId' => (string) $productId,
                'sku' => (string) $row['sku'],
                'gtin' => ((string) $row['ean']) !== '' ? (string) $row['ean'] : (((string) $row['upc']) !== '' ? (string) $row['upc'] : null),
                'mpn' => (string) $row['mpn'],
                'title' => self::text($row['name']),
                // OpenCart 4 variants share their master product's id: sudemi shows them as one
                // product with a choice of sizes or colours. Null for a product without variants.
                'itemGroupId' => isset($groups[$productId]) ? (string) $groups[$productId] : null,
                'description' => self::plain($row['description']),
                'url' => $this->productUrl($productId),
                // OpenCart stores prices without tax; buyers are shown and charged the price with it.
                'priceMinor' => null, // with the sale and quantity prices below (pricing())
                'currency' => $this->config->get('config_currency'),
                'inStock' => ((int) $row['quantity']) > 0,
                'quantity' => (int) $row['quantity'],
                'weightGrams' => (int) round($this->kilograms($row['weight'], $row['weight_class_id']) * 1000),
                // Without a shipping price for the buyer's country sudemi cannot offer the product.
                'shipping' => $this->shipping->ratesFor($this->kilograms($row['weight'], $row['weight_class_id']), (bool) $row['shipping']),
                'maxHandlingTimeDays' => $this->shipping->handlingDays(),
                'maxTransitTimeDays' => $this->shipping->transitDays(),
                'brand' => self::text($row['manufacturer']) !== '' ? self::text($row['manufacturer']) : null,
                'categoryExternalIds' => $this->categoryIds($productId),
                'images' => $this->images($productId, (string) $row['image']),
                'specs' => $this->specs($productId, $languageId),
            ];
            $listing = array_merge($listing, $this->pricing($productId, $row['price'], $row['tax_class_id']),
                $this->availability($row, (int) $row['quantity']), $this->extras($row));
            // Excluded from the right of withdrawal (EU 2011/83 art. 16), with the reason; omitted
            // when the normal right applies. A variant carries its master product's setting.
            $exclusion = isset($row['withdrawal_exclusion']) ? $row['withdrawal_exclusion'] : null;
            // The shop accepts returns of personalised items of this product (owner decision 2):
            // false is sent too, so switching it off reaches sudemi. Every combination carries it.
            $listing['personalisationReturnable'] = !empty($row['personalisation_returnable']);
            if (!empty($plan['texts'])) {
                // Text the buyer types (a name to print): sudemi asks for it before checkout.
                $listing['personalisation'] = $this->personalisation($plan, $row['tax_class_id']);
            }
            if (!Options::hasOptions($plan)) {
                $products[] = Withdrawal::apply($listing, $exclusion);
                continue;
            }
            if (Options::count($plan) > Options::MAX_COMBINATIONS) {
                $this->capped[$productId] = Options::count($plan);
                $this->issues[$productId . '|' . Issues::VARIANT_CAP] = Issues::make($productId, Issues::VARIANT_CAP, 'warning', 'options',
                    sprintf('%d combinations of the required options; only the first %d are listed.', Options::count($plan), Options::MAX_COMBINATIONS),
                    ['combinations' => Options::count($plan), 'listed' => Options::MAX_COMBINATIONS]);
            }
            foreach (Options::combinations($plan) as $combo) {
                $products[] = Withdrawal::apply($this->combination($listing, $row, $plan, $combo), $exclusion);
            }
        }

        return $products;
    }

    /**
     * One combination of a product's options as a listing of its own: the options' price, weight
     * and stock applied as OpenCart's cart applies them, the chosen values first in the specs.
     */
    private function combination(array $listing, array $row, array $plan, array $combo)
    {
        $productId = (int) $row['product_id'];
        $picks = Options::picks($plan, $combo);
        $add = Options::adjustments($picks);
        $kilograms = $this->kilograms((float) $row['weight'] + $add['weight'], $row['weight_class_id']);
        $quantity = $add['quantity'] === null ? (int) $row['quantity'] : min((int) $row['quantity'], $add['quantity']);
        $named = Options::named($picks);
        $listing['externalId'] = Options::externalId($productId, $combo);
        $listing['title'] = Options::title($listing['title'], $picks);
        // One group: the product, or its master product for an OpenCart 4 variant.
        $listing['itemGroupId'] = $listing['itemGroupId'] !== null ? $listing['itemGroupId'] : (string) $productId;
        if (!empty($combo)) {
            // The barcode and part number are the product's, not one size's: sent with every
            // combination they would tell sudemi the sizes are one and the same item.
            $listing['gtin'] = null;
            $listing['mpn'] = '';
        }
        foreach (['regularPriceMinor', 'salePriceMinor', 'salePriceFrom', 'salePriceUntil', 'quantityPrices',
                     'availability', 'availabilityDate', 'availabilityText', 'allowBackorders'] as $key) {
            unset($listing[$key]);
        }
        $listing = array_merge($listing, $this->pricing($productId, $row['price'], $row['tax_class_id'], $add['price']),
            $this->availability($row, $quantity));
        $listing['quantity'] = max(0, $quantity);
        $listing['inStock'] = $quantity > 0;
        $listing['weightGrams'] = (int) round($kilograms * 1000);
        $listing['shipping'] = $this->shipping->ratesFor($kilograms, (bool) $row['shipping']);
        $listing['specs'] = array_merge($named, $listing['specs']);
        $listing['variantAttributes'] = Options::attributes($picks);

        return $listing;
    }

    /**
     * The price fields of a listing, as OpenCart's cart prices it for the shop's default customer
     * group, with tax, in minor units: priceMinor (today's price for one item); with a special
     * (running or the next one to come) regularPriceMinor, salePriceMinor and its window
     * (salePriceFrom / salePriceUntil, so sudemi starts and ends it on time); quantityPrices from
     * the product's discounts for 2 or more. $optionPrice: the chosen options' price (+/-).
     */
    private function pricing($productId, $basePrice, $taxClassId, $optionPrice = 0.0)
    {
        $minor = function ($price) use ($taxClassId) {
            return $this->minor($this->shipping->withStoreTax(max(0.0, (float) $price), $taxClassId));
        };
        $current = $this->effectivePrice($productId, $basePrice, $optionPrice);
        $out = ['priceMinor' => $minor($current)];
        $rules = $this->priceRules($productId);
        $base = (float) $basePrice + (float) $optionPrice;
        // Without a special, today's price (a single-item discount included) is the regular one.
        $regular = $rules['single'] !== null ? $this->ruled($rules['single'], $base, $optionPrice) : $base;
        $sale = null;
        foreach ($rules['specials'] as $special) {
            if ($special['until'] === null || $special['until'] > time()) {
                $sale = $special;
                break;
            }
        }
        if ($sale !== null) {
            $out['regularPriceMinor'] = $minor($regular);
            $out['salePriceMinor'] = $minor($this->ruled($sale, $base, $optionPrice));
            if ($sale['from'] !== null) {
                $out['salePriceFrom'] = date(DATE_ATOM, $sale['from']);
            }
            if ($sale['until'] !== null) {
                $out['salePriceUntil'] = date(DATE_ATOM, $sale['until']);
            }
        }
        $tiers = [];
        foreach ($rules['tiers'] as $tier) {
            $quantity = (int) $tier['quantity'];
            if ($quantity >= 2 && $quantity <= 100000 && !isset($tiers[$quantity]) && count($tiers) < 20) {
                $tiers[$quantity] = ['minQuantity' => $quantity, 'priceMinor' => $minor($this->ruled($tier, $base, $optionPrice))];
            }
        }
        if (!empty($tiers)) {
            ksort($tiers);
            $out['quantityPrices'] = array_values($tiers);
        }

        return $out;
    }

    /** A special or discount applied to a price with its options, as the cart applies it. */
    private function ruled(array $rule, $price, $optionPrice)
    {
        if ($rule['type'] === 'P') {
            return $price - $price * $rule['price'] / 100;
        }
        if ($rule['type'] === 'S') {
            return $price - $rule['price'];
        }

        // An absolute price (OpenCart 4.0, or 4.1's type F): the options on top.
        return $rule['price'] + $optionPrice;
    }

    private $rules = [];

    /**
     * A product's specials and discounts for the default customer group, read once per product:
     * 'specials' (running or to come, soonest first, each with from / until as timestamps, until
     * exclusive as OpenCart's "date_end > NOW()"), 'single' (an active discount for one item, 4.1)
     * and 'tiers' (active discounts for 2 or more, by quantity then priority).
     */
    private function priceRules($productId)
    {
        $productId = (int) $productId;
        if (isset($this->rules[$productId])) {
            return $this->rules[$productId];
        }
        $group = (int) $this->config->get('config_customer_group_id');
        $notEnded = "(date_end = '0000-00-00' OR date_end IS NULL OR date_end > NOW())";
        $active = "((date_start = '0000-00-00' OR date_start IS NULL OR date_start < NOW()) AND " . $notEnded . ')';
        $out = ['specials' => [], 'single' => null, 'tiers' => []];
        $rule = function ($row, $type = 'F') {
            $day = function ($date) {
                return $date === null || $date === '' || strpos((string) $date, '0000') === 0 ? null : strtotime($date . ' 00:00:00');
            };

            return ['price' => (float) $row['price'], 'type' => isset($row['type']) ? (string) $row['type'] : $type,
                'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : 1,
                'from' => isset($row['date_start']) ? $day($row['date_start']) : null,
                'until' => isset($row['date_end']) ? $day($row['date_end']) : null];
        };
        if ($this->hasTable('product_special')) {
            // OpenCart 4.0: specials in their own table, absolute prices; discounts absolute too.
            foreach ($this->db->query('SELECT price, date_start, date_end FROM ' . $this->prefix . 'product_special
                WHERE product_id = ' . $productId . ' AND customer_group_id = ' . $group . ' AND ' . $notEnded . '
                ORDER BY (date_start = \'0000-00-00\' OR date_start < NOW()) DESC, date_start ASC, priority ASC, price ASC')->rows as $row) {
                $out['specials'][] = $rule($row);
            }
            if ($this->hasTable('product_discount')) {
                foreach ($this->db->query('SELECT quantity, price FROM ' . $this->prefix . 'product_discount
                    WHERE product_id = ' . $productId . ' AND customer_group_id = ' . $group . ' AND quantity > 1 AND ' . $active . '
                    ORDER BY quantity ASC, priority ASC, price ASC')->rows as $row) {
                    $out['tiers'][] = $rule($row);
                }
            }
        } elseif ($this->hasTable('product_discount')) {
            // OpenCart 4.1: specials and discounts share product_discount (special = 1 / 0), each
            // a fixed price (F), a percentage off (P) or an amount off (S).
            foreach ($this->db->query('SELECT price, type, quantity, date_start, date_end FROM ' . $this->prefix . 'product_discount
                WHERE product_id = ' . $productId . ' AND customer_group_id = ' . $group . ' AND special = 1 AND quantity <= 1 AND ' . $notEnded . '
                ORDER BY (date_start = \'0000-00-00\' OR date_start IS NULL OR date_start < NOW()) DESC, date_start ASC, priority ASC, price ASC')->rows as $row) {
                $out['specials'][] = $rule($row);
            }
            foreach ($this->db->query('SELECT price, type, quantity FROM ' . $this->prefix . 'product_discount
                WHERE product_id = ' . $productId . ' AND customer_group_id = ' . $group . ' AND special = 0 AND ' . $active . '
                ORDER BY quantity ASC, priority ASC, price ASC')->rows as $row) {
                if ((int) $row['quantity'] <= 1) {
                    $out['single'] = $out['single'] !== null ? $out['single'] : $rule($row);
                } else {
                    $out['tiers'][] = $rule($row);
                }
            }
        }

        return $this->rules[$productId] = $out;
    }

    /**
     * What sudemi tells a buyer about stock, as OpenCart's storefront handles it: in stock; when
     * the count is out, the shop's stock status text ("2-3 Days", "Pre-Order"), and either taken as
     * a backorder / pre-order (the shop's "Stock Checkout" is on, or it does not count this product)
     * or not sold. A product that only becomes available later (Date Available) is a pre-order.
     */
    private function availability(array $row, $quantity)
    {
        $checkout = (bool) $this->config->get('config_stock_checkout');
        $available = isset($row['date_available']) ? strtotime((string) $row['date_available']) : false;
        if ($available !== false && $available > time()) {
            return ['availability' => 'preorder', 'availabilityDate' => date('Y-m-d', $available), 'allowBackorders' => true];
        }
        if (isset($row['subtract']) && !(int) $row['subtract']) {
            // The shop does not count this product's stock: always for sale.
            return ['availability' => 'in_stock', 'allowBackorders' => true];
        }
        if ($quantity > 0) {
            return ['availability' => 'in_stock', 'allowBackorders' => $checkout];
        }
        $status = $this->stockStatus(isset($row['stock_status_id']) ? (int) $row['stock_status_id'] : 0);
        $out = ['availability' => 'out_of_stock', 'allowBackorders' => $checkout];
        if ($checkout) {
            $out['availability'] = preg_match('/pre.?order|vorbestell|precommand|preventa|prenota|przedsprzeda|предзаказ|ön.?sipari|预购|预订|طلب مسبق|הזמנה מוקדמת|voorbestel/iu', $status) ? 'preorder' : 'backorder';
        }
        if ($status !== '') {
            $out['availabilityText'] = mb_substr($status, 0, 100, 'UTF-8');
        }

        return $out;
    }

    private $stockStatuses = null;

    /** The shop's stock status text (Catalog > Stock Statuses) in the store language, '' when none. */
    private function stockStatus($id)
    {
        if ($this->stockStatuses === null) {
            $this->stockStatuses = [];
            foreach ($this->db->query('SELECT stock_status_id, name FROM ' . $this->prefix . 'stock_status
                WHERE language_id = ' . (int) $this->config->get('config_language_id'))->rows as $status) {
                $this->stockStatuses[(int) $status['stock_status_id']] = self::text($status['name']);
            }
        }

        return isset($this->stockStatuses[$id]) ? $this->stockStatuses[$id] : '';
    }

    /** The minimum quantity (OpenCart's "Minimum Quantity") and the dimensions in millimetres. */
    private function extras(array $row)
    {
        $out = [];
        if (isset($row['minimum']) && (int) $row['minimum'] > 1) {
            $out['minQuantity'] = min(100000, (int) $row['minimum']);
        }
        foreach (['length' => 'lengthMm', 'width' => 'widthMm', 'height' => 'heightMm'] as $column => $field) {
            $mm = isset($row[$column]) ? $this->millimetres($row[$column], isset($row['length_class_id']) ? $row['length_class_id'] : 0) : 0;
            if ($mm >= 1 && $mm <= 100000) {
                $out[$field] = $mm;
            }
        }

        return $out;
    }

    private $lengthValues = null;

    /** A length in millimetres from its length class (OpenCart: to = from * to.value / from.value); 0 when unknown. */
    private function millimetres($length, $lengthClassId)
    {
        if ((float) $length <= 0) {
            return 0;
        }
        if ($this->lengthValues === null) {
            $this->lengthValues = ['mm' => null, 'byId' => []];
            $query = $this->db->query(
                'SELECT lc.length_class_id, lc.value, lcd.unit FROM ' . $this->prefix . 'length_class lc
                   LEFT JOIN ' . $this->prefix . 'length_class_description lcd ON (lc.length_class_id = lcd.length_class_id)
                  WHERE lcd.language_id = ' . (int) $this->config->get('config_language_id')
            );
            $units = [];
            foreach ($query->rows as $class) {
                $this->lengthValues['byId'][(int) $class['length_class_id']] = (float) $class['value'];
                $units[strtolower((string) $class['unit'])] = (float) $class['value'];
            }
            // A class's value is how many of it make the default unit, so mm's value is that of a
            // known class times the millimetres in one of it (a shop without an "mm" class too).
            foreach (['mm' => 1, 'cm' => 10, 'm' => 1000, 'in' => 25.4] as $unit => $mm) {
                if (!empty($units[$unit])) {
                    $this->lengthValues['mm'] = $units[$unit] * $mm;
                    break;
                }
            }
        }
        $from = isset($this->lengthValues['byId'][(int) $lengthClassId]) ? $this->lengthValues['byId'][(int) $lengthClassId] : null;
        $mm = $this->lengthValues['mm'];

        return $from && $mm ? (int) round((float) $length * $mm / $from) : 0;
    }

    /** sudemi personalisation (texts, dates, choices), the add-on prices with tax in minor units. */
    private function personalisation(array $plan, $taxClassId)
    {
        $out = [];
        $minor = function ($price) use ($taxClassId) {
            return $price > 0 ? (int) $this->minor($this->shipping->withStoreTax($price, $taxClassId)) : 0;
        };
        foreach (Options::personalisation($plan) as $entry) {
            $price = $minor($entry['price']);
            unset($entry['price']);
            if (isset($entry['choices'])) {
                $entry['choices'] = array_map(function ($choice) use ($minor) {
                    return ['key' => $choice['key'], 'label' => $choice['label'], 'priceMinor' => $minor($choice['price'])];
                }, $entry['choices']);
            }
            $out[] = $entry + ['priceMinor' => $price];
        }

        return $out;
    }

    /**
     * Products sent with fewer combinations than they have (more than Options::MAX_COMBINATIONS),
     * product_id => how many they have; cleared once read, so each page is told once.
     */
    public function takeCapped()
    {
        $capped = $this->capped;
        $this->capped = [];

        return $capped;
    }

    /** One product's (first) listing, as a full sync sends it, or null when it is not listed. */
    public function listing($productId)
    {
        $products = $this->products(0, 1, (int) $productId);

        return empty($products) ? null : $products[0];
    }

    /** The listings of these products (enabled; one per option combination), in id order. */
    public function productsByIds(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        return $this->products(0, count($ids), null, $ids);
    }

    /**
     * Ids of the enabled products after $after, in id order: where a full sync continues after it
     * stopped. A product whose required option cannot be a listing has none (products()).
     */
    public function productIdsAfter($after, $limit)
    {
        $query = $this->db->query(
            'SELECT p.product_id FROM ' . $this->prefix . 'product p
              WHERE p.status = 1 AND p.product_id > ' . (int) $after . '
              ORDER BY p.product_id ASC LIMIT ' . max(1, (int) $limit)
        );

        return array_map(function ($row) { return (int) $row['product_id']; }, $query->rows);
    }

    /** The OpenCart 4 master product a variant belongs to, or the product itself. */
    public function familyOf($productId)
    {
        if (!$this->hasMasterColumn()) {
            return (int) $productId;
        }
        $query = $this->db->query('SELECT master_id FROM ' . $this->prefix . 'product
            WHERE product_id = ' . (int) $productId);

        return !empty($query->row) && (int) $query->row['master_id'] > 0 ? (int) $query->row['master_id'] : (int) $productId;
    }

    /**
     * The variant group of each of these products: the master product's id for a variant and for a
     * master that has variants. Products outside any group are left out.
     */
    public function variantGroups(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids) || !$this->hasMasterColumn()) {
            return [];
        }
        $list = implode(',', $ids);
        $query = $this->db->query('SELECT product_id, master_id FROM ' . $this->prefix . 'product
            WHERE product_id IN (' . $list . ') AND master_id > 0
            UNION SELECT DISTINCT master_id, master_id FROM ' . $this->prefix . 'product
            WHERE master_id IN (' . $list . ')');
        $groups = [];
        foreach ($query->rows as $row) {
            $groups[(int) $row['product_id']] = (int) $row['master_id'];
        }

        return $groups;
    }

    /** The product and, for a master product, every variant of it (any status); empty when deleted. */
    public function familyIds($productId)
    {
        $query = $this->db->query('SELECT product_id FROM ' . $this->prefix . 'product
            WHERE product_id = ' . (int) $productId
            . ($this->hasMasterColumn() ? ' OR master_id = ' . (int) $productId : '')
            . ' ORDER BY product_id ASC');

        return array_map(function ($row) { return (int) $row['product_id']; }, $query->rows);
    }

    private $masterColumn = null;

    private function hasMasterColumn()
    {
        if ($this->masterColumn === null) {
            $column = $this->db->query("SHOW COLUMNS FROM `" . $this->prefix . "product` LIKE 'master_id'");
            $this->masterColumn = !empty($column->row);
        }

        return $this->masterColumn;
    }

    /**
     * Keeps two requests from sending the same full sync at once. A MySQL named lock: released by
     * the database itself when the request ends, so a crash never leaves it held.
     */
    public function lock()
    {
        $query = $this->db->query("SELECT GET_LOCK('" . $this->lockName() . "', 0) AS got");

        return !empty($query->row) && (int) $query->row['got'] === 1;
    }

    public function unlock()
    {
        $this->db->query("SELECT RELEASE_LOCK('" . $this->lockName() . "') AS released");
    }

    private function lockName()
    {
        return 'sudemi_sync_' . substr(md5((defined('DB_DATABASE') ? DB_DATABASE : '') . '|' . $this->prefix), 0, 16);
    }

    /**
     * A cheap statement of what this catalogue is.
     *
     * Product count, newest modification date and the extension's own change counter, hashed.
     * It does not have to describe the catalogue - only to change whenever the catalogue does,
     * so that sudemi's copy of it can disagree. All three are needed: the count alone misses an
     * edited price, the date alone misses a deletion, and neither reliably moves for a stock
     * change, which is why the counter is passed in.
     *
     * @param int $changes the local change counter, from Heartbeat
     */
    public function revision($changes)
    {
        $query = $this->db->query(
            'SELECT COUNT(*) AS n, COALESCE(MAX(date_modified), \'\') AS newest
               FROM ' . $this->prefix . 'product WHERE status = 1'
        );
        if (empty($query->row)) {
            return null;
        }

        return hash(
            'sha256',
            (string) $query->row['n'] . '|' . (string) $query->row['newest'] . '|' . (int) $changes . '|' . $this->optionsFingerprint()
        );
    }

    /**
     * The products' options and the shop's option names, folded into a few numbers (1.4.3): an
     * option renamed under Catalog > Options, or a value's stock changed outside the product form,
     * changes no product's date_modified, yet changes listings (their titles, their stock).
     */
    private function optionsFingerprint()
    {
        $parts = [];
        $tables = [
            'product_option' => "product_option_id, product_id, option_id, required",
            'product_option_value' => "product_option_value_id, product_option_id, quantity, subtract, price, price_prefix, weight, weight_prefix",
            'option_description' => "option_id, language_id, name",
            'option_value_description' => "option_value_id, language_id, name",
        ];
        foreach ($tables as $table => $columns) {
            $query = $this->db->query('SELECT COUNT(*) AS n, COALESCE(BIT_XOR(CRC32(CONCAT_WS(\'|\', ' . $columns . '))), 0) AS h
                FROM ' . $this->prefix . $table);
            $parts[] = empty($query->row) ? '-' : $query->row['n'] . ':' . $query->row['h'];
        }

        return implode('|', $parts);
    }

    public function productCount()
    {
        $query = $this->db->query(
            'SELECT COUNT(*) AS n FROM ' . $this->prefix . 'product WHERE status = 1'
        );

        return empty($query->row) ? 0 : (int) $query->row['n'];
    }

    /** A price push for a product listed as itself; null for one listed per option combination. */
    public function priceAndStock($productId)
    {
        $query = $this->db->query(
            'SELECT product_id, price, tax_class_id, quantity, subtract, stock_status_id, date_available' . $this->options->variantColumns('p') . '
               FROM ' . $this->prefix . 'product p WHERE product_id = ' . (int) $productId
        );
        if (empty($query->row)) {
            return null;
        }
        $plans = $this->options->plans([$query->row]);
        $plan = $plans[(int) $productId];
        if ($plan['problem'] !== null || Options::hasOptions($plan)) {
            return null;
        }
        $pricing = $this->pricing((int) $query->row['product_id'], $query->row['price'], $query->row['tax_class_id']);
        // sudemi ends a running sale on a price update without the sale fields: they are sent.
        unset($pricing['quantityPrices']);
        $availability = $this->availability($query->row, (int) $query->row['quantity']);
        unset($availability['availabilityDate'], $availability['availabilityText']);

        return array_merge([
            'externalId' => (string) $query->row['product_id'],
            'currency' => $this->config->get('config_currency'),
            'inStock' => ((int) $query->row['quantity']) > 0,
            'quantity' => (int) $query->row['quantity'],
        ], $pricing, $availability);
    }

    /** The right-of-withdrawal exclusion, from the master product for a variant (none before the table exists). */
    private function withdrawalColumn()
    {
        return ($this->hasTable('sudemi_product') ? ', sw.withdrawal_exclusion' : '')
            // 1.4.4: the shop accepts returns of personalised items (the master product's for a variant).
            . ($this->hasTable('sudemi_product_returnable') ? ', IF(sr.product_id IS NULL, 0, 1) AS personalisation_returnable' : '');
    }

    private function withdrawalJoin()
    {
        $master = $this->db->query("SHOW COLUMNS FROM `" . $this->prefix . "product` LIKE 'master_id'");
        $owner = empty($master->row) ? 'p.product_id' : 'IF(p.master_id > 0, p.master_id, p.product_id)';
        $join = '';
        if ($this->hasTable('sudemi_product')) {
            $join .= ' LEFT JOIN ' . $this->prefix . 'sudemi_product sw ON (sw.product_id = ' . $owner . ')';
        }
        if ($this->hasTable('sudemi_product_returnable')) {
            $join .= ' LEFT JOIN ' . $this->prefix . 'sudemi_product_returnable sr ON (sr.product_id = ' . $owner . ')';
        }

        return $join;
    }

    /** OpenCart 4.0 has sku/upc/ean/mpn columns on product; 4.1 has none (see codes()). */
    private function legacyCodeColumns()
    {
        $query = $this->db->query("SHOW COLUMNS FROM `" . $this->prefix . "product` LIKE 'ean'");

        return empty($query->row) ? '' : 'p.sku, p.ean, p.upc, p.mpn,';
    }

    /** product_id => [CODE => value] from OpenCart 4.1's product_code table (empty before 4.1). */
    private function codes(array $productIds)
    {
        $ids = array_filter(array_map('intval', $productIds));
        if (empty($ids)) {
            return [];
        }
        $exists = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($this->prefix . 'product_code') . "'");
        if (empty($exists->row)) {
            return [];
        }
        $query = $this->db->query(
            'SELECT product_id, UPPER(code) AS code, value FROM ' . $this->prefix . 'product_code
              WHERE product_id IN (' . implode(',', $ids) . ')'
        );
        $out = [];
        foreach ($query->rows as $row) {
            $out[(int) $row['product_id']][(string) $row['code']] = (string) $row['value'];
        }

        return $out;
    }

    /** The product's weight in kilograms, from its own weight class (OpenCart: to = from * to.value / from.value). */
    private function kilograms($weight, $weightClassId)
    {
        if ($this->weightValues === null) {
            $this->weightValues = ['kg' => null, 'byId' => []];
            $query = $this->db->query(
                'SELECT wc.weight_class_id, wc.value, wcd.unit FROM ' . $this->prefix . 'weight_class wc
                   LEFT JOIN ' . $this->prefix . 'weight_class_description wcd ON (wc.weight_class_id = wcd.weight_class_id)
                  WHERE wcd.language_id = ' . (int) $this->config->get('config_language_id')
            );
            foreach ($query->rows as $row) {
                $this->weightValues['byId'][(int) $row['weight_class_id']] = (float) $row['value'];
                if (strtolower((string) $row['unit']) === 'kg') {
                    $this->weightValues['kg'] = (float) $row['value'];
                }
            }
        }
        $from = isset($this->weightValues['byId'][(int) $weightClassId]) ? $this->weightValues['byId'][(int) $weightClassId] : null;
        $kg = $this->weightValues['kg'];
        if (!$from || !$kg) {
            return (float) $weight;
        }

        return (float) $weight * $kg / $from;
    }

    private function categoryIds($productId)
    {
        $query = $this->db->query(
            'SELECT category_id FROM ' . $this->prefix . 'product_to_category
              WHERE product_id = ' . (int) $productId
        );
        $ids = [];
        foreach ($query->rows as $row) {
            $ids[] = (string) $row['category_id'];
        }

        return $ids;
    }

    private function images($productId, $main)
    {
        $base = $this->storeBase();
        $images = [];
        if ($main !== '') {
            $images[] = $base . '/image/' . $main;
        }
        $query = $this->db->query(
            'SELECT image FROM ' . $this->prefix . 'product_image
              WHERE product_id = ' . (int) $productId . ' ORDER BY sort_order ASC'
        );
        foreach ($query->rows as $row) {
            if (!empty($row['image'])) {
                $images[] = $base . '/image/' . $row['image'];
            }
        }

        return $images;
    }

    private function specs($productId, $languageId)
    {
        $query = $this->db->query(
            'SELECT ad.name, pa.text
               FROM ' . $this->prefix . 'product_attribute pa
               LEFT JOIN ' . $this->prefix . 'attribute_description ad
                 ON (pa.attribute_id = ad.attribute_id)
              WHERE pa.product_id = ' . (int) $productId . '
                AND pa.language_id = ' . (int) $languageId . '
                AND ad.language_id = ' . (int) $languageId
        );
        $specs = [];
        foreach ($query->rows as $row) {
            if (!empty($row['name'])) {
                $specs[] = [
                    'name' => self::text($row['name']),
                    'values' => [self::text($row['text'])],
                ];
            }
        }

        return $specs;
    }

    private function productUrl($productId)
    {
        return $this->storeBase()
            . '/index.php?route=product/product&product_id=' . (int) $productId;
    }

    /** Money in minor units: a float price is a rounding error waiting for a checkout. */
    private function minor($price)
    {
        if ($price === null || $price === '') {
            return null;
        }

        // In the shop currency's own minor units (no fixed * 100: JPY has none, KWD has three).
        return Currency::toMinor($price, $this->config->get('config_currency'));
    }

    /**
     * The storefront's base URL. OpenCart 4 has no config_url setting for the default store; its
     * address is the HTTP_CATALOG constant in the admin and HTTP_SERVER in the storefront.
     */
    private function storeBase()
    {
        $url = (string) $this->config->get('config_url');
        if ($url === '' && defined('HTTP_CATALOG')) {
            $url = HTTP_CATALOG;
        } elseif ($url === '' && defined('HTTP_SERVER')) {
            $url = HTTP_SERVER;
        }

        return rtrim($url, '/');
    }

    /** At most this many products are named in each list of the extension page's option report. */
    const REPORT_NAMED = 20;

    /**
     * What happens to the enabled products with required options, for the extension page:
     * 'offered' (how many are sent as one listing per combination), 'skipped' (not offered: a
     * required option is typed rather than chosen, or has no values; each with the option and
     * why) and 'capped' (more combinations than Options::MAX_COMBINATIONS; only the first are
     * sent). The lists name at most REPORT_NAMED products; the *_total counts are complete.
     */
    public function optionReport()
    {
        $report = ['offered' => 0, 'skipped' => [], 'skipped_total' => 0, 'capped' => [], 'capped_total' => 0];
        $withOptions = 'SELECT po.product_id FROM ' . $this->prefix . 'product_option po';
        $master = $this->hasMasterColumn() ? ' OR p.master_id IN (' . $withOptions . ')' : '';
        $after = 0;
        do {
            $query = $this->db->query(
                'SELECT p.product_id, pd.name' . $this->options->variantColumns('p') . '
                   FROM ' . $this->prefix . 'product p
                   LEFT JOIN ' . $this->prefix . 'product_description pd
                          ON (pd.product_id = p.product_id AND pd.language_id = ' . (int) $this->config->get('config_language_id') . ')
                  WHERE p.status = 1 AND p.product_id > ' . $after . '
                    AND (p.product_id IN (' . $withOptions . ')' . $master . ')
                  ORDER BY p.product_id ASC LIMIT 500'
            );
            $plans = $this->options->plans($query->rows);
            foreach ($query->rows as $row) {
                $id = (int) $row['product_id'];
                $after = $id;
                $plan = $plans[$id];
                $name = self::text($row['name']) !== '' ? self::text($row['name']) : '#' . $id;
                if ($plan['problem'] !== null) {
                    if (++$report['skipped_total'] <= self::REPORT_NAMED) {
                        $report['skipped'][] = ['product_id' => $id, 'name' => $name] + $plan['problem'];
                    }
                    continue;
                }
                if (!Options::hasOptions($plan)) {
                    continue;
                }
                ++$report['offered'];
                if (Options::count($plan) > Options::MAX_COMBINATIONS && ++$report['capped_total'] <= self::REPORT_NAMED) {
                    $report['capped'][] = ['product_id' => $id, 'name' => $name, 'combinations' => Options::count($plan)];
                }
            }
        } while (count($query->rows) === 500);

        return $report;
    }

    /**
     * The listings last sent for these products (1.4.3), product_id => external ids; a product
     * never sent since is left out. What lets a change push notice that a listing went (an option
     * value removed) and have the next full sync take it off sudemi.
     */
    public function listedIds(array $productIds)
    {
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if (empty($ids) || !$this->hasTable('sudemi_listing')) {
            return [];
        }
        $query = $this->db->query('SELECT product_id, external_ids FROM ' . $this->prefix . 'sudemi_listing
            WHERE product_id IN (' . implode(',', $ids) . ')');
        $out = [];
        foreach ($query->rows as $row) {
            $out[(int) $row['product_id']] = (string) $row['external_ids'] === '' ? [] : explode(',', (string) $row['external_ids']);
        }

        return $out;
    }

    /** Remembers the listings just sent: product_id => external ids (see listedIds()). */
    public function rememberListed(array $listed)
    {
        if (empty($listed) || !$this->hasTable('sudemi_listing')) {
            return;
        }
        $known = $this->listedIds(array_keys($listed));
        foreach ($listed as $productId => $externalIds) {
            $externalIds = array_values(array_map('strval', $externalIds));
            if (isset($known[(int) $productId]) && $known[(int) $productId] === $externalIds) {
                continue;
            }
            $this->db->query('REPLACE INTO ' . $this->prefix . 'sudemi_listing (product_id, external_ids, date_modified)
                VALUES (' . (int) $productId . ", '" . $this->db->escape(implode(',', $externalIds)) . "', NOW())");
        }
    }

    /**
     * The price the storefront charges for one item today: an active special, else an active
     * single-item discount, else the base price -- for the shop's default customer group, as
     * OpenCart's own cart computes it, with the chosen options' price ($optionPrice, signed) added
     * where the cart adds it. Sending the base price showed products on sudemi dearer than in the
     * shop while a special ran.
     */
    private function effectivePrice($productId, $basePrice, $optionPrice = 0.0)
    {
        $optionPrice = (float) $optionPrice;
        $group = (int) $this->config->get('config_customer_group_id');
        $active = "((date_start = '0000-00-00' OR date_start < NOW()) AND (date_end = '0000-00-00' OR date_end > NOW()))";
        if ($this->hasTable('product_special')) {
            // OpenCart 4.0: specials have their own table, prices are absolute; options on top.
            $special = $this->db->query('SELECT price FROM ' . $this->prefix . 'product_special
                WHERE product_id = ' . (int) $productId . ' AND customer_group_id = ' . $group . ' AND ' . $active . '
                ORDER BY priority ASC, price ASC LIMIT 1');

            return (empty($special->row) ? (float) $basePrice : (float) $special->row['price']) + $optionPrice;
        }
        if (!$this->hasTable('product_discount')) {
            return (float) $basePrice + $optionPrice;
        }
        // OpenCart 4.1: specials and discounts share product_discount; type F = fixed price (plus
        // the options), P = percentage off, S = amount off (both of the price with the options).
        $price = (float) $basePrice + $optionPrice;
        foreach ([1, 0] as $isSpecial) {
            $row = $this->db->query('SELECT price, type FROM ' . $this->prefix . 'product_discount
                WHERE product_id = ' . (int) $productId . ' AND customer_group_id = ' . $group . '
                  AND quantity = 1 AND special = ' . $isSpecial . ' AND ' . $active . '
                ORDER BY priority ASC, price ASC LIMIT 1');
            if (!empty($row->row)) {
                $value = (float) $row->row['price'];
                $type = (string) $row->row['type'];
                if ($type === 'P') {
                    return $price - $price * $value / 100;
                }
                if ($type === 'S') {
                    return $price - $value;
                }

                return $value + $optionPrice;
            }
        }

        return $price;
    }

    private $tables = [];

    private function hasTable($name)
    {
        if (!array_key_exists($name, $this->tables)) {
            $this->tables[$name] = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($this->prefix . $name) . "'")->num_rows > 0;
        }

        return $this->tables[$name];
    }
}
