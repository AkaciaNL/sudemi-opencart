<?php
/**
 * Reads and writes this extension's settings one at a time, stored as OpenCart setting rows in
 * the "sudemi" group (store 0).
 *
 * Talks to the database directly rather than through OpenCart's setting model: the storefront's
 * model has no editSetting in OpenCart 4, so the cron beat could never save anything, and the
 * admin model rewrites the whole group on every change. Shared by the admin page, both event
 * controllers and the cron endpoint.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class SettingStore
{
    const CODE = 'sudemi';

    private $db;
    private $cache = null;

    /** @param object $db OpenCart's database object ($this->db in a controller) */
    public function __construct($db)
    {
        $this->db = $db;
    }

    public function read($key)
    {
        $this->load();

        return isset($this->cache[$key]) ? $this->cache[$key] : null;
    }

    public function write($key, $value)
    {
        $this->load();
        $serialized = is_array($value) ? 1 : 0;
        $stored = $serialized ? json_encode($value) : (string) $value;
        $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = '" . self::CODE
            . "' AND `key` = '" . $this->db->escape($key) . "'");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = '" . self::CODE
            . "', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape($stored)
            . "', `serialized` = '" . $serialized . "'");
        $this->cache[$key] = $value;
    }

    public function delete($key)
    {
        $this->load();
        $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = '" . self::CODE
            . "' AND `key` = '" . $this->db->escape($key) . "'");
        unset($this->cache[$key]);
    }

    private function load()
    {
        if ($this->cache !== null) {
            return;
        }
        $this->cache = [];
        $query = $this->db->query("SELECT `key`, `value`, `serialized` FROM `" . DB_PREFIX . "setting`
            WHERE `store_id` = '0' AND `code` = '" . self::CODE . "'");
        foreach ($query->rows as $row) {
            $this->cache[$row['key']] = $row['serialized'] ? json_decode($row['value'], true) : $row['value'];
        }
    }
}
