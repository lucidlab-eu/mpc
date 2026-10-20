<?php
/** Database backups are signed by this site and only signed, unchanged dumps pass the restore check. */
define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'LUCIDLAB_MPC_DIR', dirname( __DIR__ ) . '/' );
function __( $text ) { return $text; }
function get_option( $key, $default = null ) { return $GLOBALS['lucidlab_mpc_test_options'][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['lucidlab_mpc_test_options'][ $key ] = $value; return true; }
class LucidLab_MPC_Helpers {
	public static function extend_time_limit( int $seconds ): void {}
}
require dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-backup.php';

function lucidlab_mpc_sig_call( string $method, ...$args ) {
	$m = new ReflectionMethod( 'LucidLab_MPC_Backup', $method );
	$m->setAccessible( true );
	return $m->invoke( null, ...$args );
}
function lucidlab_mpc_sig_assert( bool $ok, string $what ): void {
	if ( ! $ok ) { fwrite( STDERR, "FAIL: $what\n" ); exit( 1 ); }
}
function lucidlab_mpc_sig_refused( string $path ): bool {
	try { lucidlab_mpc_sig_call( 'assert_restorable_sql', $path ); return false; } catch ( RuntimeException $e ) { return str_contains( $e->getMessage(), 'was not created by Media & Products Cleanup' ); }
}

$file = tempnam( sys_get_temp_dir(), 'mpc-sig-' );
file_put_contents( $file, "CREATE TABLE t (a int);\nINSERT INTO t VALUES (1);" );
lucidlab_mpc_sig_assert( lucidlab_mpc_sig_refused( $file ), 'unsigned dump is refused' );

lucidlab_mpc_sig_call( 'sign_sql_file', $file );
lucidlab_mpc_sig_assert( lucidlab_mpc_sig_call( 'sql_signature_valid', $file ), 'signed dump verifies' );
lucidlab_mpc_sig_assert( ! lucidlab_mpc_sig_refused( $file ), 'signed dump is accepted' );
$signed = file_get_contents( $file );
lucidlab_mpc_sig_call( 'sign_sql_file', $file );
lucidlab_mpc_sig_assert( file_get_contents( $file ) === $signed, 'signing twice changes nothing' );

$buffer = substr( $signed, strpos( $signed, "INSERT" ) + strlen( "INSERT INTO t VALUES (1);" ) );
lucidlab_mpc_sig_assert( '' === trim( lucidlab_mpc_sig_call( 'strip_signature_line', $buffer ) ), 'signature line is stripped from the restore buffer' );

file_put_contents( $file, str_replace( 'VALUES (1)', 'VALUES (2)', $signed ) );
lucidlab_mpc_sig_assert( lucidlab_mpc_sig_refused( $file ), 'edited dump is refused' );

file_put_contents( $file, $signed );
$GLOBALS['lucidlab_mpc_test_options'] = array( LucidLab_MPC_Backup::SIGN_OPTION => str_repeat( 'a', 64 ) );
lucidlab_mpc_sig_assert( lucidlab_mpc_sig_refused( $file ), 'dump signed by another site is refused' );

unlink( $file );
echo "Backup signature checks passed.\n";
