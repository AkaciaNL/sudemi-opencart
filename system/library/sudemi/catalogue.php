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

require_once __DIR__ . '/withdrawal.php';

class Catalogue
{
    private $db;
    private $config;
    private $prefix;
    private $shipping;
    private $weightValues = null;

    public function __construct($db, $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->prefix = DB_PREFIX;
        $this->shipping = new Shipping($db, $config);
    }

    public function shop()
    {
        return [
            'displayName' => $this->config->get('config_name'),
            'description' => $this->config->get('config_meta_description'),
            'languages' => [substr((string) $this->config->get('config_language'), 0, 2)],
            'currency' => $this->config->get('config_currency'),
            'supportEmail' => $this->config->get('config_email'),
            'shipsTo' => [],
        ];
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
                'name' => (string) $row['name'],
                'description' => strip_tags((string) $row['description']),
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
     */
    public function products($offset, $limit)
    {
        $languageId = (int) $this->config->get('config_language_id');
        $offset = max(0, (int) $offset);
        $limit = max(1, min(500, (int) $limit));

        $query = $this->db->query(
            'SELECT p.product_id, p.model, ' . $this->legacyCodeColumns() . ' p.price, p.quantity, p.weight,
                    p.weight_class_id, p.tax_class_id, p.shipping, p.image, pd.name, pd.description,
                    m.name AS manufacturer' . $this->withdrawalColumn() . '
               FROM ' . $this->prefix . 'product p
               LEFT JOIN ' . $this->prefix . 'manufacturer m ON (p.manufacturer_id = m.manufacturer_id)' . $this->withdrawalJoin() . '
               LEFT JOIN ' . $this->prefix . 'product_description pd
                 ON (p.product_id = pd.product_id)
              WHERE pd.language_id = ' . $languageId . ' AND p.status = 1
                AND p.product_id NOT IN (SELECT po.product_id FROM ' . $this->prefix . 'product_option po WHERE po.required = 1)
              ORDER BY p.product_id ASC
              LIMIT ' . $offset . ', ' . $limit
        );

        $codes = $this->codes(array_map(function ($row) { return (int) $row['product_id']; }, $query->rows));
        $products = [];
        foreach ($query->rows as $row) {
            $productId = (int) $row['product_id'];
            // OpenCart 4.1 moved SKU/EAN/UPC/MPN from product columns to product_code rows.
            foreach (['sku', 'ean', 'upc', 'mpn'] as $field) {
                if (!isset($row[$field]) || $row[$field] === '') {
                    $row[$field] = isset($codes[$productId][strtoupper($field)]) ? $codes[$productId][strtoupper($field)] : '';
                }
            }
            $products[] = Withdrawal::apply([
                'externalId' => (string) $productId,
                'sku' => (string) $row['sku'],
                'gtin' => ((string) $row['ean']) !== '' ? (string) $row['ean'] : (((string) $row['upc']) !== '' ? (string) $row['upc'] : null),
                'mpn' => (string) $row['mpn'],
                'title' => (string) $row['name'],
                'description' => strip_tags((string) $row['description']),
                'url' => $this->productUrl($productId),
                // OpenCart stores prices without tax; buyers are shown and charged the price with it.
                'priceMinor' => $this->minor($this->shipping->withStoreTax($this->effectivePrice($productId, $row['price']), $row['tax_class_id'])),
                'currency' => $this->config->get('config_currency'),
                'inStock' => ((int) $row['quantity']) > 0,
                'quantity' => (int) $row['quantity'],
                'weightGrams' => (int) round($this->kilograms($row['weight'], $row['weight_class_id']) * 1000),
                // Without a shipping price for the buyer's country sudemi cannot offer the product.
                'shipping' => $this->shipping->ratesFor($this->kilograms($row['weight'], $row['weight_class_id']), (bool) $row['shipping']),
                'maxHandlingTimeDays' => $this->shipping->handlingDays(),
                'maxTransitTimeDays' => $this->shipping->transitDays(),
                'brand' => ((string) $row['manufacturer']) !== '' ? (string) $row['manufacturer'] : null,
                'categoryExternalIds' => $this->categoryIds($productId),
                'images' => $this->images($productId, (string) $row['image']),
                'specs' => $this->specs($productId, $languageId),
            // Excluded from the right of withdrawal (EU 2011/83 art. 16), with the reason; omitted
            // when the normal right applies. A variant carries its master product's setting.
            ], isset($row['withdrawal_exclusion']) ? $row['withdrawal_exclusion'] : null);
        }

        return $products;
    }

    /**
     * A cheap statement of what this catalogue is.
     *
     * Product count, newest modification date and the extension's own change counter, hashed.
     * It does not have to describe the catalogue — only to change whenever the catalogue does,
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
            (string) $query->row['n'] . '|' . (string) $query->row['newest'] . '|' . (int) $changes
        );
    }

    public function productCount()
    {
        $query = $this->db->query(
            'SELECT COUNT(*) AS n FROM ' . $this->prefix . 'product WHERE status = 1'
        );

        return empty($query->row) ? 0 : (int) $query->row['n'];
    }

    public function priceAndStock($productId)
    {
        $query = $this->db->query(
            'SELECT product_id, price, tax_class_id, quantity FROM ' . $this->prefix . 'product
              WHERE product_id = ' . (int) $productId . '
                AND product_id NOT IN (SELECT po.product_id FROM ' . $this->prefix . 'product_option po WHERE po.required = 1)'
        );
        if (empty($query->row)) {
            return null;
        }

        return [
            'externalId' => (string) $query->row['product_id'],
            'priceMinor' => $this->minor($this->shipping->withStoreTax($this->effectivePrice((int) $query->row['product_id'], $query->row['price']), $query->row['tax_class_id'])),
            'currency' => $this->config->get('config_currency'),
            'inStock' => ((int) $query->row['quantity']) > 0,
            'quantity' => (int) $query->row['quantity'],
        ];
    }

    /** The right-of-withdrawal exclusion, from the master product for a variant (none before the table exists). */
    private function withdrawalColumn()
    {
        return $this->hasTable('sudemi_product') ? ', sw.withdrawal_exclusion' : '';
    }

    private function withdrawalJoin()
    {
        if (!$this->hasTable('sudemi_product')) {
            return '';
        }
        $master = $this->db->query("SHOW COLUMNS FROM `" . $this->prefix . "product` LIKE 'master_id'");
        $owner = empty($master->row) ? 'p.product_id' : 'IF(p.master_id > 0, p.master_id, p.product_id)';

        return ' LEFT JOIN ' . $this->prefix . 'sudemi_product sw ON (sw.product_id = ' . $owner . ')';
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
                    'name' => (string) $row['name'],
                    'values' => [(string) $row['text']],
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

        return (int) round((float) $price * 100);
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

    /**
     * Enabled products with a required option (a size to pick, say). They are not offered on
     * sudemi: an order from a buyer's AI assistant carries no option, so the shop could not tell
     * which item to send. OpenCart variants (each a product of its own) are the way to offer them.
     */
    public function withRequiredOptions()
    {
        $query = $this->db->query(
            'SELECT COUNT(DISTINCT p.product_id) AS n FROM ' . $this->prefix . 'product p
               JOIN ' . $this->prefix . 'product_option po ON (po.product_id = p.product_id AND po.required = 1)
              WHERE p.status = 1'
        );

        return empty($query->row) ? 0 : (int) $query->row['n'];
    }

    /**
     * The price the storefront charges for one item today: an active special, else an active
     * single-item discount, else the base price -- for the shop's default customer group, as
     * OpenCart's own catalog model computes it. Sending the base price showed products on sudemi
     * dearer than in the shop while a special ran.
     */
    private function effectivePrice($productId, $basePrice)
    {
        $group = (int) $this->config->get('config_customer_group_id');
        $active = "((date_start = '0000-00-00' OR date_start < NOW()) AND (date_end = '0000-00-00' OR date_end > NOW()))";
        if ($this->hasTable('product_special')) {
            // OpenCart 4.0: specials have their own table, prices are absolute.
            $special = $this->db->query('SELECT price FROM ' . $this->prefix . 'product_special
                WHERE product_id = ' . (int) $productId . ' AND customer_group_id = ' . $group . ' AND ' . $active . '
                ORDER BY priority ASC, price ASC LIMIT 1');

            return empty($special->row) ? (float) $basePrice : (float) $special->row['price'];
        }
        if (!$this->hasTable('product_discount')) {
            return (float) $basePrice;
        }
        // OpenCart 4.1: specials and discounts share product_discount; type F = fixed price,
        // P = percentage off, S = amount off.
        foreach ([1, 0] as $isSpecial) {
            $row = $this->db->query('SELECT price, type FROM ' . $this->prefix . 'product_discount
                WHERE product_id = ' . (int) $productId . ' AND customer_group_id = ' . $group . '
                  AND quantity = 1 AND special = ' . $isSpecial . ' AND ' . $active . '
                ORDER BY priority ASC, price ASC LIMIT 1');
            if (!empty($row->row)) {
                $value = (float) $row->row['price'];
                $type = (string) $row->row['type'];
                if ($type === 'P') {
                    return (float) $basePrice - (float) $basePrice * $value / 100;
                }
                if ($type === 'S') {
                    return (float) $basePrice - $value;
                }

                return $value;
            }
        }

        return (float) $basePrice;
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
