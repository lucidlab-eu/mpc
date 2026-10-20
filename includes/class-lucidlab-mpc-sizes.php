<?php
/**
 * Lucid Lab Media & Products Cleanup: sizes class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab 4 — Image Sizes: list registered sizes, analyse which dimensions exist across the DB,
 * disable sizes, and quarantine selected generated sizes (removing them from metadata).
 */
class LucidLab_MPC_Sizes {
	use LucidLab_MPC_Sizes_Pro_Methods;

	/**
	 * Name => ['width','height','crop','disabled'].
	 *
	 * @return array
	 */
	public function registered(): array {
		$disabled = (array) LucidLab_MPC_Settings::get( 'disabled_sizes' );
		$out      = array();
		$subsizes = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
		foreach ( array( 'thumbnail', 'medium', 'medium_large', 'large' ) as $core ) {
			if ( isset( $subsizes[ $core ] ) ) {
				continue;
			}
			$width  = (int) get_option( $core . '_size_w' );
			$height = (int) get_option( $core . '_size_h' );
			if ( $width < 1 && $height < 1 ) {
				continue;
			}
			$subsizes[ $core ] = array(
				'width'  => $width,
				'height' => $height,
				'crop'   => (bool) get_option( $core . '_crop' ),
			);
		}
		foreach ( (array) $subsizes as $name => $s ) {
			$out[ $name ] = array(
				'width'    => (int) ( $s['width'] ?? 0 ),
				'height'   => (int) ( $s['height'] ?? 0 ),
				'crop'     => ! empty( $s['crop'] ),
				'disabled' => in_array( $name, $disabled, true ),
			);
		}
		return $out;
	}

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
	 * @param int $offset Offset.
	 *
	 * @return array
	 */
	private function image_ids( int $offset ): array {
		global $wpdb;
		return array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE %s ORDER BY ID ASC LIMIT %d OFFSET %d",
					$wpdb->esc_like( 'image/' ) . '%',
					(int) LucidLab_MPC_Settings::get( 'batch_db' ),
					$offset
				)
			)
		);
	}

	/**
	 * Analyze step.
	 *
	 * @param int   $offset Offset.
	 * @param array &$counts Counts.
	 *
	 * @return int
	 */
	public function analyze_step( int $offset, array &$counts ): int {
		$ids = class_exists( 'LucidLab_MPC_Scan_Profile', false ) ? LucidLab_MPC_Scan_Profile::timed(
			'media_sql',
			function () use ( $offset ) {
				return $this->image_ids( $offset );
			}
		) : $this->image_ids( $offset );
		if ( class_exists( 'LucidLab_MPC_Scan_Profile', false ) ) {
			LucidLab_MPC_Scan_Profile::count( count( $ids ) );
		}
		// Fetch attachment metadata once for the batch instead of once per image.
		if ( $ids ) {
			update_meta_cache( 'post', $ids ); }
		foreach ( $ids as $id ) {
			$meta = wp_get_attachment_metadata( $id );
			if ( ! is_array( $meta ) || empty( $meta['sizes'] ) ) {
				continue;
			}
			$file = get_attached_file( $id );
			$dir  = $file ? trailingslashit( dirname( $file ) ) : '';
			foreach ( $meta['sizes'] as $name => $s ) {
				if ( empty( $counts[ $name ] ) ) {
					$counts[ $name ] = array(
						'n'     => 0,
						'bytes' => 0,
					);
				}
				++$counts[ $name ]['n'];
				if ( $dir && ! empty( $s['file'] ) && file_exists( $dir . $s['file'] ) ) {
					$counts[ $name ]['bytes'] += (int) filesize( $dir . $s['file'] );
				}
			}
		}
		return count( $ids );
	}
}
