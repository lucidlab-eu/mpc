<?php
declare(strict_types=1);
/**
 * Standalone regression test: php tests/crawler-redirects.php
 *
 * Redirects are followed one hop at a time by the crawler: same-site hops are followed,
 * a redirect that ends in a 404 is reported with its target, loops stop after five hops,
 * redirects to private addresses are never requested and HEAD falls back to GET.
 */
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value, ...$args ) { return $value; } }
if ( ! class_exists( 'LucidLab_MPC_Helpers', false ) ) { class LucidLab_MPC_Helpers { public static function either( $value, $fallback ) { return $value ? $value : $fallback; } } }

define( 'ABSPATH', __DIR__ . '/../' );
function __( string $message, string $domain = '' ): string { return $message; }
function home_url( string $path = '/' ): string { return 'https://site.test' . $path; }
class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}
	public function get_error_message(): string { return $this->message; }
}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( array $response ): int { return $response['code']; }
function wp_remote_retrieve_header( array $response, string $name ): string { return $response['headers'][ $name ] ?? ''; }
function wp_remote_retrieve_body( array $response ): string { return $response['body'] ?? ''; }
function wp_http_validate_url( string $url ) { return str_contains( $url, '127.0.0.1' ) || str_contains( $url, '192.168.' ) ? false : $url; }

/** Test double for the parallel transport: answers from a map of address => response. */
final class LucidLab_MPC_Crawl_Http {
	public static array $routes = array();
	public static array $log    = array();
	public static function send( array $requests ): array {
		$out = array();
		foreach ( $requests as $key => $request ) {
			self::$log[] = $request['method'] . ' ' . $request['url'] . ( $request['safe'] ? ' safe' : '' );
			$route       = self::$routes[ $request['method'] . ' ' . $request['url'] ] ?? self::$routes[ $request['url'] ] ?? array( 'code' => 404 );
			$out[ $key ] = 'timeout' === $route ? new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) : $route + array( 'headers' => array() );
		}
		return $out;
	}
	public static function is_timeout( WP_Error $error ): bool { return str_contains( $error->get_error_message(), 'timed out' ); }
}
require __DIR__ . '/../includes/class-lucidlab-mpc-crawler.php';

$check = static function ( array $urls, string $method = 'HEAD' ): array {
	$jobs = array();
	foreach ( $urls as $url ) {
		$jobs[ $url ] = array( 'url' => $url, 'method' => $method, 'limit' => 4096, 'timeout' => 3 );
	}
	$state  = array();
	$method = new ReflectionMethod( LucidLab_MPC_Crawler::class, 'check_urls' );
	return $method->invokeArgs( null, array( $jobs, &$state ) );
};
$fail = static function ( string $what, $got = null ): void {
	fwrite( STDERR, 'FAIL: ' . $what . ( null === $got ? '' : ' ' . json_encode( $got ) ) . "\n" );
	exit( 1 );
};
$redirect = static fn ( string $to, int $code = 301 ): array => array( 'code' => $code, 'headers' => array( 'location' => $to ) );

LucidLab_MPC_Crawl_Http::$routes = array(
	'https://site.test/old'          => $redirect( '/new' ),
	'https://site.test/new'          => array( 'code' => 200 ),
	'https://site.test/go-missing'   => $redirect( '/gone.pdf', 302 ),
	'https://site.test/gone.pdf'     => array( 'code' => 404 ),
	'https://site.test/loop-a'       => $redirect( '/loop-b' ),
	'https://site.test/loop-b'       => $redirect( '/loop-a' ),
	'https://site.test/private'      => $redirect( 'http://127.0.0.1/admin' ),
	'https://site.test/members'      => $redirect( 'https://site.test/wp-login.php?redirect_to=x' ),
	'https://site.test/shop-out'     => $redirect( 'https://other.test/landing' ),
	'HEAD https://site.test/no-head' => array( 'code' => 405 ),
	'GET https://site.test/no-head'  => array( 'code' => 200 ),
	'https://site.test/slow.jpg'     => 'timeout',
	'https://other.test/a'           => $redirect( 'http://192.168.1.1/' ),
);
$urls = array( 'https://site.test/old', 'https://site.test/go-missing', 'https://site.test/loop-a', 'https://site.test/private', 'https://site.test/members', 'https://site.test/shop-out', 'https://site.test/no-head', 'https://site.test/slow.jpg', 'https://site.test/missing.png', 'https://other.test/a' );
$r    = $check( $urls );
$log  = LucidLab_MPC_Crawl_Http::$log;

if ( 200 !== $r['https://site.test/old']['c'] || 'https://site.test/new' !== ( $r['https://site.test/old']['f'] ?? '' ) || '' !== LucidLab_MPC_Crawler::verdict( $r['https://site.test/old'], false ) ) {
	$fail( 'same-site redirect to a working page', $r['https://site.test/old'] );
}
if ( 404 !== $r['https://site.test/go-missing']['c'] || 'https://site.test/gone.pdf' !== ( $r['https://site.test/go-missing']['f'] ?? '' ) || 'broken' !== LucidLab_MPC_Crawler::verdict( $r['https://site.test/go-missing'], false ) ) {
	$fail( 'redirect to a 404 must be broken, with its target', $r['https://site.test/go-missing'] );
}
if ( 'loop' !== ( $r['https://site.test/loop-a']['e'] ?? '' ) || 'broken' !== LucidLab_MPC_Crawler::verdict( $r['https://site.test/loop-a'], false ) ) {
	$fail( 'redirect loop', $r['https://site.test/loop-a'] );
}
if ( count( array_filter( $log, static fn ( $l ) => str_contains( $l, 'loop-' ) ) ) > LucidLab_MPC_Crawler::MAX_HOPS + 1 ) {
	$fail( 'loop requested more than MAX_HOPS + 1 times', $log );
}
if ( 'offsite' !== ( $r['https://site.test/private']['e'] ?? '' ) || 'unsafe' !== ( $r['https://other.test/a']['e'] ?? '' ) ) {
	$fail( 'redirect to a private address', array( $r['https://site.test/private'], $r['https://other.test/a'] ) );
}
foreach ( $log as $line ) {
	if ( str_contains( $line, '127.0.0.1' ) || str_contains( $line, '192.168.' ) ) {
		$fail( 'private address was requested', $line );
	}
	if ( str_contains( $line, 'other.test' ) && ! str_contains( $line, ' safe' ) ) {
		$fail( 'other-site request without the safe checks', $line );
	}
}
if ( 'private' !== ( $r['https://site.test/members']['e'] ?? '' ) || 'inconclusive' !== LucidLab_MPC_Crawler::verdict( $r['https://site.test/members'], false ) ) {
	$fail( 'login redirect is inconclusive, not broken', $r['https://site.test/members'] );
}
if ( 'offsite' !== ( $r['https://site.test/shop-out']['e'] ?? '' ) || in_array( 'HEAD https://other.test/landing safe', $log, true ) ) {
	$fail( 'same-site link sending visitors elsewhere is not followed', $r['https://site.test/shop-out'] );
}
if ( 200 !== $r['https://site.test/no-head']['c'] || ! in_array( 'GET https://site.test/no-head', $log, true ) ) {
	$fail( 'HEAD refused (405) must be retried with GET', $r['https://site.test/no-head'] );
}
if ( 'timeout' !== ( $r['https://site.test/slow.jpg']['e'] ?? '' ) || 'broken' !== LucidLab_MPC_Crawler::verdict( $r['https://site.test/slow.jpg'], false ) ) {
	$fail( 'timeout', $r['https://site.test/slow.jpg'] );
}
if ( 404 !== $r['https://site.test/missing.png']['c'] || in_array( 'GET https://site.test/missing.png', $log, true ) ) {
	$fail( 'a same-site 404 on HEAD is final (no GET retry)', $log );
}

// Verdicts for other sites are lenient: bot blocks are not broken links.
$cases = array(
	array( array( 'c' => 403 ), true, 'inconclusive' ),
	array( array( 'c' => 999 ), true, 'inconclusive' ),
	array( array( 'c' => 429 ), true, 'inconclusive' ),
	array( array( 'c' => 503 ), true, 'inconclusive' ),
	array( array( 'c' => 404 ), true, 'broken' ),
	array( array( 'c' => 410 ), true, 'broken' ),
	array( array( 'c' => 500 ), true, 'broken' ),
	array( array( 'c' => 0, 'e' => 'failed' ), true, 'broken' ),
	array( array( 'c' => 0, 'e' => 'failed' ), false, 'inconclusive' ),
	array( array( 'c' => 403 ), false, 'broken' ),
	array( array( 'c' => 503 ), false, 'inconclusive' ),
	array( array( 'c' => 500 ), false, 'broken' ),
	array( array( 'c' => 301, 'e' => 'offsite' ), false, '' ),
	array( array( 'c' => 204 ), false, '' ),
);
foreach ( $cases as $case ) {
	if ( $case[2] !== LucidLab_MPC_Crawler::verdict( $case[0], $case[1] ) ) {
		$fail( 'verdict', $case );
	}
}

// GET keeps the body (pages are read for their items), HEAD does not.
LucidLab_MPC_Crawl_Http::$routes = array( 'https://site.test/page/' => array( 'code' => 200, 'body' => '<img src="/a.png">' ) );
$r = $check( array( 'https://site.test/page/' ), 'GET' );
if ( '<img src="/a.png">' !== ( $r['https://site.test/page/']['b'] ?? '' ) ) {
	$fail( 'page body', $r );
}
echo "Crawler redirects: same-site hops, redirect to 404, loops, private targets, login, offsite, HEAD fallback, timeouts and verdicts OK.\n";
