<?php
/** A database backup never puts plain SQL on disk: the dump is written as gzip, signed while streaming, and temporary files are removed. */
if ( ! function_exists( 'wp_delete_file' ) ) { function wp_delete_file( $file ) { if ( is_file( $file ) ) { unlink( $file ); } } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); } }
if ( ! function_exists( 'wp_normalize_path' ) ) { function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); } }
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); } }
if ( ! function_exists( 'is_serialized' ) ) { function is_serialized( $data ) { return is_string( $data ) && ( 'b:0;' === $data || false !== @unserialize( $data, array( 'allowed_classes' => false ) ) ); } }
$root = sys_get_temp_dir() . '/lucidlab-mpc-gzdump-test-' . bin2hex( random_bytes( 4 ) );
define( 'ABSPATH', $root . '/site/' );
define( 'WP_CONTENT_DIR', $root . '/content' );
define( 'LUCIDLAB_MPC_DIR', dirname( __DIR__ ) . '/' );
mkdir( ABSPATH . 'wp-content/uploads', 0777, true );
mkdir( WP_CONTENT_DIR . '/lucidlab-mpc-backups', 0777, true );
register_shutdown_function( static function () use ( $root ): void {
    $base = realpath( sys_get_temp_dir() );
    $target = realpath( $root );
    if ( ! $base || ! $target || ! str_starts_with( $target, $base . DIRECTORY_SEPARATOR . 'lucidlab-mpc-gzdump-test-' ) ) { return; }
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
    public $rows = 5000;
    public function prepare( $sql, ...$args ) { return vsprintf( str_replace( array( '%i', '%s', '%d' ), array( '`%s`', "'%s'", '%d' ), $sql ), $args ); }
    public function get_col( $sql ) { return array( 'wp_t' ); }
    public function get_row( $sql, $out = null ) { return array( 'wp_t', 'CREATE TABLE `wp_t` (`a` text)' ); }
    public function get_var( $sql ) { return $this->rows; }
    public function get_results( $sql, $out = null ) {
        if ( str_starts_with( $sql, 'SHOW' ) ) { return array(); }
        preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $sql, $m );
        $out = array();
        for ( $i = (int) $m[2]; $i < min( $this->rows, (int) $m[2] + (int) $m[1] ); ++$i ) { $out[] = array( 'a' => 'secret-row-' . $i . str_repeat( 'x', 40 ) ); }
        return $out;
    }
    public function query( $sql ) { $this->queries[] = $sql; return 1; }
}
function esc_sql( $v ) { return addslashes( (string) $v ); }
define( 'ARRAY_N', 'ARRAY_N' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['wpdb'] = new LucidLab_MPC_Test_WPDB();
class LucidLab_MPC_Helpers {
    public static function prepend_job_history( ...$args ) { return true; }
    public static function uploads_basedir() { return ABSPATH . 'wp-content/uploads'; }
    public static function bytes( $bytes ) { return (string) $bytes; }
    public static function either( $value, $fallback ) { return $value ? $value : $fallback; }
    public static function delete_file( string $file ): bool { return ! is_file( $file ) || unlink( $file ); }
    public static function extend_time_limit( int $seconds ): void {}
}
require dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-storage.php';
require dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-backup.php';
function t_assert( bool $ok, string $what ): void { if ( ! $ok ) { fwrite( STDERR, "FAIL: $what\n" ); exit( 1 ); } }
function t_call( string $method, ...$args ) { $m = new ReflectionMethod( 'LucidLab_MPC_Backup', $method ); $m->setAccessible( true ); return $m->invoke( null, ...$args ); }
function t_plain_sql_on_disk( string $dir ): array {
    $hits = array();
    foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
        if ( $f->isFile() && str_contains( (string) file_get_contents( $f->getPathname() ), 'secret-row-' ) ) { $hits[] = $f->getFilename(); }
    }
    return $hits;
}
$uploads = LucidLab_MPC_Helpers::uploads_basedir();
$dir     = LucidLab_MPC_Backup::dir();
t_assert( str_starts_with( $dir, $uploads . '/lucidlab-mpc-cleanup/backups-' ) && is_file( $dir . '/.htaccess' ), 'backups live in a protected uploads/lucidlab-mpc-cleanup/backups-<key>/ folder' );

// 1. Database backup: during and after the job, no file contains readable SQL rows.
$j = t_call( 'start', 'db' );
t_assert( str_ends_with( $j['raw_path'], '.sql.gz.part' ), 'the dump is written to a .sql.gz.part file' );
$steps = 0;
while ( 'running' === $j['status'] && $steps++ < 50 ) {
    $j = t_call( 'step', $j );
    t_assert( array() === t_plain_sql_on_disk( $uploads ), 'no plain SQL on disk during step ' . $steps );
    t_assert( array() === glob( $dir . '/*.sql' ), 'no .sql file during step ' . $steps );
}
t_assert( 'done' === $j['status'], 'database backup finishes' );
$file = $dir . '/' . $j['file'];
t_assert( str_ends_with( $file, '.sql.gz' ) && is_file( $file ), 'the backup is a .sql.gz file' );
t_assert( array() === glob( $dir . '/*.part' ), 'no .part file is left' );
t_assert( array() === t_plain_sql_on_disk( $uploads ), 'no plain SQL on disk after the backup' );
t_assert( LucidLab_MPC_Backup::backup_signature_valid( $file ), 'the multi-member gzip dump carries a valid signature' );
$sql = implode( '', gzfile( $file ) );
t_assert( 5000 === substr_count( $sql, 'INSERT INTO' ) && str_contains( $sql, 'secret-row-4999' ), 'every row is in the dump' );
t_assert( (int) $j['raw_bytes'] === strlen( $sql ), 'the uncompressed size is recorded' );
update_option( LucidLab_MPC_Backup::JOB_OPTION, $j );
$listed = LucidLab_MPC_Backup::list_backups();
t_assert( 1 === count( $listed ) && 'signed' === $listed[0]['signature'] && (int) $listed[0]['raw_bytes'] === strlen( $sql ), 'the list shows one signed backup with its SQL size' );

// 2. A tampered dump is refused.
$bad = $dir . '/db-20261001-120000-aaaaaa.sql.gz';
file_put_contents( $bad, gzencode( str_replace( 'secret-row-1x', 'secret-row-9x', $sql ) ) );
t_assert( ! LucidLab_MPC_Backup::backup_signature_valid( $bad ), 'an edited dump is not signed' );
unlink( $bad );

// 2b. Restore pre-check: an unchanged backup that already passed the full check is not decompressed again.
$pre = t_call( 'precheck_restore', 'restore_db', $j['file'] );
t_assert( ! empty( $pre['ok'] ) && ! empty( $pre['cached'] ), 'the pre-check reuses the check made when the backup was written' );
$good  = file_get_contents( $file );
$mtime = filemtime( $file );
$evil  = $good;
$evil[ (int) ( strlen( $evil ) / 2 ) ] = chr( ord( $evil[ (int) ( strlen( $evil ) / 2 ) ] ) ^ 0x01 );
file_put_contents( $file, $evil );
touch( $file, $mtime );
clearstatcache();
t_assert( filesize( $file ) === strlen( $good ) && filemtime( $file ) === $mtime, 'the tampered file keeps its size and date' );
$refused = false;
try { t_call( 'precheck_restore', 'restore_db', $j['file'] ); } catch ( RuntimeException $e ) { $refused = true; }
t_assert( $refused, 'a changed file with the same size and date is fully re-checked and refused (content hash is part of the key)' );
t_assert( empty( get_option( LucidLab_MPC_Backup::VERIFIED_OPTION, array() )[ $j['file'] ] ), 'the failed check removes the cached result' );
// Even a cache entry that matches a tampered file cannot skip the check when the restore itself starts.
$forged = get_option( LucidLab_MPC_Backup::VERIFIED_OPTION, array() );
$forged[ $j['file'] ] = t_call( 'verified_fingerprint', $file );
update_option( LucidLab_MPC_Backup::VERIFIED_OPTION, $forged );
t_assert( ! empty( t_call( 'precheck_restore', 'restore_db', $j['file'] )['cached'] ), 'the pre-check trusts a matching cache entry' );
$refused = false;
try { t_call( 'start', 'restore_db', $j['file'] ); } catch ( RuntimeException $e ) { $refused = true; }
t_assert( $refused, 'starting the restore always runs the full server-side signature check' );
file_put_contents( $file, $good );
touch( $file, $mtime );
clearstatcache();
update_option( LucidLab_MPC_Backup::VERIFIED_OPTION, array() );
$pre = t_call( 'precheck_restore', 'restore_db', $j['file'] );
t_assert( ! empty( $pre['ok'] ) && empty( $pre['cached'] ), 'without a cached result the pre-check runs the full check' );
t_assert( ! empty( t_call( 'precheck_restore', 'restore_db', $j['file'] )['cached'] ), 'and the next pre-check is served from the cache' );
update_option( LucidLab_MPC_Backup::JOB_OPTION, $j );

// 3. Restore: the decompressed copy is a protected temporary file that is deleted at the end.
$GLOBALS['wpdb']->queries = array();
$r = t_call( 'start', 'restore_db', $j['file'] );
$r = t_call( 'step', $r );
t_assert( str_ends_with( (string) $r['sql_path'], '.restore.tmp' ) && dirname( $r['sql_path'] ) === $dir, 'restore decompresses into the protected backup folder under a .restore.tmp name' );
for ( $i = 0; $i < 400 && 'running' === $r['status']; ++$i ) { $r = t_call( 'step', $r ); }
t_assert( 'done' === $r['status'] && 5000 === count( array_filter( $GLOBALS['wpdb']->queries, static fn( $q ) => str_starts_with( $q, 'INSERT' ) ) ), 'restore runs every row' );
t_assert( array() === glob( $dir . '/*.restore.tmp' ), 'the restore copy is deleted when the restore ends' );

// 4. A failed or cancelled backup leaves no dump behind.
$k = t_call( 'start', 'db' );
$k = t_call( 'step', t_call( 'step', $k ) );
t_assert( is_file( $k['raw_path'] ), 'a running dump exists' );
t_call( 'cleanup_partial', $k );
t_assert( ! is_file( $k['raw_path'] ), 'cleanup after failure removes the partial dump' );

update_option( LucidLab_MPC_Backup::JOB_OPTION, array( 'status' => 'failed' ) );

// 5. Older stored .sql dumps are compressed, keep their signature and their date.
$legacy = $dir . '/db-20261002-120000-bbbbbb.sql';
file_put_contents( $legacy, "-- MPC\nINSERT INTO t VALUES ('secret-row-legacy');\n-- MPC\n" );
t_call( 'sign_sql_file', $legacy );
touch( $legacy, 1700000000 );
t_assert( LucidLab_MPC_Backup::compress_legacy_sql( 5.0 ), 'legacy compression finishes' );
t_assert( ! is_file( $legacy ) && is_file( $legacy . '.gz' ) && 1700000000 === filemtime( $legacy . '.gz' ), 'the .sql became .sql.gz with the same date' );
t_assert( LucidLab_MPC_Backup::backup_signature_valid( $legacy . '.gz' ), 'the compressed legacy dump keeps a valid signature' );
t_assert( array() === t_plain_sql_on_disk( $uploads ), 'no plain SQL is left after compression' );

// 6. Stale temporary files from a crashed request are swept when no job runs.
update_option( LucidLab_MPC_Backup::JOB_OPTION, array( 'status' => 'done' ) );
$stale = $dir . '/db-20261003-120000-cccccc.sql.gz.part';
file_put_contents( $stale, 'x' );
touch( $stale, time() - 7200 );
LucidLab_MPC_Backup::list_backups();
t_assert( ! is_file( $stale ), 'stale .part files are removed' );

echo "Gzip database dump: no plain SQL on disk while dumping, after it, on restore or for older .sql files; signatures stay valid; temporary files are removed.\n";
