<?php
/**
 * Lucid Lab Media & Products Cleanup: dupcontent class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab 5 — Byte Identical Images: find BYTE-IDENTICAL images (SHA-256 of the original file),
 * show everywhere each copy is attached/used (featured, product gallery, term thumbnails,
 * post/builder content), then merge duplicates into one survivor by repointing every
 * reference and quarantining the extra attachments (fully restorable — no permanent delete).
 *
 * Safe reference scope: _thumbnail_id, _product_image_gallery, term meta thumbnail_id,
 * and numeric image IDs embedded in post_content / builder shortcodes / Gutenberg blocks.
 */
class LucidLab_MPC_DupContent {
	use LucidLab_MPC_DupContent_Pro_Methods;

	/**
	 * Last cursor.
	 *
	 * @var int
	 */
	private int $last_cursor = 0;

	/** Attribute names that carry attachment IDs in Flatsome/builder shortcodes. */
	const CONTENT_ATTRS = 'id|img|bg|image|ids|logo|icon|thumb|thumbnail|image_id|bg_image|mobile_bg|md_bg|lg_bg';

	/**
	 * Total images.
	 *
	 * @return int
	 */
	public function total_images(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
	}

	/**
	 * Image ids.
	 *
	 * @param int $after_id After id.
	 *
	 * @return array
	 */
	protected function image_ids( int $after_id ): array {
		global $wpdb;
		return array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d",
					$wpdb->esc_like( 'image/' ) . '%',
					(int) $after_id,
					(int) LucidLab_MPC_Settings::get( 'batch_db' ),
				)
			)
		);
	}

	/**
	 * Original (pre-scaled) file path when available, else the attached file.
	 *
	 * @param int $id Id.
	 *
	 * @return string
	 */
	public function original_path( int $id ): string {
		$path = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $id ) : '';
		if ( ! $path ) {
			$path = get_attached_file( $id );
		}
		return (string) $path;
	}

	/**
	 * Identity of the physical file behind a path: device and inode when the file system reports them
	 * (so hard links count as one file), else the resolved path. Empty when the file is missing.
	 *
	 * @param string $path Path.
	 *
	 * @return string
	 */
	public static function file_identity( string $path ): string {
		if ( '' === $path || ! is_file( $path ) ) {
			return '';
		}
		$stat = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file that disappears between is_file() and stat() falls back to its path.
		if ( is_array( $stat ) && ! empty( $stat['ino'] ) ) {
			return 'i:' . (int) $stat['dev'] . ':' . (int) $stat['ino'];
		}
		$real = realpath( $path );
		return 'p:' . LucidLab_MPC_Helpers::norm_path( false !== $real ? $real : $path );
	}

	/**
	 * Bytes a merge or removal frees for each ID, counting every physical file once: a record that
	 * uses the same file as $keep_id or as an earlier record frees nothing.
	 *
	 * @param array    $ids     Attachment IDs that would be removed.
	 * @param int      $keep_id Record that stays (0 for none).
	 * @param callable $path_of Maps an ID to its file path.
	 *
	 * @return array<int,array{bytes:int,same_as:int}> same_as is the record that keeps the file, or 0.
	 */
	public static function freed_bytes( array $ids, int $keep_id, callable $path_of ): array {
		$owner = array();
		if ( $keep_id > 0 ) {
			$keep_identity = self::file_identity( (string) $path_of( $keep_id ) );
			if ( '' !== $keep_identity ) {
				$owner[ $keep_identity ] = $keep_id;
			}
		}
		$out = array();
		foreach ( $ids as $id ) {
			$id       = (int) $id;
			$path     = (string) $path_of( $id );
			$identity = self::file_identity( $path );
			if ( '' === $identity ) {
				$out[ $id ] = array(
					'bytes'   => 0,
					'same_as' => 0,
				);
				continue;
			}
			if ( isset( $owner[ $identity ] ) ) {
				$out[ $id ] = array(
					'bytes'   => 0,
					'same_as' => (int) $owner[ $identity ],
				);
				continue;
			}
			$owner[ $identity ] = $id;
			$out[ $id ]         = array(
				'bytes'   => (int) filesize( $path ),
				'same_as' => 0,
			);
		}
		return $out;
	}

	/**
	 * Hash one batch. $map: sha => ['ids'=>int[], 'bytes'=>int]. Returns rows processed.
	 *
	 * @param int   $after_id After id.
	 * @param array &$map      Map.
	 *
	 * @return int
	 */
	public function hash_step( int $after_id, array &$map ): int {
		$ids               = $this->image_ids( $after_id );
		$this->last_cursor = $ids ? (int) end( $ids ) : $after_id;
		if ( ! $ids ) {
			return 0;
		}
		if ( function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $ids );
		}
		$paths = array();
		foreach ( $ids as $id ) {
			if ( class_exists( 'LucidLab_MPC_Exclusions', false ) && LucidLab_MPC_Exclusions::skip_attachment( $id ) ) {
				continue;
			}
			$path = $this->original_path( $id );
			if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$paths[ $id ] = $path;
		}
		if ( class_exists( 'LucidLab_MPC_Scan_Profile', false ) ) {
			LucidLab_MPC_Scan_Profile::count( count( $paths ) );
		}
		foreach ( LucidLab_MPC_Hash_Cache::hashes( $paths ) as $id => $row ) {
			$h = $row['hash'];
			if ( empty( $map[ $h ] ) ) {
				$map[ $h ] = array(
					'ids'   => array(),
					'bytes' => (int) $row['bytes'],
				);
			}
			$map[ $h ]['ids'][] = $id;
		}
		return count( $ids );
	}

	/**
	 * Last cursor.
	 *
	 * @return int
	 */
	public function last_cursor(): int {
		return $this->last_cursor; }

	/**
	 * Build displayable duplicate groups (2+ identical files) with references + survivor pick.
	 *
	 * @param array $map Map.
	 *
	 * @return array
	 */
	public function build_groups( array $map ): array {
		$groups = array();
		$dupids = array();
		foreach ( $map as $hash => $g ) {
			$mids = array_values( array_unique( array_map( 'intval', (array) ( $g['ids'] ?? array() ) ) ) );
			if ( count( $mids ) < 2 ) {
				continue;
			}
			sort( $mids );
			$groups[] = array(
				'hash'  => (string) $hash,
				'bytes' => (int) ( $g['bytes'] ?? 0 ),
				'ids'   => $mids,
			);
			foreach ( $mids as $id ) {
				$dupids[] = $id;
			}
		}

		if ( empty( $groups ) ) {
			return array(
				'type'          => 'dupcontent',
				'groups'        => array(),
				'group_count'   => 0,
				'dupe_count'    => 0,
				'reclaim_bytes' => 0,
			);
		}

		$refindex    = $this->reference_index( $dupids );
		$out         = array();
		$dupe_count  = 0;
		$file_copies = 0;
		$same_file   = 0;
		$reclaim     = 0;
		$path_of     = array( $this, 'original_path' );

		foreach ( $groups as $g ) {
			$members    = array();
			$total_refs = 0;
			foreach ( $g['ids'] as $id ) {
				$refs        = $refindex[ $id ] ?? array();
				$total_refs += count( $refs );
				$members[]   = array(
					'id'        => $id,
					'title'     => LucidLab_MPC_Helpers::either( get_the_title( $id ), ( '#' . $id ) ),
					'thumb'     => wp_get_attachment_image_url( $id, array( 96, 96 ) ),
					'full'      => wp_get_attachment_url( $id ),
					'edit'      => get_edit_post_link( $id, 'raw' ),
					'bytes'     => (int) $g['bytes'],
					'ref_count' => count( $refs ),
					'refs'      => $refs,
				);
			}
			// Survivor = most references, tie-break oldest (lowest) ID.
			usort(
				$members,
				static function ( $a, $b ) {
					return LucidLab_MPC_Helpers::either( ( $b['ref_count'] <=> $a['ref_count'] ), ( $a['id'] <=> $b['id'] ) );
				}
			);
			$survivor = $members[0]['id'];
			// Records that point at the same file on disk are not copies: merging them removes the extra
			// media record but frees no space, and the file stays for the records that remain.
			$freed       = self::freed_bytes( array_values( array_diff( array_column( $members, 'id' ), array( $survivor ) ) ), $survivor, $path_of );
			$group_files = 1;
			$group_same  = 0;
			$group_bytes = 0;
			foreach ( $members as &$m ) {
				$m['is_survivor'] = ( $m['id'] === $survivor );
				$m['same_file']   = 0;
				if ( ! $m['is_survivor'] ) {
					++$dupe_count;
					$m['same_file'] = (int) ( $freed[ $m['id'] ]['same_as'] ?? 0 );
					if ( $m['same_file'] ) {
						++$group_same;
					} else {
						++$group_files;
						$group_bytes += (int) $g['bytes'];
					}
				}
				unset( $m['refs'] );
			}
			unset( $m );
			$file_copies += $group_files - 1;
			$same_file   += $group_same;
			$reclaim     += $group_bytes;

			// Add warning for groups with many references.
			$warning = '';
			if ( $total_refs > 50 ) {
				/* translators: %d is the number of references to images in this duplicate group. */
				$warning = sprintf( __( 'This group has %d references across all images. Review carefully before merging.', 'lucidlab-mpc-cleanup' ), $total_refs );
			} elseif ( $total_refs > 20 ) {
				/* translators: %d is the number of references to images in this duplicate group. */
				$warning = sprintf( __( 'This group has %d references. Ensure you understand all usages before merging.', 'lucidlab-mpc-cleanup' ), $total_refs );
			}

			$out[] = array(
				'hash'       => $g['hash'],
				'bytes'      => (int) $g['bytes'],
				'survivor'   => $survivor,
				'members'    => $members,
				'count'      => count( $members ),
				'files'      => $group_files,
				'same_file'  => $group_same,
				'reclaim'    => $group_bytes,
				'total_refs' => $total_refs,
				'warning'    => $warning,
			);
		}

		// Biggest reclaim first, then the most records.
		usort( $out, static fn( $a, $b ) => array( $b['reclaim'], $b['count'] ) <=> array( $a['reclaim'], $a['count'] ) );

		return array(
			'type'              => 'dupcontent',
			'groups'            => $out,
			'group_count'       => count( $out ),
			'dupe_count'        => $dupe_count,
			'file_copies'       => $file_copies,
			'same_file_records' => $same_file,
			'reclaim_bytes'     => $reclaim,
		);
	}

	/**
	 * Synchronous full scan (CSV export).
	 *
	 * @return array
	 */
	public function scan_full(): array {
		$map    = array();
		$step   = (int) LucidLab_MPC_Settings::get( 'batch_db' );
		$cursor = 0;
		do {
			$n      = $this->hash_step( $cursor, $map );
			$cursor = $this->last_cursor;
		} while ( $n === $step );
		return $this->build_groups( $map );
	}

	/* ---------- reference index ---------- */

	/**
	 * Map attachment IDs to references from the same index used by safety checks.
	 *
	 * @param array $ids Ids.
	 *
	 * @return array
	 */
	public function reference_index( array $ids ): array {
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$index = array_fill_keys( $ids, array() );
		if ( ! $ids ) {
			return $index; }
		$names  = array();
		$parser = new LucidLab_MPC_Orphans();
		foreach ( $ids as $id ) {
			foreach ( $parser->attachment_basenames( $id ) as $name ) {
				$names[ strtolower( $name ) ][ $id ] = true; }
		}
		$file      = LucidLab_MPC_Reference_Index::SHARED;
		$temporary = ! LucidLab_MPC_Work::exists( $file );
		if ( $temporary ) {
			$file = 'refs-preview-' . bin2hex( random_bytes( 8 ) ) . '.jsonl';
		}
		$seen = array();
		try {
			if ( $temporary ) {
				LucidLab_MPC_Reference_Index::build( $file );
			}
			LucidLab_MPC_Work::each(
				$file,
				static function ( array $row ) use ( &$index, &$seen, $names ) {
					$matches = array();
					foreach ( (array) ( $row['i'] ?? array() ) as $id ) {
						if ( isset( $index[ (int) $id ] ) ) {
										$matches[ (int) $id ] = 'ID'; }
					}
					foreach ( (array) ( $row['b'] ?? array() ) as $name ) {
						foreach ( (array) ( $names[ strtolower( (string) $name ) ] ?? array() ) as $id => $_ ) {
							if ( ! isset( $matches[ $id ] ) ) {
												$matches[ $id ] = __( 'filename match', 'lucidlab-mpc-cleanup' ); }
						}
					}
					if ( ! $matches ) {
						return; }
					$source = (string) ( $row['s'] ?? '' );
					$owner  = (int) ( 'm' === $source ? ( $row['p'] ?? 0 ) : ( $row['k'] ?? 0 ) );
					$edit   = '';
					$view   = '';
					if ( 'p' === $source || 'm' === $source ) {
						$post  = get_post( $owner );
						$label = ( $post ? $post->post_title : '#' . $owner ) . ' (#' . $owner . ')';
						if ( $post ) {
							$label .= ' — ' . $post->post_type; }
						$edit = get_edit_post_link( $owner, 'raw' );
						$view = get_permalink( $owner );
						$type = 'm' === $source ? sprintf( /* translators: %s: custom field key. */ __( 'custom field: %s', 'lucidlab-mpc-cleanup' ), (string) ( $row['mk'] ?? '' ) ) : 'content / excerpt';
					} elseif ( 't' === $source ) {
						$term  = get_term( $owner );
						$label = 'Term #' . $owner;
						$type  = __( 'taxonomy description / custom field', 'lucidlab-mpc-cleanup' );
						if ( $term && ! is_wp_error( $term ) ) {
							$label = $term->name . ' (#' . $owner . ') — ' . $term->taxonomy;
							$edit  = get_edit_term_link( $owner, $term->taxonomy );
							$view  = get_term_link( $term );
							if ( is_wp_error( $view ) ) {
								$view = ''; }
						}
					} elseif ( 'o' === $source ) {
						$label = __( 'Saved site setting #', 'lucidlab-mpc-cleanup' ) . $owner;
						$type  = 'setting';
					} elseif ( 'x' === $source ) {
						$label = __( 'Site identity, widgets or other static references', 'lucidlab-mpc-cleanup' );
						$type  = __( 'site setting / widget', 'lucidlab-mpc-cleanup' );
					} else {
						return; }
					foreach ( $matches as $id => $match ) {
						$uncertain_meta = 'm' === $source && ! in_array( (string) ( $row['mk'] ?? '' ), array( '_thumbnail_id', '_product_image_gallery' ), true );
						$warning        = 'ID' !== $match ? ' ' . __( '(filename match; verify before merging)', 'lucidlab-mpc-cleanup' ) : ( $uncertain_meta ? ' ' . __( '(possible reference; verify before merging)', 'lucidlab-mpc-cleanup' ) : '' );
						$key            = $source . ':' . $owner . ':' . $type;
						if ( isset( $seen[ $id ][ $key ] ) ) {
							continue; }
						$seen[ $id ][ $key ] = true;
						$index[ $id ][]      = array(
							'type'  => $type . $warning,
							'label' => $label,
							'edit'  => ( $edit ? $edit : '' ),
							'view'  => ( $view ? $view : '' ),
						);
					}
				}
			);
		} finally {
			if ( $temporary ) {
				LucidLab_MPC_Work::delete( $file );
			}
		}
		return $index;
	}
	/**
	 * Attachment IDs referenced inside a content string (shortcode attrs + Gutenberg blocks).
	 *
	 * @param string $content Content.
	 *
	 * @return array
	 */
	private function content_ids( string $content ): array {
		$ids = array();
		if ( '' === $content ) {
			return $ids;
		}
		if ( preg_match_all( '/\b(?:' . self::CONTENT_ATTRS . ')\s*=\s*(["\'])([0-9][0-9,\s]*)\1/i', $content, $mm ) ) {
			foreach ( $mm[2] as $list ) {
				foreach ( LucidLab_MPC_Helpers::split_ids( $list ) as $sid ) {
					$ids[ $sid ] = 1;
				}
			}
		}
		if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
			$this->walk_blocks( parse_blocks( $content ), $ids );
		}
		return array_keys( $ids );
	}

	/**
	 * Walk blocks.
	 *
	 * @param array $blocks Blocks.
	 * @param array &$ids    Ids.
	 */
	private function walk_blocks( array $blocks, array &$ids ): void {
		foreach ( $blocks as $b ) {
			$name  = $b['blockName'] ?? '';
			$attrs = $b['attrs'] ?? array();
			if ( in_array( $name, array( 'core/image', 'core/cover' ), true ) && isset( $attrs['id'] ) ) {
				$ids[ (int) $attrs['id'] ] = 1;
			}
			if ( 'core/media-text' === $name && isset( $attrs['mediaId'] ) ) {
				$ids[ (int) $attrs['mediaId'] ] = 1;
			}
			if ( 'core/gallery' === $name && ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
				foreach ( $attrs['ids'] as $gid ) {
					$ids[ (int) $gid ] = 1;
				}
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$this->walk_blocks( $b['innerBlocks'], $ids );
			}
		}
	}

	/* ---------- merge ---------- */

	/**
	 * Repoint every reference of each duplicate onto its survivor, then quarantine the duplicate.
	 * $pairs: [ ['dup'=>int,'keep'=>int], ... ]. Returns accumulated counts for one batch.
	 */

	/**
	 * Undo a whole merge set: restore the duplicate files + media records, then repoint the references that were moved onto the survivor back to each restored (new) attachment.
	 *
	 * @param string                  $stamp Stamp.
	 * @param LucidLab_MPC_Quarantine $quar  Quar.
	 *
	 * @return array
	 */
	public function undo_merge( string $stamp, LucidLab_MPC_Quarantine $quar ): array {
		$detail = $quar->restore_set_detailed( $stamp );
		if ( ! empty( $detail['error'] ) ) {
			return array(
				'restored'  => 0,
				'repointed' => 0,
				'error'     => $detail['error'],
			);
		}
		$repointed = 0;
		foreach ( (array) $detail['attachments'] as $a ) {
			$newid    = (int) ( $a['new_id'] ?? 0 );
			$survivor = (int) ( $a['merged_into'] ?? 0 );
			$changes  = (array) ( $a['ref_changes'] ?? array() );
			if ( $newid < 1 || $survivor < 1 ) {
				continue;
			}
			$repointed += $this->reverse_repoint( $survivor, $newid, $changes );
		}
		/* translators: 1: quarantine set name, 2: restored media count, 3: references changed back. */
		LucidLab_MPC_Logger::log( sprintf( __( 'Byte Identical Images UNDO of set %1$s: %2$d media restored, %3$d references repointed back.', 'lucidlab-mpc-cleanup' ), $stamp, (int) $detail['ok'], $repointed ), 'info' );
		return array(
			'restored'  => (int) $detail['ok'],
			'repointed' => $repointed,
		);
	}

	/**
	 * Move survivor references recorded in $changes back onto the restored attachment ($newid).
	 *
	 * @param int   $survivor Survivor.
	 * @param int   $newid    Newid.
	 * @param array $changes  Changes.
	 *
	 * @return int
	 */
	private function reverse_repoint( int $survivor, int $newid, array $changes ): int {
		global $wpdb;
		$n = 0;
		foreach ( $changes as $c ) {
			$type = (string) ( $c['t'] ?? '' );
			$oid  = (int) ( $c['o'] ?? 0 );
			if ( $oid < 1 ) {
				continue;
			}
			if ( 'featured' === $type ) {
				if ( (int) get_post_meta( $oid, '_thumbnail_id', true ) === $survivor ) {
					update_post_meta( $oid, '_thumbnail_id', $newid );
					++$n;
				}
			} elseif ( 'gallery' === $type ) {
				$ids = LucidLab_MPC_Helpers::split_ids( (string) get_post_meta( $oid, '_product_image_gallery', true ) );
				if ( in_array( $survivor, $ids, true ) ) {
					$ids = array_values( array_unique( array_map( static fn( $x ) => $x === $survivor ? $newid : $x, $ids ) ) );
					update_post_meta( $oid, '_product_image_gallery', implode( ',', $ids ) );
					++$n;
				}
			} elseif ( 'variation_gallery' === $type ) {
				$ids = LucidLab_MPC_Helpers::split_ids( (string) get_post_meta( $oid, '_product_image_gallery', true ) );
				if ( in_array( $survivor, $ids, true ) ) {
					$ids = array_values( array_unique( array_map( static fn( $x ) => $x === $survivor ? $newid : $x, $ids ) ) );
					update_post_meta( $oid, '_product_image_gallery', implode( ',', $ids ) );
					++$n;
				}
			} elseif ( 'term' === $type ) {
				if ( (int) get_term_meta( $oid, 'thumbnail_id', true ) === $survivor ) {
					update_term_meta( $oid, 'thumbnail_id', $newid );
					++$n;
				}
			} elseif ( 'content' === $type ) {
				$post = get_post( $oid );
				if ( $post ) {
					$nc = $this->repoint_content( (string) $post->post_content, $survivor, $newid );
					if ( $nc !== $post->post_content ) {
						wp_update_post(
							array(
								'ID'           => $oid,
								'post_content' => $nc,
							)
						);
						++$n;
					}
				}
			}
		}
		return $n;
	}

	/** Move every reference of $from onto $to across the safe scope. Returns the change records. */

	/**
	 * Replace the $from ID with $to only inside recognised image attributes / block IDs.
	 *
	 * @param string $content Content.
	 * @param int    $from    From.
	 * @param int    $to      To.
	 *
	 * @return string
	 */
	private function repoint_content( string $content, int $from, int $to ): string {
		// Shortcode attributes (single IDs and comma lists).
		$content = preg_replace_callback(
			'/\b(' . self::CONTENT_ATTRS . ')(\s*=\s*)(["\'])([0-9][0-9,\s]*)\3/i',
			static function ( $m ) use ( $from, $to ) {
				$ids = LucidLab_MPC_Helpers::split_ids( $m[4] );
				if ( ! in_array( $from, $ids, true ) ) {
					return $m[0];
				}
				$ids = array_map( static fn( $x ) => $x === $from ? $to : $x, $ids );
				$ids = array_values( array_unique( $ids ) );
				return $m[1] . $m[2] . $m[3] . implode( ',', $ids ) . $m[3];
			},
			$content
		);

		// Matches Gutenberg block attributes such as "id":N and "mediaId":N.
		$content = preg_replace( '/("(?:id|mediaId)"\s*:\s*)' . $from . '\b/', '${1}' . $to, $content );

		// Matches Gutenberg block attributes such as "ids":[...] arrays.
		$content = preg_replace_callback(
			'/"ids"\s*:\s*\[([0-9,\s]*)\]/',
			static function ( $m ) use ( $from, $to ) {
				$ids = LucidLab_MPC_Helpers::split_ids( $m[1] );
				if ( ! in_array( $from, $ids, true ) ) {
					return $m[0];
				}
				$ids = array_map( static fn( $x ) => $x === $from ? $to : $x, $ids );
				$ids = array_values( array_unique( $ids ) );
				return '"ids":[' . implode( ',', $ids ) . ']';
			},
			$content
		);

		return $content;
	}
}
