<?php
/**
 * Lucid Lab Media & Products Cleanup: ai job class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LucidLab_MPC_AI_Job.
 */
final class LucidLab_MPC_AI_Job {
	const RESULT  = '_lucidlab_mpc_ai_suggestion';
	const JOB_KEY = 'lucidlab_mpc_ai_library';

	/**
	 * Boot.
	 */
	public static function boot(): void {
		add_action( 'lucidlab_mpc_ai_tick', array( __CLASS__, 'cron_tick' ), 10, 2 );
	}

	/**
	 * Cron tick.
	 *
	 * @param int    $owner Owner.
	 * @param string $key   Key.
	 */
	public static function cron_tick( int $owner = 0, string $key = '' ): void {
		if ( $owner <= 0 ) {
			$owner = get_current_user_id();
		}
		if ( $owner <= 0 ) {
			return;
		}
		$key = '' !== $key ? $key : self::JOB_KEY;
		$job = get_option( self::job_option( $owner ), array() );
		if ( ! is_array( $job ) ) {
			return;
		}
		$now      = time();
		$status   = (string) ( $job['status'] ?? '' );
		$retry_at = (int) ( $job['retry_at'] ?? 0 );
		if ( $retry_at > $now ) {
			wp_schedule_single_event( $retry_at, 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
			return;
		}
		if ( 'running' !== $status && ! ( 'paused' === $status && $retry_at > 0 ) ) {
			return;
		}
		$job['status']   = 'running';
		$job['driver']   = 'cron';
		$job['retry_at'] = 0;
		$job['updated']  = $now;
		update_option( self::job_option( $owner ), $job, false );
		$switched = LucidLab_MPC_I18n::switch_to_user( $owner );
		try {
			self::command( 'cron', array( 'key' => $key ) );
		} finally {
			LucidLab_MPC_I18n::restore( $switched );
		}
	}

	/**
	 * Job option.
	 *
	 * @param int $owner Owner.
	 *
	 * @return string
	 */
	public static function job_option( int $owner = 0 ): string {
		$owner = max( 1, ( (int) $owner ? (int) $owner : get_current_user_id() ) );
		return 'lucidlab_mpc_ai_job_' . $owner;
	}

	/**
	 * Fingerprint.
	 *
	 * @param int $attachment_id Attachment id.
	 *
	 * @return string
	 */
	public static function fingerprint( int $attachment_id ): string {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_file( $file ) ) {
			return md5( (string) $attachment_id );
		}
		return LucidLab_MPC_Helpers::either( md5_file( $file ), md5( (string) $file ) );
	}

	/**
	 * Save result.
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param mixed $result        Result.
	 *
	 * @return array
	 */
	public static function save_result( int $attachment_id, $result ): array {
		if ( is_wp_error( $result ) ) {
			$payload = array(
				'error'           => $result->get_error_message(),
				'code'            => $result->get_error_code(),
				'fingerprint'     => self::fingerprint( $attachment_id ),
				'attachment_id'   => (int) $attachment_id,
				'review_required' => true,
			);
			update_post_meta( $attachment_id, self::RESULT, $payload );
			return $payload;
		}

		$payload                    = is_array( $result ) ? $result : array();
		$payload['attachment_id']   = (int) $attachment_id;
		$payload['fingerprint']     = self::fingerprint( $attachment_id );
		$payload['review_required'] = true;
		update_post_meta( $attachment_id, self::RESULT, $payload );
		return $payload;
	}

	/**
	 * Suggestion filename.
	 *
	 * @param array $saved Saved.
	 *
	 * @return string
	 */
	public static function suggestion_filename( array $saved ): string {
		$filename = (string) ( $saved['filename'] ?? '' );
		if ( class_exists( 'LucidLab_MPC_Helpers', false ) ) {
			return LucidLab_MPC_Helpers::filename_stem( $filename );
		}
		$filename = sanitize_file_name( $filename );
		$filename = strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '-', pathinfo( $filename, PATHINFO_FILENAME ) ) );
		return trim( (string) preg_replace( '/-+/', '-', $filename ), '-' );
	}

	/**
	 * Suggestion title.
	 *
	 * @param array $saved Saved.
	 *
	 * @return string
	 */
	public static function suggestion_title( array $saved ): string {
		$title = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::human_phrase( (string) ( $saved['title'] ?? '' ) ) : sanitize_text_field( (string) ( $saved['title'] ?? '' ) );
		if ( '' === $title ) {
			$filename = self::suggestion_filename( $saved );
			$title    = '' !== $filename && class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::title_from_slug( $filename ) : '';
		}
		return $title;
	}

	/**
	 * Suggestion alt.
	 *
	 * @param array $saved Saved.
	 *
	 * @return string
	 */
	public static function suggestion_alt( array $saved ): string {
		$raw = (string) ( $saved['alt_text'] ?? '' );
		$alt = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::alt_phrase( $raw ) : sanitize_text_field( $raw );
		if ( '' === $alt ) {
			$alt = self::suggestion_title( $saved );
		}
		return $alt;
	}

	/**
	 * Snapshot.
	 *
	 * @return array
	 */
	public static function snapshot(): array {
		return self::command( 'status' );
	}

	/**
	 * Command.
	 *
	 * @param string $action Action.
	 * @param array  $input  Input.
	 *
	 * @return array
	 */
	public static function command( string $action = 'status', array $input = array() ): array {
		$owner = get_current_user_id();
		if ( $owner <= 0 || ! current_user_can( 'manage_options' ) ) {
			return array(
				'status'    => 'ready',
				'label'     => __( 'Permission denied.', 'lucidlab-mpc-cleanup' ),
				'usage'     => LucidLab_MPC_AI::usage(),
				'processed' => 0,
				'total'     => 0,
				'driver'    => 'ajax',
				'updated'   => time(),
			);
		}

		$key    = sanitize_key( $input['key'] ?? self::JOB_KEY );
		$option = self::job_option( $owner );
		$job    = get_option( $option, array() );
		if ( ! is_array( $job ) ) {
			$job = array();
		}

		if ( 'status' === $action ) {
			return self::normalize_state( $job );
		}

		if ( 'start' === $action ) {
			$job = array(
				'key'        => $key,
				'status'     => 'running',
				'driver'     => 'ajax',
				'processed'  => 0,
				'total'      => self::total_images(),
				'offset'     => 0,
				'updated'    => time(),
				'progress'   => 0,
				'label'      => __( 'Starting AI library review…', 'lucidlab-mpc-cleanup' ),
				'result'     => array(),
				'retry_at'   => 0,
				'last_error' => '',
			);
			update_option( $option, $job, false );
			wp_clear_scheduled_hook( 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
			return self::normalize_state( $job );
		}

		if ( empty( $job ) || ! isset( $job['status'] ) ) {
			return self::normalize_state( array() );
		}

		if ( 'stop' === $action && 'running' === ( $job['status'] ?? '' ) ) {
			$job['status']  = 'paused';
			$job['label']   = __( 'Paused after the current image.', 'lucidlab-mpc-cleanup' );
			$job['updated'] = time();
			update_option( $option, $job, false );
			wp_clear_scheduled_hook( 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
			return self::normalize_state( $job );
		}

		if ( 'resume' === $action || 'background' === $action ) {
			$job['status']     = 'running';
			$job['driver']     = 'background' === $action ? 'cron' : 'ajax';
			$job['retry_at']   = 0;
			$job['last_error'] = '';
			$job['updated']    = time();
			update_option( $option, $job, false );
			if ( 'background' === $action ) {
				wp_clear_scheduled_hook( 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
				wp_schedule_single_event( time() + 30, 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
			}
			return self::normalize_state( $job );
		}

		if ( 'cancel' === $action ) {
			$job['status']     = 'cancelled';
			$job['label']      = __( 'AI library review cancelled. Completed results remain saved.', 'lucidlab-mpc-cleanup' );
			$job['updated']    = time();
			$job['retry_at']   = 0;
			$job['last_error'] = '';
			update_option( $option, $job, false );
			wp_clear_scheduled_hook( 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
			return self::normalize_state( $job );
		}

		if ( ( 'step' === $action || 'cron' === $action ) && ( 'running' === ( $job['status'] ?? '' ) ) ) {
			try {
				$job = self::step_job( $job, $owner, $key );
				update_option( $option, $job, false );
				return self::normalize_state( $job );
			} catch ( Throwable $e ) {
				$job['status']     = 'paused';
				$job['last_error'] = $e->getMessage();
				$job['retry_at']   = time() + 60;
				$job['label']      = sprintf( /* translators: %s: error message. */ __( 'Paused: %s', 'lucidlab-mpc-cleanup' ), $e->getMessage() );
				$job['updated']    = time();
				update_option( $option, $job, false );
				wp_clear_scheduled_hook( 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
				wp_schedule_single_event( (int) $job['retry_at'], 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
				return self::normalize_state( $job );
			}
		}

		return self::normalize_state( $job );
	}

	/**
	 * Total images.
	 *
	 * @return int
	 */
	private static function total_images(): int {
		global $wpdb;
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_status = 'inherit'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		return max( 0, (int) $count );
	}

	/**
	 * Step job.
	 *
	 * @param array  $job   Job.
	 * @param int    $owner Owner.
	 * @param string $key   Key.
	 *
	 * @return array
	 */
	private static function step_job( array $job, int $owner, string $key ): array {
		$job['updated'] = time();
		$limit          = 5;
		$offset         = (int) ( $job['offset'] ?? 0 );
		$total          = max( 1, (int) ( $job['total'] ?? self::total_images() ) );

		$images = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $images ) ) {
			$job['status']   = 'done';
			$job['label']    = __( 'AI library review complete.', 'lucidlab-mpc-cleanup' );
			$job['progress'] = 100;
			$job['updated']  = time();
			$job['retry_at'] = 0;
			wp_clear_scheduled_hook( 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
			return $job;
		}

		$processed = 0;
		foreach ( $images as $image_id ) {
			$id            = (int) $image_id;
			$saved         = get_post_meta( $id, self::RESULT, true );
			$fingerprint   = self::fingerprint( $id );
			$already_saved = is_array( $saved ) && ! empty( $saved['fingerprint'] ) && (string) $saved['fingerprint'] === (string) $fingerprint;

			if ( ! $already_saved ) {
				$context = class_exists( 'LucidLab_MPC_Review' ) ? LucidLab_MPC_Review::get_similarity_context( $id ) : array();
				$result  = method_exists( 'LucidLab_MPC_AI', 'analyze_with_similarity' )
					? LucidLab_MPC_AI::analyze_with_similarity( $id, $context )
					: LucidLab_MPC_AI::analyze_image( $id );

				if ( is_wp_error( $result ) ) {
					$error_code = $result->get_error_code();
					if ( in_array( $error_code, array( 'lucidlab_mpc_ai_limit', 'lucidlab_mpc_ai_resource', 'lucidlab_mpc_ai_usage_busy' ), true ) ) {
						$job['status']     = 'paused';
						$job['label']      = $result->get_error_message();
						$job['updated']    = time();
						$job['last_error'] = $result->get_error_message();
						$job['retry_at']   = time() + 60;
						wp_clear_scheduled_hook( 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
						wp_schedule_single_event( (int) $job['retry_at'], 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
						return $job;
					}
					self::save_result( $id, $result );
				} else {
					self::save_result( $id, $result );
				}
			}

			++$processed;
		}

		$offset          += $processed;
		$job['offset']    = $offset;
		$job['processed'] = min( (int) ( $job['processed'] ?? 0 ) + $processed, $total );
		$job['progress']  = $total > 0 ? min( 99, (int) round( ( 100 * $job['processed'] ) / $total ) ) : 100;
		/* translators: 1: images reviewed so far, 2: total images to review. */
		$job['label'] = sprintf( __( 'Reviewing library images (%1$d / %2$d)…', 'lucidlab-mpc-cleanup' ), $job['processed'], $total );

		if ( $job['processed'] >= $total ) {
			$job['status']   = 'done';
			$job['label']    = __( 'AI library review complete.', 'lucidlab-mpc-cleanup' );
			$job['progress'] = 100;
			$job['updated']  = time();
			wp_clear_scheduled_hook( 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
			return $job;
		}

		if ( 'cron' === $job['driver'] ) {
			wp_clear_scheduled_hook( 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
			wp_schedule_single_event( time() + 30, 'lucidlab_mpc_ai_tick', array( $owner, $key ) );
		}

		return $job;
	}

	/**
	 * Normalize state.
	 *
	 * @param array $job Job.
	 *
	 * @return array
	 */
	private static function normalize_state( array $job ): array {
		$state = array(
			'key'       => self::JOB_KEY,
			'status'    => 'ready',
			'driver'    => 'ajax',
			'processed' => 0,
			'total'     => 0,
			'progress'  => 0,
			'label'     => __( 'No library job started.', 'lucidlab-mpc-cleanup' ),
			'usage'     => LucidLab_MPC_AI::usage(),
			'updated'   => time(),
			'next_cron' => 0,
		);

		if ( ! empty( $job ) ) {
			$state['key']       = (string) ( $job['key'] ?? self::JOB_KEY );
			$state['status']    = (string) ( $job['status'] ?? 'ready' );
			$state['driver']    = (string) ( $job['driver'] ?? 'ajax' );
			$state['processed'] = (int) ( $job['processed'] ?? 0 );
			$state['total']     = max( 1, (int) ( $job['total'] ?? self::total_images() ) );
			$state['progress']  = (int) min( 100, max( 0, $job['progress'] ?? ( $state['total'] > 0 ? ( 100 * $state['processed'] ) / $state['total'] : 0 ) ) );
			$state['label']     = (string) ( $job['label'] ?? __( 'No library job started.', 'lucidlab-mpc-cleanup' ) );
			$state['usage']     = LucidLab_MPC_AI::usage();
			$state['updated']   = (int) ( $job['updated'] ?? time() );
			$state['next_cron'] = ! empty( $job['retry_at'] ) ? (int) $job['retry_at'] : 0;
		}

		return $state;
	}
}
