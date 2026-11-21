<?php
/**
 * Products excluded from the buyer's right of withdrawal (Directive 2011/83/EU, art. 16).
 *
 * The merchant marks a product with the legal reason it is excluded; sudemi tells the buyer
 * before they pay. Nothing is excluded by default, and only the reasons the directive itself
 * lists can be chosen: a buyer told "no withdrawal" without a lawful reason keeps the right
 * anyway, so a free-text reason would only mislead.
 *
 * Stored in the extension's own table, one row per excluded product (no row: the normal right
 * applies). A variant carries its master product's setting.
 *
 * Also owns the extension's schema upgrade. OpenCart runs install() only when the extension is
 * installed, not when its files are replaced by a newer version, so a shop that upgrades in place
 * would never get the table or the product-form events. ensureSchema() runs from every entry
 * point that already exists on such a shop (the settings page, the product-save events and the
 * cron beat), costs one settings read once done, and is idempotent.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class Withdrawal
{
    const FIELD = 'sudemi_withdrawal_exclusion';
    const SETTING_SCHEMA = 'sudemi_schema_version';
    const SCHEMA_VERSION = '1.4.7-1'; // 1.4.7: shipping rules sent when shipping settings change

    /**
     * Owner decision 2 (2026-11-19): an item made with a text the buyer typed is not returnable by
     * default (EU 2011/83 art. 16(c)). A shop may accept returns of it anyway, per product
     * ("Accept returns of personalised items", off by default): a row in sudemi_product_returnable
     * means on. The listing sends personalisationReturnable.
     */
    const FIELD_RETURNABLE = 'sudemi_personalisation_returnable';
    /** Set when an exclusion changed; the next heartbeat sends the catalogue. */
    const SETTING_RESYNC_DUE = 'sudemi_resync_due';

    /** The art. 16 exceptions sudemi accepts, as sent in the product payload. */
    const REASONS = ['hygiene', 'custom', 'perishable', 'media', 'press', 'mixed', 'alcohol'];

    /** Events added on top of the ones install() has always registered: [code, trigger, action, description]. */
    const EVENTS = [
        ['sudemi_product_form', 'admin/view/catalog/product_form/after', 'extension/sudemi/event.productForm',
            'sudemi: right of withdrawal on the product form'],
        // 1.3.0: the sudemi panel (tracking code, link to the sudemi order) on OpenCart's order page,
        // and the sudemi entries in the admin menu.
        ['sudemi_order_info', 'admin/view/sale/order_info/after', 'extension/sudemi/event.orderInfo',
            'sudemi: tracking code and sudemi order on the order page'],
        ['sudemi_menu', 'admin/view/common/column_left/before', 'extension/sudemi/event.menu',
            'sudemi: orders and shipping in the admin menu'],
        // 1.4.7: the shop's own shipping rules are sent again (by the cron entry) when a shipping
        // method's settings, the store settings (currency, weight class, country), a geo zone, a tax
        // or a weight class change, or a shipping extension is installed or removed.
        ['sudemi_ship_setting', 'admin/model/setting/setting.editSetting/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_setting_del', 'admin/model/setting/setting.deleteSetting/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_value', 'admin/model/setting/setting.editValue/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_ext_add', 'admin/model/setting/extension.install/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_ext_del', 'admin/model/setting/extension.uninstall/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_geo_add', 'admin/model/localisation/geo_zone.addGeoZone/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_geo_edit', 'admin/model/localisation/geo_zone.editGeoZone/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_geo_del', 'admin/model/localisation/geo_zone.deleteGeoZone/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_tax_rate', 'admin/model/localisation/tax_rate.editTaxRate/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_tax_rate_add', 'admin/model/localisation/tax_rate.addTaxRate/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_tax_rate_del', 'admin/model/localisation/tax_rate.deleteTaxRate/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_tax_class', 'admin/model/localisation/tax_class.editTaxClass/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_tax_class_del', 'admin/model/localisation/tax_class.deleteTaxClass/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_weight', 'admin/model/localisation/weight_class.editWeightClass/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
        ['sudemi_ship_weight_del', 'admin/model/localisation/weight_class.deleteWeightClass/after', 'extension/sudemi/event.shippingChanged',
            'sudemi: send the shop\'s shipping rules again'],
    ];

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * One of REASONS, or null for "normal right of withdrawal". Anything else is null: an unknown
     * value must never turn into an exclusion.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    public static function sanitize($value)
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return in_array($value, self::REASONS, true) ? $value : null;
    }

    /**
     * The product payload with the exclusion added when there is one; without the key otherwise.
     *
     * @param array $payload one product, as sent to /integration/v1/products
     * @param string|null $reason the stored value (sanitised again here)
     *
     * @return array
     */
    public static function apply(array $payload, $reason)
    {
        $reason = self::sanitize($reason);
        if ($reason !== null) {
            $payload['withdrawalExclusion'] = $reason;
        }

        return $payload;
    }

    /**
     * Creates the table and registers this version's events, once.
     *
     * @param SettingStore $store
     * @param bool $force run even when the stored schema version is current (install())
     */
    public function ensureSchema($store, $force = false)
    {
        if (!$force && $store->read(self::SETTING_SCHEMA) === self::SCHEMA_VERSION) {
            return;
        }
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'sudemi_product` (
                `product_id` INT(11) NOT NULL,
                `withdrawal_exclusion` VARCHAR(16) NOT NULL,
                `date_modified` DATETIME NOT NULL,
                PRIMARY KEY (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        // 1.3.0: this shop's copy of each sudemi order (the change feed's revision, status, refunded
        // amount, tracking and request), and the tracking code typed on the order before shipping.
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'sudemi_order` (
                `sudemi_order_id` CHAR(36) NOT NULL,
                `order_id` INT(11) NOT NULL DEFAULT 0,
                `revision` BIGINT NOT NULL DEFAULT 0,
                `status` VARCHAR(32) NOT NULL DEFAULT \'\',
                `refunded_minor` BIGINT NOT NULL DEFAULT 0,
                `tracking_carrier` VARCHAR(100) NOT NULL DEFAULT \'\',
                `tracking_code` VARCHAR(100) NOT NULL DEFAULT \'\',
                `request` VARCHAR(64) NOT NULL DEFAULT \'\',
                `date_modified` DATETIME NOT NULL,
                PRIMARY KEY (`sudemi_order_id`),
                KEY `order_id` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        // 1.3.1 (review 2026-11-12): which caller builds the shop order for a sudemi order. The row
        // is inserted before the order is built; its primary key lets exactly one caller in.
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'sudemi_order_claim` (
                `sudemi_order_id` CHAR(36) NOT NULL,
                `order_id` INT(11) NOT NULL DEFAULT 0,
                `claimed_at` DATETIME NOT NULL,
                PRIMARY KEY (`sudemi_order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        // 1.4.3: the listings last sent for each product (one per option combination), so a change
        // push notices a combination that went (Catalogue::listedIds).
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'sudemi_listing` (
                `product_id` INT(11) NOT NULL,
                `external_ids` MEDIUMTEXT NOT NULL,
                `date_modified` DATETIME NOT NULL,
                PRIMARY KEY (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        // 1.4.3: "Price for sudemi buyers" of a typed option (text, a date), set on the product form.
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'sudemi_option_price` (
                `product_option_id` INT(11) NOT NULL,
                `product_id` INT(11) NOT NULL,
                `price` DECIMAL(15,4) NOT NULL,
                `date_modified` DATETIME NOT NULL,
                PRIMARY KEY (`product_option_id`),
                KEY `product_id` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        // 1.4.4: the products whose personalised items the shop takes back (owner decision 2).
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'sudemi_product_returnable` (
                `product_id` INT(11) NOT NULL,
                `date_modified` DATETIME NOT NULL,
                PRIMARY KEY (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        foreach (self::EVENTS as $event) {
            $exists = $this->db->query("SELECT `event_id` FROM `" . DB_PREFIX . "event` WHERE `code` = '"
                . $this->db->escape($event[0]) . "'");
            if (empty($exists->row)) {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "event` SET `code` = '" . $this->db->escape($event[0])
                    . "', `description` = '" . $this->db->escape($event[3]) . "', `trigger` = '"
                    . $this->db->escape($event[1]) . "', `action` = '" . $this->db->escape($event[2])
                    . "', `status` = '1', `sort_order` = '1'");
            }
        }
        $store->write(self::SETTING_SCHEMA, self::SCHEMA_VERSION);
    }

    public function removeEvents()
    {
        foreach (self::EVENTS as $event) {
            $this->db->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `code` = '" . $this->db->escape($event[0]) . "'");
        }
    }

    /** @return string|null the product's own exclusion, null when the normal right applies */
    public function get($productId)
    {
        if (!$this->hasTable()) {
            return null;
        }
        $query = $this->db->query('SELECT withdrawal_exclusion FROM `' . DB_PREFIX . 'sudemi_product`
            WHERE product_id = ' . (int) $productId);

        return empty($query->row) ? null : self::sanitize($query->row['withdrawal_exclusion']);
    }

    /**
     * Stores the exclusion (null removes it).
     *
     * @return bool whether the stored value changed
     */
    public function set($productId, $reason)
    {
        $productId = (int) $productId;
        $reason = self::sanitize($reason);
        if ($productId <= 0 || !$this->hasTable() || $this->get($productId) === $reason) {
            return false;
        }
        if ($reason === null) {
            $this->forget($productId);
        } else {
            $this->db->query('REPLACE INTO `' . DB_PREFIX . 'sudemi_product` (product_id, withdrawal_exclusion, date_modified)
                VALUES (' . $productId . ", '" . $this->db->escape($reason) . "', NOW())");
        }

        return true;
    }

    public function forget($productId)
    {
        if ($this->hasTable()) {
            $this->db->query('DELETE FROM `' . DB_PREFIX . 'sudemi_product` WHERE product_id = ' . (int) $productId);
        }
        if ($this->hasReturnableTable()) {
            $this->db->query('DELETE FROM `' . DB_PREFIX . 'sudemi_product_returnable` WHERE product_id = ' . (int) $productId);
        }
    }

    /** Whether the shop accepts returns of this product when personalised (off before the table exists). */
    public function isReturnable($productId)
    {
        if (!$this->hasReturnableTable()) {
            return false;
        }
        $query = $this->db->query('SELECT product_id FROM `' . DB_PREFIX . 'sudemi_product_returnable` WHERE product_id = ' . (int) $productId);

        return !empty($query->row);
    }

    /**
     * Stores the setting.
     *
     * @return bool whether the stored value changed
     */
    public function setReturnable($productId, $on)
    {
        $productId = (int) $productId;
        $on = self::checked($on);
        if ($productId <= 0 || !$this->hasReturnableTable() || $this->isReturnable($productId) === $on) {
            return false;
        }
        if ($on) {
            $this->db->query('REPLACE INTO `' . DB_PREFIX . 'sudemi_product_returnable` (product_id, date_modified) VALUES (' . $productId . ', NOW())');
        } else {
            $this->db->query('DELETE FROM `' . DB_PREFIX . 'sudemi_product_returnable` WHERE product_id = ' . $productId);
        }

        return true;
    }

    /** A posted checkbox value: "1", "on", true are on; anything else off. Pure. */
    public static function checked($value)
    {
        return true === $value || 1 === $value || in_array(strtolower(trim((string) (is_scalar($value) ? $value : ''))), ['1', 'on', 'yes', 'true'], true);
    }

    private $returnableExists = null;

    public function hasReturnableTable()
    {
        if ($this->returnableExists !== true) {
            $this->returnableExists = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . 'sudemi_product_returnable') . "'")->num_rows > 0;
        }

        return $this->returnableExists;
    }

    private $tableExists = null;

    public function hasTable()
    {
        if ($this->tableExists !== true) {
            $this->tableExists = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . 'sudemi_product') . "'")->num_rows > 0;
        }

        return $this->tableExists;
    }
}
