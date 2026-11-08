<?php
/**
 * Order safety (1.2.6).
 *
 * 1. acceptOutcome / rejectOutcome: only a definitive answer decides; 5xx, 429, 401, 403, every
 *    409 and an already_resolved without a known order_status are 'retry'.
 * 2. An accept that is not answered never voids the order or restocks; already_resolved PAID
 *    confirms it; DECLINED releases it once.
 * 3. A voided or merchant-cancelled local order is answered with /reject, never accepted again.
 * 4. Reconcile re-asks unconfirmed local orders (reject after three days).
 * 5. Shipped/delivered that do not get through are queued and retried; a cancellation in the shop
 *    sends /reject, and /cancel only when sudemi says the order is paid.
 * 6. sudemiIdOf / idFromComment returns only the uuid; carrierName decodes OpenCart 4's JSON.
 * 7. The price queue keeps a newer price queued while the older one was in flight, and drops a
 *    batch sudemi refuses outright (the change counter moves).
 *
 * Runs without OpenCart: the database, the config and sudemi are faked here.
 */

declare(strict_types=1);

define( 'DB_PREFIX', 'oc_' );
foreach ( array( 'store', 'logger', 'keys', 'client', 'queue', 'orders' ) as $file ) {
	require_once __DIR__ . '/../system/library/sudemi/' . $file . '.php';
}

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
	public function sendSigned( $method, $path, array $body = array() ) {
		$this->calls[] = array( $method, $path, $body );
		if ( 'GET' === $method ) {
			return array( 'status' => 409, 'body' => array( 'error' => 'not_confirmed_yet' ) );
		}
		return array_shift( $this->answers );
	}
	public function postSigned( $path, array $body ) {
		return $this->sendSigned( 'POST', $path, $body );
	}
	public function getSigned( $path ) {
		return $this->sendSigned( 'GET', $path );
	}
	public function paths() {
		return array_map( function ( $c ) {
			return $c[0] . ' ' . $c[1];
		}, $this->calls );
	}
}

class FakeDb {
	public $orders   = array();
	public $lines    = array();
	public $products = array();
	public function escape( $value ) {
		return addslashes( (string) $value );
	}
	public function getLastId() {
		return 0;
	}
	public function query( $sql ) {
		$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );
		$m   = array();
		if ( preg_match( "/^SELECT order_id, order_status_id, comment FROM oc_order WHERE comment IN \('(.*)', '(.*)'\) ORDER BY order_id LIMIT 1$/", $sql, $m ) ) {
			foreach ( $this->orders as $id => $o ) {
				if ( in_array( $o['comment'], array( stripslashes( $m[1] ), stripslashes( $m[2] ) ), true ) ) {
					return $this->rows( array( array( 'order_id' => $id, 'order_status_id' => $o['status'], 'comment' => $o['comment'] ) ) );
				}
			}
			return $this->rows( array() );
		}
		if ( preg_match( "/^SELECT order_id, order_status_id, comment FROM oc_order WHERE order_id = '(\d+)' LIMIT 1$/", $sql, $m ) ) {
			$o = isset( $this->orders[ $m[1] ] ) ? $this->orders[ $m[1] ] : null;
			return $this->rows( $o ? array( array( 'order_id' => (int) $m[1], 'order_status_id' => $o['status'], 'comment' => $o['comment'] ) ) : array() );
		}
		if ( preg_match( "/^SELECT order_id, comment, TIMESTAMPDIFF/", $sql ) ) {
			$rows = array();
			foreach ( $this->orders as $id => $o ) {
				if ( 0 === $o['status'] && 0 === strpos( $o['comment'], 'sudemi:' ) ) {
					$rows[] = array( 'order_id' => $id, 'comment' => $o['comment'], 'age' => $o['age'] );
				}
			}
			return $this->rows( $rows );
		}
		if ( preg_match( "/^SELECT shipping_method FROM oc_order WHERE order_id = '(\d+)' LIMIT 1$/", $sql, $m ) ) {
			return $this->rows( array( array( 'shipping_method' => $this->orders[ $m[1] ]['shipping'] ?? '' ) ) );
		}
		if ( preg_match( "/^SELECT product_id, quantity FROM oc_order_product WHERE order_id = '(\d+)'$/", $sql, $m ) ) {
			return $this->rows( isset( $this->lines[ $m[1] ] ) ? $this->lines[ $m[1] ] : array() );
		}
		if ( preg_match( "/^UPDATE oc_product SET quantity = quantity \+ '(\d+)' WHERE product_id = '(\d+)' AND subtract = '1'$/", $sql, $m ) ) {
			$this->products[ $m[2] ] += (int) $m[1];
			return true;
		}
		if ( preg_match( "/^UPDATE oc_order SET order_status_id = '(\d+)', date_modified = NOW\(\) WHERE order_id = '(\d+)'$/", $sql, $m ) ) {
			$this->orders[ $m[2] ]['status'] = (int) $m[1];
			return true;
		}
		if ( preg_match( "/^UPDATE oc_order SET comment = CONCAT\(comment, ' \(released\)'\), date_modified = NOW\(\) WHERE order_id = '(\d+)'$/", $sql, $m ) ) {
			$this->orders[ $m[1] ]['comment'] .= ' (released)';
			return true;
		}
		throw new \RuntimeException( 'unexpected query: ' . $sql );
	}
	private function rows( array $rows ) {
		return (object) array( 'num_rows' => count( $rows ), 'row' => $rows ? $rows[0] : array(), 'rows' => $rows );
	}
}

class FakeConfig {
	public function get( $key ) {
		return array( 'config_order_status_id' => 1, 'config_processing_status' => array( '2' ), 'config_complete_status' => array( '5' ) )[ $key ] ?? null;
	}
}

const O1 = '11111111-2222-4333-8444-555555555555';
const O2 = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

/** A shop with local order 42 for O1 (status, comment suffix), 2 units of product 7 reserved (6 left). */
function shop( $status = 0, $suffix = '', $age = 60 ) {
	$db           = new FakeDb();
	$db->orders   = array( 42 => array( 'status' => $status, 'comment' => 'sudemi:' . O1 . $suffix, 'age' => $age, 'shipping' => '{"name":"Flat Shipping Rate","code":"flat.flat"}' ) );
	$db->lines    = array( 42 => array( array( 'product_id' => 7, 'quantity' => 2 ) ) );
	$db->products = array( 7 => 6 );
	$store        = new ArrayStore();
	$client       = new FakeClient( $store );
	$orders       = new \Sudemi\Orders( $db, new FakeConfig(), $client, new \Sudemi\Logger( $store ), $store );
	return array( $orders, $db, $client, $store );
}
function pending( $id = O1 ) {
	return array( array( 'order_id' => $id, 'lines' => array( array( 'external_id' => '7', 'quantity' => 2 ) ) ) );
}
function resolved( $orderStatus ) {
	return array( 'status' => 200, 'body' => array( 'status' => 'already_resolved', 'order_status' => $orderStatus ) );
}

// ---- 1. classification ---------------------------------------------------------------------------
$cases = array(
	'200 ok captured'          => array( array( 'status' => 200, 'body' => array( 'status' => 'ok', 'payment' => 'captured' ) ), 'paid', 'declined' ),
	'200 ok waiting'           => array( array( 'status' => 200, 'body' => array( 'status' => 'ok', 'payment' => 'waiting_for_other_shops' ) ), 'waiting', 'declined' ),
	'already_resolved PAID'    => array( resolved( 'PAID' ), 'paid', 'paid' ),
	'already_resolved SHIPPED' => array( resolved( 'SHIPPED' ), 'paid', 'paid' ),
	'already_resolved ACCEPTED' => array( resolved( 'ACCEPTED' ), 'waiting', 'waiting' ),
	'already_resolved DECLINED' => array( resolved( 'DECLINED' ), 'declined', 'declined' ),
	'already_resolved CANCELLED' => array( resolved( 'CANCELLED' ), 'cancelled', 'cancelled' ),
	'already_resolved REFUNDED' => array( resolved( 'REFUNDED' ), 'cancelled', 'cancelled' ),
	'already_resolved, no order_status' => array( array( 'status' => 200, 'body' => array( 'status' => 'already_resolved' ) ), 'retry', 'retry' ),
	'already_resolved AWAITING_SELLER' => array( resolved( 'AWAITING_SELLER' ), 'retry', 'retry' ),
	'404 no_such_order'        => array( array( 'status' => 404, 'body' => array( 'error' => 'no_such_order' ) ), 'declined', 'declined' ),
	'404 other'                => array( array( 'status' => 404, 'body' => array() ), 'retry', 'retry' ),
	'503'                      => array( array( 'status' => 503, 'body' => array() ), 'retry', 'retry' ),
	'429'                      => array( array( 'status' => 429, 'body' => array( 'error' => 'too_many_requests' ) ), 'retry', 'retry' ),
	'401'                      => array( array( 'status' => 401, 'body' => array( 'error' => 'not_authenticated' ) ), 'retry', 'retry' ),
	'403 suspended'            => array( array( 'status' => 403, 'body' => array( 'error' => 'seller_suspended' ) ), 'retry', 'retry' ),
	'409 site_mismatch'        => array( array( 'status' => 409, 'body' => array( 'error' => 'site_mismatch' ) ), 'retry', 'retry' ),
	'409 authorisation_lapsed' => array( array( 'status' => 409, 'body' => array( 'error' => 'authorisation_lapsed' ) ), 'retry', 'retry' ),
	'unreachable'              => array( null, 'retry', 'retry' ),
);
foreach ( $cases as $what => $case ) {
	check( "acceptOutcome: $what -> {$case[1]}", $case[1] === \Sudemi\Orders::acceptOutcome( $case[0] ) );
	check( "rejectOutcome: $what -> {$case[2]}", $case[2] === \Sudemi\Orders::rejectOutcome( $case[0] ) );
}

// ---- 2. accept answers on an existing unconfirmed order ------------------------------------------
foreach ( array( 'unreachable' => null, '503' => array( 'status' => 503, 'body' => array() ),
	'409 site_mismatch' => array( 'status' => 409, 'body' => array( 'error' => 'site_mismatch' ) ),
	'already_resolved without order_status' => array( 'status' => 200, 'body' => array( 'status' => 'already_resolved' ) ) ) as $what => $answer ) {
	list( $orders, $db, $client ) = shop();
	$client->answers = array( $answer );
	$orders->handlePending( pending() );
	check( "accept $what: order stays unconfirmed, not voided, stock still reserved",
		0 === $db->orders[42]['status'] && 'sudemi:' . O1 === $db->orders[42]['comment'] && 6 === $db->products[7]
		&& array( 'POST /integration/v1/orders/' . O1 . '/accept' ) === $client->paths() );
}
list( $orders, $db, $client ) = shop();
$client->answers = array( resolved( 'PAID' ) );
$orders->handlePending( pending() );
check( 'accept answered already_resolved PAID: order confirmed (status 1), stock untouched',
	1 === $db->orders[42]['status'] && 6 === $db->products[7] );
list( $orders, $db, $client ) = shop();
$client->answers = array( resolved( 'DECLINED' ), resolved( 'DECLINED' ) );
$orders->handlePending( pending() );
check( 'accept answered already_resolved DECLINED: released, stock back once', ' (released)' === substr( $db->orders[42]['comment'], -11 ) && 8 === $db->products[7] );
$orders->handlePending( pending() );
check( 'a released order is not accepted again (reject sent) and not restocked twice',
	'POST /integration/v1/orders/' . O1 . '/reject' === $client->paths()[1] && 8 === $db->products[7] );

// ---- 3. a merchant-cancelled local order is never accepted ----------------------------------------
list( $orders, $db, $client ) = shop( 7 );
$client->answers = array( array( 'status' => 200, 'body' => array( 'status' => 'ok' ) ) );
$orders->handlePending( pending() );
check( 'Canceled locally while pending: /reject sent, no /accept', array( 'POST /integration/v1/orders/' . O1 . '/reject' ) === $client->paths()
	&& 'cancelled in the shop' === $client->calls[0][2]['reason'] );
check( 'Canceled locally, released on sudemi: the reservation is put back once', 8 === $db->products[7] && 7 === $db->orders[42]['status'] );

// ---- 4. reconcile -------------------------------------------------------------------------------
list( $orders, $db, $client ) = shop();
$client->answers = array( resolved( 'PAID' ) );
$orders->reconcile( array() );
check( 'reconcile: an unconfirmed order is re-asked with /accept and confirmed on PAID',
	array( 'POST /integration/v1/orders/' . O1 . '/accept' ) === array_slice( $client->paths(), 0, 1 ) && 1 === $db->orders[42]['status'] );
list( $orders, $db, $client ) = shop();
$orders->reconcile( array( O1 ) );
check( 'reconcile: skips an order this beat already handled', array() === $client->calls );
list( $orders, $db, $client ) = shop( 0, ' (released)' );
$orders->reconcile( array() );
check( 'reconcile: skips a released order', array() === $client->calls );
list( $orders, $db, $client ) = shop( 0, '', 4 * 86400 );
$client->answers = array( resolved( 'DECLINED' ) );
$orders->reconcile( array() );
check( 'reconcile: after three days it asks with /reject and releases on DECLINED',
	array( 'POST /integration/v1/orders/' . O1 . '/reject' ) === $client->paths() && 8 === $db->products[7] );
list( $orders, $db, $client ) = shop();
$client->answers = array( resolved( 'ACCEPTED' ) );
$orders->reconcile( array() );
check( 'reconcile: an accepted order waiting for other shops is left as it is', 0 === $db->orders[42]['status'] && 6 === $db->products[7] );

// ---- 5. shipped/delivered retry and shop cancellation --------------------------------------------
list( $orders, $db, $client, $store ) = shop( 1 );
$client->answers = array( array( 'status' => 503, 'body' => array() ) );
$orders->report( O1, 'shipped', 42 );
$queued = $orders->pendingCalls();
check( 'shipped answered 503: queued for retry, carrier is the shipping method name',
	isset( $queued[ O1 . '|shipped' ] ) && 'Flat Shipping Rate' === $queued[ O1 . '|shipped' ]['body']['carrier'] );
$client->answers = array( array( 'status' => 200, 'body' => array( 'status' => 'ok' ) ) );
$orders->drainCalls();
check( 'the next beat sends it again and clears it', array() === $orders->pendingCalls() && 2 === count( $client->calls ) );
$client->answers = array( array( 'status' => 409, 'body' => array( 'error' => 'not_paid' ) ) );
$orders->report( O1, 'delivered', 42 );
check( 'delivered answered 409 not_paid: kept for retry', isset( $orders->pendingCalls()[ O1 . '|delivered' ] ) );
$client->answers = array( array( 'status' => 404, 'body' => array( 'error' => 'no_such_order' ) ) );
$orders->drainCalls();
check( 'a definitive refusal (404) clears it', array() === $orders->pendingCalls() );
$q                                = $orders->pendingCalls();
$store->values['sudemi_order_calls'] = json_encode( array( O1 . '|shipped' => array( 'order_id' => O1, 'call' => 'shipped', 'body' => array(), 'since' => time() - 15 * 86400 ) ) );
$n = count( $client->calls );
$orders->drainCalls();
check( 'an entry older than 14 days is dropped without a call', array() === $orders->pendingCalls() && $n === count( $client->calls ) );

list( $orders, $db, $client ) = shop( 7 );
$client->answers = array( array( 'status' => 200, 'body' => array( 'status' => 'ok' ) ) );
$orders->cancelFromShop( O1, 42, 'Cancelled by the shop' );
check( 'cancel in the shop of an unpaid order: /reject only, reservation put back',
	array( 'POST /integration/v1/orders/' . O1 . '/reject' ) === $client->paths() && 8 === $db->products[7] );
list( $orders, $db, $client ) = shop( 7 );
$client->answers = array( resolved( 'PAID' ), array( 'status' => 200, 'body' => array( 'status' => 'CANCELLED' ) ) );
$orders->cancelFromShop( O1, 42, 'Cancelled by the shop' );
check( 'cancel in the shop of a paid order: /reject answered PAID, then /cancel (refund); stock left to OpenCart',
	array( 'POST /integration/v1/orders/' . O1 . '/reject', 'POST /integration/v1/orders/' . O1 . '/cancel' ) === $client->paths() && 6 === $db->products[7] );
list( $orders, $db, $client ) = shop( 7 );
$client->answers = array( null );
$orders->cancelFromShop( O1, 42, 'Cancelled by the shop' );
check( 'cancel not answered: queued, nothing restocked', isset( $orders->pendingCalls()[ O1 . '|cancel_shop' ] ) && 6 === $db->products[7] );
$client->answers = array( resolved( 'DECLINED' ) );
$orders->drainCalls();
check( 'the queued cancellation is retried and cleared; reservation back', array() === $orders->pendingCalls() && 8 === $db->products[7] );

// ---- 6. ids and carrier ---------------------------------------------------------------------------
check( 'idFromComment: plain', O1 === \Sudemi\Orders::idFromComment( 'sudemi:' . O1 ) );
check( 'idFromComment: after (released) only the uuid', O1 === \Sudemi\Orders::idFromComment( 'sudemi:' . O1 . ' (released)' ) );
check( 'idFromComment: not ours', null === \Sudemi\Orders::idFromComment( 'Please gift wrap' ) && null === \Sudemi\Orders::idFromComment( 'sudemi:../x' ) );
check( 'carrierName: OpenCart 4 JSON -> name', 'Flat Shipping Rate' === \Sudemi\Orders::carrierName( '{"name":"Flat Shipping Rate","code":"flat.flat"}' ) );
check( 'carrierName: plain string kept, empty -> null', 'PostNL' === \Sudemi\Orders::carrierName( 'PostNL' ) && null === \Sudemi\Orders::carrierName( '' ) );

// ---- 7. price queue --------------------------------------------------------------------------------
class RacingClient extends FakeClient {
	public $queue;
	public function postSigned( $path, array $body ) {
		// A product save queues a newer price for the same product while this request is in flight.
		$this->queue->enqueue( array( 'externalId' => '7', 'priceMinor' => 999 ) );
		return parent::postSigned( $path, $body );
	}
}
$store         = new ArrayStore();
$client        = new RacingClient( $store );
$queue         = new \Sudemi\Queue( $store, $client );
$client->queue = $queue;
$queue->enqueue( array( 'externalId' => '7', 'priceMinor' => 500 ) );
$client->answers = array( array( 'status' => 200, 'body' => array( 'applied' => 1 ) ) );
$queue->drain();
check( 'queue: a newer price queued during the send stays queued', 999 === ( $queue->all()['7']['priceMinor'] ?? null ) );
$store  = new ArrayStore();
$client = new FakeClient( $store );
$queue  = new \Sudemi\Queue( $store, $client, new \Sudemi\Logger( $store ) );
$queue->enqueue( array( 'externalId' => '8', 'priceMinor' => 500 ) );
$client->answers = array( array( 'status' => 422, 'body' => array( 'error' => 'rejected' ) ) );
$queue->drain();
check( 'queue: a batch refused outright (422) is dropped and the change counter moves',
	0 === $queue->count() && 1 === (int) $store->read( 'sudemi_change_counter' ) );
$queue->enqueue( array( 'externalId' => '8', 'priceMinor' => 500 ) );
$client->answers = array( array( 'status' => 503, 'body' => array() ) );
$queue->drain();
check( 'queue: a 503 keeps it', 1 === $queue->count() );

// ---- 8. the client test seam -----------------------------------------------------------------------
$store  = new ArrayStore();
$store->values = array( 'sudemi_install_id' => 'inst-1' );
$real   = new \Sudemi\Client( new FakeKeys(), $store, new \Sudemi\Logger( $store ) );
\Sudemi\Client::$interceptor = function ( $method, $path, $body, $send ) {
	return array( 'status' => 503, 'body' => array( 'seen' => $method . ' ' . $path ) );
};
$r = $real->postSigned( '/integration/v1/orders/x/accept', array() );
\Sudemi\Client::$interceptor = null;
check( 'interceptor: its answer is used', 503 === $r['status'] && 'POST /integration/v1/orders/x/accept' === $r['body']['seen'] );

// ---- 8. what the buyer paid (1.2.10) -------------------------------------------------------------
$mug = array(
	'total_minor'    => 1345,
	'shipping_minor' => 495,
	'currency_code'  => 'EUR',
	'lines'          => array( array( 'external_id' => '14', 'quantity' => 1, 'unit_price_minor' => 850 ) ),
);
check( 'paid amounts read', array( 'shipping' => 495, 'lines' => array( 0 => 850 ), 'total' => 1345 ) === \Sudemi\Orders::paidAmounts( $mug ) );
$older = $mug;
unset( $older['shipping_minor'] );
check( 'older core without shipping: written as before', null === \Sudemi\Orders::paidAmounts( $older ) );
$older = $mug;
unset( $older['lines'][0]['unit_price_minor'] );
check( 'older core without line prices: written as before', null === \Sudemi\Orders::paidAmounts( $older ) );
$split = \Sudemi\Orders::splitTax( 850, array( 7 => 21.0 ) );
check( 'VAT split out of 8.50 at 21%: 7.02 + 1.48', 702 === $split['net'] && array( 7 => 148 ) === $split['taxes'] );
$split = \Sudemi\Orders::splitTax( 1000, array( 1 => 10.0, 2 => 5.0 ) );
check( 'two rates add up to the gross exactly', 1000 === $split['net'] + array_sum( $split['taxes'] ) );
check( 'no rates: the gross is the net', array( 'net' => 850, 'taxes' => array() ) === \Sudemi\Orders::splitTax( 850, array() ) );
$texts = \Sudemi\Orders::orderTexts( 'nl-nl' );
check( 'order texts in Dutch', 'Verzending via sudemi' === $texts['text_order_shipping'] && 'Totaal' === $texts['text_order_total'] );
check( 'unknown language falls back to English', 'Sub-Total' === \Sudemi\Orders::orderTexts( 'xx-xx' )['text_order_sub_total'] );
check( 'a path in the language code is refused', 'Total' === \Sudemi\Orders::orderTexts( '../../x' )['text_order_total'] );
foreach ( glob( __DIR__ . '/../admin/language/*/module/sudemi.php' ) as $file ) {
	$code = basename( dirname( dirname( $file ) ) );
	$_    = array();
	include $file;
	check( "order texts present in $code", isset( $_['text_order_sub_total'], $_['text_order_shipping'], $_['text_order_total'] ) );
}

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
