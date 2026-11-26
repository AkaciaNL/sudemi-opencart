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

// 1.4.10 (sudemi owner decision 2026-11-26): product descriptions go as HTML; sudemi keeps the
// paragraphs, lists and headings and removes everything else itself.
$rich   = '<h2>Care</h2><p>Salt &amp; Pepper <strong>mill</strong>&nbsp;set</p><ul><li>Steel</li><li>2 &lt; 3</li></ul>';
$stored = htmlspecialchars( $rich, ENT_COMPAT, 'UTF-8' );
check( 'a stored description is sent as the editor\'s HTML (escaping undone once)', $rich === Catalogue::html( $stored ), Catalogue::html( $stored ) );
check( 'a description stored unescaped (by an import) is sent as it is', $rich === Catalogue::html( ' ' . $rich . "\n" ), Catalogue::html( $rich ) );
check( 'an escaped plain text keeps its entities (sudemi reads them as HTML)', 'Salt &amp; Pepper' === Catalogue::html( 'Salt &amp; Pepper' ) );
check( 'an empty description stays empty', '' === Catalogue::html( null ) );
$source = (string) file_get_contents( __DIR__ . '/../system/library/sudemi/catalogue.php' );
check( 'the listing sends html(), category descriptions stay plain()',
	1 === substr_count( $source, "'description' => self::html(\$row['description'])" )
	&& 1 === substr_count( $source, "'description' => self::plain(\$row['description'])" ) );

// The version, everywhere it is said.
$install = json_decode( (string) file_get_contents( __DIR__ . '/../install.json' ), true );
check( 'install.json: version 1.4.10', '1.4.10' === $install['version'], $install['version'] );
check( 'Client::VERSION 1.4.10', '1.4.10' === \Sudemi\Client::VERSION, \Sudemi\Client::VERSION );
check( 'CHANGELOG.md starts with 1.4.10', 1 === preg_match( '/^# Changelog\s+## 1\.4\.10\n/', (string) file_get_contents( __DIR__ . '/../CHANGELOG.md' ) ) );

// The upgrade to 1.4.10 sends the whole catalogue once more (descriptions as HTML); a first install does not ask.
final class FakeDb {
	public $queries = array();
	public function query( $sql ) { $this->queries[] = $sql; return (object) array( 'row' => array( 'event_id' => 1 ), 'rows' => array() ); }
	public function escape( $value ) { return addslashes( (string) $value ); }
}
final class FakeStore {
	public $values = array();
	public function read( $key ) { return isset( $this->values[ $key ] ) ? $this->values[ $key ] : null; }
	public function write( $key, $value ) { $this->values[ $key ] = $value; }
}
$upgraded = new FakeStore();
$upgraded->values[ \Sudemi\Withdrawal::SETTING_SCHEMA ] = '1.4.7-1';
( new \Sudemi\Withdrawal( new FakeDb() ) )->ensureSchema( $upgraded );
check( 'upgrade from 1.4.9: a full sync is asked for and the schema is current',
	1 === $upgraded->read( \Sudemi\Withdrawal::SETTING_RESYNC_DUE ) && '1.4.10-1' === $upgraded->read( \Sudemi\Withdrawal::SETTING_SCHEMA ), $upgraded->values );
$fresh = new FakeStore();
( new \Sudemi\Withdrawal( new FakeDb() ) )->ensureSchema( $fresh, true );
check( 'a first install asks for no extra sync', null === $fresh->read( \Sudemi\Withdrawal::SETTING_RESYNC_DUE ), $fresh->values );
$current = new FakeStore();
$current->values[ \Sudemi\Withdrawal::SETTING_SCHEMA ] = '1.4.10-1';
( new \Sudemi\Withdrawal( new FakeDb() ) )->ensureSchema( $current );
check( 'already on 1.4.10: nothing again', null === $current->read( \Sudemi\Withdrawal::SETTING_RESYNC_DUE ), $current->values );

if ( $failures > 0 ) {
	echo "\n", $failures, " check(s) failed\n";
	exit( 1 );
}
echo "\nall text checks passed\n";
