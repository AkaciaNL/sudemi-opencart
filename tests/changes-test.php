<?php
/**
 * The order change feed and the 1.3.0 helpers (runs without OpenCart).
 *
 * 1. Client::query encodes the query string as it is sent and signed (RFC 3986), skipping empties.
 * 2. Orders::pendingFromDetail turns sudemi's order page into the heartbeat's pending shape, so an
 *    order accepted by a person is booked like one accepted automatically.
 * 3. changeFromDetail / changeFromRow give change-feed entries (an open request only; none: null).
 * 4. Changes keeps the cursor per install ('' after re-pairing), applies every entry, reports
 *    changes_more, and stores nothing from a core without the feed.
 */

declare(strict_types=1);

define( 'DB_PREFIX', 'oc_' );
foreach ( array( 'store', 'logger', 'keys', 'client', 'queue', 'orders', 'changes' ) as $file ) {
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
	public $install = 'inst-1';
	public $calls   = array();
	public function __construct( $store ) {
		parent::__construct( new FakeKeys(), $store, new \Sudemi\Logger( $store ) );
	}
	public function installId() {
		return $this->install;
	}
	public function sendSigned( $method, $path, array $body = array(), array $headers = array() ) {
		$this->calls[] = array( $method, $path );
		return null;
	}
}

/** Records applyChange calls; "applies" any entry with a revision above 1. */
class FakeOrders extends \Sudemi\Orders {
	public $applied = array();
	public function __construct() {}
	public function applyChange( array $entry, $detail = null ) {
		$this->applied[] = $entry;
		return isset( $entry['revision'] ) && $entry['revision'] > 1;
	}
}

// ---- 1. query strings --------------------------------------------------------------------------
check( 'query: empty values skipped, RFC 3986 encoding',
	'?status=PAID&q=copper%20kettle&from=2026-11-01' === \Sudemi\Client::query( array( 'status' => 'PAID', 'to' => '', 'q' => 'copper kettle', 'from' => '2026-11-01', 'x' => null ) ) );
check( 'query: nothing gives an empty string', '' === \Sudemi\Client::query( array( 'a' => '' ) ) );

// ---- 2. an order page as a pending order ---------------------------------------------------------
$detail = array(
	'order_id' => '0f6c1c3e-0000-4000-8000-000000000001', 'status' => 'AWAITING_SELLER', 'destination_country' => 'DE',
	'lines' => array( array( 'product_id' => 'p', 'external_id' => '42', 'title' => 'Kettle', 'quantity' => 2, 'unit_price_minor' => 1500, 'goods_minor' => 3000 ) ),
	'money' => array( 'currency_code' => 'EUR', 'goods_minor' => 3000, 'shipping_minor' => 695, 'total_minor' => 3695, 'refunded_minor' => 300 ),
	'tracking' => array( 'carrier' => 'DHL', 'code' => 'JJD1' ), 'revision' => 4,
	'customer_request' => array( 'id' => 'r', 'kind' => 'RETURN', 'reason' => 'x' ),
);
$pending = \Sudemi\Orders::pendingFromDetail( $detail );
check( 'pendingFromDetail: totals, currency, country and lines as the heartbeat sends them',
	3695 === $pending['total_minor'] && 695 === $pending['shipping_minor'] && 'EUR' === $pending['currency_code']
	&& 'DE' === $pending['destination_country'] && '42' === $pending['lines'][0]['external_id']
	&& 2 === $pending['lines'][0]['quantity'] && 1500 === $pending['lines'][0]['unit_price_minor'] );
check( 'pendingFromDetail: what the buyer paid can be read (paidAmounts)',
	array( 'shipping' => 695, 'lines' => array( 1500 ), 'total' => 3695 ) === \Sudemi\Orders::paidAmounts( $pending ) );

// ---- 3. change entries ---------------------------------------------------------------------------
$entry = \Sudemi\Orders::changeFromDetail( $detail );
check( 'changeFromDetail: status, revision, refunded, tracking, the open request',
	'AWAITING_SELLER' === $entry['status'] && 4 === $entry['revision'] && 300 === $entry['refunded_minor']
	&& 'JJD1' === $entry['tracking']['code'] && array( 'kind' => 'RETURN', 'status' => 'REQUESTED' ) === $entry['request'] );
unset( $detail['customer_request'] );
check( 'changeFromDetail: no open request gives null (the stored one stays)', null === \Sudemi\Orders::changeFromDetail( $detail )['request'] );
$row = \Sudemi\Orders::changeFromRow( array( 'order_id' => 'o', 'status' => 'SHIPPED', 'revision' => 7, 'refunded_minor' => 0, 'tracking' => null ) );
check( 'changeFromRow: a list row as an entry', 'SHIPPED' === $row['status'] && 7 === $row['revision'] && null === $row['tracking'] && null === $row['request'] );

// ---- 4. the feed ---------------------------------------------------------------------------------
$store   = new ArrayStore();
$client  = new FakeClient( $store );
$orders  = new FakeOrders();
$changes = new \Sudemi\Changes( $store, $client, new \Sudemi\Logger( $store ), $orders );
check( 'nothing stored: the cursor sent is "" (start)', '' === $changes->cursor() );
check( 'a core without the feed: nothing applied, nothing stored, no more',
	false === $changes->apply( array( 'status' => 'ok' ) ) && array() === $orders->applied && null === $store->read( \Sudemi\Changes::SETTING_CURSOR ) );
$more = $changes->apply( array( 'changed_orders' => array( array( 'order_id' => 'a', 'revision' => 2 ), 'junk', array( 'order_id' => 'b', 'revision' => 1 ) ),
	'changes_cursor' => 'c1.AAA', 'changes_more' => true ) );
check( 'entries applied in order (junk skipped), changes_more reported', true === $more && 2 === count( $orders->applied )
	&& 'a' === $orders->applied[0]['order_id'] && 'b' === $orders->applied[1]['order_id'] );
check( 'the cursor is kept for this install', 'c1.AAA' === $changes->cursor() );
check( 'an empty list keeps the feed going (no more)', false === $changes->apply( array( 'changed_orders' => array(), 'changes_cursor' => 'c1.BBB' ) )
	&& 'c1.BBB' === $changes->cursor() );
$client->install = 'inst-2';
check( 'after re-pairing (another install) the cursor starts again with ""', '' === $changes->cursor() );
check( 'the safety net does not run without a cursor (an older core is not asked)', 0 === $changes->safetyNet( true ) && array() === $client->calls );
$client->install = 'inst-1';
$changes->safetyNet( true );
check( 'the safety net asks for the orders since yesterday', 1 === count( $client->calls ) && 'GET' === $client->calls[0][0]
	&& 0 === strpos( $client->calls[0][1], '/integration/v1/orders?from=' . gmdate( 'Y-m-d', time() - 86400 ) ) );
$changes->forget();
check( 'forget(): the cursor is gone', '' === $changes->cursor() );

if ( $failures > 0 ) {
	echo "\n$failures check(s) failed\n";
	exit( 1 );
}
echo "\nall change-feed checks passed\n";
