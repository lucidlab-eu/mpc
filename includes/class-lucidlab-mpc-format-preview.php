<?php
/**
 * Lucid Lab Media & Products Cleanup: format preview class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Temporary Original / WebP / AVIF comparison of a sample of library images. */
class LucidLab_MPC_Format_Preview {

	public const DIRNAME = '_cleanup-format-preview';
	public const LIMIT   = 10;

	/**
	 * Caps.
	 *
	 * @return array
	 */
	public static function caps(): array {
		return LucidLab_MPC_Helpers::nextgen_caps();
	}

	/**
	 * Dir.
	 *
	 * @return string
	 */
	public static function dir(): string {
		return LucidLab_MPC_Storage::dir( 'format-preview', false );
	}

	/**
	 * Url for.
	 *
	 * @param string $abs Abs.
	 *
	 * @return string
	 */
	public static function url_for( string $abs ): string {
		$base = rtrim( str_replace( '\\', '/', LucidLab_MPC_Helpers::uploads_basedir() ), '/' );
		$abs  = str_replace( '\\', '/', $abs );
		$url  = rtrim( LucidLab_MPC_Helpers::uploads_baseurl(), '/' );
		return $base && str_starts_with( $abs, $base . '/' ) ? $url . substr( $abs, strlen( $base ) ) : '';
	}

	/**
	 * Sample attachments.
	 *
	 * @param int    $limit Limit.
	 * @param string $mode  Mode.
	 *
	 * @return array
	 */
	public static function sample_attachments( int $limit = self::LIMIT, string $mode = 'all' ): array {
		global $wpdb;
		$limit = max( 1, min( self::LIMIT, $limit ) );
		$sql   = "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type='attachment' AND p.post_mime_type IN ('image/jpeg','image/png','image/webp') AND p.post_status='inherit'";
		if ( 'featured' === $mode ) {
			$sql = "SELECT DISTINCT CAST(m.meta_value AS UNSIGNED) AS ID
				FROM {$wpdb->postmeta} m
				INNER JOIN {$wpdb->posts} p ON p.ID = CAST(m.meta_value AS UNSIGNED)
				WHERE m.meta_key='_thumbnail_id'
				  AND p.post_type='attachment'
				  AND p.post_mime_type IN ('image/jpeg','image/png','image/webp')
				  AND p.post_status='inherit'";
		}
		$out         = array();
		$cursor      = PHP_INT_MAX;
		$checked     = 0;
		$started     = microtime( true );
		$max_checked = 2000;
		$max_seconds = 3.0;
		// Recent attachments may have been quarantined or moved. Page through IDs
		// instead of giving up after the first 80 missing files.
		do {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql . ' AND p.ID < %d ORDER BY 1 DESC LIMIT 200', $cursor ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is a fixed attachment query; the cursor is prepared; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( ! $ids ) {
				break;
			}
			$cursor = min( $ids );
			foreach ( $ids as $id ) {
				if ( ++$checked > $max_checked || microtime( true ) - $started >= $max_seconds ) {
					break 2;
				}
				if ( ! $id ) {
					continue;
				}
				$file = get_attached_file( $id );
				if ( ! $file || ! is_file( $file ) ) {
					continue;
				}
				$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
				if ( 'webp' === $ext && preg_match( '/\.(?:jpe?g|png)\.webp$/i', $file ) ) {
					$base   = rtrim( str_replace( '\\', '/', LucidLab_MPC_Helpers::uploads_basedir() ), '/' );
					$path   = str_replace( '\\', '/', $file );
					$prefix = $base ? LucidLab_MPC_Helpers::nextgen_prefix_of( substr( $path, strlen( $base ) + 1 ), 'webp' ) : '';
					if ( '' !== $prefix && str_starts_with( $path, $base . '/' ) ) {
						$file = $base . '/' . substr( $path, strlen( $base ) + 1 + strlen( $prefix ), -5 );
						$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
					}
				}
				if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
					continue;
				}
				if ( ! is_file( $file ) ) {
					continue;
				}
				$url = self::url_for( $file );
				if ( ! $url ) {
					continue;
				}
				$out[] = array(
					'id'    => $id,
					'title' => self::plain_title( $id, basename( $file ) ),
					'file'  => $file,
					'url'   => $url,
					'thumb' => $url,
					'full'  => $url,
					'bytes' => (int) filesize( $file ),
				);
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
			$out_seen = count( $out );
		} while ( $out_seen < $limit );
		return $out;
	}

	/**
	 * Attachment title without wptexturize, which turns " - " into &#8211;.
	 *
	 * @param int    $id       Id.
	 * @param string $fallback Fallback.
	 *
	 * @return string
	 */
	private static function plain_title( int $id, string $fallback ): string {
		$title = html_entity_decode( wp_strip_all_tags( (string) get_post_field( 'post_title', $id, 'raw' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title = trim( (string) preg_replace( '/\s+/u', ' ', $title ) );
		return '' !== $title ? $title : $fallback;
	}

	/**
	 * Job key.
	 *
	 * @return string
	 */
	private static function job_key(): string {
		return 'lucidlab_mpc_webp_preview_job_' . (int) get_current_user_id();
	}

	/**
	 * Result key.
	 *
	 * @return string
	 */
	private static function result_key(): string {
		return 'lucidlab_mpc_webp_preview_' . (int) get_current_user_id();
	}

	/**
	 * Plan.
	 *
	 * @param array $input Input.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	private static function plan( array $input ): array {
		$caps    = self::caps();
		$mode    = 'featured';
		$formats = array();
		$quality = array();
		foreach ( array( 'webp', 'avif' ) as $fmt ) {
			$enabled = ! empty( $input[ $fmt ]['enabled'] ) && ! empty( $caps[ $fmt ] );
			if ( $enabled ) {
				$formats[] = $fmt;
			}
			$quality[ $fmt ] = LucidLab_MPC_Helpers::clamp_image_quality( $input[ $fmt ]['images'] ?? 0, $fmt );
			if ( ( $input[ $fmt ]['mode'] ?? 'all' ) !== 'featured' ) {
				$mode = 'all';
			}
		}
		if ( ! $formats ) {
			throw new RuntimeException( __( 'Select a supported format (WebP or AVIF) first.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		return array(
			'caps'    => $caps,
			'mode'    => $mode,
			'formats' => $formats,
			'quality' => $quality,
		);
	}

	/**
	 * Counts payload.
	 *
	 * @param array $state State.
	 *
	 * @return array
	 */
	private static function counts_payload( array $state ): array {
		$total  = count( (array) ( $state['samples'] ?? array() ) );
		$counts = is_array( $state['counts'] ?? null ) ? $state['counts'] : array(
			'webp' => 0,
			'avif' => 0,
		);
		return array(
			'done'    => ! empty( $state['done'] ),
			'total'   => $total,
			'index'   => (int) ( $state['index'] ?? 0 ),
			'counts'  => array(
				'webp' => (int) ( $counts['webp'] ?? 0 ),
				'avif' => (int) ( $counts['avif'] ?? 0 ),
			),
			'formats' => array_values( (array) ( $state['formats'] ?? array() ) ),
			'quality' => is_array( $state['quality'] ?? null ) ? $state['quality'] : array(),
			'rows'    => is_array( $state['rows'] ?? null ) ? $state['rows'] : array(),
		);
	}

	/**
	 * Start.
	 *
	 * @param array $input Input.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public static function start( array $input ): array {
		$plan    = self::plan( $input );
		$samples = self::sample_attachments( self::LIMIT, $plan['mode'] );
		if ( ! $samples ) {
			throw new RuntimeException( __( 'No JPEG or PNG library images were found to compare.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		self::purge();
		wp_mkdir_p( self::dir() );
		$state = array(
			'samples' => $samples,
			'formats' => $plan['formats'],
			'quality' => $plan['quality'],
			'caps'    => $plan['caps'],
			'index'   => 0,
			'done'    => false,
			'counts'  => array(
				'webp' => 0,
				'avif' => 0,
			),
			'rows'    => array(),
		);
		set_transient( self::job_key(), $state, HOUR_IN_SECONDS );
		delete_transient( self::result_key() );
		return self::counts_payload( $state );
	}

	/**
	 * Step.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public static function step(): array {
		$state = get_transient( self::job_key() );
		if ( ! is_array( $state ) || empty( $state['samples'] ) ) {
			throw new RuntimeException( __( 'The comparison job expired. Generate the 10 images again.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$index   = (int) ( $state['index'] ?? 0 );
		$samples = array_values( (array) $state['samples'] );
		$formats = array_values( (array) ( $state['formats'] ?? array() ) );
		$quality = is_array( $state['quality'] ?? null ) ? $state['quality'] : array();
		if ( $index >= count( $samples ) ) {
			$state['done'] = true;
			return self::finish( $state );
		}
		$sample = $samples[ $index ];
		$row    = array(
			'id'       => $sample['id'],
			'title'    => $sample['title'],
			'original' => self::file_payload( $sample['url'], $sample['file'], __( 'Original', 'lucidlab-mpc-cleanup' ), self::preview_thumb( (string) $sample['file'], (int) $sample['id'], 'original' ) ),
			'webp'     => null,
			'avif'     => null,
		);
		foreach ( $formats as $fmt ) {
			$converted   = self::convert_one( $sample, $fmt, (int) ( $quality[ $fmt ] ?? LucidLab_MPC_Helpers::clamp_image_quality( 0, $fmt ) ) );
			$row[ $fmt ] = $converted;
			if ( ! empty( $converted['url'] ) ) {
				$state['counts'][ $fmt ] = (int) ( $state['counts'][ $fmt ] ?? 0 ) + 1;
			}
		}
		$state['rows'][] = $row;
		$state['index']  = $index + 1;
		$state['done']   = $state['index'] >= count( $samples );
		if ( $state['done'] ) {
			return self::finish( $state );
		}
		set_transient( self::job_key(), $state, HOUR_IN_SECONDS );
		$out        = self::counts_payload( $state );
		$out['row'] = $row;
		return $out;
	}

	/**
	 * Finish.
	 *
	 * @param array $state State.
	 *
	 * @return array
	 */
	private static function finish( array $state ): array {
		$result = array(
			'rows'    => array_values( (array) ( $state['rows'] ?? array() ) ),
			'caps'    => is_array( $state['caps'] ?? null ) ? $state['caps'] : self::caps(),
			'quality' => is_array( $state['quality'] ?? null ) ? $state['quality'] : array(),
			'formats' => array_values( (array) ( $state['formats'] ?? array() ) ),
			'counts'  => is_array( $state['counts'] ?? null ) ? $state['counts'] : array(
				'webp' => 0,
				'avif' => 0,
			),
			'total'   => count( (array) ( $state['samples'] ?? array() ) ),
			'done'    => true,
			'index'   => (int) ( $state['index'] ?? 0 ),
		);
		set_transient( self::result_key(), $result, DAY_IN_SECONDS );
		delete_transient( self::job_key() );
		return $result;
	}

	/**
	 * Compare.
	 *
	 * @param array $input Input.
	 *
	 * @return array
	 */
	public static function compare( array $input ): array {
		self::start( $input );
		$result = array();
		do {
			$result = self::step();
		} while ( empty( $result['done'] ) );
		return $result;
	}

	/**
	 * Saved.
	 *
	 * @return array
	 */
	public static function saved(): array {
		$saved = get_transient( self::result_key() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Return the persisted final rows, or the current step snapshot while still running.
	 *
	 * @return array
	 */
	public static function status(): array {
		$saved = self::saved();
		if ( ! empty( $saved['done'] ) ) {
			return $saved;
		}
		$state = get_transient( self::job_key() );
		return is_array( $state ) ? self::counts_payload( $state ) : array(
			'done' => false,
			'rows' => array(),
		);
	}

	/**
	 * Clear.
	 *
	 * @return array
	 */
	public static function clear(): array {
		self::purge();
		if ( function_exists( 'delete_transient' ) && function_exists( 'get_current_user_id' ) ) {
			delete_transient( self::result_key() );
			delete_transient( self::job_key() );
		}
		return array(
			'rows'    => array(),
			'cleared' => true,
		);
	}

	/**
	 * Purge.
	 */
	public static function purge(): void {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
	}

	/**
	 * Convert one.
	 *
	 * @param array  $sample  Sample.
	 * @param string $fmt     Fmt.
	 * @param int    $quality Quality.
	 *
	 * @return array|null
	 */
	private static function convert_one( array $sample, string $fmt, int $quality ): ?array {
		$dest  = self::dir() . '/' . (int) $sample['id'] . '.' . $fmt;
		$saved = LucidLab_MPC_Helpers::encode_nextgen( (string) $sample['file'], $dest, $fmt, $quality );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( $saved['path'] ) ) {
			return array(
				'error' => is_wp_error( $saved ) ? $saved->get_error_message() : __( 'Converted file was not created.', 'lucidlab-mpc-cleanup' ),
				'label' => __( 'Failed', 'lucidlab-mpc-cleanup' ),
			);
		}
		if ( $saved['path'] !== $dest && is_file( $saved['path'] ) ) {
			rename( $saved['path'], $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		if ( ! is_file( $dest ) ) {
			return array(
				'error' => __( 'Converted file was not created.', 'lucidlab-mpc-cleanup' ),
				'label' => __( 'Failed', 'lucidlab-mpc-cleanup' ),
			);
		}
		$url   = self::url_for( $dest );
		$thumb = self::preview_thumb( $dest, (int) $sample['id'], $fmt );
		$bytes = (int) filesize( $dest );
		if ( $url ) {
			$url = add_query_arg(
				array(
					'q' => $quality,
					's' => $bytes,
				),
				$url
			);
		}
		$orig        = (int) $sample['bytes'];
		$saved_bytes = $orig - $bytes;
		$pct         = $orig > 0 ? (int) round( 100 * $saved_bytes / $orig ) : 0;
		$caption     = sprintf(
			/* translators: 1: format, 2: quality, 3: size */
			__( '%1$s q%2$d — %3$s', 'lucidlab-mpc-cleanup' ),
			strtoupper( $fmt ),
			$quality,
			LucidLab_MPC_Helpers::bytes( $bytes )
		);
		return array(
			'url'     => $url,
			'thumb'   => '' !== $thumb ? $thumb : $url,
			'bytes'   => $bytes,
			'label'   => LucidLab_MPC_Helpers::bytes( $bytes ),
			'quality' => $quality,
			'saved'   => $saved_bytes,
			'percent' => $pct,
			'caption' => $caption,
		);
	}

	/**
	 * File payload.
	 *
	 * @param string $url     Url.
	 * @param string $abs     Abs.
	 * @param string $caption Caption.
	 * @param string $thumb   Thumb.
	 *
	 * @return array
	 */
	private static function file_payload( string $url, string $abs, string $caption, string $thumb = '' ): array {
		$bytes = is_file( $abs ) ? (int) filesize( $abs ) : 0;
		return array(
			'url'     => $url,
			'thumb'   => '' !== $thumb ? $thumb : $url,
			'bytes'   => $bytes,
			'label'   => LucidLab_MPC_Helpers::bytes( $bytes ),
			'caption' => $caption . ( $bytes ? ' — ' . LucidLab_MPC_Helpers::bytes( $bytes ) : '' ),
		);
	}

	/**
	 * 1:1 center crop for the comparison table. The full file stays on data-full.
	 *
	 * @param string $source Source.
	 * @param int    $id     Id.
	 * @param string $slot   Slot.
	 *
	 * @return string
	 */
	private static function preview_thumb( string $source, int $id, string $slot ): string {
		$ext = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
		if ( 'jpeg' === $ext ) {
			$ext = 'jpg';
		}
		if ( ! in_array( $ext, array( 'jpg', 'png', 'webp', 'avif' ), true ) ) {
			$ext = 'jpg';
		}
		$dest = self::dir() . '/' . $id . '.' . preg_replace( '/[^a-z]/', '', $slot ) . '.' . $ext;
		return self::write_center_crop( $source, $dest, 220 ) ? self::url_for( $dest ) : '';
	}

	/**
	 * Write center crop.
	 *
	 * @param string $source Source.
	 * @param string $dest   Dest.
	 * @param int    $size   Size.
	 *
	 * @return bool
	 */
	private static function write_center_crop( string $source, string $dest, int $size ): bool {
		if ( $size < 1 || ! function_exists( 'imagecreatefromstring' ) || ! is_file( $source ) ) {
			return false;
		}
		$raw = ( is_readable( $source ) ? file_get_contents( $source ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		$im  = $raw ? @imagecreatefromstring( $raw ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GD emits warnings for corrupt images; the return value is checked.
		if ( ! $im ) {
			return false;
		}
		$w    = imagesx( $im );
		$h    = imagesy( $im );
		$side = min( $size, $w, $h );
		$crop = imagecreatetruecolor( $side, $side );
		// An alpha plane here makes Chrome draw the WebP thumbnail as an empty box.
		$white = imagecolorallocate( $crop, 255, 255, 255 );
		imagefilledrectangle( $crop, 0, 0, $side, $side, $white );
		imagealphablending( $crop, true );
		$ok  = imagecopy( $crop, $im, 0, 0, (int) floor( ( $w - $side ) / 2 ), (int) floor( ( $h - $side ) / 2 ), $side, $side );
		$ext = strtolower( pathinfo( $dest, PATHINFO_EXTENSION ) );
		if ( $ok && 'webp' === $ext && function_exists( 'imagewebp' ) ) {
			$ok = imagewebp( $crop, $dest, 90 );
		} elseif ( $ok && 'avif' === $ext && function_exists( 'imageavif' ) ) {
			$ok = imageavif( $crop, $dest, 60 );
		} elseif ( $ok && 'png' === $ext && function_exists( 'imagepng' ) ) {
			$ok = imagepng( $crop, $dest );
		} elseif ( $ok && function_exists( 'imagejpeg' ) ) {
			$ok = imagejpeg( $crop, $dest, 90 );
		} else {
			$ok = false;
		}
		imagedestroy( $im );
		imagedestroy( $crop );
		if ( ! $ok || ! is_file( $dest ) || filesize( $dest ) < 1 ) {
			wp_delete_file( $dest );
			return false;
		}
		return true;
	}
}
