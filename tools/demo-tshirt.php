<?php
/**
 * Makes (or brings back to its intended state) a demo T-shirt in an OpenCart 4 shop, to see how
 * the sudemi extension sends products with options. Safe to run again: it finds what it made by
 * the model code SUDEMI-DEMO-TSHIRT and the option names, and only adds what is missing.
 *
 *   Size      S, M, L, XL, XXL                                 (required select, same price)
 *   Colour    White +0, Black +2, Red +3, Navy +4, Green +5     (required radio)
 *   Material  Lycra +0, Cotton +10                             (required select)
 *   Custom name, max 20 characters, +20.00                     (optional text)
 *
 * 5 x 5 x 2 = 50 listings on sudemi, from 20.00 to 35.00 before tax, and the custom name as
 * personalisation. OpenCart itself puts no price on a text option: the +20.00 is the extension's
 * "Price for sudemi buyers" (shown under the option on the product form, kept when the product is
 * saved in the admin). Install or upgrade the extension to 1.4.3 first.
 *
 * Run inside the shop's container, from OpenCart's folder (the one with config.php):
 *
 *   docker exec -i <container> php -- /var/www/html < tools/demo-tshirt.php
 *
 * Then press "Send the catalogue now" on the sudemi page (or wait for the next heartbeat).
 * Not part of the extension (tools/ is left out of the release zip).
 *
 * @license GPL-3.0-or-later
 */

$root = rtrim(isset($argv[1]) ? $argv[1] : getcwd(), '/');
if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "No config.php in $root: give OpenCart's folder as the first argument.\n");
    exit(1);
}
require $root . '/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int) (defined('DB_PORT') ? DB_PORT : 3306));
$db->set_charset('utf8mb4');
$p = DB_PREFIX;

const MODEL = 'SUDEMI-DEMO-TSHIRT';
const OPTIONS = [
    ['Size', 'select', 101, [['S', 0], ['M', 0], ['L', 0], ['XL', 0], ['XXL', 0]]],
    ['Colour', 'radio', 102, [['White', 0], ['Black', 2], ['Red', 3], ['Navy', 4], ['Green', 5]]],
    ['Material', 'select', 103, [['Lycra', 0], ['Cotton', 10]]],
];
const TEXT = ['Custom name', 'text', 104, '/^.{1,20}$/', 20.0];

function q($sql)
{
    global $db;

    return $db->query($sql);
}

function one($sql)
{
    $r = q($sql);
    $row = $r instanceof mysqli_result ? $r->fetch_row() : null;

    return $row ? $row[0] : null;
}

function e($v)
{
    global $db;

    return "'" . $db->real_escape_string((string) $v) . "'";
}

function hasColumn($table, $column)
{
    global $p;

    return (bool) one("SHOW COLUMNS FROM `{$p}{$table}` LIKE " . e($column));
}

$languages = [];
$r = q("SELECT language_id FROM `{$p}language`");
while ($row = $r->fetch_row()) {
    $languages[] = (int) $row[0];
}
$english = (int) (one("SELECT language_id FROM `{$p}language` WHERE code LIKE 'en%' ORDER BY language_id LIMIT 1") ?: $languages[0]);

/** The option with this name and type (made when missing), with its values in order: [option_id, [name => option_value_id]]. */
function option($name, $type, $sort, array $values, $validation = '')
{
    global $p, $english, $languages;
    $id = one("SELECT o.option_id FROM `{$p}option` o JOIN `{$p}option_description` od ON od.option_id = o.option_id
        WHERE od.language_id = $english AND od.name = " . e($name) . ' AND o.type = ' . e($type) . ' ORDER BY o.option_id LIMIT 1');
    if (!$id) {
        q("INSERT INTO `{$p}option` SET type = " . e($type) . ', sort_order = ' . (int) $sort
            . (hasColumn('option', 'validation') ? ', validation = ' . e($validation) : ''));
        $id = one('SELECT LAST_INSERT_ID()');
        foreach ($languages as $l) {
            q("INSERT INTO `{$p}option_description` SET option_id = $id, language_id = $l, name = " . e($name));
        }
        echo "made option $name ($type)\n";
    }
    $ids = [];
    foreach ($values as $i => $value) {
        $vid = one("SELECT ov.option_value_id FROM `{$p}option_value` ov JOIN `{$p}option_value_description` ovd ON ovd.option_value_id = ov.option_value_id
            WHERE ov.option_id = $id AND ovd.language_id = $english AND ovd.name = " . e($value) . ' LIMIT 1');
        if (!$vid) {
            q("INSERT INTO `{$p}option_value` SET option_id = $id, image = '', sort_order = " . (int) $i);
            $vid = one('SELECT LAST_INSERT_ID()');
            foreach ($languages as $l) {
                q("INSERT INTO `{$p}option_value_description` SET option_value_id = $vid, language_id = $l, option_id = $id, name = " . e($value));
            }
        }
        $ids[$value] = (int) $vid;
    }

    return [(int) $id, $ids];
}

// The product.
$product = one("SELECT product_id FROM `{$p}product` WHERE model = " . e(MODEL) . ' LIMIT 1');
$kg = (int) (one("SELECT weight_class_id FROM `{$p}weight_class_description` WHERE unit = 'kg' LIMIT 1") ?: 1);
if (!$product) {
    q("INSERT INTO `{$p}product` SET model = " . e(MODEL) . ", quantity = 100, stock_status_id = 7, image = '', manufacturer_id = 0,
        shipping = 1, price = 20, points = 0, tax_class_id = 0, date_available = CURDATE(), weight = 0.2, weight_class_id = $kg,
        length = 0, width = 0, height = 0, length_class_id = 1, subtract = 1, minimum = 1, sort_order = 0, status = 1,
        date_added = NOW(), date_modified = NOW()" . (hasColumn('product', 'location') ? ", location = ''" : ''));
    $product = one('SELECT LAST_INSERT_ID()');
    foreach ($languages as $l) {
        q("INSERT INTO `{$p}product_description` SET product_id = $product, language_id = $l, name = 'sudemi demo T-shirt',
            description = " . e('&lt;p&gt;A plain T-shirt in five sizes, five colours and two materials. Add a name to print on the back.&lt;/p&gt;')
            . ", tag = '', meta_title = 'sudemi demo T-shirt', meta_description = '', meta_keyword = ''");
    }
    q("INSERT INTO `{$p}product_to_store` SET product_id = $product, store_id = 0");
    echo "made product $product\n";
} else {
    q("UPDATE `{$p}product` SET price = 20, status = 1, date_modified = NOW() WHERE product_id = $product");
    echo "found product $product\n";
}
$product = (int) $product;

// Its options and values, each value with 20 in stock.
foreach (OPTIONS as $option) {
    list($name, $type, $sort, $values) = $option;
    list($optionId, $valueIds) = option($name, $type, $sort, array_column($values, 0));
    $po = one("SELECT product_option_id FROM `{$p}product_option` WHERE product_id = $product AND option_id = $optionId");
    if (!$po) {
        q("INSERT INTO `{$p}product_option` SET product_id = $product, option_id = $optionId, value = '', required = 1");
        $po = one('SELECT LAST_INSERT_ID()');
    } else {
        q("UPDATE `{$p}product_option` SET required = 1 WHERE product_option_id = $po");
    }
    foreach ($values as $value) {
        list($valueName, $add) = $value;
        $vid = $valueIds[$valueName];
        $set = "price = $add, price_prefix = '+', weight = " . ($valueName === 'Cotton' ? '0.05' : '0') . ", weight_prefix = '+'";
        $pov = one("SELECT product_option_value_id FROM `{$p}product_option_value` WHERE product_option_id = $po AND option_value_id = $vid");
        if ($pov) {
            q("UPDATE `{$p}product_option_value` SET $set WHERE product_option_value_id = $pov");
        } else {
            q("INSERT INTO `{$p}product_option_value` SET product_option_id = $po, product_id = $product, option_id = $optionId,
                option_value_id = $vid, quantity = 20, subtract = 1, points = 0, points_prefix = '+', $set");
        }
    }
}

// The custom name: optional text, at most 20 characters, +20.00 for sudemi buyers. Stored as the
// extension's "Price for sudemi buyers" (1.4.3, survives saving the product in the admin); without
// the extension's table (an older extension), as a value row of the option, which it also reads.
list($name, $type, $sort, $validation, $price) = TEXT;
list($textId) = option($name, $type, $sort, [], $validation);
$po = one("SELECT product_option_id FROM `{$p}product_option` WHERE product_id = $product AND option_id = $textId");
if (!$po) {
    q("INSERT INTO `{$p}product_option` SET product_id = $product, option_id = $textId, value = '', required = 0");
    $po = one('SELECT LAST_INSERT_ID()');
}
q("DELETE FROM `{$p}product_option_value` WHERE product_option_id = $po");
if (one("SHOW TABLES LIKE " . e($p . 'sudemi_option_price'))) {
    q("REPLACE INTO `{$p}sudemi_option_price` (product_option_id, product_id, price, date_modified) VALUES ($po, $product, $price, NOW())");
} else {
    echo "the extension's option price table is missing (extension older than 1.4.3): the price is kept as a value row\n";
    q("INSERT INTO `{$p}product_option_value` SET product_option_id = $po, product_id = $product, option_id = $textId, option_value_id = 0,
        quantity = 0, subtract = 0, price = $price, price_prefix = '+', points = 0, points_prefix = '+', weight = 0, weight_prefix = '+'");
}

$combinations = (int) one("SELECT ROUND(EXP(SUM(LN(n)))) FROM (SELECT COUNT(*) AS n FROM `{$p}product_option_value` pov
    JOIN `{$p}product_option` po ON po.product_option_id = pov.product_option_id
    WHERE po.product_id = $product AND po.required = 1 GROUP BY po.product_option_id) t");
echo "product $product: $combinations combinations, custom name option $po (+$price)\n";
echo "Now press \"Send the catalogue now\" on the sudemi page, or wait for the next heartbeat.\n";
