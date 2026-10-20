<?php
/** Standalone regression: the media index fills without WP-Cron, from the plugin page, and recovers a lost cron event. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'LUCIDLAB_MPC_VERSION', '9.9.9' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['mpc_options'] = array();
$GLOBALS['mpc_cron']    = array();
$GLOBALS['mpc_admin']   = true;
$GLOBALS['mpc_rows']    = array();
for ( $i = 1; $i <= 26; $i++ ) {
	$GLOBALS['mpc_rows'][] = array( 'ID' => $i * 3, 'post_title' => 'Image ' . $i, 'post_mime_type' => 'image/jpeg' );
}
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['mpc_options'] ) ? $GLOBALS['mpc_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['mpc_options'][ $name ] = $value; return true; }
function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['mpc_options'] ) ) { return false; }
	$GLOBALS['mpc_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) { unset( $GLOBALS['mpc_options'][ $name ] ); return true; }
function wp_next_scheduled( $hook ) { return $GLOBALS['mpc_cron'][ $hook ] ?? false; }
function wp_schedule_single_event( $ts, $hook ) { $GLOBALS['mpc_cron'][ $hook ] = $ts; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['mpc_cron'][ $hook ] ); return 0; }
function is_admin() { return $GLOBALS['mpc_admin']; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return ! $GLOBALS['mpc_admin']; }
function current_user_can( $cap ) { return true; }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ); }
function wp_unslash( $value ) { return $value; }
function update_meta_cache( $type, $ids ) { return true; }
function get_attached_file( $id ) { return '/uploads/img-' . $id . '.jpg'; }
class LucidLab_MPC_Logger {
	public static array $lines = array();
	public static function diag( string $area, string $msg, array $context = array(), string $level = 'info', int $every = 0 ): void { self::$lines[] = $level . ' [' . $area . '] ' . $msg; }
}
class LucidLab_MPC_Helpers {
	public static function admin_dir(): string {
		$dir = sys_get_temp_dir() . '/lucidlab-mpc-test-admin/';
		if ( ! is_file( $dir . 'includes/upgrade.php' ) ) {
			@mkdir( $dir . 'includes', 0777, true );
			file_put_contents( $dir . 'includes/upgrade.php', "<?php\nfunction dbDelta( \$sql ) { return array(); }\n" );
		}
		return $dir;
	}
	public static function int_ids( $values ): array { return array_values( array_unique( array_filter( array_map( 'intval', (array) $values ) ) ) ); }
}
class LucidLab_MPC_Test_Db {
	public string $prefix = 'wp_';
	public string $posts  = 'wp_posts';
	public array $table   = array();
	public array $columns = array( 'id', 'title', 'mime', 'file', 'mtime', 'dirty', 'removed' );
	public string $last_error = '';
	public function get_col( $q ) { return str_starts_with( $q, 'SHOW COLUMNS' ) ? $this->columns : array(); }
	public function prepare( $sql, ...$args ) {
		return preg_replace_callback(
			'/%[dsi]/',
			static function ( $m ) use ( &$args ) {
				$v = array_shift( $args );
				return '%d' === $m[0] ? (string) (int) $v : ( '%i' === $m[0] ? '`' . $v . '`' : "'" . addslashes( (string) $v ) . "'" );
			},
			$sql
		);
	}
	public function esc_like( $text ) { return $text; }
	public function get_charset_collate() { return ''; }
	public function query( $q ) {
		$sql = $q;
		if ( str_starts_with( $sql, 'DELETE FROM' ) ) { $this->table = array(); }
		if ( str_starts_with( $sql, 'REPLACE INTO' ) && ! in_array( 'removed', $this->columns, true ) ) { $this->last_error = "Unknown column 'removed'"; return false; }
		if ( str_starts_with( $sql, 'REPLACE INTO' ) && preg_match_all( '/\((\d+),/', $sql, $m ) ) {
			foreach ( $m[1] as $id ) { $this->table[ (int) $id ] = true; }
		}
		return 1;
	}
	public function get_var( $q ) {
		$sql = $q;
		$this->last_error = '';
		if ( str_starts_with( $sql, 'SHOW TABLES' ) ) { return 'wp_lucidlab_mpc_media_index'; }
		if ( str_contains( $sql, 'removed=0' ) && ! in_array( 'removed', $this->columns, true ) ) { $this->last_error = "Unknown column 'removed'"; return null; }
		$exact = ! str_contains( $sql, 'LIMIT 1' ); // Full counts, not "any rows?" probes.
		if ( str_contains( $sql, "post_type='attachment'" ) && $exact ) { return count( $GLOBALS['mpc_rows'] ) + ( $GLOBALS['mpc_unreadable'] ?? 0 ); }
		if ( str_starts_with( $sql, 'SELECT COUNT(*) FROM `' ) && $exact ) { return count( $this->table ); }
		if ( str_contains( $sql, "post_type='attachment'" ) ) { return count( $GLOBALS['mpc_rows'] ) > 0 ? 1 : 0; }
		return count( $this->table ) > 0 ? 1 : 0;
	}
	public function get_row( $q ) { return (object) array( 'n' => count( $this->table ), 'last_id' => $this->table ? max( array_keys( $this->table ) ) : 0 ); }
	public function get_results( $q, $output = null ) {
		$after = preg_match( '/ID>(\d+)/', $q, $m ) ? (int) $m[1] : 0;
		return array_values( array_filter( $GLOBALS['mpc_rows'], static fn( $r ) => $r['ID'] > $after ) );
	}
}
$wpdb = new LucidLab_MPC_Test_Db();
require __DIR__ . '/../includes/class-lucidlab-mpc-index.php';
function mpc_assert( bool $ok, string $what ): void { if ( ! $ok ) { throw new RuntimeException( $what ); } }

// Table already installed (an upgrade, not an activation); the old build left a stale Ready flag over an empty table.
$GLOBALS['mpc_options'][ LucidLab_MPC_Index::DB_OPTION ] = LucidLab_MPC_Index::DB_VERSION;
$GLOBALS['mpc_options'][ LucidLab_MPC_Index::READY ]     = 1;
mpc_assert( ! LucidLab_MPC_Index::is_ready(), 'An empty table must not read as ready.' );

// 1) Another admin page: the build is queued for cron but nothing runs inline.
LucidLab_MPC_Index::maybe_schedule_build();
mpc_assert( false !== wp_next_scheduled( LucidLab_MPC_Index::AUTOBUILD_HOOK ), 'Build was not queued.' );
mpc_assert( 0 === count( $wpdb->table ), 'Build must not run inline outside the plugin page.' );

// 2) WP-Cron never fires and the event gets lost: the next admin load re-queues it.
wp_clear_scheduled_hook( LucidLab_MPC_Index::AUTOBUILD_HOOK );
LucidLab_MPC_Index::maybe_schedule_build();
mpc_assert( false !== wp_next_scheduled( LucidLab_MPC_Index::AUTOBUILD_HOOK ), 'Lost cron event was not re-queued.' );

// 3) The plugin's own page builds it inline, without WP-Cron.
$_GET['page'] = 'lucidlab-mpc-cleanup';
LucidLab_MPC_Index::maybe_schedule_build();
mpc_assert( 26 === count( $wpdb->table ), 'Inline step did not index all 26 images.' );
mpc_assert( LucidLab_MPC_Index::is_ready(), 'Index is not ready after the inline build.' );
mpc_assert( 26 === ( LucidLab_MPC_Index::get()['count'] ?? 0 ), 'Diagnostics count is not 26.' );
mpc_assert( false === get_option( LucidLab_MPC_Index::AUTOBUILD_LOCK ), 'Build lock was left behind.' );

// 4) A late cron event, called the way do_action() calls it, is a harmless no-op.
LucidLab_MPC_Index::autobuild_step( '' );
mpc_assert( 26 === count( $wpdb->table ) && LucidLab_MPC_Index::is_ready(), 'Late cron step broke the index.' );

// 5) A step that holds a fresh lock is skipped instead of overlapping.
LucidLab_MPC_Index::start_autobuild();
update_option( LucidLab_MPC_Index::AUTOBUILD_LOCK, time() );
LucidLab_MPC_Index::autobuild_step( 5 );
mpc_assert( 0 === count( $wpdb->table ), 'A locked build step still ran.' );
update_option( LucidLab_MPC_Index::AUTOBUILD_LOCK, time() - 120 );
$GLOBALS['mpc_admin'] = false;
LucidLab_MPC_Index::autobuild_step();
mpc_assert( 26 === count( $wpdb->table ) && LucidLab_MPC_Index::is_ready(), 'Cron step did not take over a stale lock.' );

// 6) A table from an older build without the `removed` column: the Ready flag must not stick over an
// empty table, the schema stamp is dropped so dbDelta runs again, and the next build fills the index.
$wpdb->table   = array();
$wpdb->columns = array( 'id', 'title', 'mime', 'file', 'mtime', 'dirty' );
$GLOBALS['mpc_admin'] = true;
update_option( LucidLab_MPC_Index::READY, 1 );
( new ReflectionProperty( 'LucidLab_MPC_Index', 'rows_memo' ) )->setValue( null, null ); // A new request.
mpc_assert( ! LucidLab_MPC_Index::is_ready(), 'Ready stuck over a table the build cannot read.' );
mpc_assert( false === get_option( LucidLab_MPC_Index::DB_OPTION ), 'Schema stamp kept after a failed read.' );
mpc_assert( (bool) array_filter( LucidLab_MPC_Logger::$lines, static fn( $l ) => str_starts_with( $l, 'error [media-index-schema]' ) ), 'Schema failure was not logged.' );
$wpdb->columns = array( 'id', 'title', 'mime', 'file', 'mtime', 'dirty', 'removed' ); // dbDelta added the column.
LucidLab_MPC_Index::maybe_schedule_build();
mpc_assert( 26 === count( $wpdb->table ) && LucidLab_MPC_Index::is_ready(), 'Index did not recover after the schema update.' );
mpc_assert( (bool) array_filter( LucidLab_MPC_Logger::$lines, static fn( $l ) => str_contains( $l, 'Build finished.' ) ), 'Build steps are not logged.' );

// 7) lucidlab.eu: 6.0.382 ran its build over the old table, every write failed but the cursor moved on.
// After the schema update the resumed build indexed only the 5 newest images. It must start over from ID 0.
$wpdb->table = array();
update_option( LucidLab_MPC_Index::DB_OPTION, '1' );
delete_option( LucidLab_MPC_Index::READY );
update_option( LucidLab_MPC_Index::AUTOBUILD, 63 ); // Stale cursor: only IDs 66..78 lie beyond it.
( new ReflectionProperty( 'LucidLab_MPC_Index', 'rows_memo' ) )->setValue( null, null );
LucidLab_MPC_Index::maybe_schedule_build();
mpc_assert( 26 === count( $wpdb->table ) && LucidLab_MPC_Index::is_ready(), 'A build resumed from a stale cursor indexed ' . count( $wpdb->table ) . ' of 26 images.' );

// 8) Even without a schema change, a finished build that is short of the library restarts once from ID 0.
$wpdb->table = array();
delete_option( LucidLab_MPC_Index::READY );
update_option( LucidLab_MPC_Index::AUTOBUILD, 63 );
LucidLab_MPC_Index::autobuild_step( 5 );
mpc_assert( 0 === (int) get_option( LucidLab_MPC_Index::AUTOBUILD, false ) && ! LucidLab_MPC_Index::is_ready(), 'A short build was marked Ready.' );
LucidLab_MPC_Index::autobuild_step( 5 );
mpc_assert( 26 === count( $wpdb->table ) && LucidLab_MPC_Index::is_ready(), 'The retry did not complete the index.' );

// 9) lucidlab.eu after 6.0.383 -> 6.0.384: a finished build left Ready over 5 rows while the library has 26.
// The table schema is current and no build runs, so neither install() nor the build-end check ran again.
// A version change must compare the Ready index with the library and rebuild it.
$wpdb->table = array();
foreach ( array_slice( $GLOBALS['mpc_rows'], -5 ) as $row ) { $wpdb->table[ $row['ID'] ] = true; }
update_option( LucidLab_MPC_Index::DB_OPTION, LucidLab_MPC_Index::DB_VERSION );
update_option( LucidLab_MPC_Index::READY, 1 );
update_option( LucidLab_MPC_Index::SEEN_VERSION, '6.0.383' );
delete_option( LucidLab_MPC_Index::AUTOBUILD );
delete_option( LucidLab_MPC_Index::OPTION );
wp_clear_scheduled_hook( LucidLab_MPC_Index::AUTOBUILD_HOOK );
( new ReflectionProperty( 'LucidLab_MPC_Index', 'rows_memo' ) )->setValue( null, null );
mpc_assert( LucidLab_MPC_Index::is_ready(), 'Setup: 5 rows with the Ready flag read as ready.' );
unset( $_GET['page'] ); // Upgrade lands on the Plugins screen or the theme Releases page.
LucidLab_MPC_Index::maybe_schedule_build();
mpc_assert( '9.9.9' === get_option( LucidLab_MPC_Index::SEEN_VERSION ), 'Version change was not recorded.' );
mpc_assert( false !== get_option( LucidLab_MPC_Index::AUTOBUILD, false ) && false !== wp_next_scheduled( LucidLab_MPC_Index::AUTOBUILD_HOOK ), 'Version change kept a Ready index with 5 of 26 images.' );
mpc_assert( ! LucidLab_MPC_Index::is_ready(), 'Index still reads Ready while it rebuilds.' );
mpc_assert( (bool) array_filter( LucidLab_MPC_Logger::$lines, static fn( $l ) => str_contains( $l, 'does not match the library' ) ), 'Mismatch was not logged.' );
$_GET['page'] = 'lucidlab-mpc-cleanup';
LucidLab_MPC_Index::maybe_schedule_build();
mpc_assert( 26 === count( $wpdb->table ) && LucidLab_MPC_Index::is_ready(), 'Rebuild after the version change indexed ' . count( $wpdb->table ) . ' of 26.' );
mpc_assert( false === get_option( LucidLab_MPC_Index::VERIFY_MISMATCH ), 'Mismatch marker kept after the index matched.' );

// 10) Same version, Ready with 5 rows: other admin pages do no check, the plugin page rebuilds.
$wpdb->table = array();
foreach ( array_slice( $GLOBALS['mpc_rows'], -5 ) as $row ) { $wpdb->table[ $row['ID'] ] = true; }
wp_clear_scheduled_hook( LucidLab_MPC_Index::AUTOBUILD_HOOK );
unset( $_GET['page'] );
LucidLab_MPC_Index::maybe_schedule_build();
mpc_assert( false === get_option( LucidLab_MPC_Index::AUTOBUILD, false ) && 5 === count( $wpdb->table ), 'Other admin pages must not rebuild a Ready index without a version change.' );
$_GET['page'] = 'lucidlab-mpc-cleanup';
LucidLab_MPC_Index::maybe_schedule_build();
mpc_assert( 26 === count( $wpdb->table ) && LucidLab_MPC_Index::is_ready(), 'Plugin page did not rebuild a Ready index with 5 of 26 images.' );

// 11) A matching index is left alone on the plugin page.
$lines = count( LucidLab_MPC_Logger::$lines );
LucidLab_MPC_Index::maybe_schedule_build();
mpc_assert( false === get_option( LucidLab_MPC_Index::AUTOBUILD, false ) && count( LucidLab_MPC_Logger::$lines ) === $lines, 'A matching index was rebuilt.' );

// 12) One library image the build cannot read: rebuild once, then accept the mismatch instead of looping.
$GLOBALS['mpc_unreadable'] = 1;
LucidLab_MPC_Index::maybe_schedule_build(); // Mismatch 26:27 -> rebuild, which ends 26:27 again (retry included).
LucidLab_MPC_Index::autobuild_step( 5 );
mpc_assert( LucidLab_MPC_Index::is_ready(), 'Short rebuild did not end Ready.' );
LucidLab_MPC_Index::maybe_schedule_build();
mpc_assert( false === get_option( LucidLab_MPC_Index::AUTOBUILD, false ) && LucidLab_MPC_Index::is_ready(), 'The same mismatch started another rebuild.' );
$GLOBALS['mpc_unreadable'] = 0;

echo "Media index builds inline on the plugin page, re-queues lost cron events and never overlaps steps, recovers from an outdated table schema and a stale build cursor, and rebuilds a Ready index that is short of the library after a version change or on the plugin page.\n";
