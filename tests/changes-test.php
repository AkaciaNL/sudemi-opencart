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
 * 5. An entry that fails (any Throwable) goes on a retry list; the cursor still moves on. Later beats
 *    read those orders from their order pages, a limited number per beat, and give up loudly after
 *    RETRY_MAX attempts or RETRY_DAYS days.
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
	/** order id => what applyChange throws for it (ApplyLater, or anything else) */
	public $failing = array();
	/** order id => its order page (null: not readable) */
	public $pages = array();
	public $read  = array();
	public function __construct() {}
	public function applyChange( array $entry, $detail = null ) {
		$this->applied[] = $entry;
		$id = isset( $entry['order_id'] ) ? (string) $entry['order_id'] : '';
		if ( isset( $this->failing[ $id ] ) ) {
			throw $this->failing[ $id ];
		}
		return isset( $entry['revision'] ) && $entry['revision'] > 1;
	}
	public function detail( $sudemiId ) {
		$this->read[] = $sudemiId;
		return isset( $this->pages[ $sudemiId ] ) ? $this->pages[ $sudemiId ] : null;
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

// ---- 5. entries that fail: a retry list, the cursor never held back --------------------------------
const OA = 'aaaaaaaa-0000-4000-8000-000000000001';
const OB = 'bbbbbbbb-0000-4000-8000-000000000002';
const OC = 'cccccccc-0000-4000-8000-000000000003';
function log_text( $store ) {
	return (string) $store->read( 'sudemi_log' );
}
$store   = new ArrayStore();
$client  = new FakeClient( $store );
$orders  = new FakeOrders();
$changes = new \Sudemi\Changes( $store, $client, new \Sudemi\Logger( $store ), $orders );
$orders->failing = array( OA => new \Sudemi\ApplyLater( 'order page not readable' ), OB => new \TypeError( 'boom' ) );
$changes->apply( array( 'changed_orders' => array( array( 'order_id' => OA, 'revision' => 3 ), array( 'order_id' => OB, 'revision' => 4 ),
	array( 'order_id' => OC, 'revision' => 5 ) ), 'changes_cursor' => 'c1.R1' ) );
$list = $changes->retryList();
check( 'an ApplyLater and an Error (Throwable) are both caught; the entry after them is still applied',
	3 === count( $orders->applied ) && OC === $orders->applied[2]['order_id'] );
check( 'the cursor moves on past the failed entries', 'c1.R1' === $changes->cursor() );
check( 'both failed orders on the retry list (1 attempt, first seen now), the applied one not',
	array( OA, OB ) === array_keys( $list ) && 1 === $list[ OA ]['attempts'] && 1 === $list[ OB ]['attempts']
	&& abs( time() - $list[ OA ]['first'] ) < 5 && ! isset( $list[ OC ] ) );
check( 'the failures are logged (none silent)', false !== strpos( log_text( $store ), OA ) && false !== strpos( log_text( $store ), 'boom' ) );
check( 'a failed entry is not read again in the same beat', array() === $orders->read );

// Next beat: OA's page is readable now, OB's not; the feed brings nothing for them.
$orders->failing = array();
$orders->applied = array();
$orders->pages   = array( OA => array( 'order_id' => OA, 'status' => 'PAID', 'revision' => 3 ) );
$changes->apply( array( 'changed_orders' => array(), 'changes_cursor' => 'c1.R2' ) );
$list = $changes->retryList();
check( 'next beat: both read from their order pages; OA applied and off the list',
	array( OA, OB ) === $orders->read && 1 === count( $orders->applied ) && OA === $orders->applied[0]['order_id']
	&& 'PAID' === $orders->applied[0]['status'] && ! isset( $list[ OA ] ) );
check( 'OB still unreadable: kept, attempt 2', isset( $list[ OB ] ) && 2 === $list[ OB ]['attempts'] );

// A newer feed entry for a listed order that applies takes it off the list without a page read.
$orders->read = array();
$changes->apply( array( 'changed_orders' => array( array( 'order_id' => OB, 'revision' => 6 ) ), 'changes_cursor' => 'c1.R3' ) );
check( 'a feed entry for a listed order applied: off the list, no page read', array() === $changes->retryList() && array() === $orders->read );

// Giving up loudly: after RETRY_MAX attempts, and after RETRY_DAYS days.
$store->write( \Sudemi\Changes::SETTING_RETRY, json_encode( array(
	OA => array( 'attempts' => \Sudemi\Changes::RETRY_MAX - 1, 'first' => time(), 'error' => 'x' ),
	OB => array( 'attempts' => 1, 'first' => time() - ( \Sudemi\Changes::RETRY_DAYS + 1 ) * 86400, 'error' => 'x' ),
	OC => array( 'attempts' => 1, 'first' => time(), 'error' => 'x' ),
) ) );
$orders->pages = array();
$changes->apply( array( 'changed_orders' => array(), 'changes_cursor' => 'c1.R4' ) );
check( 'given up after the attempt limit and after the day limit, each with an error in the log; the third kept',
	array( OC ) === array_keys( $changes->retryList() ) && 2 === substr_count( log_text( $store ), 'Gave up on sudemi order' )
	&& false !== strpos( log_text( $store ), 'Gave up on sudemi order ' . OA ) && false !== strpos( log_text( $store ), 'Gave up on sudemi order ' . OB ) );

// At most RETRY_PER_BEAT read per beat.
$many = array();
for ( $i = 0; $i < \Sudemi\Changes::RETRY_PER_BEAT + 5; $i++ ) {
	$many[ sprintf( 'dddddddd-0000-4000-8000-%012d', $i ) ] = array( 'attempts' => 1, 'first' => time(), 'error' => 'x' );
}
$store->write( \Sudemi\Changes::SETTING_RETRY, json_encode( $many ) );
$orders->read = array();
$changes->apply( array( 'changed_orders' => array(), 'changes_cursor' => 'c1.R5' ) );
check( 'at most RETRY_PER_BEAT orders read per beat, the rest kept', \Sudemi\Changes::RETRY_PER_BEAT === count( $orders->read )
	&& count( $many ) === count( $changes->retryList() ) );

// The safety net: a failing row goes on the list too.
$store->delete( \Sudemi\Changes::SETTING_RETRY );
class PagedClient extends FakeClient {
	public function sendSigned( $method, $path, array $body = array(), array $headers = array() ) {
		$this->calls[] = array( $method, $path );
		return array( 'status' => 200, 'body' => array( 'orders' => array( array( 'order_id' => OA, 'status' => 'PAID', 'revision' => 9 ) ), 'next_offset' => null ) );
	}
}
$client2  = new PagedClient( $store );
$changes2 = new \Sudemi\Changes( $store, $client2, new \Sudemi\Logger( $store ), $orders );
$store->write( \Sudemi\Changes::SETTING_CURSOR, json_encode( array( 'install' => 'inst-1', 'cursor' => 'c1.S' ) ) );
$orders->failing = array( OA => new \Sudemi\ApplyLater( 'order page not readable' ) );
$changes2->safetyNet( true );
check( 'safety net: a row that cannot be applied goes on the retry list', array( OA ) === array_keys( $changes2->retryList() ) );
$changes2->forget();
check( 'forget(): the retry list is gone too', array() === $changes2->retryList() );

if ( $failures > 0 ) {
	echo "\n$failures check(s) failed\n";
	exit( 1 );
}
echo "\nall change-feed checks passed\n";
