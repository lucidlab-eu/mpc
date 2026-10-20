<?php
/**
 * Lucid Lab Media & Products Cleanup: inventory class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Welcome-page inventory: uploads folder breakdown + library / reference counts.
 * Honours Options → excluded folders (and always skips quarantine / work dirs).
 */
class LucidLab_MPC_Inventory {

	const RESULT_OPTION = 'lucidlab_mpc_inventory_result';
	const DISK_STATE    = 'lucidlab_mpc_inventory_disk';
	const REFS_STATE    = 'lucidlab_mpc_inventory_refs';

	/**
	 * Regex syntax is code, never a translatable interface string.
	 *
	 * @param string $content Content.
	 * @param bool   $pdf     Pdf.
	 *
	 * @return bool
	 */
	private static function has_upload_reference( string $content, bool $pdf ): bool {
		$pattern = $pdf
			? '/wp-content\\/uploads\\/[^\\s\\"\\\'<>]+\\.pdf/i'
			: '/wp-content\\/uploads\\/[^\\s\\"\\\'<>]+\\.(?:jpe?g|png|gif|webp|avif|svg)/i';
		return 1 === preg_match( $pattern, $content );
	}

	/**
	 * Result.
	 *
	 * @return array{disk?:array,refs?:array,updated?:int}
	 */
	public static function result(): array {
		$r = get_option( self::RESULT_OPTION );
		return is_array( $r ) ? $r : array();
	}

	/**
	 * State key.
	 *
	 * @param string $which Which.
	 *
	 * @return string
	 */
	private static function state_key( string $which ): string {
		return ( 'refs' === $which ? self::REFS_STATE : self::DISK_STATE ) . '_' . get_current_user_id();
	}

	/**
	 * Get state.
	 *
	 * @param string $which Which.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_state( string $which ): array {
		$s = get_transient( self::state_key( $which ) );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Set state.
	 *
	 * @param string $which Which.
	 * @param array  $state State.
	 */
	private static function set_state( string $which, array $state ): void {
		set_transient( self::state_key( $which ), $state, HOUR_IN_SECONDS );
	}

	/**
	 * Clear state.
	 *
	 * @param string $which Which.
	 */
	private static function clear_state( string $which ): void {
		delete_transient( self::state_key( $which ) );
	}

	/**
	 * Start or continue the uploads directory scan.
	 *
	 * @param bool $reset Reset.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public function disk_step( bool $reset ): array {
		$base = rtrim( str_replace( '\\', '/', LucidLab_MPC_Helpers::uploads_basedir() ), '/' );
		if ( ! $base || ! is_dir( $base ) ) {
			throw new RuntimeException( __( 'Uploads folder is not available.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$state = $reset ? array() : self::get_state( 'disk' );
		if ( $reset || empty( $state['queue'] ) ) {
			$state = array(
				'queue'        => array( $base ),
				'buckets'      => array(),
				'folders'      => array(),
				'total_files'  => 0,
				'total_bytes'  => 0,
				'dirs_done'    => 0,
				'skipped_dirs' => 0,
				'phase'        => 'walk',
			);
		}
		$folders      = class_exists( 'LucidLab_MPC_Exclusions', false ) ? LucidLab_MPC_Exclusions::folders() : array( LucidLab_MPC_Storage::SLUG, '_cleanup-quarantine', '_cleanup-fsindex', '_cleanup-format-preview', 'mpc-releases' );
		$deadline     = microtime( true ) + 2.2;
		$guard        = 0;
		$files_before = (int) ( $state['total_files'] ?? 0 );
		while ( $guard < 400 && microtime( true ) < $deadline && ! empty( $state['queue'] ) ) {
			++$guard;
			$dir     = array_shift( $state['queue'] );
			$dir     = str_replace( '\\', '/', (string) $dir );
			$rel_dir = self::rel_from_base( $base, $dir );
			if ( '' !== $rel_dir && class_exists( 'LucidLab_MPC_Exclusions', false ) && LucidLab_MPC_Exclusions::path_is_excluded( $rel_dir, $folders ) ) {
				$state['skipped_dirs'] = (int) ( $state['skipped_dirs'] ?? 0 ) + 1;
				continue;
			}
			$entries = ( is_dir( $dir ) ? scandir( $dir ) : false );
			if ( ! is_array( $entries ) ) {
				$state['dirs_done'] = (int) ( $state['dirs_done'] ?? 0 ) + 1;
				continue;
			}
			foreach ( $entries as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$path = $dir . '/' . $name;
				$rel  = self::rel_from_base( $base, $path );
				if ( '' !== $rel && class_exists( 'LucidLab_MPC_Exclusions', false ) && LucidLab_MPC_Exclusions::path_is_excluded( $rel, $folders ) ) {
					if ( is_dir( $path ) ) {
						$state['skipped_dirs'] = (int) ( $state['skipped_dirs'] ?? 0 ) + 1;
					}
					continue;
				}
				if ( is_dir( $path ) ) {
					if ( str_starts_with( $name, '.' ) ) {
						continue;
					}
					$state['queue'][] = $path;
					continue;
				}
				if ( ! is_file( $path ) ) {
					continue;
				}
				$bytes  = (int) ( is_file( $path ) ? filesize( $path ) : false );
				$row    = self::classify( $rel );
				$parts  = explode( '/', $rel );
				$folder = count( $parts ) > 1 ? $parts[0] : '/';
				if ( ! isset( $state['folders'][ $folder ] ) ) {
					$state['folders'][ $folder ] = array(
						'name'  => $folder,
						'count' => 0,
						'bytes' => 0,
					);
				}
				++$state['folders'][ $folder ]['count'];
				$state['folders'][ $folder ]['bytes'] += $bytes;
				$key                                   = $row['key'];
				if ( ! isset( $state['buckets'][ $key ] ) ) {
					$state['buckets'][ $key ] = array(
						'ext'   => $row['ext'],
						'kind'  => $row['kind'],
						'label' => $row['label'],
						'count' => 0,
						'bytes' => 0,
					);
				}
				++$state['buckets'][ $key ]['count'];
				$state['buckets'][ $key ]['bytes'] += $bytes;
				$state['total_files']               = (int) $state['total_files'] + 1;
				$state['total_bytes']               = (int) $state['total_bytes'] + $bytes;
			}
			$state['dirs_done'] = (int) ( $state['dirs_done'] ?? 0 ) + 1;
		}
		$done = empty( $state['queue'] );
		if ( $done ) {
			$disk             = self::finalize_disk( $state );
			$saved            = self::result();
			$saved['disk']    = $disk;
			$saved['updated'] = time();
			update_option( self::RESULT_OPTION, $saved, false );
			self::clear_state( 'disk' );
			return array(
				'done'       => true,
				'progress'   => 100,
				'label'      => sprintf(
					/* translators: 1: file count, 2: human size */
					__( 'Uploads inventory ready — %1$s files (%2$s).', 'lucidlab-mpc-cleanup' ),
					number_format_i18n( (int) $disk['total_files'] ),
					LucidLab_MPC_Helpers::bytes( (int) $disk['total_bytes'] )
				),
				'disk'       => $disk,
				'refs'       => $saved['refs'] ?? null,
				'step_items' => (int) $disk['total_files'] - $files_before,
			);
		}
		self::set_state( 'disk', $state );
		$queued = count( $state['queue'] );
		$done_d = (int) ( $state['dirs_done'] ?? 0 );
		$pct    = (int) min( 95, max( 3, floor( 100 * $done_d / max( 1, $done_d + $queued ) ) ) );
		return array(
			'done'       => false,
			'progress'   => $pct,
			'label'      => sprintf(
				/* translators: 1: files counted so far, 2: folders still queued */
				__( 'Scanning uploads — %1$s files so far, %2$s folders left…', 'lucidlab-mpc-cleanup' ),
				number_format_i18n( (int) $state['total_files'] ),
				number_format_i18n( $queued )
			),
			'step_items' => (int) $state['total_files'] - $files_before,
		);
	}

	private const SAMPLE_LIMIT = 80;

	/**
	 * Start or continue the library / reference counts.
	 *
	 * @param bool $reset Reset.
	 *
	 * @return array
	 */
	public function refs_step( bool $reset ): array {
		global $wpdb;
		$state = $reset ? array() : self::get_state( 'refs' );
		if ( $reset || empty( $state['phase'] ) ) {
			$state = array(
				'phase'   => 'attachments',
				'cursor'  => 0,
				'counts'  => array(
					'attachments_image' => 0,
					'attachments_pdf'   => 0,
					'attachments_other' => 0,
					'attachments_total' => 0,
					'featured_refs'     => 0,
					'gallery_refs'      => 0,
					'content_image'     => 0,
					'content_pdf'       => 0,
				),
				'samples' => array(
					'attachments_image' => array(),
					'attachments_pdf'   => array(),
					'attachments_other' => array(),
					'featured_refs'     => array(),
					'gallery_refs'      => array(),
					'content_image'     => array(),
					'content_pdf'       => array(),
				),
			);
		}
		$deadline = microtime( true ) + 2.0;
		$step     = 0;
		if ( 'attachments' === $state['phase'] ) {
			while ( microtime( true ) < $deadline ) {
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					$wpdb->prepare(
						"SELECT ID, post_title, post_mime_type FROM {$wpdb->posts}
						 WHERE post_type = 'attachment' AND ID > %d
						 ORDER BY ID ASC LIMIT 400",
						(int) $state['cursor']
					)
				);
				if ( ! $rows ) {
					$state['phase'] = 'featured';
					break;
				}
				$step += count( $rows );
				foreach ( $rows as $row ) {
					$state['cursor'] = (int) $row->ID;
					$mime            = strtolower( (string) $row->post_mime_type );
					++$state['counts']['attachments_total'];
					$key = 'attachments_other';
					if ( str_starts_with( $mime, 'image/' ) ) {
						$key = 'attachments_image';
					} elseif ( 'application/pdf' === $mime || str_ends_with( $mime, '/pdf' ) ) {
						$key = 'attachments_pdf';
					}
					++$state['counts'][ $key ];
					self::push_sample( $state, $key, (int) $row->ID, (string) $row->post_title, 'attachment' );
				}
			}
		}
		if ( 'featured' === ( $state['phase'] ?? '' ) ) {
			$state['counts']['featured_refs'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				"SELECT COUNT(*) FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = '_thumbnail_id'
				   AND CAST(m.meta_value AS UNSIGNED) > 0
				   AND p.post_status NOT IN ('trash','auto-draft')"
			);
			$featured                         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_type FROM {$wpdb->postmeta} m
					 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
					 WHERE m.meta_key = '_thumbnail_id'
					   AND CAST(m.meta_value AS UNSIGNED) > 0
					   AND p.post_status NOT IN ('trash','auto-draft')
					 ORDER BY p.ID DESC LIMIT %d",
					self::SAMPLE_LIMIT
				)
			);
			foreach ( (array) $featured as $row ) {
				self::push_sample( $state, 'featured_refs', (int) $row->ID, (string) $row->post_title, (string) $row->post_type );
			}
			$state['counts']['gallery_refs'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				"SELECT COUNT(*) FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key IN ('_product_image_gallery','image_gallery')
				   AND m.meta_value <> ''
				   AND p.post_status NOT IN ('trash','auto-draft')"
			);
			$gallery                         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_type FROM {$wpdb->postmeta} m
					 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
					 WHERE m.meta_key IN ('_product_image_gallery','image_gallery')
					   AND m.meta_value <> ''
					   AND p.post_status NOT IN ('trash','auto-draft')
					 ORDER BY p.ID DESC LIMIT %d",
					self::SAMPLE_LIMIT
				)
			);
			foreach ( (array) $gallery as $row ) {
				self::push_sample( $state, 'gallery_refs', (int) $row->ID, (string) $row->post_title, (string) $row->post_type );
			}
			$step           += (int) $state['counts']['featured_refs'] + (int) $state['counts']['gallery_refs'];
			$state['phase']  = 'content';
			$state['cursor'] = 0;
		}
		if ( 'content' === ( $state['phase'] ?? '' ) ) {
			$like_up = '%' . $wpdb->esc_like( 'wp-content/uploads' ) . '%';
			while ( microtime( true ) < $deadline ) {
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					$wpdb->prepare(
						"SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
						 WHERE post_status NOT IN ('trash','auto-draft') AND post_type NOT IN ('attachment','revision')
						   AND ID > %d AND post_content LIKE %s
						 ORDER BY ID ASC LIMIT 80",
						(int) $state['cursor'],
						$like_up
					)
				);
				if ( ! $rows ) {
					$state['phase'] = 'done';
					break;
				}
				$step += count( $rows );
				foreach ( $rows as $row ) {
					$state['cursor'] = (int) $row->ID;
					$content         = (string) $row->post_content;
					if ( self::has_upload_reference( $content, false ) ) {
						++$state['counts']['content_image'];
						self::push_sample( $state, 'content_image', (int) $row->ID, (string) $row->post_title, (string) $row->post_type );
					}
					if ( self::has_upload_reference( $content, true ) ) {
						++$state['counts']['content_pdf'];
						self::push_sample( $state, 'content_pdf', (int) $row->ID, (string) $row->post_title, (string) $row->post_type );
					}
				}
			}
		}
		if ( 'done' === ( $state['phase'] ?? '' ) ) {
			$samples          = self::finalize_samples( (array) ( $state['samples'] ?? array() ) );
			$refs             = array(
				'rows'    => array(
					array(
						'key'   => 'attachments_image',
						'label' => __( 'Image attachments', 'lucidlab-mpc-cleanup' ),
						'count' => (int) $state['counts']['attachments_image'],
					),
					array(
						'key'   => 'attachments_pdf',
						'label' => __( 'PDF attachments', 'lucidlab-mpc-cleanup' ),
						'count' => (int) $state['counts']['attachments_pdf'],
					),
					array(
						'key'   => 'attachments_other',
						'label' => __( 'Other attachments', 'lucidlab-mpc-cleanup' ),
						'count' => (int) $state['counts']['attachments_other'],
					),
					array(
						'key'   => 'attachments_total',
						'label' => __( 'Total media library items', 'lucidlab-mpc-cleanup' ),
						'count' => (int) $state['counts']['attachments_total'],
					),
					array(
						'key'   => 'featured_refs',
						'label' => __( 'Featured-image references', 'lucidlab-mpc-cleanup' ),
						'count' => (int) $state['counts']['featured_refs'],
					),
					array(
						'key'   => 'gallery_refs',
						'label' => __( 'Product / gallery meta rows', 'lucidlab-mpc-cleanup' ),
						'count' => (int) $state['counts']['gallery_refs'],
					),
					array(
						'key'   => 'content_image',
						'label' => __( 'Posts/pages with image URLs in content', 'lucidlab-mpc-cleanup' ),
						'count' => (int) $state['counts']['content_image'],
					),
					array(
						'key'   => 'content_pdf',
						'label' => __( 'Posts/pages with PDF URLs in content', 'lucidlab-mpc-cleanup' ),
						'count' => (int) $state['counts']['content_pdf'],
					),
				),
				'counts'  => $state['counts'],
				'samples' => $samples,
				'updated' => time(),
			);
			$saved            = self::result();
			$saved['refs']    = $refs;
			$saved['updated'] = time();
			update_option( self::RESULT_OPTION, $saved, false );
			self::clear_state( 'refs' );
			return array(
				'done'       => true,
				'progress'   => 100,
				'label'      => __( 'Library references ready.', 'lucidlab-mpc-cleanup' ),
				'refs'       => $refs,
				'disk'       => $saved['disk'] ?? null,
				'step_items' => $step,
			);
		}
		self::set_state( 'refs', $state );
		$phase  = (string) ( $state['phase'] ?? '' );
		$labels = array(
			'attachments' => __( 'Counting media library attachments…', 'lucidlab-mpc-cleanup' ),
			'featured'    => __( 'Counting featured images and galleries…', 'lucidlab-mpc-cleanup' ),
			'content'     => __( 'Scanning post content for image and PDF URLs…', 'lucidlab-mpc-cleanup' ),
		);
		$pct    = 'attachments' === $phase ? 25 : ( 'content' === $phase ? 70 : 50 );
		return array(
			'done'       => false,
			'progress'   => $pct,
			'label'      => $labels[ $phase ] ?? __( 'Scanning library references…', 'lucidlab-mpc-cleanup' ),
			'step_items' => $step,
		);
	}

	/**
	 * Push sample.
	 *
	 * @param array  &$state State.
	 * @param string $key   Key.
	 * @param int    $id    Id.
	 * @param string $title Title.
	 * @param string $type  Type.
	 */
	private static function push_sample( array &$state, string $key, int $id, string $title, string $type ): void {
		if ( $id < 1 || ! isset( $state['samples'][ $key ] ) ) {
			return;
		}
		if ( count( $state['samples'][ $key ] ) >= self::SAMPLE_LIMIT ) {
			return;
		}
		foreach ( $state['samples'][ $key ] as $existing ) {
			if ( (int) ( $existing['id'] ?? 0 ) === $id ) {
				return;
			}
		}
		$state['samples'][ $key ][] = array(
			'id'    => $id,
			'title' => '' !== $title ? $title : ( '#' . $id ),
			'type'  => $type,
		);
	}

	/**
	 * Finalize samples.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $samples Samples.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function finalize_samples( array $samples ): array {
		// Free can open the expandable sample list; View / Edit links stay Pro-only.
		$allow_links = class_exists( 'LucidLab_MPC_Edition', false ) && LucidLab_MPC_Edition::pro();
		$out         = array();
		foreach ( $samples as $key => $items ) {
			$list = array();
			foreach ( (array) $items as $item ) {
				$id = (int) ( $item['id'] ?? 0 );
				if ( $id < 1 ) {
					continue;
				}
				$type = (string) ( $item['type'] ?? 'post' );
				$view = '';
				$edit = '';
				if ( $allow_links ) {
					$view = get_permalink( $id );
					$edit = get_edit_post_link( $id, 'raw' );
					if ( 'attachment' === $type ) {
						$view = LucidLab_MPC_Helpers::either( wp_get_attachment_url( $id ), $view );
					}
				}
				$list[] = array(
					'id'    => $id,
					'title' => (string) ( $item['title'] ?? ( '#' . $id ) ),
					'type'  => $type,
					'view'  => is_string( $view ) ? $view : '',
					'edit'  => is_string( $edit ) ? $edit : '',
				);
			}
			$out[ $key ] = $list;
		}
		return $out;
	}

	/**
	 * Rel from base.
	 *
	 * @param string $base Base.
	 * @param string $abs  Abs.
	 *
	 * @return string
	 */
	private static function rel_from_base( string $base, string $abs ): string {
		$abs  = str_replace( '\\', '/', $abs );
		$base = rtrim( $base, '/' );
		if ( str_starts_with( $abs, $base . '/' ) ) {
			return ltrim( substr( $abs, strlen( $base ) ), '/' );
		}
		return '';
	}

	/**
	 * Classify.
	 *
	 * @param string $rel   Rel.
	 *
	 * @return array{key:string,ext:string,kind:string,label:string}
	 */
	public static function classify( string $rel ): array {
		$rel  = str_replace( '\\', '/', $rel );
		$name = basename( $rel );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			$ext = '(none)';
		}
		$parts   = explode( '/', strtolower( $rel ) );
		$in_webp = '' !== LucidLab_MPC_Helpers::nextgen_prefix_of( $rel, 'webp' );
		$in_avif = '' !== LucidLab_MPC_Helpers::nextgen_prefix_of( $rel, 'avif' );
		// Folder copies live under uploads/lucidlab-mpc-cleanup/{webp,avif}/… (older versions: uploads/webp/… and uploads/avif/…).
		if ( $in_webp || 'webp' === $ext ) {
			$thumb = '' !== self::thumb_dims( $name );
			$kind  = $thumb ? 'webp-thumb' : 'webp';
			$label = $thumb ? __( 'WebP thumbnails', 'lucidlab-mpc-cleanup' ) : __( 'WebP copy', 'lucidlab-mpc-cleanup' );
			return array(
				'key'   => 'webp|' . $kind,
				'ext'   => 'webp',
				'kind'  => $kind,
				'label' => $label,
			);
		}
		if ( $in_avif || 'avif' === $ext ) {
			$thumb = '' !== self::thumb_dims( $name );
			$kind  = $thumb ? 'avif-thumb' : 'avif';
			$label = $thumb ? __( 'AVIF thumbnails', 'lucidlab-mpc-cleanup' ) : __( 'AVIF copy', 'lucidlab-mpc-cleanup' );
			return array(
				'key'   => 'avif|' . $kind,
				'ext'   => 'avif',
				'kind'  => $kind,
				'label' => $label,
			);
		}
		$image_exts = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'svg', 'bmp', 'tif', 'tiff', 'ico', 'heic' );
		if ( '' !== self::thumb_dims( $name ) && in_array( $ext, $image_exts, true ) ) {
			return array(
				'key'   => $ext . '|thumb',
				'ext'   => $ext,
				'kind'  => 'thumb',
				'label' => __( 'Thumbnails', 'lucidlab-mpc-cleanup' ),
			);
		}
		if ( in_array( $ext, $image_exts, true ) ) {
			return array(
				'key'   => $ext . '|original',
				'ext'   => $ext,
				'kind'  => 'original',
				'label' => __( 'Original', 'lucidlab-mpc-cleanup' ),
			);
		}
		return array(
			'key'   => $ext . '|file',
			'ext'   => $ext,
			'kind'  => 'file',
			'label' => __( 'File', 'lucidlab-mpc-cleanup' ),
		);
	}

	/**
	 * Thumb dims.
	 *
	 * @param string $name Name.
	 *
	 * @return string
	 */
	private static function thumb_dims( string $name ): string {
		if ( preg_match( '/-(\d+)x(\d+)(?:\.[a-z0-9]+)*$/i', $name, $m ) ) {
			return (int) $m[1] . '×' . (int) $m[2];
		}
		return '';
	}

	/**
	 * Finalize disk.
	 *
	 * @param array<string,mixed> $state State.
	 *
	 * @return array
	 */
	private static function finalize_disk( array $state ): array {
		$rows = array_values( (array) ( $state['buckets'] ?? array() ) );
		usort(
			$rows,
			static function ( $a, $b ) {
				$ea = strtolower( (string) ( $a['ext'] ?? '' ) );
				$eb = strtolower( (string) ( $b['ext'] ?? '' ) );
				if ( $ea !== $eb ) {
					return $ea <=> $eb;
				}
				$bytes_cmp = ( (int) ( $b['bytes'] ?? 0 ) ) <=> ( (int) ( $a['bytes'] ?? 0 ) );
				$ka        = (string) ( $a['kind'] ?? '' );
				$kb        = (string) ( $b['kind'] ?? '' );
				$rank      = static function ( string $k ): int {
					if ( 'original' === $k ) {
						return 0;
					}
					if ( 'thumb' === $k || str_starts_with( $k, 'thumb' ) ) {
						return 1;
					}
					if ( str_ends_with( $k, '-thumb' ) ) {
						return 2;
					}
					if ( str_starts_with( $k, 'webp' ) || str_starts_with( $k, 'avif' ) ) {
						return 3;
					}
					return 4;
				};
				$ra        = $rank( $ka );
				$rb        = $rank( $kb );
				if ( $ra !== $rb ) {
					return $ra <=> $rb;
				}
				return ( $bytes_cmp ? $bytes_cmp : strcmp( $ka, $kb ) );
			}
		);
		foreach ( $rows as &$row ) {
			$row['count'] = (int) ( $row['count'] ?? 0 );
			$row['bytes'] = (int) ( $row['bytes'] ?? 0 );
			$row['size']  = LucidLab_MPC_Helpers::bytes( $row['bytes'] );
			$row['ext']   = strtoupper( (string) ( $row['ext'] ?? '' ) );
		}
		unset( $row );
		$folders = array_values( (array) ( $state['folders'] ?? array() ) );
		usort( $folders, static fn( $a, $b ) => strnatcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) ) );
		foreach ( $folders as &$folder ) {
			$folder['count'] = (int) ( $folder['count'] ?? 0 );
			$folder['bytes'] = (int) ( $folder['bytes'] ?? 0 );
			$folder['size']  = LucidLab_MPC_Helpers::bytes( $folder['bytes'] );
		}
		unset( $folder );
		return array(
			'folders'      => $folders,
			'total_files'  => (int) ( $state['total_files'] ?? 0 ),
			'total_bytes'  => (int) ( $state['total_bytes'] ?? 0 ),
			'total_size'   => LucidLab_MPC_Helpers::bytes( (int) ( $state['total_bytes'] ?? 0 ) ),
			'skipped_dirs' => (int) ( $state['skipped_dirs'] ?? 0 ),
			'rows'         => $rows,
			'updated'      => time(),
		);
	}
}
