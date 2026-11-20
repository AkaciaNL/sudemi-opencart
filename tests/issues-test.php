<?php
/**
 * 1.4.4 (owner 2026-11-19: "we need to know what we are failing"): what the extension left out
 * is sent to sudemi as the push's top-level `issues` [{externalId, code, severity, field,
 * message, details}] and kept with sudemi's answer for the extension page; every code has plain
 * words in all twelve languages.
 *
 * Runs without OpenCart.
 */

declare(strict_types=1);

require __DIR__ . '/../system/library/sudemi/issues.php';

$failures = 0;

function check( string $what, bool $passed, $got = null ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, $passed ? '' : ' got ' . var_export( $got, true ), "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

class MemoryStore {
	public $data = array();
	public function read( $key ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : null;
	}
	public $fail = false;
	public function write( $key, $value ) {
		if ( $this->fail ) {
			throw new RuntimeException( 'database down' );
		}
		$this->data[ $key ] = $value;
	}
	public function delete( $key ) {
		unset( $this->data[ $key ] );
	}
}
class FakeClient {
	public $calls = array();
	public $status = 200;
	public function postSigned( $path, array $body ) {
		$this->calls[] = array( $path, $body );
		return array( 'status' => $this->status, 'body' => array() );
	}
}

$i = \Sudemi\Issues::make( 42, \Sudemi\Issues::VARIANT_CAP, 'warning', 'options', 'capped', array( 'combinations' => 120 ) );
check( 'the wire shape uses the contract names', array( 'externalId', 'code', 'severity', 'field', 'message', 'details' ) === array_keys( $i ) );
check( 'externalId is a string, details an object', '42' === $i['externalId'] && '{"combinations":120}' === json_encode( $i['details'] ) );
$file = \Sudemi\Issues::forProblem( 42, array( 'option' => 'File', 'type' => 'file', 'reason' => 'type' ) );
check( 'a required file option: required_file_upload, error, the option named', 'required_file_upload' === $file['code'] && 'error' === $file['severity'] && 'File' === $file['field'] );
check( 'a required option without values: option_without_values', 'option_without_values' === \Sudemi\Issues::forProblem( 7, array( 'option' => 'Size', 'type' => 'select', 'reason' => 'empty' ) )['code'] );
$body = \Sudemi\Issues::attach( array( 'products' => array() ), array( $file ) );
check( 'the push carries issues at the top level', isset( $body['issues'] ) && 1 === count( $body['issues'] ) );
check( 'no issues: no field', ! array_key_exists( 'issues', \Sudemi\Issues::attach( array( 'products' => array() ), array() ) ) );

$store = new MemoryStore();
\Sudemi\Issues::remember( $store, array( array( 'externalId' => '30:1' ) ), array( $file ), array( 'rejected' => array( array( 'externalId' => '30:1', 'reason' => 'price is 0' ) ) ), true );
$kept = \Sudemi\Issues::all( $store );
check( 'kept: the extension\'s issue and sudemi\'s refusal', 2 === count( $kept ) && 'sudemi' === $kept[1]['source'] && 'rejected' === $kept[1]['code'] );
\Sudemi\Issues::remember( $store, array( array( 'externalId' => '30:1' ) ), array(), array() );
check( 'a later push of 30:1 without problems clears its entry, 42 stays', array( '42' ) === array_map( function ( $k ) { return $k['externalId']; }, \Sudemi\Issues::all( $store ) ) );
check( 'the product of an external id', 30 === \Sudemi\Issues::productId( '30:12-14' ) && 42 === \Sudemi\Issues::productId( '42' ) );

// Kept list: invalid UTF-8 never empties it, ASCII JSON, capped by bytes, a failed write keeps it.
$s2 = new MemoryStore();
check( 'remember with invalid UTF-8 and Hebrew: saved', \Sudemi\Issues::remember( $s2, array(), array( \Sudemi\Issues::make( 1, 'missing_price', 'error', 'price', "bad \xC3\x28 מחיר <b>" ) ), null, true ) && 1 === count( \Sudemi\Issues::all( $s2 ) ) && false !== strpos( \Sudemi\Issues::all( $s2 )[0]['message'], 'מחיר <b>' ) );
check( 'stored as ASCII (any database character set)', 1 !== preg_match( '/[\x80-\xff]/', (string) $s2->data['sudemi_catalogue_issues'] ) );
$many = array();
for ( $n = 0; $n < 2000; $n++ ) {
	$many[] = array( 'externalId' => (string) $n, 'source' => 'extension', 'code' => 'variant_cap', 'severity' => 'warning', 'field' => 'options', 'message' => str_repeat( 'x', 60 ), 'at' => 1 );
}
$json = \Sudemi\Issues::encode( $many );
$back = json_decode( (string) $json, true );
check( 'encode: capped at 50 KB, newest kept, never empty', strlen( (string) $json ) <= \Sudemi\Issues::MAX_BYTES && count( $back ) > 100 && '1999' === end( $back )['externalId'], strlen( (string) $json ) );
$s2->fail = true;
check( 'a failed write is reported and the list stays as it was', false === \Sudemi\Issues::remember( $s2, array(), array( \Sudemi\Issues::make( 2, 'missing_price', 'error', 'price', 'm' ) ), null, true ) && 1 === count( \Sudemi\Issues::all( $s2 ) ) );
$s2->fail = false;

// Issues of a product not listed: queued (no request while saving), sent once by the heartbeat.
$s3 = new MemoryStore();
$cl = new FakeClient();
\Sudemi\Issues::queue( $s3, array( \Sudemi\Issues::make( 11, 'missing_price', 'error', 'price', 'a' ) ) );
\Sudemi\Issues::queue( $s3, array( \Sudemi\Issues::make( '12:3', 'variant_cap', 'warning', 'options', 'b' ), \Sudemi\Issues::make( '12:3', 'missing_price', 'error', 'price', 'c' ) ) );
check( 'queue: no request, shown on the page at once', 0 === count( $cl->calls ) && 3 === count( \Sudemi\Issues::all( $s3 ) ) );
check( 'queued: whole products up to the limit', array( 11 ) === array_keys( \Sudemi\Issues::queued( $s3, 2 ) ) );
$cl->status = 503;
check( 'drain on failure: one request, kept', false === \Sudemi\Issues::drain( $s3, $cl ) && 1 === count( $cl->calls ) && 3 === count( \Sudemi\Issues::flat( \Sudemi\Issues::queued( $s3 ) ) ) );
$cl->status = 200;
$ok   = \Sudemi\Issues::drain( $s3, $cl );
$sent = $cl->calls[1][1];
check( 'drain: one request with every waiting issue, no products, details as objects', $ok && 3 === count( $sent['issues'] ) && array() === $sent['products'] && '{}' === json_encode( $sent['issues'][0]['details'] ), $sent );
check( 'drain: nothing left, nothing more sent', array() === \Sudemi\Issues::queued( $s3 ) && false === \Sudemi\Issues::drain( $s3, $cl ) && 2 === count( $cl->calls ) );
\Sudemi\Issues::queue( $s3, array( \Sudemi\Issues::make( 13, 'missing_price', 'error', 'price', 'd' ) ) );
\Sudemi\Issues::unqueue( $s3, array( '13' ) );
check( 'unqueue: a newer push of the product drops what waited', array() === \Sudemi\Issues::queued( $s3 ) );

foreach ( glob( __DIR__ . '/../admin/language/*/module/sudemi.php' ) as $f ) {
	$_    = array();
	$lang = basename( dirname( dirname( $f ) ) );
	include $f;
	$missing = array();
	foreach ( array_merge( array( 'issues_title', 'issues_intro', 'issues_product', 'issues_what', 'issues_details', 'issue_not_listed', 'issue_partly', 'issue_other' ), array_map( function ( $c ) { return 'issue_' . $c; }, \Sudemi\Issues::CODES ) ) as $key ) {
		if ( empty( $_[ 'text_' . $key ] ) || false !== strpos( $_[ 'text_' . $key ], "\u{2014}" ) ) {
			$missing[] = $key;
		}
	}
	check( "$lang: every issue text present, no long dash", array() === $missing, $missing );
}

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
