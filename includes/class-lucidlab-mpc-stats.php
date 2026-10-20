<?php
/**
 * Lucid Lab Media & Products Cleanup: stats class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight cache of the reclaimable space found by recent scans, surfaced in the
 * admin bar and Site Health. Each scan updates its own slice; nothing is recomputed here,
 * so the figures are "as of the last time you ran that scan".
 */
class LucidLab_MPC_Stats {

	const OPTION = 'lucidlab_mpc_reclaimable';

	/**
	 * Record the bytes/items reclaimable for one source (e.g. 'dupcontent', 'uploads', 'webp').
	 *
	 * @param string $slice   Slice.
	 * @param int    $bytes   Bytes.
	 * @param int    $count   Count.
	 * @param int    $updated Updated.
	 */
	public static function set( string $slice, int $bytes, int $count, int $updated = 0 ): void {
		$all           = self::all();
		$all[ $slice ] = array(
			'bytes'   => max( 0, $bytes ),
			'count'   => max( 0, $count ),
			'updated' => $updated > 0 ? $updated : time(),
		);
		update_option( self::OPTION, $all, false );
		$history         = get_option( 'lucidlab_mpc_stats_history', array() );
		$day             = wp_date( 'Y-m-d' );
		$history[ $day ] = array(
			'bytes'   => self::total_bytes(),
			'count'   => self::total_count(),
			'updated' => time(),
			'slices'  => $all,
		);
		if ( count( $history ) > 90 ) {
			$history = array_slice( $history, -90, 90, true ); }
		update_option( 'lucidlab_mpc_stats_history', $history, false );
	}

	/**
	 * All.
	 *
	 * @return array
	 */
	public static function all(): array {
		$a = get_option( self::OPTION );
		return is_array( $a ) ? $a : array();
	}

	/**
	 * Recover totals from lightweight saved summaries made before scan-result bookkeeping was fixed.
	 */
	public static function sync_from_summaries(): void {
		$fields = array(
			'orphans'    => array( 'reclaim_bytes', 'orphan_count' ),
			'uploads'    => array( 'orphan_bytes', 'orphan_files' ),
			'dupcontent' => array( 'reclaim_bytes', 'dupe_count' ),
			'visual'     => array( 'reclaim_bytes', 'candidate_groups' ),
		);
		$all    = self::all();
		foreach ( $fields as $slice => $keys ) {
			$summary = get_option( 'lucidlab_mpc_summary_' . $slice, array() );
			$counts  = is_array( $summary['counts'] ?? null ) ? $summary['counts'] : array();
			if ( ! array_key_exists( $keys[0], $counts ) || ! array_key_exists( $keys[1], $counts ) ) {
				continue;
			}
			if ( (int) ( $summary['updated'] ?? 0 ) > (int) ( $all[ $slice ]['updated'] ?? 0 ) ) {
				self::set( $slice, (int) $counts[ $keys[0] ], (int) $counts[ $keys[1] ], (int) ( $summary['updated'] ?? 0 ) );
			}
		}
		$summary = get_option( 'lucidlab_mpc_summary_dbcleaner', array() );
		$counts  = is_array( $summary['counts'] ?? null ) ? $summary['counts'] : array();
		if ( array_key_exists( 'bytes', $counts ) && (int) ( $summary['updated'] ?? 0 ) > (int) ( $all['dbcleaner']['updated'] ?? 0 ) ) {
			$count = (int) ( $counts['total'] ?? 0 ) + (int) ( $counts['orphan_records'] ?? 0 );
			self::set( 'dbcleaner', (int) $counts['bytes'], $count, (int) ( $summary['updated'] ?? 0 ) );
		}
	}

	/**
	 * Total bytes.
	 *
	 * @return int
	 */
	public static function total_bytes(): int {
		$t = 0;
		foreach ( self::all() as $s ) {
			$t += (int) ( $s['bytes'] ?? 0 );
		}
		return $t;
	}

	/**
	 * Total count.
	 *
	 * @return int
	 */
	public static function total_count(): int {
		$t = 0;
		foreach ( self::all() as $s ) {
			$t += (int) ( $s['count'] ?? 0 );
		}
		return $t;
	}

	/**
	 * Last updated.
	 *
	 * @return int
	 */
	public static function last_updated(): int {
		$u = 0;
		foreach ( self::all() as $s ) {
			$u = max( $u, (int) ( $s['updated'] ?? 0 ) );
		}
		return $u;
	}

	/**
	 * Human labels for each slice.
	 *
	 * @param string $slice Slice.
	 *
	 * @return string
	 */
	public static function label( string $slice ): string {
		$map = array(
			'dupcontent' => __( 'Duplicate images', 'lucidlab-mpc-cleanup' ),
			'uploads'    => __( 'Orphan uploads', 'lucidlab-mpc-cleanup' ),
			'orphans'    => __( 'Orphan images', 'lucidlab-mpc-cleanup' ),
			'visual'     => __( 'Visually identical images', 'lucidlab-mpc-cleanup' ),
			'dbcleaner'  => __( 'Database cleaner', 'lucidlab-mpc-cleanup' ),
			'sizes'      => __( 'Image sizes', 'lucidlab-mpc-cleanup' ),
			'webp'       => __( 'Generated WebP/AVIF', 'lucidlab-mpc-cleanup' ),
		);
		return $map[ $slice ] ?? $slice;
	}
}
