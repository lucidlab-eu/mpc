<?php
declare(strict_types=1);
/** Standalone regression test: php tests/directory-inventory-resume.php */
if ( ! function_exists( 'wp_delete_file' ) ) { function wp_delete_file( $file ) { if ( is_file( $file ) ) { unlink( $file ); } } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); } }
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); } }
if ( ! function_exists( 'is_serialized' ) ) { function is_serialized( $data ) { return is_string( $data ) && ( 'b:0;' === $data || false !== @unserialize( $data, array( 'allowed_classes' => false ) ) ); } }

define( 'ABSPATH', __DIR__ . '/../' );
function __( string $message, string $domain = '' ): string { return $message; }

$root = sys_get_temp_dir() . '/lucidlab-mpc-inventory-test-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root ) || ! mkdir( $root . '/files' ) || ! mkdir( $root . '/work' ) ) {
    throw new RuntimeException( 'Cannot create temporary test directories.' );
}
class LucidLab_MPC_Work {
    public static function path( string $name ): string {
        global $root;
        return $root . '/work/' . $name;
    }
}
require __DIR__ . '/../includes/class-lucidlab-mpc-directory-inventory.php';

try {
    $expected = array( '0' );
    file_put_contents( $root . '/files/0', 'test' );
    for ( $i = 0; $i < 3000; $i++ ) {
        $name = sprintf( 'file-%04d.txt', $i );
        $expected[] = $name;
        file_put_contents( $root . '/files/' . $name, 'test' );
    }
    $token = 'audit-resume';
    $partial_seen = false;
    $handle = null;
    for ( $attempt = 0; $attempt < 300; $attempt++ ) {
        $handle = LucidLab_MPC_Directory_Inventory::open( $root . '/files', $token, 0, microtime( true ) + ( $attempt === 0 ? 0.0001 : 0.004 ) );
        if ( is_resource( $handle ) ) { break; }
        $partial_seen = $partial_seen || is_file( LucidLab_MPC_Directory_Inventory::path( $root . '/files', $token ) . '.state' );
    }
    if ( ! $partial_seen || ! is_resource( $handle ) ) {
        throw new RuntimeException( 'Inventory did not save and resume a partial listing.' );
    }
    $actual = array();
    while ( false !== ( $name = LucidLab_MPC_Directory_Inventory::next( $handle ) ) ) { $actual[] = $name; }
    fclose( $handle );
    sort( $expected );
    sort( $actual );
    if ( $actual !== $expected ) {
        throw new RuntimeException( 'Published inventory lost or duplicated file names.' );
    }
    LucidLab_MPC_Directory_Inventory::discard( $token );
    if ( is_file( LucidLab_MPC_Directory_Inventory::path( $root . '/files', $token ) ) ) {
        throw new RuntimeException( 'Discard did not remove the published inventory.' );
    }
    echo "Directory inventory resumed and listed " . count( $actual ) . " files exactly once.\n";
} finally {
    foreach ( glob( $root . '/files/*' ) ?: array() as $path ) { unlink( $path ); }
    foreach ( glob( $root . '/work/*' ) ?: array() as $path ) { unlink( $path ); }
    rmdir( $root . '/files' );
    rmdir( $root . '/work' );
    rmdir( $root );
}
