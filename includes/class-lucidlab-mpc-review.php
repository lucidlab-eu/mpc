<?php
/**
 * Lucid Lab Media & Products Cleanup: review class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Shared, read-only normalization for Review Queue findings. */
class LucidLab_MPC_Review {
	/**
	 * From result.
	 *
	 * @param string $source Source.
	 * @param array  $result Result.
	 *
	 * @return array
	 */
	public static function from_result( string $source, array $result ): array {
		$out = array();
		if ( 'orphans' === $source ) {
			foreach ( (array) ( $result['orphans'] ?? array() ) as $item ) {
				$out[] = self::finding( $source, (int) ( $item['id'] ?? 0 ), (string) ( $item['file'] ?? '' ), (int) ( $item['size'] ?? 0 ), array(), array( 'review', 'quarantine' ) ); }
		} elseif ( 'shared' === $source ) {
			foreach ( (array) ( $result['images'] ?? array() ) as $item ) {
				$refs  = (array) ( $item['products'] ?? $item['references'] ?? array() );
				$out[] = self::finding( $source, (int) ( $item['id'] ?? 0 ), '', 0, $refs, array( 'review', 'quarantine' ) ); }
		} elseif ( in_array( $source, array( 'dupcontent', 'visual' ), true ) ) {
			foreach ( (array) ( $result['groups'] ?? array() ) as $group ) {
				$members = (array) ( $group['members'] ?? $group );
				$ids     = array_map( 'intval', array_filter( array_map( static fn( $m ) => is_array( $m ) ? ( $m['id'] ?? 0 ) : $m, $members ) ) );
				if ( count( $ids ) > 1 ) {
					$refs  = array_map( static fn( $id ) => array( 'id' => $id ), $ids );
					$out[] = self::finding( $source, $ids[0], '', 0, $refs, array( 'review', 'merge' ) ); }
			}
		}
		if ( in_array( $source, array( 'uploads', 'sizes' ), true ) ) {
			$items = $result['orphan_units'] ?? $result['items'] ?? array();
			foreach ( (array) $items as $key => $item ) {
				if ( is_array( $item ) && isset( $item['files'] ) ) {
					foreach ( (array) $item['files'] as $file ) {
						$out[] = self::finding( $source, 0, (string) ( $file['rel'] ?? $file['file'] ?? $key ), (int) ( $file['bytes'] ?? $file['size'] ?? 0 ), array(), array( 'review', 'quarantine' ) );
					}
				} elseif ( is_array( $item ) ) {
					$out[] = self::finding( $source, (int) ( $item['id'] ?? 0 ), (string) ( $item['file'] ?? $key ), (int) ( $item['bytes'] ?? $item['size'] ?? 0 ), array(), array( 'review', 'quarantine' ) ); }
			}
		}
		return $out;
	}
	/**
	 * Finding.
	 *
	 * @param string $source     Source.
	 * @param int    $id         Id.
	 * @param string $file       File.
	 * @param int    $bytes      Bytes.
	 * @param array  $references References.
	 * @param array  $actions    Actions.
	 *
	 * @return array
	 */
	public static function finding( string $source, int $id = 0, string $file = '', int $bytes = 0, array $references = array(), array $actions = array() ): array {
		$allowed = array( 'duplicates', 'orphans', 'uploads', 'shared', 'sizes', 'dupcontent', 'visual', 'missing' );
		return array(
			'source'     => in_array( $source, $allowed, true ) ? $source : 'unknown',
			'id'         => max( 0, $id ),
			'file'       => function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $file ) : preg_replace( '/[^A-Za-z0-9._\/-]/', '', $file ),
			'bytes'      => max( 0, $bytes ),
			'references' => array_values( array_filter( $references, 'is_array' ) ),
			'actions'    => array_values( array_filter( $actions, 'is_string' ) ),
		);
	}

	/**
	 * Risk.
	 *
	 * @param array $finding Finding.
	 *
	 * @return string
	 */
	public static function risk( array $finding ): string {
		$bytes  = (int) ( $finding['bytes'] ?? 0 );
		$count  = (int) ( $finding['count'] ?? 0 );
		$source = (string) ( $finding['source'] ?? '' );
		// Shared images are still in use. Any list with nothing to reclaim, including Missing Images, is not a cleanup risk.
		if ( 'shared' === $source || $bytes < 1 ) {
			return 'low';
		}
		if ( $bytes >= 10485760 || $count >= 100 ) {
			return 'high';
		}
		if ( $bytes >= 1048576 || $count >= 10 ) {
			return 'medium';
		}
		return 'low';
	}

	/**
	 * Matches filters.
	 *
	 * @param array $finding Finding.
	 * @param array $filters Filters.
	 *
	 * @return bool
	 */
	public static function matches_filters( array $finding, array $filters ): bool {
		$source    = (string) ( $filters['source'] ?? 'all' );
		$risk      = (string) ( $filters['risk'] ?? 'all' );
		$status    = (string) ( $filters['status'] ?? 'all' );
		$min       = max( 0, (int) ( $filters['min'] ?? 0 ) );
		$min_bytes = max( 0, (int) ( $filters['min_bytes'] ?? 0 ) );
		if ( 'all' !== $source && ( $finding['source'] ?? '' ) !== $source ) {
			return false;
		}
		if ( (int) ( $finding['count'] ?? 0 ) < $min || (int) ( $finding['bytes'] ?? 0 ) < $min_bytes ) {
			return false;
		}
		if ( 'all' !== $risk && self::risk( $finding ) !== $risk ) {
			return false;
		}
		if ( 'all' !== $status && ( $finding['status'] ?? 'active' ) !== $status ) {
			return false;
		}
		return true;
	}

	/**
	 * Image similarity.
	 *
	 * @param int $first_id  First id.
	 * @param int $second_id Second id.
	 *
	 * @return float
	 */
	public static function image_similarity( int $first_id, int $second_id ): float {
		if ( $first_id <= 0 || $second_id <= 0 || $first_id === $second_id ) {
			return 100.0;
		}

		$first_file = wp_get_original_image_path( $first_id );
		if ( ! $first_file ) {
			$first_file = get_attached_file( $first_id );
		}
		$second_file = wp_get_original_image_path( $second_id );
		if ( ! $second_file ) {
			$second_file = get_attached_file( $second_id );
		}
		if ( ! $first_file || ! is_file( $first_file ) || ! $second_file || ! is_file( $second_file ) ) {
			return 0.0;
		}

		// Combine perceptual hash and metadata scores.
		$perceptual_score = self::perceptual_hash_similarity( $first_file, $second_file );
		$metadata_score   = self::metadata_similarity( $first_id, $second_id );

		// Weight perceptual more heavily, but penalize metadata mismatch.
		$combined = ( $perceptual_score * 0.8 ) + ( $metadata_score * 0.2 );
		return round( max( 0.0, min( 100.0, $combined ) ), 1 );
	}

	/**
	 * Perceptual hash similarity.
	 *
	 * @param string $first_file  First file.
	 * @param string $second_file Second file.
	 *
	 * @return float
	 */
	private static function perceptual_hash_similarity( string $first_file, string $second_file ): float {
		$first_hash  = self::compute_perceptual_hash( $first_file );
		$second_hash = self::compute_perceptual_hash( $second_file );

		if ( empty( $first_hash ) || empty( $second_hash ) ) {
			return 0.0;
		}

		// Hamming distance for hash comparison.
		$diff     = 0;
		$hash_len = strlen( $first_hash );
		for ( $i = 0; $i < $hash_len; $i++ ) {
			if ( $first_hash[ $i ] !== $second_hash[ $i ] ) {
				++$diff;
			}
		}

		$max_diff = strlen( $first_hash );
		return $max_diff > 0 ? 100.0 * ( 1 - ( $diff / $max_diff ) ) : 0.0;
	}

	/**
	 * Compute perceptual hash.
	 *
	 * @param string $file File.
	 *
	 * @return string
	 */
	private static function compute_perceptual_hash( string $file ): string {
		if ( ! is_file( $file ) ) {
			return '';
		}

		$contents = ( is_readable( $file ) ? file_get_contents( $file ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( false === $contents ) {
			return '';
		}

		$image = @imagecreatefromstring( $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GD emits warnings for corrupt images; the return value is checked.
		if ( ! $image ) {
			return '';
		}

		$size   = 8;
		$sample = imagecreatetruecolor( $size, $size );
		imagecopyresampled( $sample, $image, 0, 0, 0, 0, $size, $size, imagesx( $image ), imagesy( $image ) );

		$pixels = array();
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				$rgb      = imagecolorat( $sample, $x, $y );
				$r        = ( $rgb >> 16 ) & 0xff;
				$g        = ( $rgb >> 8 ) & 0xff;
				$b        = $rgb & 0xff;
				$pixels[] = (int) round( ( $r + $g + $b ) / 3 );
			}
		}

		$avg  = array_sum( $pixels ) / count( $pixels );
		$hash = '';
		foreach ( $pixels as $pixel ) {
			$hash .= ( $pixel >= $avg ) ? '1' : '0';
		}

		imagedestroy( $sample );
		imagedestroy( $image );
		return $hash;
	}

	/**
	 * Metadata similarity.
	 *
	 * @param int $first_id  First id.
	 * @param int $second_id Second id.
	 *
	 * @return float
	 */
	private static function metadata_similarity( int $first_id, int $second_id ): float {
		$first_meta  = wp_get_attachment_metadata( $first_id );
		$second_meta = wp_get_attachment_metadata( $second_id );

		if ( ! is_array( $first_meta ) || ! is_array( $second_meta ) ) {
			return 50.0; // neutral score if metadata missing.
		}

		$score = 0.0;

		// Compare dimensions.
		$first_w  = (int) ( $first_meta['width'] ?? 0 );
		$first_h  = (int) ( $first_meta['height'] ?? 0 );
		$second_w = (int) ( $second_meta['width'] ?? 0 );
		$second_h = (int) ( $second_meta['height'] ?? 0 );

		if ( $first_w > 0 && $first_h > 0 && $second_w > 0 && $second_h > 0 ) {
			$aspect_ratio_1 = $first_w / $first_h;
			$aspect_ratio_2 = $second_w / $second_h;

			// Penalize aspect ratio mismatch (crops/rotations).
			$ratio_diff = abs( $aspect_ratio_1 - $aspect_ratio_2 ) / max( $aspect_ratio_1, $aspect_ratio_2 );
			$score     += 50 * ( 1 - min( 1.0, $ratio_diff ) );

			// Dimension size similarity (prefer same-sized files).
			$size_ratio = min( $first_w * $first_h, $second_w * $second_h ) / max( $first_w * $first_h, $second_w * $second_h );
			$score     += 50 * $size_ratio;
		} else {
			$score = 50.0;
		}

		return round( max( 0.0, min( 100.0, $score ) ), 1 );
	}

	/**
	 * Visual group summary.
	 *
	 * @param array      $ids              Ids.
	 * @param array|null $known_references Known references.
	 *
	 * @return array
	 */
	public static function visual_group_summary( array $ids, ?array $known_references = null ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array(
				'scores'     => array(),
				'best_id'    => 0,
				'similarity' => 0.0,
				'warning'    => '',
				'confidence' => 'low',
			);
		}

		$anchor = (int) $ids[0];
		$scores = array();
		foreach ( $ids as $id ) {
			$scores[ $id ] = self::image_similarity( $anchor, $id );
		}
		// The anchor always matches itself: exclude it from group confidence.
		$comparisons = $scores;
		unset( $comparisons[ $anchor ] );
		$best_score = $comparisons ? (float) min( $comparisons ) : 0.0;

		// The results page already has saved usage counts. Avoid an unindexed
		// postmeta search for every image when those counts are supplied.
		$ref_index = $known_references ?? array();
		if ( null === $known_references ) {
			foreach ( $ids as $id ) {
				$ref_count = 0;
				if ( get_post( $id ) ) {
					global $wpdb;
					$ref_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE ( meta_key = '_thumbnail_id' AND meta_value = %s ) OR ( meta_key = '_product_image_gallery' AND FIND_IN_SET( %s, meta_value ) )", (string) $id, (string) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				}
				$ref_index[ $id ] = array( 'ref_count' => $ref_count );
			}
		}

		$best_id = self::suggest_keep_id( $ids, $ref_index );

		$warning              = '';
		$confidence           = 'low';
		$aspect_ratio_warning = self::check_aspect_ratio_variance( $ids );

		if ( $best_score < 70.0 ) {
			$warning    = __( 'Low similarity score. Possible crop, rotation, or compression changes.', 'lucidlab-mpc-cleanup' );
			$confidence = 'low';
		} elseif ( $best_score < 85.0 ) {
			$warning    = __( 'Moderate similarity. Review full-size images and references before merging.', 'lucidlab-mpc-cleanup' );
			$confidence = 'medium';
		} else {
			$warning    = '';
			$confidence = 'high';
		}

		if ( ! empty( $aspect_ratio_warning ) ) {
			if ( ! empty( $warning ) ) {
				$warning .= ' ' . $aspect_ratio_warning;
			} else {
				$warning = $aspect_ratio_warning;
			}
			if ( 'high' === $confidence ) {
				$confidence = 'medium';
			}
		}

		return array(
			'scores'     => $scores,
			'best_id'    => $best_id,
			'similarity' => $best_score,
			'warning'    => $warning,
			'confidence' => $confidence,
		);
	}

	/**
	 * Check aspect ratio variance.
	 *
	 * @param array $ids Ids.
	 *
	 * @return string
	 */
	private static function check_aspect_ratio_variance( array $ids ): string {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( count( $ids ) < 2 ) {
			return '';
		}

		$ratios = array();
		foreach ( $ids as $id ) {
			$meta = wp_get_attachment_metadata( $id );
			if ( is_array( $meta ) ) {
				$w = (int) ( $meta['width'] ?? 0 );
				$h = (int) ( $meta['height'] ?? 0 );
				if ( $w > 0 && $h > 0 ) {
					$ratios[] = $w / $h;
				}
			}
		}

		if ( count( $ratios ) < 2 ) {
			return '';
		}

		$min_ratio = min( $ratios );
		$max_ratio = max( $ratios );
		$variance  = ( $max_ratio - $min_ratio ) / $min_ratio;

		if ( $variance > 0.15 ) {
			return __( 'Aspect ratio varies; possible crops or different orientations.', 'lucidlab-mpc-cleanup' );
		}

		return '';
	}

	/**
	 * Suggest keep id.
	 *
	 * @param array $ids              Ids.
	 * @param array $references_index References index.
	 *
	 * @return int
	 */
	public static function suggest_keep_id( array $ids, array $references_index ): int {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$best_id    = (int) $ids[0];
		$best_score = -PHP_FLOAT_MAX;
		foreach ( $ids as $id ) {
			$score = (int) ( $references_index[ $id ]['ref_count'] ?? count( (array) ( $references_index[ $id ]['refs'] ?? array() ) ) ) * 25;
			$meta  = wp_get_attachment_metadata( $id );
			if ( is_array( $meta ) ) {
				$width  = (int) ( $meta['width'] ?? 0 );
				$height = (int) ( $meta['height'] ?? 0 );
				if ( $width > 0 && $height > 0 ) {
					$score += min( 15, max( 0, ( $width * $height ) / 200000 ) );
				}
			}
			$post = get_post( $id );
			if ( $post && ! empty( $post->post_date_gmt ) ) {
				$timestamp = strtotime( $post->post_date_gmt );
				if ( $timestamp ) {
					$score += min( 10, max( 0, ( time() - $timestamp ) / DAY_IN_SECONDS / 30 ) );
				}
			}
			if ( $score > $best_score ) {
				$best_id    = $id;
				$best_score = $score;
			}
		}

		return $best_id;
	}

	/**
	 * Group ids.
	 *
	 * @param mixed $group Group.
	 *
	 * @return array
	 */
	public static function group_ids( $group ): array {
		$raw = ( is_array( $group ) && isset( $group['members'] ) ) ? (array) $group['members'] : (array) $group;
		$ids = array();
		foreach ( $raw as $member ) {
			$ids[] = is_array( $member ) ? (int) ( $member['id'] ?? 0 ) : (int) $member;
		}
		return LucidLab_MPC_Helpers::int_ids( $ids );
	}

	/**
	 * Store visual groups.
	 *
	 * @param array $groups Groups.
	 */
	public static function store_visual_groups( array $groups ): void {
		// Each image points at its group once. Storing the whole group under every member grew with the
		// square of the group size: four groups of about 600 made a 23 MB option.
		$of   = array();
		$list = array();
		foreach ( $groups as $group ) {
			$ids = self::group_ids( $group );
			if ( count( $ids ) < 2 ) {
				continue;
			}
			$list[] = $ids;
			foreach ( $ids as $id ) {
				$of[ $id ] = count( $list ) - 1;
			}
		}
		update_option(
			'lucidlab_mpc_visual_groups',
			array(
				'v'      => 2,
				'of'     => $of,
				'groups' => $list,
			),
			false
		);
	}

	/**
	 * IDs of the visual group an image belongs to (empty when none). Reads both stored formats.
	 *
	 * @param int $attachment_id Attachment id.
	 *
	 * @return array<int,int>
	 */
	public static function visual_group_of( int $attachment_id ): array {
		$map = (array) get_option( 'lucidlab_mpc_visual_groups', array() );
		if ( 2 === (int) ( $map['v'] ?? 0 ) ) {
			$index = $map['of'][ $attachment_id ] ?? null;
			return null === $index ? array() : LucidLab_MPC_Helpers::int_ids( (array) ( $map['groups'][ (int) $index ] ?? array() ) );
		}
		return LucidLab_MPC_Helpers::int_ids( (array) ( $map[ $attachment_id ] ?? array() ) );
	}

	/**
	 * Get visual similarity context for an image if it's part of a duplicate group. Used by AI analysis to provide context about visual duplicates.
	 *
	 * @param int $attachment_id Attachment id.
	 *
	 * @return array
	 */
	public static function get_similarity_context( int $attachment_id ): array {
		$attachment_id = absint( $attachment_id );
		$ids           = self::visual_group_of( $attachment_id );
		if ( count( $ids ) < 2 ) {
			return array(
				'duplicate_group_ids' => array(),
				'min_similarity'      => 0,
				'best_id'             => 0,
				'group_size'          => 0,
			);
		}
		$summary = self::visual_group_summary( $ids );
		return array(
			'duplicate_group_ids' => $ids,
			'min_similarity'      => (float) ( $summary['similarity'] ?? 0 ),
			'best_id'             => (int) ( $summary['best_id'] ?? 0 ),
			'group_size'          => count( $ids ),
			'scores'              => (array) ( $summary['scores'] ?? array() ),
			'warning'             => (string) ( $summary['warning'] ?? '' ),
		);
	}
}
