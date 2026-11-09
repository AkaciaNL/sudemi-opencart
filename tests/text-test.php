<?php
/**
 * Text sent to sudemi is text, not OpenCart's HTML-escaped storage: OpenCart's Request class runs
 * htmlspecialchars over everything typed in the admin, so names arrive as "Salt &amp; Pepper" and
 * descriptions as "&lt;p&gt;...". Catalogue::text() and Catalogue::plain() turn them back.
 *
 * Runs without OpenCart.
 */

declare(strict_types=1);

define( 'DB_PREFIX', 'oc_' );
foreach ( array( 'store', 'logger', 'keys', 'client', 'shipping', 'withdrawal', 'catalogue' ) as $file ) {
	require_once __DIR__ . '/../system/library/sudemi/' . $file . '.php';
}

use Sudemi\Catalogue;

$failures = 0;

function check( string $what, bool $passed, $got = null ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, $passed ? '' : ' got ' . var_export( $got, true ), "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

// What OpenCart stores for a name typed as: Salt & Pepper "Mill" 'n' <Co>
$stored = htmlspecialchars( 'Salt & Pepper "Mill" \'n\' <Co>', ENT_COMPAT, 'UTF-8' );
check( 'a name typed in the admin arrives as typed', 'Salt & Pepper "Mill" \'n\' <Co>' === Catalogue::text( $stored ), Catalogue::text( $stored ) );
check( 'a name with a numeric entity (&#039;) is decoded', "Rock 'n' Roll" === Catalogue::text( 'Rock &#039;n&#039; Roll' ) );
check( 'a name is trimmed', 'Kettle' === Catalogue::text( '  Kettle ' ) );
check( 'an empty name stays empty (brand null check)', '' === Catalogue::text( null ) );

// The description editor's HTML, escaped once more by OpenCart on save.
$html   = '<p>Salt &amp; Pepper <b>bold</b>&nbsp;mill</p><p>2 &lt; 3</p>';
$stored = htmlspecialchars( $html, ENT_COMPAT, 'UTF-8' );
check( 'a stored description: no tags, no entities', 'Salt & Pepper bold mill2 < 3' === Catalogue::plain( $stored ), Catalogue::plain( $stored ) );
check( 'a description stored unescaped (by an import) gives the same text', 'Salt & Pepper bold mill2 < 3' === Catalogue::plain( $html ), Catalogue::plain( $html ) );
check( 'plain text stays as it is', 'Just text.' === Catalogue::plain( 'Just text.' ) );

if ( $failures > 0 ) {
	echo "\n", $failures, " check(s) failed\n";
	exit( 1 );
}
echo "\nall text checks passed\n";
