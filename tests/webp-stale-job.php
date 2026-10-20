<?php
/** Standalone regression: a WebP/AVIF conversion whose worker is gone recovers instead of holding the job slot forever. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
$GLOBALS['lucidlab_mpc_test_options'] = array();
$GLOBALS['lucidlab_mpc_test_uploads'] = sys_get_temp_dir() . '/lucidlab-mpc-webp-stale-' . bin2hex( random_bytes( 5 ) );
mkdir( $GLOBALS['lucidlab_mpc_test_uploads'] );
$source = $GLOBALS['lucidlab_mpc_test_uploads'] . '/sample.jpg';
file_put_contents( $source, base64_decode( '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAT/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdAAB//9k=' ) );

function __( $text ) { return $text; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, $decimals ); }
function get_option( $key, $default = false ) { return $GLOBALS['lucidlab_mpc_test_options'][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['lucidlab_mpc_test_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['lucidlab_mpc_test_options'][ $key ] ); return true; }
function wp_cache_delete() {}
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['lucidlab_mpc_test_cron'][ $hook ] ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['lucidlab_mpc_test_cron'][ $hook ] ?? false; }
function wp_schedule_single_event( $ts, $hook ) { $GLOBALS['lucidlab_mpc_test_cron'][ $hook ] = $ts; return true; }
function maybe_unserialize( $v ) { return is_string( $v ) && ( $u = @unserialize( $v ) ) !== false ? $u : $v; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function get_attached_file() { return $GLOBALS['lucidlab_mpc_test_uploads'] . '/sample.jpg'; }
function wp_get_attachment_metadata() { return array( 'sizes' => array() ); }
function update_meta_cache() {}
function wp_convert_hr_to_bytes( $value ) {
	$value = trim( (string) $value );
	return '-1' === $value ? -1 : (int) $value * ( str_ends_with( strtoupper( $value ), 'M' ) ? 1048576 : 1 );
}

class LucidLab_MPC_Settings {
	public static function save_page_webp() {}
}
class LucidLab_MPC_Edition {
	public static function pro(): bool { return true; }
}
class LucidLab_MPC_Helpers {
	public static function nextgen_caps() { return array( 'webp' => true, 'avif' => false ); }
	public static function clamp_image_quality( $value ) { return (int) $value; }
	public static function default_quality() { return 82; }
	public static function uploads_basedir() { return $GLOBALS['lucidlab_mpc_test_uploads']; }
	public static function resource_error() { return null; }
	public static function job_datetime( $time ) { return gmdate( 'Y-m-d H:i:s', $time ); }
	public static function prepend_job_history() {}
}
class LucidLab_MPC_Test_Db {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $options = 'wp_options';
	public string $last_error = '';
	public int $num_queries = 0;
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_var() { ++$this->num_queries; return 1; }
	public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
	public function get_col( $prepared ) { ++$this->num_queries; return $GLOBALS['lucidlab_mpc_test_tasks'] ?? array(); }
	public function get_results( $prepared ) {
		++$this->num_queries;
		$cursor = (int) $prepared[1][1];
		$limit = (int) $prepared[1][4];
		$rows = array();
		for ( $id = $cursor + 1; $id <= min( 151, $cursor + $limit ); ++$id ) {
			$rows[] = (object) array( 'ID' => $id, 'meta_id' => $id, 'file' => 'sample.jpg' );
		}
		return $rows;
	}
}
if ( ! function_exists( 'wp_getimagesize' ) ) { function wp_getimagesize( $file ) { return is_readable( $file ) ? getimagesize( $file ) : false; } }
if ( ! function_exists( 'wp_delete_file' ) ) { function wp_delete_file( $file ) { if ( is_file( $file ) ) { unlink( $file ); } } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); } }
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); } }
$wpdb = new LucidLab_MPC_Test_Db();
$GLOBALS['lucidlab_mpc_test_cron'] = array();
require dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-storage.php';
require getenv( 'LucidLab_MPC_WEBP_SOURCE' ) ?: dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-webp.php';

function check( bool $ok, string $what ): void {
	if ( ! $ok ) {
		throw new RuntimeException( 'FAIL: ' . $what );
	}
	echo "ok - $what\n";
}
function saved_job(): array { return (array) get_option( LucidLab_MPC_Webp::JOB_OPTION, array() ); }
function put_job( array $over ): void {
	$GLOBALS['lucidlab_mpc_test_cron'] = array();
	update_option(
		LucidLab_MPC_Webp::JOB_OPTION,
		array_merge(
			array(
				'status'        => 'running',
				'driver'        => 'ajax',
				'formats'       => array( 'webp' ),
				'quality'       => array( 'webp' => array( 'mode' => 'all', 'images' => 82, 'thumbnails' => 82 ) ),
				'format_index'  => 0,
				'offset'        => 0,
				'total'         => 0,
				'indeterminate' => true,
				'items'         => array(),
				'discovery'     => array( 'cursor_id' => 0, 'cursor_meta_id' => 0, 'attachment_rows' => 0, 'done' => false ),
				'errors'        => array(),
				'updated'       => time(),
			),
			$over
		)
	);
}

try {
	$webp = new LucidLab_MPC_Webp();

	put_job( array() );
	$before = saved_job();
	$webp->recover_stale();
	check( saved_job() === $before, 'a recently saved running job is left alone' );

	// The browser tab that drove the job was closed: continue in the background.
	put_job( array( 'updated' => time() - 1000 ) );
	$webp->recover_stale();
	$j = saved_job();
	check( 'running' === $j['status'] && 'cron' === $j['driver'] && (int) $j['recovered_at'] >= time() - 5, 'an abandoned page-driven job continues in the background' );
	check( false !== wp_next_scheduled( LucidLab_MPC_Webp::CRON_HOOK ), 'its cron event is scheduled' );
	$again = saved_job();
	$webp->recover_stale();
	check( saved_job() === $again, 'recovery does not repeat until the job is stale again' );

	// Background job whose cron event was lost after a PHP fatal error.
	put_job( array( 'driver' => 'cron', 'updated' => time() - 1000 ) );
	$webp->recover_stale();
	check( 'running' === saved_job()['status'] && false !== wp_next_scheduled( LucidLab_MPC_Webp::CRON_HOOK ), 'a background job with a lost cron event is rescheduled' );

	// Run All that owned the conversion is gone.
	$GLOBALS['lucidlab_mpc_test_tasks'] = array();
	put_job( array( 'driver' => 'run_all', 'owner' => '1:runall-abc', 'updated' => time() - 1000 ) );
	$webp->recover_stale();
	$j = saved_job();
	check( 'failed' === $j['status'] && empty( $j['owner'] ) && 'ajax' === $j['driver'], 'a conversion owned by a Run All that no longer exists is released' );
	check( str_contains( $webp->job()['label'], 'Run All' ), 'the reason is shown in the job label' );

	// Run All still running: leave it to Run All.
	$GLOBALS['lucidlab_mpc_test_tasks'] = array( serialize( array( 'owner_token' => '1:runall-abc', 'status' => 'running', 'key' => 'run_all' ) ) );
	put_job( array( 'driver' => 'run_all', 'owner' => '1:runall-abc', 'updated' => time() - 1000 ) );
	$webp->recover_stale();
	check( 'running' === saved_job()['status'] && '1:runall-abc' === saved_job()['owner'], 'a conversion of a live Run All is kept' );
	$GLOBALS['lucidlab_mpc_test_tasks'] = array( serialize( array( 'owner_token' => '1:runall-abc', 'status' => 'done', 'key' => 'run_all' ) ) );
	put_job( array( 'driver' => 'run_all', 'owner' => '1:runall-abc', 'updated' => time() - 1000 ) );
	$webp->recover_stale();
	check( 'failed' === saved_job()['status'], 'a finished Run All no longer holds its conversion' );

	// The live-site case: discovery started, nothing saved for more than six hours.
	put_job( array( 'driver' => 'cron', 'updated' => time() - 7 * 3600 ) );
	$GLOBALS['lucidlab_mpc_test_cron'][ LucidLab_MPC_Webp::CRON_HOOK ] = time() + 30;
	$webp->recover_stale();
	$j = saved_job();
	check( 'failed' === $j['status'] && false === wp_next_scheduled( LucidLab_MPC_Webp::CRON_HOOK ), 'a job without progress for 6 hours is marked failed and its cron event removed' );
	check( str_contains( $webp->job()['label'], 'no progress was saved since' ), 'the failure says why' );
	check( ! in_array( $webp->job()['status'], array( 'running', 'paused' ), true ), 'a released job no longer counts as pending for the job-slot check' );

	// Workers that die mid-step (fatal error/timeout) are detected through the in-flight marker.
	put_job( array( 'driver' => 'cron', 'inflight' => time() - 60, 'interrupts' => 0 ) );
	$webp->job_action( 'cron' );
	$j = saved_job();
	check( 'running' === $j['status'] && empty( $j['inflight'] ) && 0 === (int) $j['interrupts'] && (int) $j['discovery']['attachment_rows'] > 0, 'one interrupted step is retried and then progresses' );
	put_job( array( 'driver' => 'cron', 'inflight' => time() - 60, 'interrupts' => LucidLab_MPC_Webp::MAX_INTERRUPTS - 1 ) );
	$webp->job_action( 'cron' );
	$j = saved_job();
	check( 'failed' === $j['status'] && str_contains( $webp->job()['label'], 'ended without saving 3 times' ) && 0 === (int) $j['discovery']['attachment_rows'], 'repeated interrupted steps mark the job failed without running it again' );

	// A step marks itself in flight before working, so a fatal error leaves the marker behind.
	put_job( array( 'driver' => 'cron' ) );
	$webp->job_action( 'cron' );
	check( empty( saved_job()['inflight'] ), 'a completed step clears its in-flight marker' );

	// Cancel still works on a stale job.
	put_job( array( 'driver' => 'run_all', 'owner' => '1:gone', 'updated' => time() - 1000 ) );
	$webp->job_action( 'cancel' );
	check( 'cancelled' === saved_job()['status'], 'cancel of a stale job still cancels it' );
	echo "WebP stale-job recovery passed.\n";
} finally {
	unlink( $source );
	rmdir( $GLOBALS['lucidlab_mpc_test_uploads'] );
}
