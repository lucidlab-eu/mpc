<?php
/**
 * Lucid Lab Media & Products Cleanup: result cache class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Disposable, bounded result cache. A miss or corrupt file always requires a live check. */
final class LucidLab_MPC_Result_Cache {
	/**
	 * Read.
	 *
	 * @param string $kind Kind.
	 * @param array  $keys Keys.
	 *
	 * @return array
	 */
	public static function read( string $kind, array $keys ): array {
		$out = array();
		foreach ( self::buckets( $keys ) as $bucket => $items ) {
			$rows = self::load( self::path( $kind, $bucket ) );
			foreach ( $items as $key => $fingerprint ) {
				$row = $rows[ hash( 'sha256', $key ) ] ?? null;
				if ( is_array( $row ) && ( $row['expires'] ?? 0 ) > time()
					&& ( $row['fingerprint'] ?? null ) === $fingerprint && isset( $row['value'] ) ) {
					$out[ $key ] = $row['value'];
				}
			}
		}
		return $out;
	}

	/**
	 * Write.
	 *
	 * @param string $kind   Kind.
	 * @param array  $keys   Keys.
	 * @param array  $values Values.
	 * @param int    $ttl    Ttl.
	 */
	public static function write( string $kind, array $keys, array $values, int $ttl ): void {
		foreach ( self::buckets( array_intersect_key( $keys, $values ) ) as $bucket => $items ) {
			$handle = fopen( self::path( $kind, $bucket ), 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			if ( ! $handle ) {
				continue; }
			if ( flock( $handle, LOCK_EX ) ) {
				$rows = json_decode( (string) stream_get_contents( $handle ), true );
				$rows = is_array( $rows ) ? array_filter( $rows, static fn( $r ) => is_array( $r ) && ( $r['expires'] ?? 0 ) > time() ) : array();
				foreach ( $items as $key => $fingerprint ) {
					$hash = hash( 'sha256', $key );
					unset( $rows[ $hash ] );
					$rows[ $hash ] = array(
						'fingerprint' => $fingerprint,
						'expires'     => time() + max( 1, $ttl ),
						'value'       => $values[ $key ],
					);
				}
				$json = wp_json_encode( array_slice( $rows, -512, null, true ) );
				if ( is_string( $json ) ) {
					rewind( $handle );
					ftruncate( $handle, 0 );
					fwrite( $handle, $json ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				flock( $handle, LOCK_UN );
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
	}

	/**
	 * File metadata is checked afresh; absent files must never reuse successful results.
	 *
	 * @param string $path Path.
	 *
	 * @return string|null
	 */
	public static function file_signature( string $path ): ?string {
		clearstatcache( true, $path );
		$stat = ( file_exists( $path ) ? stat( $path ) : false );
		return $stat && is_file( $path ) ? implode( ':', array_map( static fn( $k ) => (string) $stat[ $k ], array( 'dev', 'ino', 'size', 'mtime', 'ctime' ) ) ) : null;
	}

	/**
	 * Buckets.
	 *
	 * @param array $keys Keys.
	 *
	 * @return array
	 */
	private static function buckets( array $keys ): array {
		$out = array();
		foreach ( $keys as $key => $value ) {
			$out[ substr( hash( 'sha256', (string) $key ), 0, 2 ) ][ $key ] = $value; }
		return $out;
	}
	/**
	 * Path.
	 *
	 * @param string $kind   Kind.
	 * @param string $bucket Bucket.
	 *
	 * @return string
	 */
	private static function path( string $kind, string $bucket ): string {
		return LucidLab_MPC_Work::path( 'result-v1-' . sanitize_key( $kind ) . '-' . $bucket . '.json' );
	}
	/**
	 * Load.
	 *
	 * @param string $path Path.
	 *
	 * @return array
	 */
	private static function load( string $path ): array {
		$handle = ( is_readable( $path ) ? fopen( $path, 'rb' ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			return array(); }
		$rows = null;
		if ( flock( $handle, LOCK_SH ) ) {
			$rows = json_decode( (string) stream_get_contents( $handle ), true );
			flock( $handle, LOCK_UN ); }
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		return is_array( $rows ) ? $rows : array();
	}
}
