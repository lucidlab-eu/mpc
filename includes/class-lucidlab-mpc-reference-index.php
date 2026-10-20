<?php
/**
 * Lucid Lab Media & Products Cleanup: reference index class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One content-reference index (attachment IDs and upload basenames) shared by Orphan Images,
 * the Uploads Scanner and the synchronous CSV/report scans. Each build writes to its own work
 * file; a complete build whose content generation is still current is published for reuse.
 *
 * Schema 6 stores one JSONL row per source entity (`s` source, `k` key, `i` ids, `b` basenames,
 * `p` post id for meta, `mk` meta key, `u` upload-relative paths). Unchanged libraries reuse the published file. A short dirty list of
 * posts/terms patches that file instead of re-reading every row.
 */
final class LucidLab_MPC_Reference_Index {
	const SCHEMA = 7; // 7: builds record each finished stage; a build is only used when every stage finished.
	const SHARED = 'refs-index.jsonl';
	/**
	 * Freshness marker for the published shared index. Content edits bump
	 * lucidlab_mpc_index_generation (and the dirty list); quarantine of unused files does not.
	 */
	const META  = 'lucidlab_mpc_cached_ref';
	const DIRTY = 'lucidlab_mpc_index_dirty';
	/** Long enough for Run All stages to reuse one build; generation is the real invalidator. */
	const TTL = 86400;
	/** Seconds of useful work per AJAX tick. Overhead dominates when each tick does one tiny page. */
	const STEP_BUDGET   = 8.0;
	const DIRTY_MAX     = 200;
	const PHASES        = array( 'static', 'terms', 'posts', 'meta', 'options' );
	const PATCH_PHASES  = array( 'patch-static', 'patch-posts', 'patch-meta', 'patch-terms' );
	const OPTIONS_BATCH = 500;
	/** Option and work file of the full build shared by concurrent scans. */
	const BUILD      = 'lucidlab_mpc_ref_build';
	const BUILD_FILE = 'refs-build.jsonl';
	/** Seconds without progress after which a shared build is started again. */
	const BUILD_STALE = 900; // Kept for compatibility; idle builds are resumed, not restarted.

	/**
	 * Content that changed in ways the generation does not track is covered by the short lifetime.
	 *
	 * @return bool
	 */
	public static function fresh(): bool {
		$meta = get_transient( self::META );
		return is_array( $meta )
			&& (int) ( $meta['schema'] ?? 0 ) === self::SCHEMA
			&& (string) ( $meta['generation'] ?? '' ) === (string) get_option( 'lucidlab_mpc_index_generation', 0 )
			&& LucidLab_MPC_Work::exists( self::SHARED );
	}

	/**
	 * Record posts/terms (or a full rebuild) that must be re-read before the next scan.
	 *
	 * @param string $kind Kind.
	 * @param int    $id   Id.
	 */
	public static function touch( string $kind, int $id = 0 ): void {
		self::touch_ids( $kind, $id > 0 ? array( $id ) : array() );
	}

	/**
	 * Touch ids.
	 *
	 * @param string                $kind Kind.
	 * @param array<int,int|string> $ids  Ids.
	 */
	public static function touch_ids( string $kind, array $ids ): void {
		$dirty = self::peek_dirty();
		if ( 'full' === $kind ) {
			$dirty['full'] = true;
		} elseif ( 'posts' === $kind || 'terms' === $kind ) {
			$key = 'posts' === $kind ? 'posts' : 'terms';
			if ( ! isset( $dirty[ $key ] ) || ! is_array( $dirty[ $key ] ) ) {
				$dirty[ $key ] = array();
			}
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$dirty[ $key ][ $id ] = 1;
				}
			}
		}
		$n = count( (array) ( $dirty['posts'] ?? array() ) ) + count( (array) ( $dirty['terms'] ?? array() ) );
		if ( $n > self::DIRTY_MAX ) {
			$dirty['full'] = true;
		}
		update_option( self::DIRTY, $dirty, false );
	}

	/**
	 * Peek dirty.
	 *
	 * @return array{posts?:array<int,int>,terms?:array<int,int>,full?:bool}
	 */
	public static function peek_dirty(): array {
		$dirty = get_option( self::DIRTY, array() );
		return is_array( $dirty ) ? $dirty : array();
	}

	/**
	 * Start a build into $file, or copy a fresh shared index into it (phase is then already 'done').
	 *
	 * @param string $file File.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public static function begin( string $file ): array {
		$state = array(
			'file'       => $file,
			'phase'      => 'static',
			'cursor'     => 0,
			'records'    => 0,
			'generation' => (string) get_option( 'lucidlab_mpc_index_generation', 0 ),
			'reused'     => false,
			'patched'    => false,
		);
		if ( self::fresh() && LucidLab_MPC_Work::copy( self::SHARED, $file ) ) {
			$state['phase']  = 'done';
			$state['reused'] = true;
			return $state;
		}
		$dirty = self::peek_dirty();
		$meta  = get_transient( self::META );
		if ( self::can_patch( $dirty, $meta ) && LucidLab_MPC_Work::copy( self::SHARED, $file ) ) {
			self::drop_dirty_rows( $file, $dirty );
			$state['phase']       = 'patch-static';
			$state['cursor']      = 0;
			$state['patched']     = true;
			$state['dirty']       = $dirty;
			$state['dirty_posts'] = array_values( array_map( 'intval', array_keys( (array) ( $dirty['posts'] ?? array() ) ) ) );
			$state['dirty_terms'] = array_values( array_map( 'intval', array_keys( (array) ( $dirty['terms'] ?? array() ) ) ) );
			return $state;
		}
		// Orphan, Shared and Missing scans join one shared build instead of each reading every table.
		$build = self::shared_build( $state['generation'] );
		LucidLab_MPC_Work::delete( $file );
		$state['phase'] = 'join';
		$state['build'] = self::build_view( $build );
		return $state;
	}

	/**
	 * The shared full build. An unfinished build is always resumed from its saved cursor: content edits
	 * during the build are picked up later through the dirty list, so they never restart it. A finished
	 * build is reused while the content generation matches. Only a missing work file or an older schema
	 * starts over, and a build idle for BUILD_STALE is still resumed, never discarded.
	 *
	 * @param string $generation Content generation.
	 * @param bool   $create     Start a new build when none can be used (false: return what is saved).
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	private static function shared_build( string $generation, bool $create = true ): array {
		wp_cache_delete( self::BUILD, 'options' );
		$build = get_option( self::BUILD );
		if ( is_array( $build ) && 'done' === ( $build['phase'] ?? '' ) && ! self::complete( $build ) ) {
			// A build marked done without every stage on record cannot vouch for an image being unused.
			LucidLab_MPC_Logger::diag( 'reference-index', 'Discarded a finished build that is missing stages.', array( 'completed' => implode( ',', (array) ( $build['completed'] ?? array() ) ) ), 'warning' );
			$build = null;
		}
		if ( is_array( $build ) && (int) ( $build['schema'] ?? 0 ) === self::SCHEMA && LucidLab_MPC_Work::exists( self::BUILD_FILE ) ) {
			if ( 'done' !== ( $build['phase'] ?? '' ) || ! $create || (string) ( $build['generation'] ?? '' ) === $generation || ! self::peek_dirty() ) {
				return $build;
			}
		}
		if ( ! $create && is_array( $build ) && (int) ( $build['schema'] ?? 0 ) === self::SCHEMA && LucidLab_MPC_Work::exists( self::BUILD_FILE ) ) {
			return $build;
		}
		LucidLab_MPC_Work::delete( self::BUILD_FILE );
		// A full build reads everything, so earlier edits need no patch. Edits from now on are recorded again.
		delete_option( self::DIRTY );
		delete_option( LucidLab_MPC_Orphans::HEAVY_OPTION );
		if ( false === file_put_contents( LucidLab_MPC_Work::path( self::BUILD_FILE ), '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			throw new RuntimeException( __( 'Could not create the reference index. Scan stopped.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$build = array(
			'schema'     => self::SCHEMA,
			'file'       => self::BUILD_FILE,
			'phase'      => 'static',
			'cursor'     => 0,
			'records'    => 0,
			'generation' => $generation,
			'reused'     => false,
			'patched'    => false,
			'completed'  => array(),
			'spans'      => self::table_spans(),
			'started'    => time(),
			'updated'    => time(),
		);
		update_option( self::BUILD, $build, false );
		return $build;
	}

	/**
	 * Whether a build finished every stage. Only then may a scan treat an image missing from it as unused.
	 *
	 * @param mixed $build Build state.
	 *
	 * @return bool
	 */
	public static function complete( $build ): bool {
		return is_array( $build )
			&& 'done' === ( $build['phase'] ?? '' )
			&& (int) ( $build['schema'] ?? 0 ) === self::SCHEMA
			&& ! array_diff( self::PHASES, (array) ( $build['completed'] ?? array() ) );
	}

	/**
	 * The part of the shared build a joined scan shows as progress.
	 *
	 * @param array $build Build.
	 *
	 * @return array
	 */
	private static function build_view( array $build ): array {
		return array(
			'phase'   => (string) ( $build['phase'] ?? 'static' ),
			'cursor'  => (int) ( $build['cursor'] ?? 0 ),
			'records' => (int) ( $build['records'] ?? 0 ),
			'spans'   => (array) ( $build['spans'] ?? array() ),
		);
	}

	/**
	 * Advance the shared build for a joined scan. Only one request pumps it at a time; the others wait briefly and report its progress.
	 *
	 * @param array $state  State.
	 * @param float $budget Budget.
	 *
	 * @return int
	 */
	private static function pump_joined( array &$state, float $budget ): int {
		global $wpdb;
		$lock = 'lucidlab_mpc_ref_build_' . md5( $wpdb->prefix );
		$n    = 0;
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			try {
				// A joined scan never restarts the build it joined; it resumes it from the saved cursor.
				$build = self::shared_build( (string) ( $state['generation'] ?? '' ), false );
				if ( 'done' !== ( $build['phase'] ?? '' ) ) {
					$deadline = microtime( true ) + $budget;
					while ( 'done' !== (string) ( $build['phase'] ?? 'done' ) && microtime( true ) < $deadline ) {
						$n += self::step( $build, max( 0.2, $deadline - microtime( true ) ) );
						// Save the cursor after every batch, so a request that dies mid-way loses one batch at most.
						$build['updated'] = time();
						update_option( self::BUILD, $build, false );
					}
				}
			} finally {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			}
		} else {
			// Another scan is reading the tables right now. Wait a moment instead of returning at once.
			usleep( 1500000 );
			wp_cache_delete( self::BUILD, 'options' );
			$build = get_option( self::BUILD );
			$build = is_array( $build ) ? $build : array( 'phase' => 'static' );
		}
		$view = self::build_view( $build );
		// Progress never moves backwards for a scan, even if the build had to be recreated.
		$pct              = (float) ( self::progress(
			array(
				'phase' => 'join',
				'build' => $view,
			)
		) ?? 0 );
		$view['shown']    = max( (float) ( $state['build']['shown'] ?? 0 ), $pct );
		$state['build']   = $view;
		$state['records'] = max( (int) ( $state['records'] ?? 0 ), (int) ( $build['records'] ?? 0 ) );
		if ( self::complete( $build ) && LucidLab_MPC_Work::copy( self::BUILD_FILE, (string) $state['file'] ) ) {
			$state['phase'] = 'done';
			unset( $state['build'] );
		}
		return $n;
	}

	/**
	 * Phase name used for progress labels. A joined scan reports the shared build's phase.
	 *
	 * @param array $state State.
	 *
	 * @return string
	 */
	public static function label_phase( array $state ): string {
		if ( 'join' === ( $state['phase'] ?? '' ) ) {
			return (string) ( $state['build']['phase'] ?? 'static' );
		}
		return (string) ( $state['phase'] ?? '' );
	}

	/**
	 * Highest key of each table the keyset phases walk. Cursors are those keys, so cursor / span is how far through the table the build has read.
	 *
	 * @return array<string,int>
	 */
	private static function table_spans(): array {
		global $wpdb;
		return array(
			'static'  => 0,
			'terms'   => (int) $wpdb->get_var( "SELECT MAX(term_taxonomy_id) FROM {$wpdb->term_taxonomy}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			'posts'   => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ),
			'meta'    => (int) $wpdb->get_var( "SELECT MAX(meta_id) FROM {$wpdb->postmeta}" ),
			'options' => (int) $wpdb->get_var( "SELECT MAX(option_id) FROM {$wpdb->options}" ),
		);
	}

	/**
	 * Share of the build already read, 0–100. Null for builds started without recorded spans, whose length is unknown.
	 *
	 * @param array $state State.
	 *
	 * @return float|null
	 */
	public static function progress( array $state ): ?float {
		if ( 'join' === ( $state['phase'] ?? '' ) ) {
			$shown = (float) ( $state['build']['shown'] ?? 0 );
			$state = (array) ( $state['build'] ?? array() );
			unset( $state['shown'] );
			$read = self::progress( $state );
			return null === $read ? ( $shown > 0 ? $shown : null ) : max( $shown, $read );
		}
		$phase = (string) ( $state['phase'] ?? 'done' );
		if ( 'done' === $phase ) {
			return 100.0;
		}
		if ( str_starts_with( $phase, 'patch-' ) ) {
			$posts = count( (array) ( $state['dirty_posts'] ?? array() ) );
			$spans = array(
				'patch-static' => 1,
				'patch-posts'  => $posts,
				'patch-meta'   => $posts,
				'patch-terms'  => count( (array) ( $state['dirty_terms'] ?? array() ) ),
			);
			$order = self::PATCH_PHASES;
		} elseif ( isset( $state['spans'] ) && is_array( $state['spans'] ) ) {
			$spans = array_map( 'intval', $state['spans'] );
			$order = self::PHASES;
		} else {
			return null;
		}
		$total = array_sum( $spans );
		if ( $total < 1 ) {
			return null;
		}
		$read = 0;
		foreach ( $order as $name ) {
			$span = (int) ( $spans[ $name ] ?? 0 );
			if ( $name === $phase ) {
				$read += min( $span, max( 0, (int) ( $state['cursor'] ?? 0 ) ) );
				break;
			}
			$read += $span;
		}
		// Rows added during the build can push a cursor past its recorded span.
		return min( 99.0, 100 * $read / $total );
	}

	/**
	 * Keep stepping until the time budget is used or the index is done. One AJAX tick used to pay full request overhead for ~40 meta rows; this packs many keyset pages into the same request.
	 *
	 * @param array      &$state  State.
	 * @param float|null $budget Budget.
	 *
	 * @return int
	 */
	public static function pump( array &$state, ?float $budget = null ): int {
		$budget = null === $budget ? self::STEP_BUDGET : max( 0.25, $budget );
		if ( 'join' === ( $state['phase'] ?? '' ) ) {
			return self::pump_joined( $state, $budget );
		}
		$deadline = microtime( true ) + $budget;
		$total    = 0;
		while ( 'done' !== (string) ( $state['phase'] ?? 'done' ) && microtime( true ) < $deadline ) {
			$total += self::step( $state, max( 0.2, $deadline - microtime( true ) ) );
		}
		return $total;
	}

	/**
	 * Process one batch of the current phase. Returns the number of records read.
	 *
	 * @param array      &$state  State.
	 * @param float|null $budget Budget.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return int
	 */
	public static function step( array &$state, ?float $budget = null ): int {
		$phase = (string) ( $state['phase'] ?? 'done' );
		if ( 'done' === $phase ) {
			return 0;
		}
		if ( 'join' === $phase ) {
			return self::pump_joined( $state, null === $budget ? self::STEP_BUDGET : $budget );
		}
		if ( str_starts_with( $phase, 'patch-' ) ) {
			$patched = self::patch_step( $state );
			self::note_items( $patched );
			return $patched;
		}
		$t0     = microtime( true );
		$o      = new LucidLab_MPC_Orphans();
		$ids    = array();
		$bn     = array();
		$tagged = array();
		$cursor = (int) ( $state['cursor'] ?? 0 );
		$batch  = max( 1, (int) LucidLab_MPC_Settings::get( 'scan_batch_db' ) );
		$n      = 0;
		// Yield before PHP/proxy timeouts; pump() passes the remaining request budget.
		if ( property_exists( $o, 'index_deadline' ) ) {
			$o->index_deadline = microtime( true ) + ( null === $budget ? self::STEP_BUDGET : max( 0.2, $budget ) );
		}
		if ( 'static' === $phase ) {
			if ( null !== $o->index_deadline ) {
				$part                   = (string) ( $state['static_part'] ?? 'thumbs' );
				$cursor                 = (int) ( $state['static_cursor'] ?? 0 );
				$n                      = $o->collect_static_part( $part, $cursor, $ids, $bn );
				$state['static_part']   = $part;
				$state['static_cursor'] = $cursor;
				$finished               = ( 'done' === $part );
			} else {
				$o->collect_static( $ids, $bn, $tagged );
				$finished = true;
			}
		} elseif ( 'terms' === $phase ) {
			$n        = $o->terms_step( $ids, $bn, $cursor, $tagged );
			$finished = self::phase_finished( $o, $n, $batch );
		} elseif ( 'posts' === $phase ) {
			$n        = $o->posts_step( $ids, $bn, $cursor, $tagged );
			$finished = self::phase_finished( $o, $n, $batch );
		} elseif ( 'meta' === $phase ) {
			$n        = $o->meta_step( $ids, $bn, $cursor, $tagged );
			$finished = self::phase_finished( $o, $n, $batch );
		} elseif ( 'options' === $phase ) {
			$n        = $o->options_step( $bn, $cursor, $tagged );
			$finished = self::phase_finished( $o, $n, self::OPTIONS_BATCH );
		} else {
			throw new RuntimeException( __( 'Unknown reference index phase.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		self::append_rows( (string) $state['file'], $tagged, $ids, $bn );
		$state['cursor']  = $cursor;
		$state['records'] = (int) ( $state['records'] ?? 0 ) + $n;
		$context          = array(
			'phase'   => $phase,
			'cursor'  => (int) $state['cursor'],
			'read'    => $n,
			'records' => (int) $state['records'],
			'seconds' => round( microtime( true ) - $t0, 2 ),
		);
		if ( $finished ) {
			$state['completed'] = array_values( array_unique( array_merge( (array) ( $state['completed'] ?? array() ), array( $phase ) ) ) );
			$next               = array_search( $phase, self::PHASES, true ) + 1;
			$state['phase']     = self::PHASES[ $next ] ?? 'done';
			$state['cursor']    = 0;
			if ( 'done' === $state['phase'] ) {
				$missing = array_diff( self::PHASES, (array) $state['completed'] );
				if ( $missing ) {
					// Never publish an index with a stage missing: read that stage again instead.
					LucidLab_MPC_Logger::diag( 'reference-index', 'Build reached the end with stages missing; reading them again.', array( 'missing' => implode( ',', $missing ) ), 'warning' );
					$state['phase'] = (string) reset( $missing );
				} else {
					self::publish( $state );
				}
			}
			LucidLab_MPC_Logger::diag( 'reference-index', 'Stage finished.', $context + array( 'next' => (string) $state['phase'] ) );
		} else {
			// One progress line per 30 seconds, plus every batch that alone took over 5 seconds.
			$slow = $context['seconds'] > 5;
			LucidLab_MPC_Logger::diag( $slow ? 'reference-index' : 'reference-index-progress', $slow ? 'Slow batch.' : 'Batch.', $context, $slow ? 'warning' : 'info', $slow ? 0 : 30 );
		}
		self::note_items( $n );
		return $n;
	}

	/**
	 * A short page ends the phase. A deadline yield keeps the phase open even when fewer rows were parsed.
	 *
	 * @param object $parser   Parser.
	 * @param int    $read     Read.
	 * @param int    $fallback Fallback.
	 *
	 * @return bool
	 */
	private static function phase_finished( object $parser, int $read, int $fallback ): bool {
		if ( property_exists( $parser, 'batch_open' ) && ! empty( $parser->batch_open ) ) {
			return false;
		}
		$limit = $fallback;
		if ( property_exists( $parser, 'last_fetch_limit' ) && (int) $parser->last_fetch_limit > 0 ) {
			$limit = (int) $parser->last_fetch_limit;
		}
		return $read < $limit;
	}

	/**
	 * Records rows read during this batch so the scan profile can show a rate.
	 *
	 * @param int $n N.
	 */
	private static function note_items( int $n ): void {
		if ( $n > 0 && class_exists( 'LucidLab_MPC_Scan_Profile', false ) ) {
			LucidLab_MPC_Scan_Profile::count( $n );
		}
	}

	/**
	 * Build (or reuse) a complete index synchronously.
	 *
	 * @param string $file File.
	 *
	 * @return array
	 */
	public static function build( string $file ): array {
		$state = self::begin( $file );
		while ( 'done' !== $state['phase'] ) {
			self::step( $state );
		}
		return $state;
	}

	/**
	 * Content records represented by this index. A reused or patched build's own counter is only the rows touched this pass; the file still holds the full index.
	 *
	 * @param array  $state State.
	 * @param string $file  File.
	 *
	 * @return int
	 */
	public static function covered_records( array $state, string $file ): int {
		$rows = LucidLab_MPC_Work::count_lines( $file );
		if ( ! empty( $state['reused'] ) || ! empty( $state['patched'] ) ) {
			return $rows;
		}
		return max( (int) ( $state['records'] ?? 0 ), $rows );
	}

	/**
	 * Content edited during the build may be missing from it, so only an unchanged generation is shared.
	 *
	 * @param array $state State.
	 */
	private static function publish( array $state ): void {
		// Edits made while the build ran are still in the dirty list, so the next scan patches them in.
		// On a busy shop the generation changes every few seconds; requiring it unchanged meant nothing was ever shared.
		if ( LucidLab_MPC_Work::copy( (string) $state['file'], self::SHARED ) ) {
			set_transient(
				self::META,
				array(
					'schema'     => self::SCHEMA,
					'generation' => $state['generation'],
					'built'      => time(),
					'patched'    => ! empty( $state['patched'] ),
					// A patch starts its own counter at zero. Store the published
					// file's row count so a later reuse is not reported as a handful of rows.
					'records'    => self::covered_records( $state, self::SHARED ),
				),
				self::TTL
			);
			if ( ! empty( $state['dirty'] ) ) {
				self::consume_dirty( (array) $state['dirty'] );
			}
		}
	}

	/**
	 * Load.
	 *
	 * @param string $file File.
	 *
	 * @return array{ids:array<int,int>,bn:array<string,int>}
	 */
	public static function load( string $file ): array {
		return LucidLab_MPC_Work::remember(
			$file,
			static function () use ( $file ) {
				$ref = array(
					'ids' => array(),
					'bn'  => array(),
				);
				LucidLab_MPC_Work::each(
					$file,
					static function ( array $row ) use ( &$ref ) {
						foreach ( (array) ( $row['i'] ?? array() ) as $id ) {
							$ref['ids'][ (int) $id ] = 1;
						}
						foreach ( (array) ( $row['b'] ?? array() ) as $b ) {
							$ref['bn'][ strtolower( (string) $b ) ] = 1;
						}
					}
				);
				return $ref;
			}
		);
	}

	/**
	 * Basenames.
	 *
	 * @param string $file File.
	 *
	 * @return array<string,int> Lowercased basenames.
	 */
	public static function basenames( string $file ): array {
		return self::load( $file )['bn'];
	}

	/**
	 * Whether it can patch.
	 *
	 * @param array<string,mixed> $dirty Dirty.
	 * @param mixed               $meta  Meta.
	 *
	 * @return bool
	 */
	private static function can_patch( array $dirty, $meta ): bool {
		if ( ! is_array( $meta ) || (int) ( $meta['schema'] ?? 0 ) !== self::SCHEMA || ! LucidLab_MPC_Work::exists( self::SHARED ) ) {
			return false;
		}
		if ( ! empty( $dirty['full'] ) ) {
			return false;
		}
		$n = count( (array) ( $dirty['posts'] ?? array() ) ) + count( (array) ( $dirty['terms'] ?? array() ) );
		return $n >= 1 && $n <= self::DIRTY_MAX;
	}

	/**
	 * Drop dirty rows.
	 *
	 * @param string              $file  File.
	 * @param array<string,mixed> $dirty Dirty.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	private static function drop_dirty_rows( string $file, array $dirty ): void {
		$posts = array();
		foreach ( array_keys( (array) ( $dirty['posts'] ?? array() ) ) as $id ) {
			$posts[ (int) $id ] = true;
		}
		$terms = array();
		foreach ( array_keys( (array) ( $dirty['terms'] ?? array() ) ) as $id ) {
			$terms[ (int) $id ] = true;
		}
		LucidLab_MPC_Work::filter(
			$file,
			static function ( array $row ) use ( $posts, $terms ) {
				$s = (string) ( $row['s'] ?? '' );
				$k = (int) ( $row['k'] ?? 0 );
				if ( 'x' === $s ) {
					return false;
				}
				if ( 'p' === $s && isset( $posts[ $k ] ) ) {
					return false;
				}
				if ( 'm' === $s && isset( $posts[ (int) ( $row['p'] ?? 0 ) ] ) ) {
					return false;
				}
				if ( 't' === $s && isset( $terms[ $k ] ) ) {
					return false;
				}
				return true;
			}
		) || throw new RuntimeException( __( 'Could not update the reference index. Scan stopped.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
	}

	/**
	 * Patch step.
	 *
	 * @param array &$state State.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return int
	 */
	private static function patch_step( array &$state ): int {
		$phase  = (string) $state['phase'];
		$o      = new LucidLab_MPC_Orphans();
		$tagged = array();
		$n      = 0;
		$batch  = max( 1, (int) LucidLab_MPC_Settings::get( 'scan_batch_db' ) );
		if ( 'patch-static' === $phase ) {
			$ids = array();
			$bn  = array();
			$o->collect_static( $ids, $bn, $tagged );
			$n        = 1;
			$finished = true;
		} elseif ( 'patch-posts' === $phase ) {
			$all             = (array) ( $state['dirty_posts'] ?? array() );
			$cursor          = (int) ( $state['cursor'] ?? 0 );
			$slice           = array_slice( $all, $cursor, $batch );
			$n               = $o->posts_for_ids( $slice, $tagged );
			$cursor         += count( $slice );
			$state['cursor'] = $cursor;
			$finished        = $cursor >= count( $all );
		} elseif ( 'patch-meta' === $phase ) {
			$all             = (array) ( $state['dirty_posts'] ?? array() );
			$cursor          = (int) ( $state['cursor'] ?? 0 );
			$slice           = array_slice( $all, $cursor, $batch );
			$n               = $o->meta_for_posts( $slice, $tagged );
			$cursor         += count( $slice );
			$state['cursor'] = $cursor;
			$finished        = $cursor >= count( $all );
		} elseif ( 'patch-terms' === $phase ) {
			$all             = (array) ( $state['dirty_terms'] ?? array() );
			$cursor          = (int) ( $state['cursor'] ?? 0 );
			$slice           = array_slice( $all, $cursor, $batch );
			$n               = $o->terms_for_ids( $slice, $tagged );
			$cursor         += count( $slice );
			$state['cursor'] = $cursor;
			$finished        = $cursor >= count( $all );
		} else {
			throw new RuntimeException( __( 'Unknown reference index patch phase.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		self::append_rows( (string) $state['file'], $tagged, array(), array() );
		$state['records'] = (int) ( $state['records'] ?? 0 ) + $n;
		if ( $finished ) {
			$next            = array_search( $phase, self::PATCH_PHASES, true ) + 1;
			$state['phase']  = self::PATCH_PHASES[ $next ] ?? 'done';
			$state['cursor'] = 0;
			if ( 'done' === $state['phase'] ) {
				self::publish( $state );
			}
		}
		return $n;
	}

	/**
	 * Append rows.
	 *
	 * @param string            $file   File.
	 * @param array<int,array>  $tagged Tagged.
	 * @param array<int,int>    $ids    Ids.
	 * @param array<string,int> $bn     Bn.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	private static function append_rows( string $file, array $tagged, array $ids, array $bn ): void {
		if ( $tagged ) {
			if ( ! LucidLab_MPC_Work::append( $file, $tagged ) ) {
				throw new RuntimeException( __( 'Could not save the reference index. Scan stopped.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			return;
		}
		if ( ! $ids && ! $bn ) {
			return;
		}
		if ( ! LucidLab_MPC_Work::append(
			$file,
			array(
				array(
					'i' => array_map( 'intval', array_keys( $ids ) ),
					'b' => array_map( 'strval', array_keys( $bn ) ),
				),
			)
		) ) {
			throw new RuntimeException( __( 'Could not save the reference index. Scan stopped.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
	}

	/**
	 * Consume dirty.
	 *
	 * @param array<string,mixed> $taken Taken.
	 */
	private static function consume_dirty( array $taken ): void {
		$now = self::peek_dirty();
		foreach ( array_keys( (array) ( $taken['posts'] ?? array() ) ) as $id ) {
			unset( $now['posts'][ (int) $id ] );
		}
		foreach ( array_keys( (array) ( $taken['terms'] ?? array() ) ) as $id ) {
			unset( $now['terms'][ (int) $id ] );
		}
		if ( empty( $now['posts'] ) && empty( $now['terms'] ) && empty( $now['full'] ) ) {
			delete_option( self::DIRTY );
			return;
		}
		update_option( self::DIRTY, $now, false );
	}
}
