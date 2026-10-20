<?php
/**
 * Lucid Lab Media & Products Cleanup: rename class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab 6 — Rename Images: safely rename an image's files on disk (original + generated sizes +
 * the big-image original), update _wp_attached_file + metadata, and replace the OLD upload path
 * with the NEW one inside post/builder content. Every rename is recorded so it can be undone.
 * The attachment ID never changes, so ID-based references (featured/gallery/term) keep working.
 */
class LucidLab_MPC_Rename {
	use LucidLab_MPC_Rename_Pro_Methods;

		const CRON_HOOK = 'lucidlab_mpc_rename_tick';
	const JOB_OPTION    = 'lucidlab_mpc_rename_job';

	/**
	 * Job.
	 *
	 * @return array
	 */
	public function job(): array {
		return (array) get_option(
			self::JOB_OPTION,
			array(
				'status'  => 'idle',
				'driver'  => 'ajax',
				'offset'  => 0,
				'total'   => 0,
				'renamed' => 0,
				'skipped' => 0,
				'failed'  => 0,
			)
		);
	}

	const LOG_OPTION = 'lucidlab_mpc_rename_log';
	const DB_VERSION = '1';
	const DB_OPTION  = 'lucidlab_mpc_rename_db';

	/**
	 * Matching ids.
	 *
	 * @param string $search Search.
	 *
	 * @return array
	 */
	public function matching_ids( string $search = '' ): array {
		if ( '' !== $search && class_exists( 'LucidLab_MPC_Index' ) ) {
			$ids = LucidLab_MPC_Index::search( $search );
			if ( null !== $ids ) {
				return $ids; }
		}
		global $wpdb;
		$where = '';
		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$where = $wpdb->prepare( " AND (p.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_wp_attached_file' AND m.meta_value LIKE %s))", $like, $like );
		}
		return array_map( 'intval', $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type='attachment' AND p.post_mime_type LIKE 'image/%' $where ORDER BY p.ID DESC" ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where is built with $wpdb->prepare() above; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
	}

	/**
	 * Total images.
	 *
	 * @param string $search Search.
	 *
	 * @return int
	 */
	public function total_images( string $search = '' ): int {
		return count( $this->matching_ids( $search ) ); }

	/**
	 * List images.
	 *
	 * @param int    $offset Offset.
	 * @param int    $limit  Limit.
	 * @param string $search Search.
	 *
	 * @return array
	 */
	public function list_images( int $offset, int $limit, string $search = '' ): array {
		$ids = array_slice( $this->matching_ids( $search ), $offset, $limit );
		$out = array();
		foreach ( $ids as $id ) {
			$rel = (string) get_post_meta( $id, '_wp_attached_file', true );
			if ( '' === $rel ) {
				continue;
			}
			$parent = (int) wp_get_post_parent_id( $id );
			$out[]  = array(
				'id'           => $id,
				'title'        => LucidLab_MPC_Helpers::either( get_the_title( $id ), ( '#' . $id ) ),
				'thumb'        => wp_get_attachment_image_url( $id, array( 80, 80 ) ),
				'full'         => wp_get_attachment_url( $id ),
				'edit'         => get_edit_post_link( $id, 'raw' ),
				'current'      => wp_basename( $rel ),
				'suggest'      => $this->suggest_base( $id, $rel ),
				'tags'         => $this->pattern_tags( $id ),
				'parent_title' => $parent ? get_the_title( $parent ) : '',
				'parent_url'   => $parent ? get_permalink( $parent ) : '',
				'parent_type'  => $parent ? get_post_type( $parent ) : '',
			);
		}
		return $out;
	}

	/**
	 * Suggested new base name (no extension) from the title/alt, slugified and unique-ish.
	 *
	 * @param int $id Id.
	 *
	 * @return array
	 */
	public function pattern_tags( int $id ): array {
		$parent  = (int) wp_get_post_parent_id( $id );
		$title   = $parent ? get_the_title( $parent ) : '';
		$title   = ( $title ? $title : get_the_title( $id ) );
		$product = $parent;
		if ( get_post_type( $parent ) === 'product_variation' ) {
			$product = (int) wp_get_post_parent_id( $parent );
		}
		$terms      = static function ( $object_id, $taxonomy ) {
			$items = wp_get_object_terms(
				$object_id,
				$taxonomy,
				array(
					'orderby' => 'term_id',
					'order'   => 'ASC',
					'fields'  => 'names',
				)
			);
			return is_wp_error( $items ) ? array() : $items;
		};
		$brands     = $product ? $terms( $product, 'product_brand' ) : array();
		$categories = $product ? $terms( $product, get_post_type( $product ) === 'product' ? 'product_cat' : 'category' ) : array();
		return array(
			'{title}'      => ( $title ? $title : ( 'image-' . $id ) ),
			'{sku}'        => (string) LucidLab_MPC_Helpers::either( get_post_meta( $parent, '_sku', true ), get_post_meta( $product, '_sku', true ) ),
			'{brand}'      => $brands[0] ?? '',
			'{brands}'     => implode( '-', $brands ),
			'{category}'   => $categories[0] ?? '',
			'{categories}' => implode( '-', $categories ),
			'{domain}'     => str_replace( '.', '-', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'{date}'       => get_the_date( 'Y-m-d', $id ),
			'{id}'         => (string) $id,
			'{filename}'   => pathinfo( (string) get_post_meta( $id, '_wp_attached_file', true ), PATHINFO_FILENAME ),
		);
	}

	/**
	 * Suggest base.
	 *
	 * @param int    $id      Id.
	 * @param string $rel     Rel.
	 * @param string $pattern Pattern.
	 *
	 * @return string
	 */
	public function suggest_base( int $id, string $rel = '', string $pattern = '{title}' ): string {
		$pattern = trim( $pattern ) !== '' ? $pattern : '{title}';
		return sanitize_title( strtr( $pattern, $this->pattern_tags( $id ) ) );
	}

	/**
	 * Rename with fields.
	 *
	 * @param int    $id       Id.
	 * @param string $name     Name.
	 * @param bool   $title    Title.
	 * @param bool   $alt      Alt.
	 * @param bool   $filename Filename.
	 *
	 * @return array
	 */
	public function rename_with_fields( int $id, string $name, bool $title = false, bool $alt = false, bool $filename = true ): array {
		if ( ! $filename && ! $title && ! $alt ) {
			return array(
				'ok'      => false,
				'message' => __( 'Choose filename, title and/or alt text.', 'lucidlab-mpc-cleanup' ),
			);
		}
		if ( $filename ) {
			$result = $this->rename( $id, $name, $title );
		} else {
			$attached = (string) get_post_meta( $id, '_wp_attached_file', true );
			$result   = array(
				'ok'      => true,
				'skipped' => true,
				'message' => __( 'Filename unchanged.', 'lucidlab-mpc-cleanup' ),
				'new'     => $attached ? wp_basename( $attached ) : '',
			);
		}
		if ( ! empty( $result['ok'] ) ) {
			$stem = pathinfo( $filename ? (string) ( $result['new'] ?? $name ) : $name, PATHINFO_FILENAME );
			$text = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::title_from_slug( $stem ) : ucwords( str_replace( array( '-', '_' ), ' ', $stem ) );
			if ( $title && ( ! $filename || ! empty( $result['skipped'] ) ) && '' !== $text ) {
				$updated = wp_update_post(
					array(
						'ID'         => $id,
						'post_title' => wp_slash( $text ),
					),
					true
				);
				if ( is_wp_error( $updated ) || ! $updated ) {
					$result['message'] .= ' ' . __( 'The image title could not be saved; edit it in Media Library.', 'lucidlab-mpc-cleanup' );
				} else {
					$result['skipped'] = false;
					if ( ! $filename ) {
						$result['message'] = __( 'Title updated.', 'lucidlab-mpc-cleanup' );
					}
				}
			}
			if ( $alt && '' !== $text ) {
				$changed = LucidLab_MPC_AI::change_alt( $id, false, $text );
				if ( is_wp_error( $changed ) ) {
					$result['message'] .= ' ' . sprintf( /* translators: %s: error message. */ __( 'Alt text: %s', 'lucidlab-mpc-cleanup' ), $changed->get_error_message() );
				} elseif ( ! $filename ) {
					$result['skipped'] = false;
					$result['message'] = $title ? __( 'Title and alt text updated.', 'lucidlab-mpc-cleanup' ) : __( 'Alt text updated.', 'lucidlab-mpc-cleanup' );
				}
			}
		}
		return $result;
	}

	/**
	 * Perform a rename. Returns ['ok'=>bool,'message'=>string,'new'=>string].
	 *
	 * @param int    $id           Id.
	 * @param string $new_base     New base.
	 * @param bool   $update_title Update title.
	 *
	 * @return array
	 */
	public function rename( int $id, string $new_base, bool $update_title = false ): array {
		if ( 'attachment' !== get_post_type( $id ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Not an attachment.', 'lucidlab-mpc-cleanup' ),
			);
		}
		$new_base = sanitize_file_name( $new_base );
		$new_base = pathinfo( $new_base, PATHINFO_FILENAME ); // Drop any extension the user typed.
		$new_base = strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '-', $new_base ) );
		$new_base = trim( (string) preg_replace( '/-+/', '-', $new_base ), '-' );
		if ( '' === $new_base ) {
			return array(
				'ok'      => false,
				'message' => __( 'Please enter a valid new name.', 'lucidlab-mpc-cleanup' ),
			);
		}

		$attached = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' === $attached ) {
			return array(
				'ok'      => false,
				'message' => __( 'This image has no file on record.', 'lucidlab-mpc-cleanup' ),
			);
		}
		$basedir = LucidLab_MPC_Helpers::uploads_basedir();

		$meta          = wp_get_attachment_metadata( $id );
		$meta          = is_array( $meta ) ? $meta : array();
		$old_main_name = wp_basename( $attached );
		$family_old    = $this->family_stem( $attached );
		if ( $new_base === $family_old ) {
			return array(
				'ok'      => true,
				'skipped' => true,
				'message' => __( 'Skipped — filename is unchanged.', 'lucidlab-mpc-cleanup' ),
				'new'     => $old_main_name,
			);
		}
		$quarantine     = new LucidLab_MPC_Quarantine();
		$requested_base = $new_base;
		$suffix         = 0;
		$moves          = array();
		$new_main_name  = $old_main_name;
		do {
			$new_base = $requested_base . ( $suffix ? '-' . $suffix : '' );
			// Original, scaled file, thumbnail sizes, full-size original, plus WebP and AVIF copies.
			$moves         = $this->planned_moves( $attached, $meta, $new_base );
			$new_main_name = wp_basename( (string) ( $moves[ $this->norm_rel( $attached ) ] ?? $old_main_name ) );
			$conflict      = false;
			foreach ( $moves as $old => $new ) {
				if ( $old !== $new && ( file_exists( $basedir . $new ) || $quarantine->holds( $new ) ) ) {
					$conflict = true;
					break;
				}
			}
			++$suffix;
		} while ( $conflict );

		if ( $new_main_name === $old_main_name ) {
			return array(
				'ok'      => true,
				'skipped' => true,
				'message' => __( 'Skipped — filename is unchanged.', 'lucidlab-mpc-cleanup' ),
				'new'     => $old_main_name,
			);
		}

		// Originals, WebP and AVIF already in quarantine are renamed to the same new name.
		$main_rel    = $this->norm_rel( $attached );
		$quarantined = array();
		foreach ( $moves as $old => $new ) {
			if ( $old !== $new && ! is_file( $basedir . $old ) && $quarantine->holds( $old ) ) {
				$quarantined[ $old ] = $new;
			}
		}
		if ( ! is_file( $basedir . $main_rel ) && ! isset( $quarantined[ $main_rel ] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The image file is missing from uploads and quarantine. Nothing was renamed.', 'lucidlab-mpc-cleanup' ),
			);
		}

		// Perform the moves; roll back on any failure.
		$done     = array();
		$rollback = static function () use ( &$done, $basedir ) {
			foreach ( array_reverse( $done, true ) as $o => $n ) {
				rename( $basedir . $n, $basedir . $o ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			}
		};
		foreach ( $moves as $old => $new ) {
			if ( $old === $new ) {
				continue;
			}
			$src = $basedir . $old;
			$dst = $basedir . $new;
			if ( ! is_file( $src ) ) {
				continue; // Missing size file or copy — skip, not fatal.
			}
			if ( rename( $src, $dst ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				$done[ $old ] = $new;
			} else {
				$rollback();
				return array(
					'ok'      => false,
					'message' => __( 'Could not rename a file (check folder permissions). No changes were made.', 'lucidlab-mpc-cleanup' ),
				);
			}
		}
		$quarantine_failed = __( 'Could not rename a matching file in quarantine. No changes were made.', 'lucidlab-mpc-cleanup' );
		$quarantine_done   = $quarantined ? $quarantine->rename_held( $quarantined ) : array();
		if ( count( $quarantine_done ) !== count( $quarantined ) ) {
			$quarantine->rename_held( array_flip( $quarantine_done ) );
			$rollback();
			return array(
				'ok'      => false,
				'message' => $quarantine_failed,
			);
		}

		// Update DB: _wp_attached_file + metadata.
		$new_attached = (string) ( $moves[ $this->norm_rel( $attached ) ] ?? $attached );
		update_post_meta( $id, '_wp_attached_file', $new_attached );

		$old_meta     = $meta;
		$meta['file'] = $new_attached;
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $k => $size ) {
				if ( ! empty( $size['file'] ) ) {
					$meta['sizes'][ $k ]['file'] = $this->replace_stem( wp_basename( (string) $size['file'] ), $family_old, $new_base );
				}
			}
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$meta['original_image'] = $this->replace_stem( wp_basename( (string) $meta['original_image'] ), $family_old, $new_base );
		}
		wp_update_attachment_metadata( $id, $meta );

		// Replace old upload paths, including WebP and AVIF, in post/builder content.
		$url_pairs = array();
		foreach ( $moves as $old => $new ) {
			if ( $old !== $new ) {
				$url_pairs[] = array( $old, $new );
			}
		}
		$content_changed = $this->replace_paths_in_content( $url_pairs );

		// Record the normalized attachment title with the same undo entry.
		$old_title    = (string) get_post_field( 'post_title', $id, 'raw' );
		$new_title    = $update_title ? ucwords( str_replace( array( '-', '_' ), ' ', pathinfo( $new_main_name, PATHINFO_FILENAME ) ) ) : $old_title;
		$title_failed = false;
		if ( $update_title ) {
			global $wpdb;
			// Direct title update keeps post_modified so Media Library sort order is unchanged.
			$title_failed = false === $wpdb->update( $wpdb->posts, array( 'post_title' => $new_title ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			clean_post_cache( $id );
			if ( $title_failed ) {
				$new_title = $old_title;
			}
		}
		// Record for undo.
		$this->log_add(
			array(
				'ts'           => function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
				'id'           => $id,
				'title'        => LucidLab_MPC_Helpers::either( get_the_title( $id ), ( '#' . $id ) ),
				'from'         => $old_main_name,
				'to'           => $new_main_name,
				'moves'        => $done,
				'files'        => $moves,
				'quarantine'   => $quarantine_done,
				'old_attached' => $attached,
				'old_title'    => $old_title,
				'new_title'    => $new_title,
				'old_meta'     => $old_meta,
				'new_meta'     => $meta,
				'pairs'        => $url_pairs,
				'posts'        => $content_changed,
			)
		);

		/* translators: 1: attachment ID, 2: old filename, 3: new filename, 4: files renamed, 5: files quarantined, 6: posts updated. */
		LucidLab_MPC_Logger::log( sprintf( __( 'Renamed attachment #%1$d: %2$s → %3$s (%4$d file(s), %5$d quarantined file(s), %6$d post(s) updated).', 'lucidlab-mpc-cleanup' ), $id, $old_main_name, $new_main_name, count( $done ), count( $quarantine_done ), count( $content_changed ) ) );

		/* translators: %s is the new image filename. */
		return array(
			'ok'      => true,
			'message' => sprintf( /* translators: %s: new file name. */ __( 'Renamed to %s.', 'lucidlab-mpc-cleanup' ), $new_main_name ) . ( $title_failed ? ' ' . __( 'The image title could not be saved; edit it in Media Library.', 'lucidlab-mpc-cleanup' ) : '' ),
			'new'     => $new_main_name,
		);
	}

	/**
	 * Norm rel.
	 *
	 * @param string $rel Rel.
	 *
	 * @return string
	 */
	private function norm_rel( string $rel ): string {
		return ltrim( str_replace( '\\', '/', $rel ), '/' );
	}

	/**
	 * Filename stem shared by the original, its scaled file, and its thumbnail sizes.
	 *
	 * @param string $rel Rel.
	 *
	 * @return string
	 */
	private function family_stem( string $rel ): string {
		$rel    = $this->norm_rel( $rel );
		$source = LucidLab_MPC_Helpers::generated_copy_source( $rel );
		$name   = '' !== $source ? wp_basename( $source ) : wp_basename( $rel );
		$stem   = pathinfo( $name, PATHINFO_FILENAME );
		if ( str_ends_with( $stem, '-scaled' ) ) {
			$stem = substr( $stem, 0, -7 );
		}
		return $stem;
	}

	/**
	 * Rel dir.
	 *
	 * @param string $rel Rel.
	 *
	 * @return string
	 */
	private function rel_dir( string $rel ): string {
		$dir = trim( str_replace( '\\', '/', dirname( $this->norm_rel( $rel ) ) ), '/' );
		return ( '' === $dir || '.' === $dir ) ? '' : $dir . '/';
	}

	/**
	 * Old upload path => new upload path for the original, sizes, full-size file, and WebP/AVIF copies.
	 *
	 * @param string              $attached Attached.
	 * @param array<string,mixed> $meta     Meta.
	 * @param string              $new_base New base.
	 *
	 * @return array<string,string>
	 */
	private function planned_moves( string $attached, array $meta, string $new_base ): array {
		$attached = $this->norm_rel( $attached );
		$family   = $this->family_stem( $attached );
		$prefix   = $this->rel_dir( $attached );
		$rels     = array( $attached );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$rels[] = $prefix . wp_basename( (string) $size['file'] );
				}
			}
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$rels[] = $prefix . wp_basename( (string) $meta['original_image'] );
		}
		$source = LucidLab_MPC_Helpers::generated_copy_source( $attached );
		if ( '' !== $source ) {
			$rels[]        = $source;
			$source_prefix = $this->rel_dir( $source );
			$siblings      = array();
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$siblings[] = (string) $size['file'];
					}
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$siblings[] = (string) $meta['original_image'];
			}
			foreach ( $siblings as $file ) {
				$sibling = $prefix . wp_basename( $file );
				$from    = LucidLab_MPC_Helpers::generated_copy_source( $sibling );
				$rels[]  = '' !== $from ? $from : $source_prefix . wp_basename( $file );
			}
		}
		$moves = array();
		foreach ( array_unique( $rels ) as $rel ) {
			$rel = $this->norm_rel( (string) $rel );
			if ( '' === $rel ) {
				continue;
			}
			$moves[ $rel ] = $this->rel_dir( $rel ) . $this->replace_stem( wp_basename( $rel ), $family, $new_base );
		}
		return $this->with_copies( $moves );
	}

	/**
	 * Replace stem.
	 *
	 * @param string $filename Filename.
	 * @param string $old_stem Old stem.
	 * @param string $new_stem New stem.
	 *
	 * @return string
	 */
	private function replace_stem( string $filename, string $old_stem, string $new_stem ): string {
		if ( '' === $old_stem || '' === $new_stem || ! str_starts_with( $filename, $old_stem ) ) {
			return $filename;
		}
		$next = substr( $filename, strlen( $old_stem ), 1 );
		if ( '' !== $next && '.' !== $next && '-' !== $next ) {
			return $filename;
		}
		return $new_stem . substr( $filename, strlen( $old_stem ) );
	}

	/**
	 * Replace each [old_rel => new_rel] upload path substring inside post_content. Returns post IDs.
	 *
	 * @param array $pairs Pairs.
	 *
	 * @return array
	 */
	private function replace_paths_in_content( array $pairs ): array {
		global $wpdb;
		$pairs = array_values(
			array_filter(
				$pairs,
				static function ( $pair ): bool {
					return is_array( $pair ) && '' !== (string) ( $pair[0] ?? '' ) && (string) ( $pair[0] ?? '' ) !== (string) ( $pair[1] ?? '' );
				}
			)
		);
		if ( empty( $pairs ) ) {
			return array();
		}
		usort(
			$pairs,
			static function ( $a, $b ): int {
				return strlen( (string) $b[0] ) <=> strlen( (string) $a[0] );
			}
		);
		$where = array();
		$likes = array();
		foreach ( $pairs as $pair ) {
			$where[] = 'post_content LIKE %s';
			$likes[] = '%' . $wpdb->esc_like( (string) $pair[0] ) . '%';
		}
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE (" . implode( ' OR ', $where ) . ") AND post_status NOT IN ('trash','auto-draft')", $likes ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where is only LIKE %s fragments; the values are the prepare() arguments; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$changed = array();
		foreach ( (array) $rows as $p ) {
			$c  = (string) $p->post_content;
			$nc = $c;
			foreach ( $pairs as $pair ) {
				$nc = str_replace( $pair[0], $pair[1], $nc );
			}
			if ( $nc !== $c ) {
				wp_update_post(
					array(
						'ID'           => (int) $p->ID,
						'post_content' => $nc,
					)
				);
				$changed[] = (int) $p->ID;
			}
		}
		return $changed;
	}

	/**
	 * Add the WebP/AVIF copies (uploads/lucidlab-mpc-cleanup/{webp,avif}, the older uploads/webp and uploads/avif, and legacy sidecars) of each moved file.
	 *
	 * @param array<string,string> $pairs Old_rel => new_rel.
	 *
	 * @return array<string,string>
	 */
	private function with_copies( array $pairs ): array {
		$out = $pairs;
		foreach ( $pairs as $old => $new ) {
			if ( $old === $new ) {
				continue;
			}
			$source_old = preg_match( '/\.(jpe?g|png|gif)$/i', (string) $old ) ? (string) $old : LucidLab_MPC_Helpers::generated_copy_source( (string) $old );
			$source_new = preg_match( '/\.(jpe?g|png|gif)$/i', (string) $new ) ? (string) $new : LucidLab_MPC_Helpers::generated_copy_source( (string) $new );
			if ( '' === $source_old || '' === $source_new ) {
				continue;
			}
			$out[ $source_old ] = $source_new;
			foreach ( array( 'webp', 'avif' ) as $fmt ) {
				foreach ( LucidLab_MPC_Helpers::generated_copy_candidates( $source_old, $fmt ) as $i => $candidate ) {
					$targets = LucidLab_MPC_Helpers::generated_copy_candidates( $source_new, $fmt );
					if ( isset( $targets[ $i ] ) ) {
						$out[ $candidate ] = $targets[ $i ];
					}
				}
			}
		}
		return $out;
	}

	/* ---------- undo log ---------- */

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lucidlab_mpc_rename_log';
	}

	/**
	 * Create the history table and move entries from the former option into it.
	 */
	public static function install(): void {
		global $wpdb;
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		require_once LucidLab_MPC_Helpers::admin_dir() . 'includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ts datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(255) NOT NULL DEFAULT '',
  from_name varchar(255) NOT NULL DEFAULT '',
  to_name varchar(255) NOT NULL DEFAULT '',
  posts int(10) unsigned NOT NULL DEFAULT 0,
  data longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY attachment_id (attachment_id)
) " . $wpdb->get_charset_collate() . ';'
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			return;
		}
		$legacy = get_option( self::LOG_OPTION );
		if ( is_array( $legacy ) ) {
			foreach ( $legacy as $entry ) {
				if ( is_array( $entry ) ) {
					self::insert_entry( $entry );
				}
			}
			delete_option( self::LOG_OPTION );
		}
		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Insert entry.
	 *
	 * @param array $entry Entry.
	 *
	 * @return bool
	 */
	private static function insert_entry( array $entry ): bool {
		global $wpdb;
		return false !== $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			self::table(),
			array(
				'ts'            => (string) ( $entry['ts'] ?? gmdate( 'Y-m-d H:i:s' ) ),
				'attachment_id' => (int) ( $entry['id'] ?? 0 ),
				'title'         => mb_substr( (string) ( $entry['title'] ?? '' ), 0, 255 ),
				'from_name'     => mb_substr( (string) ( $entry['from'] ?? '' ), 0, 255 ),
				'to_name'       => mb_substr( (string) ( $entry['to'] ?? '' ), 0, 255 ),
				'posts'         => count( (array) ( $entry['posts'] ?? array() ) ),
				'data'          => serialize( $entry ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Undo log entry; unserialize disallows classes.
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * One page of rename history, newest first.
	 *
	 * @param string $search Search.
	 * @param int    $page   Page.
	 * @param int    $per    Per.
	 *
	 * @return array{rows:array<int,array>,total:int}
	 */
	public function log_page( string $search, int $page, int $per ): array {
		global $wpdb;
		self::install();
		$table = self::table();
		$where = '';
		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$where = $wpdb->prepare( 'WHERE attachment_id = %d OR title LIKE %s OR from_name LIKE %s OR to_name LIKE %s OR ts LIKE %s', (int) $search, $like, $like, $like, $like );
		}
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where}", $table ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where is built with $wpdb->prepare() above; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, ts, attachment_id, title, from_name, to_name, posts FROM %i {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $table, $per, max( 0, ( $page - 1 ) * $per ) ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where is built with $wpdb->prepare() above; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'row'   => (int) $r->id,
				'ts'    => $r->ts,
				'id'    => (int) $r->attachment_id,
				'title' => $r->title,
				'from'  => $r->from_name,
				'to'    => $r->to_name,
				'posts' => (int) $r->posts,
			);
		}
		return array(
			'rows'  => $out,
			'total' => $total,
		);
	}

	/**
	 * Log add.
	 *
	 * @param array $entry Entry.
	 */
	private function log_add( array $entry ): void {
		self::install();
		if ( ! self::insert_entry( $entry ) ) {
			LucidLab_MPC_Logger::error( 'rename_log_write_failed', array( 'id' => (int) ( $entry['id'] ?? 0 ) ) );
		}
	}

	/**
	 * Undo the rename stored in the given history row.
	 *
	 * @param int $row Row.
	 *
	 * @return array
	 */
	public function undo( int $row ): array {
		global $wpdb;
		self::install();
		$data  = $row > 0 ? $wpdb->get_var( $wpdb->prepare( 'SELECT data FROM %i WHERE id = %d', self::table(), $row ) ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$entry = is_string( $data ) ? ( is_serialized( $data ) ? unserialize( $data, array( 'allowed_classes' => false ) ) : false ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Undo log entry; unserialize disallows classes.
		if ( ! is_array( $entry ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Rename record not found.', 'lucidlab-mpc-cleanup' ),
			);
		}
		$id = (int) ( $entry['id'] ?? 0 );

		$basedir = LucidLab_MPC_Helpers::uploads_basedir();
		// Reverse file moves, including WebP/AVIF copies made after the rename.
		$files = (array) ( $entry['files'] ?? $entry['moves'] ?? array() );
		$moves = array_merge( $this->with_copies( $files ), (array) ( $entry['moves'] ?? array() ) );
		foreach ( array_reverse( $moves, true ) as $old => $new ) {
			if ( $old !== $new && is_file( $basedir . $new ) && ! file_exists( $basedir . $old ) ) {
				rename( $basedir . $new, $basedir . $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			}
		}
		$forward = array();
		foreach ( array( $this->with_copies( $files ), (array) ( $entry['moves'] ?? array() ), (array) ( $entry['quarantine'] ?? array() ) ) as $map ) {
			foreach ( $map as $old => $new ) {
				if ( '' !== (string) $old && (string) $old !== (string) $new ) {
					$forward[ (string) $old ] = (string) $new;
				}
			}
		}
		if ( $forward ) {
			( new LucidLab_MPC_Quarantine() )->rename_held( array_flip( $forward ) );
		}
		// Restore DB.
		if ( $id > 0 && ! empty( $entry['old_attached'] ) ) {
			update_post_meta( $id, '_wp_attached_file', $entry['old_attached'] );
			if ( is_array( $entry['old_meta'] ?? null ) ) {
				wp_update_attachment_metadata( $id, $entry['old_meta'] );
			}
		}
		if ( isset( $entry['old_title'], $entry['new_title'] ) && get_post_field( 'post_title', $id, 'raw' ) === $entry['new_title'] ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_title' => (string) $entry['old_title'] ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			clean_post_cache( $id );
		}
		// Reverse content path replacements.
		$rev = array();
		foreach ( (array) ( $entry['pairs'] ?? array() ) as $pair ) {
			$rev[] = array( $pair[1], $pair[0] );
		}
		$this->replace_paths_in_content( $rev );

		$wpdb->delete( self::table(), array( 'id' => $row ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.

		/* translators: 1: attachment ID, 2: previous filename, 3: filename that was undone. */
		LucidLab_MPC_Logger::log( sprintf( __( 'Undid rename of attachment #%1$d (%2$s → %3$s).', 'lucidlab-mpc-cleanup' ), $id, $entry['from'] ?? '', $entry['to'] ?? '' ) );
		return array(
			'ok'      => true,
			'message' => __( 'Rename undone.', 'lucidlab-mpc-cleanup' ),
		);
	}
}
