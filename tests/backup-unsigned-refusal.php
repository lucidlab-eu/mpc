<?php
/** Unsigned or foreign database dumps are refused at import, marked in the list, refused by the pre-check and can never reach SQL execution. */
if ( ! function_exists( 'wp_delete_file' ) ) { function wp_delete_file( $file ) { if ( is_file( $file ) ) { unlink( $file ); } } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); } }
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); } }
if ( ! function_exists( 'is_serialized' ) ) { function is_serialized( $data ) { return is_string( $data ) && ( 'b:0;' === $data || false !== @unserialize( $data, array( 'allowed_classes' => false ) ) ); } }
$root = sys_get_temp_dir() . '/lucidlab-mpc-unsigned-test-' . bin2hex( random_bytes( 4 ) );
define( 'ABSPATH', $root . '/site/' );
define( 'WP_CONTENT_DIR', $root . '/content' );
define( 'LUCIDLAB_MPC_DIR', dirname( __DIR__ ) . '/' );
mkdir( ABSPATH . 'wp-content/uploads', 0777, true );
mkdir( WP_CONTENT_DIR . '/lucidlab-mpc-backups', 0777, true );
register_shutdown_function( static function () use ( $root ): void {
    $base = realpath( sys_get_temp_dir() );
    $target = realpath( $root );
    if ( ! $base || ! $target || ! str_starts_with( $target, $base . DIRECTORY_SEPARATOR . 'lucidlab-mpc-unsigned-test-' ) ) { return; }
    $items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $items as $item ) { $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); }
    rmdir( $target );
} );

function __( $text ) { return $text; }
function get_option( $key, $default = null ) { return $GLOBALS['lucidlab_mpc_test_options'][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['lucidlab_mpc_test_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['lucidlab_mpc_test_options'][ $key ] ); return true; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals ); }
function wp_date( $format, $timestamp = null ) { return date( $format, $timestamp ?? time() ); }
function wp_create_nonce() { return 'test'; }
function admin_url( $path ) { return 'https://example.test/' . $path; }
function add_query_arg( $key, $value, $url ) { return $url . '&' . $key . '=' . $value; }
function current_user_can( $cap ) { return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function wp_next_scheduled( $hook ) { return false; }
function wp_clear_scheduled_hook( $hook ) { return 0; }
function wp_schedule_single_event( $ts, $hook ) { return true; }
function sanitize_file_name( $name ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name ); }
function wp_unslash( $value ) { return $value; }
$GLOBALS['lucidlab_mpc_test_filters'] = array();
function add_filter( $hook, $cb, $prio = 10 ) { $GLOBALS['lucidlab_mpc_test_filters'][ $hook ] = $cb; return true; }
function remove_filter( $hook, $cb, $prio = 10 ) { unset( $GLOBALS['lucidlab_mpc_test_filters'][ $hook ] ); return true; }
function wp_handle_upload( $file, $overrides ) {
    $uploads = call_user_func( $GLOBALS['lucidlab_mpc_test_filters']['upload_dir'], array() );
    $dest    = $uploads['path'] . '/' . call_user_func( $overrides['unique_filename_callback'] );
    copy( $file['tmp_name'], $dest );
    return array( 'file' => $dest );
}
class LucidLab_MPC_Test_WPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $queries = array();
    public function prepare( $sql, ...$args ) { return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql ), $args ); }
    public function get_var( $sql ) { return 1; }
    public function query( $sql ) { $this->queries[] = $sql; return 1; }
}
$GLOBALS['wpdb'] = new LucidLab_MPC_Test_WPDB();
class LucidLab_MPC_Helpers {
    public static function prepend_job_history( ...$args ) { return true; }
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

function lucidlab_mpc_t_assert( bool $ok, string $what ): void { if ( ! $ok ) { fwrite( STDERR, "FAIL: $what\n" ); exit( 1 ); } }
function lucidlab_mpc_t_call( string $method, ...$args ) { $m = new ReflectionMethod( 'LucidLab_MPC_Backup', $method ); $m->setAccessible( true ); return $m->invoke( null, ...$args ); }
function lucidlab_mpc_t_throws( callable $fn, string $needle ): bool { try { $fn(); return false; } catch ( RuntimeException $e ) { return str_contains( $e->getMessage(), $needle ); } }
function lucidlab_mpc_t_upload( string $name, string $bytes ): void {
    $tmp = tempnam( sys_get_temp_dir(), 'mpc-up-' );
    file_put_contents( $tmp, $bytes );
    $_FILES['file'] = array( 'name' => $name, 'tmp_name' => $tmp, 'error' => 0, 'size' => strlen( $bytes ) );
}
function lucidlab_mpc_t_db_files(): array { return array_values( array_filter( array_map( 'basename', (array) glob( LucidLab_MPC_Backup::dir() . '/db-*' ) ), static fn( $f ) => ! str_ends_with( $f, '.json' ) ) ); }
function lucidlab_mpc_t_item( string $file ): array { foreach ( LucidLab_MPC_Backup::list_backups() as $item ) { if ( $item['file'] === $file ) { return $item; } } return array(); }
$dir      = LucidLab_MPC_Backup::dir();
$refused  = 'was not created by Media & Products Cleanup on this site';
$sql      = "-- MPC\nCREATE TABLE t (a int);\n-- MPC\nINSERT INTO t VALUES (1);\n-- MPC\n";

// 1. Import: unsigned .sql and .sql.gz are refused and not stored.
lucidlab_mpc_t_upload( 'foreign.sql', 'SELECT 1;' );
lucidlab_mpc_t_assert( lucidlab_mpc_t_throws( static fn() => LucidLab_MPC_Backup::import_upload( 'db' ), 'so it was not imported' ), 'unsigned .sql import is refused' );
lucidlab_mpc_t_upload( 'foreign.sql.gz', gzencode( $sql ) );
lucidlab_mpc_t_assert( lucidlab_mpc_t_throws( static fn() => LucidLab_MPC_Backup::import_upload( 'db' ), 'so it was not imported' ), 'unsigned .sql.gz import is refused' );
lucidlab_mpc_t_assert( array() === lucidlab_mpc_t_db_files(), 'refused imports are not kept' );

// 2. Import: a dump signed by this site is accepted and listed as signed.
$signed_plain = tempnam( sys_get_temp_dir(), 'mpc-signed-' );
file_put_contents( $signed_plain, $sql );
lucidlab_mpc_t_call( 'sign_sql_file', $signed_plain );
lucidlab_mpc_t_upload( 'mine.sql', file_get_contents( $signed_plain ) );
LucidLab_MPC_Backup::import_upload( 'db' );
lucidlab_mpc_t_upload( 'mine.sql.gz', gzencode( file_get_contents( $signed_plain ) ) );
sleep( 1 );
LucidLab_MPC_Backup::import_upload( 'db' );
$files = lucidlab_mpc_t_db_files();
lucidlab_mpc_t_assert( 2 === count( $files ), 'signed .sql and .sql.gz imports are kept' );
// A plain .sql import is stored gzip-compressed; no plain SQL or temporary upload stays on disk.
lucidlab_mpc_t_assert( 2 === count( array_filter( $files, static fn( $f ) => str_ends_with( $f, '.sql.gz' ) ) ), 'both imports are stored as .sql.gz' );
lucidlab_mpc_t_assert( array() === glob( "$dir/*.sql" ) && array() === glob( "$dir/*.part" ), 'no plain .sql or .part file is left after import' );
sort( $files );
$signed_sql = $files[0];
$signed_gz  = $files[1];
foreach ( array( $signed_sql, $signed_gz ) as $f ) {
    $item = lucidlab_mpc_t_item( $f );
    lucidlab_mpc_t_assert( 'signed' === $item['signature'] && true === $item['restorable'], "$f is listed as signed and restorable" );
}

// 3. Stored legacy or foreign dumps (copied into the folder) are marked and not restorable.
$legacy_gz  = 'db-20261016-180334-e40cb7.sql.gz';
$legacy_sql = 'db-20261018-122531-cbc3e1.sql';
file_put_contents( "$dir/$legacy_gz", gzencode( $sql ) );
file_put_contents( "$dir/$legacy_sql", 'SELECT 1;' );
$edited_gz = 'db-20261018-130000-aaaaaa.sql.gz';
file_put_contents( "$dir/$edited_gz", gzencode( str_replace( 'VALUES (1)', 'VALUES (2)', file_get_contents( $signed_plain ) ) ) );
foreach ( array( $legacy_gz, $legacy_sql, $edited_gz ) as $f ) {
    $item = lucidlab_mpc_t_item( $f );
    lucidlab_mpc_t_assert( 'unsigned' === $item['signature'] && false === $item['restorable'], "$f is listed as not signed and not restorable" );
}

// 4. Pre-check before the RESTORE prompt.
foreach ( array( $legacy_gz, $legacy_sql, $edited_gz ) as $f ) {
    lucidlab_mpc_t_assert( lucidlab_mpc_t_throws( static fn() => LucidLab_MPC_Backup::command( 'verify', array( 'kind' => 'restore_db', 'file' => $f ) ), $refused ), "pre-check refuses $f" );
}
foreach ( array( $signed_sql, $signed_gz ) as $f ) {
    $r = LucidLab_MPC_Backup::command( 'verify', array( 'kind' => 'restore_db', 'file' => $f ) );
    lucidlab_mpc_t_assert( ! empty( $r['ok'] ), "pre-check accepts $f" );
}

// 5. A restore request posted directly is refused before a job, decompression or SQL.
update_option( LucidLab_MPC_Backup::JOB_OPTION, array( 'status' => 'idle' ) );
foreach ( array( $legacy_gz, $legacy_sql, $edited_gz ) as $f ) {
    lucidlab_mpc_t_assert( lucidlab_mpc_t_throws( static fn() => LucidLab_MPC_Backup::command( 'start', array( 'kind' => 'restore_db', 'file' => $f ) ), $refused ), "direct start refuses $f" );
    lucidlab_mpc_t_assert( 'running' !== ( get_option( LucidLab_MPC_Backup::JOB_OPTION )['status'] ?? '' ), "no restore job is created for $f" );
}
lucidlab_mpc_t_assert( array() === glob( "$dir/*.restore.sql" ) && array() === glob( "$dir/*.restore.tmp" ), 'nothing was decompressed for refused dumps' );
lucidlab_mpc_t_assert( array() === $GLOBALS['wpdb']->queries, 'no SQL ran for refused dumps' );

// 6. Even a crafted job that skips start() cannot execute an unsigned file.
foreach ( array(
    array( 'kind' => 'restore_db', 'status' => 'running', 'driver' => 'ajax', 'phase' => 'sql', 'path' => "$dir/$legacy_sql", 'file' => $legacy_sql, 'offset' => 0, 'buffer' => '' ),
    array( 'kind' => 'restore_db', 'status' => 'running', 'driver' => 'ajax', 'phase' => 'inflate', 'path' => "$dir/$legacy_gz", 'file' => $legacy_gz, 'offset' => 0, 'buffer' => '' ),
    array( 'kind' => 'restore_db', 'status' => 'running', 'driver' => 'ajax', 'phase' => 'sql', 'path' => "$dir/$legacy_gz", 'sql_path' => "$dir/$legacy_sql", 'file' => $legacy_gz, 'offset' => 0, 'buffer' => '' ),
) as $job ) {
    update_option( LucidLab_MPC_Backup::JOB_OPTION, $job );
    $after = LucidLab_MPC_Backup::command( 'step' );
    lucidlab_mpc_t_assert( 'failed' === $after['status'] && str_contains( (string) $after['label'], $refused ), 'crafted ' . $job['phase'] . ' job fails with the refusal' );
}
lucidlab_mpc_t_assert( array() === $GLOBALS['wpdb']->queries, 'no SQL ran for crafted jobs' );
lucidlab_mpc_t_assert( array() === glob( "$dir/*.restore.sql" ) && array() === glob( "$dir/*.restore.tmp" ), 'temporary SQL from a refused archive is removed' );
lucidlab_mpc_t_assert( is_file( "$dir/$legacy_sql" ) && is_file( "$dir/$legacy_gz" ), 'refusing a crafted job never deletes a stored backup' );

// 7. Positive control: a signed .sql.gz restores, and the signature line is not executed.
update_option( LucidLab_MPC_Backup::JOB_OPTION, array( 'status' => 'idle' ) );
LucidLab_MPC_Backup::command( 'start', array( 'kind' => 'restore_db', 'file' => $signed_gz ) );
for ( $i = 0; $i < 10 && 'running' === ( get_option( LucidLab_MPC_Backup::JOB_OPTION )['status'] ?? '' ); ++$i ) { LucidLab_MPC_Backup::command( 'step' ); }
$final = get_option( LucidLab_MPC_Backup::JOB_OPTION );
lucidlab_mpc_t_assert( 'done' === $final['status'], 'signed .sql.gz restore finishes' );
lucidlab_mpc_t_assert( array() === glob( "$dir/*.restore.tmp" ), 'the decompressed restore copy is deleted when the restore ends' );
$ran = array_map( static fn( $q ) => trim( preg_replace( '/^--[^\n]*\n/m', '', $q ) ), $GLOBALS['wpdb']->queries );
lucidlab_mpc_t_assert( array( 'CREATE TABLE t (a int);', 'INSERT INTO t VALUES (1);' ) === $ran, 'signed restore runs exactly the dump statements' );
lucidlab_mpc_t_assert( ! str_contains( implode( "\n", $GLOBALS['wpdb']->queries ), 'LUCIDLAB-MPC-SIGNATURE' ), 'the signature line is never executed' );

// 8. Emergency override: unsigned dumps become restorable and importable.
define( 'LUCIDLAB_MPC_ALLOW_UNSIGNED_DB_RESTORE', true );
lucidlab_mpc_t_assert( true === lucidlab_mpc_t_item( $legacy_sql )['restorable'], 'override makes unsigned dumps restorable in the list' );
$r = LucidLab_MPC_Backup::command( 'verify', array( 'kind' => 'restore_db', 'file' => $legacy_sql ) );
lucidlab_mpc_t_assert( ! empty( $r['ok'] ), 'override passes the pre-check' );

unlink( $signed_plain );
echo "Unsigned database dump refusal checks passed.\n";
