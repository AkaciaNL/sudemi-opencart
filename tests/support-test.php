<?php
/**
 * The support-ticket client: what it sends to sudemi, what it refuses to send, and how sudemi's
 * answers (201, 400, 404, 429, nothing) come back to the admin page.
 *
 * Runs without OpenCart: the client only needs something with sendSigned(), faked here.
 */

declare(strict_types=1);

require __DIR__ . '/../system/library/sudemi/support.php';

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

/** Records every call and answers with whatever the test queued. */
class FakeClient {
	public $calls   = array();
	public $answers = array();

	public function sendSigned( $method, $path, array $body = array() ) {
		$this->calls[] = array( $method, $path, $body );

		return array_shift( $this->answers );
	}
}

const ID    = '3f2a9c1e-0b4d-4c6e-9a1b-2c3d4e5f6a7b';
const ORDER = '11111111-2222-4333-8444-555555555555';

function detail( $status = 'OPEN' ) {
	return array(
		'id'       => ID,
		'number'   => 'S-000042',
		'subject'  => 'Payout missing',
		'category' => 'PAYOUTS',
		'status'   => $status,
		'messages' => array(
			array( 'id' => ORDER, 'author' => 'CUSTOMER', 'body' => "Line one\nLine two", 'createdAt' => '2026-11-06T14:03:22.123Z' ),
		),
	);
}

// ---- open -----------------------------------------------------------------------------------
$fake            = new FakeClient();
$fake->answers[] = array( 'status' => 201, 'body' => array( 'ticket' => detail() ) );
$support         = new \Sudemi\Support( $fake );
$result          = $support->open( 'payouts', "  Payout missing \r\n", "Line one\r\nLine two\x07", ORDER, 'de-de' );
check( 'open: 201 is success with the ticket', $result['ok'] && ID === $result['data']['id'] && 'S-000042' === $result['data']['number'] );
check( 'open: one signed POST to the tickets path', 1 === count( $fake->calls ) && 'POST' === $fake->calls[0][0] && '/integration/v1/support/tickets' === $fake->calls[0][1] );
$sent = $fake->calls[0][2];
check( 'open: category upper-cased', 'PAYOUTS' === $sent['category'] );
check( 'open: subject trimmed', 'Payout missing' === $sent['subject'] );
check( 'open: line breaks kept, CRLF normalised, control characters dropped', "Line one\nLine two" === $sent['message'] );
check( 'open: orderId sent', ORDER === $sent['orderId'] );
check( 'open: locale de-de sent as de', 'de' === $sent['locale'] );
check( 'open: no other fields', array( 'category', 'subject', 'message', 'orderId', 'locale' ) === array_keys( $sent ) );

$fake            = new FakeClient();
$fake->answers[] = array( 'status' => 201, 'body' => array( 'ticket' => detail() ) );
( new \Sudemi\Support( $fake ) )->open( 'OTHER', 'Hello there', 'Hi', '', 'ja-jp' );
check( 'open: empty order id and unsupported locale are left out',
	! array_key_exists( 'orderId', $fake->calls[0][2] ) && ! array_key_exists( 'locale', $fake->calls[0][2] ) );

foreach ( array(
	'an unknown category'      => array( 'SHIPPING', 'Hello there', 'Hi', '' ),
	'a two-character subject'  => array( 'OTHER', 'Hi', 'Hi', '' ),
	'a 201-character subject'  => array( 'OTHER', str_repeat( 'a', 201 ), 'Hi', '' ),
	'an empty message'         => array( 'OTHER', 'Hello there', "  \n ", '' ),
	'a 10001-character message' => array( 'OTHER', 'Hello there', str_repeat( 'a', 10001 ), '' ),
	'an order id that is no UUID' => array( 'OTHER', 'Hello there', 'Hi', '42; DROP TABLE' ),
) as $what => $args ) {
	$fake   = new FakeClient();
	$result = ( new \Sudemi\Support( $fake ) )->open( $args[0], $args[1], $args[2], $args[3], 'en' );
	check( "open: $what is refused before anything is sent", ! $result['ok'] && 'invalid' === $result['error'] && array() === $fake->calls );
}
$fake            = new FakeClient();
$fake->answers[] = array( 'status' => 201, 'body' => array( 'ticket' => detail() ) );
check( 'open: a 200-character multibyte subject is accepted',
	( new \Sudemi\Support( $fake ) )->open( 'OTHER', str_repeat( 'ü', 200 ), str_repeat( '中', 10000 ) )['ok'] );

// ---- sudemi's answers --------------------------------------------------------------------------
foreach ( array(
	400 => 'invalid',
	404 => 'not_found',
	429 => 'too_many',
	500 => 'unavailable',
	403 => 'unavailable',
) as $status => $error ) {
	$fake            = new FakeClient();
	$fake->answers[] = array( 'status' => $status, 'body' => array( 'error' => 'x', 'message' => 'English text for logs' ) );
	$result          = ( new \Sudemi\Support( $fake ) )->open( 'ORDER', 'Hello there', 'Hi' );
	check( "open: $status becomes '$error'", ! $result['ok'] && $error === $result['error'] && null === $result['data'] );
	check( "open: $status is not retried", 1 === count( $fake->calls ) );
}
$fake            = new FakeClient();
$fake->answers[] = array( 'status' => 409, 'body' => array( 'error' => 'site_mismatch', 'message' => 'This shop is connected to sudemi as https://shop.example.com, but ...' ) );
check( 'open: 409 site_mismatch becomes site_mismatch', 'site_mismatch' === ( new \Sudemi\Support( $fake ) )->open( 'ORDER', 'Hello there', 'Hi' )['error'] );
$fake            = new FakeClient();
$fake->answers[] = array( 'status' => 409, 'body' => array( 'error' => 'conflict' ) );
check( 'open: another 409 stays unavailable', 'unavailable' === ( new \Sudemi\Support( $fake ) )->open( 'ORDER', 'Hello there', 'Hi' )['error'] );
$fake            = new FakeClient();
$fake->answers[] = null;
$result          = ( new \Sudemi\Support( $fake ) )->tickets();
check( 'not connected / unreachable (null) becomes unavailable', ! $result['ok'] && 'unavailable' === $result['error'] );
$fake            = new FakeClient();
$fake->answers[] = array( 'status' => 201, 'body' => array( 'ticket' => array( 'id' => '../../orders' ) ) );
check( 'a ticket without a valid id is not trusted', 'unavailable' === ( new \Sudemi\Support( $fake ) )->open( 'ORDER', 'Hello there', 'Hi' )['error'] );

// ---- list --------------------------------------------------------------------------------------
$fake            = new FakeClient();
$fake->answers[] = array( 'status' => 200, 'body' => array( 'tickets' => array( detail(), array( 'id' => 'nope' ), 'garbage' ) ) );
$result          = ( new \Sudemi\Support( $fake ) )->tickets();
check( 'list: a signed GET with an empty body', array( 'GET', '/integration/v1/support/tickets', array() ) === $fake->calls[0] );
check( 'list: only well-formed tickets are kept', $result['ok'] && 1 === count( $result['data'] ) && ID === $result['data'][0]['id'] );
$fake            = new FakeClient();
$fake->answers[] = array( 'status' => 200, 'body' => array() );
$result          = ( new \Sudemi\Support( $fake ) )->tickets();
check( 'list: no tickets key is an empty list', $result['ok'] && array() === $result['data'] );

// ---- one ticket, reply --------------------------------------------------------------------------
$fake            = new FakeClient();
$fake->answers[] = array( 'status' => 200, 'body' => array( 'ticket' => detail() ) );
$result          = ( new \Sudemi\Support( $fake ) )->ticket( strtoupper( ID ) );
check( 'ticket: GET by id (lower-cased) in the path', array( 'GET', '/integration/v1/support/tickets/' . ID, array() ) === $fake->calls[0] );
check( 'ticket: messages kept with line breaks', $result['ok'] && "Line one\nLine two" === $result['data']['messages'][0]['body'] );
$fake = new FakeClient();
foreach ( array( '', '../admin', ID . '/messages', 'S-000042', null ) as $bad ) {
	check( 'ticket: ' . var_export( $bad, true ) . ' never reaches a URL',
		'not_found' === ( new \Sudemi\Support( $fake ) )->ticket( $bad )['error'] && array() === $fake->calls );
}

$fake            = new FakeClient();
$fake->answers[] = array( 'status' => 200, 'body' => array( 'ticket' => detail( 'OPEN' ) ) );
$result          = ( new \Sudemi\Support( $fake ) )->reply( ID, "  Thanks!\r\nStill missing.  " );
check( 'reply: POST to .../messages with only the message',
	array( 'POST', '/integration/v1/support/tickets/' . ID . '/messages', array( 'message' => "Thanks!\nStill missing." ) ) === $fake->calls[0] );
check( 'reply: success carries the ticket', $result['ok'] && 'OPEN' === $result['data']['status'] );
$fake = new FakeClient();
check( 'reply: an empty message is refused before sending', 'invalid' === ( new \Sudemi\Support( $fake ) )->reply( ID, ' ' )['error'] && array() === $fake->calls );
check( 'reply: a bad id is refused before sending', 'not_found' === ( new \Sudemi\Support( $fake ) )->reply( 'x', 'Hi' )['error'] && array() === $fake->calls );

// ---- locale ---------------------------------------------------------------------------------------
foreach ( array( 'en-gb' => 'en', 'nl-nl' => 'nl', 'zh-CN' => 'zh', 'ar' => 'ar', 'he-il' => 'he', 'TR' => 'tr',
	'ja-jp' => null, '' => null, 'pt-br' => null ) as $in => $out ) {
	check( "locale '$in' -> " . var_export( $out, true ), $out === \Sudemi\Support::locale( (string) $in ) );
}
check( 'locale null -> null', null === \Sudemi\Support::locale( null ) );
check( 'seven categories', 7 === count( \Sudemi\Support::CATEGORIES ) );

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
