<?php
/** Standalone regression: with a complete reference index, an image nothing refers to is reported as an orphan. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
$GLOBALS['att'] = array(
	10 => '2024/01/used-by-id.jpg',
	11 => '2024/01/used-by-name.jpg',
	12 => '2024/01/never-used.jpg',
	13 => '2024/01/used-thumb.png',
);
function __( $s ) { return $s; }
function update_meta_cache( $t, $ids ) { return true; }
function get_post_meta( $id, $k, $s ) { return '_wp_attached_file' === $k ? ( $GLOBALS['att'][ $id ] ?? '' ) : ''; }
function wp_get_attachment_url( $id ) { return 'https://example.com/wp-content/uploads/' . $GLOBALS['att'][ $id ]; }
function wp_get_attachment_image_url( $id, $s ) { return wp_get_attachment_url( $id ); }
function wp_get_attachment_metadata( $id ) {
	return 13 === $id ? array( 'file' => $GLOBALS['att'][13], 'sizes' => array( 'thumbnail' => array( 'file' => 'used-thumb-150x150.png' ) ) ) : array( 'file' => $GLOBALS['att'][ $id ] );
}
function get_attached_file( $id ) { return '/nonexistent/' . $GLOBALS['att'][ $id ]; }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function wp_basename( $p ) { return basename( $p ); }
class LucidLab_MPC_Settings { public static function get( $k ) { return 100; } }
class LucidLab_MPC_Helpers {
	public static function uploads_basedir() { return '/nonexistent'; }
	public static function url_to_basename( string $url ): string {
		$name = strtolower( basename( (string) strtok( $url, '?#' ) ) );
		return (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/', '', $name );
	}
	public static function generated_copy_source( string $rel ): string { return preg_replace( '#^(webp|avif)/|\.(webp|avif)$#i', '', $rel ); }
}
class Fake_DB {
	public string $posts = 'wp_posts';
	public string $last_error = '';
	public function prepare( $s, ...$a ) { return array( $s, $a ); }
	public function get_results( $q ) {
		$after = (int) $q[1][1];
		$rows  = array();
		foreach ( array_keys( $GLOBALS['att'] ) as $id ) {
			if ( $id > $after ) {
				$rows[] = (object) array( 'ID' => $id, 'post_title' => 'Image ' . $id );
			}
		}
		return $rows;
	}
}
$GLOBALS['wpdb'] = new Fake_DB();
require __DIR__ . '/../includes/class-lucidlab-mpc-orphans.php';
function mpc_assert( bool $ok, string $what ): void { if ( ! $ok ) { throw new RuntimeException( $what ); } }

$o      = ( new ReflectionClass( 'LucidLab_MPC_Orphans' ) )->newInstanceWithoutConstructor();
$cursor = 0;
// Reference index: ID 10 by ID, 11 by file name, 13 through a resized copy's name. Nothing names 12.
$res = $o->scan_step( array( 10 => 1 ), array( 'used-by-name.jpg' => 1, 'used-thumb.png' => 1 ), $cursor );
$ids = array_map( static fn( $x ) => $x['id'], $res['orphans'] );
mpc_assert( in_array( 12, $ids, true ), 'An image nothing refers to was not reported as an orphan.' );
mpc_assert( ! array_intersect( array( 10, 11, 13 ), $ids ), 'A referenced image was reported as an orphan: ' . implode( ',', $ids ) );
mpc_assert( 4 === $res['count'] && 13 === $cursor, 'Not every attachment was checked.' );
mpc_assert( 1 === count( $ids ), 'Unexpected orphans: ' . implode( ',', $ids ) );

// An empty but complete index (a site where nothing is used) reports every image, not none.
$cursor = 0;
$res    = $o->scan_step( array(), array(), $cursor );
mpc_assert( 4 === count( $res['orphans'] ), 'With no references, ' . count( $res['orphans'] ) . ' of 4 originals were reported.' );

echo "Orphan check reports an unreferenced image and keeps images referenced by ID, file name or size copy.\n";
