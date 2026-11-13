<?php
/**
 * 1.4.0: statistics, the products on sudemi, the admin texts.
 *
 * - Insights: the period, a partial or malformed answer from sudemi, the listing reasons, the
 *   chart (escaped, every day drawn, a round scale), product ids.
 * - Every admin text of the English file exists in all twelve languages with the same
 *   placeholders, and no text uses the long dash.
 *
 * Runs without OpenCart.
 */

declare(strict_types=1);

require_once __DIR__ . '/../system/library/sudemi/insights.php';

use Sudemi\Insights;

$failures = 0;

function check( string $what, bool $passed, $got = null ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, $passed ? '' : ' got ' . var_export( $got, true ), "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

// ---- Period.
check( 'period 7 is kept', 7 === Insights::period( '7' ) );
check( 'period 90 is kept', 90 === Insights::period( 90 ) );
check( 'an unknown period is 30', 30 === Insights::period( '45' ) );
check( 'no period is 30', 30 === Insights::period( null ) );
check( 'a period that is not a number is 30', 30 === Insights::period( '7; drop' ) );
$now = gmmktime( 12, 0, 0, 11, 13, 2026 );
check( '30 days end today and start 29 days back', array( '2026-10-15', '2026-11-13' ) === Insights::range( 30, $now ), Insights::range( 30, $now ) );
check( '7 days', array( '2026-11-07', '2026-11-13' ) === Insights::range( 7, $now ) );

// ---- Stats: missing and malformed parts become zero or empty.
$s = Insights::stats( null );
check( 'no answer: every total is 0', 0 === array_sum( $s['totals'] ) && 11 === count( $s['totals'] ) );
check( 'no answer: currency EUR, nothing listed', 'EUR' === $s['currency'] && array() === $s['daily'] && 0 === $s['listings']['total'] );
$s = Insights::stats( array(
	'currency' => 'PLN',
	'totals' => array( 'impressions' => '120', 'orders' => 3, 'sales_minor' => 4590, 'conversion_bps' => 643, 'net_minor' => 'x' ),
	'daily' => array(
		array( 'day' => '2026-11-02', 'impressions' => 5 ),
		array( 'day' => '2026-11-01', 'impressions' => 7, 'orders' => 1 ),
		array( 'day' => 'yesterday', 'impressions' => 9 ),
		'nonsense',
	),
	'top_products' => array( array( 'external_id' => 53, 'title' => '<b>Tent</b>', 'orders' => 2 ), 'x' ),
	'orders_by_status' => array( 'PAID' => 2, 'DELIVERED' => 5, 'bad status' => 1, 'SHIPPED' => 0 ),
	'countries' => array( array( 'country' => 'DE', 'orders' => 2, 'sales_minor' => 3000 ), array( 'country' => 'Germany' ) ),
	'listings' => array( 'total' => 14, 'listed' => 13, 'not_listed' => 1 ),
) );
check( 'totals read as integers, a bad one is 0', 120 === $s['totals']['impressions'] && 0 === $s['totals']['net_minor'] && 643 === $s['totals']['conversion_bps'] );
check( 'currency from the answer', 'PLN' === $s['currency'] );
check( 'daily: bad days dropped, sorted by day', 2 === count( $s['daily'] ) && '2026-11-01' === $s['daily'][0]['day'] && 0 === $s['daily'][1]['orders'], $s['daily'] );
check( 'top products: external id as text, title kept as text (escaped by the page)', 1 === count( $s['top_products'] ) && '53' === $s['top_products'][0]['external_id'] && '<b>Tent</b>' === $s['top_products'][0]['title'] );
check( 'statuses: only known-looking codes with a count, largest first', array( 'DELIVERED' => 5, 'PAID' => 2 ) === $s['orders_by_status'], $s['orders_by_status'] );
check( 'countries: only ISO codes', 1 === count( $s['countries'] ) && 'DE' === $s['countries'][0]['country'] );
check( 'listings summary', 13 === $s['listings']['listed'] && 1 === $s['listings']['not_listed'] );

// ---- Listings.
$l = Insights::listing( array( 'external_id' => '53', 'listed' => false, 'enabled' => false, 'not_listed_reasons' => array( 'disabled_by_shop', 'something_new' ), 'price_minor' => 14995 ) );
check( 'a listing: known reason kept, unknown one becomes "other"', array( 'disabled_by_shop', 'other' ) === $l['reasons'], $l['reasons'] );
check( 'a listing: switched off', false === $l['enabled'] && false === $l['listed'] && 14995 === $l['price_minor'] );
$l = Insights::listing( array( 'external_id' => '54', 'listed' => false ) );
check( 'not listed without a reason still says why ("other")', array( 'other' ) === $l['reasons'] );
$l = Insights::listing( array( 'external_id' => '55', 'listed' => true ) );
check( 'listed: no reasons, enabled when sudemi does not say', array() === $l['reasons'] && true === $l['enabled'] );
check( 'every reason of protocol 4e is known', 7 === count( Insights::REASONS ) && in_array( 'not_in_shop', Insights::REASONS, true ) );

// ---- Product ids.
check( 'a numeric external id is an OpenCart product', 53 === Insights::productId( '53' ) );
check( 'anything else is not', 0 === Insights::productId( '53-2' ) && 0 === Insights::productId( '0' ) && 0 === Insights::productId( '' ) );

// ---- Numbers.
check( 'conversion from basis points', '6.4' === Insights::conversion( 643 ) && '0.0' === Insights::conversion( 0 ) );
check( 'counts with separators', '12,345' === Insights::count( 12345 ) );
check( 'nice scale', 4 === Insights::niceMax( 0 ) && 5 === Insights::niceMax( 4 ) && 20000 === Insights::niceMax( 13980 ) && 250 === Insights::niceMax( 210 ) && 100 === Insights::niceMax( 100 ) );

// ---- Chart.
$daily = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$daily[] = array( 'day' => sprintf( '2026-10-%02d', $i ), 'impressions' => $i * 3, 'detail_views' => 0, 'cart_adds' => 0, 'orders' => 0, 'sales_minor' => 0 );
}
$svg = Insights::chart( $daily, 'impressions', function ( $v ) { return (string) $v; }, 'Shown <in> searches' );
check( 'chart: an SVG with its accessible name escaped', 0 === strpos( $svg, '<svg' ) && false !== strpos( $svg, 'aria-label="Shown &lt;in&gt; searches"' ) );
check( 'chart: one dot per day', 30 === substr_count( $svg, '<circle' ) );
check( 'chart: a tooltip names the day and the value', false !== strpos( $svg, '<title>2026-10-30: 90</title>' ) );
check( 'chart: the scale tops at a round number', false !== strpos( $svg, '>100</text>' ) );
check( 'chart: first and last date on the axis', false !== strpos( $svg, '>10-01</text>' ) && false !== strpos( $svg, '>10-30</text>' ) );
$svg = Insights::chart( array(), 'sales_minor', function ( $v ) { return $v . ' EUR'; }, 'x' );
check( 'chart: no days still draws an empty frame', 0 === substr_count( $svg, '<circle' ) && false !== strpos( $svg, '</svg>' ) );
$svg = Insights::chart( array( array( 'day' => '2026-11-01', 'orders' => 2 ) ), 'nonsense', function ( $v ) { return '<x>'; }, 'x' );
check( 'chart: an unknown metric falls back, labels are escaped', false === strpos( $svg, '<x>' ) && false !== strpos( $svg, '&lt;x&gt;' ) );

// ---- Admin texts in all twelve languages.
$en = array();
$_ = array();
include __DIR__ . '/../admin/language/en-gb/module/sudemi.php';
$en = $_;
$placeholders = function ( $text ) {
	preg_match_all( '/%(?:\d+\$)?[sd]/', (string) $text, $m );
	sort( $m[0] );
	return $m[0];
};
$languages = glob( __DIR__ . '/../admin/language/*/module/sudemi.php' );
check( 'twelve admin languages', 12 === count( $languages ), count( $languages ) );
foreach ( $languages as $file ) {
	$code = basename( dirname( dirname( $file ) ) );
	$_ = array();
	include $file;
	$missing = array_diff( array_keys( $en ), array_keys( $_ ) );
	check( "$code has every English text", array() === $missing, array_slice( $missing, 0, 5 ) );
	$wrong = array();
	foreach ( $en as $key => $text ) {
		if ( isset( $_[ $key ] ) && $placeholders( $text ) !== $placeholders( $_[ $key ] ) ) {
			$wrong[] = $key;
		}
	}
	check( "$code keeps every placeholder", array() === $wrong, $wrong );
	$dash = array();
	foreach ( $_ as $key => $text ) {
		if ( false !== strpos( (string) $text, "\u{2014}" ) ) {
			$dash[] = $key;
		}
	}
	check( "$code has no long dash", array() === $dash, $dash );
	foreach ( array( 'text_menu_dashboard', 'text_kpi_impressions', 'text_reason_not_in_shop', 'text_profile_title', 'text_short_status_paid' ) as $key ) {
		if ( isset( $_[ $key ] ) && 'en-gb' !== $code && $_[ $key ] === $en[ $key ] && ! in_array( $key, array( 'text_menu_dashboard' ), true ) ) {
			check( "$code translates $key", false, $_[ $key ] );
		}
	}
}

echo $failures ? "\n$failures check(s) failed\n" : "\nall insights checks passed\n";
exit( $failures ? 1 : 0 );
