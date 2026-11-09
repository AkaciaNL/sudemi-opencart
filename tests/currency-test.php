<?php
/**
 * Currency step 3 (1.2.12).
 *
 * 1. Minor units follow ISO 4217 (JPY none, KWD three, EUR/PLN/HUF two), never a fixed x 100.
 * 2. An order in a currency that is not the shop's default, or that is switched off in the shop,
 *    is declined with the reason and nothing is booked.
 * 3. An order in the shop's currency is booked with its currency id, code and rate, at amounts
 *    in that currency's decimals (PLN and JPY); orders from a core without currency_code as before.
 * 4. A refund carries the order's currency; one in another currency is refused and not sent.
 * 5. The shop currency sudemi echoes on POST /shop is remembered; a refused one is noted.
 * 6. The new admin texts exist in all 12 languages; no long dash in them.
 *
 * Runs without OpenCart: the database, the config and sudemi are faked here.
 */

declare(strict_types=1);

define( 'DB_PREFIX', 'oc_' );
foreach ( array( 'store', 'logger', 'keys', 'client', 'queue', 'orders', 'sync' ) as $file ) {
	require_once __DIR__ . '/../system/library/sudemi/' . $file . '.php';
}

use Sudemi\Currency;

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

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
	public $calls   = array();
	public $answers = array();
	public function __construct( $store ) {
		parent::__construct( new FakeKeys(), $store, new \Sudemi\Logger( $store ) );
	}
	public function isConnected() {
		return true;
	}
	public function postSigned( $path, array $body ) {
		$this->calls[] = array( 'POST', $path, $body );
		return array_shift( $this->answers );
	}
	public function getSigned( $path ) {
		$this->calls[] = array( 'GET', $path, array() );
		return null;
	}
}

/** A shop database: currencies, one product (7) in stock, no tax classes; records every write. */
class FakeDb {
	public $currencies = array();
	public $booked     = null; // currency_code of the local order for the refund tests
	public $writes     = array();
	public function escape( $value ) {
		return addslashes( (string) $value );
	}
	public function getLastId() {
		return 100;
	}
	public function query( $sql ) {
		$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );
		$m   = array();
		if ( preg_match( "/^SELECT order_id, order_status_id, comment FROM oc_order WHERE comment IN/", $sql ) ) {
			return $this->rows( array() );
		}
		if ( preg_match( "/^SELECT currency_code FROM oc_order WHERE comment IN/", $sql ) ) {
			return $this->rows( null === $this->booked ? array() : array( array( 'currency_code' => $this->booked ) ) );
		}
		if ( preg_match( "/^SELECT currency_id, code, value, status FROM oc_currency WHERE code = '([A-Z]{3})' LIMIT 1$/", $sql, $m ) ) {
			return $this->rows( isset( $this->currencies[ $m[1] ] ) ? array( $this->currencies[ $m[1] ] ) : array() );
		}
		if ( preg_match( "/^SELECT p.quantity, p.status, p.subtract, pd.name FROM oc_product p/", $sql ) ) {
			return $this->rows( array( array( 'quantity' => 10, 'status' => 1, 'subtract' => 1, 'name' => 'Mug' ) ) );
		}
		if ( 0 === strpos( $sql, 'SHOW ' ) ) {
			return $this->rows( array() );
		}
		if ( preg_match( "/^SELECT country_id, name FROM oc_country/", $sql ) ) {
			return $this->rows( array( array( 'country_id' => 81, 'name' => 'Germany' ) ) );
		}
		if ( preg_match( "/^SELECT tax_class_id FROM oc_product/", $sql ) || preg_match( "/^SELECT code FROM oc_language/", $sql ) ) {
			return $this->rows( array() );
		}
		if ( preg_match( '/^(INSERT|UPDATE) /', $sql ) ) {
			$this->writes[] = $sql;
			return true;
		}
		throw new \RuntimeException( 'unexpected query: ' . $sql );
	}
	public function inserts( $table ) {
		return array_values( array_filter( $this->writes, function ( $w ) use ( $table ) {
			return 0 === strpos( $w, 'INSERT INTO oc_' . $table . ' SET' );
		} ) );
	}
	private function rows( array $rows ) {
		return (object) array( 'num_rows' => count( $rows ), 'row' => $rows ? $rows[0] : array(), 'rows' => $rows );
	}
}

class FakeConfig {
	public $values;
	public function __construct( $currency ) {
		$this->values = array( 'config_currency' => $currency, 'config_language_id' => 1 );
	}
	public function get( $key ) {
		return $this->values[ $key ] ?? null;
	}
}

const O1 = '11111111-2222-4333-8444-555555555555';

function shop( $currency, array $currencies ) {
	$db             = new FakeDb();
	$db->currencies = $currencies;
	$store          = new ArrayStore();
	$client         = new FakeClient( $store );
	$logger         = new \Sudemi\Logger( $store );
	$orders         = new \Sudemi\Orders( $db, new FakeConfig( $currency ), $client, $logger, $store );
	return array( $orders, $db, $client, $logger );
}
function order( $currency, $unit, $shipping, $quantity = 2 ) {
	$o = array(
		'order_id'            => O1,
		'total_minor'         => $unit * $quantity + $shipping,
		'shipping_minor'      => $shipping,
		'destination_country' => 'DE',
		'lines'               => array( array( 'external_id' => '7', 'title' => 'Mug', 'quantity' => $quantity, 'unit_price_minor' => $unit ) ),
	);
	if ( null !== $currency ) {
		$o['currency_code'] = $currency;
	}
	return $o;
}
function paths( $client ) {
	return array_map( function ( $c ) {
		return $c[0] . ' ' . $c[1];
	}, $client->calls );
}
function logged( $logger, $needle ) {
	foreach ( $logger->entries() as $entry ) {
		if ( false !== strpos( json_encode( $entry, JSON_UNESCAPED_UNICODE ), $needle ) ) {
			return true;
		}
	}
	return false;
}

$PLN = array( 'currency_id' => '4', 'code' => 'PLN', 'value' => '1.00000000', 'status' => '1' );
$EUR = array( 'currency_id' => '1', 'code' => 'EUR', 'value' => '1.00000000', 'status' => '1' );
$JPY = array( 'currency_id' => '9', 'code' => 'JPY', 'value' => '1.00000000', 'status' => '1' );

// ---- 1. minor units ------------------------------------------------------------------------------
check( 'EUR, PLN, HUF, TRY have 2 decimals', 2 === Currency::decimals( 'EUR' ) && 2 === Currency::decimals( 'pln' ) && 2 === Currency::decimals( 'HUF' ) && 2 === Currency::decimals( 'TRY' ) );
check( 'JPY and ISK have none, KWD three', 0 === Currency::decimals( 'JPY' ) && 0 === Currency::decimals( 'ISK' ) && 3 === Currency::decimals( 'KWD' ) );
check( 'toMinor: 12.50 PLN -> 1250', 1250 === Currency::toMinor( '12.50', 'PLN' ) );
check( 'toMinor: 1500 JPY -> 1500 (not 150000)', 1500 === Currency::toMinor( 1500, 'JPY' ) );
check( 'toMinor: 1.234 KWD -> 1234', 1234 === Currency::toMinor( '1.234', 'KWD' ) );
check( 'toMinor: 19.99 rounds to 1999, not 1998', 1999 === Currency::toMinor( 19.99, 'EUR' ) );
check( 'toMinor: no price stays null', null === Currency::toMinor( '', 'EUR' ) && null === Currency::toMinor( null, 'EUR' ) );
check( 'fromMinor: 1345 PLN -> 13.45, 1500 JPY -> 1500', abs( Currency::fromMinor( 1345, 'PLN' ) - 13.45 ) < 1e-9 && 1500.0 === (float) Currency::fromMinor( 1500, 'JPY' ) );
check( 'format: 1345 PLN, 1500 JPY, 123456 EUR', '13.45 PLN' === Currency::format( 1345, 'PLN' ) && '1500 JPY' === Currency::format( 1500, 'JPY' ) && '1234.56 EUR' === Currency::format( 123456, 'EUR' ) );
check( 'supported shop currencies (contract 4c)', Currency::isSupported( 'PLN' ) && Currency::isSupported( 'try' ) && Currency::isSupported( 'EUR' ) && ! Currency::isSupported( 'USD' ) && ! Currency::isSupported( 'JPY' ) && ! Currency::isSupported( null ) );
check( 'normalise refuses what is not a code', null === Currency::normalise( 'euro' ) && null === Currency::normalise( "EU'R" ) && 'EUR' === Currency::normalise( ' eur ' ) );

// ---- 2. which orders are declined ----------------------------------------------------------------
check( 'refusal: same currency, enabled -> none', null === \Sudemi\Orders::currencyRefusal( 'PLN', 'PLN', $PLN ) );
check( 'refusal: other currency than the shop default', 'this shop sells in PLN, not in EUR' === \Sudemi\Orders::currencyRefusal( 'EUR', 'PLN', $EUR ) );
check( 'refusal: currency switched off in the shop', 'this shop cannot take payments in PLN right now' === \Sudemi\Orders::currencyRefusal( 'PLN', 'PLN', array( 'status' => '0' ) + $PLN ) );
check( 'refusal: currency not in the shop at all', null !== \Sudemi\Orders::currencyRefusal( 'PLN', 'PLN', null ) );
check( 'refusal: no usable code', null !== \Sudemi\Orders::currencyRefusal( '', 'PLN', $PLN ) );

list( $orders, $db, $client, $logger ) = shop( 'PLN', array( 'PLN' => $PLN, 'EUR' => $EUR ) );
$orders->handlePending( array( order( 'EUR', 850, 495 ) ) );
check( 'EUR order to a PLN shop: declined with the reason, nothing booked, stock untouched',
	array( 'POST /integration/v1/orders/' . O1 . '/reject' ) === paths( $client )
	&& 'this shop sells in PLN, not in EUR' === $client->calls[0][2]['reason'] && array() === $db->writes );
check( 'the decline is in the extension log', logged( $logger, 'this shop sells in PLN, not in EUR' ) );

list( $orders, $db, $client ) = shop( 'PLN', array( 'PLN' => array( 'status' => '0' ) + $PLN ) );
$orders->handlePending( array( order( 'PLN', 850, 495 ) ) );
check( 'PLN order while PLN is switched off: declined, nothing booked',
	array( 'POST /integration/v1/orders/' . O1 . '/reject' ) === paths( $client ) && array() === $db->writes
	&& false !== strpos( $client->calls[0][2]['reason'], 'cannot take payments in PLN' ) );

// ---- 3. booked in the order's currency -----------------------------------------------------------
list( $orders, $db, $client ) = shop( 'PLN', array( 'PLN' => $PLN ) );
$orders->handlePending( array( order( 'PLN', 850, 495 ) ) );
$insert = $db->inserts( 'order' );
check( 'PLN order: booked once, then /accept sent', 1 === count( $insert ) && array( 'POST /integration/v1/orders/' . O1 . '/accept' ) === paths( $client ) );
check( 'PLN order: currency id 4, code PLN, rate 1 (not currency id 0)',
	false !== strpos( $insert[0], "currency_id = '4'" ) && false !== strpos( $insert[0], "currency_code = 'PLN'" ) && false !== strpos( $insert[0], "currency_value = '1'" ) );
check( 'PLN order: total 21.95 (2 x 8.50 + 4.95)', false !== strpos( $insert[0], "total = '21.95'" ) );
$line = $db->inserts( 'order_product' );
check( 'PLN order: line at 8.50 each, 17.00 in all', 1 === count( $line ) && false !== strpos( $line[0], "price = '8.5'" ) && false !== strpos( $line[0], "total = '17'" ) );
$totals = implode( "\n", $db->inserts( 'order_total' ) );
check( 'PLN order: shipping row 4.95, total row 21.95', false !== strpos( $totals, "code = 'shipping'" ) && false !== strpos( $totals, "value = '4.95'" ) && false !== strpos( $totals, "value = '21.95'" ) );

list( $orders, $db, $client ) = shop( 'JPY', array( 'JPY' => $JPY ) );
$orders->handlePending( array( order( 'JPY', 1500, 800, 1 ) ) );
$insert = $db->inserts( 'order' );
check( 'JPY order: total 2300 yen, not 23', 1 === count( $insert ) && false !== strpos( $insert[0], "total = '2300'" ) && false !== strpos( $insert[0], "currency_id = '9'" ) );

list( $orders, $db, $client ) = shop( 'PLN', array( 'PLN' => array( 'value' => '2.00000000' ) + $PLN ) );
$orders->handlePending( array( order( 'PLN', 850, 495 ) ) );
$insert = $db->inserts( 'order' );
check( 'a rate other than 1 on the default currency: stored so the order page shows what was paid',
	false !== strpos( $insert[0], "currency_value = '2'" ) && false !== strpos( $insert[0], "total = '10.975'" ) );

list( $orders, $db, $client ) = shop( 'EUR', array( 'EUR' => $EUR ) );
$orders->handlePending( array( order( null, 850, 495 ) ) );
$insert = $db->inserts( 'order' );
check( 'order from a core without currency_code: booked in EUR as before',
	1 === count( $insert ) && false !== strpos( $insert[0], "currency_code = 'EUR'" ) && false !== strpos( $insert[0], "currency_id = '1'" ) );

// ---- 4. refunds ----------------------------------------------------------------------------------
list( $orders, $db, $client ) = shop( 'PLN', array( 'PLN' => $PLN ) );
$db->booked      = 'PLN';
$client->answers = array( array( 'status' => 200, 'body' => array( 'fullyRefunded' => true ) ) );
$ok              = $orders->refund( O1, null, 'Refunded by the shop' );
check( 'refund: carries the order currency PLN',
	true === $ok && array( 'POST /integration/v1/orders/' . O1 . '/refund' ) === paths( $client )
	&& 'PLN' === $client->calls[0][2]['currency'] && ! isset( $client->calls[0][2]['amountMinor'] ) );
list( $orders, $db, $client, $logger ) = shop( 'PLN', array( 'PLN' => $PLN ) );
$db->booked = 'PLN';
$ok         = $orders->refund( O1, 500, 'Refunded by the shop', 'EUR' );
check( 'refund in EUR on a PLN order: refused, nothing sent', false === $ok && array() === $client->calls );
check( 'the refused refund is in the extension log', logged( $logger, 'the buyer paid in PLN' ) );
list( $orders, $db, $client ) = shop( 'PLN', array( 'PLN' => $PLN ) );
$db->booked      = 'PLN';
$client->answers = array( array( 'status' => 200, 'body' => array() ) );
$orders->refund( O1, 500, 'Partly', 'pln' );
check( 'partial refund in the same currency: amount and currency sent', 500 === $client->calls[0][2]['amountMinor'] && 'PLN' === $client->calls[0][2]['currency'] );
list( $orders, $db, $client ) = shop( 'PLN', array( 'PLN' => $PLN ) );
$client->answers = array( array( 'status' => 200, 'body' => array() ) );
$orders->refund( O1, null, 'Refunded by the shop' );
check( 'refund with no local order found: sent without a currency, as before', 1 === count( $client->calls ) && ! isset( $client->calls[0][2]['currency'] ) );

// ---- 5. the shop currency sudemi sees ------------------------------------------------------------
class ShopOnly {
	public $currency;
	public function shop() {
		return array( 'displayName' => 'Shop', 'currency' => $this->currency );
	}
}
$store             = new ArrayStore();
$client            = new FakeClient( $store );
$logger            = new \Sudemi\Logger( $store );
$catalogue         = new ShopOnly();
$catalogue->currency = 'PLN';
$sync              = new \Sudemi\Sync( $client, $logger, $catalogue, $store );
$send              = new \ReflectionMethod( $sync, 'sendShop' );
$send->setAccessible( true );
$client->answers   = array( array( 'status' => 200, 'body' => array( 'status' => 'ok', 'currency' => 'PLN' ) ) );
$send->invoke( $sync );
check( 'POST /shop answered PLN: remembered for the settings page', 'PLN' === $store->read( Currency::SETTING_SHOP_CURRENCY ) && null === $store->read( Currency::SETTING_REFUSED ) );
$catalogue->currency = 'USD';
$client->answers     = array( array( 'status' => 400, 'body' => array( 'error' => 'bad_request', 'message' => 'currency USD is not supported for shops' ) ) );
$send->invoke( $sync );
check( 'POST /shop refused USD: noted and logged, the last confirmed currency kept',
	'USD' === $store->read( Currency::SETTING_REFUSED ) && 'PLN' === $store->read( Currency::SETTING_SHOP_CURRENCY ) && logged( $logger, 'USD' ) );
$catalogue->currency = 'PLN';
$client->answers     = array( array( 'status' => 200, 'body' => array( 'status' => 'ok', 'currency' => 'PLN' ) ) );
$send->invoke( $sync );
check( 'accepted again: the refusal note is cleared', null === $store->read( Currency::SETTING_REFUSED ) );

// ---- 6. texts in all 12 languages -----------------------------------------------------------------
$keys  = array( 'entry_currency', 'text_currency_seen', 'text_currency_fee', 'text_currency_unsupported', 'text_currency_changed', 'column_paid' );
$files = glob( __DIR__ . '/../admin/language/*/module/sudemi.php' );
check( '12 admin languages', 12 === count( $files ) );
foreach ( $files as $file ) {
	$code = basename( dirname( dirname( $file ) ) );
	$_    = array();
	include $file;
	$all = true;
	foreach ( $keys as $key ) {
		$all = $all && isset( $_[ $key ] ) && '' !== $_[ $key ] && false === strpos( $_[ $key ], "\u{2014}" );
	}
	check( "currency texts in $code", $all );
	// The texts filled in with sprintf take exactly the arguments the page passes.
	check( "currency texts in $code format cleanly",
		false !== strpos( sprintf( $_['text_currency_seen'], 'PLN' ), 'PLN' )
		&& false !== strpos( sprintf( $_['text_currency_unsupported'], 'USD', 'EUR, PLN' ), 'EUR, PLN' )
		&& false !== strpos( sprintf( $_['text_currency_changed'], 'PLN', 'EUR' ), 'EUR' ) );
}

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
