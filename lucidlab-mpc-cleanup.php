<?php
/**
 * Plugin Name:       Lucid Lab Media & Products Cleanup
 * Description:       Safe manual media cleanup, exact duplicate detection, usage reports, restoration and thumbnail regeneration. Extend with the separate Pro add-on.
 * Version:           6.0.394
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Lucid Lab
 * Author URI:        https://lucidlab.eu
 * Plugin URI:        https://lucidlab.eu/
 * License:           GPL-2.0-or-later
 * Text Domain:       lucidlab-mpc-cleanup
 * Domain Path:       /languages
 *
 * Cleanup uses WordPress Trash or a restorable file quarantine where applicable.
 * Run All scans and quarantines after confirmation. Manual/automatic purge and
 * Missing Images media-record purge are permanent. See readme.txt for details.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'LUCIDLAB_MPC_VERSION' ) ) {
	return;
}

define( 'LUCIDLAB_MPC_VERSION', '6.0.394' );
define( 'LUCIDLAB_MPC_FILE', __FILE__ );
define( 'LUCIDLAB_MPC_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUCIDLAB_MPC_URL', plugin_dir_url( __FILE__ ) );


require_once LUCIDLAB_MPC_DIR . 'includes/class-lucidlab-mpc-edition.php';
require_once LUCIDLAB_MPC_DIR . 'includes/class-lucidlab-mpc-loader.php';
LucidLab_MPC_Loader::add( LUCIDLAB_MPC_DIR . 'includes', LucidLab_MPC_Loader::free_classes() );
/**
 * Lucidlab mpc boot.
 *
 * @param bool $full Full.
 */
function lucidlab_mpc_boot( bool $full = false ): void {
	if ( class_exists( 'LucidLab_MPC_Plugin', false ) ) {
		return;
	}
	foreach ( array( 'webp', 'image-delivery', 'webp-rewrite' ) as $shared_file ) {
		$shared_class = array(
			'webp'           => 'LucidLab_MPC_Webp',
			'image-delivery' => 'LucidLab_MPC_Image_Delivery',
			'webp-rewrite'   => 'LucidLab_MPC_Webp_Rewrite',
		)[ $shared_file ];
		if ( ! class_exists( $shared_class, false ) ) {
			require_once LUCIDLAB_MPC_DIR . 'includes/class-lucidlab-mpc-' . $shared_file . '.php';
		}
	}
	static $light = false;
	if ( ! $full && ! LucidLab_MPC_Loader::full_request() ) {
		// Front-end page views: image upload/index hooks only. Other classes autoload on first use.
		if ( ! $light ) {
			$light = true;
			foreach ( array( 'helpers', 'storage', 'settings', 'logger', 'exclusions', 'index', 'hooks' ) as $file ) {
				require_once LUCIDLAB_MPC_DIR . 'includes/class-lucidlab-mpc-' . $file . '.php';
			}
			LucidLab_MPC_Hooks::register();
			if ( ! LucidLab_MPC_Edition::pro() ) {
				new LucidLab_MPC_Image_Delivery();
			}
		}
		return;
	}
	foreach ( array( 'plugin', 'uploads', 'dupcontent', 'rename', 'task', 'sizes' ) as $trait_file ) {
		require_once LUCIDLAB_MPC_DIR . 'includes/trait-lucidlab-mpc-' . $trait_file . '-pro-methods.php';
	}
	require_once LUCIDLAB_MPC_DIR . 'includes/trait-lucidlab-mpc-plugin-formats.php';
	foreach ( array( 'helpers', 'storage', 'settings', 'logger', 'quarantine', 'orphans', 'uploads', 'shared', 'sizes', 'dupcontent', 'rename', 'stats', 'review', 'preview', 'format-preview', 'exclusions', 'backup', 'i18n', 'ai', 'index', 'reports', 'regenerate', 'task-response', 'task-job', 'work', 'hooks', 'inventory', 'crawl-http', 'crawler', 'reset', 'core-check', 'plugin' ) as $file ) {
		require_once LUCIDLAB_MPC_DIR . 'includes/class-lucidlab-mpc-' . $file . '.php';
	}
	if ( file_exists( LUCIDLAB_MPC_DIR . 'includes/class-lucidlab-mpc-ai-job.php' ) && ! class_exists( 'LucidLab_MPC_AI_Job', false ) ) {
		require_once LUCIDLAB_MPC_DIR . 'includes/class-lucidlab-mpc-ai-job.php';
	}
	if ( file_exists( LUCIDLAB_MPC_DIR . 'includes/class-lucidlab-mpc-ai-dashboard.php' ) && ! class_exists( 'LucidLab_MPC_AI_Dashboard', false ) ) {
		require_once LUCIDLAB_MPC_DIR . 'includes/class-lucidlab-mpc-ai-dashboard.php';
	}
	LucidLab_MPC_I18n::load();
	LucidLab_MPC_Settings::upgrade();
	if ( ! LucidLab_MPC_Edition::pro() ) {
		new LucidLab_MPC_Image_Delivery();
	}
	LucidLab_MPC_Plugin::instance();
	// One-time move of older folders into uploads/lucidlab-mpc-cleanup/ (runs before the rewrite rules are synced).
	add_action(
		'admin_init',
		static function () {
			LucidLab_MPC_Storage::migrate();
		},
		5
	);
	add_action(
		'lucidlab_mpc_daily_purge',
		static function () {
			LucidLab_MPC_Storage::migrate();
		},
		1
	);
	add_action( 'admin_init', array( 'LucidLab_MPC_Webp_Rewrite', 'sync' ) );
	// Server-cron sites that rarely open wp-admin still get rules changed by an update.
	add_action( 'lucidlab_mpc_daily_purge', array( 'LucidLab_MPC_Webp_Rewrite', 'sync' ), 2 );
	add_action( 'lucidlab_mpc_pro_license_sync', array( 'LucidLab_MPC_Webp_Rewrite', 'sync' ), 11 );
}
add_action( 'plugins_loaded', 'lucidlab_mpc_boot', 20 );
register_activation_hook(
	__FILE__,
	static function () {
		lucidlab_mpc_boot( true );
		LucidLab_MPC_Plugin::activate();
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		lucidlab_mpc_boot( true );
		LucidLab_MPC_Plugin::deactivate();
	}
);
