<?php
/** Standalone regression: a direct table-quarantine call must fail before SQL. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
function __( $text ) { return $text; }
class LucidLab_MPC_Backup {
	public static bool $recent = false;
	public static function recent_database_backup(): bool { return self::$recent; }
}
class LucidLab_MPC_Test_Db {
	public int $queries = 0;
	public function query() { ++$this->queries; throw new RuntimeException( 'SQL must not be reached.' ); }
}
$wpdb = new LucidLab_MPC_Test_Db();
require __DIR__ . '/../includes/class-lucidlab-mpc-quarantine.php';

try {
	( new LucidLab_MPC_Quarantine() )->quarantine_tables( array( 'wp_disposable_fixture' ), 'fixture' );
	throw new RuntimeException( 'Direct table quarantine bypassed the backup gate.' );
} catch ( RuntimeException $error ) {
	if ( ! str_contains( $error->getMessage(), 'backup' ) || $wpdb->queries !== 0 ) {
		throw $error;
	}
}
echo "Direct table quarantine blocked before any SQL without a recent backup.\n";
