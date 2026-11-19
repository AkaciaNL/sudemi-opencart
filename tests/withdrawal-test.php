<?php
/**
 * The right-of-withdrawal exclusion: only the seven art. 16 reasons get through, anything else
 * means "normal right of withdrawal", and the product payload carries the field only when set.
 *
 * Runs without OpenCart: the two functions under test are pure.
 */

declare(strict_types=1);

// The library has no entry guard; nothing to define.
require __DIR__ . '/../system/library/sudemi/withdrawal.php';

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

foreach ( array( 'hygiene', 'custom', 'perishable', 'media', 'press', 'mixed', 'alcohol' ) as $reason ) {
	check( "'$reason' is accepted", $reason === \Sudemi\Withdrawal::sanitize( $reason ) );
}
check( 'case and surrounding spaces are normalised', 'custom' === \Sudemi\Withdrawal::sanitize( '  Custom ' ) );
foreach ( array( '', 'none', 'other', 'custom; DROP TABLE', '<script>', 'hygiene ', null, 1, array( 'custom' ), true ) as $bad ) {
	$label = is_string( $bad ) ? "'" . $bad . "'" : gettype( $bad );
	if ( 'hygiene ' === $bad ) {
		check( "$label (trailing space) is trimmed to hygiene", 'hygiene' === \Sudemi\Withdrawal::sanitize( $bad ) );
		continue;
	}
	check( "$label is not an exclusion", null === \Sudemi\Withdrawal::sanitize( $bad ) );
}

$product = array( 'externalId' => '42', 'priceMinor' => 1999, 'maxHandlingTimeDays' => 2 );
$with    = \Sudemi\Withdrawal::apply( $product, 'custom' );
check( 'an exclusion is sent as withdrawalExclusion', isset( $with['withdrawalExclusion'] ) && 'custom' === $with['withdrawalExclusion'] );
check( 'the rest of the payload is untouched', 1999 === $with['priceMinor'] && 2 === $with['maxHandlingTimeDays'] );
check( 'no exclusion: the field is omitted', ! array_key_exists( 'withdrawalExclusion', \Sudemi\Withdrawal::apply( $product, null ) ) );
check( 'an empty stored value: the field is omitted', ! array_key_exists( 'withdrawalExclusion', \Sudemi\Withdrawal::apply( $product, '' ) ) );
check( 'an unknown stored value is never sent', ! array_key_exists( 'withdrawalExclusion', \Sudemi\Withdrawal::apply( $product, 'whatever' ) ) );
check( 'the JSON carries the field', false !== strpos( (string) json_encode( $with ), '"withdrawalExclusion":"custom"' ) );

// 1.4.4, owner decision 2: "Accept returns of personalised items", off by default. The posted
// checkbox (with its hidden 0) is read safely, and the label and help exist in all 12 languages.
foreach ( array( '1', 'on', 1, true, 'yes' ) as $on ) {
	check( 'checked: ' . var_export( $on, true ) . ' is on', \Sudemi\Withdrawal::checked( $on ) );
}
foreach ( array( '0', '', null, 0, false, 'off', array( '1' ) ) as $off ) {
	check( 'checked: ' . var_export( $off, true ) . ' is off', ! \Sudemi\Withdrawal::checked( $off ) );
}
foreach ( glob( __DIR__ . '/../admin/language/*/module/sudemi.php' ) as $file ) {
	$_    = array();
	$lang = basename( dirname( dirname( $file ) ) );
	include $file;
	foreach ( array( 'entry_personalisation_returnable', 'help_personalisation_returnable' ) as $key ) {
		check( "$lang: $key present", isset( $_[ $key ] ) && '' !== trim( $_[ $key ] ) );
		check( "$lang: $key without a long dash", ! isset( $_[ $key ] ) || false === strpos( $_[ $key ], "\u{2014}" ) );
	}
}
check( 'twelve languages', 12 === count( glob( __DIR__ . '/../admin/language/*/module/sudemi.php' ) ) );
$catalogue = (string) file_get_contents( __DIR__ . '/../system/library/sudemi/catalogue.php' );
check( 'the listing sends personalisationReturnable', false !== strpos( $catalogue, "\$listing['personalisationReturnable'] = !empty(\$row['personalisation_returnable']);" ) );

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
