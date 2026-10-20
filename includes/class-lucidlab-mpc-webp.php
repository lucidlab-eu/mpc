<?php
/**
 * Lucid Lab Media & Products Cleanup: webp class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WebP / AVIF copies in separate uploads folders, with persistent conversion jobs. */
class LucidLab_MPC_Webp {

	const STATS_OPTION    = 'lucidlab_mpc_webp_stats';
	const JOB_OPTION      = 'lucidlab_mpc_webp_job';
	const STOP_OPTION     = 'lucidlab_mpc_webp_stop';
	const CRON_HOOK       = 'lucidlab_mpc_webp_tick';
	const LARGER_AVIF     = 'lucidlab_mpc_larger_avif';
	const LARGER_WEBP     = 'lucidlab_mpc_larger_webp';
	const GEN_SCAN_BUDGET = 3.0;
	// A running conversion that saved no progress for this long is checked for a dead worker.
	const STALE_AFTER = 900;
	// A running conversion without progress for this long is marked failed so it stops holding the job slot.
	const DEAD_AFTER = 21600;
	// Steps that ended without saving (PHP fatal error, timeout, memory) before the job is marked failed.
	const MAX_INTERRUPTS = 3;
	const GEN_SKIP_DIRS  = array( 'lucidlab-mpc-cleanup', '_cleanup-quarantine', '_cleanup-fsindex', '_cleanup-format-preview', 'mpc-releases', 'woocommerce_uploads' );
	/**
	 * Savings label.
	 *
	 * @param int $bytes Bytes.
	 *
	 * @return string
	 */
	public static function savings_label( int $bytes ): string {
		return ( $bytes < 0 ? '-' : '' ) . LucidLab_MPC_Helpers::bytes( abs( $bytes ) ); }

	/**
	 * Drop quarantined JPEG/PNG files out of the stored library totals.
	 *
	 * @param int $count Count.
	 * @param int $bytes Bytes.
	 */
	public static function reduce_original_stats( int $count, int $bytes ): void {
		$stats               = self::get_stats();
		$stats['orig_count'] = max( 0, (int) $stats['orig_count'] - max( 0, $count ) );
		$stats['orig_bytes'] = max( 0, (int) $stats['orig_bytes'] - max( 0, $bytes ) );
		self::save_stats( $stats );
	}

	/**
	 * Job formats.
	 *
	 * @param array $j J.
	 *
	 * @return array
	 */
	public static function job_formats( array $j ): array {
		return array_values( array_filter( (array) ( $j['formats'] ?? array() ), static fn( $fmt ) => in_array( $fmt, array( 'webp', 'avif' ), true ) ) );
	}

	/**
	 * Advance format progress.
	 *
	 * @param array $j         J.
	 * @param int   $processed Processed.
	 *
	 * @return array
	 */
	public static function advance_format_progress( array $j, int $processed ): array {
		$formats     = self::job_formats( $j );
		$j['offset'] = (int) ( $j['offset'] ?? 0 ) + max( 0, $processed );
		$total       = (int) ( $j['total'] ?? 0 );
		$index       = (int) ( $j['format_index'] ?? 0 );
		if ( $processed < 1 || ( $total > 0 && $j['offset'] >= $total ) ) {
			++$index;
			$j['format_index'] = $index;
			if ( $index < count( $formats ) ) {
				$j['offset'] = 0;
				$j['cursor'] = 0;
			} else {
				$j['status'] = 'done';
				$j['offset'] = $total;
				unset( $j['retry_ids'] );
			}
		}
		return $j;
	}

	/**
	 * Conversion progress percent.
	 *
	 * @param array $j J.
	 *
	 * @return int
	 */
	public static function conversion_progress_percent( array $j ): int {
		if ( ( $j['status'] ?? '' ) === 'done' ) {
			return 100;
		}
		$formats = self::job_formats( $j );
		$total   = (int) ( $j['total'] ?? 0 );
		if ( $total < 1 ) {
			return 0;
		}
		$index   = (int) ( $j['format_index'] ?? 0 );
		$offset  = min( $total, (int) ( $j['offset'] ?? 0 ) );
		$phases  = max( 1, count( $formats ) );
		$overall = ( ( $index * $total ) + $offset ) / ( $phases * $total );
		return (int) min( 99, max( 0, floor( 100 * $overall ) ) );
	}

	/**
	 * Same ratio as conversion_progress_percent(), kept to two decimals for the bar label.
	 *
	 * @param array $j J.
	 *
	 * @return float
	 */
	public static function conversion_progress_exact( array $j ): float {
		if ( ( $j['status'] ?? '' ) === 'done' ) {
			return 100.0;
		}
		$formats = self::job_formats( $j );
		$total   = (int) ( $j['total'] ?? 0 );
		if ( $total < 1 ) {
			return 0.0;
		}
		$index   = (int) ( $j['format_index'] ?? 0 );
		$offset  = min( $total, (int) ( $j['offset'] ?? 0 ) );
		$phases  = max( 1, count( $formats ) );
		$overall = ( ( $index * $total ) + $offset ) / ( $phases * $total );
		return round( min( 99.99, max( 0.0, 100 * $overall ) ), 2 );
	}

	/**
	 * Conversion progress label.
	 *
	 * @param array $j J.
	 *
	 * @return string
	 */
	public static function conversion_progress_label( array $j ): string {
		$formats      = self::job_formats( $j );
		$index        = (int) ( $j['format_index'] ?? 0 );
		$current      = $formats[ $index ] ?? '';
		$offset       = (int) ( $j['offset'] ?? 0 );
		$total        = (int) ( $j['total'] ?? 0 );
		$phases       = max( 1, count( $formats ) );
		$webp         = (int) ( $j['made_webp'] ?? 0 );
		$avif         = (int) ( $j['made_avif'] ?? 0 );
		$made         = max( (int) ( $j['made'] ?? 0 ), $webp + $avif );
		$skipped      = (int) ( $j['skipped'] ?? 0 );
		$larger_total = (int) ( $j['larger_webp'] ?? 0 ) + (int) ( $j['larger_avif'] ?? 0 );
		$status       = (string) ( $j['status'] ?? '' );
		$counts       = array();
		$larger       = static function ( $n ): string {
			return (int) $n > 0 ? ' ' . sprintf( /* translators: %s: number of converted files that were not smaller and were removed. */ __( '(%s not smaller, removed)', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $n ) ) : '';
		};
		if ( in_array( 'webp', $formats, true ) || $webp > 0 ) {
			$counts[] = sprintf( /* translators: %s: number of WebP files. */ __( 'WebP %s created', 'lucidlab-mpc-cleanup' ), number_format_i18n( $webp ) ) . $larger( $j['larger_webp'] ?? 0 );
		}
		if ( in_array( 'avif', $formats, true ) || $avif > 0 ) {
			$counts[] = sprintf( /* translators: %s: number of AVIF files. */ __( 'AVIF %s created', 'lucidlab-mpc-cleanup' ), number_format_i18n( $avif ) ) . $larger( $j['larger_avif'] ?? 0 );
		}
		if ( ! $counts && $made > 0 ) {
			$counts[] = sprintf( /* translators: %s: number of copies. */ __( '%s copies created', 'lucidlab-mpc-cleanup' ), number_format_i18n( $made ) );
		}
		if ( $skipped > 0 && in_array( $status, array( 'paused', 'cancelled', 'done' ), true ) ) {
			$counts[] = sprintf( /* translators: %s: number of skipped images. */ __( '%s skipped', 'lucidlab-mpc-cleanup' ), number_format_i18n( $skipped ) );
		}
		if ( $larger_total > 0 && ! $webp && ! $avif ) {
			/* translators: %d: copies that are not smaller than their source files. */
			$counts[] = sprintf( __( '%d copies not smaller than their source', 'lucidlab-mpc-cleanup' ), $larger_total );
		}
		$count_text = implode( ' · ', $counts );
		$originals  = (int) ( $j['originals'] ?? 0 );
		if ( $originals > 0 ) {
			$count_text = trim( $count_text . ' · ' . sprintf( /* translators: %s: number of original images. */ __( '%s originals quarantined', 'lucidlab-mpc-cleanup' ), number_format_i18n( $originals ) ) );
		}
		if ( 'idle' === $status || '' === $status ) {
			return '';
		}
		if ( 'failed' === $status ) {
			$errors = (array) ( $j['errors'] ?? array() );
			$last   = end( $errors );
			return ! empty( $last['message'] ) ? (string) $last['message'] : __( 'Conversion failed.', 'lucidlab-mpc-cleanup' );
		}
		if ( 'running' === $status && ! empty( $j['discovery'] ) && empty( $j['discovery']['done'] ) ) {
			return sprintf(
				/* translators: %s: attachment records checked so far */
				__( 'Finding images to convert — %s attachment records checked', 'lucidlab-mpc-cleanup' ),
				number_format_i18n( (int) ( $j['discovery']['attachment_rows'] ?? 0 ) )
			);
		}
		if ( 'paused' === $status ) {
			return $count_text ? ( 'Stopped — ' . $count_text ) : __( 'Stopped', 'lucidlab-mpc-cleanup' );
		}
		if ( 'cancelled' === $status ) {
			return $count_text ? ( 'Cancelled — ' . $count_text ) : __( 'Cancelled', 'lucidlab-mpc-cleanup' );
		}
		if ( 'done' === $status ) {
			if ( $total < 1 && $made < 1 && $skipped < 1 ) {
				// Library rows are often the WebP copy (webp/….jpg.webp). Conversion
				// encodes the JPEG/PNG next to it. An empty list means that original
				// is missing, or Skip existing found the copy already on disk.
				return ! empty( $j['skip_existing'] )
					? __( 'Finished — nothing to generate. Skip existing is on, and every selected WebP or AVIF copy is already on disk. Uncheck Skip existing to recreate them from the JPEG or PNG originals.', 'lucidlab-mpc-cleanup' )
					: __( 'Finished — nothing to generate. No JPEG or PNG original was found on disk for the selected format. Files already stored as WebP or AVIF are left as they are unless the matching JPEG or PNG is still in uploads.', 'lucidlab-mpc-cleanup' );
			}
			return $count_text ? ( 'Finished — ' . $count_text ) : __( 'Finished', 'lucidlab-mpc-cleanup' );
		}
		// Match the progress bar: count image×format units across every selected format.
		$units_done  = min( $phases * max( 0, $total ), ( $index * max( 0, $total ) ) + max( 0, $offset ) );
		$units_total = max( 1, $phases * max( 0, $total ) );
		if ( 'webp' === $current || 'avif' === $current ) {
			$label = sprintf(
				/* translators: 1: format name, 2: completed image×format units, 3: total units */
				__( 'Converting to %1$s — %2$s/%3$s images', 'lucidlab-mpc-cleanup' ),
				'avif' === $current ? 'AVIF' : 'WebP',
				number_format_i18n( $units_done ),
				number_format_i18n( $units_total )
			);
			return $label . ( ! empty( $j['current_file'] ) ? ' · ' . (string) $j['current_file'] : '' );
		}
		$label = sprintf( 'Converting — %s/%s images', number_format_i18n( $units_done ), number_format_i18n( $units_total ) );
		return $label . ( ! empty( $j['current_file'] ) ? ' · ' . (string) $j['current_file'] : '' );
	}

	/**
	 * Destination.
	 *
	 * @param string $source Source.
	 * @param string $format Format.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return string
	 */
	public function destination( string $source, string $format ): string {
		$base   = rtrim( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) . '/';
		$source = wp_normalize_path( $source );
		if ( ! in_array( $format, array( 'webp', 'avif' ), true ) || strpos( $source, $base ) !== 0 || strpos( $source, '../' ) !== false ) {
			throw new RuntimeException( __( 'Invalid conversion path.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		// New copies live in uploads/lucidlab-mpc-cleanup/{webp,avif}/ and mirror the upload path of the original.
		return LucidLab_MPC_Storage::dir( $format, false ) . '/' . substr( $source, strlen( $base ) ) . '.' . $format;
	}

	/**
	 * Where versions by older versions put the folder copy (uploads/webp/…, uploads/avif/…). Read-only: it is never written.
	 *
	 * @param string $source Source.
	 * @param string $format Format.
	 *
	 * @return string
	 */
	public function legacy_destination( string $source, string $format ): string {
		$base   = rtrim( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) . '/';
		$source = wp_normalize_path( $source );
		if ( ! in_array( $format, array( 'webp', 'avif' ), true ) || strpos( $source, $base ) !== 0 || strpos( $source, '../' ) !== false ) {
			return '';
		}
		return $base . $format . '/' . substr( $source, strlen( $base ) ) . '.' . $format;
	}
	/**
	 * Larger option.
	 *
	 * @param string $format Format.
	 *
	 * @return string
	 */
	public static function larger_option( string $format ): string {
		return 'avif' === $format ? self::LARGER_AVIF : self::LARGER_WEBP;
	}

	/**
	 * The file visitors get when this copy is missing: for AVIF the WebP copy when there is one (Apache and picture markup fall back to it), else the original. Empty when nothing smaller can be served.
	 *
	 * @param string $source Source.
	 * @param string $format Format.
	 *
	 * @return array{0:string,1:string} Label ('webp'|'original'|'') and path.
	 */
	public function served_without( string $source, string $format ): array {
		$webp = 'avif' === $format ? $this->existing_copy( $source, 'webp' ) : '';
		if ( $webp ) {
			return array( 'webp', $webp );
		}
		return is_file( $source ) ? array( 'original', $source ) : array( '', '' );
	}

	/**
	 * Remove a copy that is not smaller than the file served without it, and remember why.
	 *
	 * @param string $format  Format.
	 * @param string $dest    Dest.
	 * @param string $source  Source.
	 * @param int    $quality Quality.
	 *
	 * @return bool
	 */
	public function drop_larger_copy( string $format, string $dest, string $source, int $quality ): bool {
		list( $vs, $path ) = $this->served_without( $source, $format );
		$size              = '' === $vs ? 0 : (int) ( is_file( $path ) ? filesize( $path ) : false );
		if ( $size < 1 || (int) ( is_file( $dest ) ? filesize( $dest ) : false ) < $size || ! LucidLab_MPC_Helpers::delete_file( $dest ) ) {
			return false;
		}
		$key = $this->larger_copy_key( $source );
		if ( '' !== $key ) {
			$list         = (array) get_option( self::larger_option( $format ), array() );
			$list[ $key ] = array(
				'q'  => $quality,
				'vs' => $vs,
			);
			update_option( self::larger_option( $format ), $list, false );
		}
		return true;
	}

	/**
	 * Which existing copies of one JPEG/PNG are not smaller than the file served without them. WebP is compared with the original; AVIF with the WebP copy that stays, else the original.
	 *
	 * @param string $source Source.
	 *
	 * @return array<string,array{copies:string[],bytes:int,vs:string}> Keyed by format.
	 */
	public function larger_copies( string $source ): array {
		$original = (int) ( is_file( $source ) ? filesize( $source ) : false );
		if ( $original < 1 ) {
			return array();
		}
		$out       = array();
		$kept_webp = 0;
		foreach ( array( 'webp', 'avif' ) as $format ) {
			$copies = array();
			foreach ( array_filter( array( $this->destination( $source, $format ), $this->legacy_destination( $source, $format ), $source . '.' . $format ) ) as $candidate ) {
				if ( LucidLab_MPC_Helpers::valid_nextgen_file( $candidate, $format ) ) {
					$copies[] = $candidate;
				}
			}
			if ( ! $copies ) {
				continue;
			}
			$size = (int) filesize( $copies[0] );
			$vs   = 'avif' === $format && $kept_webp ? 'webp' : 'original';
			if ( $size < ( 'webp' === $vs ? $kept_webp : $original ) ) {
				if ( 'webp' === $format ) {
					$kept_webp = $size;
				}
				continue;
			}
			$out[ $format ] = array(
				'copies' => $copies,
				'bytes'  => (int) array_sum( array_map( 'filesize', $copies ) ),
				'vs'     => $vs,
			);
		}
		return $out;
	}

	/**
	 * One time-limited pass over the library for copies that are not smaller than the file served without them. Only counts unless $remove is true.
	 *
	 * @param array $state  State.
	 * @param bool  $remove Remove.
	 * @param float $budget Budget.
	 *
	 * @return array
	 */
	public function prune_larger_step( array $state, bool $remove, float $budget = 2.0 ): array {
		global $wpdb;
		$state   += array(
			'cursor'  => 0,
			'checked' => 0,
			'webp'    => 0,
			'avif'    => 0,
			'bytes'   => 0,
			'failed'  => 0,
			'done'    => false,
			'total'   => $this->total_images(),
		);
		$deadline = microtime( true ) + $budget;
		$quality  = (array) ( $this->job()['quality'] ?? array() );
		$lists    = array();
		$base     = rtrim( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) . '/';
		while ( ! $state['done'] && microtime( true ) < $deadline ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID ASC LIMIT 50", $wpdb->esc_like( 'image/' ) . '%', (int) $state['cursor'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
			if ( ! $ids ) {
				$state['done'] = true;
				break;
			}
			foreach ( $ids as $id ) {
				if ( microtime( true ) >= $deadline ) {
					break 2;
				}
				$meta   = wp_get_attachment_metadata( $id );
				$thumbs = array_flip( array_filter( array_column( (array) ( $meta['sizes'] ?? array() ), 'file' ) ) );
				foreach ( array_unique( $this->source_files( $id ) ) as $abs ) {
					$abs = wp_normalize_path( $abs );
					if ( ! preg_match( '/\.(jpe?g|jpe|png)$/i', $abs ) || strpos( $abs, $base ) !== 0 || ! is_file( $abs ) ) {
						continue;
					}
					$is_thumb = isset( $thumbs[ basename( $abs ) ] );
					foreach ( $this->larger_copies( $abs ) as $format => $found ) {
						if ( $remove ) {
							$gone = 0;
							foreach ( $found['copies'] as $copy ) {
								$gone += LucidLab_MPC_Helpers::delete_file( $copy ) ? 1 : 0;
							}
							if ( $gone < count( $found['copies'] ) ) {
								++$state['failed'];
								continue;
							}
							$lists[ $format ] = $lists[ $format ] ?? (array) get_option( self::larger_option( $format ), array() );
							$q                = LucidLab_MPC_Helpers::clamp_image_quality( $quality[ $format ][ $is_thumb ? 'thumbnails' : 'images' ] ?? 0, $format, LucidLab_MPC_Helpers::default_quality( $format, $is_thumb ) );
							$lists[ $format ][ substr( $abs, strlen( $base ) ) ] = array(
								'q'  => $q,
								'vs' => $found['vs'],
							);
						}
						++$state[ $format ];
						$state['bytes'] += $found['bytes'];
					}
				}
				$state['cursor'] = $id;
				++$state['checked'];
			}
		}
		foreach ( $lists as $format => $list ) {
			update_option( self::larger_option( $format ), $list, false );
		}
		return $state;
	}

	/**
	 * A removed larger copy is not encoded again unless the quality is lowered or the smaller file is gone.
	 *
	 * @param string $format  Format.
	 * @param string $source  Source.
	 * @param int    $quality Quality.
	 *
	 * @return bool
	 */
	private function larger_copy_holds( string $format, string $source, int $quality ): bool {
		$entry = $this->larger_copy_entry( $format, $source );
		if ( ! $entry || $quality < (int) ( $entry['q'] ?? 0 ) ) {
			return false;
		}
		return 'webp' === ( $entry['vs'] ?? '' ) ? '' !== $this->existing_copy( $source, 'webp' ) : is_file( $source );
	}

	/**
	 * Larger copy entry.
	 *
	 * @param string $format Format.
	 * @param string $source Source.
	 *
	 * @return array
	 */
	private function larger_copy_entry( string $format, string $source ): array {
		$key  = $this->larger_copy_key( $source );
		$list = '' === $key ? array() : (array) get_option( self::larger_option( $format ), array() );
		return is_array( $list[ $key ] ?? null ) ? $list[ $key ] : array();
	}

	/**
	 * Larger copy key.
	 *
	 * @param string $source Source.
	 *
	 * @return string
	 */
	private function larger_copy_key( string $source ): string {
		$base   = rtrim( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) . '/';
		$source = wp_normalize_path( $source );
		return strpos( $source, $base ) === 0 ? substr( $source, strlen( $base ) ) : '';
	}

	/**
	 * Existing copy.
	 *
	 * @param string $source Source.
	 * @param string $format Format.
	 *
	 * @return string
	 */
	private function existing_copy( string $source, string $format ): string {
		foreach ( array_filter( array( $this->destination( $source, $format ), $this->legacy_destination( $source, $format ) ) ) as $dest ) {
			if ( LucidLab_MPC_Helpers::valid_nextgen_file( $dest, $format ) ) {
				return $dest;
			}
		}
		$sidecar = $source . '.' . $format;
		return LucidLab_MPC_Helpers::valid_nextgen_file( $sidecar, $format ) ? $sidecar : '';
	}
	/**
	 * Job.
	 *
	 * @return array
	 */
	public function job(): array {
		$defaults            = array(
			'status'       => 'idle',
			'driver'       => 'ajax',
			'offset'       => 0,
			'total'        => 0,
			'made'         => 0,
			'made_webp'    => 0,
			'made_avif'    => 0,
			'skipped'      => 0,
			'failed'       => 0,
			'formats'      => array(),
			'format_index' => 0,
		);
		$j                   = get_option( self::JOB_OPTION, $defaults );
		$j                   = is_array( $j ) ? array_merge( $defaults, $j ) : $defaults;
		$j['made']           = max( (int) $j['made'], (int) $j['made_webp'] + (int) $j['made_avif'] );
		$j['progress']       = self::conversion_progress_exact( $j );
		$j['progress_exact'] = $j['progress'];
		$j['label']          = self::conversion_progress_label( $j );
		$j['message']        = $j['label'];
		return $j;
	}

	/**
	 * Record history.
	 *
	 * @param array $j J.
	 */
	private function record_history( array $j ): void {
		$status = (string) ( $j['status'] ?? '' );
		if ( ! in_array( $status, array( 'done', 'failed', 'cancelled' ), true ) ) {
			return;
		}
		$label = self::conversion_progress_label( $j );
		$entry = array(
			'key'        => 'lucidlab_mpc_webp_job',
			'label'      => $label,
			'status'     => $status,
			'error'      => 'failed' === $status ? $label : '',
			'updated'    => (int) ( $j['updated'] ?? time() ),
			'failed'     => (int) ( $j['failed'] ?? 0 ),
			'failed_ids' => array_slice( array_map( 'intval', (array) ( $j['failed_ids'] ?? array() ) ), 0, 200 ),
			'panel'      => 'webp',
			'scan_tab'   => 'webp',
			'can_retry'  => in_array( $status, array( 'done', 'failed' ), true ) && (int) ( $j['failed'] ?? 0 ) > 0,
			'special'    => 'webp',
		);
		// Repeated no-work starts are the same outcome, so keep one recent history row.
		if ( 'done' === $status && (int) ( $j['total'] ?? 0 ) === 0 ) {
			$history = (array) get_option( 'lucidlab_mpc_job_history', array() );
			$latest  = is_array( $history[0] ?? null ) ? $history[0] : array();
			if ( ( $latest['key'] ?? '' ) === 'lucidlab_mpc_webp_job' && ( $latest['status'] ?? '' ) === 'done' && ( $latest['label'] ?? '' ) === $label && (int) ( $latest['updated'] ?? 0 ) >= time() - 600 ) {
				return;
			}
		}
		if ( method_exists( 'LucidLab_MPC_Helpers', 'prepend_job_history' ) ) {
			LucidLab_MPC_Helpers::prepend_job_history( $entry );
			return;
		}
		$history = (array) get_option( 'lucidlab_mpc_job_history', array() );
		array_unshift( $history, $entry );
		update_option( 'lucidlab_mpc_job_history', array_slice( $history, 0, 50 ), false );
	}

	/**
	 * Persist job.
	 *
	 * @param array       $j            J.
	 * @param string|null $prior_status Prior status.
	 *
	 * @return array
	 */
	private function persist_job( array $j, ?string $prior_status = null ): array {
		$j['made']           = max( (int) ( $j['made'] ?? 0 ), (int) ( $j['made_webp'] ?? 0 ) + (int) ( $j['made_avif'] ?? 0 ) );
		$j['progress']       = self::conversion_progress_exact( $j );
		$j['progress_exact'] = $j['progress'];
		$j['label']          = self::conversion_progress_label( $j );
		$j['message']        = $j['label'];
		if ( null === $prior_status ) {
			$previous     = get_option( self::JOB_OPTION, array() );
			$prior_status = is_array( $previous ) ? (string) ( $previous['status'] ?? '' ) : '';
		}
		update_option( self::JOB_OPTION, $j, false );
		$status = (string) ( $j['status'] ?? '' );
		if ( in_array( $status, array( 'done', 'failed', 'cancelled' ), true ) && in_array( $prior_status, array( 'running', 'paused' ), true ) && $status !== $prior_status ) {
			$profile = get_option( 'lucidlab_mpc_webp_discovery_profile', array() );
			if ( is_array( $profile ) ) {
				$profile['terminal'] = array(
					'status'      => $status,
					'at'          => (int) ( $j['updated'] ?? time() ),
					'made'        => (int) ( $j['made'] ?? 0 ),
					'skipped'     => (int) ( $j['skipped'] ?? 0 ),
					'failed'      => (int) ( $j['failed'] ?? 0 ),
					'larger_webp' => (int) ( $j['larger_webp'] ?? 0 ),
					'larger_avif' => (int) ( $j['larger_avif'] ?? 0 ),
				);
				update_option( 'lucidlab_mpc_webp_discovery_profile', $profile, false );
			}
			$this->record_history( $j );
			if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
				$verb   = 'cancelled' === $status ? __( 'cancelled', 'lucidlab-mpc-cleanup' ) : ( 'failed' === $status ? __( 'failed', 'lucidlab-mpc-cleanup' ) : __( 'finished', 'lucidlab-mpc-cleanup' ) );
				$detail = ( (int) ( $j['total'] ?? 0 ) < 1 && (int) ( $j['made'] ?? 0 ) < 1 )
					? (string) ( $j['label'] ?? '' )
					/* translators: 1: generated copies; 2: skipped copies; 3: failed copies. */
					: sprintf( __( '%1$d copies generated, %2$d skipped, %3$d failed.', 'lucidlab-mpc-cleanup' ), (int) ( $j['made'] ?? 0 ), (int) ( $j['skipped'] ?? 0 ), (int) ( $j['failed'] ?? 0 ) );
				/* translators: 1: conversion status; 2: conversion summary. */
				LucidLab_MPC_Logger::log( sprintf( __( 'WebP / AVIF %1$s: %2$s', 'lucidlab-mpc-cleanup' ), $verb, $detail ) );
			}
		}
		return $j;
	}

	/**
	 * Job action.
	 *
	 * @param string $action Action.
	 * @param array  $input  Input.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public function job_action( string $action, array $input = array() ): array {
		global $wpdb;
		$lock = 'lucidlab_mpc_webp_' . md5( $wpdb->prefix );
		if ( in_array( $action, array( 'managed_release', 'cancel' ), true ) ) {
			update_option( self::STOP_OPTION, $action, false );
		}
		$wait = in_array( $action, array( 'managed_release', 'cancel' ), true ) ? 8 : 0;
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, $wait ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
			return array_merge( $this->job(), array( 'busy' => true ) ); }
		try {
			wp_cache_delete( self::JOB_OPTION, 'options' );
			$previous     = get_option( self::JOB_OPTION, array() );
			$prior_status = is_array( $previous ) ? (string) ( $previous['status'] ?? '' ) : '';
			$j            = $this->job();
			if ( ! LucidLab_MPC_Edition::pro() ) {
				if ( 'start' === $action && ( ! empty( $input['avif']['enabled'] ) || ! empty( $input['replace_originals'] ) ) ) {
					throw new RuntimeException( __( 'AVIF conversion and original-image quarantine require the Pro add-on.', 'lucidlab-mpc-cleanup' ) );
				}
				$pro_job = in_array( 'avif', (array) ( $j['formats'] ?? array() ), true ) || ! empty( $j['replace_originals'] ) || 'run_all' === ( $j['driver'] ?? '' );
				if ( $pro_job && in_array( $action, array( 'step', 'cron', 'managed_step', 'resume', 'background', 'retry' ), true ) ) {
					$j['status']  = 'paused';
					$j['label']   = __( 'Paused: this conversion requires an active Pro license.', 'lucidlab-mpc-cleanup' );
					$j['updated'] = time();
					wp_clear_scheduled_hook( self::CRON_HOOK );
					return $this->persist_job( $j, $prior_status );
				}
				if ( 'retry' === $action && in_array( 'avif', (array) ( $j['formats'] ?? array() ), true ) ) {
					throw new RuntimeException( __( 'AVIF conversion requires the Pro add-on.', 'lucidlab-mpc-cleanup' ) );
				}
			}
			if ( $this->apply_stop_request( $j ) && in_array( $action, array( 'status', 'managed_release', 'cancel', 'managed_step', 'step', 'cron' ), true ) ) {
				$j['updated']       = time();
				$j['updated_label'] = LucidLab_MPC_Helpers::job_datetime( (int) $j['updated'] );
				return $this->persist_job( $j, $prior_status );
			}
			if ( 'cancel' !== $action && $this->recover_job( $j, time() ) ) {
				$j            = $this->persist_job( $j, $prior_status );
				$prior_status = (string) ( $j['status'] ?? '' );
			}
			if ( 'recover' === $action ) {
				return $j;
			}
			if ( ( $j['driver'] ?? '' ) === 'run_all' && ! in_array( $action, array( 'status', 'managed_step', 'managed_start', 'managed_release' ), true ) ) {
				throw new RuntimeException( __( 'This conversion is controlled by Run All. Use its controls.', 'lucidlab-mpc-cleanup' ) );
			}
			if ( 'managed_start' === $action && 'run_all' === ( $j['driver'] ?? '' ) && ( $j['owner'] ?? '' ) !== ( $input['owner'] ?? '' ) ) {
				throw new RuntimeException( __( 'Another Run All job owns this worker.', 'lucidlab-mpc-cleanup' ) );
			}
			if ( 'managed_release' === $action ) {
				$j = $this->detach_from_run_all( $j, true );
				delete_option( self::STOP_OPTION );
				return $this->persist_job( $j, $prior_status );
			}
			$managed = 'managed_start' === $action;
			if ( $managed && in_array( $j['status'], array( 'running', 'paused' ), true ) ) {
				throw new RuntimeException( __( 'A conversion is already pending. Finish or cancel it before Run All.', 'lucidlab-mpc-cleanup' ) );
			}
			if ( $managed ) {
				$action = 'start';
			}
			if ( 'managed_step' === $action && ( ( $j['owner'] ?? '' ) !== ( $input['owner'] ?? '' ) || 'run_all' !== $j['driver'] ) ) {
				throw new RuntimeException( __( 'Conversion ownership changed.', 'lucidlab-mpc-cleanup' ) );
			}
			if ( 'start' === $action ) {
				if ( 'running' === $j['status'] ) {
					throw new RuntimeException( __( 'A conversion is already running.', 'lucidlab-mpc-cleanup' ) ); }
				delete_option( self::STOP_OPTION );
				if ( ! $managed && class_exists( 'LucidLab_MPC_Plugin', false ) ) {
					LucidLab_MPC_Plugin::assert_job_slot( 'webp' );
				}
				if ( class_exists( 'LucidLab_MPC_Settings', false ) ) {
					LucidLab_MPC_Settings::save_page_webp( $input );
				}
				$caps    = $this->caps();
				$formats = array();
				$quality = array();
				foreach ( array( 'webp', 'avif' ) as $fmt ) {
					if ( ! empty( $input[ $fmt ]['enabled'] ) && ! empty( $caps[ $fmt ] ) ) {
						$formats[]               = $fmt;
						$quality[ $fmt ]['mode'] = ( $input[ $fmt ]['mode'] ?? 'all' ) === 'featured' ? 'featured' : 'all';
						foreach ( array( 'images', 'thumbnails' ) as $kind ) {
							$quality[ $fmt ][ $kind ] = LucidLab_MPC_Helpers::clamp_image_quality( $input[ $fmt ][ $kind ] ?? 0, $fmt, LucidLab_MPC_Helpers::default_quality( $fmt, 'thumbnails' === $kind ) );
						}
					}
				}
				if ( ! $formats ) {
					throw new RuntimeException( __( 'Select a supported format.', 'lucidlab-mpc-cleanup' ) ); }
				$skip_existing = ! isset( $input['skip_existing'] ) || ! empty( $input['skip_existing'] );
				// Discovery now runs in bounded job ticks, rather than in this start request.
				// Keep the existing items/total queue fields for conversion and retries.
				$j = array(
					'status'        => 'running',
					'driver'        => ! empty( $input['background'] ) ? 'cron' : 'ajax',
					'formats'       => $formats,
					'quality'       => $quality,
					'format_index'  => 0,
					'offset'        => 0,
					'total'         => 0,
					'indeterminate' => true,
					'current_file'  => '',
					'items'         => array(),
					'discovery'     => array(
						'cursor_id'          => 0,
						'cursor_meta_id'     => 0,
						'attachment_rows'    => 0,
						'started_at'         => microtime( true ),
						'seconds'            => 0.0,
						'processing_seconds' => 0.0,
						'queries'            => 0,
						'peak_mb'            => 0.0,
						'done'               => false,
					),
					'made'          => 0,
					'made_webp'     => 0,
					'made_avif'     => 0,
					'bytes'         => 0,
					'skipped'       => 0,
					'skipped_webp'  => 0,
					'skipped_avif'  => 0,
					'failed'        => 0,
					'failed_ids'    => array(),
					'errors'        => array(),
					'originals'     => 0,
					'skip_existing' => $skip_existing ? 1 : 0,
				);
				if ( ! empty( $input['replace_originals'] ) ) {
					$j['replace_originals'] = 1;
					$j['original_stamp']    = ( new LucidLab_MPC_Quarantine() )->new_stamp();
				}
			} elseif ( 'retry' === $action ) {
				$failed = LucidLab_MPC_Helpers::retry_ids( $j, (array) ( $j['errors'] ?? array() ) );
				if ( ! $failed ) {
					throw new RuntimeException( __( 'No failed images are available to retry.', 'lucidlab-mpc-cleanup' ) );
				}
				$j['status']       = 'running';
				$j['driver']       = 'ajax';
				$j['format_index'] = 0;
				$j['offset']       = 0;
				$j['total']        = count( $failed );
				$j['items']        = $failed;
				$j['current_file'] = '';
				$j['made']         = 0;
				$j['made_webp']    = 0;
				$j['made_avif']    = 0;
				$j['skipped']      = 0;
				$j['skipped_webp'] = 0;
				$j['skipped_avif'] = 0;
				$j['larger_webp']  = 0;
				$j['larger_avif']  = 0;
				$j['failed']       = 0;
				$j['failed_ids']   = array();
				$j['errors']       = array();
				$j['retry_ids']    = $failed;
			} elseif ( 'cancel' === $action ) {
				$j['status'] = 'cancelled';
				$j['driver'] = 'ajax';
				unset( $j['owner'] );
				wp_clear_scheduled_hook( self::CRON_HOOK );
				delete_option( self::STOP_OPTION );
			} elseif ( 'stop' === $action && 'running' === $j['status'] ) {
				$j['status'] = 'paused';
				wp_clear_scheduled_hook( self::CRON_HOOK );
			} elseif ( in_array( $action, array( 'resume', 'background' ), true ) && in_array( $j['status'], array( 'running', 'paused' ), true ) ) {
				$j['status'] = 'running';
				$j['driver'] = 'background' === $action ? 'cron' : 'ajax';
			} elseif ( ( ( 'step' === $action && 'ajax' === $j['driver'] ) || ( 'cron' === $action && 'cron' === $j['driver'] ) || ( 'managed_step' === $action && 'run_all' === $j['driver'] ) ) && 'running' === $j['status'] ) {
				$resource = LucidLab_MPC_Helpers::resource_error();
				$stalled  = false;
				if ( ! $resource && ! empty( $j['inflight'] ) ) {
					// The previous step started but never saved: its PHP worker died (fatal error, timeout or memory).
					$j['interrupts'] = (int) ( $j['interrupts'] ?? 0 ) + 1;
					if ( $j['interrupts'] >= self::MAX_INTERRUPTS ) {
						$where = '' !== (string) ( $j['current_file'] ?? '' ) ? (string) $j['current_file'] : __( 'finding images to convert', 'lucidlab-mpc-cleanup' );
						/* translators: 1: number of interrupted steps; 2: file name or phase. */
						$this->mark_stalled( $j, sprintf( __( 'Stopped: the conversion worker ended without saving %1$d times in a row (at: %2$s). This is usually a PHP fatal error, timeout or memory limit; check the PHP error log, then start the conversion again.', 'lucidlab-mpc-cleanup' ), (int) $j['interrupts'], $where ) );
						$stalled = true;
					}
				}
				if ( ! $stalled && ! $resource ) {
					// Mark the step as started; a worker that dies before saving leaves this behind.
					$j['inflight'] = time();
					update_option( self::JOB_OPTION, $j, false );
				}
				if ( $stalled ) {
					$resource = null;
				} elseif ( $resource ) {
					$j = LucidLab_MPC_Helpers::pause_for_resource( $j, $resource );} elseif ( ! empty( $j['discovery'] ) && empty( $j['discovery']['done'] ) ) {
					try {
						$this->discover_work_step( $j );
					} catch ( Throwable $error ) {
						$j['status']        = 'failed';
						$j['indeterminate'] = false;
						$j['failed']        = (int) ( $j['failed'] ?? 0 ) + 1;
						$j['errors'][]      = array(
							'id'      => 0,
							'file'    => '',
							'format'  => '',
							'message' => $error->getMessage(),
						);
					}
					} else {
						$deadline = microtime( true ) + 1.5;
						$guard    = 0;
						do {
							$formats = self::job_formats( $j );
							$current = $formats[ (int) ( $j['format_index'] ?? 0 ) ] ?? '';
							// Prebuilt work lists are small; process several attachments per tick.
							$batch = 1;
							$n     = $this->convert_step( 'all', (int) $j['offset'], $batch, $current ? array( $current ) : $formats, $j['quality'], $j );
							$j     = self::advance_format_progress( $j, $n );
							if ( $this->apply_stop_request( $j ) ) {
								$j['updated'] = time();
								break;
							}
							++$guard;
						} while ( $guard < 5 && ( $j['status'] ?? '' ) === 'running' && $n > 0 && microtime( true ) < $deadline );
					}
					if ( ! $stalled ) {
						unset( $j['inflight'] );
						$j['interrupts'] = 0;
					}
			}
			if ( $managed ) {
				$j['driver'] = 'run_all';
				$j['owner']  = $input['owner'];
				wp_clear_scheduled_hook( self::CRON_HOOK );}
			if ( 'status' !== $action ) {
				$j['updated']       = time();
				$j['updated_label'] = LucidLab_MPC_Helpers::job_datetime( (int) $j['updated'] ); }
			// Fresh start/retry must not inherit a prior terminal status for history gating.
			// Immediate done (nothing to convert) still counts as a finished run.
			if ( in_array( $action, array( 'start', 'retry' ), true ) || $managed ) {
				$prior_status = ( ( $j['status'] ?? '' ) === 'done' ) ? 'running' : 'idle';
			}
			return $this->persist_job( $j, $prior_status );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
			$this->schedule(); }
	}

	/**
	 * Apply stop request.
	 *
	 * @param array &$j J.
	 *
	 * @return bool
	 */
	private function apply_stop_request( array &$j ): bool {
		$stop = (string) get_option( self::STOP_OPTION, '' );
		if ( '' === $stop ) {
			return false;
		}
		$active = in_array( (string) ( $j['status'] ?? '' ), array( 'running', 'paused' ), true );
		if ( ! $active ) {
			delete_option( self::STOP_OPTION );
			return false;
		}
		$j['status'] = 'cancelled';
		$j['driver'] = 'ajax';
		unset( $j['owner'] );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::STOP_OPTION );
		return true;
	}

	/**
	 * Detach from run all.
	 *
	 * @param array $j                J.
	 * @param bool  $cancel_if_active Cancel if active.
	 *
	 * @return array
	 */
	private function detach_from_run_all( array $j, bool $cancel_if_active ): array {
		if ( ( $j['driver'] ?? '' ) !== 'run_all' ) {
			return $j;
		}
		$j['driver'] = 'ajax';
		unset( $j['owner'] );
		if ( $cancel_if_active && in_array( (string) ( $j['status'] ?? '' ), array( 'running', 'paused' ), true ) ) {
			$j['status'] = 'cancelled';
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		$j['label']    = self::conversion_progress_label( $j );
		$j['message']  = $j['label'];
		$j['progress'] = self::conversion_progress_percent( $j );
		return $j;
	}
	/**
	 * Recover a conversion whose worker is gone, so its saved "running" state cannot hold the job slot forever.
	 *
	 * Called from the job list, the job-slot check (Run All and Automation) and the daily cron. Cheap when the
	 * job is idle or recently saved.
	 */
	public function recover_stale(): void {
		$j = get_option( self::JOB_OPTION, array() );
		if ( ! is_array( $j ) || 'running' !== ( $j['status'] ?? '' ) ) {
			return;
		}
		$last = max( (int) ( $j['updated'] ?? 0 ), (int) ( $j['recovered_at'] ?? 0 ) );
		if ( $last > 0 && time() - $last < self::STALE_AFTER ) {
			return;
		}
		$this->job_action( 'recover' );
	}

	/**
	 * Recover job. A browser-driven job whose page was closed continues in the background, a background job
	 * whose cron event was lost is rescheduled, a job owned by a Run All that no longer exists is released,
	 * and any job without progress for DEAD_AFTER seconds is marked failed.
	 *
	 * @param array $j   Job (changed in place).
	 * @param int   $now Current time.
	 *
	 * @return bool Whether the job changed.
	 */
	public function recover_job( array &$j, int $now ): bool {
		if ( 'running' !== ( $j['status'] ?? '' ) ) {
			return false;
		}
		$updated = (int) ( $j['updated'] ?? 0 );
		if ( $updated < 1 ) {
			// Older saved jobs without a timestamp: start the clock now.
			$j['updated'] = $now;
			return true;
		}
		if ( $now - max( $updated, (int) ( $j['recovered_at'] ?? 0 ) ) < self::STALE_AFTER ) {
			return false;
		}
		$since = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::job_datetime( $updated ) : gmdate( 'Y-m-d H:i', $updated );
		$hours = (int) round( self::DEAD_AFTER / 3600 );
		if ( $now - $updated >= self::DEAD_AFTER ) {
			/* translators: 1: date and time of the last saved progress; 2: number of hours. */
			$this->mark_stalled( $j, sprintf( __( 'Stopped: no progress was saved since %1$s (more than %2$d hours), so the job was released. Start the conversion again to continue.', 'lucidlab-mpc-cleanup' ), $since, $hours ) );
			return true;
		}
		$driver = (string) ( $j['driver'] ?? 'ajax' );
		if ( 'run_all' === $driver ) {
			if ( self::run_all_owner_active( (string) ( $j['owner'] ?? '' ) ) ) {
				$j['recovered_at'] = $now;
				return true;
			}
			/* translators: %s: date and time of the last saved progress. */
			$this->mark_stalled( $j, sprintf( __( 'Stopped: the Run All job that controlled this conversion is no longer running (last progress %s), so the job was released.', 'lucidlab-mpc-cleanup' ), $since ) );
			return true;
		}
		// The page that drove this job was closed, or its cron event was lost after a PHP fatal error:
		// continue in the background (the next cron run picks it up; schedule() adds the event).
		if ( 'cron' !== $driver ) {
			$j['driver'] = 'cron';
		} elseif ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( $now + 2, self::CRON_HOOK );
		}
		$j['recovered_at'] = $now;
		$j['recoveries']   = (int) ( $j['recoveries'] ?? 0 ) + 1;
		return true;
	}

	/**
	 * Whether a Run All job with this owner token is still running, paused or queued.
	 *
	 * @param string $token Owner token (user ID, colon, unique ID).
	 *
	 * @return bool
	 */
	public static function run_all_owner_active( string $token ): bool {
		global $wpdb;
		$user = (int) strtok( $token, ':' );
		if ( '' === $token || $user < 1 ) {
			return false;
		}
		$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s", $wpdb->esc_like( 'lucidlab_mpc_task_' . $user . '_' ) . '%', '%' . $wpdb->esc_like( $token ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Saved task jobs are options with dynamic names; this liveness check must read the live table.
		foreach ( $rows as $value ) {
			$job = maybe_unserialize( $value );
			if ( is_array( $job ) && ( $job['owner_token'] ?? '' ) === $token && in_array( $job['status'] ?? '', array( 'running', 'paused', 'queued' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mark a dead conversion failed and release it.
	 *
	 * @param array  $j       Job (changed in place).
	 * @param string $message Reason shown in Current Running Jobs, Finished Jobs and the log.
	 */
	private function mark_stalled( array &$j, string $message ): void {
		$j['status']        = 'failed';
		$j['indeterminate'] = false;
		$j['driver']        = 'ajax';
		$j['errors']        = (array) ( $j['errors'] ?? array() );
		$j['errors'][]      = array(
			'id'      => 0,
			'file'    => (string) ( $j['current_file'] ?? '' ),
			'format'  => '',
			'message' => $message,
		);
		$j['updated']       = time();
		$j['updated_label'] = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::job_datetime( (int) $j['updated'] ) : '';
		unset( $j['owner'], $j['inflight'] );
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Schedule.
	 */
	private function schedule(): void {
		$j = $this->job();
		if ( 'running' === $j['status'] && 'cron' === $j['driver'] && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 2, self::CRON_HOOK ); }
	}
	/**
	 * Cron tick.
	 */
	public function cron_tick(): void {
		try {
			$this->job_action( 'cron' );
			LucidLab_MPC_Helpers::mark_cron_ok();
		} finally {
			$this->schedule(); } }

	/**
	 * Conversion error.
	 *
	 * @param array  &$acc     Acc.
	 * @param int    $id      Id.
	 * @param string $file    File.
	 * @param string $format  Format.
	 * @param string $message Message.
	 */
	private function conversion_error( array &$acc, int $id, string $file, string $format, string $message ): void {
		$acc['failed']     = (int) ( $acc['failed'] ?? 0 ) + 1;
		$acc['failed_ids'] = LucidLab_MPC_Helpers::int_ids( array_merge( (array) ( $acc['failed_ids'] ?? array() ), array( $id ) ) );
		$acc['errors'][]   = array(
			'id'      => $id,
			'file'    => basename( $file ),
			'format'  => $format,
			'message' => $message,
			'edit'    => get_edit_post_link( $id, 'raw' ),
			'thumb'   => wp_get_attachment_image_url( $id, 'thumbnail' ),
		);
		$acc['errors']     = array_slice( $acc['errors'], -100 );
	}
	/**
	 * Server capabilities via the active WP image editor.
	 *
	 * @return array
	 */
	public function caps(): array {
		return class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::nextgen_caps() : array(
			'driver' => 'none',
			'webp'   => false,
			'avif'   => false,
		);
	}

	/**
	 * Total images.
	 *
	 * @return int
	 */
	public function total_images(): int {
		$index = class_exists( 'LucidLab_MPC_Index' ) ? LucidLab_MPC_Index::get() : array();
		if ( ! empty( $index['count'] ) ) {
			return (int) $index['count']; }
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
	}

	/**
	 * Attachment IDs that still have convertible files on disk (and, when skipping existing copies, at least one WebP/AVIF destination that is missing). Avoids walking every media-library row when originals were deleted.
	 *
	 * @param string[] $formats       Formats.
	 * @param array    $quality       Quality.
	 * @param bool     $skip_existing Skip existing.
	 *
	 * @return int[]
	 * @throws RuntimeException When the operation fails.
	 */
	public function collect_work_ids( array $formats, array $quality, bool $skip_existing ): array {
		global $wpdb;
		$started        = microtime( true );
		$queries_before = (int) $wpdb->num_queries;
		$formats        = array_values( array_intersect( $formats, array( 'webp', 'avif' ) ) );
		if ( ! $formats ) {
			return array();
		}
		$out             = array();
		$attachment_rows = 0;
		$cursor_id       = 0;
		$cursor_meta_id  = 0;
		$limit           = 500;
		do {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
				$wpdb->prepare(
					"SELECT p.ID, m.meta_id, m.meta_value AS file
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
				 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s AND m.meta_value <> ''
				 AND (p.ID > %d OR (p.ID = %d AND m.meta_id > %d))
				 ORDER BY p.ID ASC, m.meta_id ASC LIMIT %d",
					'image/%',
					$cursor_id,
					$cursor_id,
					$cursor_meta_id,
					$limit
				)
			);
			if ( $wpdb->last_error ) {
				throw new RuntimeException( __( 'Could not read image attachments for conversion. No conversion job was started.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$count            = count( (array) $rows );
			$attachment_rows += $count;
			if ( $count ) {
				update_meta_cache( 'post', array_values( array_unique( array_map( static fn( $row ) => (int) $row->ID, $rows ) ) ) );
			}
			foreach ( (array) $rows as $row ) {
				$id             = (int) $row->ID;
				$cursor_id      = $id;
				$cursor_meta_id = (int) $row->meta_id;
				$rel            = ltrim( str_replace( '\\', '/', (string) $row->file ), '/' );
				if ( '' === $rel || strpos( $rel, '../' ) !== false ) {
					continue;
				}
				// The primary file may have been quarantined while a listed size or
				// next-gen library file remains. Inspect the actual source set below.
				if ( $this->attachment_needs_conversion( $id, $formats, $quality, $skip_existing ) ) {
					$out[] = $id;
				}
			}
		} while ( $count === $limit );
		update_option(
			'lucidlab_mpc_webp_discovery_profile',
			array(
				'at'              => time(),
				'attachment_rows' => $attachment_rows,
				'work_ids'        => count( $out ),
				'seconds'         => round( microtime( true ) - $started, 3 ),
				'queries'         => max( 0, (int) $wpdb->num_queries - $queries_before ),
				'peak_mb'         => round( memory_get_peak_usage( true ) / 1048576, 1 ),
				'formats'         => $formats,
				'skip_existing'   => $skip_existing,
			),
			false
		);
		return $out;
	}

	/**
	 * Discover one bounded batch and persist its keyset cursor with the job.
	 *
	 * The start request no longer inspects the full media library. Each tick
	 * resumes after the last processed postmeta row, including duplicate rows
	 * for an attachment, without changing the conversion queue's items format.
	 *
	 * @param array &$job Job.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	private function discover_work_step( array &$job ): void {
		global $wpdb;
		$started        = microtime( true );
		$queries_before = (int) $wpdb->num_queries;
		$state          = (array) $job['discovery'];
		$cursor_id      = (int) ( $state['cursor_id'] ?? 0 );
		$cursor_meta_id = (int) ( $state['cursor_meta_id'] ?? 0 );
		$limit          = 75;
		$rows           = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
			$wpdb->prepare(
				"SELECT p.ID, m.meta_id, m.meta_value AS file
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s AND m.meta_value <> ''
			 AND (p.ID > %d OR (p.ID = %d AND m.meta_id > %d))
			 ORDER BY p.ID ASC, m.meta_id ASC LIMIT %d",
				'image/%',
				$cursor_id,
				$cursor_id,
				$cursor_meta_id,
				$limit
			)
		);
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not read image attachments for conversion. No conversion job was started.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$rows = (array) $rows;
		if ( $rows ) {
			update_meta_cache( 'post', array_values( array_unique( array_map( static fn( $row ) => (int) $row->ID, $rows ) ) ) );
		}
		$processed = 0;
		foreach ( $rows as $row ) {
			$id                      = (int) $row->ID;
			$state['cursor_id']      = $id;
			$state['cursor_meta_id'] = (int) $row->meta_id;
			++$processed;
			++$state['attachment_rows'];
			$rel = ltrim( str_replace( '\\', '/', (string) $row->file ), '/' );
			if ( '' !== $rel && false === strpos( $rel, '../' ) && $this->attachment_needs_conversion( $id, self::job_formats( $job ), (array) $job['quality'], ! empty( $job['skip_existing'] ) ) ) {
				$job['items'][] = $id;
			}
			if ( microtime( true ) - $started >= 1.0 ) {
				break;
			}
		}
		$finished                    = microtime( true );
		$state['started_at']         = (float) ( $state['started_at'] ?? ( $started - (float) ( $state['seconds'] ?? 0 ) ) );
		$state['processing_seconds'] = (float) ( $state['processing_seconds'] ?? 0 ) + $finished - $started;
		$state['seconds']            = max( 0.0, $finished - $state['started_at'] );
		$state['queries']            = (int) ( $state['queries'] ?? 0 ) + max( 0, (int) $wpdb->num_queries - $queries_before );
		$state['peak_mb']            = max( (float) ( $state['peak_mb'] ?? 0 ), round( memory_get_peak_usage( true ) / 1048576, 1 ) );
		$state['done']               = count( $rows ) === $processed && count( $rows ) < $limit;
		$job['discovery']            = $state;
		update_option(
			'lucidlab_mpc_webp_discovery_profile',
			array(
				'at'                 => time(),
				'attachment_rows'    => (int) $state['attachment_rows'],
				'work_ids'           => count( $job['items'] ),
				'seconds'            => round( $state['seconds'], 3 ),
				'processing_seconds' => round( $state['processing_seconds'], 3 ),
				'queries'            => (int) $state['queries'],
				'peak_mb'            => (float) $state['peak_mb'],
				'formats'            => self::job_formats( $job ),
				'skip_existing'      => ! empty( $job['skip_existing'] ),
			),
			false
		);
		if ( $state['done'] ) {
			$job['total']         = count( $job['items'] );
			$job['indeterminate'] = false;
			if ( 0 === $job['total'] ) {
				$job['status'] = 'done';
			}
		}
	}

	/**
	 * Estimate peak encoder memory before loading source pixels into GD/Imagick.
	 *
	 * @param string $source Source.
	 * @param string $format Format.
	 *
	 * @return bool
	 */
	private function source_exceeds_memory_budget( string $source, string $format ): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return false;
		}
		$size = wp_getimagesize( $source );
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return false;
		}
		$pixels   = (float) $size[0] * (float) $size[1];
		$estimate = $pixels * ( 'avif' === $format ? 16 : 12 ) + 32 * 1048576;
		return memory_get_usage( true ) + $estimate > $limit * 0.85;
	}

	/**
	 * True when at least one on-disk source still needs a selected format.
	 *
	 * @param int   $id            Id.
	 * @param array $formats       Formats.
	 * @param array $quality       Quality.
	 * @param bool  $skip_existing Skip existing.
	 *
	 * @return bool
	 */
	private function attachment_needs_conversion( int $id, array $formats, array $quality, bool $skip_existing ): bool {
		$featured_ok = array();
		foreach ( $formats as $fmt ) {
			if ( ( $quality[ $fmt ]['mode'] ?? 'all' ) !== 'featured' ) {
				$featured_ok[ $fmt ] = true;
				continue;
			}
			global $wpdb;
			$featured_ok[ $fmt ] = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' AND meta_value=%s LIMIT 1", (string) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
		}
		if ( ! array_filter( $featured_ok ) ) {
			return false;
		}
		$meta   = wp_get_attachment_metadata( $id );
		$thumbs = array();
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$thumbs[ (string) $size['file'] ] = true;
			}
		}
		foreach ( $this->source_files( $id ) as $abs ) {
			if ( ! is_file( $abs ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, array( 'svg', 'gif' ), true ) ) {
				continue;
			}
			$dest_source = $abs;
			if ( in_array( $ext, array( 'webp', 'avif' ), true ) ) {
				$logical = $this->logical_original_path( $abs );
				if ( '' === $logical ) {
					continue;
				}
				$dest_source = $logical;
			} elseif ( ! in_array( $ext, array( 'jpg', 'jpeg', 'jpe', 'png' ), true ) ) {
				continue;
			}
			if ( ! is_file( $dest_source ) ) {
				continue;
			}
			$is_thumb   = isset( $thumbs[ basename( $dest_source ) ] );
			$encode_ext = strtolower( pathinfo( $dest_source, PATHINFO_EXTENSION ) );
			foreach ( $formats as $fmt ) {
				// Compare with the JPEG/PNG original, not the WebP/AVIF library file that points at it.
				if ( $fmt === $encode_ext ) {
					continue;
				}
				if ( empty( $featured_ok[ $fmt ] ) ) {
					continue;
				}
				$q = LucidLab_MPC_Helpers::clamp_image_quality( $quality[ $fmt ][ $is_thumb ? 'thumbnails' : 'images' ] ?? 0, $fmt, LucidLab_MPC_Helpers::default_quality( $fmt, $is_thumb ) );
				try {
					$dest = $this->destination( $dest_source, $fmt );
				} catch ( Throwable $e ) {
					continue;
				}
				if ( $skip_existing && ( file_exists( $dest ) || '' !== $this->existing_copy( $dest_source, $fmt ) || $this->larger_copy_holds( $fmt, $dest_source, $q ) ) ) {
					continue;
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * Count targets.
	 *
	 * @param string $mode Mode.
	 *
	 * @return int
	 */
	public function count_targets( string $mode ): int {
		global $wpdb;
		if ( 'featured' === $mode ) {
			return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
				"SELECT COUNT(DISTINCT pm.meta_value) FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} a ON a.ID = pm.meta_value
				 WHERE pm.meta_key='_thumbnail_id' AND a.post_type='attachment' AND a.post_mime_type LIKE 'image/%'"
			);
		}
		return $this->total_images();
	}

	/**
	 * Target ids.
	 *
	 * @param string $mode     Mode.
	 * @param int    $offset   Offset.
	 * @param int    $limit    Limit.
	 * @param array  $only     Only.
	 * @param int    $after_id After id.
	 *
	 * @return array
	 */
	private function target_ids( string $mode, int $offset, int $limit, array $only = array(), int $after_id = 0 ): array {
		if ( $only ) {
			return array_slice( LucidLab_MPC_Helpers::int_ids( $only ), $offset, $limit );
		}
		global $wpdb;
		if ( 'featured' === $mode ) {
			return array_map(
				'intval',
				(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
					$wpdb->prepare(
						"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
						 JOIN {$wpdb->posts} a ON a.ID = pm.meta_value
						 WHERE pm.meta_key='_thumbnail_id' AND a.post_type='attachment' AND a.post_mime_type LIKE %s
						 AND CAST(pm.meta_value AS UNSIGNED) > %d
						 ORDER BY CAST(pm.meta_value AS UNSIGNED) ASC LIMIT %d",
						$wpdb->esc_like( 'image/' ) . '%',
						$after_id,
						$limit
					)
				)
			);
		}
		return array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d",
					$wpdb->esc_like( 'image/' ) . '%',
					$after_id,
					$limit
				)
			)
		);
	}

	/** Absolute source files for an attachment (original + sizes + big-image original). */
	/**
	 * Uploads path of the JPEG/PNG that a WebP or AVIF copy was made from.
	 *
	 * @param string $abs Abs.
	 *
	 * @return string
	 */
	private function logical_original_path( string $abs ): string {
		$base = rtrim( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) . '/';
		$abs  = wp_normalize_path( $abs );
		if ( strpos( $abs, $base ) !== 0 ) {
			return '';
		}
		$rel = substr( $abs, strlen( $base ) );
		if ( ! preg_match( '#^(?:webp|avif)/(.+)\.(?:webp|avif)$#i', $rel, $match ) ) {
			return '';
		}
		$logical = $base . $match[1];
		return strpos( $logical, '../' ) === false ? $logical : '';
	}

	/**
	 * Source files.
	 *
	 * @param int $id Id.
	 *
	 * @return array
	 */
	private function source_files( int $id ): array {
		$out  = array();
		$file = get_attached_file( $id );
		if ( ! $file ) {
			return $out;
		}
		$out[] = $file;
		$dir   = trailingslashit( dirname( $file ) );
		$meta  = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $s ) {
					if ( ! empty( $s['file'] ) ) {
						$out[] = $dir . $s['file'];
					}
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$out[] = $dir . $meta['original_image'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Convert one batch. $formats subset of ['webp','avif']. Accumulates into $acc. Returns the number of attachments processed (for stepping).
	 *
	 * @param string $mode    Mode.
	 * @param int    $offset  Offset.
	 * @param int    $limit   Limit.
	 * @param array  $formats Formats.
	 * @param array  $quality Quality.
	 * @param array  &$acc     Acc.
	 *
	 * @return int
	 */
	public function convert_step( string $mode, int $offset, int $limit, array $formats, array $quality, array &$acc ): int {
		$caps    = $this->caps();
		$formats = array_values( array_intersect( $formats, array( 'webp', 'avif' ) ) );
		$formats = array_filter( $formats, static fn( $f ) => ! empty( $caps[ $f ] ) );

		$only = LucidLab_MPC_Helpers::int_ids( $acc['retry_ids'] ?? array() );
		if ( ! $only ) {
			$only = LucidLab_MPC_Helpers::int_ids( $acc['items'] ?? array() );
		}
		if ( ! $only && ! array_key_exists( 'cursor', $acc ) ) {
			$acc['cursor'] = 0;
			if ( $offset > 0 ) {
				global $wpdb;
				$acc['cursor'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE %s ORDER BY ID ASC LIMIT 1 OFFSET %d",
						$wpdb->esc_like( 'image/' ) . '%',
						$offset - 1
					)
				);
			}
		}
		$ids = $this->target_ids( $mode, $offset, $limit, $only, (int) ( $acc['cursor'] ?? 0 ) );
		if ( $ids && ! $only ) {
			$acc['cursor'] = max( $ids );
		}
		foreach ( $ids as $id ) {
			$acc['current_file'] = wp_basename( (string) get_attached_file( $id ) );
			$counted             = (int) ( $acc['made'] ?? 0 ) + (int) ( $acc['skipped'] ?? 0 ) + (int) ( $acc['failed'] ?? 0 ) + (int) ( $acc['larger_webp'] ?? 0 ) + (int) ( $acc['larger_avif'] ?? 0 );
			foreach ( $this->source_files( $id ) as $abs ) {
				if ( ! is_file( $abs ) ) {
					continue;
				}
				$ext = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );
				if ( in_array( $ext, array( 'svg', 'gif' ), true ) ) {
					continue;
				}
				$encode_from = $abs;
				$dest_source = $abs;
				if ( in_array( $ext, array( 'webp', 'avif' ), true ) ) {
					$logical = $this->logical_original_path( $abs );
					if ( '' === $logical ) {
						continue;
					}
					$dest_source = $logical;
					if ( is_file( $logical ) ) {
						$encode_from = $logical;
					} else {
						$base = rtrim( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) . '/';
						$held = ( new LucidLab_MPC_Quarantine() )->find_original( substr( $logical, strlen( $base ) ) );
						if ( '' !== $held ) {
							$encode_from = $held;
						}
						// Original gone: encode AVIF from WebP (and WebP from AVIF) instead of skipping.
					}
				}
				$acc['current_file'] = wp_basename( $encode_from );
				foreach ( $formats as $fmt ) {
					// Do not re-encode a file into the format it already is.
					// A library .webp is converted from its JPEG/PNG original, so compare that extension.
					$encode_ext = strtolower( pathinfo( $encode_from, PATHINFO_EXTENSION ) );
					if ( $fmt === $encode_ext ) {
						continue;
					}
					if ( ( $quality[ $fmt ]['mode'] ?? 'all' ) === 'featured' ) {
						global $wpdb;
						if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' AND meta_value=%s LIMIT 1", (string) $id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
							continue; }
					}
					if ( ! is_file( $encode_from ) ) {
						continue;
					}
					$dest = $this->destination( $dest_source, $fmt );
					LucidLab_MPC_Storage::dir( $fmt );
					wp_mkdir_p( dirname( $dest ) );
					LucidLab_MPC_Helpers::drop_invalid_nextgen( $dest, $fmt );
					$meta     = wp_get_attachment_metadata( $id );
					$is_thumb = false;
					foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
						if ( basename( $dest_source ) === ( $size['file'] ?? '' ) ) {
							$is_thumb = true;
							break; }
					}
					$q             = LucidLab_MPC_Helpers::clamp_image_quality( $quality[ $fmt ][ $is_thumb ? 'thumbnails' : 'images' ] ?? 0, $fmt, LucidLab_MPC_Helpers::default_quality( $fmt, $is_thumb ) );
					$skip_existing = ! isset( $acc['skip_existing'] ) || ! empty( $acc['skip_existing'] );
					if ( file_exists( $dest ) || '' !== $this->existing_copy( $dest_source, $fmt ) ) {
						if ( $skip_existing ) {
							$acc['skipped']           = (int) ( $acc['skipped'] ?? 0 ) + 1;
							$acc[ 'skipped_' . $fmt ] = (int) ( $acc[ 'skipped_' . $fmt ] ?? 0 ) + 1;
							continue;
						}
					}
					if ( $skip_existing && $this->larger_copy_holds( $fmt, $dest_source, $q ) ) {
						$acc['skipped']           = (int) ( $acc['skipped'] ?? 0 ) + 1;
						$acc[ 'skipped_' . $fmt ] = (int) ( $acc[ 'skipped_' . $fmt ] ?? 0 ) + 1;
						continue;
					}
					if ( $this->source_exceeds_memory_budget( $encode_from, $fmt ) ) {
						$this->conversion_error( $acc, $id, $abs, $fmt, __( 'Image needs more memory than this server can safely provide for conversion. The original and any existing copy were kept.', 'lucidlab-mpc-cleanup' ) );
						continue;
					}
					// Encode beside the published copy so an encoder failure cannot remove it.
					$temp  = $dest . '.lucidlab-mpc-new-' . wp_generate_password( 12, false, false ) . '.' . $fmt;
					$saved = LucidLab_MPC_Helpers::encode_nextgen( $encode_from, $temp, $fmt, $q );
					if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( $saved['path'] ) ) {
						wp_delete_file( $temp );
						$this->conversion_error( $acc, $id, $abs, $fmt, is_wp_error( $saved ) ? $saved->get_error_message() : __( 'Converted file was not created on disk.', 'lucidlab-mpc-cleanup' ) );
						continue;
					}
					// Editors may append their own extension; normalise to the staged path.
					if ( $saved['path'] !== $temp ) {
						if ( ! rename( $saved['path'], $temp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
							$this->conversion_error( $acc, $id, $abs, $fmt, __( 'Unable to move converted file to its destination.', 'lucidlab-mpc-cleanup' ) );
							continue; }
					}
					if ( ! LucidLab_MPC_Helpers::valid_nextgen_file( $temp, $fmt ) ) {
						LucidLab_MPC_Helpers::drop_invalid_nextgen( $temp, $fmt );
						$this->conversion_error( $acc, $id, $abs, $fmt, __( 'The converted file was empty and was removed. The original is kept.', 'lucidlab-mpc-cleanup' ) );
						continue;
					}
					if ( $this->drop_larger_copy( $fmt, $temp, $dest_source, $q ) ) {
						$acc[ 'larger_' . $fmt ] = (int) ( $acc[ 'larger_' . $fmt ] ?? 0 ) + 1;
						continue;
					}
					// Rename is atomic where supported. On failure, keep the old copy and report it.
					if ( ! rename( $temp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
						wp_delete_file( $temp );
						$this->conversion_error( $acc, $id, $abs, $fmt, __( 'Unable to move converted file to its destination.', 'lucidlab-mpc-cleanup' ) );
						continue;
					}
					$acc['made']           = (int) ( $acc['made'] ?? 0 ) + 1;
					$acc[ 'made_' . $fmt ] = (int) ( $acc[ 'made_' . $fmt ] ?? 0 ) + 1;
					$acc['bytes']          = (int) ( $acc['bytes'] ?? 0 ) + (int) ( is_file( $dest ) ? filesize( $dest ) : 0 );
				}
			}
			$this->maybe_quarantine_originals( $id, $formats, $acc );
			$now = (int) ( $acc['made'] ?? 0 ) + (int) ( $acc['skipped'] ?? 0 ) + (int) ( $acc['failed'] ?? 0 ) + (int) ( $acc['larger_webp'] ?? 0 ) + (int) ( $acc['larger_avif'] ?? 0 );
			if ( $now === $counted ) {
				$acc['skipped'] = (int) ( $acc['skipped'] ?? 0 ) + 1;
			}
		}
		return count( $ids );
	}

	/**
	 * Move a converted JPEG/PNG set into quarantine only after every selected copy exists. The attachment is then pointed at WebP, or at AVIF when WebP was not selected.
	 *
	 * @param int   $id      Id.
	 * @param array $formats Formats.
	 * @param array &$acc     Acc.
	 */
	private function maybe_quarantine_originals( int $id, array $formats, array &$acc ): void {
		if ( empty( $acc['replace_originals'] ) ) {
			return;
		}
		$selected = self::job_formats( $acc );
		$current  = $formats[0] ?? '';
		$replace  = in_array( 'webp', $selected, true ) ? 'webp' : ( in_array( 'avif', $selected, true ) ? 'avif' : '' );
		if ( ! $selected || ! $replace || $current !== $selected[ count( $selected ) - 1 ] ) {
			return;
		}
		$main = get_attached_file( $id );
		if ( ! $main || ! is_file( $main ) ) {
			return;
		}
		$ready = array();
		$need  = 0;
		foreach ( $this->source_files( $id ) as $abs ) {
			if ( ! is_file( $abs ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, array( 'webp', 'avif', 'svg', 'gif' ), true ) ) {
				continue;
			}
			++$need;
			$copies = array();
			foreach ( $selected as $fmt ) {
				$copy = $this->existing_copy( $abs, $fmt );
				if ( ! $copy && 'avif' === $fmt && isset( $copies['webp'] ) && 'webp' === ( $this->larger_copy_entry( 'avif', $abs )['vs'] ?? '' ) ) {
					continue;
				}
				if ( ! $copy || ! LucidLab_MPC_Helpers::valid_nextgen_file( $copy, $fmt ) ) {
					$copies = array();
					break;
				}
				$copies[ $fmt ] = $copy;
			}
			if ( $copies ) {
				$ready[] = array(
					'abs'    => $abs,
					'copies' => $copies,
				);
			}
		}
		if ( ! $need || count( $ready ) !== $need ) {
			return;
		}
		// The copy becomes the Media Library file, so it moves out of the plugin folder and next to the original.
		$ready = $this->adopt_library_copies( $ready, $replace );
		if ( ! $ready ) {
			return;
		}
		$base = rtrim( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) . '/';
		$rels = array();
		foreach ( $ready as $row ) {
			$abs = wp_normalize_path( $row['abs'] );
			if ( strpos( $abs, $base ) !== 0 ) {
				return;
			}
			$rels[] = substr( $abs, strlen( $base ) );
		}
		$stamp = (string) ( $acc['original_stamp'] ?? '' );
		if ( '' === $stamp ) {
			$stamp                 = ( new LucidLab_MPC_Quarantine() )->new_stamp();
			$acc['original_stamp'] = $stamp;
		}
		$moved_bytes = 0;
		foreach ( $ready as $row ) {
			$moved_bytes += is_file( $row['abs'] ) ? (int) filesize( $row['abs'] ) : 0;
		}
		$result = ( new LucidLab_MPC_Quarantine() )->quarantine_files( $rels, $stamp, 'originals' );
		if ( empty( $result['ok'] ) || ! empty( $result['fail'] ) || is_file( $main ) ) {
			return;
		}
		( new LucidLab_MPC_Quarantine() )->remember_originals_attachment( $stamp, $id, $rels );
		$this->retarget_attachment( $id, $main, $ready, $replace, $base );
		$acc['originals'] = (int) ( $acc['originals'] ?? 0 ) + (int) $result['ok'];
		self::reduce_original_stats( (int) $result['ok'], $moved_bytes );
	}

	/**
	 * Move the copies that will become Media Library files from uploads/lucidlab-mpc-cleanup/ to sit beside their originals
	 * (2024/05/a.jpg → 2024/05/a.jpg.webp), the normal media location. All or nothing: on any failure the moves are undone
	 * and an empty array is returned, so the originals stay where they are.
	 *
	 * @param array  $ready   Rows with abs and copies.
	 * @param string $replace Format that replaces the original.
	 *
	 * @return array
	 */
	private function adopt_library_copies( array $ready, string $replace ): array {
		$plugin = LucidLab_MPC_Storage::base() . '/';
		$moved  = array();
		foreach ( $ready as $i => $row ) {
			$copy = wp_normalize_path( (string) ( $row['copies'][ $replace ] ?? '' ) );
			if ( '' === $copy || ! str_starts_with( $copy, $plugin ) ) {
				continue;
			}
			$beside = wp_normalize_path( (string) $row['abs'] ) . '.' . $replace;
			if ( file_exists( $beside ) || ! @rename( $copy, $beside ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Same-volume move inside uploads; failure is handled below.
				foreach ( $moved as $pair ) {
					@rename( $pair[1], $pair[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Undo of the move above.
				}
				return array();
			}
			$moved[]                           = array( $copy, $beside );
			$ready[ $i ]['copies'][ $replace ] = $beside;
		}
		return $ready;
	}

	/**
	 * Point Media Library records back at restored JPEG/PNG originals after a quarantine restore.
	 *
	 * @param string[] $rels           Relative upload paths that were just restored.
	 * @param int[]    $attachment_ids Optional attachment IDs stored with the originals set.
	 *
	 * @return int
	 */
	public function retarget_attachments_to_originals( array $rels, array $attachment_ids = array() ): int {
		$base = rtrim( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) . '/';
		$done = array();
		$ids  = array_values( array_unique( array_filter( array_map( 'intval', $attachment_ids ) ) ) );
		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );
			if ( ! $file ) {
				continue;
			}
			$ext      = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			$original = '';
			if ( in_array( $ext, array( 'webp', 'avif' ), true ) ) {
				$original = $this->logical_original_path( $file );
			}
			if ( '' === $original || ! is_file( $original ) ) {
				// Prefer a restored main file that matches this attachment's basename stem.
				$stem = pathinfo( (string) get_post_meta( $id, '_wp_attached_file', true ), PATHINFO_FILENAME );
				$stem = preg_replace( '/\.(webp|avif)$/i', '', (string) $stem );
				foreach ( $rels as $rel ) {
					$rel = ltrim( wp_normalize_path( (string) $rel ), '/' );
					if ( '' === $rel || strpos( $rel, '../' ) !== false ) {
						continue;
					}
					$candidate = $base . $rel;
					if ( ! is_file( $candidate ) ) {
						continue;
					}
					$cext = strtolower( pathinfo( $candidate, PATHINFO_EXTENSION ) );
					if ( in_array( $cext, array( 'webp', 'avif', 'svg', 'gif' ), true ) ) {
						continue;
					}
					if ( $stem && pathinfo( $rel, PATHINFO_FILENAME ) === $stem && $this->retarget_attachment_to_original( $id, $candidate ) ) {
						$done[ $id ] = true;
						break;
					}
				}
				continue;
			}
			if ( $this->retarget_attachment_to_original( $id, $original ) ) {
				$done[ $id ] = true;
			}
		}
		// Discover attachments still pointed at next-gen copies of restored originals.
		global $wpdb;
		foreach ( $rels as $rel ) {
			$rel = ltrim( wp_normalize_path( (string) $rel ), '/' );
			if ( '' === $rel || strpos( $rel, '../' ) !== false ) {
				continue;
			}
			$original = $base . $rel;
			if ( ! is_file( $original ) ) {
				continue;
			}
			$cext = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
			if ( in_array( $cext, array( 'webp', 'avif', 'svg', 'gif' ), true ) ) {
				continue;
			}
			foreach ( array( 'webp', 'avif' ) as $fmt ) {
				$nextgen_rels   = array_map(
					static function ( string $prefix ) use ( $rel, $fmt ): string {
						return $prefix . $rel . '.' . $fmt;
					},
					LucidLab_MPC_Helpers::nextgen_prefixes( $fmt )
				);
				$nextgen_rels[] = $rel . '.' . $fmt;
				foreach ( $nextgen_rels as $nextgen_rel ) {
					$id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
						$wpdb->prepare(
							"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value=%s LIMIT 1",
							$nextgen_rel
						)
					);
					if ( $id < 1 || isset( $done[ $id ] ) ) {
						continue;
					}
					if ( $this->retarget_attachment_to_original( $id, $original ) ) {
						$done[ $id ] = true;
					}
				}
			}
		}
		return count( $done );
	}

	/**
	 * Reverse retarget_attachment(): point an attachment at a restored JPEG/PNG original.
	 *
	 * @param int    $id           Id.
	 * @param string $original_abs Original abs.
	 *
	 * @return bool
	 */
	public function retarget_attachment_to_original( int $id, string $original_abs ): bool {
		$base         = rtrim( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) . '/';
		$original_abs = wp_normalize_path( $original_abs );
		if ( $id < 1 || ! is_file( $original_abs ) || strpos( $original_abs, $base ) !== 0 ) {
			return false;
		}
		$ext  = strtolower( pathinfo( $original_abs, PATHINFO_EXTENSION ) );
		$mime = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'jpe'  => 'image/jpeg',
			'png'  => 'image/png',
		)[ $ext ] ?? '';
		if ( '' === $mime ) {
			return false;
		}
		$current = get_attached_file( $id );
		$current = $current ? wp_normalize_path( $current ) : '';
		$old_dir = $current ? rtrim( dirname( $current ), '/' ) : '';
		$new_dir = rtrim( dirname( $original_abs ), '/' );
		update_attached_file( $id, $original_abs );
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$meta['file'] = substr( $original_abs, strlen( $base ) );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) && $old_dir ) {
			foreach ( $meta['sizes'] as $name => $size ) {
				$basename = (string) ( $size['file'] ?? '' );
				foreach ( array( 'webp', 'avif' ) as $fmt ) {
					$suffix = '.' . $fmt;
					if ( $basename && substr( $basename, -strlen( $suffix ) ) === $suffix ) {
						$basename = substr( $basename, 0, -strlen( $suffix ) );
						break;
					}
				}
				$candidate = $new_dir . '/' . $basename;
				if ( $basename && is_file( $candidate ) ) {
					$meta['sizes'][ $name ]['file']      = $basename;
					$meta['sizes'][ $name ]['mime-type'] = $mime;
				}
			}
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$oi = (string) $meta['original_image'];
			foreach ( array( 'webp', 'avif' ) as $fmt ) {
				$suffix = '.' . $fmt;
				if ( substr( $oi, -strlen( $suffix ) ) === $suffix ) {
					$oi = substr( $oi, 0, -strlen( $suffix ) );
					break;
				}
			}
			if ( $oi && is_file( $new_dir . '/' . $oi ) ) {
				$meta['original_image'] = $oi;
			} else {
				unset( $meta['original_image'] );
			}
		}
		wp_update_attachment_metadata( $id, $meta );
		wp_update_post(
			array(
				'ID'             => $id,
				'post_mime_type' => $mime,
			)
		);
		return true;
	}

	/**
	 * Retarget attachment.
	 *
	 * @param int    $id      Id.
	 * @param string $main    Main.
	 * @param array  $ready   Ready.
	 * @param string $replace Replace.
	 * @param string $base    Base.
	 */
	private function retarget_attachment( int $id, string $main, array $ready, string $replace, string $base ): void {
		$by_abs = array();
		foreach ( $ready as $row ) {
			$by_abs[ wp_normalize_path( $row['abs'] ) ] = wp_normalize_path( $row['copies'][ $replace ] );
		}
		$new_main = $by_abs[ wp_normalize_path( $main ) ] ?? '';
		if ( '' === $new_main || strpos( $new_main, $base ) !== 0 || ! is_file( $new_main ) ) {
			return;
		}
		update_attached_file( $id, $new_main );
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$meta['file'] = substr( $new_main, strlen( $base ) );
		$new_dir      = rtrim( dirname( $new_main ), '/' );
		$old_dir      = rtrim( wp_normalize_path( dirname( $main ) ), '/' );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $size ) {
				$old = $old_dir . '/' . ( $size['file'] ?? '' );
				$new = $by_abs[ wp_normalize_path( $old ) ] ?? '';
				if ( $new && rtrim( dirname( $new ), '/' ) === $new_dir ) {
					$meta['sizes'][ $name ]['file']      = basename( $new );
					$meta['sizes'][ $name ]['mime-type'] = 'image/' . $replace;
				}
			}
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$old = $old_dir . '/' . $meta['original_image'];
			$new = $by_abs[ wp_normalize_path( $old ) ] ?? '';
			if ( $new && rtrim( dirname( $new ), '/' ) === $new_dir ) {
				$meta['original_image'] = basename( $new );
			} else {
				unset( $meta['original_image'] );
			}
		}
		wp_update_attachment_metadata( $id, $meta );
		wp_update_post(
			array(
				'ID'             => $id,
				'post_mime_type' => 'image/' . $replace,
			)
		);
	}

	/**
	 * Accumulate library + WebP/AVIF coverage totals for one batch of attachments. $acc keys: orig_count, orig_bytes, webp_count, webp_bytes, webp_saved, avif_count, avif_bytes, avif_saved. Every physical file (original + every generated size + the pre-scaled original) is counted once.
	 *
	 * @param int   $offset Offset.
	 * @param int   $limit  Limit.
	 * @param array &$acc    Acc.
	 *
	 * @return int
	 */
	public function stats_step( int $offset, int $limit, array &$acc ): int {
		global $wpdb;
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Copies are replaced atomically on the local filesystem. Batch reads use $wpdb and are not cached across requests.
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE %s ORDER BY ID ASC LIMIT %d OFFSET %d",
					$wpdb->esc_like( 'image/' ) . '%',
					$limit,
					$offset
				)
			)
		);
		foreach ( $ids as $id ) {
			foreach ( $this->source_files( $id ) as $abs ) {
				if ( ! is_file( $abs ) ) {
					continue;
				}
				$ext = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );
				if ( in_array( $ext, array( 'webp', 'avif' ), true ) ) {
					continue; // These live in the DB as .webp/.avif "originals"; don't double count.
				}
				$size              = (int) filesize( $abs );
				$acc['orig_count'] = (int) ( $acc['orig_count'] ?? 0 ) + 1;
				$acc['orig_bytes'] = (int) ( $acc['orig_bytes'] ?? 0 ) + $size;

				$webp = $this->existing_copy( $abs, 'webp' );
				$avif = $this->existing_copy( $abs, 'avif' );
				if ( is_file( $webp ) && is_file( $avif ) ) {
					$acc['avif_vs_webp'] = (int) ( $acc['avif_vs_webp'] ?? 0 ) + filesize( $webp ) - filesize( $avif ); }
				foreach ( array( 'webp', 'avif' ) as $fmt ) {
					$dest = $this->existing_copy( $abs, $fmt );
					if ( ! is_file( $dest ) ) {
						continue;
					}
					$dsize                  = (int) filesize( $dest );
					$acc[ $fmt . '_count' ] = (int) ( $acc[ $fmt . '_count' ] ?? 0 ) + 1;
					$acc[ $fmt . '_bytes' ] = (int) ( $acc[ $fmt . '_bytes' ] ?? 0 ) + $dsize;
					$acc[ $fmt . '_saved' ] = (int) ( $acc[ $fmt . '_saved' ] ?? 0 ) + ( $size - $dsize );
				}
			}
		}
		return count( $ids );
	}

	/**
	 * Persist a finished stats scan.
	 *
	 * @param array $acc Acc.
	 */
	public static function save_stats( array $acc ): void {
		$acc['updated'] = time();
		update_option( self::STATS_OPTION, $acc, false );
	}

	/**
	 * Cached stats from the last scan (all zero + updated=0 if never run).
	 *
	 * @return array
	 */
	public static function get_stats(): array {
		$defaults = array(
			'orig_count'   => 0,
			'orig_bytes'   => 0,
			'webp_count'   => 0,
			'webp_bytes'   => 0,
			'webp_saved'   => 0,
			'avif_count'   => 0,
			'avif_bytes'   => 0,
			'avif_saved'   => 0,
			'avif_vs_webp' => 0,
			'updated'      => 0,
		);
		$s        = get_option( self::STATS_OPTION );
		return is_array( $s ) ? array_merge( $defaults, $s ) : $defaults;
	}

	/**
	 * $work names a work file for found copies; without it they are kept in the state.
	 *
	 * @param string $work Work.
	 *
	 * @return array
	 */
	public function new_generated_scan_state( string $work = '' ): array {
		if ( '' !== $work ) {
			LucidLab_MPC_Work::delete( $work );
		}
		return array(
			'queue'      => array( '' ),
			'files'      => array(),
			'work'       => $work,
			'found'      => 0,
			'dirs'       => 0,
			'current'    => '/',
			'orig_count' => 0,
			'orig_bytes' => 0,
		);
	}

	/**
	 * Count a folder copy (webp/rel.jpg.webp) once when a leftover sidecar (rel.jpg.webp) also exists.
	 *
	 * @param array<int,array{rel?:string,bytes?:int}> $files Files.
	 *
	 * @return array<int,array{rel:string,bytes:int}>
	 */
	public static function unique_generated_files( array $files ): array {
		$best = array();
		foreach ( $files as $file ) {
			$rel = ltrim( str_replace( '\\', '/', (string) ( $file['rel'] ?? '' ) ), '/' );
			if ( '' === $rel ) {
				continue;
			}
			$fmt = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
			if ( ! in_array( $fmt, array( 'webp', 'avif' ), true ) ) {
				continue;
			}
			$prefix    = LucidLab_MPC_Helpers::nextgen_prefix_of( $rel, $fmt );
			$in_folder = '' !== $prefix;
			$identity  = $in_folder ? substr( $rel, strlen( $prefix ) ) : $rel;
			$key       = $fmt . ':' . strtolower( $identity );
			if ( ! isset( $best[ $key ] ) || ( $in_folder && empty( $best[ $key ]['folder'] ) ) ) {
				$best[ $key ] = array(
					'rel'    => $rel,
					'bytes'  => (int) ( $file['bytes'] ?? 0 ),
					'folder' => $in_folder,
				);
			}
		}
		$out = array();
		foreach ( $best as $row ) {
			$out[] = array(
				'rel'   => $row['rel'],
				'bytes' => $row['bytes'],
			);
		}
		return $out;
	}

	/**
	 * JPEG/PNG path a generated copy was made from, e.g. webp/2024/a.jpg.webp → 2024/a.jpg.
	 *
	 * @param string $rel Rel.
	 *
	 * @return string
	 */
	public static function logical_source_rel( string $rel ): string {
		$source = LucidLab_MPC_Helpers::generated_copy_source( $rel );
		if ( '' !== $source ) {
			return $source;
		}
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
		return (string) preg_replace( '/\.(webp|avif)$/i', '', $rel );
	}

	/**
	 * Bytes of the JPEG/PNG a copy came from, on disk or in the originals quarantine.
	 *
	 * @param string            $generated_rel Generated rel.
	 * @param array<string,int> $quarantine    Quarantine.
	 *
	 * @return int
	 */
	public function source_bytes( string $generated_rel, array $quarantine ): int {
		$orig = self::logical_source_rel( $generated_rel );
		if ( '' === $orig || strpos( $orig, '../' ) !== false ) {
			return 0;
		}
		$live = rtrim( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) . '/' . $orig;
		if ( is_file( $live ) ) {
			return (int) filesize( $live );
		}
		return (int) ( $quarantine[ $orig ] ?? 0 );
	}

	/**
	 * Walk uploads for a few seconds, collecting generated .jpg.webp / .png.avif copies.
	 *
	 * @param array $state State.
	 *
	 * @return array
	 */
	public function scan_generated_step( array $state ): array {
		$basedir = rtrim( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ), '/' );
		if ( ! is_dir( $basedir ) ) {
			$state['queue'] = array();
			return $state;
		}
		$quar       = LucidLab_MPC_Quarantine::DIRNAME;
		$started    = microtime( true );
		$step_items = 0;
		$queue      = is_array( $state['queue'] ?? null ) ? $state['queue'] : array();
		$work       = (string) ( $state['work'] ?? '' );
		$files      = '' === $work && is_array( $state['files'] ?? null ) ? $state['files'] : array();
		unset( $state['seen'] );
		while ( $queue && ( microtime( true ) - $started ) < self::GEN_SCAN_BUDGET ) {
			$rel_dir = (string) array_shift( $queue );
			$abs     = $basedir . ( '' === $rel_dir ? '' : '/' . $rel_dir );
			if ( ! is_dir( $abs ) ) {
				continue;
			}
			$state['dirs']    = (int) ( $state['dirs'] ?? 0 ) + 1;
			$state['current'] = '' === $rel_dir ? '/' : $rel_dir;
			$entries          = ( is_dir( $abs ) ? scandir( $abs ) : false );
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$path = $abs . '/' . $name;
				$rel  = ltrim( ( '' === $rel_dir ? $name : $rel_dir . '/' . $name ), '/' );
				if ( is_dir( $path ) ) {
					if ( LucidLab_MPC_Storage::SLUG === $rel ) {
						// Only the copy folders of the plugin folder hold generated images.
						foreach ( array( 'webp', 'avif' ) as $copy_dir ) {
							if ( is_dir( $path . '/' . $copy_dir ) ) {
								$queue[] = $rel . '/' . $copy_dir;
							}
						}
						continue;
					}
					if ( in_array( $name, self::GEN_SKIP_DIRS, true ) || str_starts_with( $name, 'lucidlab-mpc-backups-' ) || 0 === strpos( $rel . '/', $quar . '/' ) ) {
						continue;
					}
					$in_copies = '' !== LucidLab_MPC_Helpers::nextgen_prefix_of( $rel . '/' );
					if ( class_exists( 'LucidLab_MPC_Exclusions', false ) && ! $in_copies && LucidLab_MPC_Exclusions::skip_rel( $rel ) ) {
						continue;
					}
					$queue[] = $rel;
					continue;
				}
				if ( ! preg_match( '/\.(jpe?g|png|gif)\.(webp|avif)$/i', $name ) ) {
					if ( '' === LucidLab_MPC_Helpers::nextgen_prefix_of( $rel ) && preg_match( '/\.(jpe?g|png|gif)$/i', $name ) ) {
						$state['orig_count'] = (int) ( $state['orig_count'] ?? 0 ) + 1;
						$state['orig_bytes'] = (int) ( $state['orig_bytes'] ?? 0 ) + (int) ( is_file( $path ) ? filesize( $path ) : false );
						++$step_items;
					}
					continue;
				}
				$bytes = (int) ( is_file( $path ) ? filesize( $path ) : false );
				if ( $bytes < 32 ) {
					$state['empty'] = (int) ( $state['empty'] ?? 0 ) + 1;
					continue;
				}
				// Folder copies and leftover sidecars are de-duplicated once the scan ends.
				$files[] = array(
					'rel'   => $rel,
					'bytes' => $bytes,
				);
				++$step_items;
			}
		}
		if ( class_exists( 'LucidLab_MPC_Scan_Profile', false ) ) {
			LucidLab_MPC_Scan_Profile::count( $step_items );
		}
		$state['queue'] = $queue;
		if ( '' === $work ) {
			$state['files'] = $files;
			$state['found'] = count( $files );
		} else {
			LucidLab_MPC_Work::append( $work, $files );
			$state['found'] = (int) ( $state['found'] ?? 0 ) + count( $files );
		}
		return $state;
	}

	/**
	 * Every copy found by a scan, de-duplicated. @return array<int,array{rel:string,bytes:int}>.
	 *
	 * @param array $state State.
	 *
	 * @return array
	 */
	public static function generated_files( array $state ): array {
		$work = (string) ( $state['work'] ?? '' );
		return self::unique_generated_files( '' !== $work ? LucidLab_MPC_Work::lines( $work ) : (array) ( $state['files'] ?? array() ) );
	}

	/**
	 * Find generated next-gen copies (double extension e.g. photo.jpg.webp / photo.png.avif) anywhere under uploads, skipping the quarantine folder. Returns rel paths + total bytes.
	 *
	 * @return array
	 */
	public function scan_generated(): array {
		$state = $this->new_generated_scan_state();
		while ( ! empty( $state['queue'] ) ) {
			$state = $this->scan_generated_step( $state );
		}
		$files = self::generated_files( $state );
		return array(
			'files' => $files,
			'count' => count( $files ),
			'bytes' => array_sum( array_column( $files, 'bytes' ) ),
		);
	}
}
