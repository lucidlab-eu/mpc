<?php
/**
 * Lucid Lab Media & Products Cleanup: task job class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Durable, per-user queues. Each batch is serialized with its control commands. */
class LucidLab_MPC_Task_Job {
	use LucidLab_MPC_Task_Pro_Methods;

	/**
	 * Plugin.
	 *
	 * @var LucidLab_MPC_Plugin
	 */
	private LucidLab_MPC_Plugin $plugin;
	/**
	 * Constructor.
	 *
	 * @param LucidLab_MPC_Plugin $plugin Plugin.
	 */
	public function __construct( LucidLab_MPC_Plugin $plugin ) {
		$this->plugin = $plugin; }
	/**
	 * Option.
	 *
	 * @param int    $owner Owner.
	 * @param string $key   Key.
	 *
	 * @return string
	 */
	private function option( int $owner, string $key ): string {
		return 'lucidlab_mpc_task_' . $owner . '_' . $key; }
	/**
	 * Public job.
	 *
	 * @param array $job Job.
	 *
	 * @return array
	 */
	private function public_job( array $job ): array {
		if ( ( $job['request']['op'] ?? '' ) === 'scan' && ( $job['status'] ?? '' ) === 'done' ) {
			$job['result_available'] = $this->plugin->task_result_exists( (string) ( $job['request']['tab'] ?? $job['scan_tab'] ?? '' ) );
			if ( 'shared' === (string) ( $job['request']['tab'] ?? $job['scan_tab'] ?? '' ) && ! empty( $job['result']['result_available'] ) ) {
				$job['result_available'] = true;
			}
		}
		$request = is_array( $job['request'] ?? null ) ? $job['request'] : array();
		$replay  = array(
			'op'  => sanitize_key( (string) ( $request['op'] ?? $job['op'] ?? '' ) ),
			'tab' => sanitize_key( (string) ( $request['tab'] ?? $job['scan_tab'] ?? '' ) ),
		);
		foreach ( array( 'lucidlab_mpc_match_mode', 'format', 'progress_id', 'sub_action' ) as $field ) {
			if ( isset( $request[ $field ] ) && is_scalar( $request[ $field ] ) && '' !== (string) $request[ $field ] ) {
				$replay[ $field ] = sanitize_text_field( (string) $request[ $field ] );
			}
		}
		if ( ! empty( $request['include_empty_gallery'] ) ) {
			$replay['include_empty_gallery'] = 1;
		}
		if ( isset( $request['db_mode'] ) ) {
			$job['db_mode'] = sanitize_key( (string) $request['db_mode'] );
		}
		if ( '' !== $replay['op'] && '' !== $replay['tab'] ) {
			$job['replay'] = $replay;
		}
		unset( $job['request'], $job['items'], $job['work'], $job['settings'] );
		// A Cancel or Pause sent while a batch holds the job lock is applied by that batch when it ends.
		// Report it right away, so the list does not flip back to Running or Paused in the meantime.
		$owner = (int) ( $job['owner'] ?? get_current_user_id() );
		if ( in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'paused', 'queued' ), true ) && '' !== (string) ( $job['key'] ?? '' ) ) {
			wp_cache_delete( $this->control_option( $owner, (string) $job['key'] ), 'options' );
			$cmd = (string) get_option( $this->control_option( $owner, (string) $job['key'] ), '' );
			if ( 'cancel' === $cmd ) {
				$job['status']  = 'cancelled';
				$job['label']   = __( 'Cancelled.', 'lucidlab-mpc-cleanup' );
				$job['updated'] = time();
			} elseif ( 'pause' === $cmd && 'running' === ( $job['status'] ?? '' ) ) {
				$job['status'] = 'paused';
			}
		}
		$job['updated_label'] = ! empty( $job['updated'] ) ? LucidLab_MPC_Helpers::job_datetime( (int) $job['updated'] ) : '';
		if ( ! empty( $job['started'] ) ) {
			$job['elapsed'] = max( 0, time() - (int) $job['started'] );
		}
		$job['now']        = time();
		$job['failed_ids'] = LucidLab_MPC_Helpers::int_ids( $job['failed_ids'] ?? array() );
		$job['errors']     = array_slice( (array) ( $job['errors'] ?? array() ), -20 );
		$job['can_retry']  = in_array( (string) ( $job['status'] ?? '' ), array( 'done', 'failed' ), true ) && ( ! empty( $job['failed_ids'] ) || ! empty( $job['result']['fails'] ) );
		return $job;
	}

	/**
	 * A saved running or paused job blocks a new start while it is still moving or still has a recent cron tick. An abandoned job does not.
	 *
	 * @param array $job     Job.
	 * @param int   $now     Now.
	 * @param int   $cron_at Cron at.
	 *
	 * @return bool
	 */
	public static function saved_job_blocks_start( array $job, int $now, int $cron_at ): bool {
		if ( 'queued' === ( $job['status'] ?? '' ) ) {
			return true;
		}
		if ( ! in_array( $job['status'] ?? '', array( 'running', 'paused' ), true ) ) {
			return false;
		}
		$age       = $now - (int) ( $job['updated'] ?? 0 );
		$cron_live = ( $job['driver'] ?? '' ) === 'cron' && $cron_at >= $now - 180;
		return $age < 90 || $cron_live;
	}

	/**
	 * Running and paused task options, including ones missing from the key list.
	 *
	 * @param int $owner Owner.
	 *
	 * @return array
	 */
	public function active_saved_keys( int $owner ): array {
		global $wpdb;
		if ( $owner < 1 ) {
			return array();
		}
		$prefix = 'lucidlab_mpc_task_' . $owner . '_';
		$names  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
		$keys   = array();
		foreach ( (array) $names as $name ) {
			$name           = (string) $name;
			$matched_prefix = $prefix;
			if ( ! str_starts_with( $name, $matched_prefix ) ) {
				continue;
			}
			$job = get_option( $name );
			if ( ! is_array( $job ) || ! in_array( $job['status'] ?? '', array( 'running', 'paused', 'queued' ), true ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $job['key'] ?? substr( $name, strlen( $matched_prefix ) ) ) );
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Mark the current Run All stage skipped and advance to the next one.
	 *
	 * @param array $job Job.
	 *
	 * @return array
	 */
	public static function apply_skip_stage( array $job ): array {
		$stages = $job['stages'] ?? null;
		if ( ! is_array( $stages ) || ! $stages ) {
			return $job;
		}
		$index = (int) ( $job['stage_index'] ?? 0 );
		if ( ! isset( $stages[ $index ] ) ) {
			return $job;
		}
		$status = (string) ( $stages[ $index ]['status'] ?? '' );
		if ( in_array( $status, array( 'done', 'skipped' ), true ) ) {
			return $job;
		}
		$stages[ $index ]['status']   = 'skipped';
		$stages[ $index ]['progress'] = 100;
		$stages[ $index ]['message']  = __( 'Skipped', 'lucidlab-mpc-cleanup' );
		$job['stages']                = $stages;
		unset( $job['work'], $job['inflight'] );
		$job['stage_index'] = $index + 1;
		$job['attempts']    = 0;
		$job['retry_at']    = 0;
		$job['last_error']  = '';
		$total              = count( $stages );
		if ( $job['stage_index'] >= $total ) {
			$job['status']   = 'done';
			$job['progress'] = 100;
			$job['label']    = __( 'Run All completed. Open each tab to review results.', 'lucidlab-mpc-cleanup' );
			$job['result']   = array( 'done' => true );
			return $job;
		}
		$job['status']   = 'running';
		$job['progress'] = 100 * $job['stage_index'] / $total;
		$next            = $stages[ $job['stage_index'] ];
		$job['label']    = ( (string) ( $next['label'] ?? __( 'Next stage', 'lucidlab-mpc-cleanup' ) ) ) . ' — Starting…';
		return $job;
	}
	/**
	 * Schedule.
	 *
	 * @param int    $owner Owner.
	 * @param string $key   Key.
	 * @param int    $when  When.
	 */
	private function schedule( int $owner, string $key, int $when = 0 ): void {
		// The loopback below must be able to see the event as already due.
		$time = $when > 0 ? $when : time();
		wp_clear_scheduled_hook( 'lucidlab_mpc_task_tick', array( $owner, $key ) );
		wp_schedule_single_event( $time, 'lucidlab_mpc_task_tick', array( $owner, $key ) );
		$spawned = $when <= 0 && function_exists( 'spawn_cron' ) ? spawn_cron( time() ) : false;
		if ( $when <= 0 && ! $spawned ) {
			// Fallback only when WordPress did not wake cron itself.
			$cron_url = site_url( 'wp-cron.php' );
			wp_remote_post(
				$cron_url,
				array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'sslverify' => true,
				)
			);
		}
	}
	/**
	 * Control option.
	 *
	 * @param int    $owner Owner.
	 * @param string $key   Key.
	 *
	 * @return string
	 */
	private function control_option( int $owner, string $key ): string {
		return 'lucidlab_mpc_task_ctrl_' . $owner . '_' . sanitize_key( $key );
	}
	/**
	 * Set control.
	 *
	 * @param int    $owner Owner.
	 * @param string $key   Key.
	 * @param string $cmd   Cmd.
	 */
	private function set_control( int $owner, string $key, string $cmd ): void {
		update_option( $this->control_option( $owner, $key ), $cmd, false );
	}
	/**
	 * Clear control.
	 *
	 * @param int    $owner Owner.
	 * @param string $key   Key.
	 */
	private function clear_control( int $owner, string $key ): void {
		delete_option( $this->control_option( $owner, $key ) );
	}
	/**
	 * Apply control.
	 *
	 * @param array  &$job   Job.
	 * @param int    $owner Owner.
	 * @param string $key   Key.
	 */
	private function apply_control( array &$job, int $owner, string $key ): void {
		$cmd = (string) get_option( $this->control_option( $owner, $key ), '' );
		if ( 'cancel' === $cmd ) {
			if ( isset( $job['stages'] ) && LucidLab_MPC_Edition::pro() ) {
				$this->release_run_all( $job );
			}
			$job['status']     = 'cancelled';
			$job['label']      = __( 'Cancelled.', 'lucidlab-mpc-cleanup' );
			$job['retry_at']   = 0;
			$job['attempts']   = 0;
			$job['last_error'] = '';
			unset( $job['items'], $job['inflight'] );
			$job['request'] = array();
			$this->clear_control( $owner, $key );
			return;
		}
		if ( 'pause' === $cmd && 'running' === ( $job['status'] ?? '' ) ) {
			$job['status'] = 'paused';
			unset( $job['inflight'] );
			$this->clear_control( $owner, $key );
			return;
		}
		if ( 'skip' === $cmd ) {
			$this->clear_control( $owner, $key );
			if ( isset( $job['stages'] ) && LucidLab_MPC_Edition::pro() && method_exists( $this, 'skip_current_stage' ) ) {
				$this->skip_current_stage( $job );
			}
		}
	}
	/**
	 * Command.
	 *
	 * @param string $action Action.
	 * @param array  $input  Input.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public function command( string $action, array $input ): array {
		global $wpdb;
		$owner         = get_current_user_id();
		$promote_after = false;
		if ( ! $owner || ! current_user_can( 'manage_options' ) ) {
			throw new RuntimeException( __( 'Permission denied', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( 'list' === $action ) {
			$this->promote_queued( $owner );
			$jobs          = array();
			$keys          = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) get_user_meta( $owner, 'lucidlab_mpc_task_keys', true ) ) ) ) );
			$active        = $this->active_saved_keys( $owner );
			$keys          = array_values( array_unique( array_merge( array_values( array_diff( $keys, $active ) ), $active ) ) );
			$keys          = array_slice( $keys, -20 );
			$kept_keys     = array();
			$terminal_jobs = 0;
			foreach ( array_reverse( $keys ) as $key ) {
				$j = get_option( $this->option( $owner, $key ) );
				if ( is_array( $j ) && ! LucidLab_MPC_Edition::pro() ) {
					try {
						LucidLab_MPC_Edition::check( $j['request'] ?? array() );
					} catch ( Throwable $e ) {
						continue;}
				}
				if ( ! is_array( $j ) ) {
					continue; }
				$status = (string) ( $j['status'] ?? '' );
				if ( in_array( $status, array( 'running', 'paused', 'queued' ), true ) || $terminal_jobs < 10 ) {
					$jobs[]      = $this->public_job( $j );
					$kept_keys[] = $key;
					if ( ! in_array( $status, array( 'running', 'paused', 'queued' ), true ) ) {
						++$terminal_jobs; }
				} else {
					delete_option( $this->option( $owner, $key ) );
				}
			}
			$kept_keys = array_reverse( $kept_keys );
			wp_cache_delete( $owner, 'user_meta' );
			$fresh     = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) get_user_meta( $owner, 'lucidlab_mpc_task_keys', true ) ) ) ) );
			$kept_keys = array_values( array_unique( array_merge( $kept_keys, array_values( array_diff( $fresh, $keys ) ), $this->active_saved_keys( $owner ) ) ) );
			$kept_keys = array_slice( $kept_keys, -20 );
			if ( $kept_keys !== $keys ) {
				update_user_meta( $owner, 'lucidlab_mpc_task_keys', $kept_keys );
			}
			$jobs = array_reverse( $jobs );
			return array( 'jobs' => $jobs );
		}
		if ( ! in_array( $action, array( 'start', 'status', 'step', 'stop', 'resume', 'cancel', 'skip', 'background', 'cron', 'retry' ), true ) ) {
			throw new RuntimeException( __( 'Invalid job command', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$key = sanitize_key( $input['key'] ?? '' );
		if ( 'start' === $action ) {
			$op = $input['op'] ?? '';
			if ( ! in_array( $op, array( 'scan', 'quarantine', 'merge', 'trash_dup', 'webpscan', 'webpclean', 'run_all', 'form' ), true ) ) {
				throw new RuntimeException( __( 'Invalid job operation', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$key = sanitize_key( $op . '_' . ( $input['tab'] ?? '' ) . '_' . ( $input['format'] ?? ( $input['sub_action'] ?? '' ) ) );
		}
		if ( ! $key ) {
			throw new RuntimeException( __( 'Missing job key', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$option = $this->option( $owner, $key );
		$lock   = 'lucidlab_mpc_task_' . md5( $wpdb->prefix . $owner . ':' . $key );
		if ( 'status' === $action ) {
			wp_cache_delete( $option, 'options' );
			$job = (array) get_option( $option, array() );
			if ( ! $job ) {
				throw new RuntimeException( __( 'Saved job was not found', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			return $this->public_job( $job );
		}
		if ( 'cancel' === $action ) {
			$this->set_control( $owner, $key, 'cancel' );
		} elseif ( 'stop' === $action ) {
			$this->set_control( $owner, $key, 'pause' );
		} elseif ( 'skip' === $action ) {
			$this->set_control( $owner, $key, 'skip' );
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			wp_cache_delete( $option, 'options' );
			$job        = (array) get_option( $option, array() );
			$pub        = $job ? $this->public_job( $job ) : array( 'key' => $key );
			$pub['key'] = $key;
			if ( 'cancel' === $action ) {
				// A paused or queued job has no batch that would apply the cancel later: save it now.
				if ( $job && in_array( (string) ( $job['status'] ?? '' ), array( 'paused', 'queued' ), true ) ) {
					$this->apply_control( $job, $owner, $key );
					update_option( $option, $job, false );
				}
				$pub['status']  = 'cancelled';
				$pub['updated'] = time();
				$pub['label']   = __( 'Cancelled. The current batch will stop shortly.', 'lucidlab-mpc-cleanup' );
				return $pub;
			}
			if ( 'stop' === $action ) {
				$pub['status'] = 'paused';
				return $pub;
			}
			if ( 'skip' === $action ) {
				$pub['label']    = __( 'Skipping this stage after the current batch…', 'lucidlab-mpc-cleanup' );
				$pub['skipping'] = true;
				return array_merge( $pub, array( 'busy' => true ) );
			}
			return array_merge( $pub, array( 'busy' => true ) );
		}
		try {
			wp_cache_delete( $option, 'options' );
			$job          = (array) get_option( $option, array() );
			$prior_status = (string) ( $job['status'] ?? '' );
			if ( $job ) {
				$this->apply_control( $job, $owner, $key );
			}
			if ( in_array( $action, array( 'resume', 'background', 'step', 'cron' ), true ) && ! empty( $job['request'] ) ) {
				LucidLab_MPC_Edition::check( $job['request'] );
			}
			// Free may background Free jobs only; Pro-only requests fail the check above.
			if ( 'start' === $action ) {
				if ( $job && in_array( $job['status'] ?? '', array( 'running', 'paused', 'queued' ), true ) ) {
					$cron_at = (int) wp_next_scheduled( 'lucidlab_mpc_task_tick', array( $owner, $key ) );
					if ( self::saved_job_blocks_start( $job, time(), $cron_at ) ) {
						throw new RuntimeException( __( 'This action has a saved job. Resume it or cancel it before starting another.', 'lucidlab-mpc-cleanup' ) );
					}
					if ( isset( $job['stages'] ) && LucidLab_MPC_Edition::pro() && method_exists( $this, 'release_run_all' ) ) {
						$this->release_run_all( $job );
					}
					$job['status']  = 'cancelled';
					$job['request'] = array();
					update_option( $option, $job, false );
					$this->clear_control( $owner, $key );
				}
				$queue = $this->plugin->job_should_queue( $input );
				if ( ! $queue ) {
					$this->plugin->assert_can_start_job( '', ( $input['op'] ?? '' ) === 'run_all', $input );
				}
				$input          = $this->plugin->prepare_task( $input );
				$input['reset'] = 1;
				$input['stamp'] = '';
				$this->clear_control( $owner, $key );
				$scan_tab = sanitize_key( (string) ( $input['tab'] ?? '' ) );
				if ( '' === $scan_tab && ( $input['op'] ?? '' ) === 'form' ) {
					$scan_tab = sanitize_key( (string) ( $input['panel'] ?? '' ) );
					if ( '' === $scan_tab ) {
						$scan_tab = 'quarantine';
					}
				}
				$job = array(
					'scan_tab'    => $scan_tab,
					'key'         => $key,
					'status'      => 'running',
					'driver'      => 'ajax',
					'request'     => $input,
					'offset'      => 0,
					'total'       => 0,
					'started'     => time(),
					'updated'     => time(),
					'progress'    => 0,
					'label'       => __( 'Starting…', 'lucidlab-mpc-cleanup' ),
					'result'      => array(),
					'panel'       => $input['panel'] ?? ( $input['tab'] ?? 'duplicates' ),
					'progress_id' => $input['progress_id'] ?? ( $input['tab'] ?? 'task' ),
					'op'          => sanitize_key( $input['op'] ?? '' ),
					'format'      => sanitize_key( $input['format'] ?? '' ),
					'sub_action'  => sanitize_key( $input['sub_action'] ?? '' ),
					'source'      => sanitize_key( $input['lucidlab_mpc_source'] ?? '' ),
					'attempts'    => 0,
					'retry_at'    => 0,
					'last_error'  => '',
					'failed_ids'  => array(),
					'errors'      => array(),
				);
				if ( ( $input['op'] ?? '' ) === 'form' ) {
					$set_name = LucidLab_MPC_Quarantine::source_label( (string) ( $job['source'] ?? '' ) );
					$sub      = (string) ( $job['sub_action'] ?? '' );
					if ( in_array( $sub, array( 'restore_quarantine', 'restore_one' ), true ) ) {
						/* translators: %s is the name of the quarantine set being restored. */
						$job['label'] = $set_name ? sprintf( __( 'Restoring the %s set', 'lucidlab-mpc-cleanup' ), $set_name ) : __( 'Restoring…', 'lucidlab-mpc-cleanup' );
					} elseif ( in_array( $sub, array( 'purge_quarantine', 'purge_source' ), true ) ) {
						/* translators: %s is the name of the quarantine set being deleted. */
						$job['label'] = $set_name ? sprintf( __( 'Deleting the %s set', 'lucidlab-mpc-cleanup' ), $set_name ) : ( 'ALL' === ( $input['lucidlab_mpc_set'] ?? '' ) ? __( 'Deleting all sets', 'lucidlab-mpc-cleanup' ) : __( 'Deleting…', 'lucidlab-mpc-cleanup' ) );
					}
				}
				if ( $queue ) {
					$job['status']    = 'queued';
					$job['driver']    = 'cron';
					$job['queued_at'] = microtime( true );
					$job['label']     = __( 'Queued. Starts automatically when a job slot is free.', 'lucidlab-mpc-cleanup' );
				}
				if ( 'run_all' === $input['op'] ) {
					$this->plan_run_all( $job );
					$job['driver'] = 'cron';
					$job['label']  = __( 'Starting…', 'lucidlab-mpc-cleanup' );}
				wp_cache_delete( $owner, 'user_meta' );
				$keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_merge( (array) get_user_meta( $owner, 'lucidlab_mpc_task_keys', true ), $this->active_saved_keys( $owner ), array( $key ) ) ) ) ) );
				update_user_meta( $owner, 'lucidlab_mpc_task_keys', $keys );
			} elseif ( ! $job ) {
				throw new RuntimeException( __( 'Saved job was not found', 'lucidlab-mpc-cleanup' ) );
			} elseif ( 'stop' === $action && 'running' === $job['status'] ) {
				$job['status'] = 'paused';
			} elseif ( 'cancel' === $action ) {
				if ( isset( $job['stages'] ) && LucidLab_MPC_Edition::pro() ) {
					$this->release_run_all( $job );
				} $job['status'] = 'cancelled';
				unset( $job['items'], $job['inflight'] );
				$job['request']    = array();
				$job['retry_at']   = 0;
				$job['last_error'] = '';
				$job['attempts']   = 0;
				$this->clear_control( $owner, $key );
			} elseif ( 'skip' === $action ) {
				if ( empty( $job['stages'] ) ) {
					throw new RuntimeException( __( 'Skip is only available for Run All.', 'lucidlab-mpc-cleanup' ) );
				}
				if ( ( $job['status'] ?? '' ) === 'running' ) {
					$this->watched_step( $job, $option );
				}
			} elseif ( 'retry' === $action && in_array( $job['status'] ?? '', array( 'done', 'failed' ), true ) ) {
				$failed = LucidLab_MPC_Helpers::retry_ids( $job, (array) ( $job['result']['fails'] ?? array() ) );
				if ( ! $failed ) {
					throw new RuntimeException( __( 'No failed items are available to retry.', 'lucidlab-mpc-cleanup' ) );
				}
				/* translators: %d is the number of failed items being retried. */
				$job['status']     = 'running';
				$job['driver']     = 'ajax';
				$job['offset']     = 0;
				$job['items']      = $failed;
				$job['total']      = count( $failed );
				$job['failed_ids'] = array();
				$job['attempts']   = 0;
				$job['retry_at']   = 0;
				$job['last_error'] = '';
				$job['label']      = sprintf( /* translators: %d: number of failed items being retried. */ __( 'Retrying %d failed item(s)…', 'lucidlab-mpc-cleanup' ), count( $failed ) );
			} elseif ( in_array( $action, array( 'resume', 'background' ), true ) && in_array( $job['status'], array( 'paused', 'running' ), true ) ) {
				$this->clear_control( $owner, $key );
				$was_paused    = 'paused' === $job['status'];
				$job['status'] = 'running';
				$job['driver'] = 'background' === $action ? 'cron' : 'ajax';
				// Resuming a paused job takes a slot like a start does; when all are busy it waits in the queue.
				if ( $was_paused && empty( $job['stages'] ) && LucidLab_MPC_Edition::parallel_jobs() ) {
					LucidLab_MPC_Plugin::hold_slot_lock();
					$busy = LucidLab_MPC_Plugin::running_task_jobs_sitewide( $option ) + $this->plugin->running_job_count( $this->plugin->special_job_states() );
					if ( $busy >= LucidLab_MPC_Edition::max_concurrent_jobs() ) {
						$job['status']    = 'queued';
						$job['driver']    = 'cron';
						$job['queued_at'] = microtime( true );
						$job['label']     = __( 'Queued. Starts automatically when a job slot is free.', 'lucidlab-mpc-cleanup' );
					}
				}
				$job['retry_at']   = 0;
				$job['last_error'] = '';
				$job['attempts']   = 0;
				if ( 'background' === $action ) {
					$job['explicit_background'] = 1;
					$job['label']               = trim( (string) ( $job['label'] ?? '' ) );
					if ( '' === $job['label'] || 'Starting…' === $job['label'] || __( 'Starting…', 'lucidlab-mpc-cleanup' ) === $job['label'] ) {
						$job['label'] = __( 'Running in the background. You can leave this page and come back later to see how far the scan has got.', 'lucidlab-mpc-cleanup' );
					}
				}
			} elseif ( ( ( 'step' === $action && in_array( $job['driver'] ?? '', array( 'ajax', 'cron' ), true ) ) || ( 'cron' === $action && 'cron' === ( $job['driver'] ?? '' ) ) ) && 'running' === $job['status'] ) {
				try {
					// Scans and Run All may retry from their saved checkpoint after a worker timeout.
					if ( ! empty( $job['inflight'] ) && ! in_array( $job['request']['op'] ?? '', array( 'scan', 'webpscan', 'run_all' ), true ) ) {
						throw new RuntimeException( __( 'The previous batch was interrupted. Review its results before starting a new job; it will not be repeated automatically.', 'lucidlab-mpc-cleanup' ) );
					}
					$stale = ! empty( $job['inflight'] );
					if ( $stale && (int) ( $job['attempts'] ?? 0 ) >= 5 ) {
						throw new RuntimeException( __( 'The worker was interrupted repeatedly at the same checkpoint. Check PHP timeout/memory logs and directory access before retrying.', 'lucidlab-mpc-cleanup' ) . ( ! empty( $job['fatal'] ) ? ' ' . (string) $job['last_error'] : '' ) );
					}
					if ( $stale && ! empty( $job['fatal'] ) ) {
						LucidLab_MPC_Logger::diag(
							'job',
							'Retrying a step that ended with a PHP fatal error.',
							array(
								'attempt' => (int) ( $job['attempts'] ?? 0 ) + 1,
								'fatal'   => (string) $job['last_error'],
							),
							'warning'
						);
					}
					$job['inflight']   = true;
					$job['attempts']   = (int) ( $job['attempts'] ?? 0 ) + 1;
					$job['last_error'] = '';
					$job['retry_at']   = 0;
					update_option( $option, $job, false );
					$job['stale_step'] = $stale;
					$this->watched_step( $job, $option );
					unset( $job['inflight'], $job['stale_step'] );
					// Retries count consecutive failures, not successful batches in a large scan.
					$job['attempts'] = 0;
				} catch ( Throwable $e ) {
					$give_up = (int) ( $job['attempts'] ?? 0 ) >= 5;
					if ( $give_up ) {
						$job['status']     = 'failed';
						$job['label']      = sprintf( /* translators: %s: error message. */ __( 'Stopped after 5 retry attempts. Error: %s', 'lucidlab-mpc-cleanup' ), $e->getMessage() );
						$job['last_error'] = $e->getMessage();
						$this->record_history( $job );
						if ( isset( $job['stages'][ $job['stage_index'] ?? 0 ] ) ) {
							$job['stages'][ $job['stage_index'] ]['status']  = 'failed';
							$job['stages'][ $job['stage_index'] ]['message'] = $e->getMessage();
						}
						if ( isset( $job['stages'] ) && method_exists( $this, 'release_run_all' ) ) {
							$this->release_run_all( $job );
						}
					} else {
						// Leave the current stage running. Marking it failed here made
						// Finished Jobs record the scan as cancelled while Run All retried.
						$job['status']     = 'running';
						$job['last_error'] = $e->getMessage();
						$job['retry_at']   = time() + min( 300, 15 * max( 1, (int) ( $job['attempts'] ?? 1 ) ) );
						$job['label']      = sprintf( /* translators: %s: number of minutes. */ __( 'Retrying automatically in %s minute(s)…', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) ceil( ( (int) $job['retry_at'] - time() ) / 60 ) ) );
					}
				}
			}
			if ( 'status' !== $action ) {
				if ( $owner && $key && $job ) {
					$this->apply_control( $job, $owner, $key );
				}
				$job['updated'] = time();
				if ( 'cancelled' === ( $job['status'] ?? '' ) && in_array( $prior_status, array( 'running', 'paused' ), true ) && 'start' !== $action ) {
					$this->record_history( $job );
				}
				update_option( $option, $job, false );
			}
			if ( in_array( $job['status'] ?? '', array( 'done', 'failed', 'cancelled', 'paused' ), true ) && in_array( $prior_status, array( 'running', 'queued' ), true ) ) {
				$promote_after = true;
			}
			if ( 'running' === $job['status'] && 'cron' === $job['driver'] ) {
				if ( ! empty( $job['retry_at'] ) && (int) $job['retry_at'] > time() ) {
					$this->schedule( $owner, $key, (int) $job['retry_at'] );
				} else {
					$this->schedule( $owner, $key );
				}
			} else {
				wp_clear_scheduled_hook( 'lucidlab_mpc_task_tick', array( $owner, $key ) );
			}
			return $this->public_job( $job );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( ! empty( $promote_after ) ) {
				$this->promote_queued( $owner );
			}
		}
	}

	/**
	 * Start queued Pro jobs, oldest first, while a job slot is free. Queued jobs run in the background.
	 *
	 * @param int $owner Owner.
	 */
	public function promote_queued( int $owner ): void {
		global $wpdb;
		if ( $owner < 1 || ! LucidLab_MPC_Edition::parallel_jobs() ) {
			return;
		}
		$lock = 'lucidlab_mpc_queue_' . md5( $wpdb->prefix . $owner );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			return;
		}
		LucidLab_MPC_Plugin::hold_slot_lock();
		try {
			$queued = array();
			foreach ( $this->active_saved_keys( $owner ) as $key ) {
				$option = $this->option( $owner, $key );
				wp_cache_delete( $option, 'options' );
				$job = get_option( $option );
				if ( is_array( $job ) && 'queued' === ( $job['status'] ?? '' ) ) {
					$queued[ $key ] = (float) ( $job['queued_at'] ?? 0 );
				}
			}
			if ( ! $queued ) {
				return;
			}
			asort( $queued );
			$free = LucidLab_MPC_Edition::max_concurrent_jobs() - $this->plugin->running_job_count();
			foreach ( array_keys( $queued ) as $key ) {
				if ( $free < 1 ) {
					break;
				}
				$option = $this->option( $owner, $key );
				$job    = get_option( $option );
				if ( ! is_array( $job ) || 'queued' !== ( $job['status'] ?? '' ) ) {
					continue;
				}
				$job['status']  = 'running';
				$job['driver']  = 'cron';
				$job['started'] = time();
				$job['updated'] = time();
				$job['label']   = __( 'Starting…', 'lucidlab-mpc-cleanup' );
				update_option( $option, $job, false );
				$this->schedule( $owner, $key );
				--$free;
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		}
	}
	/**
	 * Tick.
	 *
	 * @param int    $owner Owner.
	 * @param string $key   Key.
	 */
	public function tick( int $owner, string $key ): void {
		$previous = get_current_user_id();
		wp_set_current_user( $owner );
		$switched = LucidLab_MPC_I18n::switch_to_user( $owner );
		try {
			$job = get_option( $this->option( $owner, $key ) );
			if ( is_array( $job ) && ! empty( $job['retry_at'] ) && (int) $job['retry_at'] > time() ) {
				$this->schedule( $owner, $key, (int) $job['retry_at'] );
				return;
			}
			// WP-Cron removes the current event before invoking us. Arm recovery
			// before executing work so a PHP timeout/fatal cannot strand the job.
			if ( is_array( $job ) && ( $job['status'] ?? '' ) === 'running' && ( $job['driver'] ?? '' ) === 'cron' ) {
				$this->schedule( $owner, $key, time() + 60 );
			}
			$job = $this->command( 'cron', array( 'key' => $key ) );
			LucidLab_MPC_Helpers::mark_cron_ok();
			if ( ! empty( $job['busy'] ) ) {
				$this->schedule( $owner, $key );
			}
		} catch ( Throwable $e ) {
			$option = $this->option( $owner, $key );
			$job    = get_option( $option );
			if ( is_array( $job ) ) {
				$job['status']     = 'running';
				$job['driver']     = $job['driver'] ?? 'cron';
				$job['last_error'] = $e->getMessage();
				$job['retry_at']   = time() + 60;
				update_option( $option, $job, false );
				$this->schedule( $owner, $key, (int) $job['retry_at'] );}
		} finally {
			LucidLab_MPC_I18n::restore( $switched );
			wp_set_current_user( $previous ); }
	}
	/**
	 * A scan that has used most of max_execution_time keeps running in the next request. A memory cap still pauses, because a new request would hit it again.
	 *
	 * @param array &$job      Job.
	 * @param mixed $resource_error Resource.
	 */
	private function hold_for_resources( array &$job, $resource_error ): void {
		$op      = (string) ( $job['request']['op'] ?? '' );
		$data    = $resource_error instanceof WP_Error ? $resource_error->get_error_data() : null;
		$reason  = is_array( $data ) ? (string) ( $data['reason'] ?? '' ) : '';
		$elapsed = microtime( true ) - ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ) );
		if ( 'timeout' === $reason && $elapsed >= 1.0 && in_array( $op, array( 'scan', 'webpscan', 'run_all', 'crawl' ), true ) ) {
			$job['status']     = 'running';
			$job['last_error'] = $resource_error->get_error_message();
			$job['retry_at']   = time() + 2;
			return;
		}
		$job = LucidLab_MPC_Helpers::pause_for_resource( $job, $resource_error );
	}

	/**
	 * Run one step with the fatal-error handler armed, so a timeout or out-of-memory error is written to the job and the Logs.
	 *
	 * @param array  &$job   Job.
	 * @param string $option Option that stores the job.
	 */
	private function watched_step( array &$job, string $option ): void {
		$p    = (array) ( $job['request'] ?? array() );
		$what = trim( 'job ' . (string) ( $p['op'] ?? '' ) . ' ' . (string) ( $p['tab'] ?? $job['scan_tab'] ?? '' ) );
		LucidLab_MPC_Logger::watch_fatal( $what, $option );
		try {
			$this->step( $job );
		} finally {
			LucidLab_MPC_Logger::unwatch();
		}
	}

	/**
	 * Step.
	 *
	 * @param array &$job Job.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	private function step( array &$job ): void {
		$owner = get_current_user_id();
		$key   = (string) ( $job['key'] ?? '' );
		if ( $owner && $key ) {
			$this->apply_control( $job, $owner, $key );
			if ( ( $job['status'] ?? '' ) !== 'running' ) {
				return;
			}
		}
		$resource = LucidLab_MPC_Helpers::resource_error();
		if ( $resource ) {
			$this->hold_for_resources( $job, $resource );
			return;
		}
		$deadline = microtime( true ) + 2.0;
		$guard    = 0;
		do {
			// Honour controls between short batches, not just at request entry.
			if ( $guard > 0 ) {
				if ( $owner && $key ) {
					$this->apply_control( $job, $owner, $key );
				}
				if ( ( $job['status'] ?? '' ) !== 'running' ) {
					return;
				}
				$resource = LucidLab_MPC_Helpers::resource_error();
				if ( $resource ) {
					$this->hold_for_resources( $job, $resource );
					return;}
			}
			$p = $job['request'];
			if ( ! LucidLab_MPC_Edition::pro() && ( ! LucidLab_MPC_Edition::allows( $p ) || ( 'form' === ( $p['op'] ?? '' ) && in_array( (string) ( $p['sub_action'] ?? '' ), array( 'purge_quarantine', 'purge_source' ), true ) && LucidLab_MPC_Edition::pro_quarantine_source( (string) ( $job['source'] ?? $p['lucidlab_mpc_source'] ?? '' ) ) ) ) ) {
				$job['status'] = 'paused';
				$job['label']  = __( 'Paused: this job requires an active Pro license.', 'lucidlab-mpc-cleanup' );
				return;
			}
			if ( ! empty( $job['stale_step'] ) ) {
				// Retry the saved cursor. An interrupted worker is not permission to
				// skip a directory and publish an incomplete uploads inventory.
				$job['stale_step'] = false;
			}
			if ( 'run_all' === $p['op'] ) {
				$stage_before        = (int) ( $job['stage_index'] ?? 0 );
				$stage_status_before = (string) ( $job['stages'][ $stage_before ]['status'] ?? '' );
				$this->run_all( $job );
				$completed_stage = $job['stages'][ $stage_before ] ?? array();
				if ( 'done' !== $stage_status_before && 'done' === ( $completed_stage['status'] ?? '' ) ) {
					$source = sanitize_key( (string) ( $completed_stage['panel'] ?? '' ) );
					LucidLab_MPC_Helpers::prepend_job_history(
						array(
							'key'      => (string) ( $job['key'] ?? 'run_all' ) . ':' . $stage_before,
							'label'    => (string) ( $completed_stage['label'] ?? LucidLab_MPC_Helpers::scan_tab_label( $source ) ),
							'status'   => 'done',
							'error'    => '',
							'updated'  => (int) ( $completed_stage['completed_at'] ?? time() ),
							'failed'   => 0,
							'panel'    => $source,
							'scan_tab' => $source,
							'special'  => 'run_all_stage',
						)
					);
					if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
						LucidLab_MPC_Logger::log(
							__( 'Run All', 'lucidlab-mpc-cleanup' ) . ' — ' .
							(string) ( $completed_stage['label'] ?? LucidLab_MPC_Helpers::scan_tab_label( $source ) ) . ': ' .
							__( 'finished', 'lucidlab-mpc-cleanup' ) . '. ' .
							trim( (string) ( $completed_stage['message'] ?? '' ) ),
							'info'
						);
					}
				}
				if ( ( $job['status'] ?? '' ) === 'done' ) {
					$this->record_history( $job );
				}
				++$guard;
				// Coalesce scan-only work. Never accelerate a stage transition into quarantine.
				$same_stage = (int) ( $job['stage_index'] ?? 0 ) === $stage_before;
				$scan_only  = ( $job['stages'][ $stage_before ]['kind'] ?? '' ) === 'cleanup' && ( $job['work']['phase'] ?? 'scan' ) === 'scan';
				if ( ! $same_stage || ! $scan_only || $guard >= 8 || ( $job['status'] ?? '' ) !== 'running' || microtime( true ) >= $deadline ) {
					return;
				}
				if ( $owner && $key ) {
					update_option( $this->option( $owner, $key ), $job, false );
				}
				$repeatable = true;
				$data       = array( 'done' => false );
				continue;
			}
			// Set restore reads remaining items from the manifest each batch (Uploads Scanner can be 10k+ files).
			if ( ( $p['op'] ?? '' ) === 'form' && ( $p['sub_action'] ?? '' ) === 'restore_quarantine' ) {
				if ( empty( $job['total'] ) ) {
					$job['total'] = max( 1, (int) ( $p['restore_total'] ?? 0 ) );
				}
				$p['restore_retry_failed'] = empty( $job['offset'] ) ? 1 : 0;
				$data                      = $this->plugin->execute_task( $p );
				$processed                 = max( 0, (int) ( $data['processed'] ?? 0 ) );
				$job['offset']             = min( (int) $job['total'], (int) ( $job['offset'] ?? 0 ) + $processed );
				foreach ( array( 'ok', 'fail' ) as $field ) {
					$data[ $field ] = (int) ( $job['result'][ $field ] ?? 0 ) + (int) ( $data[ $field ] ?? 0 );
				}
				$done = ! empty( $data['done'] ) || $job['offset'] >= (int) $job['total'];
				if ( ! $done && $processed < 1 ) {
					throw new RuntimeException( __( 'Restore made no progress. Check file permissions on uploads and quarantine.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				}
				$set_name         = LucidLab_MPC_Quarantine::source_label( (string) ( $job['source'] ?? $p['lucidlab_mpc_source'] ?? '' ) );
				$data['done']     = $done;
				$data['progress'] = 100 * $job['offset'] / max( 1, (int) $job['total'] );
				$data['label']    = $this->quarantine_set_label( 'restore', $set_name, (int) $job['offset'], (int) $job['total'], (int) ( $data['fail'] ?? 0 ) );
				$job['result']    = $data;
				$job['progress']  = $data['progress'];
				$job['label']     = $data['label'];
				if ( $done ) {
					$job['status'] = 'done';
					unset( $job['items'] );
					$this->record_history( $job );
				}
				++$guard;
				if ( $owner && $key && ( $job['status'] ?? '' ) === 'running' ) {
					update_option( $this->option( $owner, $key ), $job, false );
				}
				if ( ! ( $guard < 8 && ( $job['status'] ?? '' ) === 'running' && empty( $data['done'] ) && microtime( true ) < $deadline ) ) {
					return;
				}
				continue;
			}
			// The heading checkbox covers the saved uploads result. Paths stay on disk and are read in batches.
			// Keep moving for a few seconds: a 2s yield made a large result return after a single small batch.
			if ( ( $p['op'] ?? '' ) === 'quarantine' && ( $p['tab'] ?? '' ) === 'uploads' && ! empty( $p['all'] ) ) {
				if ( ! isset( $uploads_all_deadline ) ) {
					$uploads_all_deadline = microtime( true ) + 8.0;
				}
				if ( empty( $job['total'] ) ) {
					$job['total'] = max( 1, (int) ( $p['file_total'] ?? 0 ) );
				}
				$data = $this->plugin->execute_task( $p );
				if ( isset( $data['stamp'] ) ) {
					$job['request']['stamp'] = $data['stamp'];
				}
				$processed     = max( 0, (int) ( $data['batch'] ?? 0 ) );
				$job['offset'] = min( (int) $job['total'], (int) ( $job['offset'] ?? 0 ) + $processed );
				foreach ( array( 'ok', 'fail' ) as $field ) {
					$data[ $field ] = (int) ( $job['result'][ $field ] ?? 0 ) + (int) ( $data[ $field ] ?? 0 );
				}
				$data['errors'] = array_slice( array_merge( (array) ( $job['result']['errors'] ?? array() ), (array) ( $data['errors'] ?? array() ) ), -100 );
				$done           = ! empty( $data['done'] );
				if ( ! $done && $processed < 1 ) {
					throw new RuntimeException( __( 'Quarantine made no progress. Check file permissions on uploads and quarantine.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				}
				$data['done']     = $done;
				$data['progress'] = 100 * $job['offset'] / max( 1, (int) $job['total'] );
				$data['label']    = sprintf( /* translators: 1: files done so far, 2: total files. */ __( 'Quarantining %1$s / %2$s files', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $job['offset'] ), number_format_i18n( (int) $job['total'] ) ) . ( ! empty( $data['fail'] ) ? ' — ' . sprintf( /* translators: %s: number of failed items. */ __( '%s failed', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $data['fail'] ) ) : '' );
				$job['result']    = $data;
				$job['progress']  = $data['progress'];
				$job['label']     = $data['label'];
				if ( $done ) {
					$job['status'] = 'done';
					unset( $job['items'] );
					$this->record_history( $job );
				}
				++$guard;
				if ( $owner && $key && ( $job['status'] ?? '' ) === 'running' ) {
					update_option( $this->option( $owner, $key ), $job, false );
				}
				if ( ! ( $guard < 8 && ( $job['status'] ?? '' ) === 'running' && empty( $data['done'] ) && microtime( true ) < $uploads_all_deadline ) ) {
					return;
				}
				continue;
			}
			$batch_field = 'form' === $p['op'] ? 'commands' : ( 'merge' === $p['op'] ? 'pairs' : ( in_array( $p['op'], array( 'trash_dup', 'quarantine' ), true ) && ! in_array( $p['tab'] ?? '', array( 'sizes', 'fs-sizes' ), true ) ? 'items' : '' ) );
			if ( $batch_field ) {
				if ( ! isset( $job['items'] ) ) {
					$job['items'] = LucidLab_MPC_Helpers::sanitize_decoded( json_decode( (string) ( $p[ $batch_field ] ?? '[]' ), true ), array( 'list' => 'auto' ) );
					if ( ! is_array( $job['items'] ) || ! $job['items'] ) {
						throw new RuntimeException( __( 'No items selected', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
					}
					$job['total'] = count( $job['items'] );
				}
				$chunk_size = in_array( $p['op'], array( 'merge', 'form' ), true ) ? 1 : ( 'quarantine' === $p['op'] ? 50 : 25 );
				if ( ( $p['op'] ?? '' ) === 'form' && in_array( $p['sub_action'] ?? '', array( 'restore_quarantine', 'restore_one' ), true ) ) {
					$chunk_size = 200; }
				$chunk             = array_slice( $job['items'], $job['offset'], $chunk_size );
				$p[ $batch_field ] = wp_json_encode( $chunk );
			}
			$data                    = $this->plugin->execute_task( $p );
			$job['request']['reset'] = 0;
			if ( isset( $data['stamp'] ) ) {
				$job['request']['stamp'] = $data['stamp'];
			}
			if ( 'trash_dup' === $p['op'] ) {
				$job['request']['stamp'] = '1';
			}
			if ( $batch_field ) {
				$job['offset'] += count( $chunk );
				foreach ( array( 'ok', 'fail', 'repointed', 'products', 'variations' ) as $field ) {
					$data[ $field ] = (int) ( $job['result'][ $field ] ?? 0 ) + (int) ( $data[ $field ] ?? 0 );
				}
				foreach ( array( 'errors', 'fails' ) as $field ) {
					$data[ $field ] = array_slice( array_merge( $job['result'][ $field ] ?? array(), $data[ $field ] ?? array() ), -100 );
				}
				if ( ! empty( $data['fail'] ) && $chunk ) {
					$job['failed_ids'] = array_values( array_unique( array_merge( (array) ( $job['failed_ids'] ?? array() ), $chunk ) ) );
					$job['errors']     = LucidLab_MPC_Helpers::remember_error( (array) ( $job['errors'] ?? array() ), LucidLab_MPC_Helpers::either( implode( '; ', (array) ( $data['fails'] ?? array() ) ), __( 'Item failed', 'lucidlab-mpc-cleanup' ) ) );
				}
				$data['done']     = $job['offset'] >= $job['total'];
				$data['progress'] = 100 * $job['offset'] / $job['total'];
				$data['label']    = sprintf( /* translators: 1: items done so far, 2: total items. */ __( '%1$s / %2$s items processed', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $job['offset'] ), number_format_i18n( (int) $job['total'] ) ) . ( ! empty( $data['fail'] ) ? ' — ' . sprintf( /* translators: %s: number of failed items. */ __( '%s failed', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $data['fail'] ) ) : '' );
				if ( 'quarantine' === $p['op'] && isset( $data['attachments_total'], $data['files_total'] ) ) {
					$data['label'] = sprintf( /* translators: 1: attachments done so far, 2: total attachments, 3: attachments quarantined, 4: files in the quarantine set. */ __( '%1$s / %2$s attachments processed — %3$s attachments quarantined; %4$s files in this set (originals and thumbnails)', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $job['offset'] ), number_format_i18n( (int) $job['total'] ), number_format_i18n( (int) $data['attachments_total'] ), number_format_i18n( (int) $data['files_total'] ) ) . ( ! empty( $data['fail'] ) ? ' — ' . sprintf( /* translators: %s: number of failed items. */ __( '%s failed', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $data['fail'] ) ) : '' );
				}
				if ( in_array( $p['sub_action'] ?? '', array( 'restore_quarantine', 'restore_one' ), true ) ) {
					$set_name      = LucidLab_MPC_Quarantine::source_label( (string) ( $job['source'] ?? $p['lucidlab_mpc_source'] ?? '' ) );
					$data['label'] = $this->quarantine_set_label( 'restore', $set_name, (int) $job['offset'], (int) $job['total'], (int) ( $data['fail'] ?? 0 ) );
				} elseif ( in_array( $p['sub_action'] ?? '', array( 'purge_quarantine', 'purge_source' ), true ) ) {
					$set_name      = LucidLab_MPC_Quarantine::source_label( (string) ( $job['source'] ?? $p['lucidlab_mpc_source'] ?? '' ) );
					$data['label'] = $this->quarantine_set_label( 'delete', $set_name, (int) $job['offset'], (int) $job['total'], (int) ( $data['fail'] ?? 0 ) );
				}
			} elseif ( ! array_key_exists( 'done', $data ) ) {
				$data['done'] = true; }
			$job['result']            = $data;
			$job['progress']          = $data['progress'] ?? 100;
			$job['indeterminate']     = ! empty( $data['indeterminate'] ) && empty( $data['done'] );
			$job['progress_estimate'] = ! empty( $data['progress_estimate'] ) && empty( $data['done'] );
			$job['label']             = $data['label'] ?? ( ! empty( $data['done'] ) ? __( 'Completed', 'lucidlab-mpc-cleanup' ) : __( 'Processing…', 'lucidlab-mpc-cleanup' ) );
			$wait_until               = empty( $data['done'] ) ? (int) ( $data['wait_until'] ?? 0 ) : 0;
			if ( $wait_until > time() ) {
				// The step asked to wait (the site answered 429/503). The job stays running and the next
				// batch runs at that time: WP-Cron is scheduled for it, and the browser only polls the status.
				$job['retry_at'] = $wait_until;
				if ( $owner && $key ) {
					update_option( $this->option( $owner, $key ), $job, false );
				}
				return;
			}
			if ( ! empty( $data['done'] ) ) {
				$job['status'] = 'done';
				unset( $job['items'] );
				$this->record_history( $job ); }
			if ( ! empty( $data['fail'] ) && 'merge' === $p['op'] ) {
				$job['status'] = 'failed';
				$job['label']  = LucidLab_MPC_Helpers::either( implode( '; ', $data['fails'] ?? array() ), __( 'Some images could not be merged. Review Quarantine before retrying.', 'lucidlab-mpc-cleanup' ) );
				$this->record_history( $job ); }
			$op         = (string) ( $p['op'] ?? '' );
			$tab        = (string) ( $p['tab'] ?? '' );
			$repeatable = 'webpscan' === $op || 'scan' === $op || 'quarantine' === $op || ( 'form' === $op && in_array( $p['sub_action'] ?? '', array( 'restore_quarantine', 'restore_one' ), true ) );
			++$guard;
			if ( $repeatable && $owner && $key && ( $job['status'] ?? '' ) === 'running' ) {
				update_option( $this->option( $owner, $key ), $job, false );
			}
		} while ( $repeatable && $guard < 8 && ( $job['status'] ?? '' ) === 'running' && empty( $data['done'] ) && microtime( true ) < $deadline );
	}

	/**
	 * Quarantine set label.
	 *
	 * @param string $action   Action.
	 * @param string $set_name Set name.
	 * @param int    $offset   Offset.
	 * @param int    $total    Total.
	 * @param int    $failed   Failed.
	 *
	 * @return string
	 */
	private function quarantine_set_label( string $action, string $set_name, int $offset, int $total, int $failed ): string {
		$counts = number_format_i18n( $offset ) . ' / ' . number_format_i18n( $total );
		if ( 'delete' === $action ) {
			$label = $set_name
				? sprintf( /* translators: 1: quarantine set name, 2: done/total counts */ __( 'Deleting the %1$s set — %2$s items', 'lucidlab-mpc-cleanup' ), $set_name, $counts )
				: sprintf( /* translators: %s: done/total counts */ __( 'Deleting — %s items', 'lucidlab-mpc-cleanup' ), $counts );
		} else {
			$label = $set_name
				? sprintf( /* translators: 1: quarantine set name, 2: done/total counts */ __( 'Restoring the %1$s set — %2$s items', 'lucidlab-mpc-cleanup' ), $set_name, $counts )
				: sprintf( /* translators: %s: done/total counts */ __( 'Restoring — %s items', 'lucidlab-mpc-cleanup' ), $counts );
		}
		if ( $failed > 0 ) {
			$label .= ' — ' . sprintf( /* translators: %s: failed item count */ __( '%s failed', 'lucidlab-mpc-cleanup' ), number_format_i18n( $failed ) );
		}
		return $label;
	}

	/**
	 * Record history.
	 *
	 * @param array $job Job.
	 */
	private function record_history( array $job ): void {
		LucidLab_MPC_Helpers::prepend_job_history(
			array(
				'key'        => $job['key'] ?? '',
				'label'      => $job['label'] ?? '',
				'status'     => $job['status'] ?? '',
				'error'      => $job['last_error'] ?? '',
				'updated'    => time(),
				'failed'     => is_countable( $job['failed_ids'] ?? null ) ? count( (array) $job['failed_ids'] ) : 0,
				'failed_ids' => array_slice( LucidLab_MPC_Helpers::int_ids( $job['failed_ids'] ?? array() ), 0, 200 ),
				'panel'      => $job['panel'] ?? '',
				'scan_tab'   => $job['scan_tab'] ?? '',
				'can_retry'  => in_array( (string) ( $job['status'] ?? '' ), array( 'done', 'failed' ), true ) && ! empty( $job['failed_ids'] ),
				'special'    => $job['special'] ?? '',
			)
		);
		$op = sanitize_key( (string) ( $job['op'] ?? ( $job['request']['op'] ?? '' ) ) );
		if ( in_array( $op, array( 'scan', 'webpscan' ), true ) && class_exists( 'LucidLab_MPC_Logger', false ) ) {
			$source       = sanitize_key( (string) ( $job['scan_tab'] ?? $job['panel'] ?? '' ) );
			$status       = sanitize_key( (string) ( $job['status'] ?? '' ) );
			$status_label = array(
				'done'      => __( 'finished', 'lucidlab-mpc-cleanup' ),
				'cancelled' => __( 'cancelled', 'lucidlab-mpc-cleanup' ),
				'failed'    => __( 'failed', 'lucidlab-mpc-cleanup' ),
			);
			LucidLab_MPC_Logger::log(
				sprintf(
					/* translators: 1: scanner name, 2: terminal status, 3: result message */
					__( 'Scan %1$s: %2$s. %3$s', 'lucidlab-mpc-cleanup' ),
					$source ? LucidLab_MPC_Helpers::scan_tab_label( $source ) : __( 'unknown', 'lucidlab-mpc-cleanup' ),
					$status_label[ $status ] ?? ( ( $status ? $status : __( 'finished', 'lucidlab-mpc-cleanup' ) ) ),
					trim( (string) ( $job['label'] ?? '' ) )
				),
				'failed' === $status ? 'error' : ( 'cancelled' === $status ? 'warning' : 'info' )
			);
		} elseif ( 'run_all' === $op && class_exists( 'LucidLab_MPC_Logger', false ) ) {
			LucidLab_MPC_Logger::log(
				__( 'Run All', 'lucidlab-mpc-cleanup' ) . ': ' .
				( 'done' === ( $job['status'] ?? '' ) ? __( 'finished', 'lucidlab-mpc-cleanup' ) : (string) ( $job['status'] ?? '' ) ) . '. ' .
				trim( (string) ( $job['label'] ?? '' ) ),
				'failed' === ( $job['status'] ?? '' ) ? 'error' : 'info'
			);
		} elseif ( class_exists( 'LucidLab_MPC_Logger', false ) && in_array( $op, array( 'quarantine', 'restore_quarantine', 'purge_quarantine' ), true ) ) {
			$source   = sanitize_key( (string) ( $job['scan_tab'] ?? $job['panel'] ?? '' ) );
			$q_status = sanitize_key( (string) ( $job['status'] ?? '' ) );
			LucidLab_MPC_Logger::log(
				sprintf(
					/* translators: 1: action name, 2: tab name, 3: result message */
					__( '%1$s %2$s: %3$s', 'lucidlab-mpc-cleanup' ),
					$op,
					$source ? LucidLab_MPC_Helpers::scan_tab_label( $source ) : __( 'Quarantine', 'lucidlab-mpc-cleanup' ),
					trim( (string) ( $job['label'] ?? $q_status ) )
				),
				'failed' === $q_status ? 'error' : 'info'
			);
		}
	}
}
