<?php
/**
 * Lucid Lab Media & Products Cleanup: hash cache class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SHA-256 of original image files, reused while path, size and mtime stay the same.
 * Same invalidation as visual fingerprints: a replaced file gets a new key and is hashed again.
 */
final class LucidLab_MPC_Hash_Cache {
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
	private const SCHEMA      = 1;

	/**
	 * File key.
	 *
	 * @param string $path Path.
	 *
	 * @return string
	 */
	public static function file_key( string $path ): string {
		return md5( $path . '|' . (int) ( is_file( $path ) ? filemtime( $path ) : false ) . '|' . (int) ( is_file( $path ) ? filesize( $path ) : false ) );
	}

	/**
	 * Hashes.
	 *
	 * @param array<int,string> $paths Attachment_id => absolute path.
	 *
	 * @return array<int,array{hash:string,bytes:int}>
	 */
	public static function hashes( array $paths ): array {
		$buckets = array();
		$out     = array();
		foreach ( $paths as $id => $path ) {
			$buckets[ intdiv( (int) $id, 1000 ) ][ (int) $id ] = (string) $path;
		}
		foreach ( $buckets as $bucket => $items ) {
			$file    = LucidLab_MPC_Work::path( 'hash-v' . self::SCHEMA . '-' . $bucket . '.json' );
			$saved   = json_decode( (string) ( is_readable( $file ) ? file_get_contents( $file ) : false ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			$saved   = is_array( $saved ) ? $saved : array();
			$changed = array();
			foreach ( $items as $id => $path ) {
				$key = self::file_key( $path );
				$row = $saved[ $id ] ?? null;
				if ( is_array( $row ) && isset( $row['key'], $row['hash'] ) && hash_equals( (string) $row['key'], $key ) && 64 === strlen( (string) $row['hash'] ) ) {
					$out[ $id ] = array(
						'hash'  => (string) $row['hash'],
						'bytes' => (int) ( $row['bytes'] ?? 0 ),
					);
					++self::$hits;
					continue;
				}
				++self::$misses;
				$hash = ( is_readable( $path ) ? hash_file( 'sha256', $path ) : false );
				if ( ! $hash ) {
					continue;
				}
				$bytes          = (int) ( is_file( $path ) ? filesize( $path ) : false );
				$out[ $id ]     = array(
					'hash'  => $hash,
					'bytes' => $bytes,
				);
				$changed[ $id ] = array(
					'key'   => $key,
					'hash'  => $hash,
					'bytes' => $bytes,
				);
			}
			$handle = $changed ? fopen( $file, 'c+b' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			if ( $handle ) {
				if ( flock( $handle, LOCK_EX ) ) {
					$latest = json_decode( stream_get_contents( $handle ), true );
					$latest = is_array( $latest ) ? $latest : array();
					$json   = wp_json_encode( array_replace( $latest, $changed ) );
					if ( is_string( $json ) ) {
						rewind( $handle );
						ftruncate( $handle, 0 );
						fwrite( $handle, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
					}
					flock( $handle, LOCK_UN );
				}
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			}
		}
		return $out;
	}

	/**
	 * Forget.
	 *
	 * @param int $id Id.
	 */
	public static function forget( int $id ): void {
		$file  = LucidLab_MPC_Work::path( 'hash-v' . self::SCHEMA . '-' . intdiv( max( 0, $id ), 1000 ) . '.json' );
		$saved = json_decode( (string) ( is_readable( $file ) ? file_get_contents( $file ) : false ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! is_array( $saved ) || ! isset( $saved[ $id ] ) ) {
			return;
		}
		unset( $saved[ $id ] );
		$json = wp_json_encode( $saved );
		if ( is_string( $json ) ) {
			file_put_contents( $file, $json, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
	}
}
