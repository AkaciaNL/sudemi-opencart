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
	public function write( $key, $value ) {
		$this->data[ $key ] = $value;
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
