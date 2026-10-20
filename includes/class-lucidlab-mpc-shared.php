<?php
/**
 * Lucid Lab Media & Products Cleanup: shared class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Images referenced by two or more distinct posts or taxonomy terms. */
class LucidLab_MPC_Shared {
	/** Post types that never place media on the site (orders, logs, caches). */
	public const SKIP_TYPES = "'attachment','revision','nav_menu_item','shop_order','shop_order_refund','shop_order_placehold','shop_subscription','scheduled-action','oembed_cache','customize_changeset','wpcf7s','flamingo_inbound','flamingo_outbound'";

	/**
	 * Image attachment IDs, loaded once per request: Missing runs many small batches per request.
	 *
	 * @var array|null
	 */
	private static ?array $valid = null;

	/**
	 * Forget images.
	 */
	public static function forget_images(): void {
		self::$valid = null;
	}

	/**
	 * Scan.
	 *
	 * @param bool $shared_only         Shared only.
	 * @param int  $offset              Offset.
	 * @param int  $limit               Limit.
	 * @param bool $collect_broken_urls Collect broken urls.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public function scan( bool $shared_only = true, int $offset = 0, int $limit = 0, bool $collect_broken_urls = false ): array {
		global $wpdb;
		if ( null === self::$valid ) {
			$ids = (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( $wpdb->last_error ) {
				throw new RuntimeException( __( 'Could not read attachments. Scan is incomplete.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			self::$valid = array_fill_keys( array_map( 'intval', $ids ), true );
		}
		$map         = array();
		$valid       = self::$valid;
		$broken      = array();
		$base        = trailingslashit( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ) );
		$push        = static function ( $image, $key, $row, $usage ) use ( &$map, $valid ) {
			$image = (int) $image;
			if ( ! isset( $valid[ $image ] ) ) {
				return;
			}
			if ( ! isset( $map[ $image ][ $key ] ) ) {
				$map[ $image ][ $key ]           = $row;
				$map[ $image ][ $key ]['usages'] = array();}
			$map[ $image ][ $key ]['usages'][ $usage ] = true;
		};
		$push_broken = static function ( string $url, array $row ) use ( &$broken, $base ) {
			$path = LucidLab_MPC_Helpers::uploads_url_to_path( $url );
			if ( '' === $path || ! str_starts_with( strtolower( $path ), strtolower( $base ) ) || is_file( $path ) ) {
				return;
			}
			$rel = substr( $path, strlen( $base ) );
			if ( LucidLab_MPC_Helpers::has_generated_copy( $rel ) ) {
				return;
			}
			$key = strtolower( $rel );
			if ( ! isset( $broken[ $key ] ) ) {
				$aid = (int) attachment_url_to_postid( $url );
				if ( ! $aid ) {
					$aid = (int) attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url ) );
				}
				$broken[ $key ] = array(
					'url'           => $url,
					'file'          => $rel,
					'attachment_id' => $aid,
					'products'      => array(),
				);
			}
			$pid = (int) ( $row['id'] ?? 0 );
			if ( $pid > 0 ) {
				$reference_key                                = (string) ( $row['reference_key'] ?? ( 'post:' . $pid ) );
				$broken[ $key ]['products'][ $reference_key ] = array(
					'reference_key' => $reference_key,
					'id'            => $pid,
					'title'         => (string) ( $row['title'] ?? ( '#' . $pid ) ),
					'edit'          => (string) ( $row['edit'] ?? '' ),
					'view'          => (string) ( $row['view'] ?? '' ),
					'usage'         => __( 'content URL', 'lucidlab-mpc-cleanup' ),
				);
			}
		};
		$parser      = new LucidLab_MPC_Orphans();
		$url_cache   = array();
		$post_sql    = "SELECT ID, post_title, post_type, post_content, post_excerpt, post_parent FROM {$wpdb->posts} WHERE post_type NOT IN (" . self::SKIP_TYPES . ") AND post_status NOT IN ('trash','auto-draft','inherit')";
		if ( $limit > 0 ) {
			$post_sql = $wpdb->prepare( $post_sql . ' ORDER BY ID LIMIT %d OFFSET %d', absint( $limit ), absint( $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SKIP_TYPES is a fixed post-type list.
		}
		$posts = $this->timed(
			'content_sql',
			static function () use ( $wpdb, $post_sql ) {
				return $wpdb->get_results( $post_sql ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $post_sql was prepared above; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			}
		);
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not read content references. Scan is incomplete.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		$batch_ids  = array_map( static fn( $p )=> (int) $p->ID, (array) $posts );
		$parent_ids = array_values( array_unique( array_filter( array_map( static fn( $p )=>'product_variation' === $p->post_type ? (int) $p->post_parent : 0, (array) $posts ) ) ) );
		// Titles, links and meta are read per post below; load them for the whole batch at once.
		if ( $batch_ids ) {
			update_meta_cache( 'post', $batch_ids );
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $batch_ids, false, false );}
		}
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not read custom fields. Scan is incomplete.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		if ( $parent_ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $parent_ids, false, false );}
		foreach ( (array) $posts as $post ) {
			$id  = (int) $post->ID;
			$key = 'post:' . $id;
			$row = array(
				'id'    => $id,
				'title' => ( $post->post_title ? $post->post_title : '#' . $id ) . ' (' . $post->post_type . ')',
				'edit'  => get_edit_post_link( $id, 'raw' ),
				'view'  => get_permalink( $id ),
			);
			$push( get_post_thumbnail_id( $id ), $key, $row, 'featured' );
			if ( 'product' === $post->post_type ) {
				foreach ( LucidLab_MPC_Helpers::split_ids( (string) get_post_meta( $id, '_product_image_gallery', true ) ) as $image ) {
					$push( $image, $key, $row, 'product gallery' );}
			}
			if ( 'product_variation' === $post->post_type ) {
				$parent_id       = (int) $post->post_parent;
				$parent          = get_post( $parent_id );
				$parent_title    = $parent ? get_the_title( $parent_id ) : ( 'Product #' . $parent_id );
				$variation_title = $parent_title . ' — Variation #' . $id;
				$row['title']    = $variation_title . ' (product_variation)';
				foreach ( LucidLab_MPC_Helpers::split_ids( (string) get_post_meta( $id, '_product_image_gallery', true ) ) as $image ) {
					$push( $image, $key, $row, 'variation gallery' );}
			}
			$mark_id  = static function ( $image ) use ( $push, $key, $row ) {
				$push( $image, $key, $row, 'post / builder content' );
			};
			$mark_url = static function ( $url ) use ( &$url_cache, $mark_id, $collect_broken_urls, $push_broken, $row ) {
				$url = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
				$url = (string) strtok( $url, '?' );
				if ( strpos( $url, '/' ) === 0 ) {
					$origin = wp_parse_url( home_url() );
					$url    = $origin['scheme'] . '://' . $origin['host'] . ( isset( $origin['port'] ) ? ':' . $origin['port'] : '' ) . $url;}
				if ( $collect_broken_urls ) {
					$push_broken( $url, $row );}
				if ( ! isset( $url_cache[ $url ] ) ) {
					$image = attachment_url_to_postid( $url );
					if ( ! $image ) {
						$image = attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url ) );}
					$url_cache[ $url ] = $image;
				}
				if ( $url_cache[ $url ] ) {
					$mark_id( $url_cache[ $url ] );
				}
			};
			$parser->from_content( (string) $post->post_content, $mark_id, $mark_url );
			$parser->from_content( (string) $post->post_excerpt, $mark_id, $mark_url );
			foreach ( get_post_meta( $id ) as $meta_key => $values ) {
				if ( in_array( $meta_key, array( '_thumbnail_id', '_product_image_gallery' ), true ) ) {
					continue;
				}

				foreach ( $values as $value ) {
					$parser->from_meta( $meta_key, $value, $mark_id, $mark_url );
				}
			}
		}
		// Page taxonomy references alongside posts so large category libraries stay bounded.
		$term_sql = "SELECT term_id, taxonomy, description FROM {$wpdb->term_taxonomy}";
		if ( $limit > 0 ) {
			$term_sql = $wpdb->prepare( $term_sql . ' ORDER BY term_taxonomy_id LIMIT %d OFFSET %d', absint( $limit ), absint( $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- base query has no external input.
		}
		$terms = $this->timed(
			'content_sql',
			static function () use ( $wpdb, $term_sql ) {
				return $wpdb->get_results( $term_sql ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $term_sql was prepared above; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			}
		);
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not read taxonomy references. Scan is incomplete.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		if ( $terms ) {
			$term_ids = array_map( static fn( $term )=> (int) $term->term_id, $terms );
			update_meta_cache( 'term', $term_ids );
			if ( function_exists( '_prime_term_caches' ) ) {
				_prime_term_caches( $term_ids, false );}
		}
		foreach ( (array) $terms as $ref ) {
			$term = get_term( (int) $ref->term_id, $ref->taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$view     = get_term_link( $term );
			$key      = 'term:' . $term->term_id;
			$row      = array(
				'id'            => (int) $term->term_id,
				'reference_key' => $key,
				'title'         => $term->name . ' (' . $term->taxonomy . ')',
				'edit'          => get_edit_term_link( $term->term_id, $term->taxonomy ),
				'view'          => is_wp_error( $view ) ? '' : $view,
			);
			$mark_id  = static function ( $image ) use ( $push, $key, $row ) {
				$push( $image, $key, $row, 'taxonomy description / custom field' );
			};
			$mark_url = static function ( $url ) use ( &$url_cache, $mark_id, $collect_broken_urls, $push_broken, $row ) {
				$url = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
				$url = (string) strtok( $url, '?#' );
				if ( strpos( $url, '/' ) === 0 ) {
					$origin = wp_parse_url( home_url() );
					$url    = $origin['scheme'] . '://' . $origin['host'] . ( isset( $origin['port'] ) ? ':' . $origin['port'] : '' ) . $url;}
				if ( $collect_broken_urls ) {
					$push_broken( $url, $row );}
				if ( ! isset( $url_cache[ $url ] ) ) {
					$url_cache[ $url ] = attachment_url_to_postid( $url );
					if ( ! $url_cache[ $url ] ) {
						$url_cache[ $url ] = attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url ) );
					}
				}
				if ( $url_cache[ $url ] ) {
					$mark_id( $url_cache[ $url ] );}
			};
			$parser->from_content( (string) $ref->description, $mark_id, $mark_url );
			foreach ( get_term_meta( (int) $term->term_id ) as $meta_key => $values ) {
				foreach ( $values as $value ) {
					$parser->from_meta( $meta_key, $value, $mark_id, $mark_url );}
			}
		}
		if ( ! $shared_only ) {
			$parent_ids = array_map( static fn( $p )=> (int) $p->ID, (array) $posts );
			$parent_sql = "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%' AND post_parent>0";
			if ( $limit > 0 ) {
				$parent_ids   = ( $parent_ids ? $parent_ids : array( 0 ) );
				$placeholders = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );
				$parent_sql   = $wpdb->prepare( $parent_sql . " AND post_parent IN ($placeholders)", $parent_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders matches the prepared parent IDs.
			}
			foreach ( (array) $this->timed(
				'content_sql',
				static function () use ( $wpdb, $parent_sql ) {
					return $wpdb->get_results( $parent_sql ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $parent_sql was prepared above; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				}
			) as $attachment ) {
				$parent = get_post( (int) $attachment->post_parent );
				if ( ! $parent || in_array( $parent->post_status, array( 'trash', 'auto-draft' ), true ) ) {
					continue;
				}
				$pid = (int) $parent->ID;
				$push(
					$attachment->ID,
					'post:' . $pid,
					array(
						'id'    => $pid,
						'title' => ( $parent->post_title ? $parent->post_title : '#' . $pid ) . ' (' . $parent->post_type . ')',
						'edit'  => get_edit_post_link( $pid, 'raw' ),
						'view'  => get_permalink( $pid ),
					),
					'attached parent'
				);
			}
		}
		$images = array();
		$total  = 0;
		if ( $map ) {
			$image_ids = array_map( 'intval', array_keys( $map ) );
			update_meta_cache( 'post', $image_ids );
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $image_ids, false, false );}
		}
		foreach ( $map as $image => $items ) {
			if ( class_exists( 'LucidLab_MPC_Exclusions', false ) && LucidLab_MPC_Exclusions::skip_attachment( (int) $image ) ) {
				continue;
			}
			if ( $shared_only && count( $items ) < 2 ) {
				continue;
			}
			$rows = array();
			foreach ( $items as $row ) {
				$row['usage'] = implode( ' + ', array_keys( $row['usages'] ) );
				unset( $row['usages'] );
				$rows[] = $row;}
			usort( $rows, static fn( $a, $b )=>strcasecmp( $a['title'], $b['title'] ) );
			$total   += count( $rows );
			$images[] = array(
				'id'       => $image,
				'title'    => LucidLab_MPC_Helpers::either( get_the_title( $image ), '#' . $image ),
				'thumb'    => wp_get_attachment_image_url( $image, array( 120, 120 ) ),
				'full'     => wp_get_attachment_url( $image ),
				'edit'     => get_edit_post_link( $image, 'raw' ),
				'count'    => count( $rows ),
				'products' => $rows,
			);
		}
		usort( $images, static fn( $a, $b )=>$b['count'] <=> $a['count'] );
		$broken_list = array();
		foreach ( $broken as $item ) {
			$item['products'] = array_values( $item['products'] );
			usort( $item['products'], static fn( $a, $b ) => strcasecmp( (string) $a['title'], (string) $b['title'] ) );
			$broken_list[] = $item;
		}
		usort( $broken_list, static fn( $a, $b ) => strcasecmp( (string) $a['file'], (string) $b['file'] ) );
		if ( class_exists( 'LucidLab_MPC_Scan_Profile', false ) ) {
			LucidLab_MPC_Scan_Profile::count( max( count( (array) $posts ), count( (array) $terms ) ) );
		}
		return array(
			'type'          => 'shared',
			'images'        => $images,
			'image_count'   => count( $images ),
			'product_links' => $total,
			'batch_count'   => max( count( (array) $posts ), count( (array) $terms ) ),
			'broken_urls'   => $broken_list,
		);
	}

	/**
	 * Build or reuse the shared reference index, then read Shared or Missing rows from it.
	 *
	 * @param bool $shared_only  Shared only.
	 * @param bool $only_missing Only missing.
	 *
	 * @return array
	 */
	public function scan_indexed( bool $shared_only = true, bool $only_missing = false ): array {
		$file = 'refs-shared-sync-' . bin2hex( random_bytes( 4 ) ) . '.jsonl';
		try {
			LucidLab_MPC_Reference_Index::build( $file );
			return $this->from_reference( $file, $shared_only, $only_missing );
		} finally {
			LucidLab_MPC_Work::delete( $file );
		}
	}

	/**
	 * Shared Images or missing-file rows from a finished reference index. Content is not parsed again. Widgets and plugin settings stay in the orphan index only.
	 *
	 * @param string $file         File.
	 * @param bool   $shared_only  Shared only.
	 * @param bool   $only_missing Only missing.
	 *
	 * @return array{type:string,images:array,image_count:int,product_links:int,batch_count:int,broken_urls:array}
	 */
	public function from_reference( string $file, bool $shared_only, bool $only_missing ): array {
		global $wpdb;
		$valid   = array_fill_keys(
			array_map(
				'intval',
				(array) $this->timed(
					'media_sql',
					static function () use ( $wpdb ) {
						return $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					}
				)
			),
			true
		);
		$by_rel  = array();
		$by_base = array();
		foreach ( (array) $this->timed(
			'media_sql',
			static function () use ( $wpdb ) {
				return $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			}
		) as $row ) {
			$rel = strtolower( wp_normalize_path( (string) $row->meta_value ) );
			$id  = (int) $row->post_id;
			if ( '' === $rel || $id < 1 ) {
				continue;
			}
			$by_rel[ $rel ]                               = $id;
			$by_base[ strtolower( wp_basename( $rel ) ) ] = $id;
		}
		$map     = array();
		$broken  = array();
		$sources = 0;
		$base    = trailingslashit( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ) );
		$push    = static function ( int $image, string $key, string $usage ) use ( &$map, $valid ) {
			if ( $image < 1 || ! isset( $valid[ $image ] ) ) {
				return;
			}
			if ( ! isset( $map[ $image ][ $key ] ) ) {
				$map[ $image ][ $key ] = array( 'usages' => array() );
			}
			$map[ $image ][ $key ]['usages'][ $usage ] = true;
		};
		$resolve = static function ( string $rel ) use ( $by_rel, $by_base ): int {
			$rel = strtolower( wp_normalize_path( $rel ) );
			if ( isset( $by_rel[ $rel ] ) ) {
				return $by_rel[ $rel ];
			}
			$stripped = (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $rel );
			if ( isset( $by_rel[ $stripped ] ) ) {
				return $by_rel[ $stripped ];
			}
			$name = strtolower( wp_basename( $stripped ) );
			return (int) ( $by_base[ $name ] ?? 0 );
		};
		LucidLab_MPC_Work::each(
			$file,
			static function ( array $row ) use ( &$sources, &$broken, $push, $resolve, $only_missing, $base ) {
				$source = (string) ( $row['s'] ?? '' );
				if ( 'x' === $source || 'o' === $source || ( 'p' !== $source && 'm' !== $source && 't' !== $source ) ) {
					return;
				}
				++$sources;
				$owner = 't' === $source ? (int) ( $row['k'] ?? 0 ) : ( 'm' === $source ? (int) ( $row['p'] ?? 0 ) : (int) ( $row['k'] ?? 0 ) );
				if ( $owner < 1 ) {
					return;
				}
				$key   = ( 't' === $source ? 'term:' : 'post:' ) . $owner;
				$usage = self::usage_label( $source, (string) ( $row['mk'] ?? '' ) );
				foreach ( (array) ( $row['i'] ?? array() ) as $id ) {
					$push( (int) $id, $key, $usage );
				}
				foreach ( (array) ( $row['b'] ?? array() ) as $name ) {
					$push( $resolve( (string) $name ), $key, $usage );
				}
				if ( ! $only_missing ) {
					return;
				}
				foreach ( (array) ( $row['u'] ?? array() ) as $rel ) {
					$rel  = ltrim( wp_normalize_path( (string) $rel ), '/' );
					$path = $base . $rel;
					if ( '' === $rel || is_file( $path ) || LucidLab_MPC_Helpers::has_generated_copy( $rel ) ) {
						continue;
					}
					$file_key = strtolower( $rel );
					if ( ! isset( $broken[ $file_key ] ) ) {
						$broken[ $file_key ] = array(
							'url'           => LucidLab_MPC_Helpers::uploads_baseurl() . $rel,
							'file'          => $rel,
							'attachment_id' => $resolve( $rel ),
							'products'      => array(),
						);
					}
					$broken[ $file_key ]['products'][ $key ] = array(
						'kind' => 't' === $source ? 'term' : 'post',
						'id'   => $owner,
					);
				}
			}
		);
		foreach ( (array) $this->timed(
			'content_sql',
			static function () use ( $wpdb ) {
				return $wpdb->get_results( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%' AND post_parent>0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			}
		) as $attachment ) {
			$parent = (int) $attachment->post_parent;
			if ( $parent > 0 ) {
				$push( (int) $attachment->ID, 'post:' . $parent, 'attached parent' );
			}
		}
		if ( class_exists( 'LucidLab_MPC_Scan_Profile', false ) ) {
			LucidLab_MPC_Scan_Profile::count( $sources );
		}
		$post_ids = array();
		$term_ids = array();
		foreach ( $map as $image => $items ) {
			if ( class_exists( 'LucidLab_MPC_Exclusions', false ) && LucidLab_MPC_Exclusions::skip_attachment( (int) $image ) ) {
				unset( $map[ $image ] );
				continue;
			}
			if ( $only_missing && ! $this->file_is_missing( (int) $image, $base ) ) {
				unset( $map[ $image ] );
				continue;
			}
			if ( $shared_only && count( $items ) < 2 ) {
				unset( $map[ $image ] );
				continue;
			}
			foreach ( $items as $key => $_row ) {
				if ( str_starts_with( (string) $key, 'term:' ) ) {
					$term_ids[ (int) substr( (string) $key, 5 ) ] = 1;
				} else {
					$post_ids[ (int) substr( (string) $key, 5 ) ] = 1;
				}
			}
		}
		if ( $only_missing ) {
			foreach ( $broken as $item ) {
				foreach ( (array) $item['products'] as $ref ) {
					if ( 'term' === ( $ref['kind'] ?? '' ) ) {
						$term_ids[ (int) $ref['id'] ] = 1;
					} else {
						$post_ids[ (int) $ref['id'] ] = 1;
					}
				}
			}
		}
		$posts  = $this->load_posts( array_keys( $post_ids ) );
		$terms  = $this->load_terms( array_keys( $term_ids ) );
		$images = array();
		$links  = 0;
		foreach ( $map as $image => $items ) {
			$rows = array();
			foreach ( $items as $key => $item ) {
				$row = str_starts_with( (string) $key, 'term:' ) ? ( $terms[ (int) substr( (string) $key, 5 ) ] ?? null ) : ( $posts[ (int) substr( (string) $key, 5 ) ] ?? null );
				if ( ! $row ) {
					continue;
				}
				$usages = array_keys( $item['usages'] );
				if ( 'product_variation' === ( $row['post_type'] ?? '' ) ) {
					$usages = array_map( static fn( $label ) => 'product gallery' === $label ? 'variation gallery' : $label, $usages );
				}
				unset( $row['post_type'] );
				$row['usage'] = self::join_usages( $usages );
				$rows[]       = $row;
			}
			if ( $shared_only && count( $rows ) < 2 ) {
				continue;
			}
			if ( ! $rows ) {
				continue;
			}
			usort( $rows, static fn( $a, $b ) => strcasecmp( (string) $a['title'], (string) $b['title'] ) );
			$links   += count( $rows );
			$images[] = array(
				'id'       => (int) $image,
				'title'    => LucidLab_MPC_Helpers::either( get_the_title( $image ), ( '#' . $image ) ),
				'thumb'    => wp_get_attachment_image_url( $image, array( 120, 120 ) ),
				'full'     => wp_get_attachment_url( $image ),
				'edit'     => get_edit_post_link( $image, 'raw' ),
				'count'    => count( $rows ),
				'products' => $rows,
			);
			if ( $only_missing ) {
				$images[ array_key_last( $images ) ]['file'] = $this->missing_rel( (int) $image, $base );
			}
		}
		usort( $images, static fn( $a, $b ) => ( (int) $b['count'] ) <=> ( (int) $a['count'] ) );
		$broken_list = array();
		if ( $only_missing ) {
			foreach ( $broken as $item ) {
				$products = array();
				foreach ( (array) $item['products'] as $ref ) {
					$row = 'term' === ( $ref['kind'] ?? '' ) ? ( $terms[ (int) $ref['id'] ] ?? null ) : ( $posts[ (int) $ref['id'] ] ?? null );
					if ( ! $row ) {
						continue;
					}
					$row['usage'] = __( 'content URL', 'lucidlab-mpc-cleanup' );
					unset( $row['post_type'] );
					$products[] = $row;
				}
				if ( ! $products ) {
					continue;
				}
				usort( $products, static fn( $a, $b ) => strcasecmp( (string) $a['title'], (string) $b['title'] ) );
				$item['products'] = $products;
				$broken_list[]    = $item;
			}
			usort( $broken_list, static fn( $a, $b ) => strcasecmp( (string) ( $a['file'] ?? '' ), (string) ( $b['file'] ?? '' ) ) );
		}
		return array(
			'type'          => 'shared',
			'images'        => $images,
			'image_count'   => count( $images ),
			'product_links' => $links,
			'batch_count'   => $sources,
			'broken_urls'   => $broken_list,
		);
	}

	/**
	 * Timed.
	 *
	 * @param string   $section Section.
	 * @param callable $query   Query.
	 */
	private function timed( string $section, callable $query ) {
		return class_exists( 'LucidLab_MPC_Scan_Profile', false ) ? LucidLab_MPC_Scan_Profile::timed( $section, $query ) : $query();
	}

	/**
	 * Usage label.
	 *
	 * @param string $source   Source.
	 * @param string $meta_key Meta key.
	 *
	 * @return string
	 */
	private static function usage_label( string $source, string $meta_key ): string {
		if ( 't' === $source ) {
			return 'taxonomy description / custom field';
		}
		if ( '_thumbnail_id' === $meta_key ) {
			return 'featured';
		}
		if ( '_product_image_gallery' === $meta_key ) {
			return 'product gallery';
		}
		return 'post / builder content';
	}

	/**
	 * Join usages.
	 *
	 * @param array<int,string> $usages Usages.
	 *
	 * @return string
	 */
	private static function join_usages( array $usages ): string {
		$order = array( 'featured', 'product gallery', 'variation gallery', 'post / builder content', 'taxonomy description / custom field', 'attached parent' );
		$have  = array_fill_keys( $usages, true );
		$out   = array();
		foreach ( $order as $label ) {
			if ( isset( $have[ $label ] ) ) {
				$out[] = $label;
			}
		}
		foreach ( $usages as $label ) {
			if ( ! in_array( $label, $out, true ) ) {
				$out[] = $label;
			}
		}
		$labels = array(
			'featured'                            => __( 'featured', 'lucidlab-mpc-cleanup' ),
			'product gallery'                     => __( 'product gallery', 'lucidlab-mpc-cleanup' ),
			'variation gallery'                   => __( 'variation gallery', 'lucidlab-mpc-cleanup' ),
			'post / builder content'              => __( 'post / builder content', 'lucidlab-mpc-cleanup' ),
			'taxonomy description / custom field' => __( 'taxonomy description / custom field', 'lucidlab-mpc-cleanup' ),
			'attached parent'                     => __( 'attached parent', 'lucidlab-mpc-cleanup' ),
		);
		return implode( ' + ', array_map( static fn( $label ) => $labels[ $label ] ?? $label, $out ) );
	}

	/**
	 * File is missing.
	 *
	 * @param int    $id   Id.
	 * @param string $base Base.
	 *
	 * @return bool
	 */
	private function file_is_missing( int $id, string $base ): bool {
		$file = LucidLab_MPC_Helpers::attached_file_path( $id );
		return is_string( $file ) && '' !== $file && str_starts_with( strtolower( $file ), strtolower( $base ) ) && ! is_file( $file );
	}

	/**
	 * Missing rel.
	 *
	 * @param int    $id   Id.
	 * @param string $base Base.
	 *
	 * @return string
	 */
	private function missing_rel( int $id, string $base ): string {
		$file = LucidLab_MPC_Helpers::attached_file_path( $id );
		if ( ! is_string( $file ) || ! str_starts_with( strtolower( $file ), strtolower( $base ) ) ) {
			return '';
		}
		return substr( $file, strlen( $base ) );
	}

	/**
	 * Load posts.
	 *
	 * @param array<int,int> $ids Ids.
	 *
	 * @return array
	 */
	private function load_posts( array $ids ): array {
		global $wpdb;
		$ids  = array_values( array_filter( array_map( 'intval', $ids ) ) );
		$out  = array();
		$skip = array( 'attachment', 'revision', 'nav_menu_item', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription', 'scheduled-action', 'oembed_cache', 'customize_changeset', 'wpcf7s', 'flamingo_inbound', 'flamingo_outbound' );
		foreach ( array_chunk( $ids, 400 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$posts_chunk  = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_type, post_status, post_parent FROM {$wpdb->posts} WHERE ID IN ($placeholders)", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			foreach ( (array) $posts_chunk as $post ) {
				if ( in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) || in_array( $post->post_type, $skip, true ) ) {
					continue;
				}
				$id    = (int) $post->ID;
				$title = ( $post->post_title ? $post->post_title : ( '#' . $id ) ) . ' (' . $post->post_type . ')';
				$view  = (int) $post->post_parent;
				if ( 'product_variation' === $post->post_type ) {
					$parent = get_post( $view );
					$title  = ( $parent ? get_the_title( $view ) : ( 'Product #' . $view ) ) . ' — Variation #' . $id . ' (product_variation)';
					$view   = $view > 0 ? $view : $id;
				} else {
					$view = $id;
				}
				$out[ $id ] = array(
					'id'            => $id,
					'reference_key' => 'post:' . $id,
					'title'         => $title,
					'edit'          => get_edit_post_link( $id, 'raw' ),
					'view'          => get_permalink( $view ),
					'post_type'     => (string) $post->post_type,
				);
			}
		}
		return $out;
	}

	/**
	 * Load terms.
	 *
	 * @param array<int,int> $ids Ids.
	 *
	 * @return array
	 */
	private function load_terms( array $ids ): array {
		$out = array();
		foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
			$term = get_term( $id );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$view       = get_term_link( $term );
			$out[ $id ] = array(
				'id'            => $id,
				'reference_key' => 'term:' . $id,
				'title'         => $term->name . ' (' . $term->taxonomy . ')',
				'edit'          => get_edit_term_link( $term->term_id, $term->taxonomy ),
				'view'          => is_wp_error( $view ) ? '' : $view,
			);
		}
		return $out;
	}
}
