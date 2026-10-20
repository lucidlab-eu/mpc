<?php
/** Plugin data lives in uploads/lucidlab-mpc-cleanup/, protected; old folders are moved once, and kept in use when a move fails. */
$root = sys_get_temp_dir() . '/lucidlab-mpc-storage-test-' . bin2hex( random_bytes( 4 ) );
define( 'ABSPATH', $root . '/site/' );
define( 'LUCIDLAB_MPC_DIR', dirname( __DIR__ ) . '/' );
register_shutdown_function(
	static function () use ( $root ): void {
		if ( ! is_dir( $root ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $root );
	}
);
$GLOBALS['opts']    = array();
$GLOBALS['site']    = 1;
$GLOBALS['logged']  = array();
function __( $text ) { return $text; }
function get_option( $key, $default = false ) { return $GLOBALS['opts'][ $GLOBALS['site'] ][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['opts'][ $GLOBALS['site'] ][ $key ] = $value; return true; }
function add_option( $key, $value ) { if ( isset( $GLOBALS['opts'][ $GLOBALS['site'] ][ $key ] ) ) { return false; } $GLOBALS['opts'][ $GLOBALS['site'] ][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['opts'][ $GLOBALS['site'] ][ $key ] ); return true; }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); }
function wp_delete_file( $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_file_name( $n ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $n ); }
class LucidLab_MPC_Helpers {
	public static function uploads_basedir() { return ABSPATH . 'wp-content/uploads' . ( 1 === $GLOBALS['site'] ? '' : '/sites/' . $GLOBALS['site'] ); }
	public static function uploads_baseurl() { return 'https://example.test/wp-content/uploads' . ( 1 === $GLOBALS['site'] ? '' : '/sites/' . $GLOBALS['site'] ); }
}
class LucidLab_MPC_Logger { public static function log( string $msg, string $level = 'info', bool $force = false ): void { $GLOBALS['logged'][] = $msg; } }
class LucidLab_MPC_Backup {
	public static array $job = array( 'status' => 'idle' );
	public static bool $compressed = true;
	public static function job(): array { return self::$job; }
	public static function compress_legacy_sql( float $budget = 15.0 ): bool { return self::$compressed; }
}
require dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-storage.php';
function check( bool $ok, string $what ): void { if ( ! $ok ) { fwrite( STDERR, "FAIL: $what\n" ); exit( 1 ); } }
function up( string $rel = '' ): string { return rtrim( str_replace( '\\', '/', LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) . ( '' === $rel ? '' : '/' . $rel ); }
function put( string $path, string $body = 'x' ): void { wp_mkdir_p( dirname( $path ) ); file_put_contents( $path, $body ); }

// 1. Fresh site: every folder is under uploads/lucidlab-mpc-cleanup/ with the right protection.
mkdir( up(), 0777, true );
check( up( 'lucidlab-mpc-cleanup/work' ) === LucidLab_MPC_Storage::dir( 'work' ), 'work folder is uploads/lucidlab-mpc-cleanup/work' );
check( up( 'lucidlab-mpc-cleanup/quarantine' ) === LucidLab_MPC_Storage::dir( 'quarantine' ), 'quarantine folder is in the plugin folder' );
check( up( 'lucidlab-mpc-cleanup/backups-' . str_repeat( 'b', 32 ) ) === LucidLab_MPC_Storage::dir( 'backups-' . str_repeat( 'b', 32 ) ), 'backup folder keeps its random key' );
check( up( 'lucidlab-mpc-cleanup/webp' ) === LucidLab_MPC_Storage::dir( 'webp' ), 'webp copies go to the plugin folder' );
foreach ( array( 'work', 'quarantine', 'backups-' . str_repeat( 'b', 32 ) ) as $sub ) {
	foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $f ) {
		check( is_file( up( "lucidlab-mpc-cleanup/$sub/$f" ) ), "$sub has $f" );
	}
	check( str_contains( (string) file_get_contents( up( "lucidlab-mpc-cleanup/$sub/.htaccess" ) ), 'Require all denied' ), "$sub denies web access" );
}
check( is_file( up( 'lucidlab-mpc-cleanup/index.php' ) ) && ! is_file( up( 'lucidlab-mpc-cleanup/.htaccess' ) ), 'base folder has index.php only (public copy folders must stay reachable)' );
check( is_file( up( 'lucidlab-mpc-cleanup/webp/index.php' ) ) && ! is_file( up( 'lucidlab-mpc-cleanup/webp/.htaccess' ) ), 'webp folder is not denied' );
check( 'https://example.test/wp-content/uploads/lucidlab-mpc-cleanup/webp/a.jpg.webp' === LucidLab_MPC_Storage::url_for( up( 'lucidlab-mpc-cleanup/webp/a.jpg.webp' ) ), 'url_for maps plugin files to uploads URLs' );
check( '' === LucidLab_MPC_Storage::url_for( ABSPATH . 'wp-config.php' ), 'url_for refuses paths outside uploads' );
$bad = false;
try { LucidLab_MPC_Storage::dir( '../x' ); } catch ( InvalidArgumentException $e ) { $bad = true; }
check( $bad, 'subfolder names cannot leave the plugin folder' );

// 2. Multisite: another site gets its own uploads/sites/N/lucidlab-mpc-cleanup/.
$GLOBALS['site'] = 3;
LucidLab_MPC_Storage::flush();
check( up( 'lucidlab-mpc-cleanup/work' ) === LucidLab_MPC_Storage::dir( 'work' ) && str_contains( LucidLab_MPC_Storage::dir( 'work' ), '/uploads/sites/3/' ), 'site 3 uses its own uploads folder' );

// 3. Upgrade: old folders stay in use until the move, then are moved with their contents and random names.
$GLOBALS['site'] = 4;
LucidLab_MPC_Storage::flush();
$qkey = str_repeat( 'c', 32 );
$bkey = str_repeat( 'd', 32 );
put( up( "_cleanup-quarantine/$qkey/20261001-120000/manifest.json" ), '{"files":[{"rel":"2024/a.jpg"}]}' );
put( up( '_cleanup-fsindex/scan.jsonl' ) );
put( up( '_cleanup-format-preview/p.webp' ) );
put( up( "lucidlab-mpc-backups-$bkey/db-20261001-120000-abcdef.sql.gz" ), 'gz' );
put( up( 'webp/2024/a.jpg.webp' ), 'RIFF' );
put( up( 'avif/2024/a.jpg.avif' ), 'avif' );
check( LucidLab_MPC_Storage::needs_migration(), 'an upgraded site needs the move' );
check( up( '_cleanup-quarantine' ) === LucidLab_MPC_Storage::dir( 'quarantine' ), 'before the move the old quarantine folder is used' );
check( up( "lucidlab-mpc-backups-$bkey" ) === LucidLab_MPC_Storage::dir( "backups-$bkey" ), 'before the move the old backup folder is used' );
LucidLab_MPC_Backup::$job = array( 'status' => 'running' );
$state = LucidLab_MPC_Storage::migrate();
check( ! empty( $state['retry'] ) && is_dir( up( '_cleanup-quarantine' ) ), 'nothing moves while a backup job runs' );
LucidLab_MPC_Backup::$job = array( 'status' => 'idle' );
$state = LucidLab_MPC_Storage::migrate();
check( empty( $state['retry'] ), 'move completes' );
foreach ( array( 'work', 'quarantine', 'format-preview', "backups-$bkey" ) as $sub ) {
	check( 'moved' === ( $state['dirs'][ $sub ] ?? '' ), "$sub recorded as moved" );
}
check( is_file( up( "lucidlab-mpc-cleanup/quarantine/$qkey/20261001-120000/manifest.json" ) ), 'quarantine sets keep their random folder and manifests' );
check( is_file( up( "lucidlab-mpc-cleanup/backups-$bkey/db-20261001-120000-abcdef.sql.gz" ) ), 'stored backups are moved' );
check( is_file( up( 'lucidlab-mpc-cleanup/work/scan.jsonl' ) ) && is_file( up( 'lucidlab-mpc-cleanup/format-preview/p.webp' ) ), 'work and preview files are moved' );
check( ! is_dir( up( '_cleanup-quarantine' ) ) && ! is_dir( up( '_cleanup-fsindex' ) ) && ! is_dir( up( "lucidlab-mpc-backups-$bkey" ) ), 'old folders are gone from the uploads root' );
check( is_file( up( "lucidlab-mpc-cleanup/backups-$bkey/.htaccess" ) ) && is_file( up( 'lucidlab-mpc-cleanup/quarantine/web.config' ) ), 'moved private folders are protected' );
check( 'kept' === ( $state['dirs']['webp'] ?? '' ) && is_file( up( 'webp/2024/a.jpg.webp' ) ) && is_file( up( 'avif/2024/a.jpg.avif' ) ), 'existing WebP/AVIF copies stay where library records and content point at them' );
check( up( 'lucidlab-mpc-cleanup/webp' ) === LucidLab_MPC_Storage::dir( 'webp' ), 'new WebP copies still go to the plugin folder' );
check( up( 'lucidlab-mpc-cleanup/quarantine' ) === LucidLab_MPC_Storage::dir( 'quarantine' ), 'after the move the new quarantine folder is used' );
check( ! LucidLab_MPC_Storage::needs_migration() && array() !== $GLOBALS['logged'], 'the move runs once and is logged' );
mkdir( up( '_cleanup-quarantine' ) );
LucidLab_MPC_Storage::flush();
check( up( 'lucidlab-mpc-cleanup/quarantine' ) === LucidLab_MPC_Storage::dir( 'quarantine' ), 'a later empty old folder (for example from an old site backup) never hides the moved data' );

// 4. A move that fails leaves the old folder in place and in use, and is retried.
$GLOBALS['site'] = 5;
LucidLab_MPC_Storage::flush();
put( up( '_cleanup-quarantine/' . $qkey . '/s1/manifest.json' ) );
mkdir( up( 'lucidlab-mpc-cleanup' ), 0777, true );
file_put_contents( up( 'lucidlab-mpc-cleanup/quarantine' ), 'a file where the folder should go' );
$state = LucidLab_MPC_Storage::migrate();
check( 'failed' === ( $state['dirs']['quarantine'] ?? '' ) && ! empty( $state['retry'] ), 'failed move is recorded for a retry' );
check( is_file( up( '_cleanup-quarantine/' . $qkey . '/s1/manifest.json' ) ), 'a failed move loses nothing' );
check( up( '_cleanup-quarantine' ) === LucidLab_MPC_Storage::dir( 'quarantine', false ), 'the old folder keeps being read after a failed move' );
check( LucidLab_MPC_Storage::needs_migration(), 'a failed move is tried again later' );
unlink( up( 'lucidlab-mpc-cleanup/quarantine' ) );
$state = LucidLab_MPC_Storage::migrate();
check( 'moved' === ( $state['dirs']['quarantine'] ?? '' ) && is_file( up( "lucidlab-mpc-cleanup/quarantine/$qkey/s1/manifest.json" ) ), 'the retry moves the folder' );

// 5. Both folders exist: entries are merged, collisions stay in the old folder.
$GLOBALS['site'] = 6;
LucidLab_MPC_Storage::flush();
put( up( '_cleanup-fsindex/a.jsonl' ) );
put( up( '_cleanup-fsindex/same.jsonl' ), 'old' );
put( up( 'lucidlab-mpc-cleanup/work/same.jsonl' ), 'new' );
$state = LucidLab_MPC_Storage::migrate();
check( is_file( up( 'lucidlab-mpc-cleanup/work/a.jsonl' ) ) && 'new' === file_get_contents( up( 'lucidlab-mpc-cleanup/work/same.jsonl' ) ), 'merge moves new names and never overwrites' );
check( is_file( up( '_cleanup-fsindex/same.jsonl' ) ) && 'failed' === $state['dirs']['work'], 'a colliding file stays in the old folder and the move is retried' );

// 6. Stored plain .sql dumps that cannot be compressed yet keep the move open.
$GLOBALS['site'] = 7;
LucidLab_MPC_Storage::flush();
LucidLab_MPC_Backup::$compressed = false;
check( ! empty( LucidLab_MPC_Storage::migrate()['retry'] ), 'unfinished SQL compression is retried' );
LucidLab_MPC_Backup::$compressed = true;

echo "Storage locations: plugin folder under uploads, protection files, multisite, one-time move, fallback after a failed move, WebP copies kept, merge without overwrite.\n";
