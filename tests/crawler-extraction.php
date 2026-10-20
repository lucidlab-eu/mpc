<?php
declare(strict_types=1);
/**
 * Standalone regression test: php tests/crawler-extraction.php
 *
 * The live-page check finds everything a page loads or links to and gives it the right type.
 */
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value, ...$args ) { return $value; } }
if ( ! class_exists( 'LucidLab_MPC_Helpers', false ) ) { class LucidLab_MPC_Helpers { public static function either( $value, $fallback ) { return $value ? $value : $fallback; } } }
define( 'ABSPATH', __DIR__ . '/../' );
function __( string $message, string $domain = '' ): string { return $message; }
function home_url( string $path = '/' ): string { return 'https://site.test' . $path; }
require __DIR__ . '/../includes/class-lucidlab-mpc-crawler.php';

$fail = static function ( string $what, $got = null ): void {
	fwrite( STDERR, 'FAIL: ' . $what . ( null === $got ? '' : ' ' . json_encode( $got, JSON_UNESCAPED_SLASHES ) ) . "\n" );
	exit( 1 );
};
$kinds = static function ( array $items ): array {
	$out = array();
	foreach ( $items as $item ) {
		$out[ $item['u'] ] = $item['k'] . ( $item['x'] ? '+x' : '' );
	}
	return $out;
};

$html = <<<'HTML'
<html><head>
<link rel="stylesheet" href="/wp-content/themes/t/style.css?ver=1">
<link rel="icon" href="/favicon.png">
<link rel="preload" as="font" href="/wp-content/fonts/a.woff2" crossorigin>
<link rel="stylesheet" href="https://cdn.other.test/lib.css">
<meta property="og:image" content="https://site.test/wp-content/uploads/og.jpg">
<script src="/wp-includes/js/jquery.js"></script>
<script>var x = '<img src="/not-an-image-in-script.png">';</script>
<style>.hero{background:url('/wp-content/uploads/hero.webp')} @font-face{src:url(/wp-content/fonts/b.ttf)}</style>
</head><body>
<!-- <img src="/commented-out.png"> -->
<img src="/wp-content/uploads/a.jpg" srcset="/wp-content/uploads/a-300.jpg 300w, /wp-content/uploads/a-768.jpg 768w" data-src="/wp-content/uploads/lazy.jpg">
<picture><source srcset="/wp-content/uploads/b.avif 1x, /wp-content/uploads/b@2x.avif 2x" type="image/avif"></picture>
<div style="background-image:url(&quot;/wp-content/uploads/inline-bg.png&quot;)"></div>
<div data-bg="/wp-content/uploads/lazy-bg.jpg"></div>
<a href="/docs/manual.pdf">Manual</a>
<a href="/wp-content/uploads/photo.jpeg">Photo</a>
<a href="/about/">About</a>
<a href="/about/#team">About team</a>
<a href="https://other.test/page">Other site</a>
<a href="/wp-admin/">Admin</a>
<a href="/?add-to-cart=12">Cart</a>
<a href="/feed/">Feed</a>
<a href="/blog/?replytocom=5#respond">Reply</a>
<a href="mailto:a@b.test">Mail</a>
<a href="tel:+3212">Tel</a>
<a href="#top">Top</a>
<a href="javascript:void(0)">JS</a>
<a href="https://site.test/current/">Self</a>
<video src="/wp-content/uploads/clip.mp4" poster="/wp-content/uploads/poster.jpg"><track src="/wp-content/uploads/subs.vtt"></video>
<audio><source src="/wp-content/uploads/song.mp3"></audio>
<iframe src="/embed/form/"></iframe>
<iframe src="https://www.youtube.test/embed/x"></iframe>
<img src="data:image/gif;base64,R0lGOD">
<img src="https://www.site.test/wp-content/uploads/www.png">
<svg><use href="#icon"></use></svg>
<div style="background:url(data:image/svg+xml,%3Csvg%3E%23n)"></div>
</body></html>
HTML;

$page = 'https://site.test/current/';
$k    = $kinds( LucidLab_MPC_Crawler::items_from_html( $html, $page, 'https://site.test/' ) );
$want = array(
	'https://site.test/wp-content/themes/t/style.css?ver=1'  => 'css',
	'https://site.test/favicon.png'                          => 'image',
	'https://site.test/wp-content/fonts/a.woff2'             => 'font',
	'https://site.test/wp-content/uploads/og.jpg'            => 'image',
	'https://site.test/wp-includes/js/jquery.js'             => 'js',
	'https://site.test/wp-content/uploads/hero.webp'         => 'background',
	'https://site.test/wp-content/fonts/b.ttf'               => 'font',
	'https://site.test/wp-content/uploads/a.jpg'             => 'image',
	'https://site.test/wp-content/uploads/a-300.jpg'         => 'srcset',
	'https://site.test/wp-content/uploads/a-768.jpg'         => 'srcset',
	'https://site.test/wp-content/uploads/lazy.jpg'          => 'image',
	'https://site.test/wp-content/uploads/b.avif'            => 'srcset',
	'https://site.test/wp-content/uploads/b@2x.avif'         => 'srcset',
	'https://site.test/wp-content/uploads/inline-bg.png'     => 'background',
	'https://site.test/wp-content/uploads/lazy-bg.jpg'       => 'background',
	'https://site.test/docs/manual.pdf'                      => 'file',
	'https://site.test/wp-content/uploads/photo.jpeg'        => 'image',
	'https://site.test/about/'                               => 'link',
	'https://site.test/wp-content/uploads/clip.mp4'          => 'media',
	'https://site.test/wp-content/uploads/poster.jpg'        => 'image',
	'https://site.test/wp-content/uploads/subs.vtt'          => 'media',
	'https://site.test/wp-content/uploads/song.mp3'          => 'media',
	'https://site.test/embed/form/'                          => 'iframe',
	'https://www.site.test/wp-content/uploads/www.png'       => 'image',
);
foreach ( $want as $url => $kind ) {
	if ( ( $k[ $url ] ?? '(missing)' ) !== $kind ) {
		$fail( "kind of $url: expected $kind, got " . ( $k[ $url ] ?? '(missing)' ), $k );
	}
}
$extra = array_diff_key( $k, $want );
if ( $extra ) {
	$fail( 'unexpected items (other sites, skipped links, scripts, comments, fragments, data: or self)', $extra );
}

// Links switched off: assets only.
$k = $kinds( LucidLab_MPC_Crawler::items_from_html( $html, $page, 'https://site.test/', array( 'links' => false ) ) );
if ( isset( $k['https://site.test/about/'] ) || ! isset( $k['https://site.test/docs/manual.pdf'] ) ) {
	$fail( 'links off keeps files but drops page links', $k );
}

// Other sites (Pro): included and marked, except iframes (embeds of other sites are not checked).
$k = $kinds( LucidLab_MPC_Crawler::items_from_html( $html, $page, 'https://site.test/', array( 'external' => true ) ) );
if ( 'link+x' !== ( $k['https://other.test/page'] ?? '' ) || 'css+x' !== ( $k['https://cdn.other.test/lib.css'] ?? '' ) || isset( $k['https://www.youtube.test/embed/x'] ) ) {
	$fail( 'other sites with external on', $k );
}

// <base href> changes how relative addresses resolve.
$k = $kinds( LucidLab_MPC_Crawler::items_from_html( '<base href="https://site.test/sub/"><img src="pic.png"><a href="../up.pdf">x</a>', $page, 'https://site.test/' ) );
if ( ! isset( $k['https://site.test/sub/pic.png'], $k['https://site.test/up.pdf'] ) ) {
	$fail( 'base href', $k );
}

// Stylesheets: url() relative to the stylesheet, @import, fonts; data: and SVG fragments skipped.
$css = "@import 'parts/grid.css'; /* url(/commented.png) */ .a{background:url(../img/bg.png)} @font-face{src:url('../fonts/x.woff2?v=2') format('woff2')} .b{mask:url(#m)} .c{background:url(data:image/png;base64,AAA)} .d{background:url(https://cdn.other.test/x.png)}";
$k   = $kinds( LucidLab_MPC_Crawler::items_from_css( $css, 'https://site.test/wp-content/themes/t/css/style.css', 'https://site.test/' ) );
$want = array(
	'https://site.test/wp-content/themes/t/css/parts/grid.css'   => 'css',
	'https://site.test/wp-content/themes/t/img/bg.png'           => 'background',
	'https://site.test/wp-content/themes/t/fonts/x.woff2?v=2'    => 'font',
);
ksort( $k );
ksort( $want );
if ( $k !== $want ) {
	$fail( 'stylesheet items', $k );
}
$k = $kinds( LucidLab_MPC_Crawler::items_from_css( $css, 'https://site.test/wp-content/themes/t/css/style.css', 'https://site.test/', true ) );
if ( 'background+x' !== ( $k['https://cdn.other.test/x.png'] ?? '' ) ) {
	$fail( 'stylesheet other-site item with external on', $k );
}

// Problem wording shown in the report.
$labels = array(
	array( array( 'status' => 404 ), '404' ),
	array( array( 'status' => 404, 'final' => 'https://site.test/gone.pdf' ), 'https://site.test/gone.pdf' ),
	array( array( 'status' => 0, 'error' => 'timeout' ), 'imeout' ),
	array( array( 'status' => 301, 'error' => 'loop' ), 'redirect' ),
);
foreach ( $labels as $case ) {
	$label = LucidLab_MPC_Crawler::problem_label( $case[0] );
	if ( ! str_contains( $label, $case[1] ) ) {
		$fail( 'problem label', array( $case, $label ) );
	}
}
echo "Crawler extraction: images, srcset, backgrounds, css, js, fonts, media, files, links, iframes, base href, stylesheets, skipped links and other-site gating OK.\n";
