<?php
/**
 * Lucid Lab Media & Products Cleanup: preview class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only impact summary for quarantine and merge confirmations. */
class LucidLab_MPC_Preview {

	const SAMPLE_LIMIT = 5;

	/**
	 * Summarize.
	 *
	 * @param array $items Items.
	 *
	 * @return array
	 */
	public static function summarize( array $items ): array {
		$count   = 0;
		$bytes   = 0;
		$files   = 0;
		$samples = array();
		foreach ( $items as $item ) {
			if ( is_numeric( $item ) ) {
				$item = array( 'id' => (int) $item );
			} elseif ( is_string( $item ) ) {
				$item = array(
					'file'  => $item,
					'label' => $item,
				);
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			++$count;
			$item_bytes = max( 0, (int) ( $item['bytes'] ?? $item['size'] ?? 0 ) );
			$item_files = max( 1, (int) ( $item['count'] ?? 1 ) );
			$bytes     += $item_bytes;
			$files     += $item_files;
			if ( count( $samples ) >= self::SAMPLE_LIMIT ) {
				continue;
			}
			$id    = (int) ( $item['id'] ?? 0 );
			$label = (string) ( $item['label'] ?? $item['file'] ?? $item['title'] ?? $item['dim'] ?? '' );
			if ( '' === $label ) {
				$label = $id ? ( '#' . $id ) : 'item';
			}
			$samples[] = array(
				'id'    => $id,
				'label' => $label,
				'bytes' => $item_bytes,
			);
		}

		return array(
			'count'       => $count,
			'bytes'       => $bytes,
			'files'       => $files,
			'samples'     => $samples,
			'more'        => max( 0, $count - count( $samples ) ),
			'bytes_label' => self::bytes_label( $bytes ),
			'destructive' => false,
		);
	}

	/**
	 * From merge pairs.
	 *
	 * @param array $pairs Pairs.
	 *
	 * @return array
	 */
	public static function from_merge_pairs( array $pairs ): array {
		$items = array();
		$keeps = array();
		$by    = array();
		foreach ( $pairs as $pair ) {
			if ( is_array( $pair ) && (int) ( $pair['dup'] ?? 0 ) > 0 ) {
				$by[ (int) ( $pair['keep'] ?? 0 ) ][] = (int) $pair['dup'];
			}
		}
		// Count what is really freed: a duplicate record that uses the same file as its Keep image (or as
		// another duplicate) frees nothing, because the file stays in place.
		$freed = array();
		if ( class_exists( 'LucidLab_MPC_DupContent', false ) ) {
			$path_of = array( new LucidLab_MPC_DupContent(), 'original_path' );
			foreach ( $by as $keep => $dups ) {
				$freed += LucidLab_MPC_DupContent::freed_bytes( $dups, (int) $keep, $path_of );
			}
		}
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$dup  = (int) ( $pair['dup'] ?? 0 );
			$keep = (int) ( $pair['keep'] ?? 0 );
			if ( $keep ) {
				$keeps[] = $keep;
			}
			$same    = (int) ( $freed[ $dup ]['same_as'] ?? 0 );
			$items[] = array(
				'id'    => $dup,
				'label' => $dup ? ( '#' . $dup . ' → keep #' . $keep . ( $same ? ' ' . __( '(same file, frees no space)', 'lucidlab-mpc-cleanup' ) : '' ) ) : '',
				'bytes' => isset( $freed[ $dup ] ) ? (int) $freed[ $dup ]['bytes'] : (int) ( $pair['bytes'] ?? 0 ),
			);
		}
		$out          = self::summarize( $items );
		$out['keeps'] = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::int_ids( $keeps ) : array_values( array_unique( array_filter( $keeps ) ) );
		return $out;
	}

	/**
	 * Bytes label.
	 *
	 * @param int $bytes Bytes.
	 *
	 * @return string
	 */
	public static function bytes_label( int $bytes ): string {
		return LucidLab_MPC_Helpers::bytes( $bytes );
	}
}
