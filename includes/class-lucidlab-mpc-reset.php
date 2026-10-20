<?php
/**
 * Lucid Lab Media & Products Cleanup: reset class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options → Reset plugin data.
 *
 * Restores lucidlab_mpc_settings to defaults and removes scan-derived caches, indexes,
 * job state, Finished Jobs user meta, other plugin options, and work folders.
 * License keys, rename undo history, user backups and quarantine stay unless
 * quarantine wipe is requested (protected sets always kept).
 */
final class LucidLab_MPC_Reset {

	/** Options that must survive a reset (license / schema stamps / internals). */
	private const KEEP_OPTIONS = array(
		'lucidlab_mpc_settings_revision',
		'lucidlab_mpc_pro_license_state',
		'lucidlab_mpc_pro_install_id',
		'lucidlab_mpc_install_id',
		'lucidlab_mpc_language_packs',
		'lucidlab_mpc_rename_db',
		'lucidlab_mpc_media_index_db',
		'lucidlab_mpc_visual_db',
		'lucidlab_mpc_cron_last_ok',
		'lucidlab_mpc_backup_dir_key',
		'lucidlab_mpc_backup_sign_key',
		'lucidlab_mpc_quarantine_dir_key',
	);

	/**
	 * Run.
	 *
	 * @param array{dry_run?:bool,clear_quarantine?:bool} $args Args.
	 *
	 * @return array{
	 * dry_run:bool,
	 * settings_reset:bool,
	 * options_deleted:int,
	 * transients_deleted:int,
	 * user_meta_cleared:int,
	 * tables_truncated:array<int,string>,
	 * files_removed:int,
	 * quarantine_purged:int,
	 * quarantine_protected_kept:array<int,string>,
	 * option_names:array<int,string>,
	 * work_dirs:array<int,string>
	 * }
	 */
	public static function run( array $args = array() ): array {
		global $wpdb;

		$dry              = ! empty( $args['dry_run'] );
		$clear_quarantine = ! empty( $args['clear_quarantine'] );
		$report           = array(
			'dry_run'                   => $dry,
			'settings_reset'            => false,
			'options_deleted'           => 0,
			'transients_deleted'        => 0,
			'user_meta_cleared'         => 0,
			'tables_truncated'          => array(),
			'files_removed'             => 0,
			'quarantine_purged'         => 0,
			'quarantine_protected_kept' => LucidLab_MPC_Quarantine::PROTECTED_HEALTH_SETS,
			'option_names'              => array(),
			'work_dirs'                 => array(),
		);

		if ( ! $dry ) {
			self::cancel_running_jobs();
		}

		// 1) Settings → factory defaults (license is a separate option).
		if ( ! $dry ) {
			// A reset must not silently make previously excluded upload folders eligible for quarantine.
			$previous_settings           = (array) get_option( LucidLab_MPC_Settings::OPTION, array() );
			$defaults                    = LucidLab_MPC_Settings::defaults();
			$defaults['exclude_folders'] = (string) ( $previous_settings['exclude_folders'] ?? '' );
			update_option( LucidLab_MPC_Settings::OPTION, $defaults, false );
			$report['settings_reset'] = true;
			if ( class_exists( 'LucidLab_MPC_Reports', false ) ) {
				( new LucidLab_MPC_Reports() )->configure();
			}
			if ( class_exists( 'LucidLab_MPC_Automation', false ) && method_exists( 'LucidLab_MPC_Automation', 'configure' ) ) {
				LucidLab_MPC_Automation::configure();
			}
		} else {
			$report['settings_reset'] = true;
		}

		// 2) All plugin options except settings, license, and schema stamps.
		// Narrow prefix lists used to leave finished-job leftovers (e.g. lucidlab_mpc_ai_job, lucidlab_mpc_webp_rewrite_hash).
		$like        = $wpdb->esc_like( 'lucidlab_mpc_' ) . '%';
		$modern_like = $wpdb->esc_like( 'lucidlab_mpc_' ) . '%';
		$opt_names   = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $modern_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		foreach ( (array) $opt_names as $name ) {
			$name        = (string) $name;
			$legacy_name = str_starts_with( $name, 'lucidlab_' ) ? substr( $name, strlen( 'lucidlab_' ) ) : $name;
			if ( in_array( $name, self::KEEP_OPTIONS, true ) || in_array( $legacy_name, self::KEEP_OPTIONS, true ) || LucidLab_MPC_Settings::OPTION === $name || 'lucidlab_mpc_settings' === $name ) {
				continue;
			}
			$report['option_names'][] = $name;
			if ( ! $dry ) {
				delete_option( $name );
			}
			++$report['options_deleted'];
		}

		// 3) Transients used for scan state / results / caches.
		$transient_prefixes = array(
			'_transient_lucidlab_mpc_',
			'_transient_timeout_lucidlab_mpc_',
			'_site_transient_lucidlab_mpc_',
			'_site_transient_timeout_lucidlab_mpc_',
		);
		foreach ( $transient_prefixes as $prefix ) {
			$like  = $wpdb->esc_like( $prefix ) . '%';
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			foreach ( (array) $names as $name ) {
				$name = (string) $name;
				if ( in_array( $name, $report['option_names'], true ) ) {
					continue;
				}
				$report['option_names'][] = $name;
				if ( ! $dry ) {
					delete_option( $name );
				}
				++$report['transients_deleted'];
			}
		}

		// 4) Per-user plugin meta (Finished Jobs history, task keys, etc.).
		$meta_like                   = $wpdb->esc_like( 'lucidlab_mpc_' ) . '%';
		$modern_meta_like            = $wpdb->esc_like( 'lucidlab_mpc_' ) . '%';
		$meta_ids                    = $wpdb->get_col( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $meta_like, $modern_meta_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$report['user_meta_cleared'] = count( (array) $meta_ids );
		if ( ! $dry && $meta_ids ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $meta_like, $modern_meta_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		}

		// 5) Scan-derived DB tables (keep rename undo log).
		if ( class_exists( 'LucidLab_MPC_Index', false ) ) {
			$table = LucidLab_MPC_Index::table();
			if ( self::table_exists( $table ) ) {
				$report['tables_truncated'][] = $table;
				if ( ! $dry ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from plugin helper; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
				}
			}
		}
		if ( class_exists( 'LucidLab_MPC_Visual', false ) && method_exists( 'LucidLab_MPC_Visual', 'table' ) ) {
			$table = LucidLab_MPC_Visual::table();
			if ( self::table_exists( $table ) ) {
				$report['tables_truncated'][] = $table;
				if ( ! $dry ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is escaped below; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
				}
			}
		} else {
			// Pro may be inactive; still clear the visual fingerprint table if present.
			foreach ( array( 'lucidlab_mpc_visual' ) as $suffix ) {
				$table = $wpdb->prefix . $suffix;
				if ( self::table_exists( $table ) ) {
					$report['tables_truncated'][] = $table;
					if ( ! $dry ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is escaped below; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
						$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
					}
				}
			}
		}

		// 6) Work / cache folders created by scans (not quarantine, not user backups).
		$uploads = trailingslashit( str_replace( '\\', '/', LucidLab_MPC_Helpers::uploads_basedir() ) );
		$dirs    = array(
			LucidLab_MPC_Storage::dir( 'work', false ),
			LucidLab_MPC_Storage::dir( 'format-preview', false ),
			$uploads . '_cleanup-fsindex',
			$uploads . '_cleanup-format-preview',
			// Older versions only: nothing is written here any more.
			trailingslashit( str_replace( '\\', '/', WP_CONTENT_DIR ) ) . 'lucidlab-mpc-core-check',
		);
		$dirs    = array_values( array_unique( $dirs ) );
		LucidLab_MPC_Storage::flush();
		$report['work_dirs'] = $dirs;
		foreach ( $dirs as $dir ) {
			$report['files_removed'] += self::wipe_dir( $dir, $dry );
		}
		if ( ! $dry && class_exists( 'LucidLab_MPC_Work', false ) ) {
			// Recreate protective stubs for the scan work folder.
			LucidLab_MPC_Work::dir();
		}

		// 7) Optional quarantine wipe (always skips protected health sets).
		if ( $clear_quarantine ) {
			$quar = new LucidLab_MPC_Quarantine();
			if ( $dry ) {
				$n = 0;
				foreach ( (array) glob( trailingslashit( $quar->root() ) . '*', GLOB_ONLYDIR ) as $dir ) {
					$stamp = wp_basename( $dir );
					if ( ! LucidLab_MPC_Quarantine::is_health_protected( $stamp ) ) {
						++$n;
					}
				}
				$report['quarantine_purged'] = $n;
			} else {
				$report['quarantine_purged'] = $quar->purge_all();
				LucidLab_MPC_Quarantine::clear_last_action();
			}
		}

		// Clear job cron ticks that may still be queued for deleted jobs.
		if ( ! $dry ) {
			foreach ( array( 'lucidlab_mpc_task_tick', 'lucidlab_mpc_rename_tick', 'lucidlab_mpc_webp_tick', 'lucidlab_mpc_regen_tick', 'lucidlab_mpc_ai_tick' ) as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
		}

		$report['option_names'] = array_values( array_unique( $report['option_names'] ) );
		sort( $report['option_names'] );

		return $report;
	}

	/**
	 * Cancel running jobs.
	 */
	private static function cancel_running_jobs(): void {
		global $wpdb;
		$like_task   = $wpdb->esc_like( 'lucidlab_mpc_task_' ) . '%';
		$like_ctrl   = $wpdb->esc_like( 'lucidlab_mpc_task_ctrl_' ) . '%';
		$modern_task = $wpdb->esc_like( 'lucidlab_mpc_task_' ) . '%';
		$modern_ctrl = $wpdb->esc_like( 'lucidlab_mpc_task_ctrl_' ) . '%';
		$names       = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_name NOT LIKE %s AND option_name NOT LIKE %s", $like_task, $modern_task, $like_ctrl, $modern_ctrl ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		foreach ( (array) $names as $name ) {
			$job = get_option( (string) $name );
			if ( ! is_array( $job ) ) {
				continue;
			}
			$status = (string) ( $job['status'] ?? '' );
			if ( in_array( $status, array( 'running', 'paused' ), true ) ) {
				$job['status']     = 'cancelled';
				$job['label']      = __( 'Cancelled by Options reset.', 'lucidlab-mpc-cleanup' );
				$job['retry_at']   = 0;
				$job['last_error'] = '';
				unset( $job['items'], $job['inflight'] );
				$job['request'] = array();
				update_option( (string) $name, $job, false );
			}
		}
		foreach ( array( 'lucidlab_mpc_regen_job', 'lucidlab_mpc_rename_job', 'lucidlab_mpc_webp_job', 'lucidlab_mpc_backup_job' ) as $job_option ) {
			$job = get_option( $job_option );
			if ( ! is_array( $job ) ) {
				continue;
			}
			if ( in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'paused', 'queued' ), true ) ) {
				$job['status'] = 'cancelled';
				update_option( $job_option, $job, false );
			}
		}
		if ( false !== get_option( 'lucidlab_mpc_webp_stop', false ) ) {
			update_option( 'lucidlab_mpc_webp_stop', 1, false );
		}
		if ( false !== get_option( 'lucidlab_mpc_backup_cancel', false ) ) {
			update_option( 'lucidlab_mpc_backup_cancel', time(), false );
		}
	}

	/**
	 * Table exists.
	 *
	 * @param string $table Table.
	 *
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		if ( '' === $table ) {
			return false;
		}
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
	}

	/**
	 * Remove files under $dir; keep the directory itself. Returns file count.
	 *
	 * @param string $dir Dir.
	 * @param bool   $dry Dry.
	 *
	 * @return int
	 */
	private static function wipe_dir( string $dir, bool $dry ): int {
		$dir = rtrim( str_replace( '\\', '/', $dir ), '/' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 0;
		}
		// Refuse anything outside uploads.
		$uploads = rtrim( str_replace( '\\', '/', LucidLab_MPC_Helpers::uploads_basedir() ), '/' );
		if ( '' === $uploads || ! str_starts_with( $dir, $uploads . '/' ) ) {
			return 0;
		}
		$count = 0;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
		} catch ( Throwable $e ) {
			return 0;
		}
		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() ) {
				if ( ! $dry ) {
					rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				}
				continue;
			}
			++$count;
			if ( ! $dry ) {
				wp_delete_file( $path );
			}
		}
		return $count;
	}
}
