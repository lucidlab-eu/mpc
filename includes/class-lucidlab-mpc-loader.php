<?php
/**
 * Lucid Lab Media & Products Cleanup: loader class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class map autoloader. Admin, AJAX, cron, REST and WP-CLI requests still load every class up
 * front, because many checks use class_exists( ..., false ). Front-end page views load only the
 * few classes their hooks need; anything else is loaded from this map on first use.
 */
final class LucidLab_MPC_Loader {
	/**
	 * Map.
	 *
	 * @var array<string,string>
	 */
	private static array $map = array();
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Add.
	 *
	 * @param string               $dir      Dir.
	 * @param array<string,string> $classes  Class => file relative to $dir.
	 * @param bool                 $override Override.
	 */
	public static function add( string $dir, array $classes, bool $override = false ): void {
		foreach ( $classes as $class => $file ) {
			if ( $override || ! isset( self::$map[ $class ] ) ) {
				self::$map[ $class ] = trailingslashit( $dir ) . $file;
			}
		}
		if ( ! self::$registered ) {
			spl_autoload_register( array( __CLASS__, 'load' ) );
			self::$registered = true;
		}
	}

	/**
	 * Load.
	 *
	 * @param string $class_name Class.
	 */
	public static function load( string $class_name ): void {
		if ( isset( self::$map[ $class_name ] ) && is_readable( self::$map[ $class_name ] ) ) {
			require_once self::$map[ $class_name ];
		}
	}

	/**
	 * Free classes.
	 *
	 * @return array
	 */
	public static function free_classes(): array {
		$map = array(
			'LucidLab_MPC_Helpers'             => 'class-lucidlab-mpc-helpers.php',
			'LucidLab_MPC_Settings'            => 'class-lucidlab-mpc-settings.php',
			'LucidLab_MPC_Logger'              => 'class-lucidlab-mpc-logger.php',
			'LucidLab_MPC_Quarantine'          => 'class-lucidlab-mpc-quarantine.php',
			'LucidLab_MPC_Orphans'             => 'class-lucidlab-mpc-orphans.php',
			'LucidLab_MPC_Uploads'             => 'class-lucidlab-mpc-uploads.php',
			'LucidLab_MPC_Shared'              => 'class-lucidlab-mpc-shared.php',
			'LucidLab_MPC_Sizes'               => 'class-lucidlab-mpc-sizes.php',
			'LucidLab_MPC_DupContent'          => 'class-lucidlab-mpc-dupcontent.php',
			'LucidLab_MPC_Rename'              => 'class-lucidlab-mpc-rename.php',
			'LucidLab_MPC_Stats'               => 'class-lucidlab-mpc-stats.php',
			'LucidLab_MPC_Review'              => 'class-lucidlab-mpc-review.php',
			'LucidLab_MPC_Preview'             => 'class-lucidlab-mpc-preview.php',
			'LucidLab_MPC_Format_Preview'      => 'class-lucidlab-mpc-format-preview.php',
			'LucidLab_MPC_Exclusions'          => 'class-lucidlab-mpc-exclusions.php',
			'LucidLab_MPC_Backup'              => 'class-lucidlab-mpc-backup.php',
			'LucidLab_MPC_I18n'                => 'class-lucidlab-mpc-i18n.php',
			'LucidLab_MPC_AI'                  => 'class-lucidlab-mpc-ai.php',
			'LucidLab_MPC_Index'               => 'class-lucidlab-mpc-index.php',
			'LucidLab_MPC_Result_Cache'        => 'class-lucidlab-mpc-result-cache.php',
			'LucidLab_MPC_Scan_Cache'          => 'class-lucidlab-mpc-scan-cache.php',
			'LucidLab_MPC_Hash_Cache'          => 'class-lucidlab-mpc-hash-cache.php',
			'LucidLab_MPC_Scan_Profile'        => 'class-lucidlab-mpc-scan-profile.php',
			'LucidLab_MPC_Reference_Index'     => 'class-lucidlab-mpc-reference-index.php',
			'LucidLab_MPC_Directory_Inventory' => 'class-lucidlab-mpc-directory-inventory.php',
			'LucidLab_MPC_Reports'             => 'class-lucidlab-mpc-reports.php',
			'LucidLab_MPC_Regenerate'          => 'class-lucidlab-mpc-regenerate.php',
			'LucidLab_MPC_Task_Job'            => 'class-lucidlab-mpc-task-job.php',
			'LucidLab_MPC_Task_Response'       => 'class-lucidlab-mpc-task-response.php',
			'LucidLab_MPC_Plugin'              => 'class-lucidlab-mpc-plugin.php',
			'LucidLab_MPC_Work'                => 'class-lucidlab-mpc-work.php',
			'LucidLab_MPC_Storage'             => 'class-lucidlab-mpc-storage.php',
			'LucidLab_MPC_Hooks'               => 'class-lucidlab-mpc-hooks.php',
			'LucidLab_MPC_Site_Ping'           => 'class-lucidlab-mpc-site-ping.php',
			'LucidLab_MPC_AI_Job'              => 'class-lucidlab-mpc-ai-job.php',
			'LucidLab_MPC_AI_Dashboard'        => 'class-lucidlab-mpc-ai-dashboard.php',
			'LucidLab_MPC_Inventory'           => 'class-lucidlab-mpc-inventory.php',
			'LucidLab_MPC_Crawler'             => 'class-lucidlab-mpc-crawler.php',
			'LucidLab_MPC_Crawl_Http'          => 'class-lucidlab-mpc-crawl-http.php',
			'LucidLab_MPC_Reset'               => 'class-lucidlab-mpc-reset.php',
			'LucidLab_MPC_Core_Check'          => 'class-lucidlab-mpc-core-check.php',
			'LucidLab_MPC_Plugin_Formats'      => 'trait-lucidlab-mpc-plugin-formats.php',
		);
		foreach ( array( 'Plugin', 'Uploads', 'DupContent', 'Rename', 'Task', 'Sizes' ) as $trait ) {
			$map[ 'LucidLab_MPC_' . $trait . '_Pro_Methods' ] = 'trait-lucidlab-mpc-' . strtolower( $trait ) . '-pro-methods.php';
		}
		return $map;
	}

	/**
	 * Whether this request needs the whole plugin (anything except a normal front-end page view).
	 *
	 * @return bool
	 */
	public static function full_request(): bool {
		if ( 'cli' === PHP_SAPI || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return true;
		}
		if ( ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/display parameter; no state change.
			return true;
		}
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		return '' === $uri || str_contains( $uri, '/' . $prefix . '/' ) || str_ends_with( strtok( $uri, '?' ), '/' . $prefix ) || str_contains( $uri, 'wp-login.php' );
	}
}
