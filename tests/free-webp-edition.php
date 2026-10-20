<?php
define( 'ABSPATH', __DIR__ . '/' );
function __( $message, $domain = '' ) { return $message; }
class LucidLab_MPC_Pro {
	public static bool $active = false;
	public static function ready(): bool { return self::$active; }
}
require dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-edition.php';
require dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-storage.php';
require dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-webp-rewrite.php';

function expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

expect( LucidLab_MPC_Edition::allows( array( 'op' => 'convert' ) ), 'Free must allow WebP conversion requests.' );
expect( ! LucidLab_MPC_Edition::allows( array( 'op' => 'scan', 'tab' => 'fs-sizes' ) ), 'Other Sizes must remain Pro-only.' );
expect( ! LucidLab_MPC_Edition::allows( array( 'op' => 'run_all' ) ), 'Run All must remain Pro-only.' );
expect( LucidLab_MPC_Edition::pro_quarantine_source( 'avif' ), 'AVIF quarantine must remain Pro-only.' );
expect( ! LucidLab_MPC_Edition::pro_quarantine_source( 'orphans' ), 'Orphan recovery must remain available in Free.' );

$free_rules = LucidLab_MPC_Webp_Rewrite::rules( '/tmp/uploads' );
expect( str_contains( $free_rules, 'image/webp' ), 'Free must serve generated WebP copies.' );
expect( ! str_contains( $free_rules, '%{HTTP_ACCEPT} image/avif' ) && ! str_contains( $free_rules, ' avif/$1.avif [' ), 'Free must not select AVIF copies.' );
LucidLab_MPC_Pro::$active = true;
$pro_rules = LucidLab_MPC_Webp_Rewrite::rules( '/tmp/uploads' );
expect( str_contains( $pro_rules, 'image/avif' ), 'Pro must continue serving AVIF copies.' );
foreach ( array( $free_rules, $pro_rules ) as $rules ) {
	expect( str_contains( $rules, '"/tmp/uploads/lucidlab-mpc-cleanup/webp/$1.webp" -s' ) && str_contains( $rules, ' lucidlab-mpc-cleanup/webp/$1.webp [' ), 'New WebP copies in uploads/lucidlab-mpc-cleanup/webp/ are served.' );
	expect( str_contains( $rules, '"/tmp/uploads/webp/$1.webp" -s' ), 'WebP copies made by older versions in uploads/webp/ keep being served.' );
	expect( strpos( $rules, 'lucidlab-mpc-cleanup/webp/$1.webp [' ) < strpos( $rules, ' webp/$1.webp [NC,T=image/webp,E=lucidlab_mpc_image_vary:1,L]' . "\n" . '</IfModule>' ), 'The new location is tried first.' );
	expect( str_contains( $rules, 'RewriteRule ^lucidlab-mpc-cleanup/backups- - [L]' ), 'Plugin folders are never rewritten.' );
}
expect( str_contains( $pro_rules, '"/tmp/uploads/lucidlab-mpc-cleanup/avif/$1.avif" -s' ) && str_contains( $pro_rules, '"/tmp/uploads/avif/$1.avif" -s' ), 'Pro serves new and older AVIF copies.' );
foreach ( array( $free_rules, $pro_rules ) as $rules ) {
	expect( str_contains( $rules, "<IfModule mod_mime.c>\nAddType image/avif .avif\nAddType image/webp .webp\n</IfModule>" ), 'AVIF and WebP copies are sent with their own Content-Type (x.jpg.avif must not become image/jpeg).' );
	expect( str_contains( $rules, 'RewriteRule ^(lucidlab-mpc-cleanup/)?avif/.+\\.avif$ - [NC,T=image/avif,L]' ), 'Direct AVIF addresses are typed image/avif.' );
	expect( strpos( $rules, 'T=image/avif,L]' ) < strpos( $rules, 'RewriteRule ^(lucidlab-mpc-cleanup/)?avif/ - [L]' ), 'The typed AVIF rule runs before the generic pass-through.' );
	expect( str_contains( $rules, 'Header append Vary Accept env=REDIRECT_lucidlab_mpc_image_vary' ), 'Negotiated responses send Vary: Accept.' );
}

// Updating rewrites only the marker block, in place; everything outside it is kept byte for byte.
$block  = LucidLab_MPC_Webp_Rewrite::BEGIN . "\nRewriteRule old - [L]\n" . LucidLab_MPC_Webp_Rewrite::END . "\n";
$before = "# BEGIN WordPress\r\nOptions -Indexes\r\n# END WordPress\r\n\n";
$after  = "\n# Custom\nHeader set X-Test 1";
$merged = LucidLab_MPC_Webp_Rewrite::merge( $before . $block . $after, $pro_rules );
expect( $merged === $before . $pro_rules . $after, 'The old marker block is replaced in place without touching other lines.' );
expect( LucidLab_MPC_Webp_Rewrite::merge( $merged, $pro_rules ) === $merged, 'A second sync changes nothing.' );
expect( LucidLab_MPC_Webp_Rewrite::merge( $before . $block . $block . $after, $pro_rules ) === $before . $pro_rules . $after, 'A duplicate marker block is dropped.' );
expect( LucidLab_MPC_Webp_Rewrite::merge( $before . $block . $after, '' ) === $before . $after, 'Removing the rules leaves the rest of the file unchanged.' );
expect( LucidLab_MPC_Webp_Rewrite::merge( "Options -Indexes\n", $free_rules ) === "Options -Indexes\n\n" . $free_rules, 'Without a block the rules are appended.' );
expect( LucidLab_MPC_Webp_Rewrite::merge( "Options -Indexes", '' ) === 'Options -Indexes', 'Nothing to remove leaves the file as is.' );
expect( LucidLab_MPC_Webp_Rewrite::merge( $block, '' ) === '', 'A file with only the block becomes empty.' );
echo "Free WebP and Pro AVIF edition boundaries passed.\n";
