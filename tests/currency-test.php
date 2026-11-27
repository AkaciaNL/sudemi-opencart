<?php
/**
 * Currency step 3 (1.2.12).
 *
 * 1. Minor units follow ISO 4217 (JPY none, KWD three, EUR/PLN/HUF two), never a fixed x 100.
 * 2. An order in another currency than the shop's default is booked in that currency when the
 *    shop has it (a shop that changed its default currency, owner 2026-11-27). One in a currency
 *    the shop does not have, or has switched off, is neither booked nor declined: it waits, and a
 *    shop-wide order_currency_unavailable issue tells the shop and sudemi how to fix it.
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
	public function sendSigned( $method, $path, array $body = array(), array $headers = array() ) {
		$this->calls[] = array( $method, $path, $body, $headers );
		return 'GET' === $method ? null : array_shift( $this->answers );
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
	/** The order claim (review 2026-11-12) is tested in claims-test.php; here every caller wins it. */
	public function countAffected() {
		return 1;
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
		if ( preg_match( "/^SELECT p.product_id, p.quantity, p.subtract, p.status, pd.name FROM oc_product p .* WHERE p.product_id = (\d+)$/", $sql, $m ) ) {
			return $this->rows( array( array( 'product_id' => (int) $m[1], 'quantity' => 10, 'status' => 1, 'subtract' => 1, 'name' => 'Mug' ) ) );
		}
		if ( 0 === strpos( $sql, 'SELECT po.product_option_id' ) ) {
			return $this->rows( array() ); // a product without options
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
	// These cases are about answering orders automatically: switched on, as a shop does (off by default since 1.4.5).
	$orders->setAutoConfirm( true );
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
check( 'supported shop currencies (contract 4c)', Currency::isSupported( 'PLN' ) && Currency::isSupported( 'try' ) && Currency::isSupported( 'EUR' ) && Currency::isSupported( 'JPY' ) && Currency::isSupported( 'USD' ) && ! Currency::isSupported( 'ISK' ) && ! Currency::isSupported( null ) );
check( 'normalise refuses what is not a code', null === Currency::normalise( 'euro' ) && null === Currency::normalise( "EU'R" ) && 'EUR' === Currency::normalise( ' eur ' ) );

// ---- 2. which orders cannot be booked (owner 2026-11-27) -------------------------------------------
check( 'refusal: same currency, enabled -> none', null === \Sudemi\Orders::currencyRefusal( 'PLN', 'PLN', $PLN ) );
check( 'refusal: another currency than the shop default, which the shop has -> none (booked in it)',
	null === \Sudemi\Orders::currencyRefusal( 'EUR', 'PLN', $EUR ) );
check( 'refusal: currency switched off in the shop', 'the order was paid in PLN and the PLN currency is switched off in this shop' === \Sudemi\Orders::currencyRefusal( 'PLN', 'PLN', array( 'status' => '0' ) + $PLN ) );
check( 'refusal: currency not in the shop at all', 'the order was paid in GBP and this shop has no GBP currency' === \Sudemi\Orders::currencyRefusal( 'GBP', 'EUR', null ) );
check( 'refusal: a currency row without a rate', null !== \Sudemi\Orders::currencyRefusal( 'PLN', 'EUR', array( 'value' => '0' ) + $PLN ) );
check( 'refusal: no usable code', null !== \Sudemi\Orders::currencyRefusal( '', 'PLN', $PLN ) );

// The owner's case (prod 2026-11-27): the shop switched its default from USD to EUR while a USD
// order waited. It is booked in USD at the USD row's rate; the amounts shown are what was paid.
$USD = array( 'currency_id' => '2', 'code' => 'USD', 'value' => '1.08000000', 'status' => '1' );
list( $orders, $db, $client, $logger ) = shop( 'EUR', array( 'EUR' => $EUR, 'USD' => $USD ) );
$orders->handlePending( array( order( 'USD', 1999, 495, 1 ) ) );
$insert = $db->inserts( 'order' );
check( 'USD order after the default became EUR: booked once, then /accept sent (nothing declined)',
	1 === count( $insert ) && array( 'POST /integration/v1/orders/' . O1 . '/accept' ) === paths( $client ) );
check( 'USD order: currency id 2, code USD, rate 1.08 of the USD row',
	false !== strpos( $insert[0], "currency_id = '2'" ) && false !== strpos( $insert[0], "currency_code = 'USD'" ) && false !== strpos( $insert[0], "currency_value = '1.08'" ) );
$m = array();
preg_match( "/ total = '([0-9.]+)'/", $insert[0], $m );
check( 'USD order: the order page shows exactly 24.94 USD (stored / rate, as OpenCart does)',
	isset( $m[1] ) && '24.94' === number_format( round( round( (float) $m[1], 4 ) * 1.08, 2 ), 2, '.', '' ) );
$totals = $db->inserts( 'order_total' );
$shown  = array();
foreach ( $totals as $row ) {
	preg_match( "/code = '([a-z_]+)'.* value = '(-?[0-9.E-]+)'/", $row, $m );
	$shown[ $m[1] ] = number_format( round( round( (float) $m[2], 4 ) * 1.08, 2 ), 2, '.', '' );
}
check( 'USD order: shipping 4.95 and total 24.94 shown in USD', '4.95' === $shown['shipping'] && '24.94' === $shown['total'] );
check( 'USD order: nothing about a currency in the log', ! logged( $logger, 'currency' ) );

// A currency the shop does not have: nothing booked, nothing declined; the order waits and the
// shop and sudemi are told how to fix it.
list( $orders, $db, $client, $logger ) = shop( 'EUR', array( 'EUR' => $EUR ) );
$waiting = array( 'respond_by' => '2026-11-29T10:00:00Z' ) + order( 'GBP', 850, 495 );
$orders->handlePending( array( $waiting ) );
check( 'GBP order to a shop without GBP: not declined, nothing booked, stock untouched',
	array() === paths( $client ) && array() === $db->writes );
check( 'the wait and the fix are in the extension log',
	logged( $logger, 'this shop has no GBP currency' ) && logged( $logger, 'System > Localisation > Currencies' ) );
$client->answers = array( array( 'status' => 200, 'body' => array() ) );
check( 'reported to sudemi', true === $orders->reportCurrencyProblems( array( $waiting ) ) );
$sent = $client->calls[0];
$issue = $sent[2]['issues'][0];
check( 'as a shop-wide order_currency_unavailable issue on the products push (no products)',
	'POST /integration/v1/products' === $sent[0] . ' ' . $sent[1] && array() === $sent[2]['products']
	&& 'order_currency_unavailable' === $issue['code'] && '' === $issue['externalId'] && 'error' === $issue['severity'] && 'currency' === $issue['field'] );
$details = (array) $issue['details'];
check( 'the issue names the currency, the order, its deadline and the fix',
	array( 'GBP' ) === $details['currencies'] && O1 === $details['orders'][0]['orderId'] && '2026-11-29T10:00:00Z' === $details['orders'][0]['respondBy']
	&& false !== strpos( $issue['message'], 'this shop has no GBP currency' ) && false !== strpos( $issue['message'], 'decline the order' )
	&& false !== strpos( $issue['message'], '11111111' ) );

list( $orders, $db, $client ) = shop( 'EUR', array( 'EUR' => $EUR, 'USD' => $USD ) );
check( 'nothing reported when every waiting order can be booked',
	false === $orders->reportCurrencyProblems( array( order( 'USD', 850, 495 ), order( 'EUR', 850, 495 ) ) ) && array() === $client->calls );
check( 'nothing reported for an empty list', false === $orders->reportCurrencyProblems( array() ) && array() === $client->calls );
check( 'currencyIssue: none for none', null === \Sudemi\Orders::currencyIssue( array() ) );
$two = \Sudemi\Orders::currencyIssue( array(
	array( 'order_id' => 'b', 'currency' => 'GBP', 'respond_by' => '2026-11-30T00:00:00Z', 'reason' => 'r2' ),
	array( 'order_id' => 'a', 'currency' => 'CHF', 'respond_by' => '2026-11-29T00:00:00Z', 'reason' => 'r1' ),
) );
check( 'currencyIssue: soonest deadline first, both currencies, count in the message',
	'a' === $two['details']->orders[0]['orderId'] && array( 'CHF', 'GBP' ) === $two['details']->currencies
	&& 0 === strpos( $two['message'], '2 waiting order(s)' ) && false !== strpos( $two['message'], 'r1' ) );

// Accept by hand (the sudemi Orders page): the same rule, and the answer says what to do.
function detail( $currency ) {
	return array( 'order_id' => O1, 'status' => 'AWAITING_SELLER', 'destination_country' => 'DE',
		'money' => array( 'total_minor' => 1345, 'shipping_minor' => 495, 'currency_code' => $currency ),
		'lines' => array( array( 'external_id' => '7', 'title' => 'Mug', 'quantity' => 1, 'unit_price_minor' => 850 ) ) );
}
list( $orders, $db, $client ) = shop( 'EUR', array( 'EUR' => $EUR, 'USD' => $USD ) );
$ready = $orders->prepareAccept( O1, detail( 'USD' ) );
check( 'Accept by hand, USD order in a shop now on EUR: booked in USD', true === $ready['ok'] && 1 === count( $db->inserts( 'order' ) )
	&& false !== strpos( $db->inserts( 'order' )[0], "currency_code = 'USD'" ) );
list( $orders, $db, $client ) = shop( 'EUR', array( 'EUR' => $EUR ) );
$ready = $orders->prepareAccept( O1, detail( 'GBP' ) );
check( 'Accept by hand, GBP order in a shop without GBP: not booked, nothing sent, the reason says how to fix it',
	false === $ready['ok'] && 'shortfall' === $ready['error'] && array() === $db->writes && array() === $client->calls
	&& false !== strpos( $ready['reason'], 'this shop has no GBP currency' ) && false !== strpos( $ready['reason'], 'System > Localisation > Currencies' ) );

list( $orders, $db, $client ) = shop( 'PLN', array( 'PLN' => array( 'status' => '0' ) + $PLN ) );
$orders->handlePending( array( order( 'PLN', 850, 495 ) ) );
check( 'PLN order while PLN is switched off: waits, nothing booked, nothing declined',
	array() === paths( $client ) && array() === $db->writes );

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
$catalogue->currency = 'ISK';
$client->answers     = array( array( 'status' => 400, 'body' => array( 'error' => 'bad_request', 'message' => 'currency ISK is not supported for shops' ) ) );
$send->invoke( $sync );
check( 'POST /shop refused ISK: noted and logged, the last confirmed currency kept',
	'ISK' === $store->read( Currency::SETTING_REFUSED ) && 'PLN' === $store->read( Currency::SETTING_SHOP_CURRENCY ) && logged( $logger, 'ISK' ) );
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
		&& false !== strpos( sprintf( $_['text_currency_unsupported'], 'ISK', 'EUR, PLN' ), 'EUR, PLN' )
		&& false !== strpos( sprintf( $_['text_currency_changed'], 'PLN', 'EUR' ), 'EUR' ) );
}

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
