<?php
/**
 * Collecting a claimed pairing from cron (\Sudemi\Pairing::pollInBackground): asks once per run
 * while a pairing is open, waits between asks (a minute, later twenty minutes, longer when a 429
 * says so), stores the install like "Check again" does, and gives up after the 24-hour lifetime
 * with one log line. Also: the cron token survives a disconnect (Heartbeat::forget).
 *
 * Runs without OpenCart: the settings store and sudemi are faked here.
 */

declare(strict_types=1);

define( 'DB_PREFIX', 'oc_' );
foreach ( array( 'store', 'logger', 'keys', 'client', 'pairing', 'sync', 'queue', 'heartbeat' ) as $file ) {
	require_once __DIR__ . '/../system/library/sudemi/' . $file . '.php';
}

use Sudemi\Pairing;

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
}

/** sudemi: records every unsigned call and answers with whatever the test set. */
class FakeClient extends \Sudemi\Client {
	public $calls = array();
	public $next  = null;
	public function __construct( $store ) {
		parent::__construct( new FakeKeys(), $store, new \Sudemi\Logger( $store ) );
	}
	public function postUnsigned( $path, array $body ) {
		$this->calls[] = array( $path, $body );
		return $this->next;
	}
}

/** A fresh store with an open pairing of the given age, and the objects around it. */
function open_pairing( int $age ): array {
	$store                 = new ArrayStore();
	$store->values         = array(
		Pairing::SETTING_CODE      => 'ABCD-1234',
		Pairing::SETTING_POLL      => 'poll-secret',
		Pairing::SETTING_CLAIM_URL => 'https://sudemi.com/seller/claim/ABCD-1234',
		Pairing::SETTING_STARTED   => time() - $age,
		// Stored only (no file is written outside a shop: DIR_OPENCART is not defined here).
		Pairing::SETTING_CHALLENGE => 'challenge-token-xxxxxxxxxxxx',
	);
	$logger  = new \Sudemi\Logger( $store );
	$client  = new FakeClient( $store );
	$pairing = new Pairing( $client, new FakeKeys(), $store, $logger );
	return array( $store, $client, $pairing, $logger );
}

function messages( \Sudemi\Logger $logger ): array {
	return array_map( function ( $e ) {
		return $e['level'] . ' ' . $e['message'];
	}, $logger->entries() );
}

// Nothing started: no call at all.
list( $store, $client, $pairing ) = open_pairing( 10 );
$store->values = array();
check( 'no pairing open: nothing is asked', false === $pairing->pollInBackground() && array() === $client->calls );

// Open, not claimed yet: one ask, then the next run within the minute asks nothing.
list( $store, $client, $pairing ) = open_pairing( 10 );
$client->next = array( 'status' => 202, 'body' => array( 'status' => 'pending' ), 'retry_after' => 0 );
$first        = $pairing->pollInBackground();
check( 'open pairing: one poll with the code and the poll token',
	false === $first && 1 === count( $client->calls )
	&& '/integration/v1/pair/poll' === $client->calls[0][0]
	&& array( 'publicCode' => 'ABCD-1234', 'pollToken' => 'poll-secret' ) === $client->calls[0][1] );
$next = (int) $store->read( Pairing::SETTING_NEXT_POLL );
check( 'the next background ask is a minute away', $next >= time() + 59 && $next <= time() + 61 );
$pairing->pollInBackground();
check( 'a second run within the minute asks nothing', 1 === count( $client->calls ) && $pairing->pending() );

// After the first half hour: every twenty minutes.
list( $store, $client, $pairing ) = open_pairing( 2000 );
$client->next = array( 'status' => 202, 'body' => array(), 'retry_after' => 0 );
$pairing->pollInBackground();
check( 'after half an hour the next ask is twenty minutes away', (int) $store->read( Pairing::SETTING_NEXT_POLL ) >= time() + 1199 );

// 429: wait as long as Retry-After says.
list( $store, $client, $pairing ) = open_pairing( 10 );
$client->next = array( 'status' => 429, 'body' => array( 'error' => 'too_many_requests' ), 'retry_after' => 600 );
$pairing->pollInBackground();
check( 'a 429 holds the next ask for its Retry-After', (int) $store->read( Pairing::SETTING_NEXT_POLL ) >= time() + 599 && $pairing->pending() );

// Claimed: stored exactly as the button does.
list( $store, $client, $pairing, $logger ) = open_pairing( 10 );
$client->next = array( 'status' => 200, 'body' => array( 'status' => 'ready', 'install_id' => 'inst-1' ), 'retry_after' => 0 );
check( 'claimed: connected, install id stored', true === $pairing->pollInBackground() && 'inst-1' === $store->read( \Sudemi\Client::SETTING_INSTALL_ID ) );
check( 'claimed: the challenge and the pairing are forgotten',
	null === $store->read( Pairing::SETTING_CHALLENGE ) && ! $pairing->pending()
	&& null === $store->read( Pairing::SETTING_STARTED ) && null === $store->read( Pairing::SETTING_NEXT_POLL ) );
check( 'claimed: logged once', array( 'info Connected to sudemi.' ) === messages( $logger ) );
$client->calls = array();
$pairing->pollInBackground();
check( 'claimed: the next run asks nothing', array() === $client->calls );

// Older than the 24-hour lifetime: given up without asking, one log line.
list( $store, $client, $pairing, $logger ) = open_pairing( 86400 + 5 );
check( 'expired: not asked', false === $pairing->pollInBackground() && array() === $client->calls );
check( 'expired: pairing forgotten', ! $pairing->pending() && null === $store->read( Pairing::SETTING_STARTED ) );
$lines = messages( $logger );
check( 'expired: one line in the log', 1 === count( $lines ) && 0 === strpos( $lines[0], 'warn The connection attempt expired after 24 hours' ) );
$pairing->pollInBackground();
check( 'expired: the next run logs nothing more', 1 === count( messages( $logger ) ) );

// 410 from sudemi: forgotten, as before.
list( $store, $client, $pairing ) = open_pairing( 10 );
$client->next = array( 'status' => 410, 'body' => array( 'error' => 'pairing_unavailable' ), 'retry_after' => 0 );
$pairing->pollInBackground();
check( 'a 410 ends the pairing', ! $pairing->pending() );

// Started by an older version (no start time): counted from now, and asked.
list( $store, $client, $pairing ) = open_pairing( 10 );
$store->delete( Pairing::SETTING_STARTED );
$client->next = array( 'status' => 202, 'body' => array(), 'retry_after' => 0 );
$pairing->pollInBackground();
check( 'no start time: asked, and the lifetime counts from now',
	1 === count( $client->calls ) && abs( (int) $store->read( Pairing::SETTING_STARTED ) - time() ) <= 1 );

// The button (poll()) still asks whenever pressed, even inside the background wait.
list( $store, $client, $pairing ) = open_pairing( 10 );
$store->write( Pairing::SETTING_NEXT_POLL, time() + 600 );
$client->next = array( 'status' => 202, 'body' => array(), 'retry_after' => 0 );
$pairing->poll();
check( '"Check again" asks even while the background poll waits', 1 === count( $client->calls ) );

// Disconnect (Heartbeat::forget) keeps the cron token, so the crontab line keeps working.
$store                                                  = new ArrayStore();
$store->values[ \Sudemi\Heartbeat::SETTING_CRON_TOKEN ] = str_repeat( 'c', 32 );
$client    = new FakeClient( $store );
$logger    = new \Sudemi\Logger( $store );
$heartbeat = new \Sudemi\Heartbeat(
	$store, $client, $logger, new \Sudemi\Queue( $store, $client, $logger ), null,
	new Pairing( $client, new FakeKeys(), $store, $logger ), new \Sudemi\Sync( $client, $logger, null, $store )
);
$heartbeat->forget();
check( 'disconnect keeps the cron token', str_repeat( 'c', 32 ) === $heartbeat->cronToken() );

if ( $failures > 0 ) {
	echo "\n", $failures, " check(s) failed\n";
	exit( 1 );
}
echo "\nall pairing checks passed\n";
