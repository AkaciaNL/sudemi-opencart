<?php
/**
 * Tracking after shipping (1.3.0, core 2026-11-09, PROTOCOL.md 4d) and who cancelled on the change feed.
 *
 * 1. Tracking typed before the order is reported shipped is only saved (Shipped sends it).
 * 2. Typed or changed after shipping: sent at once with POST /orders/{id}/tracking; nothing sent
 *    when sudemi already has it; already_resolved is fine.
 * 3. 409 not_shipped: still PAID on sudemi sends `shipped` with the tracking instead; delivered,
 *    cancelled or refunded is final. Not answered: queued and retried on the heartbeat, with the
 *    same verdicts.
 * 4. No loop: tracking that comes from sudemi (the change feed) is stored and never sent back.
 * 5. changed_orders' cancelled_by: the note says who cancelled; on a DECLINED order (changed_orders
 *    and settled_orders) who declined, once, and nothing from an older core.
 *
 * Runs without OpenCart: the database, the config and sudemi are faked here.
 */

declare(strict_types=1);

define( 'DB_PREFIX', 'oc_' );
foreach ( array( 'store', 'logger', 'keys', 'client', 'currency', 'queue', 'orders' ) as $file ) {
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

/** sudemi: records every call and answers with whatever the test queued (GET included). */
class FakeClient extends \Sudemi\Client {
	public $calls   = array();
	public $answers = array();
	public function __construct( $store ) {
		parent::__construct( new FakeKeys(), $store, new \Sudemi\Logger( $store ) );
	}
	public function isConnected() {
		return true;
	}
	public function sendSigned( $method, $path, array $body = array(), array $headers = array() ) {
		$this->calls[] = array( $method, $path, $body );
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

/** The OpenCart tables Orders touches, with the extension's oc_sudemi_order, in memory. */
class FakeDb {
	public $orders   = array();
	public $lines    = array();
	public $products = array();
	public $history  = array();
	public $sudemi   = array();
	public function escape( $value ) {
		return addslashes( (string) $value );
	}
	public function getLastId() {
		return 0;
	}
	public function query( $sql ) {
		$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );
		$m   = array();
		if ( 0 === strpos( $sql, 'SHOW TABLES LIKE' ) ) {
			return $this->rows( array( array( 'x' => 'oc_sudemi_order' ) ) );
		}
		if ( preg_match( "/^SELECT \* FROM `oc_sudemi_order` WHERE sudemi_order_id = '(.*)' LIMIT 1$/", $sql, $m ) ) {
			return $this->rows( isset( $this->sudemi[ $m[1] ] ) ? array( $this->sudemi[ $m[1] ] ) : array() );
		}
		if ( preg_match( "/^INSERT INTO `oc_sudemi_order` SET `sudemi_order_id` = '([^']*)', (.*) ON DUPLICATE KEY UPDATE /", $sql, $m ) ) {
			$row = isset( $this->sudemi[ $m[1] ] ) ? $this->sudemi[ $m[1] ] : array( 'sudemi_order_id' => $m[1], 'order_id' => 0, 'revision' => 0,
				'status' => '', 'refunded_minor' => 0, 'tracking_carrier' => '', 'tracking_code' => '', 'request' => '' );
			preg_match_all( "/`([a-z_]+)` = '((?:[^'\\\\]|\\\\.)*)'/", $m[2], $sets, PREG_SET_ORDER );
			foreach ( $sets as $set ) {
				$row[ $set[1] ] = stripslashes( $set[2] );
			}
			$this->sudemi[ $m[1] ] = $row;
			return true;
		}
		if ( preg_match( "/^SELECT order_id FROM oc_order WHERE comment = '(.*)' LIMIT 1$/", $sql, $m ) ) {
			foreach ( $this->orders as $id => $o ) {
				if ( $o['comment'] === stripslashes( $m[1] ) ) {
					return $this->rows( array( array( 'order_id' => $id ) ) );
				}
			}
			return $this->rows( array() );
		}
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
			return $this->rows( $o ? array( array( 'order_id' => $m[1], 'order_status_id' => $o['status'], 'comment' => $o['comment'] ) ) : array() );
		}
		if ( preg_match( "/^UPDATE oc_order SET comment = CONCAT\(comment, ' \(released\)'\), date_modified = NOW\(\) WHERE order_id = '(\d+)'$/", $sql, $m ) ) {
			$this->orders[ $m[1] ]['comment'] .= ' (released)';
			return true;
		}
		if ( preg_match( "/^SELECT order_status_id FROM oc_order WHERE order_id = '(\d+)'$/", $sql, $m ) ) {
			return $this->rows( isset( $this->orders[ $m[1] ] ) ? array( array( 'order_status_id' => $this->orders[ $m[1] ]['status'] ) ) : array() );
		}
		if ( preg_match( "/^SELECT shipping_method FROM oc_order WHERE order_id = '(\d+)' LIMIT 1$/", $sql, $m ) ) {
			return $this->rows( array( array( 'shipping_method' => '{"name":"Flat Shipping Rate","code":"flat.flat"}' ) ) );
		}
		if ( preg_match( "/^SELECT COUNT\(\*\) AS n FROM oc_order_history WHERE order_id = '(\d+)' AND comment IN \((.*)\)$/", $sql, $m ) ) {
			preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $m[2], $values );
			$texts = array_map( 'stripslashes', $values[1] );
			$n     = 0;
			foreach ( $this->history as $h ) {
				$n += ( $h['order_id'] === (int) $m[1] && in_array( $h['comment'], $texts, true ) ) ? 1 : 0;
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
		'config_language_admin'    => 'en-gb',
		'config_order_status_id'   => 1,
		'config_processing_status' => array( '1', '2' ),
		'config_complete_status'   => array( '5', '3' ),
	);
	public function get( $key ) {
		return isset( $this->values[ $key ] ) ? $this->values[ $key ] : null;
	}
}

const O1 = '11111111-2222-4333-8444-555555555555';
const TRACK = 'POST /integration/v1/orders/' . O1 . '/tracking';
const SHIP  = 'POST /integration/v1/orders/' . O1 . '/shipped';
const PAGE  = 'GET /integration/v1/orders/' . O1;

/**
 * Local order 42 for O1 with an OpenCart status, and this shop's copy of the sudemi order with
 * sudemi's status and the tracking it holds (null: no copy yet).
 */
function shop( $localStatus, $sudemiStatus = null, $carrier = '', $code = '' ) {
	$db           = new FakeDb();
	$db->orders   = array( 42 => array( 'status' => $localStatus, 'comment' => 'sudemi:' . O1 ) );
	$db->lines    = array( 42 => array( array( 'product_id' => 7, 'quantity' => 1 ) ) );
	$db->products = array( 7 => 5 );
	if ( null !== $sudemiStatus ) {
		$db->sudemi[ O1 ] = array( 'sudemi_order_id' => O1, 'order_id' => 42, 'revision' => 3, 'status' => $sudemiStatus, 'refunded_minor' => 0,
			'tracking_carrier' => $carrier, 'tracking_code' => $code, 'request' => '' );
	}
	$store  = new ArrayStore();
	$client = new FakeClient( $store );
	$orders = new \Sudemi\Orders( $db, new FakeConfig(), $client, new \Sudemi\Logger( $store ), $store );
	return array( $orders, $db, $client, $store );
}
function ok( $status = 'ok' ) {
	return array( 'status' => 200, 'body' => array( 'status' => $status, 'order_id' => O1 ) );
}
function not_shipped() {
	return array( 'status' => 409, 'body' => array( 'error' => 'not_shipped', 'message' => 'tracking can only be changed while the order is SHIPPED.' ) );
}
function page( $status ) {
	return array( 'status' => 200, 'body' => array( 'order_id' => O1, 'status' => $status, 'revision' => 9 ) );
}
function saved( $db ) {
	return $db->sudemi[ O1 ]['tracking_carrier'] . '|' . $db->sudemi[ O1 ]['tracking_code'];
}

// ---- 1. before shipping: saved, sent with Shipped -------------------------------------------------
foreach ( array( 'PAID' => 'PAID', 'no copy yet' => null ) as $what => $status ) {
	list( $orders, $db, $client ) = shop( 2, $status );
	check( "not shipped yet ($what): saved only, nothing sent", 'saved' === $orders->updateTracking( O1, ' DHL ', ' JJD-DE-1 ' )
		&& 'DHL|JJD-DE-1' === saved( $db ) && array() === $client->calls );
}
list( $orders, $db, $client ) = shop( 2, 'PAID' );
$orders->updateTracking( O1, 'DHL', 'JJD-DE-1' );
$client->answers = array( ok() );
$orders->report( O1, 'shipped', 42 );
check( 'Shipped then sends the saved carrier and tracking code', array( SHIP ) === $client->paths()
	&& array( 'carrier' => 'DHL', 'trackingCode' => 'JJD-DE-1' ) === $client->calls[0][2] );

// ---- 2. after shipping: sent at once ---------------------------------------------------------------
list( $orders, $db, $client ) = shop( 3, 'PAID' );
$client->answers = array( ok() );
check( 'shipped here, sudemi has not said SHIPPED yet: the tracking is sent', 'sent' === $orders->updateTracking( O1, 'UPS', '1Z-FR-1' )
	&& array( TRACK ) === $client->paths() && array( 'carrier' => 'UPS', 'trackingCode' => '1Z-FR-1' ) === $client->calls[0][2] );

list( $orders, $db, $client ) = shop( 3, 'SHIPPED', 'DHL', 'JJD-OLD' );
$client->answers = array( ok() );
check( 'SHIPPED on sudemi, tracking changed: sent', 'sent' === $orders->updateTracking( O1, 'DHL', 'JJD-NEW' )
	&& array( TRACK ) === $client->paths() && 'JJD-NEW' === $client->calls[0][2]['trackingCode'] && 'DHL|JJD-NEW' === saved( $db ) );
list( $orders, $db, $client ) = shop( 3, 'SHIPPED', 'DHL', '' );
$client->answers = array( ok() );
check( 'SHIPPED without a code, a code added later: sent, an empty carrier keeps sudemi\'s (null)',
	'sent' === $orders->updateTracking( O1, '', 'JJD-ADDED' ) && array( 'carrier' => null, 'trackingCode' => 'JJD-ADDED' ) === $client->calls[0][2] );

list( $orders, $db, $client ) = shop( 3, 'SHIPPED', 'DHL', 'JJD-SAME' );
check( 'the same carrier and code sudemi has: nothing sent', 'unchanged' === $orders->updateTracking( O1, 'DHL', 'JJD-SAME' ) && array() === $client->calls );
check( 'the same code with the carrier left empty: nothing sent', 'unchanged' === $orders->updateTracking( O1, '', 'JJD-SAME' ) && array() === $client->calls );
list( $orders, $db, $client ) = shop( 3, 'SHIPPED', 'DHL', 'JJD-A' );
$client->answers = array( ok( 'already_resolved' ) );
check( 'already_resolved (sudemi had it after all): unchanged, nothing queued', 'unchanged' === $orders->updateTracking( O1, 'DHL', 'JJD-B' )
	&& array() === $orders->pendingCalls() );

list( $orders, $db, $client ) = shop( 3, 'SHIPPED', 'DHL', 'JJD-A' );
check( 'shipped, the code emptied: refused here (sudemi needs one), nothing sent', 'code_required' === $orders->updateTracking( O1, 'DHL', '' ) && array() === $client->calls );
foreach ( array( 'DELIVERED', 'CANCELLED', 'REFUNDED' ) as $final ) {
	list( $orders, $db, $client ) = shop( 5, $final, 'DHL', 'JJD-A' );
	check( "$final on sudemi: saved here only, nothing sent", 'final' === $orders->updateTracking( O1, 'DHL', 'JJD-B' ) && array() === $client->calls
		&& 'DHL|JJD-B' === saved( $db ) );
}
list( $orders, $db, $client ) = shop( 3, 'SHIPPED' );
check( 'an id that is not a sudemi order id never reaches a URL', 'invalid' === $orders->updateTracking( '../x', 'a', 'b' ) && array() === $client->calls );

// ---- 3. 409 not_shipped, retries ------------------------------------------------------------------
list( $orders, $db, $client ) = shop( 3, 'PAID' );
$client->answers = array( not_shipped(), page( 'PAID' ), ok() );
check( 'tracking answered 409 not_shipped, sudemi says PAID: shipped is sent with the tracking instead',
	'sent' === $orders->updateTracking( O1, 'GLS', 'GLS-ES-1' ) && array( TRACK, PAGE, SHIP ) === $client->paths()
	&& array( 'carrier' => 'GLS', 'trackingCode' => 'GLS-ES-1' ) === $client->calls[2][2] && array() === $orders->pendingCalls() );

list( $orders, $db, $client ) = shop( 3, 'SHIPPED', 'DHL', 'A' );
$client->answers = array( not_shipped(), page( 'DELIVERED' ) );
check( 'tracking answered 409 not_shipped, sudemi says DELIVERED: final, nothing queued, logged',
	'final' === $orders->updateTracking( O1, 'DHL', 'B' ) && array( TRACK, PAGE ) === $client->paths() && array() === $orders->pendingCalls() );

list( $orders, $db, $client, $store ) = shop( 3, 'SHIPPED', 'DHL', 'A' );
$client->answers = array( array( 'status' => 503, 'body' => array() ) );
check( 'tracking answered 503: queued for the heartbeat', 'retry' === $orders->updateTracking( O1, 'DHL', 'B' )
	&& array( 'carrier' => 'DHL', 'trackingCode' => 'B' ) === $orders->pendingCalls()[ O1 . '|tracking' ]['body'] );
check( 'while it is queued, the same code typed again is sent again (not "unchanged")', 'retry' === ( function () use ( $orders, $client ) {
	$client->answers = array( null );
	return $orders->updateTracking( O1, 'DHL', 'B' );
} )() );
$client->calls   = array();
$client->answers = array( ok() );
$orders->drainCalls();
check( 'the heartbeat sends it again and clears it', array( TRACK ) === $client->paths() && array() === $orders->pendingCalls() );

list( $orders, $db, $client ) = shop( 3, 'SHIPPED', 'DHL', 'A' );
$client->answers = array( array( 'status' => 429, 'body' => array( 'error' => 'too_many_requests' ) ) );
$orders->updateTracking( O1, 'DHL', 'B' );
$client->calls   = array();
$client->answers = array( not_shipped(), page( 'PAID' ), array( 'status' => 503, 'body' => array() ) );
$orders->drainCalls();
check( 'retry: 409 not_shipped and PAID, shipped not answered: kept as one queued tracking call',
	array( TRACK, PAGE, SHIP ) === $client->paths() && array( O1 . '|tracking' ) === array_keys( $orders->pendingCalls() ) );
$client->calls   = array();
$client->answers = array( not_shipped(), page( 'PAID' ), ok() );
$orders->drainCalls();
check( 'retry: shipped with the tracking gets through, the queue is empty',
	array( TRACK, PAGE, SHIP ) === $client->paths() && 'B' === $client->calls[2][2]['trackingCode'] && array() === $orders->pendingCalls() );

list( $orders, $db, $client ) = shop( 3, 'SHIPPED', 'DHL', 'A' );
$client->answers = array( null );
$orders->updateTracking( O1, 'DHL', 'B' );
$client->answers = array( not_shipped(), page( 'REFUNDED' ) );
$orders->drainCalls();
check( 'retry: 409 not_shipped on a refunded order is final, the queue is empty', array() === $orders->pendingCalls() );
list( $orders, $db, $client ) = shop( 3, 'SHIPPED', 'DHL', 'A' );
$client->answers = array( null );
$orders->updateTracking( O1, 'DHL', 'B' );
$client->answers = array( not_shipped(), null );
$orders->drainCalls();
check( 'retry: 409 not_shipped and the order page cannot be read: kept for the next beat', isset( $orders->pendingCalls()[ O1 . '|tracking' ] ) );
list( $orders, $db, $client ) = shop( 3, 'SHIPPED', 'DHL', 'A' );
$client->answers = array( array( 'status' => 400, 'body' => array( 'error' => 'tracking_code_required' ) ) );
check( 'a definitive refusal (400): failed, not queued', 'failed' === $orders->updateTracking( O1, 'DHL', 'B' ) && array() === $orders->pendingCalls() );
list( $orders, $db, $client ) = shop( 3, 'SHIPPED', 'DHL', 'A' );
$client->answers = array( array( 'status' => 404, 'body' => array( 'error' => 'no_such_order' ) ) );
check( '404 no_such_order: failed, not queued', 'failed' === $orders->updateTracking( O1, 'DHL', 'B' ) && array() === $orders->pendingCalls() );

// ---- 4. no loop: what sudemi sends is stored, never sent back -------------------------------------
list( $orders, $db, $client ) = shop( 3, 'SHIPPED', 'DHL', 'A' );
$orders->applyChange( array( 'order_id' => O1, 'status' => 'SHIPPED', 'revision' => 4, 'refunded_minor' => 0,
	'tracking' => array( 'carrier' => 'DPD', 'code' => '0944-PORTAL' ), 'request' => null ) );
check( 'a tracking change from the feed: stored with a note, nothing sent to sudemi', array() === $client->calls && 'DPD|0944-PORTAL' === saved( $db )
	&& 1 === count( $db->history ) && false !== strpos( $db->history[0]['comment'], '0944-PORTAL' ) );
check( 'saving the panel unchanged afterwards sends nothing', 'unchanged' === $orders->updateTracking( O1, 'DPD', '0944-PORTAL' ) && array() === $client->calls );
$orders->applyChange( array( 'order_id' => O1, 'status' => 'SHIPPED', 'revision' => 4, 'tracking' => array( 'carrier' => 'X', 'code' => 'Y' ) ) );
check( 'the same revision again changes nothing', 'DPD|0944-PORTAL' === saved( $db ) && array() === $client->calls );
list( $orders, $db, $client ) = shop( 2, 'PAID' );
$orders->applyChange( array( 'order_id' => O1, 'status' => 'SHIPPED', 'revision' => 4, 'tracking' => array( 'carrier' => 'GLS', 'code' => 'G-1' ) ) );
check( 'shipped on the portal: the local order Shipped with the tracking, nothing sent back',
	3 === $db->orders[42]['status'] && 'GLS|G-1' === saved( $db ) && array() === $client->calls );

// ---- 5. who cancelled, from the change feed -----------------------------------------------------
foreach ( array( 'buyer' => 'Cancelled by the buyer on sudemi', 'shop' => 'Cancelled by the shop on sudemi', 'sudemi' => 'Cancelled by sudemi', null => 'Cancelled on sudemi' ) as $by => $start ) {
	list( $orders, $db, $client, $store ) = shop( 1, 'PAID' );
	$entry = array( 'order_id' => O1, 'status' => 'CANCELLED', 'revision' => 4, 'refunded_minor' => 1000, 'currency_code' => 'EUR', 'tracking' => null, 'request' => null );
	if ( '' !== (string) $by ) {
		$entry['cancelled_by'] = $by;
	}
	$orders->applyChange( $entry );
	$note = isset( $db->history[0] ) ? $db->history[0]['comment'] : '';
	check( 'feed CANCELLED, cancelled_by ' . json_encode( $by ) . ": Canceled, stock back, the note says \"$start\"",
		7 === $db->orders[42]['status'] && 6 === $db->products[7] && 0 === strpos( $note, $start ) && array() === $client->calls );
	check( 'feed CANCELLED, cancelled_by ' . json_encode( $by ) . ': the event guard sees it', $orders->isSettledOnSudemi( 42 ) );
}
list( $orders, $db, $client ) = shop( 1, 'PAID' );
$client->answers = array( array( 'status' => 200, 'body' => array( 'order_id' => O1, 'status' => 'CANCELLED', 'revision' => 6, 'money' => array( 'refunded_minor' => 1000, 'currency_code' => 'EUR' ) ) ) );
$orders->refresh( O1, 'shop' );
check( 'a cancel pressed in this shop, read back from the order page: the note says the shop',
	7 === $db->orders[42]['status'] && 0 === strpos( $db->history[0]['comment'], 'Cancelled by the shop on sudemi' ) );
check( 'changeFromRow / changeFromDetail carry cancelled_by when sudemi sends it',
	'buyer' === \Sudemi\Orders::changeFromRow( array( 'order_id' => O1, 'status' => 'CANCELLED', 'cancelled_by' => 'buyer' ) )['cancelled_by']
	&& null === \Sudemi\Orders::changeFromDetail( array( 'order_id' => O1, 'status' => 'CANCELLED' ) )['cancelled_by'] );

// ---- 5b. who declined (never charged), from the change feed and settled_orders --------------------
const DECLINED_NOTES = array(
	'buyer'  => 'Cancelled by the buyer on sudemi before the order was confirmed; the buyer was not charged',
	'shop'   => 'Declined by your shop on sudemi; the buyer was not charged',
	'sudemi' => 'Declined automatically by sudemi (for example because the order was not answered in time); the buyer was not charged',
);
function declined( $by, $revision = 4 ) {
	$entry = array( 'order_id' => O1, 'status' => 'DECLINED', 'revision' => $revision, 'refunded_minor' => 0, 'tracking' => null, 'request' => null );
	if ( null !== $by ) {
		$entry['cancelled_by'] = $by;
	}
	return $entry;
}
foreach ( array( 'buyer', 'shop', 'sudemi', null, 'someone' ) as $by ) {
	list( $orders, $db, $client ) = shop( 0, 'AWAITING_SELLER' );
	$orders->applyChange( declined( $by ) );
	$notes = array_column( $db->history, 'comment' );
	$want  = isset( DECLINED_NOTES[ (string) $by ] ) ? array( DECLINED_NOTES[ $by ] ) : array();
	check( 'feed DECLINED, cancelled_by ' . json_encode( $by ) . ': released, stock back once, ' . ( $want ? 'the note says who declined' : 'no note (as before)' ),
		0 === $db->orders[42]['status'] && 'sudemi:' . O1 . ' (released)' === $db->orders[42]['comment'] && 6 === $db->products[7]
		&& $want === $notes && array() === $client->calls );
	$orders->applyChange( declined( $by, 5 ) );
	check( 'feed DECLINED, cancelled_by ' . json_encode( $by ) . ': a later entry adds nothing', $want === array_column( $db->history, 'comment' ) && 6 === $db->products[7] );
}

// Released by an earlier answer in the same beat (reconcile's accept: already_resolved DECLINED),
// then the feed says sudemi declined it: the note still comes, once.
list( $orders, $db, $client ) = shop( 0, 'AWAITING_SELLER' );
$db->orders[42]['comment'] .= ' (released)';
$orders->applyChange( declined( 'sudemi' ) );
check( 'already released here, feed DECLINED by sudemi: the note says sudemi declined it, nothing restocked again',
	array( DECLINED_NOTES['sudemi'] ) === array_column( $db->history, 'comment' ) && 5 === $db->products[7] );
list( $orders, $db, $client ) = shop( 0, 'DECLINED' );
$orders->applyChange( declined( 'shop', 5 ) );
check( 'already DECLINED in this shop\'s copy: no note repeated', array() === $db->history );
list( $orders, $db, $client ) = shop( 0, null );
$db->orders[42]['comment'] .= ' (released)';
$orders->applyChange( declined( 'shop' ) );
check( 'an order this shop did not follow yet (first feed after an upgrade): no old news', array() === $db->history );

// settled_orders: an accepted order released after all, then the same order on the feed.
list( $orders, $db, $client, $store ) = shop( 0, 'ACCEPTED' );
$orders->handleSettled( array( array( 'order_id' => O1, 'status' => 'DECLINED', 'declined_reason' => 'Out of stock in DE', 'cancelled_by' => 'shop' ) ) );
check( 'settled DECLINED by the shop: released, stock back, the note says your shop declined it',
	'sudemi:' . O1 . ' (released)' === $db->orders[42]['comment'] && 6 === $db->products[7]
	&& array( DECLINED_NOTES['shop'] ) === array_column( $db->history, 'comment' ) );
check( 'the log line keeps the declined reason and says who', false !== strpos( (string) $store->read( 'sudemi_log' ), 'declined by the shop' )
	&& false !== strpos( (string) $store->read( 'sudemi_log' ), 'Out of stock in DE' ) );
$orders->handleSettled( array( array( 'order_id' => O1, 'status' => 'DECLINED', 'declined_reason' => 'Out of stock in DE', 'cancelled_by' => 'shop' ) ) );
$orders->applyChange( declined( 'shop' ) );
check( 'the next beat (settled again, then the feed): still one note, stock back once',
	array( DECLINED_NOTES['shop'] ) === array_column( $db->history, 'comment' ) && 6 === $db->products[7] );
list( $orders, $db, $client, $store ) = shop( 0, 'ACCEPTED' );
$orders->handleSettled( array( array( 'order_id' => O1, 'status' => 'DECLINED', 'declined_reason' => 'authorisation lapsed' ) ) );
check( 'settled DECLINED from an older core (no cancelled_by): released, no note, the reason logged as before',
	6 === $db->products[7] && array() === $db->history && false !== strpos( (string) $store->read( 'sudemi_log' ), 'released after all: authorisation lapsed' ) );

// The note in the admin's language; a note written in another language still counts as written.
list( $orders, $db, $client ) = shop( 0, 'AWAITING_SELLER' );
$db->history[] = array( 'order_id' => 42, 'status' => 0, 'comment' => 'x' );
$config = new FakeConfig();
$config->values['config_language_admin'] = 'de-de';
$orders = new \Sudemi\Orders( $db, $config, $client, new \Sudemi\Logger( new ArrayStore() ), new ArrayStore() );
$orders->applyChange( declined( 'sudemi' ) );
check( 'German admin: the note in German', 'Automatisch von sudemi abgelehnt (zum Beispiel, weil die Bestellung nicht rechtzeitig beantwortet wurde); dem Käufer wurde nichts berechnet'
	=== $db->history[1]['comment'] );
$config->values['config_language_admin'] = 'en-gb';
$orders->applyChange( declined( 'sudemi', 5 ) );
check( 'the admin language changed afterwards: no second note in English', 2 === count( $db->history ) );
check( 'declineNotes: three per language, twelve languages, HTML-escaped as stored',
	36 === count( \Sudemi\Orders::declineNotes() ) && in_array( 'Annulée par l’acheteur sur sudemi avant la confirmation de la commande ; l’acheteur n’a pas été débité', \Sudemi\Orders::declineNotes(), true ) );

// ---- 6. the texts in every language ---------------------------------------------------------------
$keys = array( 'text_tracking_saved', 'text_tracking_sent', 'text_tracking_unchanged', 'text_tracking_retry', 'error_tracking_final',
	'error_tracking_failed', 'error_tracking_code_required', 'button_update_tracking', 'help_update_tracking', 'text_action_tracking_updated',
	'help_tracking', 'text_note_cancelled_buyer', 'text_note_cancelled_shop', 'text_note_cancelled_sudemi', 'text_note_cancelled',
	'text_note_declined_buyer', 'text_note_declined_shop', 'text_note_declined_sudemi' );
$_ = array();
include __DIR__ . '/../admin/language/en-gb/module/sudemi.php';
$english = $_;
foreach ( glob( __DIR__ . '/../admin/language/*/module/sudemi.php' ) as $file ) {
	$code = basename( dirname( dirname( $file ) ) );
	$_    = array();
	include $file;
	$missing = array_diff( $keys, array_keys( array_filter( $_, 'strlen' ) ) );
	check( "tracking and cancellation texts present in $code", array() === $missing );
	$dash = false;
	foreach ( $keys as $key ) {
		$dash = $dash || ( isset( $_[ $key ] ) && preg_match( '/\x{2014}|\x{2013}/u', $_[ $key ] ) );
	}
	check( "no long dash in the new texts in $code", ! $dash );
	if ( 'en-gb' !== $code ) {
		check( "the new texts are translated in $code", $_['text_tracking_sent'] !== $english['text_tracking_sent'] && $_['text_note_cancelled_shop'] !== $english['text_note_cancelled_shop']
			&& $_['text_note_declined_shop'] !== $english['text_note_declined_shop'] && $_['text_note_declined_sudemi'] !== $english['text_note_declined_sudemi']
			&& $_['text_note_declined_buyer'] !== $english['text_note_declined_buyer'] );
	}
}
check( 'twelve languages', 12 === count( glob( __DIR__ . '/../admin/language/*/module/sudemi.php' ) ) );
$template = (string) file_get_contents( __DIR__ . '/../admin/view/template/module/sudemi_order.twig' );
check( 'the order page offers Change tracking when sudemi allows update_tracking',
	false !== strpos( $template, 'order.actions.update_tracking' ) && false !== strpos( $template, "sudemiAct('update_tracking'" ) );

echo $failures ? "\n$failures check(s) failed\n" : "\nall tracking checks passed\n";
exit( $failures ? 1 : 0 );
