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

class Catalogue
{
    private $db;
    private $config;
    private $prefix;

    public function __construct($db, $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->prefix = DB_PREFIX;
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
            'SELECT p.product_id, p.model, p.sku, p.ean, p.mpn, p.price, p.quantity, p.weight,
                    p.image, pd.name, pd.description
               FROM ' . $this->prefix . 'product p
               LEFT JOIN ' . $this->prefix . 'product_description pd
                 ON (p.product_id = pd.product_id)
              WHERE pd.language_id = ' . $languageId . ' AND p.status = 1
              ORDER BY p.product_id ASC
              LIMIT ' . $offset . ', ' . $limit
        );

        $products = [];
        foreach ($query->rows as $row) {
            $productId = (int) $row['product_id'];
            $products[] = [
                'externalId' => (string) $productId,
                'sku' => (string) $row['sku'],
                'gtin' => (string) $row['ean'],
                'mpn' => (string) $row['mpn'],
                'title' => (string) $row['name'],
                'description' => strip_tags((string) $row['description']),
                'url' => $this->productUrl($productId),
                'priceMinor' => $this->minor($row['price']),
                'currency' => $this->config->get('config_currency'),
                'inStock' => ((int) $row['quantity']) > 0,
                'quantity' => (int) $row['quantity'],
                'weightGrams' => (int) round((float) $row['weight'] * 1000),
                'categoryExternalIds' => $this->categoryIds($productId),
                'images' => $this->images($productId, (string) $row['image']),
                'specs' => $this->specs($productId, $languageId),
            ];
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
            'SELECT product_id, price, quantity FROM ' . $this->prefix . 'product
              WHERE product_id = ' . (int) $productId
        );
        if (empty($query->row)) {
            return null;
        }

        return [
            'externalId' => (string) $query->row['product_id'],
            'priceMinor' => $this->minor($query->row['price']),
            'currency' => $this->config->get('config_currency'),
            'inStock' => ((int) $query->row['quantity']) > 0,
            'quantity' => (int) $query->row['quantity'],
        ];
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
        $base = rtrim((string) $this->config->get('config_url'), '/');
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
        return rtrim((string) $this->config->get('config_url'), '/')
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
}
