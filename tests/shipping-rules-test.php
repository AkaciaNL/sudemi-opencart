<?php
/**
 * Shipping rules (1.4.7): the shop's own OpenCart shipping rules as sudemi charges a parcel.
 *
 * 1. Weight class conversion to grams (kg, g, lb, oz store classes), rounded down.
 * 2. Weight bands read like OpenCart's checkout (order typed, unreachable entries, broken ones).
 * 3. Merging band lists (geo zones, a flat rate next to them).
 * 4. One rule per country: flat, per item, weight, free shipping, pickup, carriers, choices.
 * 5. The payload: rules sorted by country, issues grouped with sorted countries, shop-wide.
 * 6. Shipping::rulesPayload() on a faked OpenCart database (DE, FR, NL, PL, AT).
 * 7. The sender: flag, unchanged rules not sent again, retries, forget; a commit asks for them.
 * 8. The new admin texts exist in all 12 languages; no long dash.
 *
 * Runs without OpenCart: the database, the config and sudemi are faked here.
 */

declare(strict_types=1);

define( 'DB_PREFIX', 'oc_' );
foreach ( array( 'store', 'logger', 'keys', 'client', 'shipping', 'shipping_rules' ) as $file ) {
	require_once __DIR__ . '/../system/library/sudemi/' . $file . '.php';
}

use Sudemi\ShippingRules;
use Sudemi\ShippingRulesSender;

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

// 1. Weight classes (OpenCart's defaults: kg 1, g 1000, lb 2.20462262, oz 35.27396195).
check( 'kg store: 2 kg is 2000 g', 2000 === ShippingRules::toGrams( 2, 1.0, 1.0 ) );
check( 'kg store: 0.5 kg is 500 g (no float loss)', 500 === ShippingRules::toGrams( 0.5, 1.0, 1.0 ) );
check( 'g store: 2500 g is 2500 g', 2500 === ShippingRules::toGrams( 2500, 1000.0, 1.0 ) );
check( 'g store: 2.7 g rounds down to 2', 2 === ShippingRules::toGrams( 2.7, 1000.0, 1.0 ) );
check( 'lb store: 1 lb is 453 g (rounded down)', 453 === ShippingRules::toGrams( 1, 2.20462262, 1.0 ) );
check( 'oz store: 16 oz is 453 g', 453 === ShippingRules::toGrams( 16, 35.27396195, 1.0 ) );
check( 'kg class found as grams only (value 1000 per g class, kg = 1)', 3000 === ShippingRules::toGrams( 3, 1.0, 1000 / 1000 ) );
check( 'unknown store class: null', null === ShippingRules::toGrams( 2, 0, 1.0 ) );
check( 'unknown kg class: null', null === ShippingRules::gramsPerUnit( 1.0, null ) );

// 2. Weight bands. Price: cost + 21% in cents.
$price = function ( $cost ) {
	return (int) round( $cost * 1.21 * 100 );
};
$w = ShippingRules::weightBands( '2:4.00, 10:7.00,30:12.00', 1000.0, $price );
check( 'bands: grams and prices with tax', $w['bands'] === array(
	array( 'upTo' => 2000, 'priceMinor' => 484 ),
	array( 'upTo' => 10000, 'priceMinor' => 847 ),
	array( 'upTo' => 30000, 'priceMinor' => 1452 ),
) && null === $w['approximated'] );
$w = ShippingRules::weightBands( '10:7,2:4,20:9', 1000.0, $price );
check( 'bands: an entry lighter than one before it is never reached (OpenCart order)', $w['bands'] === array(
	array( 'upTo' => 10000, 'priceMinor' => 847 ),
	array( 'upTo' => 20000, 'priceMinor' => 1089 ),
) );
$w = ShippingRules::weightBands( '5:4,10:4,15:6,', 1000.0, $price );
check( 'bands: equal neighbours joined, trailing comma ignored', $w['bands'] === array(
	array( 'upTo' => 10000, 'priceMinor' => 484 ),
	array( 'upTo' => 15000, 'priceMinor' => 726 ),
) );
$w = ShippingRules::weightBands( '2:4,5,10:9', 1000.0, $price );
check( 'bands: an entry without a cost -> one band to the heaviest weight at the highest price, reported',
	$w['bands'] === array( array( 'upTo' => 10000, 'priceMinor' => 1089 ) ) && is_string( $w['approximated'] ) );
$w = ShippingRules::weightBands( '2:4,10:9', null, $price );
check( 'bands: no gram conversion -> highest rate at every weight, reported',
	$w['bands'] === array( array( 'upTo' => null, 'priceMinor' => 1089 ) ) && is_string( $w['approximated'] ) );
$w = ShippingRules::weightBands( 'abc:3', 1000.0, $price );
check( 'bands: nothing priceable -> no bands, reported', array() === $w['bands'] && is_string( $w['approximated'] ) );
$w = ShippingRules::weightBands( '4.4:5', ShippingRules::gramsPerUnit( 2.20462262, 1.0 ), $price );
check( 'bands: lb store, 4.4 lb -> 1995 g', 1995 === $w['bands'][0]['upTo'] );

// 3. Merging.
$m = ShippingRules::mergeBands( array(
	array( array( 'upTo' => 2000, 'priceMinor' => 500 ), array( 'upTo' => 10000, 'priceMinor' => 900 ) ),
	array( array( 'upTo' => 5000, 'priceMinor' => 700 ), array( 'upTo' => 20000, 'priceMinor' => 1500 ) ),
) );
check( 'merge: lowest price at every weight, to the heaviest band', $m === array(
	array( 'upTo' => 2000, 'priceMinor' => 500 ),
	array( 'upTo' => 5000, 'priceMinor' => 700 ),
	array( 'upTo' => 10000, 'priceMinor' => 900 ),
	array( 'upTo' => 20000, 'priceMinor' => 1500 ),
) );
$m = ShippingRules::mergeBands( array(
	array( array( 'upTo' => 2000, 'priceMinor' => 500 ), array( 'upTo' => 10000, 'priceMinor' => 900 ) ),
	array( array( 'upTo' => null, 'priceMinor' => 800 ) ),
) );
check( 'merge: flat rate caps the bands and covers every weight', $m === array(
	array( 'upTo' => 2000, 'priceMinor' => 500 ),
	array( 'upTo' => null, 'priceMinor' => 800 ),
) );

// 4. One country.
$r = ShippingRules::forCountry( 'FR', array( 'flat' => 695 ) );
check( 'flat: baseMinor', $r['rule'] === array( 'country' => 'FR', 'baseMinor' => 695 ) && array() === $r['issues'] );
$r = ShippingRules::forCountry( 'DE', array( 'item' => array( 'first' => 302, 'extra' => 303 ) ) );
check( 'per item: baseMinor and extraItemMinor', $r['rule'] === array( 'country' => 'DE', 'baseMinor' => 302, 'extraItemMinor' => 303 ) );
$bands = array( array( 'upTo' => 2000, 'priceMinor' => 495 ), array( 'upTo' => 10000, 'priceMinor' => 895 ) );
$r = ShippingRules::forCountry( 'NL', array( 'weight' => array( array( 'bands' => $bands, 'approximated' => null ) ) ) );
check( 'weight: bands, nothing above the last', $r['rule'] === array( 'country' => 'NL', 'bandBasis' => 'weight', 'bands' => $bands, 'aboveLast' => 'none' ) );
$r = ShippingRules::forCountry( 'NL', array( 'flat' => 1000, 'weight' => array( array( 'bands' => $bands, 'approximated' => null ) ) ) );
check( 'weight + flat: bands with the flat rate above them, exact', $r['rule']['bands'] === array(
	array( 'upTo' => 2000, 'priceMinor' => 495 ), array( 'upTo' => 10000, 'priceMinor' => 895 ), array( 'upTo' => null, 'priceMinor' => 1000 ),
) && ! isset( $r['rule']['aboveLast'] ) && array() === $r['issues'] );
$r = ShippingRules::forCountry( 'BE', array( 'flat' => 500, 'item' => array( 'first' => 600, 'extra' => 600 ) ) );
check( 'flat cheaper than one item: flat, exact', $r['rule'] === array( 'country' => 'BE', 'baseMinor' => 500 ) && array() === $r['issues'] );
$r = ShippingRules::forCountry( 'BE', array( 'flat' => 900, 'item' => array( 'first' => 300, 'extra' => 300 ) ) );
check( 'per item cheaper for one item next to flat: per item, reported',
	$r['rule'] === array( 'country' => 'BE', 'baseMinor' => 300, 'extraItemMinor' => 300 )
	&& 1 === count( $r['issues'] ) && ShippingRules::APPROXIMATED === $r['issues'][0]['code'] && 'item' === $r['issues'][0]['method'] );
$r = ShippingRules::forCountry( 'NL', array( 'item' => array( 'first' => 300, 'extra' => 300 ), 'weight' => array( array( 'bands' => $bands, 'approximated' => null ) ) ) );
check( 'per item cheaper than the first band: per item, reported (weight)', 300 === $r['rule']['baseMinor'] && 'item' === $r['issues'][0]['method'] );
$r = ShippingRules::forCountry( 'NL', array( 'item' => array( 'first' => 1200, 'extra' => 1200 ), 'weight' => array( array( 'bands' => $bands, 'approximated' => null ) ) ) );
check( 'bands without a flat rate next to per item: bands, reported (heavier parcels only per item in OpenCart)',
	isset( $r['rule']['bands'] ) && 'weight' === $r['issues'][0]['method'] );
$r = ShippingRules::forCountry( 'AT', array( 'flat' => 500, 'free' => array( 'always' => false, 'overMinor' => 6050 ) ) );
check( 'free over a total: freeOverMinor on the chosen rule', $r['rule'] === array( 'country' => 'AT', 'baseMinor' => 500, 'freeOverMinor' => 6050 ) );
$r = ShippingRules::forCountry( 'AT', array( 'flat' => 500, 'free' => array( 'always' => true, 'overMinor' => 0 ) ) );
check( 'free with total 0: baseMinor 0', $r['rule'] === array( 'country' => 'AT', 'baseMinor' => 0 ) );
$r = ShippingRules::forCountry( 'AT', array( 'flat' => 500, 'free' => array( 'always' => false, 'overMinor' => null ) ) );
check( 'free with a fixed tax: left out (shop never undercharged), reported',
	$r['rule'] === array( 'country' => 'AT', 'baseMinor' => 500 ) && 'free' === $r['issues'][0]['method'] );
$r = ShippingRules::forCountry( 'PL', array( 'free' => array( 'always' => false, 'overMinor' => 6050 ), 'pickup' => true, 'others' => array( 'dhl_express', 'my_table' ) ) );
$codes = array_map( function ( $i ) { return $i['code'] . ':' . $i['method']; }, $r['issues'] );
check( 'nothing fixed: no rule, never a price invented', null === $r['rule'] );
check( 'nothing fixed: free-only, pickup, carrier (calculated) and other module (approximated) reported', $codes === array(
	'shipping_rule_approximated:free', 'shipping_rule_approximated:pickup', 'shipping_rate_calculated:dhl_express', 'shipping_rule_approximated:my_table',
) );
$r = ShippingRules::forCountry( 'PL', array( 'flat' => 500, 'pickup' => true, 'others' => array( 'ups' ) ) );
check( 'pickup and carriers next to a fixed method: not reported', array() === $r['issues'] );
check( 'carrier codes', ShippingRules::isCarrier( 'ups' ) && ShippingRules::isCarrier( 'royal_mail' ) && ShippingRules::isCarrier( 'dhl_express' ) && ! ShippingRules::isCarrier( 'pickups_table' ) && ! ShippingRules::isCarrier( 'xshipping_pro' ) );
check( 'extra item rounded up (1.5125 per item -> 152)', 152 === ShippingRules::extraMinor( 1.5125, 3.025, 'EUR' ) );
check( 'extra item with a fixed tax counted once (gross(2c) - gross(c))', 250 === ShippingRules::extraMinor( 2.5 + 1.0, 5.0 + 1.0, 'EUR' ) );
check( 'free minimum 50.00 without tax at 21% -> 60.50 with tax', 6050 === ShippingRules::freeOverMinor( 50, 1.21, 'EUR' ) );
check( 'free minimum rounded up', 3631 === ShippingRules::freeOverMinor( 30.005, 1.21, 'EUR' ) );
check( 'free minimum in JPY (no decimals)', 5500 === ShippingRules::freeOverMinor( 5000, 1.1, 'JPY' ) );

// 5. Payload.
$p = ShippingRules::payload( 'EUR', array(
	'NL' => array( 'flat' => 900, 'item' => array( 'first' => 300, 'extra' => 300 ) ),
	'DE' => array( 'flat' => 900, 'item' => array( 'first' => 300, 'extra' => 300 ) ),
	'FR' => array( 'flat' => 695 ),
	'PL' => array( 'others' => array( 'ups' ) ),
) );
check( 'payload: currency, rules sorted by country', 'EUR' === $p['currency']
	&& array( 'DE', 'FR', 'NL' ) === array_map( function ( $r ) { return $r['country']; }, $p['rules'] ) );
check( 'payload: one issue per code and method, countries sorted, shop-wide (no externalId)',
	2 === count( $p['issues'] ) && array( 'DE', 'NL' ) === $p['issues'][0]['details']['countries']
	&& 'item' === $p['issues'][0]['details']['method'] && ! isset( $p['issues'][0]['externalId'] )
	&& array( 'PL' ) === $p['issues'][1]['details']['countries'] && 'shipping_rate_calculated' === $p['issues'][1]['code'] );
check( 'payload: JSON shape', false !== strpos( (string) json_encode( $p ), '"details":{"countries":["DE","NL"],"method":"item"}' ) );

// 6. Shipping::rulesPayload() on a faked OpenCart.
class Result {
	public $row;
	public $rows;
	public function __construct( array $rows ) {
		$this->rows = $rows;
		$this->row  = $rows ? $rows[0] : array();
	}
}
class FakeDb {
	public $geo = array(
		// geo_zone_id => country ids (whole country)
		3 => array( 81 ),          // "Germany"
		4 => array( 81, 150, 170 ), // "EU": DE, NL, PL
		5 => array( 72 ),          // "France" (weight)
	);
	public $taxClasses = array( 9, 10 );
	public function query( $sql ) {
		$sql = preg_replace( '/\s+/', ' ', $sql );
		if ( false !== strpos( $sql, 'FROM oc_country' ) ) {
			return new Result( array(
				array( 'country_id' => 81, 'iso_code_2' => 'DE' ), array( 'country_id' => 72, 'iso_code_2' => 'FR' ),
				array( 'country_id' => 150, 'iso_code_2' => 'NL' ), array( 'country_id' => 170, 'iso_code_2' => 'PL' ),
				array( 'country_id' => 14, 'iso_code_2' => 'AT' ),
			) );
		}
		if ( preg_match( '/SELECT 1 FROM oc_zone_to_geo_zone WHERE geo_zone_id = (\d+) AND country_id = (\d+)/', $sql, $m ) ) {
			return new Result( isset( $this->geo[ (int) $m[1] ] ) && in_array( (int) $m[2], $this->geo[ (int) $m[1] ], true ) ? array( array( 1 => 1 ) ) : array() );
		}
		if ( preg_match( '/SELECT DISTINCT geo_zone_id FROM oc_zone_to_geo_zone WHERE country_id = (\d+)/', $sql, $m ) ) {
			$out = array();
			foreach ( $this->geo as $id => $countries ) {
				if ( in_array( (int) $m[1], $countries, true ) ) {
					$out[] = array( 'geo_zone_id' => $id );
				}
			}
			return new Result( $out );
		}
		if ( preg_match( '/tax_class_id = (\d+) AND z2gz.country_id = (\d+)/', $sql, $m ) ) {
			// Class 9: 21% everywhere (the store is in NL, 150), class 10: 9%.
			return new Result( array( array( 'rate' => '9' === $m[1] ? 21 : 9, 'type' => 'P' ) ) );
		}
		if ( false !== strpos( $sql, 'FROM oc_weight_class' ) ) {
			return new Result( array(
				array( 'weight_class_id' => 1, 'value' => '1.00000000', 'unit' => 'kg' ),
				array( 'weight_class_id' => 2, 'value' => '1000.00000000', 'unit' => 'g' ),
				array( 'weight_class_id' => 5, 'value' => '2.20460000', 'unit' => 'lb' ),
			) );
		}
		if ( false !== strpos( $sql, 'DISTINCT tax_class_id FROM oc_product' ) ) {
			return new Result( array_map( function ( $c ) { return array( 'tax_class_id' => $c ); }, $this->taxClasses ) );
		}
		if ( false !== strpos( $sql, 'FROM oc_extension' ) ) {
			return new Result( array( array( 'code' => 'flat' ), array( 'code' => 'item' ), array( 'code' => 'weight' ),
				array( 'code' => 'free' ), array( 'code' => 'pickup' ), array( 'code' => 'ups' ) ) );
		}
		throw new RuntimeException( 'unexpected query: ' . $sql );
	}
}
class FakeConfig {
	public $values;
	public function __construct( array $values ) {
		$this->values = $values;
	}
	public function get( $key ) {
		return isset( $this->values[ $key ] ) ? $this->values[ $key ] : null;
	}
}
$config = new FakeConfig( array(
	'config_currency' => 'EUR', 'config_country_id' => 150, 'config_weight_class_id' => 2, // grams
	'shipping_flat_status' => 1, 'shipping_flat_cost' => '10.00', 'shipping_flat_tax_class_id' => 9, 'shipping_flat_geo_zone_id' => 3,
	'shipping_item_status' => 1, 'shipping_item_cost' => '2.50', 'shipping_item_tax_class_id' => 9, 'shipping_item_geo_zone_id' => 4,
	'shipping_weight_status' => 1, 'shipping_weight_tax_class_id' => 9,
	'shipping_weight_5_status' => 1, 'shipping_weight_5_rate' => '1000:5.00,5000:8.00',
	'shipping_free_status' => 1, 'shipping_free_total' => '50', 'shipping_free_geo_zone_id' => 4,
	'shipping_pickup_status' => 1, 'shipping_pickup_geo_zone_id' => 0,
	'shipping_ups_status' => 1,
) );
$db       = new FakeDb();
$shipping = new \Sudemi\Shipping( $db, $config );
$p        = $shipping->rulesPayload();
$rules    = array();
foreach ( $p['rules'] as $rule ) {
	$rules[ $rule['country'] ] = $rule;
}
check( 'OpenCart: store weight class grams -> 1 g per unit', 1.0 === $shipping->gramsPerStoreUnit() );
check( 'OpenCart: goods tax factor is the highest product rate (21%)', abs( $shipping->goodsTaxFactor() - 1.21 ) < 1e-9 );
check( 'OpenCart: FR weight bands in grams with 21% tax', isset( $rules['FR'] ) && $rules['FR'] === array( 'country' => 'FR', 'bandBasis' => 'weight',
	'bands' => array( array( 'upTo' => 1000, 'priceMinor' => 605 ), array( 'upTo' => 5000, 'priceMinor' => 968 ) ), 'aboveLast' => 'none' ) );
check( 'OpenCart: NL per item 2.50 + 21% = 3.03 first, 3.03 extra, free over 60.50', isset( $rules['NL'] ) && $rules['NL'] === array( 'country' => 'NL',
	'baseMinor' => 303, 'extraItemMinor' => 303, 'freeOverMinor' => 6050 ) );
check( 'OpenCart: DE per item cheaper than flat for one item: per item, reported', isset( $rules['DE'] ) && 303 === $rules['DE']['baseMinor'] && 6050 === $rules['DE']['freeOverMinor'] );
check( 'OpenCart: AT only pickup and a carrier: no rule', ! isset( $rules['AT'] ) );
$byCode = array();
foreach ( $p['issues'] as $i ) {
	$byCode[ $i['code'] . ':' . $i['details']['method'] ] = $i['details']['countries'];
}
check( 'OpenCart: issues for DE (choice), AT (pickup, ups)', isset( $byCode['shipping_rule_approximated:item'], $byCode['shipping_rule_approximated:pickup'], $byCode['shipping_rate_calculated:ups'] )
	&& array( 'DE' ) === $byCode['shipping_rule_approximated:item'] && array( 'AT' ) === $byCode['shipping_rate_calculated:ups'] );
$config->values['config_weight_class_id'] = 5; // lb
$lb = ( new \Sudemi\Shipping( $db, $config ) )->rulesPayload();
foreach ( $lb['rules'] as $rule ) {
	if ( 'FR' === $rule['country'] ) {
		check( 'OpenCart: lb store, 1000 lb -> 453597 g', 453597 === $rule['bands'][0]['upTo'] );
	}
}

// 7. Sender.
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
class FakeClient {
	public $calls   = array();
	public $answers = array();
	public $connected = true;
	public function isConnected() {
		return $this->connected;
	}
	public function postSigned( $path, array $body ) {
		$this->calls[] = array( $path, $body );
		return array_shift( $this->answers );
	}
}
class QuietLogger {
	public $lines = array();
	public function warn( $m ) {
		$this->lines[] = $m;
	}
}
$store  = new ArrayStore();
$client = new FakeClient();
$log    = new QuietLogger();
$body   = array( 'currency' => 'EUR', 'rules' => array( array( 'country' => 'FR', 'baseMinor' => 695 ) ), 'issues' => array() );
$sender = new ShippingRulesSender( $store, $client, $log, function () use ( &$body ) {
	return $body;
} );
$t = 1000000;
check( 'sender: never sent -> due', $sender->due( $t ) );
$client->answers[] = array( 'status' => 200, 'body' => array( 'status' => 'ok', 'accepted' => 1, 'ignored' => array( array( 'country' => 'US', 'reason' => 'not delivered' ) ) ) );
check( 'sender: sent', 'sent' === $sender->sendIfDue( $t ) && '/integration/v1/shipping/rules' === $client->calls[0][0] && $client->calls[0][1] === $body );
check( 'sender: ignored kept for the admin and logged', 'US' === $sender->state()['ignored'][0]['country'] && 1 === count( $log->lines ) );
check( 'sender: not due again within the hour', ! $sender->due( $t + 60 ) && 'idle' === $sender->sendIfDue( $t + 60 ) );
check( 'sender: hourly check, unchanged -> not sent', 'same' === $sender->sendIfDue( $t + 3700 ) && 1 === count( $client->calls ) );
ShippingRulesSender::request( $store );
$client->answers[] = array( 'status' => 200, 'body' => array( 'status' => 'ok' ) );
check( 'sender: asked (settings changed) -> sent even when unchanged', 'sent' === $sender->sendIfDue( $t + 3800 ) && 2 === count( $client->calls ) );
$body['rules'][0]['baseMinor'] = 795;
$client->answers[] = null;
check( 'sender: changed, sudemi unreachable -> failed, asked again', 'failed' === $sender->sendIfDue( $t + 7500 ) && $store->read( ShippingRulesSender::SETTING_DUE ) );
$client->answers[] = array( 'status' => 404, 'body' => array() );
check( 'sender: refused -> not asked again before the hourly check', 'failed' === $sender->sendIfDue( $t + 7600 ) && ! $store->read( ShippingRulesSender::SETTING_DUE ) && ! $sender->due( $t + 7700 ) );
$client->connected = false;
ShippingRulesSender::request( $store );
check( 'sender: not connected -> idle', 'idle' === $sender->sendIfDue( $t + 9000 ) );
$sender->forget();
check( 'sender: forget clears the state', array() === $sender->state() && null === $store->read( ShippingRulesSender::SETTING_DUE ) );
$sync = file_get_contents( __DIR__ . '/../system/library/sudemi/sync.php' );
check( 'sync commit asks for the rules', false !== strpos( $sync, 'ShippingRulesSender::request($this->store);' ) );
$withdrawal = file_get_contents( __DIR__ . '/../system/library/sudemi/withdrawal.php' );
foreach ( array( 'setting/setting.editSetting/after', 'localisation/geo_zone.editGeoZone/after', 'setting/extension.install/after' ) as $trigger ) {
	check( 'event registered: ' . $trigger, false !== strpos( $withdrawal, $trigger ) );
}

// 8. Texts.
foreach ( array( 'en-gb', 'nl-nl', 'tr-tr', 'de-de', 'fr-fr', 'es-es', 'it-it', 'pl-pl', 'ru-ru', 'zh-cn', 'ar', 'he-il' ) as $lang ) {
	$_ = array();
	include __DIR__ . '/../admin/language/' . $lang . '/module/sudemi.php';
	$all = true;
	foreach ( array( 'text_issue_shipping', 'text_issue_shipping_rule_approximated', 'text_issue_shipping_rate_calculated', 'text_issue_shipping_rule_ignored' ) as $key ) {
		$all = $all && ! empty( $_[ $key ] ) && false === strpos( $_[ $key ], "\u{2014}" );
	}
	check( 'texts in ' . $lang, $all );
}

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
