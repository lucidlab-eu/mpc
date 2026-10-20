<?php
/**
 * Lucid Lab Media & Products Cleanup: scan profile class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Lightweight batch measurements; never enables query logging or stores SQL. */
final class LucidLab_MPC_Scan_Profile {
	/**
	 * Seconds spent inside named query groups during the current batch.
	 *
	 * @var array
	 */
	private static array $timers = array();
	/**
	 * Files or candidates handled during the current batch.
	 *
	 * @var int
	 */
	private static int $items = 0;
	private const SQL_LABELS  = array(
		'media_sql'   => 'media lookup',
		'content_sql' => 'content search',
	);

	/**
	 * Start.
	 *
	 * @return array
	 */
	public static function start(): array {
		global $wpdb;
		self::$timers = array();
		self::$items  = 0;
		return array(
			'at'      => microtime( true ),
			'queries' => (int) $wpdb->num_queries,
			'hits'    => self::reuse_hits(),
			'misses'  => self::reuse_misses(),
		);
	}
	/**
	 * Count.
	 *
	 * @param int $items Items.
	 */
	public static function count( int $items ): void {
		self::$items += max( 0, $items );
	}
	/**
	 * Runs $query and adds its wall time to $section, so database time is known without SAVEQUERIES.
	 *
	 * @param string   $section Section.
	 * @param callable $query   Query.
	 */
	public static function timed( string $section, callable $query ) {
		$at = microtime( true );
		try {
			return $query(); } finally {
			self::$timers[ $section ] = ( self::$timers[ $section ] ?? 0 ) + microtime( true ) - $at; }
	}
	/**
	 * Finish.
	 *
	 * @param string $tab    Tab.
	 * @param string $phase  Phase.
	 * @param array  $start  Start.
	 * @param bool   $failed Failed.
	 */
	public static function finish( string $tab, string $phase, array $start, bool $failed ): void {
		global $wpdb;
		$key  = 'lucidlab_mpc_scan_profile_' . sanitize_key( $tab );
		$rows = 'start' === $phase ? array() : (array) get_option( $key, array() );
		$row  = array(
			'phase'   => $phase,
			'at'      => time(),
			'seconds' => round( microtime( true ) - $start['at'], 4 ),
			'queries' => max( 0, (int) $wpdb->num_queries - $start['queries'] ),
			'peak_mb' => round( memory_get_peak_usage( true ) / 1048576, 1 ),
			'reused'  => self::reuse_hits() - $start['hits'],
			'parsed'  => self::reuse_misses() - $start['misses'],
			'failed'  => $failed,
			'items'   => self::$items,
		);
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && is_array( $wpdb->queries ) ) {
			$row['db_seconds'] = round( array_sum( array_column( array_slice( $wpdb->queries, $start['queries'] ), 1 ) ), 4 );
		}
		$previous = $rows[ $phase ] ?? array();
		foreach ( self::$timers as $section => $seconds ) {
			$row['sql'][ $section ]       = round( $seconds, 4 );
			$row['total_sql'][ $section ] = round( (float) ( $previous['total_sql'][ $section ] ?? 0 ) + $seconds, 4 );
		}
		$row['batches']       = (int) ( $previous['batches'] ?? 0 ) + 1;
		$row['total_seconds'] = round( (float) ( $previous['total_seconds'] ?? 0 ) + $row['seconds'], 4 );
		$row['total_queries'] = (int) ( $previous['total_queries'] ?? 0 ) + $row['queries'];
		$row['total_items']   = (int) ( $previous['total_items'] ?? 0 ) + $row['items'];
		if ( $row['total_items'] > 0 && $row['total_seconds'] > 0 ) {
			$row['items_per_second'] = round( $row['total_items'] / $row['total_seconds'], 1 );
		}
		$rows[ $phase ] = $row;
		update_option( $key, array_slice( $rows, -12, null, true ), false );
	}
	/**
	 * Reuse hits.
	 *
	 * @return int
	 */
	private static function reuse_hits(): int {
		$hits = LucidLab_MPC_Scan_Cache::$hits + ( class_exists( 'LucidLab_MPC_Hash_Cache', false ) ? LucidLab_MPC_Hash_Cache::$hits : 0 );
		return $hits + ( class_exists( 'LucidLab_MPC_Visual', false ) ? LucidLab_MPC_Visual::$pair_hits : 0 );
	}
	/**
	 * Reuse misses.
	 *
	 * @return int
	 */
	private static function reuse_misses(): int {
		$misses = LucidLab_MPC_Scan_Cache::$misses + ( class_exists( 'LucidLab_MPC_Hash_Cache', false ) ? LucidLab_MPC_Hash_Cache::$misses : 0 );
		return $misses + ( class_exists( 'LucidLab_MPC_Visual', false ) ? LucidLab_MPC_Visual::$pair_misses : 0 );
	}
	/**
	 * Accumulated query-group time with its share of the phase's processing time.
	 *
	 * @param array $row Row.
	 *
	 * @return string
	 */
	private static function sql_summary( array $row ): string {
		$parts  = array();
		$labels = array(
			'media_sql'   => __( 'media lookup', 'lucidlab-mpc-cleanup' ),
			'content_sql' => __( 'content search', 'lucidlab-mpc-cleanup' ),
		);
		foreach ( (array) ( $row['total_sql'] ?? array() ) as $section => $seconds ) {
			$share   = (float) ( $row['total_seconds'] ?? 0 ) > 0 ? round( 100 * $seconds / $row['total_seconds'] ) : 0;
			$parts[] = ( $labels[ $section ] ?? $section ) . ' ' . $seconds . 's (' . $share . '%)';
		}
		return $parts ? implode( ', ', $parts ) : '—';
	}
	/**
	 * Render.
	 */
	public static function render(): void {
		echo '<details class="lucidlab-mpc-scan-profile-details"><summary>' . esc_html__( 'Scan performance', 'lucidlab-mpc-cleanup' ) . ( '</summary><p class="lucidlab-mpc-hint">' . esc_html__( 'Latest measured batch and accumulated processing time per phase, excluding time waiting between requests. Totals restart with a new scan. Peak memory is for the PHP request. Database time is available only when query logging was already enabled. Timed SQL is measured for content searches and for the Uploads verification queries. Items are files walked, content records read, or candidates verified; the rate uses accumulated processing time. The table shows the 20 most recent phases.', 'lucidlab-mpc-cleanup' ) . '</p><div class="lucidlab-mpc-table-scroll" role="region" aria-label="' . esc_attr__( 'Scan performance', 'lucidlab-mpc-cleanup' ) . '" tabindex="0"><table class="widefat lucidlab-mpc-table lucidlab-mpc-scan-profile"><thead><tr><th>' . esc_html__( 'Scan / phase', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Measured', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Seconds / DB', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Queries', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Peak MB', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'References reused / parsed', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Batches / total seconds', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Items / per second', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Timed SQL (total)', 'lucidlab-mpc-cleanup' ) . '</th></tr></thead><tbody>' );
		$rows = array();
		foreach ( array( 'uploads', 'orphans', 'sizes', 'fs-sizes', 'webpstats', 'duplicates', 'shared', 'visual', 'dupcontent', 'missing', 'inventory' ) as $tab ) {
			foreach ( (array) get_option( 'lucidlab_mpc_scan_profile_' . $tab, array() ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$row['tab'] = $tab;
				$rows[]     = $row;
			}
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) ( $b['at'] ?? 0 ) <=> (int) ( $a['at'] ?? 0 );
			}
		);
		$rows = array_slice( $rows, 0, 20 );
		if ( ! $rows ) {
			echo ( '<tr><td colspan="9">' . esc_html__( 'Run a scan to collect measurements.', 'lucidlab-mpc-cleanup' ) . '</td></tr>' );
		}
		foreach ( $rows as $row ) {
			$scan_label  = LucidLab_MPC_Helpers::scan_tab_label( (string) $row['tab'] );
			$phase_label = ucwords( str_replace( array( '_', '-' ), ' ', (string) $row['phase'] ) );
			$values      = array( $scan_label . ' / ' . $phase_label . ( ! empty( $row['failed'] ) ? ' (' . __( 'failed', 'lucidlab-mpc-cleanup' ) . ')' : '' ), wp_date( 'Y-m-d H:i:s', (int) $row['at'] ), $row['seconds'] . ' / ' . ( $row['db_seconds'] ?? '—' ), $row['queries'], $row['peak_mb'], $row['reused'] . ' / ' . $row['parsed'], ( $row['batches'] ?? 1 ) . ' / ' . ( $row['total_seconds'] ?? $row['seconds'] ), ! empty( $row['total_items'] ) ? $row['total_items'] . ' / ' . ( $row['items_per_second'] ?? '—' ) : '—', self::sql_summary( $row ) );
			echo '<tr>';
			foreach ( $values as $value ) {
				echo '<td>' . esc_html( (string) $value ) . '</td>';
			}echo '</tr>';
		}
		echo '</tbody></table></div></details>';
	}
}
