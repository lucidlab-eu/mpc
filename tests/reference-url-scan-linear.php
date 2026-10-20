<?php
/** Standalone regression: settings/meta URL scanning stays linear on large values and keeps its matching rules. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
function wp_upload_dir() { return array( 'baseurl' => 'https://example.com/wp-content/uploads' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
require __DIR__ . '/../includes/class-lucidlab-mpc-orphans.php';

$o = ( new ReflectionClass( 'LucidLab_MPC_Orphans' ) )->newInstanceWithoutConstructor();
$scan = static function ( string $blob ) use ( $o ): array {
	$found = array();
	$o->collect_urls( $blob, static function ( $u ) use ( &$found ) { $found[] = $u; } );
	sort( $found );
	return array_values( array_unique( $found ) );
};
function mpc_assert( bool $ok, string $what ): void { if ( ! $ok ) { throw new RuntimeException( $what ); } }

// Matching rules are unchanged.
mpc_assert( array( '/wp-content/uploads/2024/01/a.jpg' ) === $scan( 'x="https://example.com/wp-content/uploads/2024/01/a.jpg"' ), 'Absolute URL not found.' );
mpc_assert( array( '/wp-content/uploads/b.png' ) === $scan( 'src="/wp-content/uploads/b.png"' ), 'Root-relative URL not found.' );
mpc_assert( array( '/wp-content/uploads/c.webp' ) === $scan( '{"u":"https:\/\/example.com\/wp-content\/uploads\/c.webp"}' ), 'JSON-escaped URL not found.' );
mpc_assert( array( '/wp-content/uploads/d.jpg' ) === $scan( '/wp-content/uploads/d.jpg' ), 'URL at the start of a value not found.' );
mpc_assert( array( 'e.jpg' ) === $scan( 'backups/imports/wp-content/uploads/e.jpg' ), 'A nested copy must only protect its file name.' );
mpc_assert( array( 'f.jpg' ) === $scan( 'logo f.jpg used' ), 'A bare file name was not found.' );
// A hit far into a long value still sees the separator and host in front of it.
mpc_assert( array( '/wp-content/uploads/g.jpg' ) === $scan( str_repeat( 'z', 200000 ) . ' "https://example.com/wp-content/uploads/g.jpg"' ), 'Hit after a long prefix not found.' );
mpc_assert( array( 'h.jpg' ) === $scan( str_repeat( 'z', 200000 ) . '/nested/wp-content/uploads/h.jpg' ), 'Nested copy after a long prefix was treated as a real upload.' );

// 8,000 URLs in a 7.5 MB value used to take 78 seconds (quadratic); it must now take well under a second or two.
$blob = '';
for ( $i = 0; $i < 8000; $i++ ) {
	$blob .= str_repeat( 'x', 900 ) . ' "https://example.com/wp-content/uploads/2024/01/img' . $i . '.jpg" ';
}
$t     = microtime( true );
$count = count( $scan( $blob ) );
$secs  = microtime( true ) - $t;
mpc_assert( 8000 === $count, 'Not every URL in the large value was found: ' . $count );
mpc_assert( $secs < 3.0, sprintf( 'Large value scan took %.1f s.', $secs ) );
printf( "Reference URL scan found all 8000 URLs in a 7.5 MB value in %.2f s with unchanged matching rules.\n", $secs );
