<?php
/**
 * Return requests and buyer cancellations.
 *
 * 1. The heartbeat's open_requests replace the stored list on every successful beat (and clear it
 *    when absent); the admin's answers are signed POSTs to /orders/{id}/request/accept|reject, a
 *    rejection needs a reason, and every answer of sudemi's maps to an outcome.
 * 2. A buyer's cancellation of a paid order (settled_orders CANCELLED) cancels the confirmed local
 *    order: Canceled, stock back, a history note, no call to sudemi, idempotent; shipped, complete,
 *    cancelled or refunded orders are left alone.
 *
 * Runs without OpenCart: the database, the config and sudemi are faked here.
 */

declare(strict_types=1);

define( 'DB_PREFIX', 'oc_' );
foreach ( array( 'store', 'logger', 'keys', 'client', 'pairing', 'sync', 'queue', 'orders', 'heartbeat', 'requests' ) as $file ) {
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

/** OpenCart's setting rows, in memory. */
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

/** sudemi: records every call and answers with whatever the test queued. */
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
		$this->calls[] = array( $method, $path, $body, json_encode( $body ) );
		return array_shift( $this->answers );
	}
	public function postSigned( $path, array $body ) {
		return $this->sendSigned( 'POST', $path, $body );
	}
	public function getSigned( $path ) {
		return $this->sendSigned( 'GET', $path );
	}
}

class FakeCatalogue {
	public function revision( $changes ) {
		return 'rev';
	}
	public function productCount() {
		return 1;
	}
}

const O1 = '11111111-2222-4333-8444-555555555555';
const O2 = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
const R1 = '99999999-8888-4777-8666-555555555555';

function entry( $order, $respondBy, $reason = 'Too small' ) {
	return array(
		'request_id'   => R1,
		'order_id'     => $order,
		'kind'         => 'RETURN',
		'reason'       => $reason,
		'requested_at' => '2026-11-07T10:00:00.123456Z',
		'respond_by'   => $respondBy,
	);
}

// ---- 1. the heartbeat stores open_requests --------------------------------------------------------
$store  = new ArrayStore();
$store->values = array( 'sudemi_install_id' => 'inst-1' );
$client = new FakeClient( $store );
$logger = new \Sudemi\Logger( $store );
$queue  = new \Sudemi\Queue( $store, $client );
$beat   = new \Sudemi\Heartbeat( $store, $client, $logger, $queue, new FakeCatalogue(),
	new \Sudemi\Pairing( $client, new FakeKeys(), $store, $logger ), new \Sudemi\Sync( $client, $logger, new FakeCatalogue() ) );
$requests = new \Sudemi\Requests( $store, $client );

$client->answers[] = array( 'status' => 200, 'body' => array(
	'catalogue_listed' => true,
	'open_requests'    => array(
		entry( O2, '2026-11-14T12:00:00Z', "<script>alert(1)</script>\r\nsecond line" ),
		entry( strtoupper( O1 ), '2026-11-10T09:00:00Z' ),
		array( 'order_id' => '../../admin', 'respond_by' => 'x' ),
		'garbage',
	),
	'open_requests_note' => 'The buyer asked...',
) );
$beat->run();
check( 'heartbeat: one signed POST to /heartbeat', 'POST' === $client->calls[0][0] && '/integration/v1/heartbeat' === $client->calls[0][1] );
$all = $requests->all();
check( 'heartbeat: well-formed requests stored, malformed ones dropped', 2 === count( $all ) );
check( 'stored list: soonest deadline first, order id lower-cased', O1 === $all[0]['order_id'] && O2 === $all[1]['order_id'] );
check( 'stored list: reason kept as plain text (escaped on output), CRLF normalised',
	"<script>alert(1)</script>\nsecond line" === $all[1]['reason'] );
check( 'stored list: instants kept', '2026-11-10T09:00:00Z' === $all[0]['respond_by'] && '2026-11-07T10:00:00.123456Z' === $all[0]['requested_at'] );
check( 'find(): by sudemi order id', null !== $requests->find( O2 ) && null === $requests->find( R1 ) );

$client->answers[] = array( 'status' => 200, 'body' => array( 'catalogue_listed' => true, 'open_requests' => array( entry( O2, '2026-11-14T12:00:00Z' ) ) ) );
$beat->run();
check( 'next beat: the list is replaced, not merged', 1 === count( $requests->all() ) && O2 === $requests->all()[0]['order_id'] );

$client->answers[] = array( 'status' => 503, 'body' => array() );
$beat->run();
check( 'a beat that is not 200 keeps the list', 1 === count( $requests->all() ) );
$client->answers[] = null;
$beat->run();
check( 'an unreachable beat keeps the list', 1 === count( $requests->all() ) );

$client->answers[] = array( 'status' => 200, 'body' => array( 'catalogue_listed' => true ) );
$beat->run();
check( 'a 200 beat without open_requests clears the list', array() === $requests->all() && ! isset( $store->values[ \Sudemi\Requests::SETTING ] ) );

\Sudemi\Requests::remember( $store, array( entry( O1, '2026-11-10T09:00:00Z' ) ) );
$beat->forget();
check( 'disconnecting forgets the stored requests', array() === $requests->all() );

// ---- 2. answering ----------------------------------------------------------------------------------
function fresh( array $answers ) {
	$store = new ArrayStore();
	\Sudemi\Requests::remember( $store, array( entry( O1, '2026-11-10T09:00:00Z' ), entry( O2, '2026-11-14T12:00:00Z' ) ) );
	$client          = new FakeClient( $store );
	$client->answers = $answers;
	return array( new \Sudemi\Requests( $store, $client ), $client );
}

list( $r, $c ) = fresh( array( array( 'status' => 200, 'body' => array( 'status' => 'ok' ) ) ) );
$result        = $r->accept( strtoupper( O1 ) );
check( 'accept: ok', $result['ok'] && 'ok' === $result['outcome'] );
check( 'accept: one signed POST to /orders/{id}/request/accept', 1 === count( $c->calls ) && 'POST' === $c->calls[0][0]
	&& '/integration/v1/orders/' . O1 . '/request/accept' === $c->calls[0][1] );
check( 'accept: the body is a JSON object, not []', '{"reason":null}' === $c->calls[0][3] );
check( 'accept: the request leaves the stored list', null === $r->find( O1 ) && null !== $r->find( O2 ) );

list( $r, $c ) = fresh( array( array( 'status' => 200, 'body' => array( 'status' => 'ok' ) ) ) );
$result        = $r->reject( O2, "  Worn and washed\r\n " );
check( 'reject: ok, POST to /request/reject with the trimmed reason',
	$result['ok'] && '/integration/v1/orders/' . O2 . '/request/reject' === $c->calls[0][1] && array( 'reason' => 'Worn and washed' ) === $c->calls[0][2] );
check( 'reject: the request leaves the stored list', null === $r->find( O2 ) && null !== $r->find( O1 ) );

foreach ( array( '', "  \n ", null, array( 'x' ) ) as $empty ) {
	list( $r, $c ) = fresh( array() );
	$result        = $r->reject( O1, $empty );
	check( 'reject: reason ' . json_encode( $empty ) . ' is refused before sending',
		! $result['ok'] && 'reason_required' === $result['outcome'] && array() === $c->calls && null !== $r->find( O1 ) );
}
list( $r, $c ) = fresh( array( array( 'status' => 200, 'body' => array( 'status' => 'ok' ) ) ) );
$r->reject( O1, str_repeat( 'é', 1500 ) );
check( 'reject: a long reason is capped at 1000 characters', 1000 === mb_strlen( $c->calls[0][2]['reason'], 'UTF-8' ) );

foreach ( array( '', '../admin', O1 . '/x', 'S-1', null ) as $bad ) {
	list( $r, $c ) = fresh( array() );
	check( 'answer: order id ' . var_export( $bad, true ) . ' never reaches a URL',
		'not_found' === $r->accept( $bad )['outcome'] && 'not_found' === $r->reject( $bad, 'x' )['outcome'] && array() === $c->calls );
}

foreach ( array(
	'200 already_resolved' => array( array( 'status' => 200, 'body' => array( 'status' => 'already_resolved', 'message' => 'x' ) ), 'already_resolved', true ),
	'404'                  => array( array( 'status' => 404, 'body' => array( 'error' => 'not_found' ) ), 'not_found', true ),
	'400'                  => array( array( 'status' => 400, 'body' => array( 'error' => 'invalid_request' ) ), 'invalid', false ),
	'409 site_mismatch'    => array( array( 'status' => 409, 'body' => array( 'error' => 'site_mismatch' ) ), 'site_mismatch', false ),
	'500'                  => array( array( 'status' => 500, 'body' => array() ), 'unavailable', false ),
	'200 without a status' => array( array( 'status' => 200, 'body' => array() ), 'unavailable', false ),
	'429'                  => array( array( 'status' => 429, 'body' => array() ), 'unavailable', false ),
	'transport error'      => array( null, 'unavailable', false ),
) as $what => $case ) {
	list( $r, $c ) = fresh( array( $case[0] ) );
	$result        = $r->reject( O1, 'Worn' );
	check( "outcome: $what -> {$case[1]}", ! $result['ok'] && $case[1] === $result['outcome'] && 1 === count( $c->calls ) );
	check( "outcome: $what " . ( $case[2] ? 'removes' : 'keeps' ) . ' the stored request', $case[2] === ( null === $r->find( O1 ) ) );
}
check( 'without a client nothing is sent', 'unavailable' === ( new \Sudemi\Requests( new ArrayStore() ) )->accept( O1 )['outcome'] );

// ---- 3. buyer cancellation of a paid order reaches the local order --------------------------------
/** The few OpenCart tables Orders touches, in memory; queries are matched by shape. */
class FakeDb {
	public $orders   = array();
	public $lines    = array();
	public $products = array();
	public $history  = array();
	public $log      = array();
	public function escape( $value ) {
		return addslashes( (string) $value );
	}
	public function getLastId() {
		return 0;
	}
	public function query( $sql ) {
		$sql         = preg_replace( '/\s+/', ' ', trim( $sql ) );
		$this->log[] = $sql;
		$m           = array();
		if ( preg_match( "/^SELECT order_id FROM oc_order WHERE comment = '(.*)' LIMIT 1$/", $sql, $m ) ) {
			foreach ( $this->orders as $id => $o ) {
				if ( $o['comment'] === stripslashes( $m[1] ) ) {
					return $this->rows( array( array( 'order_id' => $id ) ) );
				}
			}
			return $this->rows( array() );
		}
		if ( preg_match( "/^SELECT order_status_id FROM oc_order WHERE order_id = '(\d+)'$/", $sql, $m ) ) {
			return $this->rows( isset( $this->orders[ $m[1] ] ) ? array( array( 'order_status_id' => $this->orders[ $m[1] ]['status'] ) ) : array() );
		}
		if ( preg_match( "/^SELECT COUNT\(\*\) AS n FROM oc_order_history WHERE order_id = '(\d+)' AND comment = '(.*)'$/", $sql, $m ) ) {
			$n = 0;
			foreach ( $this->history as $h ) {
				$n += ( $h['order_id'] === (int) $m[1] && $h['comment'] === stripslashes( $m[2] ) ) ? 1 : 0;
			}
			return $this->rows( array( array( 'n' => $n ) ) );
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
		if ( preg_match( "/^INSERT INTO oc_order_history SET order_id = '(\d+)', order_status_id = '(\d+)', notify = '0', comment = '(.*)', date_added = NOW\(\)$/", $sql, $m ) ) {
			$this->history[] = array( 'order_id' => (int) $m[1], 'status' => (int) $m[2], 'comment' => stripslashes( $m[3] ) );
			return true;
		}
		throw new \RuntimeException( 'unexpected query: ' . $sql );
	}
	private function rows( array $rows ) {
		return (object) array( 'num_rows' => count( $rows ), 'row' => $rows ? $rows[0] : array(), 'rows' => $rows );
	}
}
class FakeConfig {
	public $values = array(
		'config_order_status_id'   => 1,
		'config_processing_status' => array( '5', '1', '2', '12', '3' ),
		'config_complete_status'   => array( '5', '3' ),
	);
	public function get( $key ) {
		return isset( $this->values[ $key ] ) ? $this->values[ $key ] : null;
	}
}

function shop( $status ) {
	$db           = new FakeDb();
	$db->orders   = array( 42 => array( 'status' => $status, 'comment' => 'sudemi:' . O1 ) );
	$db->lines    = array( 42 => array( array( 'product_id' => 7, 'quantity' => 2 ) ) );
	$db->products = array( 7 => 6 );
	$store        = new ArrayStore();
	$client       = new FakeClient( $store );
	$orders       = new \Sudemi\Orders( $db, new FakeConfig(), $client, new \Sudemi\Logger( $store ) );
	return array( $orders, $db, $client );
}
$cancelled = array( array( 'order_id' => O1, 'status' => 'CANCELLED' ) );

foreach ( array( 1 => 'Pending', 2 => 'Processing' ) as $status => $name ) {
	list( $orders, $db, $client ) = shop( $status );
	$orders->handleSettled( $cancelled );
	check( "buyer cancel of a paid order ($name): local order Canceled", 7 === $db->orders[42]['status'] );
	check( "buyer cancel ($name): stock restored (6 + 2)", 8 === $db->products[7] );
	check( "buyer cancel ($name): history note added with status Canceled", 1 === count( $db->history )
		&& 7 === $db->history[0]['status'] && \Sudemi\Orders::NOTE_CANCELLED_BY_BUYER === $db->history[0]['comment'] );
	check( "buyer cancel ($name): nothing sent to sudemi (no /cancel, no /refund)", array() === $client->calls );
	check( "buyer cancel ($name): the event guard sees it", $orders->isCancelledOnSudemi( 42 ) );
	$orders->handleSettled( $cancelled );
	check( "buyer cancel ($name): the next beat (repeated for 7 days) changes nothing",
		7 === $db->orders[42]['status'] && 8 === $db->products[7] && 1 === count( $db->history ) && array() === $client->calls );
}
check( 'the note is the agreed text', 'Cancelled by the buyer on sudemi before shipping; the buyer has been refunded in full' === \Sudemi\Orders::NOTE_CANCELLED_BY_BUYER );

foreach ( array( 3 => 'Shipped', 5 => 'Complete', 7 => 'Canceled', 11 => 'Refunded', 8 => 'Denied' ) as $status => $name ) {
	list( $orders, $db, $client ) = shop( $status );
	$orders->handleSettled( $cancelled );
	check( "an order already $name is left alone", $status === $db->orders[42]['status'] && 6 === $db->products[7]
		&& array() === $db->history && array() === $client->calls && ! $orders->isCancelledOnSudemi( 42 ) );
}

// ACCEPTED (waiting for other shops) then cancelled by the buyer: DECLINED on sudemi, voided here.
foreach ( array( 'DECLINED', 'CANCELLED' ) as $state ) {
	list( $orders, $db, $client ) = shop( 0 );
	$orders->handleSettled( array( array( 'order_id' => O1, 'status' => $state ) ) );
	check( "$state on an order still on hold: stock back, stays voided, never confirmed",
		0 === $db->orders[42]['status'] && 8 === $db->products[7] && array() === $client->calls );
	$orders->handleSettled( array( array( 'order_id' => O1, 'status' => $state ) ) );
	check( "$state on hold, repeated: no second restock", 8 === $db->products[7] );
}

list( $orders, $db, $client ) = shop( 1 );
$orders->handleSettled( array( array( 'order_id' => R1, 'status' => 'CANCELLED' ) ) );
check( 'a cancellation for an order this shop does not have changes nothing', 1 === $db->orders[42]['status'] && array() === $db->history );

list( $orders, $db, $client ) = shop( 1 );
$orders->note( 42, "sudemi: return request rejected. Reason shown to the buyer: it's worn" );
check( 'note(): history row with the current status, quotes kept', 1 === $db->history[0]['status']
	&& "sudemi: return request rejected. Reason shown to the buyer: it's worn" === $db->history[0]['comment'] );
check( 'localOrderOf(): finds the local order by its sudemi id', 42 === $orders->localOrderOf( O1 ) && null === $orders->localOrderOf( O2 ) );

// ---- 4. the order-status events skip an order cancelled on sudemi --------------------------------
foreach ( array( 'admin/controller/event.php', 'catalog/controller/event.php' ) as $file ) {
	$source = (string) file_get_contents( __DIR__ . '/../' . $file );
	$guard  = strpos( $source, 'isCancelledOnSudemi($orderId)' );
	check( "$file: the guard runs before refund()/cancel() are sent", false !== $guard
		&& $guard < strpos( $source, "->refund(\$sudemiId" ) && $guard < strpos( $source, "->cancel(\$sudemiId" ) );
}

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
