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
                . ($this->hasColumn('option', 'validation') ? ', o.validation' : '') . '
               FROM ' . $this->prefix . 'product_option po
               LEFT JOIN `' . $this->prefix . 'option` o ON (o.option_id = po.option_id)
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
                    'values' => [],
                ],
            ];
        }
        $values = $this->db->query(
            'SELECT pov.product_option_value_id, pov.product_option_id, pov.option_value_id, pov.quantity,
                    pov.subtract, pov.price, pov.price_prefix, pov.weight, pov.weight_prefix, ovd.name
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
        ];
    }

    /**
     * What a product's options mean for its listings.
     *
     * 'choices': the required choice options the buyer picks one value of (one listing per
     * combination). 'fixed': the options an OpenCart 4 variant sets to its own values (every
     * listing of the variant carries them). 'texts': the text and textarea options (required or
     * not; dates and times too), which the buyer fills in through sudemi's personalisation.
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
     * the product_option_id. price is the signed add-on before tax (Catalogue turns it into
     * priceMinor): OpenCart itself puts no price on a typed option, so it is the price of a value
     * row the shop keeps on that option, 0 without one.
     */
    public static function personalisation(array $plan)
    {
        // At most MAX_TEXTS (sudemi's limit), the required ones first.
        $texts = $plan['texts'];
        usort($texts, function ($a, $b) {
            return (int) $b['required'] - (int) $a['required'];
        });

        return array_map(function ($option) {
            $price = 0.0;
            foreach ($option['values'] as $value) {
                $price += $value['price'];
            }

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
                'maxLength' => $max === null ? self::MAX_TEXT : min($max, self::MAX_TEXT),
                'price' => $price,
            ];
        }, array_slice($texts, 0, self::MAX_TEXTS));
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
            if (is_array($entry) && isset($entry['key'], $entry['value']) && is_scalar($entry['value'])) {
                $byKey[(string) $entry['key']] = trim((string) $entry['value']);
            }
        }
        $rows = [];
        foreach ($plan['texts'] as $option) {
            $value = isset($byKey[(string) $option['product_option_id']]) ? $byKey[(string) $option['product_option_id']] : '';
            if ($value !== '' && isset(self::FORMATS[$option['type']])) {
                $value = self::formatted($value, $option['type']);
            }
            if ($value === '') {
                if ($option['required']) {
                    return null;
                }
                continue;
            }
            if ($option['maxLength'] !== null && mb_strlen($value, 'UTF-8') > $option['maxLength']) {
                $value = mb_substr($value, 0, $option['maxLength'], 'UTF-8');
            }
            $rows[] = [
                'product_option_id' => $option['product_option_id'],
                'product_option_value_id' => 0,
                'name' => $option['name'],
                'value' => $value,
                'type' => $option['type'],
            ];
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
