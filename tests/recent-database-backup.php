<?php
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages stay plain text; HTML output is escaped and AJAX responses are JSON-encoded.
/** Standalone regression test: php tests/recent-database-backup.php */
declare(strict_types=1);

$root = sys_get_temp_dir() . '/lucidlab-mpc-recent-backup-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root ) || ! mkdir( $root . '/lucidlab-mpc-backups' ) ) {
	throw new RuntimeException( 'Cannot create temporary backup directory.' );
}
define( 'ABSPATH', $root . '/' );
define( 'WP_CONTENT_DIR', $root );
define( 'DAY_IN_SECONDS', 86400 );
$backup_job = array();
function get_option( string $name, $default = false ) {
	global $backup_job;
	if ( 'lucidlab_mpc_backup_dir_key' === $name ) {
		return str_repeat( 'a', 32 );
	}
	return 'lucidlab_mpc_backup_job' === $name ? $backup_job : $default;
}
function update_option( string $name, $value ): bool {
	return true;
}
function __( string $text ): string {
	return $text;
}
function wp_mkdir_p( string $dir ): bool {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}
class LucidLab_MPC_Helpers {
	public static function uploads_basedir(): string {
		return WP_CONTENT_DIR;
	}
}
require __DIR__ . '/../includes/class-lucidlab-mpc-storage.php';
require __DIR__ . '/../includes/class-lucidlab-mpc-backup.php';

$dir = LucidLab_MPC_Backup::dir();
if ( ! is_dir( $dir ) ) {
	mkdir( $dir );
}
$name = static fn( string $kind ): string => $kind . '-20261009-120000-abcdef.sql.gz';
$check = static function ( bool $expected, string $case ): void {
	if ( LucidLab_MPC_Backup::recent_database_backup() !== $expected ) {
		throw new RuntimeException( $case . ' returned the wrong backup state.' );
	}
};

try {
	$check( false, 'empty directory' );
	file_put_contents( $dir . '/' . $name( 'uploads' ), 'archive' );
	$check( false, 'uploads-only backup' );
	file_put_contents( $dir . '/' . $name( 'db' ), 'archive' );
	touch( $dir . '/' . $name( 'db' ), time() - 2 * DAY_IN_SECONDS );
	$check( false, 'stale database backup' );
	touch( $dir . '/' . $name( 'db' ), time() );
	clearstatcache();
	$check( true, 'recent database backup' );
	unlink( $dir . '/' . $name( 'db' ) );
	file_put_contents( $dir . '/' . $name( 'site' ), 'archive' );
	$check( true, 'recent full-site backup' );
	unlink( $dir . '/' . $name( 'site' ) );
	file_put_contents( $dir . '/' . $name( 'db' ), '' );
	clearstatcache();
	$check( false, 'empty database backup' );
	file_put_contents( $dir . '/' . $name( 'db' ), 'partial' );
	$backup_job = array( 'status' => 'running', 'raw_path' => $dir . '/' . $name( 'db' ) );
	clearstatcache();
	$check( false, 'in-progress database backup' );
	echo "Recent database backup checks passed.\n";
} finally {
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $root );
}
