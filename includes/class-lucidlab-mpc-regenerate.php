<?php
/**
 * Lucid Lab Media & Products Cleanup: regenerate class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab 7 — Regenerate thumbnails. Persistent job state (survives page reloads) with
 * pause + "continue in background" via WP-Cron. Honours disabled sizes + JPEG quality.
 */
class LucidLab_MPC_Regenerate {

	const JOB_OPTION = 'lucidlab_mpc_regen_job';
	const CRON_HOOK  = 'lucidlab_mpc_regen_tick';

	/**
	 * Only sizes.
	 *
	 * @var array
	 */
	private array $only_sizes = array();
	/**
	 * Quality.
	 *
	 * @var int
	 */
	private int $quality = 85;

	/**
	 * Job.
	 *
	 * @return array
	 */
	public function job(): array {
		$j             = get_option( self::JOB_OPTION );
		$defaults      = array(
			'status'      => 'idle',
			'driver'      => 'ajax',
			'mode'        => 'skip',
			'sizes'       => array(),
			'quality'     => 85,
			'offset'      => 0,
			'cursor'      => 0,
			'total'       => 0,
			'done'        => 0,
			'regenerated' => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'reasons'     => array(),
			'failed_ids'  => array(),
			'updated'     => 0,
		);
		$j             = is_array( $j ) ? array_merge( $defaults, $j ) : $defaults;
		$j['progress'] = $this->progress( $j );
		return $j;
	}

	/**
	 * Save.
	 *
	 * @param array $j J.
	 */
	private function save( array $j ): void {
		$previous      = get_option( self::JOB_OPTION, array() );
		$prior         = is_array( $previous ) ? (string) ( $previous['status'] ?? '' ) : '';
		$j['progress'] = $this->progress( $j );
		$j['updated']  = time();
		update_option( self::JOB_OPTION, $j, false );
		$status = (string) ( $j['status'] ?? '' );
		if ( in_array( $status, array( 'done', 'failed', 'cancelled' ), true ) && in_array( $prior, array( 'running', 'paused' ), true ) && $status !== $prior ) {
			$this->record_history( $j );
			if ( 'cancelled' === $status ) {
				/* translators: 1: processed thumbnails, 2: total, 3: regenerated, 4: skipped, 5: failed. */
				LucidLab_MPC_Logger::log( sprintf( __( 'Regenerate Thumbnails cancelled: processed %1$d of %2$d, regenerated %3$d, skipped %4$d, failed %5$d.', 'lucidlab-mpc-cleanup' ), (int) ( $j['done'] ?? 0 ), (int) ( $j['total'] ?? 0 ), (int) ( $j['regenerated'] ?? 0 ), (int) ( $j['skipped'] ?? 0 ), (int) ( $j['failed'] ?? 0 ) ) );
			}
		}
	}

	/**
	 * Progress.
	 *
	 * @param array $j J.
	 *
	 * @return float
	 */
	private function progress( array $j ): float {
		$total = max( 0, (int) ( $j['total'] ?? 0 ) );
		if ( in_array( (string) ( $j['status'] ?? '' ), array( 'done', 'failed' ), true ) ) {
			return 100.0;
		}
		if ( $total < 1 ) {
			return 0.0;
		}
		$processed = max( (int) ( $j['offset'] ?? 0 ), (int) ( $j['done'] ?? 0 ) );
		return min( 99.99, max( 0.0, 100 * $processed / $total ) );
	}

	/**
	 * Record history.
	 *
	 * @param array $j J.
	 */
	private function record_history( array $j ): void {
		$status = (string) ( $j['status'] ?? '' );
		if ( 'cancelled' === $status ) {
			$label = __( 'Cancelled.', 'lucidlab-mpc-cleanup' );
		} elseif ( 'failed' === $status ) {
			$label = __( 'Regenerate thumbnails failed.', 'lucidlab-mpc-cleanup' );
		} else {
			$label = sprintf(
				/* translators: 1: regenerated count, 2: skipped count, 3: failed count */
				__( 'Regenerated %1$d, skipped %2$d, failed %3$d', 'lucidlab-mpc-cleanup' ),
				(int) ( $j['regenerated'] ?? 0 ),
				(int) ( $j['skipped'] ?? 0 ),
				(int) ( $j['failed'] ?? 0 )
			);
		}
		$failed_ids = array_slice( array_map( 'intval', (array) ( $j['failed_ids'] ?? array() ) ), 0, 200 );
		LucidLab_MPC_Helpers::prepend_job_history(
			array(
				'key'        => 'lucidlab_mpc_regen_job',
				'label'      => $label,
				'status'     => $status,
				'error'      => 'failed' === $status ? $label : '',
				'updated'    => (int) ( $j['updated'] ?? time() ),
				'failed'     => (int) ( $j['failed'] ?? 0 ),
				'failed_ids' => $failed_ids,
				'panel'      => 'regenerate',
				'scan_tab'   => 'regenerate',
				'can_retry'  => in_array( $status, array( 'done', 'failed' ), true ) && (int) ( $j['failed'] ?? 0 ) > 0,
				'special'    => 'regen',
			)
		);
	}

	/**
	 * Record a capped, de-duplicated reason so the UI can explain skips/failures.
	 *
	 * @param array  &$j    J.
	 * @param string $type Type.
	 * @param int    $id   Id.
	 * @param string $why  Why.
	 */
	private function note( array &$j, string $type, int $id, string $why ): void {
		if ( empty( $j['reasons'] ) || ! is_array( $j['reasons'] ) ) {
			$j['reasons'] = array();
		}
		$j['reasons'][] = array(
			'type' => $type,
			'id'   => $id,
			'why'  => $why,
		);
		if ( count( $j['reasons'] ) > 12 ) {
			$j['reasons'] = array_slice( $j['reasons'], -12 );
		}
	}

	/**
	 * Count targets.
	 *
	 * @param string $mode Mode.
	 *
	 * @return int
	 */
	public function count_targets( string $mode ): int {
		$index = class_exists( 'LucidLab_MPC_Index' ) ? LucidLab_MPC_Index::get() : array();
		if ( 'all' === $mode && ! empty( $index['count'] ) ) {
			return (int) $index['count']; }
		global $wpdb;
		if ( 'featured' === $mode ) {
			return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				"SELECT COUNT(DISTINCT pm.meta_value) FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key='_thumbnail_id' AND p.post_type IN ('post','page','product')"
			);
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
	}

	/**
	 * Start.
	 *
	 * @param string $mode    Mode.
	 * @param array  $sizes   Sizes.
	 * @param int    $quality Quality.
	 *
	 * @return array
	 */
	public function start( string $mode, array $sizes, int $quality ): array {
		$j = array(
			'status'      => 'running',
			'driver'      => 'ajax',
			'mode'        => $mode,
			'sizes'       => array_values( $sizes ),
			'quality'     => max( 1, min( 100, $quality ) ),
			'offset'      => 0,
			'cursor'      => 0,
			'total'       => $this->count_targets( $mode ),
			'done'        => 0,
			'regenerated' => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'reasons'     => array(),
			'failed_ids'  => array(),
		);
		$this->save( $j );
		LucidLab_MPC_Logger::log( __( 'Regenerate started: mode=', 'lucidlab-mpc-cleanup' ) . $mode . ' total=' . $j['total'] );
		return $this->job();
	}

	/**
	 * Pause.
	 *
	 * @return array
	 */
	public function pause(): array {
		$j = $this->job();
		if ( 'running' === $j['status'] ) {
			$j['status'] = 'paused';
			$this->save( $j );
		}
		return $this->job();
	}

	/**
	 * Background.
	 *
	 * @return array
	 */
	public function background(): array {
		$j = $this->job();
		if ( in_array( $j['status'], array( 'running', 'paused' ), true ) ) {
			$j['status'] = 'running';
			$j['driver'] = 'cron';
			$this->save( $j );
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( time() + 2, self::CRON_HOOK );
			}
		}
		return $this->job();
	}

	/**
	 * Reset.
	 */
	public function reset(): void {
		delete_option( self::JOB_OPTION );
	}

	/**
	 * Id batch.
	 *
	 * @param array $j     J.
	 * @param int   $limit Limit.
	 *
	 * @return array
	 */
	private function id_batch( array $j, int $limit ): array {
		global $wpdb;
		if ( ! empty( $j['retry_ids'] ) ) {
			return array_slice( LucidLab_MPC_Helpers::int_ids( $j['retry_ids'] ?? array() ), 0, $limit );
		}
		if ( 'featured' === $j['mode'] ) {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
					 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						WHERE pm.meta_key='_thumbnail_id' AND p.post_type IN ('post','page','product') AND CAST(pm.meta_value AS UNSIGNED) > %d
						ORDER BY CAST(pm.meta_value AS UNSIGNED) LIMIT %d",
					(int) ( $j['cursor'] ?? 0 ),
					$limit
				)
			);
		} else {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE %s AND ID > %d
						ORDER BY ID LIMIT %d",
					$wpdb->esc_like( 'image/' ) . '%',
					(int) ( $j['cursor'] ?? 0 ),
					$limit
				)
			);
		}
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Process one batch; returns updated job.
	 *
	 * @param int $limit Limit.
	 *
	 * @return array
	 */
	public function step( int $limit = 15 ): array {
		$j = $this->job();
		if ( 'running' !== $j['status'] ) {
			return $j;
		}
		$this->only_sizes = (array) $j['sizes'];
		$this->quality    = (int) $j['quality'];

		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_sizes' ), 99 );
		add_filter( 'jpeg_quality', array( $this, 'filter_quality' ), 99 );
		add_filter( 'wp_editor_set_quality', array( $this, 'filter_quality' ), 99 );

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once LucidLab_MPC_Helpers::admin_dir() . 'includes/image.php';
		}

		$ids = $this->id_batch( $j, $limit );
		if ( $ids ) {
			$j['cursor'] = max( (int) ( $j['cursor'] ?? 0 ), max( $ids ) ); }
		if ( ! empty( $j['retry_ids'] ) ) {
			$j['retry_ids'] = array_values( array_diff( LucidLab_MPC_Helpers::int_ids( $j['retry_ids'] ?? array() ), $ids ) );
		}
		foreach ( $ids as $id ) {
			$resource = LucidLab_MPC_Helpers::resource_error();
			if ( $resource ) {
				$j = LucidLab_MPC_Helpers::pause_for_resource( $j, $resource );
				$this->save( $j );
				return $j;
			}
			++$j['done'];
			if ( 'attachment' !== get_post_type( $id ) ) {
				++$j['failed'];
				$j['failed_ids'][] = $id;
				$this->note( $j, 'failed', $id, __( 'not an image attachment', 'lucidlab-mpc-cleanup' ) );
				continue; }
			$file = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::attached_file_path( $id ) : (string) get_attached_file( $id );
			if ( ! $file || ! file_exists( $file ) ) {
				++$j['failed'];
				$j['failed_ids'][] = $id;
				$this->note( $j, 'failed', $id, __( 'source file missing on disk', 'lucidlab-mpc-cleanup' ) );
				continue; }

			if ( 'skip' === $j['mode'] && $this->already_ok( $id ) ) {
				++$j['skipped'];
				$this->note( $j, 'skipped', $id, __( 'all sizes already present & correct', 'lucidlab-mpc-cleanup' ) );
			} else {
				$previous = (array) wp_get_attachment_metadata( $id );
				$meta     = wp_generate_attachment_metadata( $id, $file );
				if ( is_array( $meta ) && ! empty( $meta ) ) {
					// A partial regeneration must not unregister other existing thumbnails.
					$retained      = array_filter( (array) ( $previous['sizes'] ?? array() ), static fn( $size )=> ! empty( $size['file'] ) && is_file( trailingslashit( dirname( $file ) ) . $size['file'] ) );
					$meta['sizes'] = array_merge( $retained, (array) ( $meta['sizes'] ?? array() ) );
					wp_update_attachment_metadata( $id, $meta );
					++$j['regenerated'];
				} else {
					++$j['failed'];
					$j['failed_ids'][] = $id;
					$this->note( $j, 'failed', $id, __( 'editor could not process (unsupported/corrupt file, or no Imagick/GD)', 'lucidlab-mpc-cleanup' ) );
				}
			}
		}

		remove_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_sizes' ), 99 );
		remove_filter( 'jpeg_quality', array( $this, 'filter_quality' ), 99 );
		remove_filter( 'wp_editor_set_quality', array( $this, 'filter_quality' ), 99 );

		$j['offset'] += count( $ids );
		if ( count( $ids ) < $limit || $j['done'] >= $j['total'] ) {
			$j['status'] = 'done';
			LucidLab_MPC_Logger::log( __( 'Regenerate done: regen=', 'lucidlab-mpc-cleanup' ) . $j['regenerated'] . ' skip=' . $j['skipped'] . ' fail=' . $j['failed'] );
		}
		$this->save( $j );
		return $j;
	}

	/**
	 * Already ok.
	 *
	 * @param int $id Id.
	 *
	 * @return bool
	 */
	private function already_ok( int $id ): bool {
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) ) {
			return false;
		}
		$file       = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::attached_file_path( $id ) : (string) get_attached_file( $id );
		$dir        = $file ? trailingslashit( dirname( $file ) ) : '';
		$registered = wp_get_registered_image_subsizes();
		$selected   = $this->filter_sizes( $registered );
		foreach ( $selected as $name => $size ) {
			$dimensions = image_resize_dimensions( (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ), (int) $size['width'], (int) $size['height'], $size['crop'] );
			// WordPress does not create a thumbnail when the source is too small.
			if ( ! $dimensions ) {
				continue; }
			$thumbnail = $meta['sizes'][ $name ] ?? array();
			if ( empty( $thumbnail['file'] ) || ! $dir || ! file_exists( $dir . $thumbnail['file'] ) || (int) ( $thumbnail['width'] ?? 0 ) !== $dimensions[4] || (int) ( $thumbnail['height'] ?? 0 ) !== $dimensions[5] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Filter sizes.
	 *
	 * @param array $sizes Sizes.
	 *
	 * @return array
	 */
	public function filter_sizes( array $sizes ): array {
		if ( empty( $this->only_sizes ) ) {
			return $sizes;
		}
		// An explicit regeneration selection overrides theme filters which removed a size.
		$selected = array_intersect_key( wp_get_registered_image_subsizes(), array_flip( $this->only_sizes ) );
		return array_diff_key( $selected, array_flip( (array) LucidLab_MPC_Settings::get( 'disabled_sizes' ) ) );
	}

	/**
	 * Filter quality.
	 *
	 * @return int
	 */
	public function filter_quality(): int {
		return $this->quality;
	}

	/**
	 * WP-Cron tick: process a few batches then reschedule while running in background.
	 *
	 * @param int $id Id.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public function retry( int $id = 0 ): array {
		$j = $this->job();
		if ( in_array( $j['status'], array( 'running', 'paused' ), true ) ) {
			throw new RuntimeException( __( 'Stop the current regeneration job before retrying failed images.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		$failed_reasons = array_values( array_filter( (array) ( $j['reasons'] ?? array() ), static fn( $reason ) => ( $reason['type'] ?? '' ) === 'failed' ) );
		$ids            = $id > 0 ? array( $id ) : LucidLab_MPC_Helpers::retry_ids( $j, $failed_reasons );
		if ( ! $ids ) {
			throw new RuntimeException( __( 'No failed images are available to retry.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		foreach ( $ids as $retry_id ) {
			if ( 'attachment' !== get_post_type( $retry_id ) ) {
				throw new RuntimeException( __( 'A failed image is no longer available.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$j = array_merge(
			$j,
			array(
				'status'      => 'running',
				'driver'      => 'ajax',
				'offset'      => 0,
				'cursor'      => 0,
				'total'       => count( $ids ),
				'done'        => 0,
				'regenerated' => 0,
				'skipped'     => 0,
				'failed'      => 0,
				'reasons'     => array(),
				'failed_ids'  => array(),
				'retry_ids'   => $ids,
			)
		);
		$this->save( $j );
		return $this->step( max( 1, count( $ids ) ) );
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
	public function command( string $action, array $input = array() ): array {
		global $wpdb;
		$lock = 'lucidlab_mpc_regen_' . md5( $wpdb->prefix );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			return array_merge( $this->job(), array( 'busy' => true ) );
		}
		try {
			wp_cache_delete( self::JOB_OPTION, 'options' );
			$j = $this->job();
			if ( ( $j['driver'] ?? '' ) === 'run_all' && class_exists( 'LucidLab_MPC_Edition' ) && ! LucidLab_MPC_Edition::pro() ) {
				$j['driver'] = 'ajax';
				if ( 'running' === $j['status'] ) {
					$j['status'] = 'paused';
				}$this->save( $j );
				wp_clear_scheduled_hook( self::CRON_HOOK );}
			if ( ( $j['driver'] ?? '' ) === 'run_all' && ! in_array( $action, array( 'status', 'managed_step', 'managed_start', 'managed_release' ), true ) ) {
				throw new RuntimeException( __( 'This regeneration is controlled by Run All. Use its controls.', 'lucidlab-mpc-cleanup' ) );
			}
			if ( 'managed_start' === $action && 'run_all' === ( $j['driver'] ?? '' ) && ( $j['owner'] ?? '' ) !== ( $input['owner'] ?? '' ) ) {
				throw new RuntimeException( __( 'Another Run All job owns this worker.', 'lucidlab-mpc-cleanup' ) );
			}
			if ( 'managed_release' === $action ) {
				if ( ( $j['driver'] ?? '' ) === 'run_all' && ( $j['owner'] ?? '' ) === ( $input['owner'] ?? '' ) ) {
					$j['driver'] = 'ajax';
					unset( $j['owner'] );
					if ( in_array( $j['status'] ?? '', array( 'running', 'paused' ), true ) ) {
						$j['status'] = 'cancelled';
						wp_clear_scheduled_hook( self::CRON_HOOK );
					} $this->save( $j );
				}return $j;}
			if ( 'managed_start' === $action ) {
				if ( in_array( $j['status'], array( 'running', 'paused' ), true ) ) {
					throw new RuntimeException( __( 'Regeneration is already pending. Finish or cancel it before Run All.', 'lucidlab-mpc-cleanup' ) );
				}$j          = $this->start( $input['mode'] ?? 'skip', $input['sizes'] ?? array(), (int) ( $input['quality'] ?? 85 ) );
				$j['driver'] = 'run_all';
				$j['status'] = 'running';
				$j['owner']  = $input['owner'];
				$this->save( $j );
				wp_clear_scheduled_hook( self::CRON_HOOK );
				return $j;}
			if ( 'managed_step' === $action && ( ( $j['owner'] ?? '' ) !== ( $input['owner'] ?? '' ) || 'run_all' !== $j['driver'] ) ) {
				throw new RuntimeException( __( 'Regeneration ownership changed.', 'lucidlab-mpc-cleanup' ) );
			}
			if ( 'start' === $action ) {
				if ( 'running' === $j['status'] ) {
					throw new RuntimeException( __( 'Regeneration is already running.', 'lucidlab-mpc-cleanup' ) );
				}
				if ( class_exists( 'LucidLab_MPC_Plugin', false ) ) {
					LucidLab_MPC_Plugin::assert_job_slot( 'regen' );
				}
				return $this->start( $input['mode'] ?? 'skip', $input['sizes'] ?? array(), (int) ( $input['quality'] ?? 85 ) );
			}
			if ( 'pause' === $action ) {
				$j['status'] = 'paused'; } elseif ( 'cancel' === $action || 'reset' === $action ) {
				$j['status'] = 'cancelled'; } elseif ( in_array( $action, array( 'resume', 'bg' ), true ) && in_array( $j['status'], array( 'paused', 'running' ), true ) ) {
					$j['status'] = 'running';
					$j['driver'] = 'bg' === $action ? 'cron' : 'ajax'; } elseif ( ( ( 'step' === $action && 'ajax' === $j['driver'] ) || ( 'cron' === $action && 'cron' === $j['driver'] ) || ( 'managed_step' === $action && 'run_all' === $j['driver'] ) ) && 'running' === $j['status'] ) {
					$j = $this->step( max( 1, min( 100, (int) LucidLab_MPC_Settings::get( 'regen_batch' ) ) ) ); }
					$this->save( $j );
					if ( 'running' === $j['status'] && 'cron' === $j['driver'] ) {
						if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
							wp_schedule_single_event( time() + 2, self::CRON_HOOK );
						}
					} else {
						wp_clear_scheduled_hook( self::CRON_HOOK );
					}
						return $j;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
	}

	/**
	 * Cron tick.
	 */
	public function cron_tick(): void {
		$j = $this->command( 'cron' );
		LucidLab_MPC_Helpers::mark_cron_ok();
		if ( 'running' === $j['status'] && 'cron' === $j['driver'] && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 2, self::CRON_HOOK );
		}
	}
}
