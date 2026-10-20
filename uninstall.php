<?php
/**
 * Lucid Lab Media & Products Cleanup: uninstall.
 *
 * By default, settings, rename history, queues, quarantine manifests and backups are kept for
 * reinstallation and recovery; only caches are removed. When an administrator ticked "Delete all
 * plugin data when the plugin is deleted" in Options, everything the plugin created is removed.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'lucidlab_mpc_uninstall_rmdir' ) ) {
	/**
	 * Recursively delete a plugin-owned folder. Symbolic links are removed, never followed.
	 *
	 * @param string $dir Absolute folder path.
	 */
	function lucidlab_mpc_uninstall_rmdir( string $dir ): void {
		if ( '' === $dir || is_link( $dir ) || ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Uninstall removes the plugin's own work, quarantine and backup folders; WP_Filesystem may need credentials here.
			} else {
				wp_delete_file( $path );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- See above.
	}
}

if ( ! function_exists( 'lucidlab_mpc_uninstall_htaccess' ) ) {
	/**
	 * Remove the plugin's marker block from uploads/.htaccess. The file is deleted when nothing else is left in it.
	 */
	function lucidlab_mpc_uninstall_htaccess(): void {
		$uploads = wp_upload_dir( null, false );
		$base    = rtrim( str_replace( '\\', '/', (string) ( $uploads['basedir'] ?? '' ) ), '/' );
		$file    = $base . '/.htaccess';
		if ( '' === $base || ! is_file( $file ) || ! wp_is_writable( $file ) ) {
			return;
		}
		$current = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file under uploads; WP_Filesystem may need credentials during uninstall.
		$next    = (string) preg_replace( '/^# BEGIN Media Products Cleanup WebP.*?# END Media Products Cleanup WebP[ \t]*(?:\r?\n)?/ms', '', $current );
		if ( $next === $current ) {
			return;
		}
		if ( '' === trim( $next ) ) {
			wp_delete_file( $file );
			return;
		}
		file_put_contents( $file, $next ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local file under uploads; WP_Filesystem may need credentials during uninstall.
	}
}

if ( ! function_exists( 'lucidlab_mpc_uninstall_site' ) ) {
	/**
	 * Uninstall cleanup for the current site.
	 */
	function lucidlab_mpc_uninstall_site(): void {
		global $wpdb;

		$settings   = get_option( 'lucidlab_mpc_settings', array() );
		$delete_all = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

		foreach ( array( 'lucidlab_mpc_daily_purge', 'lucidlab_mpc_weekly_report', 'lucidlab_mpc_automation_weekly', 'lucidlab_mpc_automation_monthly', 'lucidlab_mpc_automation_retry', 'lucidlab_mpc_task_tick', 'lucidlab_mpc_rename_tick', 'lucidlab_mpc_webp_tick', 'lucidlab_mpc_regen_tick', 'lucidlab_mpc_index_autobuild', 'lucidlab_mpc_site_ping', 'lucidlab_mpc_backup_tick', 'lucidlab_mpc_ai_tick', 'lucidlab_mpc_pro_license_sync' ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		// The plugin is gone, so its image rewrite rules go too, whether or not its data is kept.
		lucidlab_mpc_uninstall_htaccess();
		delete_option( 'lucidlab_mpc_webp_rewrite_hash' );

		if ( ! $delete_all ) {
			// The media index is a cache and is rebuilt on the next scan.
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lucidlab_mpc_media_index" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops this plugin's own cache table; no core API exists.
			foreach ( array( 'lucidlab_mpc_media_index', 'lucidlab_mpc_media_index_ready', 'lucidlab_mpc_media_index_dirty', 'lucidlab_mpc_media_index_db', 'lucidlab_mpc_media_index_autobuild', 'lucidlab_mpc_media_index_autobuild_lock', 'lucidlab_mpc_index_seen_version', 'lucidlab_mpc_media_index_mismatch', 'lucidlab_mpc_state_autoload_fix', 'lucidlab_mpc_site_ping_last', 'lucidlab_mpc_install_id' ) as $option ) {
				delete_option( $option );
			}
			return;
		}

		// Folders: read their random names before the options that hold them are deleted.
		$uploads = wp_upload_dir( null, false );
		$base    = rtrim( str_replace( '\\', '/', (string) ( $uploads['basedir'] ?? '' ) ), '/' );
		if ( '' !== $base && is_dir( $base ) ) {
			// uploads/lucidlab-mpc-cleanup/ holds all plugin data in this version; the others are the older locations.
			$dirs = array( $base . '/lucidlab-mpc-cleanup', $base . '/_cleanup-quarantine', $base . '/_cleanup-fsindex', $base . '/_cleanup-format-preview' );
			foreach ( (array) glob( $base . '/lucidlab-mpc-backups-*', GLOB_ONLYDIR ) as $backup_dir ) {
				if ( preg_match( '/^lucidlab-mpc-backups-[a-f0-9]{32}$/', basename( (string) $backup_dir ) ) ) {
					$dirs[] = (string) $backup_dir;
				}
			}
			foreach ( $dirs as $dir ) {
				lucidlab_mpc_uninstall_rmdir( $dir );
			}
		}
		lucidlab_mpc_uninstall_rmdir( rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/lucidlab-mpc-core-check' );

		// Tables created by Free and Pro.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'lucidlab_mpc_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall lists this plugin's own tables.
		foreach ( (array) $tables as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', (string) $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops this plugin's own tables.
		}

		// Options and transients (Free and Pro share the lucidlab_mpc_ prefix).
		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No core API lists options by prefix.
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'lucidlab_mpc_' ) . '%',
				$wpdb->esc_like( '_transient_lucidlab_mpc_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_lucidlab_mpc_' ) . '%',
				$wpdb->esc_like( '_site_transient_lucidlab_mpc_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_lucidlab_mpc_' ) . '%'
			)
		);
		foreach ( (array) $names as $name ) {
			delete_option( (string) $name );
		}

		// Post meta (AI suggestions, product copy and undo) and per-user meta.
		foreach ( array( '_lucidlab_mpc_', 'lucidlab_mpc_' ) as $prefix ) {
			$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No core API lists meta keys by prefix.
			foreach ( (array) $keys as $key ) {
				delete_post_meta_by_key( (string) $key );
			}
			$user_keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No core API lists meta keys by prefix.
			foreach ( (array) $user_keys as $key ) {
				delete_metadata( 'user', 0, (string) $key, '', true );
			}
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $lucidlab_mpc_site_id ) {
		switch_to_blog( (int) $lucidlab_mpc_site_id );
		lucidlab_mpc_uninstall_site();
		restore_current_blog();
	}
} else {
	lucidlab_mpc_uninstall_site();
}
