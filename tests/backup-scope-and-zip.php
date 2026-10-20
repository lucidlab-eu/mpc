<?php
/** Focused regression checks for scoped site backups and uploads ZIP completion. */
if ( ! function_exists( 'wp_delete_file' ) ) { function wp_delete_file( $file ) { if ( is_file( $file ) ) { unlink( $file ); } } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); } }
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); } }
if ( ! function_exists( 'is_serialized' ) ) { function is_serialized( $data ) { return is_string( $data ) && ( 'b:0;' === $data || false !== @unserialize( $data, array( 'allowed_classes' => false ) ) ); } }
$root = sys_get_temp_dir() . '/lucidlab-mpc-backup-test-' . bin2hex( random_bytes( 4 ) );
define( 'ABSPATH', $root . '/site/' );
define( 'WP_CONTENT_DIR', $root . '/content' );
define( 'LUCIDLAB_MPC_DIR', dirname( __DIR__ ) . '/' );
mkdir( ABSPATH . 'wp-content/uploads', 0777, true );
mkdir( WP_CONTENT_DIR . '/lucidlab-mpc-backups', 0777, true );
register_shutdown_function( static function () use ( $root ): void {
    $base = realpath( sys_get_temp_dir() );
    $target = realpath( $root );
    if ( ! $base || ! $target || ! str_starts_with( $target, $base . DIRECTORY_SEPARATOR . 'lucidlab-mpc-backup-test-' ) ) { return; }
    $items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $items as $item ) { $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); }
    rmdir( $target );
} );

function __( $text ) { return $text; }
function get_option( $key, $default = null ) { return $GLOBALS['lucidlab_mpc_test_options'][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['lucidlab_mpc_test_options'][ $key ] = $value; return true; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals ); }
function wp_date( $format, $timestamp ) { return date( $format, $timestamp ); }
function wp_create_nonce() { return 'test'; }
function admin_url( $path ) { return 'https://example.test/' . $path; }
function add_query_arg( $key, $value, $url ) { return $url . '&' . $key . '=' . $value; }
class LucidLab_MPC_Helpers {
    public static function uploads_basedir() { return ABSPATH . 'wp-content/uploads'; }
    public static function bytes( $bytes ) { return (string) $bytes; }
    public static function either( $value, $fallback ) { return $value ? $value : $fallback; }
    public static function delete_file( string $file ): bool { return ! is_file( $file ) || unlink( $file ); }
    public static function extend_time_limit( int $seconds ): void {}
    public static function rel_to_root( string $dir ): string { $root = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/'; $dir = rtrim( str_replace( '\\', '/', $dir ), '/' ); return str_starts_with( $dir . '/', $root ) ? trim( substr( $dir, strlen( $root ) ), '/' ) : 'wp-content'; }
    public static function site_folders(): array { return array( 'wp-admin', 'wp-includes', 'wp-content/plugins', 'wp-content/themes', 'wp-content/uploads' ); }
}
if ( ! class_exists( 'ZipArchive' ) ) {
    class ZipArchive { public function open() { throw new RuntimeException( 'Unexpected ZIP open' ); } }
}
require dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-storage.php';
require dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-backup.php';
is_dir( LucidLab_MPC_Backup::dir() ) || mkdir( LucidLab_MPC_Backup::dir(), 0777, true );

// The list is created before its ZIP. Listing backups must not prune a running job's list.
$active_archive = LucidLab_MPC_Backup::dir() . '/uploads-20261011-210000-abcdef.zip';
$active_list = $active_archive . '.lst';
file_put_contents( $active_list, "sample.txt\n" );
update_option( LucidLab_MPC_Backup::JOB_OPTION, array( 'status' => 'running', 'list_file' => $active_list ) );
LucidLab_MPC_Backup::list_backups();
if ( ! is_file( $active_list ) ) { throw new RuntimeException( 'Active backup list was pruned' ); }
update_option( LucidLab_MPC_Backup::JOB_OPTION, array( 'status' => 'done', 'list_file' => $active_list ) );
LucidLab_MPC_Backup::list_backups();
if ( is_file( $active_list ) ) { throw new RuntimeException( 'Orphan backup list was not pruned' ); }

$allowed = array( 'wp-admin/a.php', 'wp-includes/a.php', 'wp-content/plugins/a.php', 'wp-content/themes/a.php', 'wp-content/uploads/a.jpg', 'wp-config.php', 'wp-load.php' );
$excluded = array( 'wp-content/cache/a', 'wp-content/mu-plugins/a.php', 'wp-content/uploads/lucidlab-mpc-cleanup/' . basename( LucidLab_MPC_Backup::dir() ) . '/a.zip', 'custom/a', 'random.php' );
foreach ( $allowed as $path ) {
    if ( LucidLab_MPC_Backup::skip_site_path( LucidLab_MPC_Backup::site_root() . '/' . $path ) ) { throw new RuntimeException( 'Unexpectedly excluded ' . $path ); }
}
foreach ( $excluded as $path ) {
    if ( ! LucidLab_MPC_Backup::skip_site_path( LucidLab_MPC_Backup::site_root() . '/' . $path ) ) { throw new RuntimeException( 'Unexpectedly included ' . $path ); }
}

try {
    LucidLab_MPC_Backup::write_restore_script();
    throw new RuntimeException( 'Web-accessible restore unexpectedly enabled' );
} catch ( RuntimeException $error ) {
    if ( 'Web-accessible restore unexpectedly enabled' === $error->getMessage() ) { throw $error; }
}
if ( is_file( ABSPATH . 'lucidlab-mpc-restore.php' ) || is_file( ABSPATH . 'lucidlab-mpc-restore-paths.php' ) ) {
    throw new RuntimeException( 'A root restore file was created' );
}

$step = new ReflectionMethod( 'LucidLab_MPC_Backup', 'step_uploads' );
$name = 'uploads-20261011-160012-850b95.zip';
$job = array( 'kind' => 'uploads', 'status' => 'running', 'phase' => 'zip', 'file' => $name, 'path' => LucidLab_MPC_Backup::dir() . '/' . $name, 'list_file' => LucidLab_MPC_Backup::dir() . '/' . $name . '.lst', 'zip_index' => 0 );
file_put_contents( $job['list_file'], '' );
try {
    $step->invoke( null, $job );
    throw new RuntimeException( 'Missing ZIP was falsely reported ready' );
} catch ( RuntimeException $error ) {
    if ( 'Missing ZIP was falsely reported ready' === $error->getMessage() ) { throw $error; }
}
if ( defined( 'ZipArchive::CREATE' ) ) {
    file_put_contents( LucidLab_MPC_Helpers::uploads_basedir() . '/sample.txt', 'sample' );
    file_put_contents( $job['list_file'], "sample.txt\n" );
    $job = $step->invoke( null, $job );
    if ( 1 !== $job['zip_index'] || ! is_file( $job['path'] ) ) { throw new RuntimeException( 'First ZIP batch did not create an archive' ); }
    $result = $step->invoke( null, $job );
    if ( 'done' !== $result['status'] || ! is_file( $job['path'] ) ) { throw new RuntimeException( 'Valid ZIP was not accepted' ); }

    foreach ( array( 'wp-admin', 'wp-includes', 'wp-content/plugins', 'wp-content/themes', 'wp-content/uploads' ) as $folder ) {
        if ( ! is_dir( ABSPATH . $folder ) ) { mkdir( ABSPATH . $folder, 0777, true ); }
        file_put_contents( ABSPATH . $folder . '/sample.txt', $folder );
    }
    file_put_contents( ABSPATH . 'wp-config.php', 'configuration' );
    $site = array(
        'kind' => 'site', 'status' => 'running', 'phase' => 'list',
        'file' => 'site-20261011-160012-850b95.zip',
        'path' => LucidLab_MPC_Backup::dir() . '/site-20261011-160012-850b95.zip',
        'list_file' => LucidLab_MPC_Backup::dir() . '/site-20261011-160012-850b95.zip.lst',
        'stack' => array( LucidLab_MPC_Backup::site_root() ), 'done' => 0, 'zip_index' => 0,
        'sql_in_site' => LucidLab_MPC_Backup::dir() . '/' . LucidLab_MPC_Backup::SQL_IN_SITE,
    );
    file_put_contents( $site['list_file'], '' );
    file_put_contents( $site['sql_in_site'], 'SELECT 1;' );
    $site_step = new ReflectionMethod( 'LucidLab_MPC_Backup', 'step_site' );
    for ( $i = 0; $i < 20 && 'done' !== $site['status']; ++$i ) { $site = $site_step->invoke( null, $site ); }
    if ( 'done' !== $site['status'] ) { throw new RuntimeException( 'Full-site ZIP did not finish' ); }
    $site_zip = new ZipArchive();
    $site_zip->open( $site['path'] );
    foreach ( array( LucidLab_MPC_Backup::SQL_IN_SITE, 'wp-admin/sample.txt', 'wp-includes/sample.txt', 'wp-content/plugins/sample.txt', 'wp-content/themes/sample.txt', 'wp-content/uploads/sample.txt' ) as $entry ) {
        if ( false === $site_zip->locateName( $entry ) ) { throw new RuntimeException( 'Full-site ZIP omitted ' . $entry ); }
    }
    $site_zip->close();
    file_put_contents( $site['list_file'], "wp-content/uploads/not-in-archive.jpg\n" );
    try {
        ( new ReflectionMethod( 'LucidLab_MPC_Backup', 'finish_site_file' ) )->invoke( null, $site );
        throw new RuntimeException( 'Incomplete full-site ZIP was falsely accepted' );
    } catch ( RuntimeException $error ) {
        if ( 'Incomplete full-site ZIP was falsely accepted' === $error->getMessage() ) { throw $error; }
    }
    echo "Backup scope, uploads ZIP, full-site ZIP and missing-entry checks passed.\n";
} else {
    echo "Backup scope and missing-ZIP checks passed; ZIP extension unavailable.\n";
}
