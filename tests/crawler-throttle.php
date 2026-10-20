<?php
declare(strict_types=1);
/**
 * Standalone regression test: php tests/crawler-throttle.php
 *
 * The live-page check slows down when this site answers 429 or 503: it pauses (Retry-After or
 * 60 s doubling), continues one request at a time, speeds up slowly to at most 3/4 of the refused
 * speed, never reports a 429 as broken and asks refused addresses again. The progress bar is
 * pages checked / pages listed. Links that only sort or filter a listed page need no request.
 */
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value, ...$args ) { return $value; } }
if ( ! class_exists( 'LucidLab_MPC_Helpers', false ) ) { class LucidLab_MPC_Helpers { public static function either( $value, $fallback ) { return $value ? $value : $fallback; } } }
define( 'ABSPATH', __DIR__ . '/../' );
function __( string $message, string $domain = '' ): string { return $message; }
function home_url( string $path = '/' ): string { return 'https://site.test' . $path; }
function get_option( string $name, $default = false ) { return $default; }
function wp_date( string $format, int $time ): string { return gmdate( $format, $time ); }
class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}
	public function get_error_message(): string { return $this->message; }
}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( array $response ): int { return $response['code']; }
function wp_remote_retrieve_header( array $response, string $name ): string { return $response['headers'][ $name ] ?? ''; }
function wp_remote_retrieve_body( array $response ): string { return $response['body'] ?? ''; }
function wp_http_validate_url( string $url ) { return $url; }

final class LucidLab_MPC_Crawl_Http {
	public static array $routes = array();
	public static array $log    = array();
	public static function send( array $requests ): array {
		$out = array();
		foreach ( $requests as $key => $request ) {
			self::$log[] = $request['method'] . ' ' . $request['url'] . ' ' . ( $request['agent'] ?? '' );
			$out[ $key ] = ( self::$routes[ $request['url'] ] ?? array( 'code' => 200 ) ) + array( 'headers' => array() );
		}
		return $out;
	}
	public static function is_timeout( WP_Error $error ): bool { return false; }
}
require __DIR__ . '/../includes/class-lucidlab-mpc-crawler.php';

$fail = static function ( string $what, $got = null ): void {
	fwrite( STDERR, 'FAIL: ' . $what . ( null === $got ? '' : ' ' . json_encode( $got, JSON_UNESCAPED_SLASHES ) ) . "\n" );
	exit( 1 );
};
$call = static function ( string $name, array $args ) {
	$method = new ReflectionMethod( LucidLab_MPC_Crawler::class, $name );
	return $method->invokeArgs( null, $args );
};

// Retry-After: seconds or an HTTP date, capped at one hour.
$now = 1800000000;
if ( 120 !== LucidLab_MPC_Crawler::retry_after_seconds( '120', $now ) || 90 !== LucidLab_MPC_Crawler::retry_after_seconds( gmdate( 'D, d M Y H:i:s', $now + 90 ) . ' GMT', $now ) || 0 !== LucidLab_MPC_Crawler::retry_after_seconds( 'soon', $now ) || 3600 !== LucidLab_MPC_Crawler::retry_after_seconds( '99999', $now ) ) {
	$fail( 'Retry-After parsing' );
}

// Pauses: 60 s doubling without Retry-After, at least Retry-After when it asks for more; one request at a time.
$state = array( 'throttle' => LucidLab_MPC_Crawler::fresh_throttle() );
$until = LucidLab_MPC_Crawler::throttle_back( $state, 429, 0, $now );
if ( $now + 60 !== $until || 1 !== $state['throttle']['par'] || 1.0 !== (float) $state['throttle']['rate'] || 3 !== $state['throttle']['cap_par'] || 3.0 !== (float) $state['throttle']['cap_rate'] ) {
	$fail( 'first pause', $state['throttle'] );
}
$state['throttle']['until'] = 0;
if ( $now + 120 !== LucidLab_MPC_Crawler::throttle_back( $state, 429, 0, $now ) ) {
	$fail( 'second pause doubles', $state['throttle'] );
}
if ( 3 !== $state['throttle']['cap_par'] ) {
	$fail( 'a refusal at one request at a time must not lower the ceiling again', $state['throttle'] );
}
$state['throttle']['until'] = 0;
if ( $now + 900 !== LucidLab_MPC_Crawler::throttle_back( $state, 503, 900, $now ) || '503' !== $state['throttle']['reason'] ) {
	$fail( 'Retry-After longer than the backoff is honoured', $state['throttle'] );
}
for ( $i = 0; $i < 12; $i++ ) {
	$state['throttle']['until'] = 0;
	$until                      = LucidLab_MPC_Crawler::throttle_back( $state, 429, 0, $now );
}
if ( $now + LucidLab_MPC_Crawler::PAUSE_MAX !== $until ) {
	$fail( 'backoff is capped', $until - $now );
}

// Ramp: +1 request at a time and +0.5/s per 30 quiet answers, up to 3/4 of the refused speed.
$state = array( 'throttle' => LucidLab_MPC_Crawler::fresh_throttle() );
LucidLab_MPC_Crawler::throttle_back( $state, 429, 0, $now );
LucidLab_MPC_Crawler::throttle_ramp( $state, 29 );
if ( 1 !== $state['throttle']['par'] ) {
	$fail( 'no ramp before 30 answers', $state['throttle'] );
}
LucidLab_MPC_Crawler::throttle_ramp( $state, 1 );
if ( 2 !== $state['throttle']['par'] || 1.5 !== (float) $state['throttle']['rate'] ) {
	$fail( 'ramp after 30 answers', $state['throttle'] );
}
LucidLab_MPC_Crawler::throttle_ramp( $state, 1000 );
if ( 3 !== $state['throttle']['par'] || 3.0 !== (float) $state['throttle']['rate'] || 0 !== $state['throttle']['level'] ) {
	$fail( 'ramp stops at 3/4 of the refused speed; a long quiet run resets the backoff', $state['throttle'] );
}

// A paused check sends nothing and says when it resumes.
$state = LucidLab_MPC_Crawler::initial_state( array( 'links' => true ) );
$state['phase']   = 'pages';
$state['pages']   = 4419;
$state['checked'] = 805;
if ( 18 !== $call( 'progress', array( $state ) ) ) {
	$fail( 'progress is pages checked / pages listed (805 / 4419 = 18%)', $call( 'progress', array( $state ) ) );
}
LucidLab_MPC_Crawler::throttle_back( $state, 429, 120 );
LucidLab_MPC_Crawl_Http::$log = array();
$result = LucidLab_MPC_Crawler::step( $state, 1 );
if ( LucidLab_MPC_Crawl_Http::$log || empty( $result['wait_until'] ) || ! str_contains( $result['label'], 'Paused: the server asked us to slow down (HTTP 429)' ) || ! str_contains( $result['label'], '805 / 4419' ) || 18 !== $result['progress'] ) {
	$fail( 'paused step', array( $result, LucidLab_MPC_Crawl_Http::$log ) );
}

// A 429 from this site: no GET retry, Retry-After kept, the open addresses deferred, the check paused.
LucidLab_MPC_Crawl_Http::$routes = array(
	'https://site.test/a.png' => array( 'code' => 429, 'headers' => array( 'retry-after' => '300' ) ),
	'https://other.test/x'    => array( 'code' => 429 ),
	'https://site.test/old'   => array( 'code' => 301, 'headers' => array( 'location' => '/new' ) ),
);
LucidLab_MPC_Crawl_Http::$log = array();
$state = array( 'throttle' => LucidLab_MPC_Crawler::fresh_throttle() );
$jobs  = array();
foreach ( array( 'https://site.test/a.png', 'https://site.test/old', 'https://site.test/b.png' ) as $url ) {
	$jobs[ $url ] = array( 'url' => $url, 'method' => 'HEAD', 'limit' => 4096, 'timeout' => 3, 'page' => false );
}
$r = $call( 'check_urls', array( $jobs, &$state ) );
if ( 'throttled' !== ( $r['https://site.test/a.png']['e'] ?? '' ) || 300 !== ( $r['https://site.test/a.png']['r'] ?? 0 ) || in_array( 'GET https://site.test/a.png', array_map( static fn( $l ) => substr( $l, 0, 27 ), LucidLab_MPC_Crawl_Http::$log ), true ) ) {
	$fail( 'own 429 result', array( $r, LucidLab_MPC_Crawl_Http::$log ) );
}
if ( 'deferred' !== ( $r['https://site.test/old']['e'] ?? '' ) || 200 !== ( $r['https://site.test/b.png']['c'] ?? 0 ) ) {
	$fail( 'redirect still open is deferred; answered address keeps its result', $r );
}
if ( LucidLab_MPC_Crawler::paused_until( $state ) < time() + 299 || 1 !== (int) $state['throttled'] ) {
	$fail( 'Retry-After 300 pauses the check', $state );
}
foreach ( LucidLab_MPC_Crawl_Http::$log as $line ) {
	if ( ! str_contains( $line, 'LucidLabLiveCheck/' ) ) {
		$fail( 'requests carry the recognisable user agent', $line );
	}
}
// Another site that answers 429 is inconclusive and does not pause this check.
$state = array( 'throttle' => LucidLab_MPC_Crawler::fresh_throttle() );
$r     = $call( 'check_urls', array( array( 'x' => array( 'url' => 'https://other.test/x', 'method' => 'GET', 'limit' => 4096, 'timeout' => 3, 'page' => false ) ), &$state ) );
if ( LucidLab_MPC_Crawler::paused_until( $state ) || 'inconclusive' !== LucidLab_MPC_Crawler::verdict( $r['x'], true ) ) {
	$fail( 'other-site 429', array( $r, $state['throttle'] ) );
}

// Refused addresses are asked again (MAX_RETRIES tries), deferred ones without counting; never stored as broken.
$state  = array( 'retries' => array(), 'urls' => 0, 'images' => 0 );
$status = array();
$call( 'store_results', array( array( 'https://site.test/a.png' => 'https://site.test/a.png', 'https://site.test/b.png' => 'https://site.test/b.png' ), array( 'uhttps://site.test/a.png' => array( 'c' => 429, 'e' => 'throttled' ), 'uhttps://site.test/b.png' => array( 'c' => 0, 'e' => 'deferred' ) ), &$status, &$state ) );
if ( $status || 1 !== ( $state['retries']['https://site.test/a.png'] ?? 0 ) || isset( $state['retries']['https://site.test/b.png'] ) || 0 !== $state['urls'] ) {
	$fail( 'refused and deferred addresses get no result yet', array( $status, $state ) );
}
for ( $i = 0; $i < LucidLab_MPC_Crawler::MAX_RETRIES; $i++ ) {
	$call( 'store_results', array( array( 'https://site.test/a.png' => 'https://site.test/a.png' ), array( 'uhttps://site.test/a.png' => array( 'c' => 429, 'e' => 'throttled' ) ), &$status, &$state ) );
}
if ( 429 !== ( $status['https://site.test/a.png']['c'] ?? 0 ) || 'inconclusive' !== LucidLab_MPC_Crawler::verdict( $status['https://site.test/a.png'], false ) ) {
	$fail( 'after MAX_RETRIES refusals the address is inconclusive, not broken', $status );
}

// Pages refused with 429 go back to the front of the list and are not counted as checked.
$state = array( 'retries' => array(), 'retry_rows' => array(), 'checked' => 0, 'fetched' => 2, 'page_queue' => array(), 'links' => true );
$status = array();
$rows   = array( array( 'url' => 'https://site.test/p1/', 'title' => 'P1' ), array( 'url' => 'https://site.test/p2/', 'title' => 'P2' ) );
$call( 'queue_pages', array( 1, $rows, array( 'p0' => array( 'c' => 429, 'e' => 'throttled' ), 'p1' => array( 'c' => 0, 'e' => 'deferred' ) ), &$state, &$status, 'https://site.test/' ) );
if ( 0 !== $state['checked'] || 2 !== count( $state['retry_rows'] ) || $status || $state['page_queue'] ) {
	$fail( 'refused pages are queued again', $state );
}

// Sorting, filter and paging arguments of a listed page need no request; content-selecting ones do.
$listed = array( 'site.test/shop' => 1, 'site.test/blog' => 1 );
foreach ( array( 'https://site.test/shop/?orderby=price' => true, 'https://site.test/shop/?filter_color=red&query_type_color=or' => true, 'https://site.test/blog/?p=12' => false, 'https://site.test/?product=x' => false ) as $url => $same ) {
	if ( $same !== isset( $listed[ LucidLab_MPC_Crawler::view_key( $url ) ] ) ) {
		$fail( "view key of $url", LucidLab_MPC_Crawler::view_key( $url ) );
	}
}
echo "Crawler throttle: Retry-After, pauses with backoff, one request at a time, slow ramp to 3/4 of the refused speed, 429 never broken, refused pages and addresses asked again, page-based progress and view-only links OK.\n";
