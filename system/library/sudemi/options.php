<?php
/**
 * Product options as variants (1.4.3).
 *
 * Most OpenCart shops sell sizes and colours as options of one product: a required "Size" select,
 * a required "Colour" radio, each value with its own price (+/-), weight and stock. An order from
 * a buyer's AI assistant has no option form to fill in, so sudemi needs one listing per choice:
 * each combination of the values of the product's required choice options is a listing of its
 * own, "<product_id>:<product_option_value_id>-<product_option_value_id>", grouped under the
 * product. An order for that listing is written with those options, as a storefront order is.
 *
 * Mirrors OpenCart's own cart (system/library/cart/cart.php): the options come from the master
 * product for an OpenCart 4 variant; a variant fixes the options it overrides (override.variant)
 * to its own values; a value's price and weight are added or taken off by their prefix; a value
 * that subtracts stock limits the quantity.
 *
 * Options whose answer is typed (text, textarea, date, time, datetime) are sudemi personalisation:
 * the buyer's assistant asks for it and the order carries it. A file cannot be uploaded by an AI
 * assistant: a product with a required file option is not offered, and the extension page says
 * which one and why; an optional one is left out.
 *
 * The static functions hold no SQL, so they are tested without a database.
 *
 * @license GPL-3.0-or-later
 */

namespace Sudemi;

class Options
{
    /** At most this many listings per product; the extension page and the log say when a product has more. */
    const MAX_COMBINATIONS = 100;

    /** sudemi takes at most this many texts per listing, each at most MAX_TEXT characters long. */
    const MAX_TEXTS = 5;
    const MAX_TEXT = 200;
    const MAX_CHOICES = 100;

    /** Option types whose answer is one of a list of values (image: OpenCart 3's picture list). */
    const CHOICE_TYPES = ['select', 'radio', 'checkbox', 'image'];

    /**
     * Option types whose answer the buyer types: sent as sudemi personalisation (a name to print,
     * a delivery date). A date or time is asked for in the format OpenCart stores it in.
     */
    const TEXT_TYPES = ['text', 'textarea', 'date', 'time', 'datetime'];

    /** The format a date or time answer must have, as OpenCart's storefront fields send it. */
    const FORMATS = [
        'date' => ['note' => 'YYYY-MM-DD', 'pattern' => '/^\d{4}-\d{2}-\d{2}$/', 'php' => 'Y-m-d'],
        'time' => ['note' => 'HH:MM', 'pattern' => '/^\d{2}:\d{2}$/', 'php' => 'H:i'],
        'datetime' => ['note' => 'YYYY-MM-DD HH:MM', 'pattern' => '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', 'php' => 'Y-m-d H:i'],
    ];

    private $db;
    private $config;
    private $prefix;
    private $columns = [];

    public function __construct($db, $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->prefix = DB_PREFIX;
    }

    // ------------------------------------------------------------------ reading OpenCart ----

    /**
     * The option plan of each product (see plan()).
     *
     * @param array $products rows with product_id and, on OpenCart 4, master_id, variant and override
     *
     * @return array product_id => plan
     */
    public function plans(array $products)
    {
        $sources = [];
        foreach ($products as $row) {
            $sources[(int) $row['product_id']] = self::sourceOf($row);
        }
        if (empty($sources)) {
            return [];
        }
        $options = $this->optionsOf(array_values(array_unique($sources)));
        $plans = [];
        foreach ($products as $row) {
            $id = (int) $row['product_id'];
            $plans[$id] = self::plan(
                isset($options[$sources[$id]]) ? $options[$sources[$id]] : [],
                self::json(isset($row['variant']) ? $row['variant'] : ''),
                self::json(isset($row['override']) ? $row['override'] : '')
            );
        }

        return $plans;
    }

    /**
     * One product's plan, read from OpenCart; null when the product does not exist.
     *
     * @return array|null ['row' => product row (product_id, master_id, quantity, subtract, name), 'plan' => plan]
     */
    public function planOf($productId)
    {
        $query = $this->db->query(
            'SELECT p.product_id, p.quantity, p.subtract, p.status' . $this->variantColumns('p') . ', pd.name
               FROM ' . $this->prefix . 'product p
               LEFT JOIN ' . $this->prefix . 'product_description pd
                      ON pd.product_id = p.product_id AND pd.language_id = ' . (int) $this->config->get('config_language_id') . '
              WHERE p.product_id = ' . (int) $productId
        );
        if (empty($query->row)) {
            return null;
        }
        $plans = $this->plans([$query->row]);

        return ['row' => $query->row, 'plan' => $plans[(int) $productId]];
    }

    /** ", p.master_id, p.variant, p.override" where the columns exist (OpenCart 4), else "". */
    public function variantColumns($alias)
    {
        $columns = '';
        foreach (['master_id', 'variant', 'override'] as $column) {
            if ($this->hasColumn('product', $column)) {
                $columns .= ', ' . $alias . '.`' . $column . '`';
            }
        }

        return $columns;
    }

    /**
     * Every option of these products (required or not) with its values, in the storefront's
     * order: option sort order, then value sort order.
     *
     * @return array product_id => option[]
     */
    private function optionsOf(array $productIds)
    {
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if (empty($ids)) {
            return [];
        }
        $languageId = (int) $this->config->get('config_language_id');
        $query = $this->db->query(
            'SELECT po.product_option_id, po.product_id, po.option_id, po.required, o.type, od.name'
                . ($this->hasColumn('option', 'validation') ? ', o.validation' : '')
                . ($this->hasPriceTable() ? ', sop.price AS sudemi_price' : '') . '
               FROM ' . $this->prefix . 'product_option po
               LEFT JOIN `' . $this->prefix . 'option` o ON (o.option_id = po.option_id)'
                . ($this->hasPriceTable() ? ' LEFT JOIN `' . $this->prefix . self::PRICE_TABLE . '` sop ON (sop.product_option_id = po.product_option_id)' : '') . '
               LEFT JOIN ' . $this->prefix . 'option_description od
                      ON (od.option_id = po.option_id AND od.language_id = ' . $languageId . ')
              WHERE po.product_id IN (' . implode(',', $ids) . ')
              ORDER BY po.product_id ASC, o.sort_order ASC, po.product_option_id ASC'
        );
        if (empty($query->rows)) {
            return [];
        }
        $options = [];
        $byId = [];
        foreach ($query->rows as $row) {
            $byId[(int) $row['product_option_id']] = [
                'product_id' => (int) $row['product_id'],
                'option' => [
                    'product_option_id' => (int) $row['product_option_id'],
                    'option_id' => (int) $row['option_id'],
                    'name' => self::text($row['name']),
                    'type' => strtolower((string) $row['type']),
                    'required' => (bool) (int) $row['required'],
                    'maxLength' => self::maxLength(isset($row['validation']) ? $row['validation'] : ''),
                    // The "Price for sudemi buyers" set on the product form (typed options), or null.
                    'sudemiPrice' => isset($row['sudemi_price']) && $row['sudemi_price'] !== null ? (float) $row['sudemi_price'] : null,
                    'values' => [],
                ],
            ];
        }
        $values = $this->db->query(
            'SELECT pov.product_option_value_id, pov.product_option_id, pov.option_value_id, pov.quantity,
                    pov.subtract, pov.price, pov.price_prefix, pov.weight, pov.weight_prefix, ovd.name'
                . ($this->hasColumn('option_value', 'image') ? ', ov.image' : '') . '
               FROM ' . $this->prefix . 'product_option_value pov
               LEFT JOIN ' . $this->prefix . 'option_value ov ON (ov.option_value_id = pov.option_value_id)
               LEFT JOIN ' . $this->prefix . 'option_value_description ovd
                      ON (ovd.option_value_id = pov.option_value_id AND ovd.language_id = ' . $languageId . ')
              WHERE pov.product_option_id IN (' . implode(',', array_keys($byId)) . ')
              ORDER BY ov.sort_order ASC, pov.product_option_value_id ASC'
        );
        foreach ($values->rows as $row) {
            $optionId = (int) $row['product_option_id'];
            if (isset($byId[$optionId])) {
                $byId[$optionId]['option']['values'][] = self::value($row);
            }
        }
        foreach ($byId as $entry) {
            $options[$entry['product_id']][] = $entry['option'];
        }

        return $options;
    }

    /** The extension's own price of a typed option (1.4.3), keyed by product_option_id. */
    const PRICE_TABLE = 'sudemi_option_price';

    /** The name of the product form's field: sudemi_option_price[option_id]. */
    const PRICE_FIELD = 'sudemi_option_price';

    private $priceTable = null;

    public function hasPriceTable()
    {
        if ($this->priceTable === null) {
            $this->priceTable = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($this->prefix . self::PRICE_TABLE) . "'")->num_rows > 0;
        }

        return $this->priceTable;
    }

    /** A product's typed-option prices for the form: option_id => price ('' when none). */
    public function formPrices($productId)
    {
        if ((int) $productId <= 0 || !$this->hasPriceTable()) {
            return [];
        }
        $query = $this->db->query('SELECT po.option_id, sop.price FROM `' . $this->prefix . self::PRICE_TABLE . '` sop
            JOIN ' . $this->prefix . 'product_option po ON (po.product_option_id = sop.product_option_id)
            WHERE sop.product_id = ' . (int) $productId);
        $out = [];
        foreach ($query->rows as $row) {
            $out[(int) $row['option_id']] = self::amount($row['price']);
        }

        return $out;
    }

    /**
     * Stores the prices the product form sent (option_id => amount; '' removes it), against the
     * product_option_id OpenCart gave each option in this save, and drops the rows of options the
     * product no longer has.
     *
     * @return bool whether anything changed
     */
    public function savePrices($productId, array $posted)
    {
        $productId = (int) $productId;
        if ($productId <= 0 || !$this->hasPriceTable()) {
            return false;
        }
        $current = [];
        foreach ($this->db->query('SELECT product_option_id, option_id FROM ' . $this->prefix . 'product_option
            WHERE product_id = ' . $productId)->rows as $row) {
            $current[(int) $row['option_id']] = (int) $row['product_option_id'];
        }
        $before = $this->formPrices($productId);
        $changed = false;
        foreach ($posted as $optionId => $raw) {
            $optionId = (int) $optionId;
            if (!isset($current[$optionId])) {
                continue;
            }
            $amount = self::parseAmount($raw);
            if ($amount === null) {
                $this->db->query('DELETE FROM `' . $this->prefix . self::PRICE_TABLE . '` WHERE product_option_id = ' . $current[$optionId]);
            } else {
                $this->db->query('REPLACE INTO `' . $this->prefix . self::PRICE_TABLE . '` (product_option_id, product_id, price, date_modified)
                    VALUES (' . $current[$optionId] . ', ' . $productId . ", '" . $amount . "', NOW())");
            }
            $changed = $changed || (isset($before[$optionId]) ? $before[$optionId] : '') !== ($amount === null ? '' : self::amount($amount));
        }
        $this->db->query('DELETE FROM `' . $this->prefix . self::PRICE_TABLE . '` WHERE product_id = ' . $productId
            . (empty($current) ? '' : ' AND product_option_id NOT IN (' . implode(',', $current) . ')'));

        return $changed;
    }

    /** A typed amount ("20", "20,50", "+2.5", "-1") as a number string, null when empty or not a number. */
    public static function parseAmount($raw)
    {
        $raw = str_replace([' ', ','], ['', '.'], trim((string) $raw));
        if ($raw === '' || !preg_match('/^[+-]?\d{1,9}(\.\d{1,4})?$/', $raw)) {
            return null;
        }

        return number_format((float) $raw, 4, '.', '');
    }

    /** An amount for the form field: "20.00", "2.5" stays "2.50". */
    public static function amount($value)
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** Whether a column exists on one of OpenCart's tables (cached). */
    public function hasColumn($table, $column)
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $this->columns)) {
            $query = $this->db->query('SHOW COLUMNS FROM `' . $this->prefix . $table . "` LIKE '" . $this->db->escape($column) . "'");
            $this->columns[$key] = !empty($query->row);
        }

        return $this->columns[$key];
    }

    // ------------------------------------------------------------------- pure functions ----

    /** A name as text (OpenCart stores what the admin typed HTML-escaped); as Catalogue::text(). */
    public static function text($value)
    {
        return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** The product whose options apply: the master product for an OpenCart 4 variant. */
    public static function sourceOf(array $row)
    {
        return isset($row['master_id']) && (int) $row['master_id'] > 0 ? (int) $row['master_id'] : (int) $row['product_id'];
    }

    /** A product_option_value row as a value: price and weight signed by their prefix, as the cart adds them. */
    public static function value(array $row)
    {
        $signed = function ($amount, $prefix) {
            if ($prefix === '+') {
                return (float) $amount;
            }
            if ($prefix === '-') {
                return -(float) $amount;
            }

            return 0.0;
        };

        return [
            'product_option_value_id' => (int) $row['product_option_value_id'],
            'option_value_id' => (int) $row['option_value_id'],
            'name' => self::text(isset($row['name']) ? $row['name'] : ''),
            'quantity' => (int) $row['quantity'],
            'subtract' => (bool) (int) $row['subtract'],
            'price' => $signed($row['price'], (string) $row['price_prefix']),
            'weight' => $signed($row['weight'], (string) $row['weight_prefix']),
            // The value's own picture (a colour's photo), a path under image/; '' without one.
            'image' => isset($row['image']) ? trim((string) $row['image']) : '',
        ];
    }

    /**
     * What a product's options mean for its listings.
     *
     * 'choices': the required select, radio and image options the buyer picks one value of (one
     * listing per combination). 'fixed': the options an OpenCart 4 variant sets to its own values
     * (every listing of the variant carries them). 'texts': what the buyer fills in or picks
     * through sudemi's personalisation: text, textarea, date, time and datetime options, checkboxes
     * (several may be ticked) and optional choices, required or not.
     * 'problem': why the product cannot be offered (a required file option, or a required choice
     * without values), null when it can.
     *
     * @param array $options  the options of the product (of its master product for a variant)
     * @param array $variant  the variant's product.variant: product_option_id => value id(s)
     * @param array $override the variant's product.override; override.variant marks the fixed options
     */
    public static function plan(array $options, array $variant = [], array $override = [])
    {
        $plan = ['choices' => [], 'fixed' => [], 'texts' => [], 'problem' => null];
        $fixedIds = isset($override['variant']) && is_array($override['variant']) ? $override['variant'] : [];
        foreach ($options as $option) {
            $optionId = $option['product_option_id'];
            if (in_array($option['type'], self::TEXT_TYPES, true)) {
                $plan['texts'][] = $option;
                continue;
            }
            if (!empty($fixedIds[$optionId]) && isset($variant[$optionId])) {
                $chosen = array_map('intval', (array) $variant[$optionId]);
                $values = array_values(array_filter($option['values'], function ($value) use ($chosen) {
                    return in_array($value['product_option_value_id'], $chosen, true);
                }));
                if (!empty($values)) {
                    $option['values'] = $values;
                    $plan['fixed'][] = $option;
                }
                continue;
            }
            if (in_array($option['type'], self::CHOICE_TYPES, true)
                && ($option['type'] === 'checkbox' || !$option['required'])) {
                // A checkbox (several may be ticked) and an optional choice (gift wrap +3.00) are
                // sudemi personalisation choices: the buyer picks, each choice with its own price.
                // A value without stock (and keeping stock) is not offered, as the storefront hides it.
                $option['values'] = array_values(array_filter($option['values'], function ($value) {
                    return !$value['subtract'] || $value['quantity'] > 0;
                }));
                if (!empty($option['values'])) {
                    $plan['texts'][] = $option;
                } elseif ($option['required']) {
                    $plan['problem'] = ['option' => $option['name'], 'type' => $option['type'], 'reason' => 'empty'];

                    return $plan;
                }
                continue;
            }
            if (!$option['required']) {
                continue;
            }
            if (!in_array($option['type'], self::CHOICE_TYPES, true)) {
                $plan['problem'] = ['option' => $option['name'], 'type' => $option['type'], 'reason' => 'type'];

                return $plan;
            }
            if (empty($option['values'])) {
                $plan['problem'] = ['option' => $option['name'], 'type' => $option['type'], 'reason' => 'empty'];

                return $plan;
            }
            $plan['choices'][] = $option;
        }

        return $plan;
    }

    /**
     * The longest answer an option's validation pattern allows ("/^.{1,20}$/" is 20), or null
     * when it sets no plain upper bound. OpenCart 4.1 checks typed options against that pattern.
     */
    public static function maxLength($validation)
    {
        if (preg_match('/\{(?:\d+\s*,\s*)?(\d+)\}\$?\/?[a-z]*$/i', trim((string) $validation), $m)) {
            return (int) $m[1] > 0 ? (int) $m[1] : null;
        }

        return null;
    }

    /**
     * The text options as sudemi personalisation: [{key, label, required, maxLength, price}], key
     * the product_option_id. price is the add-on before tax (Catalogue turns it into priceMinor):
     * OpenCart itself puts no price on a typed option, so it is the extension's own "Price for
     * sudemi buyers" from the product form, else the price of a value row kept on that option.
     */
    /**
     * The typed and chosen options sudemi can ask, and what it cannot (owner 2026-11-19): an
     * option with a price that lowers the price (a "-" value, a negative add-on) cannot be charged
     * through sudemi yet. A required one keeps the product off sudemi (blocked, an error issue);
     * an optional one is left out (a warning issue). More than MAX_TEXTS required ones block the
     * product; optional ones beyond it are left out. Pure.
     *
     * @return array{texts:array,issues:array,blocked:string|null} texts: the plan's options to
     *               ask; issues: [code, severity, option name, why]
     */
    public static function screen(array $plan)
    {
        $texts = [];
        $issues = [];
        $blocked = null;
        foreach ($plan['texts'] as $option) {
            $negative = false;
            if (in_array($option['type'], self::CHOICE_TYPES, true)) {
                foreach ($option['values'] as $value) {
                    $negative = $negative || (float) $value['price'] < 0;
                }
            } else {
                $price = 0.0;
                if (isset($option['sudemiPrice']) && $option['sudemiPrice'] !== null) {
                    $price = (float) $option['sudemiPrice'];
                } else {
                    foreach ($option['values'] as $value) {
                        $price += (float) $value['price'];
                    }
                }
                $negative = $price < 0;
            }
            if ($negative) {
                $issues[] = ['addon_negative_price', $option['required'] ? 'error' : 'warning', (string) $option['name'],
                    'it lowers the price (a discount), which sudemi cannot charge yet'];
                if ($option['required'] && $blocked === null) {
                    $blocked = 'addon_negative_price';
                }
                continue;
            }
            $texts[] = $option;
        }
        $required = count(array_filter($texts, function ($o) {
            return !empty($o['required']);
        }));
        if ($required > self::MAX_TEXTS) {
            $issues[] = ['too_many_required_texts', 'error', 'options', sprintf('%d required texts or choices; sudemi asks at most %d', $required, self::MAX_TEXTS)];
            $blocked = $blocked === null ? 'too_many_required_texts' : $blocked;
        } elseif (count($texts) > self::MAX_TEXTS) {
            usort($texts, function ($a, $b) {
                return (int) $b['required'] - (int) $a['required'];
            });
            foreach (array_slice($texts, self::MAX_TEXTS) as $left) {
                $issues[] = ['too_many_optional_texts', 'warning', (string) $left['name'], 'sudemi asks at most ' . self::MAX_TEXTS];
            }
        }

        return ['texts' => $texts, 'issues' => $issues, 'blocked' => $blocked];
    }

    public static function personalisation(array $plan)
    {
        // At most MAX_TEXTS (sudemi's limit), the required ones first.
        $texts = $plan['texts'];
        usort($texts, function ($a, $b) {
            return (int) $b['required'] - (int) $a['required'];
        });

        return array_map(function ($option) {
            if (in_array($option['type'], self::CHOICE_TYPES, true)) {
                return [
                    'key' => (string) $option['product_option_id'],
                    'label' => self::label($option['name']),
                    'required' => (bool) $option['required'],
                    'type' => $option['type'] === 'checkbox' ? 'multi_choice' : 'choice',
                    // Only a text needs it; a core that does not know choices yet reads the item
                    // as a text, and without it would refuse the whole product.
                    'maxLength' => self::MAX_TEXT,
                    'price' => 0.0,
                    // Each value its own price (+/-; sudemi only adds, so a "-" is no add-on).
                    'choices' => self::choiceList($option['values']),
                ];
            }
            // The price set on the product form for sudemi buyers; without one, a value row kept on
            // the option (how some option-price extensions store it); else nothing.
            $price = 0.0;
            if (isset($option['sudemiPrice']) && $option['sudemiPrice'] !== null) {
                $price = $option['sudemiPrice'];
            } else {
                foreach ($option['values'] as $value) {
                    $price += $value['price'];
                }
            }
            // sudemi adds a price when the text is filled in, never takes one off.
            $price = max(0.0, $price);

            $format = isset(self::FORMATS[$option['type']]) ? self::FORMATS[$option['type']] : null;
            $note = $format === null ? '' : ' (' . $format['note'] . ')';
            // sudemi takes a label of 1 to 100 characters and a maxLength of 1 to 200; a text
            // option without a limit in its validation gets the most sudemi allows.
            $max = $format === null ? $option['maxLength'] : strlen($format['note']);

            return [
                'key' => (string) $option['product_option_id'],
                // A date or time says how to write it: "Delivery date (YYYY-MM-DD)".
                'label' => mb_substr($option['name'], 0, 100 - strlen($note), 'UTF-8') . $note,
                'required' => (bool) $option['required'],
                'type' => $format === null ? 'text' : $option['type'],
                'maxLength' => $max === null ? self::MAX_TEXT : min($max, self::MAX_TEXT),
                'price' => $price,
            ];
        }, array_slice($texts, 0, self::MAX_TEXTS));
    }

    /**
     * A choice option's values as sudemi choices: at most MAX_CHOICES, each label once (sudemi
     * tells the buyer's pick by its label too), a value without a name named by its id.
     */
    private static function choiceList(array $values)
    {
        $out = [];
        $seen = [];
        foreach ($values as $value) {
            $label = self::label($value['name']);
            $label = $label !== '' ? $label : '#' . $value['product_option_value_id'];
            if (isset($seen[mb_strtolower($label, 'UTF-8')]) || count($out) >= self::MAX_CHOICES) {
                continue;
            }
            $seen[mb_strtolower($label, 'UTF-8')] = true;
            $out[] = ['key' => (string) $value['product_option_value_id'], 'label' => $label, 'price' => max(0.0, $value['price'])];
        }

        return $out;
    }

    /** A label as sudemi takes it: at most 100 characters, without the angle brackets it refuses. */
    private static function label($name)
    {
        return mb_substr(trim(str_replace(['<', '>'], '', (string) $name)), 0, 100, 'UTF-8');
    }

    /**
     * The order_option rows for a line's personalisation ([{key, label, value}] from sudemi): one
     * per text option of the product that was filled in, cut to its maxLength. Null when a
     * required one is missing (the shop could not make the item).
     */
    public static function personalRows(array $plan, $given)
    {
        $byKey = [];
        foreach (is_array($given) ? $given : [] as $entry) {
            if (is_array($entry) && isset($entry['key'])) {
                $byKey[(string) $entry['key']] = $entry;
            }
        }
        $rows = [];
        foreach ($plan['texts'] as $option) {
            $entry = isset($byKey[(string) $option['product_option_id']]) ? $byKey[(string) $option['product_option_id']] : null;
            $picked = in_array($option['type'], self::CHOICE_TYPES, true)
                ? self::chosenRows($option, $entry)
                : self::typedRow($option, $entry);
            if ($picked === []) {
                if ($option['required']) {
                    return null;
                }
                continue;
            }
            $rows = array_merge($rows, $picked);
        }

        return $rows;
    }

    /** The row for a typed option ([] when not given or not valid). */
    private static function typedRow(array $option, $entry)
    {
        $value = is_array($entry) && isset($entry['value']) && is_scalar($entry['value']) ? trim((string) $entry['value']) : '';
        if ($value !== '' && isset(self::FORMATS[$option['type']])) {
            $value = self::formatted($value, $option['type']);
        }
        if ($value === '') {
            return [];
        }
        if ($option['maxLength'] !== null && mb_strlen($value, 'UTF-8') > $option['maxLength']) {
            $value = mb_substr($value, 0, $option['maxLength'], 'UTF-8');
        }

        return [[
            'product_option_id' => $option['product_option_id'],
            'product_option_value_id' => 0,
            'name' => $option['name'],
            'value' => $value,
            'type' => $option['type'],
            'subtract' => false,
            'quantity' => 0,
        ]];
    }

    /**
     * The rows for the values chosen of a choice option (choice_keys, else choice_key; a radio or
     * select takes one): [] when none of them is a value of the option.
     */
    private static function chosenRows(array $option, $entry)
    {
        $keys = [];
        if (is_array($entry) && isset($entry['choice_keys'])) {
            $keys = is_array($entry['choice_keys']) ? $entry['choice_keys'] : explode(',', (string) $entry['choice_keys']);
        } elseif (is_array($entry) && isset($entry['choice_key'])) {
            $keys = [$entry['choice_key']];
        }
        $keys = array_values(array_unique(array_map(function ($key) {
            return trim((string) $key);
        }, $keys)));
        $rows = [];
        foreach ($option['values'] as $value) {
            if (in_array((string) $value['product_option_value_id'], $keys, true)) {
                $rows[] = [
                    'product_option_id' => $option['product_option_id'],
                    'product_option_value_id' => $value['product_option_value_id'],
                    'name' => $option['name'],
                    'value' => $value['name'],
                    'type' => $option['type'],
                    'subtract' => $value['subtract'],
                    'quantity' => $value['quantity'],
                ];
            }
        }
        if ($option['type'] !== 'checkbox' && count($rows) > 1) {
            $rows = array_slice($rows, 0, 1);
        }

        return $rows;
    }

    /** Whether the product's listings carry options (else it is one plain listing, as before 1.4.3). */
    public static function hasOptions(array $plan)
    {
        return !empty($plan['choices']) || !empty($plan['fixed']);
    }

    /** How many combinations the choices make (1 without choices). */
    public static function count(array $plan)
    {
        $n = 1;
        foreach ($plan['choices'] as $option) {
            $n *= count($option['values']);
        }

        return $n;
    }

    /**
     * The combinations, the first $max of them, in the storefront's order (the first option
     * changes slowest). Each is a list of ['option' => option, 'value' => value], one per choice.
     */
    public static function combinations(array $plan, $max = self::MAX_COMBINATIONS)
    {
        $choices = $plan['choices'];
        if (empty($choices)) {
            return [[]];
        }
        $out = [];
        $at = array_fill(0, count($choices), 0);
        while (count($out) < $max) {
            $combo = [];
            foreach ($choices as $i => $option) {
                $combo[] = ['option' => $option, 'value' => $option['values'][$at[$i]]];
            }
            $out[] = $combo;
            // Next: the last option moves fastest.
            for ($i = count($choices) - 1; $i >= 0; --$i) {
                if (++$at[$i] < count($choices[$i]['values'])) {
                    break;
                }
                $at[$i] = 0;
            }
            if ($i < 0) {
                break;
            }
        }

        return $out;
    }

    /** The listing's external id: the product id, then the chosen value ids ("12:51-60"). */
    public static function externalId($productId, array $combo)
    {
        if (empty($combo)) {
            return (string) (int) $productId;
        }

        return (int) $productId . ':' . implode('-', array_map(function ($pick) {
            return (int) $pick['value']['product_option_value_id'];
        }, $combo));
    }

    /**
     * An external id taken apart: ['product_id' => 12, 'values' => [51, 60]], or null when it is
     * not one this extension makes.
     */
    public static function parse($externalId)
    {
        if (!preg_match('/^([1-9]\d{0,9})(?::([1-9]\d{0,9}(?:-[1-9]\d{0,9}){0,19}))?$/', (string) $externalId, $m)) {
            return null;
        }

        return [
            'product_id' => (int) $m[1],
            'values' => isset($m[2]) && $m[2] !== '' ? array_map('intval', explode('-', $m[2])) : [],
        ];
    }

    /**
     * The combination these value ids choose: one value of every choice, nothing else. Null when
     * they do not (a value removed since, an option added, an id from elsewhere).
     */
    public static function selection(array $plan, array $valueIds)
    {
        $valueIds = array_map('intval', $valueIds);
        if (count($valueIds) !== count($plan['choices']) || count(array_unique($valueIds)) !== count($valueIds)) {
            return null;
        }
        $combo = [];
        foreach ($plan['choices'] as $option) {
            $found = null;
            foreach ($option['values'] as $value) {
                if (in_array($value['product_option_value_id'], $valueIds, true)) {
                    $found = $value;
                    break;
                }
            }
            if ($found === null) {
                return null;
            }
            $combo[] = ['option' => $option, 'value' => $found];
        }

        return $combo;
    }

    /** Everything a listing carries: the variant's fixed values, then the combination. */
    public static function picks(array $plan, array $combo)
    {
        $picks = [];
        foreach ($plan['fixed'] as $option) {
            foreach ($option['values'] as $value) {
                $picks[] = ['option' => $option, 'value' => $value];
            }
        }

        return array_merge($picks, $combo);
    }

    /**
     * What the picks add: 'price' and 'weight' (signed sums, as the cart adds them) and
     * 'quantity', the lowest stock of a value that subtracts stock (null when none does).
     */
    public static function adjustments(array $picks)
    {
        $out = ['price' => 0.0, 'weight' => 0.0, 'quantity' => null];
        foreach ($picks as $pick) {
            $out['price'] += $pick['value']['price'];
            $out['weight'] += $pick['value']['weight'];
            if ($pick['value']['subtract']) {
                $out['quantity'] = $out['quantity'] === null ? $pick['value']['quantity'] : min($out['quantity'], $pick['value']['quantity']);
            }
        }

        return $out;
    }

    /**
     * The photos of a listing: the pictures of its picked values (a colour's photo) first, as
     * <storeBase>/image/<path>, then the product's own photos; each once.
     */
    public static function images(array $picks, array $productImages, $base)
    {
        $images = [];
        foreach ($picks as $pick) {
            if (isset($pick['value']['image']) && $pick['value']['image'] !== '') {
                $images[] = $base . '/image/' . $pick['value']['image'];
            }
        }

        return array_values(array_unique(array_merge($images, $productImages)));
    }

    /** The picks per option name: [['name' => 'Size', 'values' => ['M']], ...], in order. */
    public static function named(array $picks)
    {
        $named = [];
        foreach ($picks as $pick) {
            $key = $pick['option']['product_option_id'];
            if (!isset($named[$key])) {
                $named[$key] = ['name' => $pick['option']['name'], 'values' => []];
            }
            $named[$key]['values'][] = $pick['value']['name'];
        }

        return array_values($named);
    }

    /** sudemi's variantAttributes: [{name, value}], one per option. */
    public static function attributes(array $picks)
    {
        return array_map(function ($option) {
            return ['name' => $option['name'], 'value' => implode(', ', $option['values'])];
        }, self::named($picks));
    }

    /** The listing title: "T-shirt (Size: M, Colour: Red)". */
    public static function title($name, array $picks)
    {
        $parts = array_map(function ($option) {
            return $option['name'] . ': ' . implode(', ', $option['values']);
        }, self::named($picks));

        return empty($parts) ? $name : $name . ' (' . implode(', ', $parts) . ')';
    }

    /** OpenCart's order_option rows for the picks, as the storefront's checkout writes them. */
    public static function orderRows(array $picks)
    {
        return array_map(function ($pick) {
            return [
                'product_option_id' => $pick['option']['product_option_id'],
                'product_option_value_id' => $pick['value']['product_option_value_id'],
                'name' => $pick['option']['name'],
                'value' => $pick['value']['name'],
                'type' => $pick['option']['type'],
            ];
        }, $picks);
    }

    /** A date or time answer in OpenCart's format ('' when it is not a real date or time). */
    public static function formatted($value, $type)
    {
        $format = self::FORMATS[$type];
        $value = str_replace('T', ' ', trim((string) $value));
        if (!preg_match($format['pattern'], $value)) {
            return '';
        }
        $parsed = \DateTime::createFromFormat('!' . $format['php'], $value);

        return $parsed !== false && $parsed->format($format['php']) === $value ? $value : '';
    }

    /** A JSON column as an array ('' and anything not an object: empty). */
    public static function json($raw)
    {
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
