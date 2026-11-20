<?php
/**
 * Product options as variants (1.4.3).
 *
 * 1. Options: plans, combinations (in the storefront's order, capped), external ids taken apart
 *    again, a selection from an order line, price / weight / stock of the chosen values, titles,
 *    variantAttributes, order_option rows; an OpenCart 4 variant's fixed options; typed options as
 *    personalisation (maxLength from the option's validation pattern), dates and times with their
 *    format; a required file option is not offered.
 * 2. Catalogue::products(): a T-shirt with Size x Colour x Material is one listing per combination,
 *    each with its own price (option prices with tax, a percentage special on top), stock (the
 *    lowest of the values that keep stock), weight, specs and one group; the barcode is left out.
 *    A product with a required file option is not sent; the cap is told.
 * 3. Sync::pushFamily: a combination that went (an option value removed) counts as a removal, so
 *    no push carries the revision until a full sync took it off sudemi; one added does not. A full
 *    sync left by an earlier connection is started again.
 * 4. Orders: an order line for a combination is written with its options (order_option rows) and
 *    the typed text, and stock comes off the product and each value that keeps stock; a line whose
 *    combination is gone, or without a required text, is refused.
 *
 * Runs without OpenCart: the database, the config and sudemi are faked here.
 */

declare(strict_types=1);

define( 'DB_PREFIX', 'oc_' );
foreach ( array( 'store', 'logger', 'keys', 'client', 'queue', 'currency', 'shipping', 'withdrawal', 'options', 'catalogue', 'heartbeat', 'sync', 'orders' ) as $file ) {
	require_once __DIR__ . '/../system/library/sudemi/' . $file . '.php';
}

use Sudemi\Options;

$failures = 0;

function check( string $what, bool $passed, $got = null ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, $passed ? '' : ' got ' . var_export( $got, true ), "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

/** A product_option_value row as OpenCart stores it. */
function pov( $id, $name, $price = '0', $prefix = '+', $qty = 10, $subtract = 1, $weight = '0', $wprefix = '+' ) {
	return array( 'product_option_value_id' => $id, 'option_value_id' => $id + 1000, 'name' => $name, 'quantity' => $qty, 'subtract' => $subtract,
		'price' => $price, 'price_prefix' => $prefix, 'weight' => $weight, 'weight_prefix' => $wprefix );
}
function opt( $id, $name, $type, array $values, $required = true, $validation = '' ) {
	return array( 'product_option_id' => $id, 'option_id' => $id + 100, 'name' => $name, 'type' => $type, 'required' => $required,
		'maxLength' => Options::maxLength( $validation ), 'values' => array_map( array( Options::class, 'value' ), $values ) );
}

// ---- 1. Options -----------------------------------------------------------------------------------
$size   = opt( 1, 'Size', 'select', array( pov( 11, 'S' ), pov( 12, 'M' ), pov( 13, 'L', '1.50', '+', 2 ) ) );
$colour = opt( 2, 'Colour', 'radio', array( pov( 21, 'White' ), pov( 22, 'Black', '2', '+' ), pov( 23, 'Red', '3', '+', 0 ), pov( 24, 'Sale', '1', '-', 5, 0, '0.1', '-' ) ) );
$name   = opt( 3, 'Custom name', 'text', array(), false, '/^.{1,20}$/' );
$note   = opt( 4, 'Gift note', 'select', array( pov( 41, 'Yes' ) ), false );
$plan   = Options::plan( array( $size, $colour, $name, $note ) );
check( 'plan: the required choices make combinations; the text and the optional choice are personalisation',
	2 === count( $plan['choices'] ) && 2 === count( $plan['texts'] ) && null === $plan['problem'] && array() === $plan['fixed'] );
check( 'count: 3 sizes x 4 colours = 12', 12 === Options::count( $plan ) );
$combos = Options::combinations( $plan );
check( '12 combinations, the first option changes slowest', 12 === count( $combos )
	&& '7:11-21' === Options::externalId( 7, $combos[0] ) && '7:11-22' === Options::externalId( 7, $combos[1] ) && '7:13-24' === Options::externalId( 7, $combos[11] ),
	array_map( function ( $c ) { return Options::externalId( 7, $c ); }, $combos ) );
check( 'all twelve external ids differ', 12 === count( array_unique( array_map( function ( $c ) { return Options::externalId( 7, $c ); }, $combos ) ) ) );
check( 'capped at 100: 5 x 5 x 5 = 125 makes 100', 100 === count( Options::combinations( Options::plan( array(
	opt( 1, 'A', 'select', array( pov( 1, 'a' ), pov( 2, 'b' ), pov( 3, 'c' ), pov( 4, 'd' ), pov( 5, 'e' ) ) ),
	opt( 2, 'B', 'select', array( pov( 6, 'a' ), pov( 7, 'b' ), pov( 8, 'c' ), pov( 9, 'd' ), pov( 10, 'e' ) ) ),
	opt( 3, 'C', 'select', array( pov( 16, 'a' ), pov( 17, 'b' ), pov( 18, 'c' ), pov( 19, 'd' ), pov( 20, 'e' ) ) ),
) ) ) ) );
check( 'no choices: one combination, the plain product id', array( array() ) === Options::combinations( Options::plan( array() ) ) && '7' === Options::externalId( 7, array() ) );

check( 'parse: a plain id', array( 'product_id' => 53, 'values' => array() ) === Options::parse( '53' ) );
check( 'parse: a combination', array( 'product_id' => 53, 'values' => array( 12, 14 ) ) === Options::parse( '53:12-14' ) );
check( 'parse: anything else is null', null === Options::parse( '53-2' ) && null === Options::parse( '0' ) && null === Options::parse( '53:' )
	&& null === Options::parse( '53:1-0' ) && null === Options::parse( ' 53' ) && null === Options::parse( '' ) && null === Options::parse( '53:1;2' ) );

$sel = Options::selection( $plan, array( 23, 13 ) );
check( 'selection: one value per choice, in any order', null !== $sel && 'L' === $sel[0]['value']['name'] && 'Red' === $sel[1]['value']['name'] );
check( 'selection: a value missing, extra, repeated or unknown is null',
	null === Options::selection( $plan, array( 13 ) ) && null === Options::selection( $plan, array( 13, 23, 41 ) )
	&& null === Options::selection( $plan, array( 13, 13 ) ) && null === Options::selection( $plan, array( 13, 99 ) )
	&& null === Options::selection( $plan, array( 11, 12 ) ) );

$picks = Options::picks( $plan, $sel );
$add   = Options::adjustments( $picks );
check( 'L (+1.50, 2 in stock) / Red (+3, none in stock): price +4.50, quantity 0', abs( $add['price'] - 4.5 ) < 1e-9 && 0 === $add['quantity'], $add );
$sale = Options::adjustments( Options::picks( $plan, Options::selection( $plan, array( 11, 24 ) ) ) );
check( 'a "-" prefix takes off price and weight; a value without stock keeping does not limit the quantity',
	abs( $sale['price'] + 1.0 ) < 1e-9 && abs( $sale['weight'] + 0.1 ) < 1e-9 && 10 === $sale['quantity'], $sale );
check( 'no stock-keeping value at all: no quantity limit', null === Options::adjustments( array() )['quantity'] );
check( 'title: the product name with the chosen values', 'Tee (Size: L, Colour: Red)' === Options::title( 'Tee', $picks ), Options::title( 'Tee', $picks ) );
check( 'variantAttributes: name and value per option', array( array( 'name' => 'Size', 'value' => 'L' ), array( 'name' => 'Colour', 'value' => 'Red' ) ) === Options::attributes( $picks ) );
$rows = Options::orderRows( $picks );
check( 'order_option rows as the storefront writes them', 2 === count( $rows ) && 1 === $rows[0]['product_option_id'] && 13 === $rows[0]['product_option_value_id']
	&& 'Size' === $rows[0]['name'] && 'L' === $rows[0]['value'] && 'select' === $rows[0]['type'] && 'radio' === $rows[1]['type'] );
check( 'an option value name is text, not OpenCart\'s escaped storage', 'Black & Blue' === Options::value( pov( 1, 'Black &amp; Blue' ) )['name'] );

// Typed options: personalisation.
check( 'maxLength from the validation pattern', 20 === Options::maxLength( '/^.{1,20}$/' ) && 12 === Options::maxLength( '/^[A-Z ]{12}$/' )
	&& null === Options::maxLength( '' ) && null === Options::maxLength( '/^.+$/' ) && null === Options::maxLength( '/^.{1,}$/' ) );
$priced = opt( 3, 'Custom name', 'text', array( pov( 31, '', '20', '+' ) ), false, '/^.{1,20}$/' );
$tee    = Options::plan( array( $size, $priced ) );
check( 'personalisation: key = product_option_id, label, optional, maxLength, the add-on price',
	array( array( 'key' => '3', 'label' => 'Custom name', 'required' => false, 'type' => 'text', 'maxLength' => 20, 'price' => 20.0 ) ) === Options::personalisation( $tee ),
	Options::personalisation( $tee ) );
$texts = Options::personalRows( $tee, array( array( 'key' => '3', 'label' => 'Custom name', 'value' => ' AJAX AMSTERDAM ' ) ) );
check( 'the typed text becomes an order_option row (type text)', array( array( 'product_option_id' => 3, 'product_option_value_id' => 0,
	'name' => 'Custom name', 'value' => 'AJAX AMSTERDAM', 'type' => 'text', 'subtract' => false, 'quantity' => 0 ) ) === $texts, $texts );
check( 'too long: cut to maxLength', 20 === mb_strlen( Options::personalRows( $tee, array( array( 'key' => '3', 'value' => str_repeat( 'É', 30 ) ) ) )[0]['value'] ) );
check( 'optional and not given: no row', array() === Options::personalRows( $tee, array() ) && array() === Options::personalRows( $tee, 'nonsense' ) );
$must = Options::plan( array( opt( 5, 'Engraving', 'textarea', array(), true ) ) );
check( 'a required text not given: null (the shop cannot make it); given: a row', null === Options::personalRows( $must, array() )
	&& null === Options::personalRows( $must, array( array( 'key' => '5', 'value' => '  ' ) ) )
	&& 1 === count( Options::personalRows( $must, array( array( 'key' => '5', 'value' => 'Love' ) ) ) ) );
$six = Options::plan( array_merge( array_map( function ( $i ) { return opt( 60 + $i, 'Line ' . $i, 'text', array(), false ); }, range( 1, 5 ) ),
	array( opt( 70, 'Must', 'textarea', array(), true ) ) ) );
$sent = Options::personalisation( $six );
check( 'at most 5 texts (sudemi\'s limit), the required one first; no limit in the validation: 200', 5 === count( $sent ) && '70' === $sent[0]['key'] && 200 === $sent[1]['maxLength'] );
check( 'a label is at most 100 characters', 100 === mb_strlen( Options::personalisation( Options::plan( array( opt( 9, str_repeat( 'é', 120 ), 'date', array(), false ) ) ) )[0]['label'] ) );
check( 'a required text is not a problem: it is personalisation', null === $must['problem'] && ! Options::hasOptions( $must ) );

// Choices the buyer picks (owner 2026-11-19, capture everything): an optional priced select, a checkbox.
$wrap  = opt( 80, 'Gift wrap', 'radio', array( pov( 81, 'Paper', '3', '+' ), pov( 82, 'Box', '5', '+', 0 ), pov( 83, 'Discount', '1', '-' ) ), false );
$extra = opt( 90, 'Extras', 'checkbox', array( pov( 91, 'Card', '1.5', '+' ), pov( 92, 'Ribbon', '2', '+', 0, 0 ) ), true );
$gift  = Options::plan( array( $size, $wrap, $extra ) );
$given = Options::personalisation( $gift );
check( 'an optional radio is a choice, a required checkbox a multi_choice; no combinations for them', 3 === Options::count( $gift ) && 2 === count( $given )
	&& 'multi_choice' === $given[0]['type'] && true === $given[0]['required'] && 'choice' === $given[1]['type'] && false === $given[1]['required'], $given );
check( 'choices: key = product_option_value_id, label, price; a value out of stock is left out; a "-" price is no add-on',
	array( array( 'key' => '81', 'label' => 'Paper', 'price' => 3.0 ), array( 'key' => '83', 'label' => 'Discount', 'price' => 0.0 ) ) === $given[1]['choices']
	&& array( '91', '92' ) === array_column( $given[0]['choices'], 'key' ), $given );
$rows = Options::personalRows( $gift, array( array( 'key' => '90', 'type' => 'multi_choice', 'choice_keys' => array( '91', '92' ), 'value' => 'Card, Ribbon' ),
	array( 'key' => '80', 'type' => 'choice', 'choice_key' => '81', 'value' => 'Paper' ) ) );
check( 'the chosen choices become order_option rows with their values (two ticks, one radio)', 3 === count( $rows )
	&& array( 81, 91, 92 ) === array_column( $rows, 'product_option_value_id' )
	&& 'Ribbon' === $rows[2]['value'] && 'checkbox' === $rows[1]['type'] && 'radio' === $rows[0]['type'], $rows );
check( 'choice_keys as a comma list too; the required checkbox missing or unknown: null; a radio takes one',
	2 === count( Options::personalRows( $gift, array( array( 'key' => '90', 'choice_keys' => '91,92' ) ) ) )
	&& null === Options::personalRows( $gift, array() ) && null === Options::personalRows( $gift, array( array( 'key' => '90', 'choice_keys' => array( '99' ) ) ) )
	&& 2 === count( Options::personalRows( $gift, array( array( 'key' => '90', 'choice_key' => '91' ), array( 'key' => '80', 'choice_keys' => array( '81', '83' ) ) ) ) ) );
check( 'a required checkbox with every value out of stock: not offered', 'empty' === Options::plan( array(
	opt( 95, 'Extras', 'checkbox', array( pov( 96, 'Card', '1', '+', 0 ) ), true ) ) )['problem']['reason'] );

// Not offered.
$file = Options::plan( array( $size, opt( 6, 'Logo', 'file', array() ) ) );
check( 'a required file option: a problem naming it', array( 'option' => 'Logo', 'type' => 'file', 'reason' => 'type' ) === $file['problem'] );
check( 'an optional date option: no problem', null === Options::plan( array( $size, opt( 6, 'When', 'date', array(), false ) ) )['problem'] );
$dated = Options::plan( array( opt( 8, 'Delivery date', 'date', array(), true ), opt( 9, 'Pick-up', 'datetime', array(), false ) ) );
check( 'dates and times are personalisation with their format in the label', null === $dated['problem']
	&& array( 'key' => '8', 'label' => 'Delivery date (YYYY-MM-DD)', 'required' => true, 'type' => 'date', 'maxLength' => 10, 'price' => 0.0 ) === Options::personalisation( $dated )[0]
	&& 'Pick-up (YYYY-MM-DD HH:MM)' === Options::personalisation( $dated )[1]['label'], Options::personalisation( $dated ) );
check( 'a date is kept as OpenCart stores it; a datetime with T too; a wrong one is not given',
	'2026-12-24' === Options::personalRows( $dated, array( array( 'key' => '8', 'value' => '2026-12-24' ) ) )[0]['value']
	&& '2026-12-24 10:30' === Options::personalRows( $dated, array( array( 'key' => '8', 'value' => '2026-12-24' ), array( 'key' => '9', 'value' => '2026-12-24T10:30' ) ) )[1]['value']
	&& null === Options::personalRows( $dated, array( array( 'key' => '8', 'value' => '24-12-2026' ) ) )
	&& null === Options::personalRows( $dated, array( array( 'key' => '8', 'value' => '2026-02-30' ) ) )
	&& '' === Options::formatted( '25:00', 'time' ) && '09:15' === Options::formatted( '09:15', 'time' ) );
check( 'a required choice without values: a problem', 'empty' === Options::plan( array( opt( 7, 'Size', 'select', array() ) ) )['problem']['reason'] );

// An OpenCart 4 variant fixing Colour to Black: its listings are the sizes; Black's price applies to each.
$variant = Options::plan( array( $size, $colour ), array( '2' => '22' ), array( 'variant' => array( '2' => '1' ) ) );
check( 'variant: the overridden option is fixed to its value, the rest are choices', 1 === count( $variant['fixed'] ) && 1 === count( $variant['choices'] )
	&& 3 === Options::count( $variant ) && 'Black' === $variant['fixed'][0]['values'][0]['name'] );
$vc = Options::combinations( $variant );
check( 'variant: the external id names only the free choice; the picks carry the fixed value first',
	'9:12' === Options::externalId( 9, $vc[1] ) && 'Tee (Colour: Black, Size: M)' === Options::title( 'Tee', Options::picks( $variant, $vc[1] ) ) );
check( 'variant: a value in variant but not overridden is a free choice', 12 === Options::count( Options::plan( array( $size, $colour ), array( '2' => '22' ), array() ) ) );
check( 'variant with every option fixed: the plain id', '9' === Options::externalId( 9, Options::combinations( Options::plan( array( $colour ), array( 2 => 22 ), array( 'variant' => array( 2 => 1 ) ) ) )[0] ) );

// ---- 2. Catalogue::products() ---------------------------------------------------------------------
/** Answers the queries Catalogue, Options and Shipping make; anything else has no rows. */
class CatalogueDb {
	public $products = array();
	public $options  = array(); // product_id => [option rows]
	public $values   = array(); // product_option_id => [value rows]
	public $discount = null;
	public $specials = array();
	public $tiers    = array();
	public $listed   = array();
	public $writes   = array();
	public function escape( $v ) {
		return addslashes( (string) $v );
	}
	public function getLastId() {
		return 0;
	}
	public function query( $sql ) {
		$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );
		if ( preg_match( "/^SHOW COLUMNS FROM `oc_product` LIKE '(master_id|variant|override)'$/", $sql ) ) {
			return $this->rows( array( array( 'Field' => 'x' ) ) );
		}
		if ( "SHOW COLUMNS FROM `oc_option_value` LIKE 'image'" === $sql ) {
			return $this->rows( array( array( 'Field' => 'image' ) ) );
		}
		if ( preg_match( "/^SHOW TABLES LIKE 'oc_(product_discount|sudemi_listing|sudemi_option_price)'$/", $sql ) ) {
			return $this->rows( array( array( 'x' => 1 ) ) );
		}
		if ( 0 === strpos( $sql, 'SHOW ' ) ) {
			return $this->rows( array() );
		}
		if ( preg_match( '/^SELECT p.product_id, p.model, .* WHERE pd.language_id = 1 AND p.status = 1 AND p.product_id IN \(([\d,]+)\)/', $sql, $m ) ) {
			$ids = array_map( 'intval', explode( ',', $m[1] ) );
			return $this->rows( array_values( array_filter( $this->products, function ( $p ) use ( $ids ) {
				return in_array( $p['product_id'], $ids, true );
			} ) ) );
		}
		if ( preg_match( '/^SELECT po.product_option_id, .* WHERE po.product_id IN \(([\d,]+)\)/', $sql, $m ) ) {
			$rows = array();
			foreach ( explode( ',', $m[1] ) as $id ) {
				$rows = array_merge( $rows, isset( $this->options[ (int) $id ] ) ? $this->options[ (int) $id ] : array() );
			}
			return $this->rows( $rows );
		}
		if ( preg_match( '/^SELECT pov.product_option_value_id, .* WHERE pov.product_option_id IN \(([\d,]+)\)/', $sql, $m ) ) {
			$rows = array();
			foreach ( explode( ',', $m[1] ) as $id ) {
				foreach ( isset( $this->values[ (int) $id ] ) ? $this->values[ (int) $id ] : array() as $v ) {
					$rows[] = $v + array( 'product_option_id' => (int) $id );
				}
			}
			return $this->rows( $rows );
		}
		if ( preg_match( '/^SELECT price, type, quantity, date_start, date_end FROM oc_product_discount .* special = 1 /', $sql ) ) {
			return $this->rows( $this->specials );
		}
		if ( preg_match( '/^SELECT price, type, quantity FROM oc_product_discount .* special = 0 /', $sql ) ) {
			return $this->rows( $this->tiers );
		}
		if ( preg_match( '/^SELECT lc.length_class_id/', $sql ) ) {
			return $this->rows( array( array( 'length_class_id' => 1, 'value' => 1, 'unit' => 'cm' ), array( 'length_class_id' => 3, 'value' => 0.3937, 'unit' => 'in' ) ) );
		}
		if ( preg_match( '/^SELECT stock_status_id, name FROM oc_stock_status/', $sql ) ) {
			return $this->rows( array( array( 'stock_status_id' => 6, 'name' => '2-3 Days' ), array( 'stock_status_id' => 8, 'name' => 'Pre-Order' ) ) );
		}
		if ( preg_match( '/^SELECT price, type FROM oc_product_discount .* AND special = 1 /', $sql ) ) {
			return $this->rows( $this->discount ? array( $this->discount ) : array() );
		}
		if ( preg_match( '/^SELECT wc.weight_class_id/', $sql ) ) {
			return $this->rows( array( array( 'weight_class_id' => 1, 'value' => 1, 'unit' => 'kg' ) ) );
		}
		if ( preg_match( '/^SELECT tr2.rate, tr2.type FROM/', $sql ) ) {
			return $this->rows( array( array( 'rate' => 21, 'type' => 'P' ) ) ); // 21% VAT
		}
		if ( preg_match( '/^SELECT product_id, external_ids FROM oc_sudemi_listing WHERE product_id IN \(([\d,]+)\)$/', $sql, $m ) ) {
			$rows = array();
			foreach ( explode( ',', $m[1] ) as $id ) {
				if ( isset( $this->listed[ (int) $id ] ) ) {
					$rows[] = array( 'product_id' => (int) $id, 'external_ids' => $this->listed[ (int) $id ] );
				}
			}
			return $this->rows( $rows );
		}
		if ( preg_match( "/^REPLACE INTO oc_sudemi_listing \(product_id, external_ids, date_modified\) VALUES \((\d+), '([^']*)', NOW\(\)\)$/", $sql, $m ) ) {
			$this->listed[ (int) $m[1] ] = $m[2];
			$this->writes[]              = $sql;
			return true;
		}
		return $this->rows( array() );
	}
	private function rows( array $rows ) {
		return (object) array( 'num_rows' => count( $rows ), 'row' => $rows ? $rows[0] : array(), 'rows' => $rows );
	}
}
class FakeConfig {
	public $values = array( 'config_language_id' => 1, 'config_currency' => 'EUR', 'config_customer_group_id' => 1, 'config_country_id' => 150,
		'config_url' => 'https://shop.example' );
	public function get( $key ) {
		return isset( $this->values[ $key ] ) ? $this->values[ $key ] : null;
	}
}
function product( $id, $name, $price, $qty, array $extra = array() ) {
	return $extra + array( 'product_id' => $id, 'model' => 'M' . $id, 'sku' => 'SKU' . $id, 'ean' => '8710000000000', 'upc' => '', 'mpn' => 'MPN' . $id,
		'price' => $price, 'quantity' => $qty, 'weight' => '0.2', 'weight_class_id' => 1, 'tax_class_id' => 9, 'shipping' => 0, 'image' => '',
		'name' => $name, 'description' => '', 'manufacturer' => '', 'master_id' => 0, 'variant' => '', 'override' => '',
		'subtract' => 1, 'minimum' => 1, 'stock_status_id' => 6, 'date_available' => '2020-01-01', 'length' => '30', 'width' => '20', 'height' => '0.5',
		'length_class_id' => 1 );
}
function option_row( $poid, $pid, $name, $type, $required = 1 ) {
	return array( 'product_option_id' => $poid, 'product_id' => $pid, 'option_id' => $poid + 100, 'required' => $required, 'type' => $type, 'name' => $name );
}

// The owner's T-shirt (2026-11-19): 5 sizes x 5 colours x 2 materials = 50, an optional custom name.
$db               = new CatalogueDb();
$db->products     = array( product( 20, 'T-shirt', '20.0000', 30 ), product( 21, 'Logo mug', '9.0000', 4 ) );
$db->options[20]  = array( option_row( 201, 20, 'Size', 'select' ), option_row( 202, 20, 'Colour', 'radio' ), option_row( 203, 20, 'Material', 'select' ),
	option_row( 204, 20, 'Custom name', 'text', 0 ) );
$db->options[21]  = array( option_row( 211, 21, 'Logo', 'file' ) );
$db->values[201]  = array( pov( 1, 'S' ), pov( 2, 'M' ), pov( 3, 'L' ), pov( 4, 'XL', '0', '+', 3 ), pov( 5, 'XXL' ) );
$db->values[202]  = array( pov( 6, 'White' ), pov( 7, 'Black', '2' ), pov( 8, 'Red', '3' ), pov( 9, 'Navy', '4' ), pov( 10, 'Green', '5', '+', 50, 0 ) );
$db->values[203]  = array( pov( 14, 'Lycra' ), pov( 15, 'Cotton', '10', '+', 10, 1, '0.05', '+' ) );
$db->values[204]  = array( pov( 16, '', '20', '+' ) );
// 1.4.6: a colour's own picture (option_value.image) and the T-shirt's photos.
$db->products[0]['image'] = 'catalog/tee.jpg';
$db->values[202][2]['image'] = 'catalog/colours/red.jpg';
$catalogue        = new \Sudemi\Catalogue( $db, new FakeConfig() );
$listings         = $catalogue->productsByIds( array( 20, 21 ) );
$byId             = array();
foreach ( $listings as $l ) {
	$byId[ $l['externalId'] ] = $l;
}
check( 'the T-shirt is 50 listings; the mug with a required file option none', 50 === count( $listings ) && ! isset( $byId['21'] ), count( $listings ) );
$xl = isset( $byId['20:4-8-15'] ) ? $byId['20:4-8-15'] : array();
check( 'XL / Red / Cotton: 20 + 3 + 10 = 33.00 + 21% VAT = 39.93', 3993 === ( $xl['priceMinor'] ?? null ), $xl['priceMinor'] ?? null );
check( 'S / White / Lycra: 20.00 + 21% = 24.20', 2420 === ( $byId['20:1-6-14']['priceMinor'] ?? null ) );
check( 'XXL / Green / Cotton: 35.00 + 21% = 42.35 (the dearest)', 4235 === ( $byId['20:5-10-15']['priceMinor'] ?? null ) );
$prices = array();
foreach ( $listings as $l ) {
	$prices[ $l['priceMinor'] ] = true;
}
check( 'ten different prices (5 colours x 2 materials), sizes the same', 10 === count( $prices ) );
check( 'stock: the lowest stock-keeping value (XL has 3)', 3 === $xl['quantity'] && true === $xl['inStock'] );
check( 'Green keeps no stock: the product and the other values decide', 10 === ( $byId['20:1-10-15']['quantity'] ?? null ) );
check( 'weight: 0.2 kg + Cotton 0.05 kg = 250 g', 250 === $xl['weightGrams'] );
check( 'title and group', 'T-shirt (Size: XL, Colour: Red, Material: Cotton)' === $xl['title'] && '20' === $xl['itemGroupId'] );
check( 'the product\'s barcode and part number are not sent with a combination', null === $xl['gtin'] && '' === $xl['mpn'] && 'SKU20' === $xl['sku'] );
check( 'specs start with the chosen values; variantAttributes the same', array( 'name' => 'Size', 'values' => array( 'XL' ) ) === $xl['specs'][0]
	&& array( 'name' => 'Material', 'value' => 'Cotton' ) === $xl['variantAttributes'][2] );
check( 'photos: the picked colour\'s own picture first, then the product\'s', array( 'https://shop.example/image/catalog/colours/red.jpg', 'https://shop.example/image/catalog/tee.jpg' ) === $xl['images'], $xl['images'] ?? null );
check( 'photos: a value without a picture keeps the product\'s', array( 'https://shop.example/image/catalog/tee.jpg' ) === ( $byId['20:1-6-14']['images'] ?? null ), $byId['20:1-6-14']['images'] ?? null );
$withImage = function ( $image ) {
	return array( 'option' => array( 'product_option_id' => 1 ), 'value' => array( 'image' => $image ) );
};
check( 'photos: pictures of every picked value in order, each once, none from an empty one',
	array( 'https://s.example/image/a.jpg', 'https://s.example/image/b.jpg', 'https://s.example/image/p.jpg' )
		=== Options::images( array( $withImage( 'a.jpg' ), $withImage( '' ), $withImage( 'b.jpg' ), $withImage( 'a.jpg' ) ), array( 'https://s.example/image/p.jpg', 'https://s.example/image/b.jpg' ), 'https://s.example' ) );
check( 'photos: an OpenCart without option_value.image (no image key) is the product\'s photos', array( 'https://s.example/image/p.jpg' )
	=== Options::images( array( array( 'option' => array(), 'value' => array() ) ), array( 'https://s.example/image/p.jpg' ), 'https://s.example' ) );
check( 'personalisation: the custom name, optional, its add-on with VAT (20 + 21% = 24.20)',
	array( array( 'key' => '204', 'label' => 'Custom name', 'required' => false, 'type' => 'text', 'maxLength' => 200, 'priceMinor' => 2420 ) ) === $xl['personalisation'], $xl['personalisation'] ?? null );
// The extension's own "Price for sudemi buyers" (product form) wins over a value row; never negative.
$db->options[20][3]['sudemi_price'] = '25.0000';
$own = ( new \Sudemi\Catalogue( $db, new FakeConfig() ) )->productsByIds( array( 20 ) );
check( 'the price set on the product form (25.00 + 21% = 30.25) wins over the value row', 3025 === $own[0]['personalisation'][0]['priceMinor'], $own[0]['personalisation'] );
$db->options[20][3]['sudemi_price'] = '-5.0000';
$negcat = new \Sudemi\Catalogue( $db, new FakeConfig() );
$neg    = $negcat->productsByIds( array( 20 ) );
$negiss = $negcat->takeIssues();
// Owner rule 2026-11-19: never sent as a free add-on (the buyer would pay too much). The optional
// custom name is not asked; the T-shirt stays listed; a warning issue says why.
check( 'a negative price on the optional custom name: not asked (never as free), the T-shirt still listed, a warning issue',
	! empty( $neg ) && ! isset( $neg[0]['personalisation'] ) && 'addon_negative_price' === $negiss[0]['code'] && 'warning' === $negiss[0]['severity'],
	array( isset( $neg[0]['personalisation'] ), $negiss ) );
unset( $db->options[20][3]['sudemi_price'] );
check( 'amounts typed in the form', '20.0000' === Options::parseAmount( '20' ) && '20.5000' === Options::parseAmount( ' 20,50 ' )
	&& '-1.0000' === Options::parseAmount( '-1' ) && '2.5000' === Options::parseAmount( '+2.5' ) && null === Options::parseAmount( '' )
	&& null === Options::parseAmount( 'twenty' ) && null === Options::parseAmount( '1e5' ) && '2.50' === Options::amount( '2.5000' ) );

/** Records what savePrices writes. */
class PriceDb {
	public $writes = array();
	public $prices = array(); // product_option_id => price
	public function escape( $v ) {
		return addslashes( (string) $v );
	}
	public function query( $sql ) {
		$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );
		$rows = array();
		if ( 0 === strpos( $sql, 'SHOW TABLES' ) ) {
			$rows = array( array( 'x' => 1 ) );
		} elseif ( 0 === strpos( $sql, 'SELECT product_option_id, option_id FROM oc_product_option WHERE product_id = 20' ) ) {
			$rows = array( array( 'product_option_id' => 204, 'option_id' => 304 ), array( 'product_option_id' => 201, 'option_id' => 301 ) );
		} elseif ( 0 === strpos( $sql, 'SELECT po.option_id, sop.price' ) ) {
			foreach ( $this->prices as $po => $price ) {
				$rows[] = array( 'option_id' => $po + 100, 'price' => $price );
			}
		} else {
			$this->writes[] = $sql;
			if ( preg_match( "/^REPLACE INTO `oc_sudemi_option_price` .* VALUES \((\d+), 20, '([^']*)', NOW\(\)\)$/", $sql, $m ) ) {
				$this->prices[ (int) $m[1] ] = $m[2];
			}
			return true;
		}
		return (object) array( 'num_rows' => count( $rows ), 'row' => $rows ? $rows[0] : array(), 'rows' => $rows );
	}
}
$pdb    = new PriceDb();
$prices = new Options( $pdb, new FakeConfig() );
check( 'the form\'s price is stored against the option OpenCart saved (product_option_id 204)', true === $prices->savePrices( 20, array( 304 => '20,00', 999 => '5' ) )
	&& array( 204 => '20.0000' ) === $pdb->prices && array( 304 => '20.00' ) === $prices->formPrices( 20 ) );
check( 'saved again unchanged: nothing changed; options the product no longer has are cleaned up', false === $prices->savePrices( 20, array( 304 => '20' ) )
	&& false !== strpos( end( $pdb->writes ), 'WHERE product_id = 20 AND product_option_id NOT IN (204,201)' ) );
$prices->savePrices( 20, array( 304 => '' ) );
check( 'emptied: removed', false !== strpos( implode( "\n", $pdb->writes ), 'DELETE FROM `oc_sudemi_option_price` WHERE product_option_id = 204' ) );

// A 10% special (OpenCart 4.1, type P) is taken off the price with the options, as the cart does.
$db->discount = array( 'price' => '10', 'type' => 'P' );
$sale         = ( new \Sudemi\Catalogue( $db, new FakeConfig() ) )->productsByIds( array( 20 ) );
check( '10% off: 33.00 - 3.30 = 29.70 + 21% = 35.94', 3594 === $sale[ array_search( '20:4-8-15', array_column( $sale, 'externalId' ), true ) ]['priceMinor'] );
$db->discount = array( 'price' => '15', 'type' => 'F' );
$fixed        = ( new \Sudemi\Catalogue( $db, new FakeConfig() ) )->productsByIds( array( 20 ) );
check( 'fixed special 15 + options 13 = 28.00 + 21% = 33.88', 3388 === $fixed[ array_search( '20:4-8-15', array_column( $fixed, 'externalId' ), true ) ]['priceMinor'] );
$db->discount = null;

check( 'dimensions in mm from the length class (30 x 20 x 0.5 cm); no minimum of 1 sent; in stock, no backorders',
	300 === $xl['lengthMm'] && 200 === $xl['widthMm'] && 5 === $xl['heightMm'] && ! isset( $xl['minQuantity'] )
	&& 'in_stock' === $xl['availability'] && false === $xl['allowBackorders'] && ! isset( $xl['salePriceMinor'] ) && ! isset( $xl['quantityPrices'] ) );

// A special with dates is a sale window (sudemi starts and ends it on time); discounts for 2+ are quantity prices.
$from         = date( 'Y-m-d', time() - 86400 );
$until        = date( 'Y-m-d', time() + 7 * 86400 );
$db->specials = array( array( 'price' => '10', 'type' => 'P', 'quantity' => 1, 'date_start' => $from, 'date_end' => $until ) );
$db->discount = array( 'price' => '10', 'type' => 'P' ); // what effectivePrice reads for today
$db->tiers    = array( array( 'price' => '5', 'type' => 'S', 'quantity' => 3 ), array( 'price' => '20', 'type' => 'P', 'quantity' => 10 ),
	array( 'price' => '1', 'type' => 'S', 'quantity' => 3 ) );
$db->products[0]['minimum'] = 2;
$sale = ( new \Sudemi\Catalogue( $db, new FakeConfig() ) )->productsByIds( array( 20 ) );
$sx   = $sale[ array_search( '20:4-8-15', array_column( $sale, 'externalId' ), true ) ];
check( 'sale: 33.00 regular (39.93), 10% off while it runs (35.94), from and until as the shop set them (until exclusive)',
	3594 === $sx['priceMinor'] && 3993 === $sx['regularPriceMinor'] && 3594 === $sx['salePriceMinor']
	&& date( DATE_ATOM, strtotime( $from . ' 00:00:00' ) ) === $sx['salePriceFrom'] && date( DATE_ATOM, strtotime( $until . ' 00:00:00' ) ) === $sx['salePriceUntil'], $sx );
check( 'quantity prices: 3+ at 33 - 5 = 28.00 (33.88), 10+ at 20% off = 26.40 (31.94); one tier per quantity; minimum 2 sent',
	array( array( 'minQuantity' => 3, 'priceMinor' => 3388 ), array( 'minQuantity' => 10, 'priceMinor' => 3194 ) ) === $sx['quantityPrices'] && 2 === $sx['minQuantity'], $sx['quantityPrices'] );
$db->specials = array( array( 'price' => '15', 'type' => 'F', 'quantity' => 1, 'date_start' => date( 'Y-m-d', time() + 3 * 86400 ), 'date_end' => '0000-00-00' ) );
$db->discount = null;
$soon = ( new \Sudemi\Catalogue( $db, new FakeConfig() ) )->productsByIds( array( 20 ) );
$so   = $soon[ array_search( '20:4-8-15', array_column( $soon, 'externalId' ), true ) ];
check( 'a special still to come: today\'s price, the sale (15 + options 13 = 28.00, 33.88) from its day, open-ended',
	3993 === $so['priceMinor'] && 3388 === $so['salePriceMinor'] && isset( $so['salePriceFrom'] ) && ! isset( $so['salePriceUntil'] ), $so );
$db->specials = array();
$db->tiers    = array();
$db->products[0]['minimum'] = 1;

// Availability as the storefront handles it.
$config = new FakeConfig();
$db->products[1] = product( 22, 'Lamp', '10.0000', 0, array( 'stock_status_id' => 6 ) );
$lamp = function () use ( $db, &$config ) {
	$l = ( new \Sudemi\Catalogue( $db, $config ) )->productsByIds( array( 22 ) );
	return $l ? $l[0] : array();
};
$l = $lamp();
check( 'out of stock, no stock checkout: out_of_stock with the shop\'s words', 'out_of_stock' === $l['availability'] && false === $l['allowBackorders'] && '2-3 Days' === $l['availabilityText'], $l );
$config->values['config_stock_checkout'] = 1;
$l = $lamp();
check( 'out of stock with stock checkout on: a backorder ("2-3 Days")', 'backorder' === $l['availability'] && true === $l['allowBackorders'], $l );
$db->products[1]['stock_status_id'] = 8;
$l = $lamp();
check( 'stock status "Pre-Order": a pre-order', 'preorder' === $l['availability'] && 'Pre-Order' === $l['availabilityText'] );
$db->products[1]['date_available'] = date( 'Y-m-d', time() + 10 * 86400 );
$l = $lamp();
check( 'available from a later date: a pre-order with that date', 'preorder' === $l['availability'] && $db->products[1]['date_available'] === $l['availabilityDate'] );
$db->products[1] = product( 22, 'Lamp', '10.0000', 0, array( 'subtract' => 0 ) );
unset( $config->values['config_stock_checkout'] );
$l = $lamp();
check( 'stock not counted (Subtract Stock: No): in stock, always taken', 'in_stock' === $l['availability'] && true === $l['allowBackorders'] );
$db->products[1] = product( 21, 'Logo mug', '9.0000', 4 );

// More than the cap: told once.
$db->values[203][] = pov( 17, 'Silk', '30' );
$db->values[203][] = pov( 18, 'Wool', '12' );
$db->values[203][] = pov( 19, 'Linen', '8' );
$big               = new \Sudemi\Catalogue( $db, new FakeConfig() );
check( '5 x 5 x 5 = 125 combinations: 100 sent, the cap told once', 100 === count( $big->productsByIds( array( 20 ) ) )
	&& array( 20 => 125 ) === $big->takeCapped() && array() === $big->takeCapped() );
array_splice( $db->values[203], 2 );

// ---- 3. Sync::pushFamily: a removed value -----------------------------------------------------------
class ArrayStore {
	public $values = array();
	public function read( $key ) {
		return isset( $this->values[ $key ] ) ? $this->values[ $key ] : null;
	}
	public function write( $key, $value ) {
		$this->values[ $key ] = $value;
	}
	public function delete( $key ) {
		unset( $this->values[ $key ] );
	}
}
class FakeKeys extends \Sudemi\Keys {
	public function __construct() {}
	public function hasKey() {
		return true;
	}
	public function sign( $message ) {
		return 'sig';
	}
}
class FakeClient extends \Sudemi\Client {
	public $calls = array();
	public function __construct( $store ) {
		parent::__construct( new FakeKeys(), $store, new \Sudemi\Logger( $store ) );
	}
	public function isConnected() {
		return true;
	}
	public function installId() {
		return 'install';
	}
	public function sendSigned( $method, $path, array $body = array(), array $headers = array() ) {
		$this->calls[] = array( $method, $path, $body );
		return array( 'status' => 200, 'body' => array( 'status' => 'ok' ) );
	}
	public function postSigned( $path, array $body ) {
		return $this->sendSigned( 'POST', $path, $body );
	}
}
/** The real Catalogue for listings, with a fixed revision and family. */
class SyncCatalogue extends \Sudemi\Catalogue {
	public function revision( $changes ) {
		return 'rev-' . $changes;
	}
	public function familyIds( $productId ) {
		return array( (int) $productId );
	}
	public function categories() {
		return array();
	}
}
class FakeHeartbeat {
	public function changes() {
		return 1;
	}
	public function noteChange() {}
}
$store   = new ArrayStore();
$client  = new FakeClient( $store );
$logger  = new \Sudemi\Logger( $store );
$sync    = new \Sudemi\Sync( $client, $logger, new SyncCatalogue( $db, new FakeConfig() ), $store );
$queue   = new \Sudemi\Queue( $store, $client );
$posts   = function () use ( $client ) {
	return array_values( array_filter( $client->calls, function ( $c ) {
		return '/integration/v1/products' === $c[1];
	} ) );
};
$sync->pushFamily( 20, $queue, new FakeHeartbeat() );
$sent = $posts();
check( 'first push: all 50 combinations in one request, with the revision (nothing removed)', 1 === count( $sent ) && 50 === count( $sent[0][2]['products'] )
	&& 'rev-1' === ( $sent[0][2]['catalogueRevision'] ?? null ), count( $sent ) );
check( 'the listings sent are remembered', 50 === count( explode( ',', $db->listed[20] ?? '' ) ) );
// The merchant removes Navy (5 combinations x 2 materials go).
$navy = array_splice( $db->values[202], 3, 1 );
$client->calls = array();
$sync->pushFamily( 20, $queue, new FakeHeartbeat() );
$sent = $posts();
check( 'Navy removed: 40 listings pushed without the revision (a full sync must take 10 off sudemi)',
	1 === count( $sent ) && 40 === count( $sent[0][2]['products'] ) && ! isset( $sent[0][2]['catalogueRevision'] ) && 1 === (int) $store->read( \Sudemi\Sync::SETTING_REMOVALS ) );
// Navy back: an addition removes nothing; the removal still waits for its full sync.
array_splice( $db->values[202], 3, 0, $navy );
$client->calls = array();
$sync->pushFamily( 20, $queue, new FakeHeartbeat() );
check( 'a value added counts no removal', 1 === (int) $store->read( \Sudemi\Sync::SETTING_REMOVALS ) && 50 === count( $posts()[0][2]['products'] ) );
// A full sync left by an earlier connection (seen in the e2e: the first sync after connecting again was lost to a 422).
$store->write( \Sudemi\Sync::SETTING_STATE, json_encode( array( 'id' => 'old', 'install' => 'earlier', 'cursor' => 5, 'sent' => 0, 'started' => time() ) ) );
check( 'a sync of an earlier connection is forgotten and a new full sync asked for', ! $sync->inProgress()
	&& null === $store->read( \Sudemi\Sync::SETTING_STATE ) && 1 === $store->read( \Sudemi\Withdrawal::SETTING_RESYNC_DUE ) );
$store->write( \Sudemi\Sync::SETTING_STATE, json_encode( array( 'id' => 'mine', 'install' => 'install', 'cursor' => 5, 'sent' => 0, 'started' => time() ) ) );
check( 'this connection\'s own sync continues', $sync->inProgress() );
$store->delete( \Sudemi\Sync::SETTING_STATE );
check( 'byProduct: listings per product, [] for one without', array( 20 => array( '20:1', '20:2' ), 21 => array() )
	=== \Sudemi\Sync::byProduct( array( 20, 21 ), array( array( 'externalId' => '20:1' ), array( 'externalId' => '20:2' ), array( 'externalId' => '99' ) ) ) );

// ---- 4. Orders ----------------------------------------------------------------------------------------
/** Catalogue's database plus what an order writes. */
class OrderDb extends CatalogueDb {
	public $last = 100;
	public function getLastId() {
		return ++$this->last;
	}
	public function query( $sql ) {
		$flat = preg_replace( '/\s+/', ' ', trim( $sql ) );
		if ( preg_match( '/^(INSERT|UPDATE) /', $flat ) ) {
			$this->writes[] = $flat;
			return true;
		}
		if ( preg_match( "/^SHOW COLUMNS FROM `oc_order_product` LIKE 'master_id'$/", $flat ) ) {
			return (object) array( 'num_rows' => 1, 'row' => array( 'Field' => 'master_id' ), 'rows' => array( array( 'Field' => 'master_id' ) ) );
		}
		if ( preg_match( '/^SELECT p.product_id, p.quantity, p.subtract, p.status, .* WHERE p.product_id = (\d+)$/', $flat, $m ) ) {
			foreach ( $this->products as $p ) {
				if ( $p['product_id'] === (int) $m[1] ) {
					$row = array( 'product_id' => $p['product_id'], 'quantity' => $p['quantity'], 'subtract' => 1, 'status' => 1, 'master_id' => 0,
						'variant' => '', 'override' => '', 'name' => $p['name'] );
					return (object) array( 'num_rows' => 1, 'row' => $row, 'rows' => array( $row ) );
				}
			}
			return (object) array( 'num_rows' => 0, 'row' => array(), 'rows' => array() );
		}
		if ( preg_match( '/^SELECT currency_id, code, value, status FROM oc_currency/', $flat ) ) {
			$row = array( 'currency_id' => 3, 'code' => 'EUR', 'value' => 1, 'status' => 1 );
			return (object) array( 'num_rows' => 1, 'row' => $row, 'rows' => array( $row ) );
		}
		return parent::query( $sql );
	}
	public function written( $prefix ) {
		return array_values( array_filter( $this->writes, function ( $w ) use ( $prefix ) {
			return 0 === strpos( $w, $prefix );
		} ) );
	}
}
$odb          = new OrderDb();
$odb->products = $db->products;
$odb->options  = $db->options;
$odb->values   = $db->values;
// An optional gift wrap (radio, priced) the buyer picked as a choice on sudemi.
$odb->options[20][] = option_row( 205, 20, 'Gift wrap', 'radio', 0 );
$odb->values[205]   = array( pov( 61, 'Paper', '3' ), pov( 62, 'Box', '5', '+', 1 ) );
$orders       = new \Sudemi\Orders( $odb, new FakeConfig(), $client, $logger, $store );
$shortfall    = new ReflectionMethod( \Sudemi\Orders::class, 'firstShortfall' );
$shortfall->setAccessible( true );
$create       = new ReflectionMethod( \Sudemi\Orders::class, 'createLocalOrder' );
$create->setAccessible( true );
$line         = array( 'external_id' => '20:4-8-15', 'quantity' => 1, 'title' => 'T-shirt (Size: XL, Colour: Red, Material: Cotton)',
	'personalisation' => array( array( 'key' => '204', 'label' => 'Custom name', 'value' => 'AJAX AMSTERDAM' ),
		array( 'key' => '205', 'label' => 'Gift wrap', 'value' => 'Paper', 'type' => 'choice', 'choice_key' => '61', 'choice_keys' => array( '61' ) ) ) );
check( 'an order for XL / Red / Cotton can be supplied', null === $shortfall->invoke( $orders, array( 'lines' => array( $line ) ) ) );
check( 'a combination that is gone (Navy removed, an unknown value): no longer sold here',
	'"x" is no longer sold here' === $shortfall->invoke( $orders, array( 'lines' => array( array( 'external_id' => '20:4-99-15', 'quantity' => 1, 'title' => 'x' ) ) ) ) );
check( 'the plain product id of a product with options: no longer sold here (which size?)',
	'"x" is no longer sold here' === $shortfall->invoke( $orders, array( 'lines' => array( array( 'external_id' => '20', 'quantity' => 1, 'title' => 'x' ) ) ) ) );
check( 'XL has 3: an order of 4 is out of stock', '"x" is out of stock' === $shortfall->invoke( $orders, array( 'lines' => array( array( 'external_id' => '20:4-8-15', 'quantity' => 4, 'title' => 'x' ) ) ) ) );
$orderId = $create->invoke( $orders, array( 'lines' => array( $line ), 'currency_code' => 'EUR', 'total_minor' => 6413, 'destination_country' => 'DE' ), '11111111-2222-4333-8444-555555555555' );
$op      = $odb->written( 'INSERT INTO oc_order_product SET' );
check( 'the order line: the product, its own name, master_id 0', 1 === count( $op ) && false !== strpos( $op[0], "product_id = '20'" )
	&& false !== strpos( $op[0], "master_id = '0'" ) && false !== strpos( $op[0], "name = 'T-shirt'" ), $op );
$oo = $odb->written( 'INSERT INTO oc_order_option SET' );
check( 'order_option: Size XL, Colour Red, Material Cotton and the custom name, on the order line', 5 === count( $oo )
	&& false !== strpos( $oo[0], "product_option_id = '201', product_option_value_id = '4', name = 'Size', value = 'XL', type = 'select'" )
	&& false !== strpos( $oo[1], "name = 'Colour', value = 'Red', type = 'radio'" )
	&& false !== strpos( $oo[2], "name = 'Material', value = 'Cotton'" )
	&& false !== strpos( $oo[3], "product_option_id = '204', product_option_value_id = '0', name = 'Custom name', value = 'AJAX AMSTERDAM', type = 'text'" )
	&& false !== strpos( $oo[0], "order_product_id = '" . ( $orderId + 1 ) . "'" ), $oo );
$stock = $odb->written( 'UPDATE oc_product_option_value' );
check( 'stock comes off each chosen value that keeps stock (XL, Red, Cotton; the gift wrap below)', 4 === count( $stock )
	&& false !== strpos( $stock[0], "product_option_value_id = '4' AND subtract = '1'" ) && false !== strpos( $stock[2], "product_option_value_id = '15'" ), $stock );
check( 'and off the product', 1 === count( $odb->written( "UPDATE oc_product SET quantity = quantity - '1' WHERE product_id = '20'" ) ) );
check( 'the gift wrap chosen on sudemi is on the order line with its value, and its stock comes off', 5 === count( $oo )
	&& false !== strpos( $oo[4], "product_option_id = '205', product_option_value_id = '61', name = 'Gift wrap', value = 'Paper', type = 'radio'" )
	&& 1 === count( $odb->written( "UPDATE oc_product_option_value SET quantity = quantity - '1' WHERE product_option_value_id = '61' AND subtract = '1'" ) ), $oo );
$box = $line;
$box['personalisation'][1]['choice_key'] = '62';
unset( $box['personalisation'][1]['choice_keys'] );
check( 'a chosen choice with not enough stock (Box: 1, 2 ordered): out of stock', '"x" is out of stock' === $shortfall->invoke( $orders,
	array( 'lines' => array( array( 'title' => 'x', 'quantity' => 2 ) + $box ) ) ) );
$checkout = new FakeConfig();
$checkout->values['config_stock_checkout'] = 1;
$backorders = new \Sudemi\Orders( $odb, $checkout, $client, $logger, $store );
check( 'the shop sells without stock (Stock Checkout on): 4 XL (3 in stock) is taken, as in its storefront',
	null === $shortfall->invoke( $backorders, array( 'lines' => array( array( 'external_id' => '20:4-8-15', 'quantity' => 4, 'title' => 'x' ) ) ) ) );

// Owner rule 2026-11-19: an option whose price lowers the price (a "-" value) cannot be charged
// through sudemi. REQUIRED: the product is not listed (an error issue); OPTIONAL: left out (a warning).
$disc_opt = opt( 61, 'Club discount', 'checkbox', array( pov( 611, 'Member', '3', '-' ) ), true );
$scr      = Options::screen( Options::plan( array( $disc_opt ) ) );
check( 'required negative option (a required checkbox with a "-" price): the product is blocked, an error issue',
	'addon_negative_price' === $scr['blocked'] && 'error' === $scr['issues'][0][1] && array() === $scr['texts'], $scr );
$wrap_opt = opt( 62, 'Gift wrap', 'select', array( pov( 621, 'Paper', '2', '-' ) ), false );
$scr      = Options::screen( Options::plan( array( $wrap_opt, $name ) ) );
check( 'optional negative option: left out with a warning, the product stays listed with the custom name',
	null === $scr['blocked'] && 'warning' === $scr['issues'][0][1] && 'Gift wrap' === $scr['issues'][0][2] && 1 === count( $scr['texts'] ), $scr );
$six_req = Options::plan( array_map( function ( $i ) { return opt( 70 + $i, 'Line ' . $i, 'text', array(), true ); }, range( 1, 6 ) ) );
check( 'six required texts: blocked (too_many_required_texts)', 'too_many_required_texts' === Options::screen( $six_req )['blocked'] );
check( 'an option without a "-" price: nothing blocked, nothing reported', array( 'texts' => Options::plan( array( $name ) )['texts'], 'issues' => array(), 'blocked' => null ) === Options::screen( Options::plan( array( $name ) ) ) );

if ( $failures > 0 ) {
	echo "\n", $failures, " check(s) failed\n";
	exit( 1 );
}
echo "\nall option checks passed\n";
