<?php
/**
 * Lucid Lab Media & Products Cleanup: orphans class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab 2 — Flatsome-aware orphan image finder. Builds one authoritative "used" set
 * (attachment IDs + upload basenames) from every source, then the remainder is orphaned.
 * Bias: when in doubt, keep. Reference building is chunked so the AJAX scan can show progress.
 */
class LucidLab_MPC_Orphans {

	/**
	 * Acf active.
	 *
	 * @return bool
	 */
	public function acf_active(): bool {
		return function_exists( 'get_field' ) || class_exists( 'ACF' );
	}

	/**
	 * Set by the reference index so a large table can return before PHP or the proxy times out.
	 *
	 * @var float|null
	 */
	public ?float $index_deadline = null;

	/**
	 * True when this batch stopped early and the same phase still has rows.
	 *
	 * @var bool
	 */
	public bool $batch_open = false;

	/**
	 * SQL limit used for this batch. A short return below this limit ends the phase.
	 *
	 * @var int
	 */
	public int $last_fetch_limit = 0;

	/**
	 * Wall time for a content query, recorded on the scan that is running.
	 *
	 * @param callable $query Query.
	 */
	private function timed_content( callable $query ) {
		return class_exists( 'LucidLab_MPC_Scan_Profile', false ) ? LucidLab_MPC_Scan_Profile::timed( 'content_sql', $query ) : $query();
	}

	/**
	 * Cap a keyset page while a deadline is set so one request cannot load a huge table slice. Caps stay below scan_batch_db but high enough that AJAX round-trips are not the bottleneck.
	 *
	 * @param int $limit Limit.
	 * @param int $cap   Cap.
	 *
	 * @return int
	 */
	private function indexing_prepare( int $limit, int $cap ): int {
		$limit = max( 1, $limit );
		if ( null !== $this->index_deadline ) {
			$limit = min( $limit, $cap );
		}
		$this->last_fetch_limit = $limit;
		$this->batch_open       = false;
		return $limit;
	}

	/**
	 * Stop after the first slice once the reference-index deadline has passed.
	 *
	 * @param int $done Done.
	 *
	 * @return bool
	 */
	private function indexing_should_yield( int $done ): bool {
		return $done > 0 && null !== $this->index_deadline && microtime( true ) >= $this->index_deadline;
	}

	/**
	 * Walk an ordered id page in short slices. The cursor moves only through ids whose values were parsed. An early stop leaves the phase unfinished.
	 *
	 * @param array<int,object> $id_rows   Id rows.
	 * @param callable          $id_of     Id of.
	 * @param callable          $apply_ids Apply ids.
	 * @param int               &$cursor    Cursor.
	 * @param int               $slice     Slice.
	 *
	 * @return int
	 */
	private function index_ids( array $id_rows, callable $id_of, callable $apply_ids, int &$cursor, int $slice ): int {
		$processed = 0;
		$total     = count( $id_rows );
		$slice     = max( 1, $slice );
		while ( $processed < $total ) {
			if ( $this->indexing_should_yield( $processed ) ) {
				$this->batch_open = true;
				break;
			}
			$chunk = array_slice( $id_rows, $processed, $slice );
			$ids   = array();
			foreach ( $chunk as $row ) {
				$id = (int) $id_of( $row );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			if ( $ids ) {
				$apply_ids( $ids );
				$cursor = max( $cursor, max( $ids ) );
			}
			$processed += count( $chunk );
		}
		return $processed;
	}

	/* ---------- Reference building (chunked) ---------- */
	/**
	 * Additional plugin settings can reference loose files outside the media library.
	 *
	 * @param array       &$bn     Bn.
	 * @param int         &$cursor Cursor.
	 * @param array&|null $tagged Tagged.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return int
	 */
	public function options_step( array &$bn, int &$cursor, ?array &$tagged = null ): int {
		global $wpdb;
		$limit = $this->indexing_prepare( 500, 500 );
		$rows  = $this->timed_content(
			static function () use ( $wpdb, $cursor, $limit ) {
				return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					$wpdb->prepare(
						"SELECT option_id, option_name, LENGTH(option_value) AS len FROM {$wpdb->options} WHERE option_id > %d AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_id ASC LIMIT %d",
						$cursor,
						$wpdb->esc_like( '_transient' ) . '%',
						$wpdb->esc_like( '_site_transient' ) . '%',
						$wpdb->esc_like( 'lucidlab_mpc_' ) . '%',
						$limit
					)
				);
			}
		);
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not index settings references.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		$large = array();
		foreach ( (array) $rows as $row ) {
			if ( (int) ( $row->len ?? 0 ) > self::OPTION_SLICE_BYTES ) {
				$large[ (int) $row->option_id ] = $row;
			}
		}
		return $this->index_ids(
			(array) $rows,
			static function ( $row ) {
				return (int) $row->option_id;
			},
			function ( array $ids ) use ( &$bn, &$tagged, $large ) {
				global $wpdb;
				$ids = array_map( 'intval', $ids );
				foreach ( $ids as $pos => $id ) {
					if ( isset( $large[ $id ] ) ) {
						$this->index_large_option( $large[ $id ], $bn, $tagged );
						unset( $ids[ $pos ] );
					}
				}
				$ids = array_values( $ids );
				if ( ! $ids ) {
					return;
				}
				$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$full = $this->timed_content(
					static function () use ( $wpdb, $in, $ids ) {
						return $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id IN ($in)", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					}
				);
				if ( $wpdb->last_error ) {
					throw new RuntimeException( __( 'Could not index settings references.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				$parse  = function ( $row ) {
					$found = array(
						'ids'  => array(),
						'urls' => array(),
					);
					$this->collect_urls(
						(string) $row->option_value,
						static function ( $url ) use ( &$found ) {
							$found['urls'][] = $url;
						}
					);
					return $found;
				};
				$cached = class_exists( 'LucidLab_MPC_Scan_Cache' ) ? LucidLab_MPC_Scan_Cache::batch( 'options', (array) $full, static fn( $r )=> (int) $r->option_id, static fn( $r )=>$r->option_value, $parse ) : array();
				foreach ( (array) $full as $row ) {
					$found = $cached[ (int) $row->option_id ] ?? $parse( $row );
					self::note_heavy_setting( (string) ( $row->option_name ?? $row->option_id ), count( (array) $found['urls'] ) );
					$unused_ids = array();
					self::merge_found( $found, $unused_ids, $bn );
					if ( is_array( $tagged ) ) {
						$tagged[] = self::tagged_row( 'o', (int) $row->option_id, $found ); }
				}
			},
			$cursor,
			10
		);
	}
	/**
	 * Settings that name many files, kept for the scan diagnostics. One plugin setting that lists the whole
	 * library (a cache, an optimizer log, an export) marks every image in use, so an Orphan scan finds nothing.
	 *
	 * @param string $name  Option name.
	 * @param int    $count File references found in it.
	 */
	public static function note_heavy_setting( string $name, int $count ): void {
		if ( $count < self::HEAVY_SETTING_FILES ) {
			return;
		}
		$heavy          = (array) get_option( self::HEAVY_OPTION, array() );
		$heavy[ $name ] = $count;
		arsort( $heavy );
		update_option( self::HEAVY_OPTION, array_slice( $heavy, 0, 20, true ), false );
		if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
			LucidLab_MPC_Logger::diag(
				'reference-index',
				'A setting names many files; every image it names counts as in use.',
				array(
					'option' => $name,
					'files'  => $count,
				),
				'warning'
			);
		}
	}

	/**
	 * Read one large settings value in overlapping slices, so a multi-megabyte option cannot exhaust
	 * memory or time in one request. Every URL and file name in it is still indexed.
	 *
	 * @param object      $row     Row with option_id, option_name and len.
	 * @param array       &$bn     Bn.
	 * @param array&|null $tagged Tagged.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	private function index_large_option( object $row, array &$bn, ?array &$tagged ): void {
		global $wpdb;
		$id    = (int) $row->option_id;
		$len   = (int) $row->len;
		$start = microtime( true );
		$found = array(
			'ids'  => array(),
			'urls' => array(),
		);
		for ( $offset = 0; $offset < $len; $offset += self::OPTION_SLICE_BYTES ) {
			$part = $wpdb->get_var( $wpdb->prepare( "SELECT SUBSTRING(option_value, %d, %d) FROM {$wpdb->options} WHERE option_id = %d", $offset + 1, self::OPTION_SLICE_BYTES + self::OPTION_SLICE_OVERLAP, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( $wpdb->last_error ) {
				throw new RuntimeException( __( 'Could not index settings references.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			$this->collect_urls(
				(string) $part,
				static function ( $url ) use ( &$found ) {
					$found['urls'][ $url ] = $url;
				}
			);
		}
		$found['urls'] = array_values( $found['urls'] );
		self::note_heavy_setting( (string) $row->option_name, count( $found['urls'] ) );
		$unused_ids = array();
		self::merge_found( $found, $unused_ids, $bn );
		if ( is_array( $tagged ) ) {
			$tagged[] = self::tagged_row( 'o', $id, $found ); }
		if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
			LucidLab_MPC_Logger::diag(
				'reference-index',
				'Large setting read in slices.',
				array(
					'option'  => (string) $row->option_name,
					'bytes'   => $len,
					'urls'    => count( $found['urls'] ),
					'seconds' => round( microtime( true ) - $start, 2 ),
				)
			);
		}
	}

	/**
	 * Categories, tags and custom taxonomies can contain media in descriptions/custom fields.
	 *
	 * @param array       &$ids    Ids.
	 * @param array       &$bn     Bn.
	 * @param int         &$cursor Cursor.
	 * @param array&|null $tagged Tagged.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return int
	 */
	public function terms_step( array &$ids, array &$bn, int &$cursor, ?array &$tagged = null ): int {
		global $wpdb;
		$limit = $this->indexing_prepare( max( 1, (int) LucidLab_MPC_Settings::get( 'scan_batch_db' ) ), 200 );
		$rows  = $this->timed_content(
			static function () use ( $wpdb, $cursor, $limit ) {
				return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					$wpdb->prepare(
						"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id > %d ORDER BY term_taxonomy_id ASC LIMIT %d",
						$cursor,
						$limit
					)
				);
			}
		);
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not read taxonomy references. Scan stopped before identifying unused images.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		return $this->index_ids(
			(array) $rows,
			static function ( $row ) {
				return (int) $row->term_taxonomy_id;
			},
			function ( array $chunk ) use ( &$ids, &$bn, &$tagged ) {
				global $wpdb;
				$chunk = array_map( 'intval', $chunk );
				$in    = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				$full  = $this->timed_content(
					static function () use ( $wpdb, $in, $chunk ) {
						return $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, term_id, description FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ($in)", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					}
				);
				if ( $wpdb->last_error ) {
					throw new RuntimeException( __( 'Could not read taxonomy references. Scan stopped before identifying unused images.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				if ( $full ) {
					update_meta_cache( 'term', array_map( static fn( $row ) => (int) $row->term_id, $full ) ); }
				foreach ( (array) $full as $row ) {
					$found = $this->term_findings( $row );
					self::merge_found( $found, $ids, $bn );
					if ( is_array( $tagged ) ) {
						$tagged[] = self::tagged_row( 't', (int) $row->term_id, $found );
					}
				}
			},
			$cursor,
			20
		);
	}

	/**
	 * Shared parser for builder data and arbitrarily named custom fields.
	 *
	 * @param string   $key      Key.
	 * @param mixed    $value    Value.
	 * @param callable $mark_id  Mark id.
	 * @param callable $mark_url Mark url.
	 */
	public function from_meta( string $key, $value, callable $mark_id, callable $mark_url ): void {
		if ( in_array( $key, array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_edit_lock', '_edit_last' ), true ) || str_starts_with( $key, '_lucidlab_mpc' ) ) {
			return; }
		$decoded = ( is_string( $value ) && is_serialized( $value ) ) ? unserialize( trim( $value ), array( 'allowed_classes' => false ) ) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Objects are disabled (allowed_classes false), so stored data cannot instantiate classes.
		if ( is_string( $decoded ) ) {
			$json = json_decode( $decoded, true );
			if ( is_array( $json ) ) {
				$decoded = $json; }
		}
		// A bare numeric custom field can be an ACF image/file ID. Keeping a possible
		// reference is preferable to marking an image unused based on its field name.
		if ( '_product_image_gallery' === $key ) {
			foreach ( LucidLab_MPC_Helpers::split_ids( is_scalar( $decoded ) ? (string) $decoded : '' ) as $gid ) {
				$mark_id( $gid );
			}
		}
		if ( is_scalar( $decoded ) && ctype_digit( trim( (string) $decoded ) ) ) {
			$mark_id( (int) $decoded ); }
		$this->from_mixed( array( $key => $decoded ), $mark_id, $mark_url );
	}

	/**
	 * Quick single-query sources: featured, gallery, term thumbs, theme mods, widgets, site icon.
	 *
	 * @param array       &$ids    Ids.
	 * @param array       &$bn     Bn.
	 * @param array&|null $tagged Tagged.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	public function collect_static( array &$ids, array &$bn, ?array &$tagged = null ): void {
		global $wpdb;
		$mark_id  = static function ( $id ) use ( &$ids ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[ $id ] = 1; }
		};
		$mark_url = static function ( $url ) use ( &$bn ) {
			$b = LucidLab_MPC_Helpers::url_to_basename( (string) $url );
			if ( '' !== $b ) {
				$bn[ $b ] = 1; }
		};

		foreach ( (array) $this->timed_content(
			static function () use ( $wpdb ) {
				return $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			}
		) as $tid ) {
			$mark_id( $tid );
		}
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not read featured-image references. Scan is incomplete.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		foreach ( (array) $this->timed_content(
			static function () use ( $wpdb ) {
				return $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			}
		) as $g ) {
			foreach ( LucidLab_MPC_Helpers::split_ids( (string) $g ) as $gid ) {
				$mark_id( $gid );
			}
		}
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not read gallery references. Scan is incomplete.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		foreach ( (array) $this->timed_content(
			static function () use ( $wpdb ) {
				return $wpdb->get_col( "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_key = 'thumbnail_id'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			}
		) as $tt ) {
			$mark_id( $tt );
		}
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not read category-image references. Scan is incomplete.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.

		$stylesheet = (string) get_option( 'stylesheet' );
		$template   = (string) get_option( 'template' );
		foreach ( array_unique( array_filter( array( $stylesheet, $template ) ) ) as $theme ) {
			$mods = get_option( 'theme_mods_' . $theme );
			if ( is_array( $mods ) ) {
				$this->from_mixed( $mods, $mark_id, $mark_url );
				if ( ! empty( $mods['custom_logo'] ) ) {
					$mark_id( $mods['custom_logo'] );
				}
			}
		}
		$icon = (int) get_option( 'site_icon' );
		if ( $icon ) {
			$mark_id( $icon );
		}
		// WooCommerce stores the placeholder as an ID or a URL, the email header as a URL.
		foreach ( array( 'woocommerce_placeholder_image', 'site_logo', 'woocommerce_email_header_image' ) as $option ) {
			$value = get_option( $option );
			if ( is_numeric( $value ) ) {
				$mark_id( $value );
			} elseif ( is_string( $value ) && '' !== $value ) {
				$mark_url( $value );
			}
		}
		// Media widgets plus HTML/text/block widgets (footer logos, social icons, payment badges).
		foreach ( array( 'widget_media_image', 'widget_media_gallery', 'widget_media_video', 'widget_custom_html', 'widget_text', 'widget_block' ) as $w ) {
			$opt = get_option( $w );
			if ( is_array( $opt ) ) {
				$this->from_mixed( $opt, $mark_id, $mark_url );
			} elseif ( is_string( $opt ) && '' !== $opt ) {
				$this->collect_urls( $opt, $mark_url );
			}
		}
		if ( is_array( $tagged ) ) {
			$tagged[] = self::tagged_row(
				'x',
				0,
				array(
					'ids'  => array_keys( $ids ),
					'urls' => array_keys( $bn ),
				)
			);
		}
	}

	/**
	 * One slice of the static reference pass. $part advances thumbs → galleries → terms → theme → done. Used when a deadline is set so a shop with thousands of featured images does not hold one request.
	 *
	 * @param string &$part   Part.
	 * @param int    &$cursor Cursor.
	 * @param array  &$ids    Ids.
	 * @param array  &$bn     Bn.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return int
	 */
	public function collect_static_part( string &$part, int &$cursor, array &$ids, array &$bn ): int {
		global $wpdb;
		$limit = 1500;
		$mark  = static function ( $id ) use ( &$ids ): void {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[ $id ] = 1;
			}
		};
		if ( 'thumbs' === $part ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare(
					"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
					$cursor,
					$limit
				)
			);
			if ( $wpdb->last_error ) {
				throw new RuntimeException( __( 'Could not read featured-image references. Scan is incomplete.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			foreach ( (array) $rows as $row ) {
				$cursor = (int) $row->meta_id;
				$mark( $row->meta_value );
			}
			if ( count( (array) $rows ) < $limit ) {
				$part   = 'galleries';
				$cursor = 0;
			}
			return count( (array) $rows );
		}
		if ( 'galleries' === $part ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare(
					"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
					$cursor,
					$limit
				)
			);
			if ( $wpdb->last_error ) {
				throw new RuntimeException( __( 'Could not read gallery references. Scan is incomplete.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			foreach ( (array) $rows as $row ) {
				$cursor = (int) $row->meta_id;
				foreach ( LucidLab_MPC_Helpers::split_ids( (string) $row->meta_value ) as $gid ) {
					$mark( $gid );
				}
			}
			if ( count( (array) $rows ) < $limit ) {
				$part   = 'terms';
				$cursor = 0;
			}
			return count( (array) $rows );
		}
		if ( 'terms' === $part ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare(
					"SELECT meta_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = 'thumbnail_id' AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
					$cursor,
					$limit
				)
			);
			if ( $wpdb->last_error ) {
				throw new RuntimeException( __( 'Could not read category-image references. Scan is incomplete.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			foreach ( (array) $rows as $row ) {
				$cursor = (int) $row->meta_id;
				$mark( $row->meta_value );
			}
			if ( count( (array) $rows ) < $limit ) {
				$part   = 'theme';
				$cursor = 0;
			}
			return count( (array) $rows );
		}
		$mark_url   = static function ( $url ) use ( &$bn ): void {
			$b = LucidLab_MPC_Helpers::url_to_basename( (string) $url );
			if ( '' !== $b ) {
				$bn[ $b ] = 1;
			}
		};
		$stylesheet = (string) get_option( 'stylesheet' );
		$template   = (string) get_option( 'template' );
		foreach ( array_unique( array_filter( array( $stylesheet, $template ) ) ) as $theme ) {
			$mods = get_option( 'theme_mods_' . $theme );
			if ( is_array( $mods ) ) {
				$this->from_mixed( $mods, $mark, $mark_url );
				if ( ! empty( $mods['custom_logo'] ) ) {
					$mark( $mods['custom_logo'] );
				}
			}
		}
		$icon = (int) get_option( 'site_icon' );
		if ( $icon ) {
			$mark( $icon );
		}
		foreach ( array( 'woocommerce_placeholder_image', 'site_logo', 'woocommerce_email_header_image' ) as $option ) {
			$value = get_option( $option );
			if ( is_numeric( $value ) ) {
				$mark( $value );
			} elseif ( is_string( $value ) && '' !== $value ) {
				$mark_url( $value );
			}
		}
		foreach ( array( 'widget_media_image', 'widget_media_gallery', 'widget_media_video', 'widget_custom_html', 'widget_text', 'widget_block' ) as $w ) {
			$opt = get_option( $w );
			if ( is_array( $opt ) ) {
				$this->from_mixed( $opt, $mark, $mark_url );
			} elseif ( is_string( $opt ) && '' !== $opt ) {
				$this->collect_urls( $opt, $mark_url );
			}
		}
		$part = 'done';
		return 1;
	}

	/**
	 * One batch of post_content: Flatsome shortcodes + Gutenberg blocks + URLs/srcset.
	 *
	 * @param array       &$ids    Ids.
	 * @param array       &$bn     Bn.
	 * @param int         &$cursor Cursor.
	 * @param array&|null $tagged Tagged.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return int
	 */
	public function posts_step( array &$ids, array &$bn, int &$cursor, ?array &$tagged = null ): int {
		global $wpdb;
		$limit = $this->indexing_prepare( max( 1, (int) LucidLab_MPC_Settings::get( 'scan_batch_db' ) ), 200 );
		$rows  = $this->timed_content(
			static function () use ( $wpdb, $cursor, $limit ) {
				return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts}
					 WHERE (post_content <> '' OR post_excerpt <> '') AND post_status NOT IN ('trash','auto-draft') AND ID > %d
					 ORDER BY ID ASC LIMIT %d",
						$cursor,
						$limit
					)
				);
			}
		);
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Reference query failed; scan stopped before identifying unused images.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		return $this->index_ids(
			(array) $rows,
			static function ( $row ) {
				return (int) $row->ID;
			},
			function ( array $chunk ) use ( &$ids, &$bn, &$tagged ) {
				global $wpdb;
				$chunk        = array_map( 'intval', $chunk );
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				$full         = $this->timed_content(
					static function () use ( $wpdb, $chunk, $placeholders ) {
						return $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID IN ($placeholders)", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					}
				);
				if ( $wpdb->last_error ) {
					throw new RuntimeException( __( 'Reference query failed; scan stopped before identifying unused images.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				$this->apply_post_rows( (array) $full, $ids, $bn, $tagged );
			},
			$cursor,
			25
		);
	}

	/**
	 * Re-read specific posts after they change. Returns the number of requested IDs.
	 *
	 * @param array $post_ids Post ids.
	 * @param array &$tagged   Tagged.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return int
	 */
	public function posts_for_ids( array $post_ids, array &$tagged ): int {
		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
		if ( ! $post_ids ) {
			return 0;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$rows         = $this->timed_content(
			static function () use ( $wpdb, $post_ids, $placeholders ) {
				return $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_status NOT IN ('trash','auto-draft')", $post_ids ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			}
		);
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Reference query failed; scan stopped before identifying unused images.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		$ids = array();
		$bn  = array();
		$this->apply_post_rows( (array) $rows, $ids, $bn, $tagged );
		return count( $post_ids );
	}

	/**
	 * One keyset batch of custom fields, including serialized builder data.
	 *
	 * @param array       &$ids    Ids.
	 * @param array       &$bn     Bn.
	 * @param int         &$cursor Cursor.
	 * @param array&|null $tagged Tagged.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return int
	 */
	public function meta_step( array &$ids, array &$bn, int &$cursor, ?array &$tagged = null ): int {
		global $wpdb;
		$limit = $this->indexing_prepare( max( 1, (int) LucidLab_MPC_Settings::get( 'scan_batch_db' ) ) * 20, 5000 );
		if ( null === $this->meta_max_id ) {
			$this->meta_max_id = (int) $wpdb->get_var( "SELECT MAX(meta_id) FROM {$wpdb->postmeta}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		}
		$window_end = $cursor + self::META_WINDOW;
		$skip       = self::meta_skip_keys();
		$holders    = implode( ',', array_fill( 0, count( $skip ), '%s' ) );
		// One indexed range read per window returns the values themselves (up to 64 KB each); only larger builder blobs are
		// fetched again in size-bounded slices. Values without a digit or dot (yes/no flags, plain words) cannot name an attachment ID,
		// an upload path or a file name, so SQL drops them, along with shop keys that never hold media.
		$rows = $this->timed_content(
			static function () use ( $wpdb, $cursor, $window_end, $limit, $skip, $holders ) {
				return $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $holders is a list of %s placeholders built with array_fill(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are passed as one array matching the generated placeholders.
					$wpdb->prepare(
						"SELECT meta_id, post_id, meta_key, CASE WHEN LENGTH(meta_value) <= 65536 THEN meta_value ELSE NULL END AS meta_value, LENGTH(meta_value) AS len FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_id <= %d AND meta_value <> '' AND meta_value REGEXP '[0-9.]' AND meta_key NOT IN ($holders) ORDER BY meta_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- NOT IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare().
						array_merge( array( $cursor, $window_end ), $skip, array( $limit ) )
					)
				);
			}
		);
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Reference query failed; scan stopped before identifying unused images.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		$rows      = (array) $rows;
		$processed = 0;
		$last_id   = $cursor;
		$chunk     = array();
		$bytes     = 0;
		$flush     = function () use ( &$chunk, &$bytes, &$ids, &$bn, &$tagged, &$cursor ) {
			global $wpdb;
			if ( ! $chunk ) {
				return;
			}
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$full         = $this->timed_content(
				static function () use ( $wpdb, $chunk, $placeholders ) {
					return $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id IN ($placeholders)", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				}
			);
			if ( $wpdb->last_error ) {
				throw new RuntimeException( __( 'Reference query failed; scan stopped before identifying unused images.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			$this->apply_meta_rows( (array) $full, $ids, $bn, $tagged );
			$cursor = max( $cursor, max( $chunk ) );
			$chunk  = array();
			$bytes  = 0;
		};
		foreach ( $rows as $row ) {
			if ( $this->indexing_should_yield( $processed ) ) {
				$flush();
				$cursor           = max( $cursor, $last_id );
				$this->batch_open = true;
				return $processed;
			}
			++$processed;
			$last_id = (int) $row->meta_id;
			if ( null !== $row->meta_value ) {
				$this->apply_meta_rows( array( $row ), $ids, $bn, $tagged );
				continue;
			}
			$chunk[] = (int) $row->meta_id;
			$bytes  += (int) $row->len;
			// Fetch large values in slices bounded by size, so builder blobs do not exhaust memory.
			if ( $bytes >= self::META_CHUNK_BYTES || count( $chunk ) >= 50 ) {
				$flush();
			}
		}
		$flush();
		$cursor = max( $cursor, $last_id );
		if ( count( $rows ) >= $limit ) {
			// The window had more rows than one page; continue inside it.
			$this->batch_open = true;
			return $processed;
		}
		$cursor           = max( $cursor, min( $window_end, max( $this->meta_max_id, $cursor ) ) );
		$this->batch_open = $window_end < $this->meta_max_id;
		return $processed;
	}

	/**
	 * Width of one meta_id range read by the reference index.
	 */
	const META_WINDOW = 100000;

	/**
	 * Bytes before an uploads path that can hold its separator, scheme and host.
	 */
	const URL_LOOKBEHIND = 512;

	/**
	 * Settings values above this size are read in slices instead of whole.
	 */
	const HEAVY_SETTING_FILES = 200;
	const HEAVY_OPTION        = 'lucidlab_mpc_ref_heavy_settings';
	const OPTION_SLICE_BYTES  = 1048576;

	/**
	 * Overlap between slices so a URL cut at a slice edge is still found whole.
	 */
	const OPTION_SLICE_OVERLAP = 4096;

	/**
	 * Upper bound for the meta values fetched in one query.
	 */
	const META_CHUNK_BYTES = 1048576;

	/**
	 * Highest meta_id, read once per request.
	 *
	 * @var int|null
	 */
	private ?int $meta_max_id = null;

	/**
	 * Custom field keys that never reference media. Skipping them in SQL keeps large shops fast.
	 *
	 * @return string[]
	 */
	public static function meta_skip_keys(): array {
		$keys = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_page_template', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_price', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to', '_stock', '_stock_status', '_sku', '_manage_stock', '_backorders', '_sold_individually', '_virtual', '_downloadable', '_tax_status', '_tax_class', '_weight', '_length', '_width', '_height', 'total_sales', '_wc_average_rating', '_wc_review_count', '_download_limit', '_download_expiry', '_low_stock_amount', '_global_unique_id', '_menu_item_type', '_menu_item_menu_item_parent', '_menu_item_target', '_menu_item_classes', '_menu_item_xfn', '_encloseme', '_pingme' );
		/**
		 * Filters custom field keys the reference index skips because they never hold media.
		 *
		 * @param string[] $keys Meta keys.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'lucidlab_mpc_reference_skip_meta_keys', $keys ) ) ) );
	}

	/**
	 * Re-read custom fields for specific posts after they change.
	 *
	 * @param array $post_ids Post ids.
	 * @param array &$tagged   Tagged.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return int
	 */
	public function meta_for_posts( array $post_ids, array &$tagged ): int {
		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
		if ( ! $post_ids ) {
			return 0;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$rows         = $this->timed_content(
			static function () use ( $wpdb, $post_ids, $placeholders ) {
				return $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes','_edit_lock','_edit_last')", $post_ids ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			}
		);
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Reference query failed; scan stopped before identifying unused images.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		$ids = array();
		$bn  = array();
		$this->apply_meta_rows( (array) $rows, $ids, $bn, $tagged );
		return count( $post_ids );
	}

	/**
	 * Re-read specific terms after they change.
	 *
	 * @param array $term_ids Term ids.
	 * @param array &$tagged   Tagged.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return int
	 */
	public function terms_for_ids( array $term_ids, array &$tagged ): int {
		$term_ids = array_values( array_filter( array_map( 'intval', $term_ids ) ) );
		if ( ! $term_ids ) {
			return 0;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
		$rows         = $this->timed_content(
			static function () use ( $wpdb, $term_ids, $placeholders ) {
				return $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, term_id, description FROM {$wpdb->term_taxonomy} WHERE term_id IN ($placeholders)", $term_ids ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			}
		);
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not read taxonomy references. Scan stopped before identifying unused images.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		if ( $rows ) {
			update_meta_cache( 'term', $term_ids ); }
		$ids = array();
		$bn  = array();
		foreach ( (array) $rows as $row ) {
			$found = $this->term_findings( $row );
			self::merge_found( $found, $ids, $bn );
			$tagged[] = self::tagged_row( 't', (int) $row->term_id, $found );
		}
		return count( $term_ids );
	}

	/**
	 * One batch of attachments compared against the finished reference.
	 *
	 * @param array $ids    Ids.
	 * @param array $bn     Bn.
	 * @param int   &$cursor Cursor.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public function scan_step( array $ids, array $bn, int &$cursor ): array {
		global $wpdb;
		$limit = max( 1, (int) LucidLab_MPC_Settings::get( 'scan_batch_db' ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				 WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d
				 ORDER BY ID ASC LIMIT %d",
				'image/%',
				$cursor,
				$limit
			)
		);
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Attachment query failed; scan is incomplete.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		if ( $rows ) {
			update_meta_cache( 'post', array_map( static fn( $row ) => (int) $row->ID, $rows ) );
		}

		$orphans   = array();
		$protected = 0;
		foreach ( (array) $rows as $r ) {
			$id = (int) $r->ID;
			if ( $id > $cursor ) {
				$cursor = $id;
			}
			// A generated sidecar can have its own attachment record. Its source
			// attachment is the library item; do not offer the derived copy as an orphan.
			$attached   = (string) get_post_meta( $id, '_wp_attached_file', true );
			$source_rel = preg_match( '#^(?:webp|avif)/#i', ltrim( str_replace( '\\', '/', $attached ), '/' ) )
				? LucidLab_MPC_Helpers::generated_copy_source( $attached )
				: '';
			if ( '' !== $source_rel ) {
				$source_path = trailingslashit( LucidLab_MPC_Helpers::uploads_basedir() ) . $source_rel;
				if ( is_file( $source_path ) ) {
					++$protected;
					continue;
				}
			}
			if ( isset( $ids[ $id ] ) ) {
				++$protected;
				continue;
			}
			if ( class_exists( 'LucidLab_MPC_Exclusions', false ) && LucidLab_MPC_Exclusions::skip_attachment( $id ) ) {
				++$protected;
				continue;
			}
			$hit = false;
			foreach ( $this->attachment_basenames( $id ) as $b ) {
				if ( isset( $bn[ $b ] ) ) {
					$hit = true;
					break; }
			}
			if ( $hit ) {
				++$protected;
				continue;
			}
			$size      = $this->attachment_size( $id );
			$orphans[] = array(
				'id'    => $id,
				'title' => (string) $r->post_title,
				'url'   => wp_get_attachment_url( $id ),
				'thumb' => wp_get_attachment_image_url( $id, array( 96, 96 ) ),
				'size'  => $size,
			);
		}
		return array(
			'orphans'   => $orphans,
			'protected' => $protected,
			'count'     => count( (array) $rows ),
		);
	}

	/**
	 * Total image attachments.
	 *
	 * @return int
	 */
	public function total_image_attachments(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
	}

	/**
	 * Synchronous full scan (CSV export + weekly report).
	 *
	 * @return array
	 */
	public function scan_full(): array {
		$file = 'refs-full-' . bin2hex( random_bytes( 6 ) ) . '.jsonl';
		try {
			LucidLab_MPC_Reference_Index::build( $file );
			$ref = LucidLab_MPC_Reference_Index::load( $file );
		} finally {
			LucidLab_MPC_Work::delete( $file );
		}
		$ids        = $ref['ids'];
		$bn         = $ref['bn'];
		$scan_batch = (int) LucidLab_MPC_Settings::get( 'scan_batch_db' );
		$orphans    = array();
		$protected  = 0;
		$scanned    = 0;
		$cursor     = 0;
		do {
			$res        = $this->scan_step( $ids, $bn, $cursor );
			$orphans    = array_merge( $orphans, $res['orphans'] );
			$protected += $res['protected'];
			$scanned   += $res['count'];
		} while ( $scan_batch === $res['count'] );

		$bytes = 0;
		foreach ( $orphans as $x ) {
			$bytes += (int) $x['size'];
		}
		return array(
			'type'          => 'orphans',
			'orphans'       => $orphans,
			'orphan_count'  => count( $orphans ),
			'protected'     => $protected,
			'scanned'       => $scanned,
			'reclaim_bytes' => $bytes,
			'acf_active'    => $this->acf_active(),
		);
	}

	/* ---------- attachment helpers ---------- */

	/**
	 * Attachment basenames.
	 *
	 * @param int $id Id.
	 *
	 * @return array
	 */
	public function attachment_basenames( int $id ): array {
		$names = array();
		$url   = wp_get_attachment_url( $id );
		if ( $url ) {
			$names[] = LucidLab_MPC_Helpers::url_to_basename( $url );
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['file'] ) ) {
				$names[] = LucidLab_MPC_Helpers::url_to_basename( (string) $meta['file'] );
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$names[] = strtolower( wp_basename( (string) $meta['original_image'] ) );
			}
			if ( ! empty( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $s ) {
					if ( ! empty( $s['file'] ) ) {
						$names[] = LucidLab_MPC_Helpers::url_to_basename( (string) $s['file'] );
					}
				}
			}
		}
		return array_values( array_unique( array_filter( $names ) ) );
	}

	/**
	 * Term findings.
	 *
	 * @param object $row Row.
	 *
	 * @return array{ids:array,urls:array}
	 */
	private function term_findings( object $row ): array {
		$found    = array(
			'ids'  => array(),
			'urls' => array(),
		);
		$mark_id  = static function ( $id ) use ( &$found ) {
			$found['ids'][] = $id;
		};
		$mark_url = static function ( $url ) use ( &$found ) {
			$found['urls'][] = $url;
		};
		$this->from_content( (string) $row->description, $mark_id, $mark_url );
		foreach ( get_term_meta( (int) $row->term_id ) as $key => $values ) {
			foreach ( $values as $value ) {
				$this->from_meta( $key, $value, $mark_id, $mark_url ); }
		}
		return $found;
	}

	/**
	 * Apply post rows.
	 *
	 * @param array<int,object> $rows   Rows.
	 * @param array             &$ids    Ids.
	 * @param array             &$bn     Bn.
	 * @param array&|null       $tagged Tagged.
	 */
	private function apply_post_rows( array $rows, array &$ids, array &$bn, ?array &$tagged ): void {
		$parse  = function ( $row ) {
			$found = array(
				'ids'  => array(),
				'urls' => array(),
			);
			$id    = static function ( $value ) use ( &$found ) {
				$found['ids'][] = $value;
			};
			$url   = static function ( $value ) use ( &$found ) {
				$found['urls'][] = $value;
			};
			$this->from_content( (string) $row->post_content, $id, $url );
			$this->from_content( (string) $row->post_excerpt, $id, $url );
			return $found;
		};
		$cached = class_exists( 'LucidLab_MPC_Scan_Cache' ) ? LucidLab_MPC_Scan_Cache::batch( 'posts', $rows, static fn( $row ) => $row->ID, static fn( $row ) => array( $row->post_content, $row->post_excerpt ), $parse ) : array();
		foreach ( $rows as $row ) {
			$found = $cached[ (int) $row->ID ] ?? $parse( $row );
			self::merge_found( $found, $ids, $bn );
			if ( is_array( $tagged ) ) {
				$tagged[] = self::tagged_row( 'p', (int) $row->ID, $found );
			}
		}
	}

	/**
	 * Apply meta rows.
	 *
	 * @param array<int,object> $rows   Rows.
	 * @param array             &$ids    Ids.
	 * @param array             &$bn     Bn.
	 * @param array&|null       $tagged Tagged.
	 */
	private function apply_meta_rows( array $rows, array &$ids, array &$bn, ?array &$tagged ): void {
		$parse  = function ( $row ) {
			$found = array(
				'ids'  => array(),
				'urls' => array(),
			);
			$this->from_meta(
				(string) $row->meta_key,
				$row->meta_value,
				static function ( $id ) use ( &$found ) {
					$found['ids'][] = $id;
				},
				static function ( $url ) use ( &$found ) {
					$found['urls'][] = $url;
				}
			);
			return $found;
		};
		$cached = class_exists( 'LucidLab_MPC_Scan_Cache' ) ? LucidLab_MPC_Scan_Cache::batch( 'postmeta', $rows, static fn( $row ) => $row->meta_id, static fn( $row ) => array( $row->meta_key, $row->meta_value ), $parse ) : array();
		foreach ( $rows as $m ) {
			$found = $cached[ (int) $m->meta_id ] ?? $parse( $m );
			if ( '_product_image_gallery' === (string) $m->meta_key ) {
				foreach ( LucidLab_MPC_Helpers::split_ids( (string) $m->meta_value ) as $gid ) {
					$found['ids'][] = $gid;
				}
			}
			self::merge_found( $found, $ids, $bn );
			if ( is_array( $tagged ) ) {
				$row       = self::tagged_row( 'm', (int) $m->meta_id, $found, (int) ( $m->post_id ?? 0 ) );
				$row['mk'] = (string) $m->meta_key;
				$tagged[]  = $row;
			}
		}
	}

	/**
	 * Merge found.
	 *
	 * @param array{ids?:array,urls?:array} $found Found.
	 * @param array                         &$ids   Ids.
	 * @param array                         &$bn    Bn.
	 */
	public static function merge_found( array $found, array &$ids, array &$bn ): void {
		foreach ( (array) ( $found['ids'] ?? array() ) as $value ) {
			$id = (int) $value;
			if ( $id > 0 ) {
				$ids[ $id ] = 1;
			}
		}
		foreach ( (array) ( $found['urls'] ?? array() ) as $value ) {
			$b = LucidLab_MPC_Helpers::url_to_basename( (string) $value );
			if ( '' === $b ) {
				continue;
			}
			$bn[ $b ] = 1;
			// A reference to photo.jpg.webp also protects photo.jpg (and the reverse basename).
			$source = LucidLab_MPC_Helpers::generated_copy_source( $b );
			if ( '' !== $source ) {
				$bn[ strtolower( basename( $source ) ) ] = 1;
			}
			foreach ( LucidLab_MPC_Helpers::generated_copy_rels( $b ) as $copy_rel ) {
				$bn[ strtolower( basename( $copy_rel ) ) ] = 1;
			}
		}
	}

	/**
	 * Tagged row.
	 *
	 * @param string                        $source  Source.
	 * @param int                           $key     Key.
	 * @param array{ids?:array,urls?:array} $found   Found.
	 * @param int                           $post_id Post id.
	 *
	 * @return array{s:string,k:int,i:array<int,int>,b:array<int,string>,p?:int}
	 */
	public static function tagged_row( string $source, int $key, array $found, int $post_id = 0 ): array {
		$ids = array();
		$bn  = array();
		self::merge_found( $found, $ids, $bn );
		$row = array(
			's' => $source,
			'k' => $key,
			'i' => array_map( 'intval', array_keys( $ids ) ),
			'b' => array_keys( $bn ),
		);
		if ( $post_id > 0 ) {
			$row['p'] = $post_id;
		}
		$rels = array();
		if ( class_exists( 'LucidLab_MPC_Helpers', false ) ) {
			$base      = trailingslashit( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ) );
			$base_fold = strtolower( $base );
			foreach ( (array) ( $found['urls'] ?? array() ) as $url ) {
				$path = LucidLab_MPC_Helpers::uploads_url_to_path( (string) $url );
				if ( '' === $path ) {
					continue;
				}
				$norm = wp_normalize_path( $path );
				if ( str_starts_with( strtolower( $norm ), $base_fold ) ) {
					$rels[ ltrim( substr( $norm, strlen( $base ) ), '/' ) ] = 1;
				}
			}
		}
		if ( $rels ) {
			$row['u'] = array_keys( $rels );
		}
		return $row;
	}

	/**
	 * Attachment size.
	 *
	 * @param int $id Id.
	 *
	 * @return int
	 */
	public function attachment_size( int $id ): int {
		$total = 0;
		$file  = get_attached_file( $id );
		if ( $file && file_exists( $file ) ) {
			$total += (int) filesize( $file );
			$dir    = trailingslashit( dirname( $file ) );
			$meta   = wp_get_attachment_metadata( $id );
			if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $s ) {
					if ( ! empty( $s['file'] ) && file_exists( $dir . $s['file'] ) ) {
						$total += (int) filesize( $dir . $s['file'] );
					}
				}
			}
			if ( is_array( $meta ) && ! empty( $meta['original_image'] ) && file_exists( $dir . $meta['original_image'] ) ) {
				$total += (int) filesize( $dir . $meta['original_image'] );
			}
		}
		return $total;
	}

	/* ---------- parsers ---------- */

	/**
	 * From content.
	 *
	 * @param string   $content  Content.
	 * @param callable $mark_id  Mark id.
	 * @param callable $mark_url Mark url.
	 */
	public function from_content( string $content, callable $mark_id, callable $mark_url ): void {
		if ( '' === $content ) {
			return;
		}
		$this->collect_urls( $content, $mark_url );
		// Classic editor images can retain their ID even when their URL changes.
		if ( preg_match_all( '/\bwp-image-([0-9]+)\b/i', $content, $classes ) ) {
			foreach ( $classes[1] as $id ) {
				$mark_id( (int) $id ); }
		}

		$attrs = 'id|img|bg|image|ids|logo|icon|thumb|thumbnail|bg_image|image_id|mobile_bg|md_bg|lg_bg|background|banner_bg|slider_bg';
		if ( preg_match_all( '/\b(?:' . $attrs . ')\s*=\s*(["\'])([0-9][0-9,\s]*)\1/i', $content, $mm ) ) {
			foreach ( $mm[2] as $list ) {
				foreach ( LucidLab_MPC_Helpers::split_ids( $list ) as $sid ) {
					$mark_id( $sid );
				}
			}
		}
		if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
			$this->walk_blocks( parse_blocks( $content ), $mark_id );
		}
	}

	/**
	 * Walk blocks.
	 *
	 * @param array    $blocks  Blocks.
	 * @param callable $mark_id Mark id.
	 */
	private function walk_blocks( array $blocks, callable $mark_id ): void {
		foreach ( $blocks as $b ) {
			$name  = $b['blockName'] ?? '';
			$attrs = $b['attrs'] ?? array();
			if ( in_array( $name, array( 'core/image', 'core/cover', 'core/file', 'core/audio', 'core/video' ), true ) && isset( $attrs['id'] ) ) {
				$mark_id( $attrs['id'] );
			}
			if ( 'core/media-text' === $name && isset( $attrs['mediaId'] ) ) {
				$mark_id( $attrs['mediaId'] );
			}
			if ( 'core/gallery' === $name && ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
				foreach ( $attrs['ids'] as $gid ) {
					$mark_id( $gid );
				}
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$this->walk_blocks( $b['innerBlocks'], $mark_id );
			}
		}
	}

	/**
	 * From mixed.
	 *
	 * @param mixed    $data     Data.
	 * @param callable $mark_id  Mark id.
	 * @param callable $mark_url Mark url.
	 */
	public function from_mixed( $data, callable $mark_id, callable $mark_url ): void {
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				if ( is_array( $v ) ) {
					$this->from_mixed( $v, $mark_id, $mark_url );
					continue;
				}
				$key = strtolower( (string) $k );
				if ( is_numeric( $v ) && ( is_int( $k ) || str_contains( $key, 'id' ) || str_contains( $key, 'image' ) || str_contains( $key, 'logo' ) || str_contains( $key, 'attachment' ) || str_contains( $key, 'icon' ) || str_contains( $key, 'thumb' ) ) ) {
					$mark_id( $v );
				}
				if ( is_string( $v ) ) {
					$this->from_content( $v, $mark_id, $mark_url );
				}
			}
		} elseif ( is_string( $data ) ) {
			$this->from_content( $data, $mark_id, $mark_url );
		}
	}

	/**
	 * Collect urls.
	 *
	 * @param string   $blob     Blob.
	 * @param callable $mark_url Mark url.
	 */
	public function collect_urls( string $blob, callable $mark_url ): void {
		if ( '' === $blob ) {
			return;
		}
		// JSON builders escape slashes; custom upload directories need not contain "uploads".
		$blob         = html_entity_decode( str_replace( array( '\\/', '\\u002F', '\\u002f' ), '/', $blob ), ENT_QUOTES, 'UTF-8' );
		$uploads      = wp_upload_dir();
		$path         = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		$roots        = array_unique( array_filter( array( '/wp-content/uploads/', $path ? rtrim( $path, '/' ) . '/' : '' ) ) );
		$root_pattern = implode( '|', array_map( static fn( $root ) => preg_quote( $root, '#' ), $roots ) );
		$accepted     = array();
		if ( preg_match_all( '#(?:' . $root_pattern . ')[^\s"\'<>()\\\\]+?\.(?:jpe?g|png|gif|webp|avif|svg|bmp|tiff?|ico|heic|pdf|mp4|m4v|mov|webm|mp3|m4a|ogg|wav|zip|docx?|xlsx?|pptx?)(?=[?\#\s"\'<>()\\\\,]|$)#i', $blob, $um, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $um[0] as $hit ) {
				$u  = (string) $hit[0];
				$at = (int) $hit[1];
				// A real uploads URL sits at the start of the path. Nested copies such as
				// backups-dup-pro/imports/wp-content/uploads/file.jpg are a different folder.
				// Only the text just before the hit matters (a separator plus an optional scheme and host).
				// Copying and scanning the whole prefix for every hit made large values quadratic:
				// a 7.5 MB option with 8,000 image URLs took 78 seconds and the request was killed.
				$from   = max( 0, $at - self::URL_LOOKBEHIND );
				$prefix = substr( $blob, $from, $at - $from );
				$lead   = 0 === $from ? '(?:^|[\\s"\'=(,])' : '[\\s"\'=(,]';
				if ( ! preg_match( '~' . $lead . '(?:(?:https?:)?//[^/\\s"\']+)?$~i', $prefix ) ) {
					continue;
				}
				$accepted[] = array( $at, strlen( $u ) );
				$mark_url( $u );
			}
		}
		if ( $accepted ) {
			// Cut the accepted hits out in one pass. str_replace() with thousands of needles rescanned the whole value per needle.
			$rest = '';
			$pos  = 0;
			foreach ( $accepted as $hit ) {
				$rest .= substr( $blob, $pos, $hit[0] - $pos );
				$pos   = $hit[0] + $hit[1];
			}
			$blob = $rest . substr( $blob, $pos );
		}
		// Relative filenames also occur in custom fields and plugin settings.
		// Index them conservatively instead of rediscovering them at quarantine.
		if ( preg_match_all( '~[^\s"\'<>/\\\\:;,{}\[\]]+\.(?:jpe?g|png|gif|webp|avif|svg|bmp|tiff?|ico|heic|pdf|mp4|m4v|mov|webm|mp3|m4a|ogg|wav|zip|docx?|xlsx?|pptx?)(?=[?#\s"\'<>\\\\,;}]|$)~i', $blob, $files ) ) {
			foreach ( $files[0] as $file ) {
				$mark_url( $file ); }
		}
	}

	/**
	 * Elementor ids.
	 *
	 * @param array    $nodes   Nodes.
	 * @param callable $mark_id Mark id.
	 */
	public function elementor_ids( array $nodes, callable $mark_id ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			foreach ( $node as $v ) {
				if ( is_array( $v ) ) {
					if ( isset( $v['id'], $v['url'] ) && str_contains( (string) $v['url'], '/uploads/' ) ) {
						$mark_id( $v['id'] );
					}
					$this->elementor_ids( array( $v ), $mark_id );
				}
			}
		}
	}
}
