<?php
/**
 * Lucid Lab Media & Products Cleanup: scan cache class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Shared, disposable parsed-reference cache. Live rows remain the source of truth. */
final class LucidLab_MPC_Scan_Cache {
	/**
	 * Hits.
	 *
	 * @var int
	 */
	public static int $hits = 0;
	/**
	 * Misses.
	 *
	 * @var int
	 */
	public static int $misses = 0;
	/** Bump when URL or reference parsing changes so saved parses are not reused. */
	const REVISION = 2;
	const MAX_AGE  = 30 * DAY_IN_SECONDS;

	/**
	 * Batch.
	 *
	 * @param string   $kind  Kind.
	 * @param array    $rows  Rows.
	 * @param callable $key   Key.
	 * @param callable $input Input.
	 * @param callable $parse Parse.
	 *
	 * @return array
	 */
	public static function batch( string $kind, array $rows, callable $key, callable $input, callable $parse ): array {
		$buckets = array();
		$out     = array();
		foreach ( $rows as $row ) {
			$id                                    = (int) $key( $row );
			$buckets[ intdiv( $id, 1000 ) ][ $id ] = $row; }
		foreach ( $buckets as $bucket => $items ) {
			$path    = LucidLab_MPC_Work::path( 'parsed-v' . self::REVISION . '-' . sanitize_key( $kind ) . '-' . $bucket . '.json' );
			$saved   = json_decode( (string) ( is_readable( $path ) ? file_get_contents( $path ) : false ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			$saved   = is_array( $saved ) ? array_filter( $saved, static fn( $entry ) => is_array( $entry ) && (int) ( $entry['updated'] ?? 0 ) >= time() - self::MAX_AGE ) : array();
			$changed = array();
			foreach ( $items as $id => $row ) {
				$hash = hash( 'sha256', serialize( array( self::REVISION, $input( $row ), wp_upload_dir()['baseurl'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Used only as hash input.
				if ( isset( $saved[ $id ]['hash'], $saved[ $id ]['value'] ) && hash_equals( $saved[ $id ]['hash'], $hash ) && is_array( $saved[ $id ]['value'] ) ) {
					$out[ $id ] = $saved[ $id ]['value'];
					++self::$hits;
				} else {
					$out[ $id ] = $parse( $row );
					++self::$misses;
					$changed[ $id ] = array(
						'hash'    => $hash,
						'value'   => $out[ $id ],
						'updated' => time(),
					);
				}
			}
			// Cache failure only costs performance. Never substitute empty references.
			$handle = $changed ? fopen( $path, 'c+b' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			if ( $handle ) {
				if ( flock( $handle, LOCK_EX ) ) {
					$latest = json_decode( stream_get_contents( $handle ), true );
					$latest = is_array( $latest ) ? array_filter( $latest, static fn( $entry ) => is_array( $entry ) && (int) ( $entry['updated'] ?? 0 ) >= time() - self::MAX_AGE ) : array();
					$json   = wp_json_encode( array_replace( $latest, $changed ) );
					if ( is_string( $json ) ) {
						rewind( $handle );
						ftruncate( $handle, 0 );
						fwrite( $handle, $json ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
					flock( $handle, LOCK_UN );
				}
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			}
		}
		return $out;
	}
}
