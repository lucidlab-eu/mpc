<?php
/** Standalone regression for bounded, pausable WebP work discovery. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
$GLOBALS['lucidlab_mpc_test_options'] = array();
$GLOBALS['lucidlab_mpc_test_uploads'] = sys_get_temp_dir() . '/lucidlab-mpc-webp-discovery-' . bin2hex( random_bytes( 5 ) );
mkdir( $GLOBALS['lucidlab_mpc_test_uploads'] );
$source = $GLOBALS['lucidlab_mpc_test_uploads'] . '/sample.jpg';
file_put_contents( $source, base64_decode( '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAT/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdAAB//9k=' ) );

function __( $text ) { return $text; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, $decimals ); }
function get_option( $key, $default = false ) { return $GLOBALS['lucidlab_mpc_test_options'][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['lucidlab_mpc_test_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['lucidlab_mpc_test_options'][ $key ] ); return true; }
function wp_cache_delete() {}
function wp_clear_scheduled_hook() {}
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
	public string $last_error = '';
	public int $num_queries = 0;
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_var() { ++$this->num_queries; return 1; }
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
require dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-storage.php';
// Free's class is the only WebP engine; Pro loads it from Free (LUCIDLAB_MPC_DIR).
require getenv( 'LucidLab_MPC_WEBP_SOURCE' ) ?: dirname( __DIR__ ) . '/includes/class-lucidlab-mpc-webp.php';

try {
	$webp = new LucidLab_MPC_Webp();
	$job = $webp->job_action( 'start', array( 'webp' => array( 'enabled' => 1, 'images' => 85, 'thumbnails' => 85 ), 'skip_existing' => 0 ) );
	if ( 'running' !== $job['status'] || 0 !== $job['total'] || ! isset( $job['discovery'] ) || empty( $job['indeterminate'] ) ) { throw new RuntimeException( 'Start did not defer discovery with indeterminate progress.' ); }
	$job['discovery']['started_at'] -= 540;
	update_option( 'lucidlab_mpc_webp_job', $job );
	$job = $webp->job_action( 'step' );
	if ( (int) $job['discovery']['attachment_rows'] < 1 || (int) $job['discovery']['attachment_rows'] > 75 ) { throw new RuntimeException( 'Discovery exceeded one bounded batch.' ); }
	$cursor = (int) $job['discovery']['cursor_id'];
	$job = $webp->job_action( 'stop' );
	if ( 'paused' !== $job['status'] ) { throw new RuntimeException( 'Discovery did not pause.' ); }
	$job = $webp->job_action( 'resume' );
	if ( 'running' !== $job['status'] || $cursor !== (int) $job['discovery']['cursor_id'] ) { throw new RuntimeException( 'Resume lost the discovery cursor.' ); }
	for ( $tick = 0; $tick < 5 && empty( $job['discovery']['done'] ); ++$tick ) { $job = $webp->job_action( 'step' ); }
	if ( empty( $job['discovery']['done'] ) || ! empty( $job['indeterminate'] ) || 151 !== $job['total'] || 151 !== count( array_unique( $job['items'] ) ) ) { throw new RuntimeException( 'Discovery lost or repeated attachments or did not enable determinate progress.' ); }
	$profile = get_option( 'lucidlab_mpc_webp_discovery_profile' );
	if ( 151 !== $profile['attachment_rows'] || 151 !== $profile['work_ids'] ) { throw new RuntimeException( 'Discovery profile is inaccurate.' ); }
	if ( (float) $profile['seconds'] < 540 || (float) $profile['processing_seconds'] >= 30 ) { throw new RuntimeException( 'Discovery elapsed time omitted waits between batches.' ); }
	$label = LucidLab_MPC_Webp::conversion_progress_label( array( 'status' => 'running', 'formats' => array( 'webp' ), 'format_index' => 0, 'offset' => 1, 'total' => 2, 'current_file' => 'sample.jpg' ) );
	if ( ! str_contains( $label, 'sample.jpg' ) ) { throw new RuntimeException( 'Conversion progress omitted the current filename.' ); }
	$apply_stop = new ReflectionMethod( LucidLab_MPC_Webp::class, 'apply_stop_request' );
	foreach ( array( 'ajax', 'run_all' ) as $driver ) {
		update_option( LucidLab_MPC_Webp::STOP_OPTION, 'cancel' );
		$active_job = array( 'status' => 'running', 'driver' => $driver );
		$args = array( &$active_job );
		if ( ! $apply_stop->invokeArgs( $webp, $args ) || 'cancelled' !== $active_job['status'] || get_option( LucidLab_MPC_Webp::STOP_OPTION, '' ) ) {
			throw new RuntimeException( 'A pending cancellation was not applied to a ' . $driver . ' conversion.' );
		}
	}
	$budget = new ReflectionMethod( LucidLab_MPC_Webp::class, 'source_exceeds_memory_budget' );
	$old_limit = ini_get( 'memory_limit' );
	ini_set( 'memory_limit', '128M' );
	$large = $GLOBALS['lucidlab_mpc_test_uploads'] . '/oversized.png';
	file_put_contents( $large, "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'NNCCCCC', 10000, 10000, 8, 2, 0, 0, 0 ) . "\0\0\0\0" );
	try {
		if ( $budget->invoke( $webp, $source, 'webp' ) || ! $budget->invoke( $webp, $large, 'webp' ) ) {
			throw new RuntimeException( 'Encoder memory preflight accepted a huge image or rejected a tiny one.' );
		}
	} finally {
		ini_set( 'memory_limit', $old_limit );
		unlink( $large );
	}
	echo "WebP discovery completed in bounded resumable ticks.\n";
} finally {
	unlink( $source );
	rmdir( $GLOBALS['lucidlab_mpc_test_uploads'] );
}
