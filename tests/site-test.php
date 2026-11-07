<?php
/**
 * The Sudemi-Site header and sudemi's 409 site_mismatch answer.
 *
 * A staging copy or a restored backup holds the live shop's install id and key. Every signed call
 * therefore says which site it comes from (Sudemi-Site, outside the signature), and sudemi refuses
 * a call from any other site than the connected shop with 409 site_mismatch. This proves the header
 * really goes out over HTTP on signed calls only, that the signature is unchanged by it, and that
 * the heartbeat records the refusal as "not connected" with the connected address.
 *
 * Runs without OpenCart: sudemi is played by tests/fake-sudemi.php on PHP's built-in server.
 */

declare(strict_types=1);


$dir = sys_get_temp_dir() . '/sudemi-site-test-' . getmypid();
@mkdir( $dir );
$port   = 18000 + ( getmypid() % 1000 );
$server = proc_open(
	array( PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fake-sudemi.php' ),
	array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
	$pipes,
	null,
	array( 'SUDEMI_FAKE_DIR' => $dir )
);
register_shutdown_function(
	function () use ( $server, $dir ) {
		proc_terminate( $server );
		array_map( 'unlink', glob( $dir . '/*' ) ?: array() );
		@rmdir( $dir );
	}
);
for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $port ); $i++ ) {
	usleep( 100000 );
}

foreach ( array( 'store', 'logger', 'keys', 'client', 'pairing', 'sync', 'queue', 'heartbeat' ) as $file ) {
	require_once __DIR__ . '/../system/library/sudemi/' . $file . '.php';
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
/** Signs without key material, and remembers what it signed. */
class FakeKeys extends \Sudemi\Keys {
	public $signed = array();
	public function __construct() {}
	public function hasKey() {
		return true;
	}
	public function sign( $message ) {
		$this->signed[] = $message;
		return 'sig';
	}
}
class FakeCatalogue {
	public function revision( $changes ) {
		return 'rev';
	}
	public function productCount() {
		return 3;
	}
}

$store = new ArrayStore();
$store->values = array(
	'sudemi_install_id' => 'inst-1',
	'sudemi_api_base'   => 'http://127.0.0.1:' . $port,
);
$logger    = new \Sudemi\Logger( $store );
$keys      = new FakeKeys();
$client    = new \Sudemi\Client( $keys, $store, $logger );
$client->setSiteUrl( 'https://staging.shop.example/' );
$queue     = new \Sudemi\Queue( $store, $client );
$pairing   = new \Sudemi\Pairing( $client, $keys, $store, $logger );
$sync      = new \Sudemi\Sync( $client, $logger, new FakeCatalogue() );
$heartbeat = new \Sudemi\Heartbeat( $store, $client, $logger, $queue, new FakeCatalogue(), $pairing, $sync );

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

function answer_with( int $status, array $body ): void {
	global $dir;
	file_put_contents( $dir . '/answer.json', json_encode( array( 'status' => $status, 'body' => $body ) ) );
}

function last_request(): array {
	global $dir;
	$lines = file( $dir . '/requests.jsonl', FILE_IGNORE_NEW_LINES ) ?: array( '{}' );
	$request = json_decode( end( $lines ), true );
	$headers = array();
	foreach ( isset( $request['headers'] ) ? $request['headers'] : array() as $name => $value ) {
		$headers[ strtolower( $name ) ] = $value;
	}
	$request['headers'] = $headers;
	return $request;
}

// ---- the header, over HTTP ----
answer_with( 200, array( 'ok' => true ) );
$response = $client->postSigned( '/integration/v1/products', array( 'products' => array() ) );
check( 'the fake sudemi answered', is_array( $response ) && 200 === $response['status'] );
$sent = last_request()['headers'];
check( 'a signed POST carries Sudemi-Site', isset( $sent['sudemi-site'] ) && 'https://staging.shop.example/' === $sent['sudemi-site'] );
check( 'next to the four signing headers', isset( $sent['sudemi-install'], $sent['sudemi-timestamp'], $sent['sudemi-nonce'], $sent['sudemi-signature'] ) );
$canonical = end( $keys->signed );
check( 'the signed string is still five lines', 5 === count( explode( "\n", $canonical ) ) );
check( 'the site is not part of the signed string', false === strpos( $canonical, 'staging.shop.example' ) );

$client->getSigned( '/integration/v1/orders/x' );
check( 'a signed GET carries Sudemi-Site', isset( last_request()['headers']['sudemi-site'] ) );

$client->sendSigned( 'POST', '/integration/v1/support/tickets', array( 'subject' => 'x' ) );
check( 'a one-shot signed call carries Sudemi-Site', isset( last_request()['headers']['sudemi-site'] ) );

$client->postUnsigned( '/integration/v1/pair', array( 'shopUrl' => 'x' ) );
check( 'an unsigned call (pairing) does not', ! isset( last_request()['headers']['sudemi-site'] ) );

$client->setSiteUrl( "https://shop.example/\r\nX-Evil: 1" );
$headers = $client->signedHeaders( 'POST', '/x', '{}' );
check( 'line breaks never reach the header', 1 === count( preg_grep( '/^Sudemi-Site: https:\/\/shop\.example\/X-Evil: 1$/', $headers ) ) );
$client->setSiteUrl( 'https://staging.shop.example/' );

// ---- reading the refusal ----
$message = 'This shop is connected to sudemi as https://shop.example.com, but this request came from https://staging.example.com. Requests from other sites (a staging copy, a restored backup) are refused. If this is a copy, disconnect sudemi here. If the shop moved, connect it again.';
check( '409 site_mismatch names the connected address', 'https://shop.example.com' === \Sudemi\Client::siteMismatch( array( 'status' => 409, 'body' => array( 'error' => 'site_mismatch', 'message' => $message ) ) ) );
check( 'an unreadable message still counts, without an address', '' === \Sudemi\Client::siteMismatch( array( 'status' => 409, 'body' => array( 'error' => 'site_mismatch' ) ) ) );
check( 'another 409 is not a site mismatch', null === \Sudemi\Client::siteMismatch( array( 'status' => 409, 'body' => array( 'error' => 'conflict' ) ) ) );
check( 'no answer is not a site mismatch', null === \Sudemi\Client::siteMismatch( null ) );

// ---- the heartbeat ----
answer_with( 409, array( 'error' => 'site_mismatch', 'message' => $message ) );
check( 'heartbeat: sudemi answered', true === $heartbeat->run() );
$status = $heartbeat->status();
check( 'heartbeat: the beat carried Sudemi-Site', isset( last_request()['headers']['sudemi-site'] ) );
check( 'heartbeat: reachable but not connected', true === $status['reachable'] && false === $status['connected'] );
check( 'heartbeat: the problem and the connected address are recorded', 'site_mismatch' === $status['problem'] && 'https://shop.example.com' === $status['paired'] );

answer_with( 401, array( 'error' => 'not_authenticated' ) );
$heartbeat->run();
$status = $heartbeat->status();
check( 'heartbeat: a 401 is still "not recognised", not a site mismatch', false === $status['connected'] && ! isset( $status['problem'] ) );

answer_with( 200, array( 'catalogue_listed' => true ) );
$heartbeat->run();
$status = $heartbeat->status();
check( 'heartbeat: the next good beat clears it', true === $status['connected'] && ! isset( $status['problem'] ) );

// ---- the admin page's wording, in all twelve languages ----
foreach ( array( 'en-gb', 'nl-nl', 'tr-tr', 'de-de', 'fr-fr', 'es-es', 'it-it', 'pl-pl', 'ru-ru', 'zh-cn', 'ar', 'he-il' ) as $lang ) {
	$_ = array();
	include __DIR__ . '/../admin/language/' . $lang . '/module/sudemi.php';
	check( "$lang: the message with the address has one %s", isset( $_['text_site_mismatch_at'] ) && 1 === substr_count( $_['text_site_mismatch_at'], '%s' ) );
	check( "$lang: the message without the address has none", isset( $_['text_site_mismatch'] ) && '' !== $_['text_site_mismatch'] && false === strpos( $_['text_site_mismatch'], '%s' ) );
}
$_ = array();
include __DIR__ . '/../admin/language/en-gb/module/sudemi.php';
check( 'en-gb says what to do with a copy', false !== strpos( $_['text_site_mismatch'], 'disconnect sudemi here' ) );

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
