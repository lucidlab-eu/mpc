<?php
/**
 * Lucid Lab Media & Products Cleanup: work class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scan work files in uploads/lucidlab-mpc-cleanup/work. Large, growing scan data is appended here
 * instead of being rewritten in wp_options on every step.
 */
final class LucidLab_MPC_Work {
	/**
	 * Cache.
	 *
	 * @var array<string,array{key:string,data:mixed}>
	 */
	private static array $cache = array();

	/**
	 * Dir.
	 *
	 * @return string
	 */
	public static function dir(): string {
		$dir = LucidLab_MPC_Uploads::scan_work_dir();
		if ( is_dir( $dir ) && ! is_file( $dir . '/web.config' ) ) {
			// Also covers an old folder still in use because moving it failed.
			LucidLab_MPC_Storage::protect( $dir, true );
		}
		return $dir;
	}

	/**
	 * Path.
	 *
	 * @param string $name Name.
	 *
	 * @return string
	 */
	public static function path( string $name ): string {
		return self::dir() . '/' . sanitize_file_name( $name );
	}

	/**
	 * Exists.
	 *
	 * @param string $name Name.
	 *
	 * @return bool
	 */
	public static function exists( string $name ): bool {
		return is_file( self::path( $name ) );
	}

	/**
	 * Non-empty lines in a work file. Used for index coverage without decoding each row.
	 *
	 * @param string $name Name.
	 *
	 * @return int
	 */
	public static function count_lines( string $name ): int {
		$handle = ( is_readable( self::path( $name ) ) ? fopen( self::path( $name ), 'rb' ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			return 0;
		}
		$n = 0;
		while ( true ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			if ( '' !== trim( $line ) ) {
				++$n;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		return $n;
	}

	/**
	 * Age.
	 *
	 * @param string $name Name.
	 *
	 * @return int
	 */
	public static function age( string $name ): int {
		$time = ( is_file( self::path( $name ) ) ? filemtime( self::path( $name ) ) : false );
		return false === $time ? PHP_INT_MAX : max( 0, time() - (int) $time );
	}

	/**
	 * Delete.
	 *
	 * @param string ...$names Names.
	 */
	public static function delete( string ...$names ): void {
		foreach ( $names as $name ) {
			$path = self::path( $name );
			unset( self::$cache[ $path ] );
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Copy.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 *
	 * @return bool
	 */
	public static function copy( string $from, string $to ): bool {
		$src = self::path( $from );
		return is_file( $src ) && copy( $src, self::path( $to ) );
	}

	/**
	 * Append rows as JSON lines.
	 *
	 * @param string $name Name.
	 * @param array  $rows Rows.
	 *
	 * @return bool
	 */
	public static function append( string $name, array $rows ): bool {
		if ( ! $rows ) {
			return true;
		}
		$lines = '';
		foreach ( $rows as $row ) {
			$json = wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
			if ( is_string( $json ) ) {
				$lines .= $json . "\n";
			}
		}
		$path = self::path( $name );
		unset( self::$cache[ $path ] );
		return '' === $lines || false !== file_put_contents( $path, $lines, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
	}

	/**
	 * Call $each for every JSON line.
	 *
	 * @param string   $name Name.
	 * @param callable $each Each.
	 */
	public static function each( string $name, callable $each ): void {
		$handle = ( is_readable( self::path( $name ) ) ? fopen( self::path( $name ), 'rb' ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			return;
		}
		while ( true ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			$row = json_decode( $line, true );
			if ( is_array( $row ) ) {
				$each( $row );
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
	}

	/**
	 * Lines.
	 *
	 * @param string $name Name.
	 *
	 * @return array<int,array>
	 */
	public static function lines( string $name ): array {
		$rows = array();
		self::each(
			$name,
			static function ( array $row ) use ( &$rows ) {
				$rows[] = $row;
			}
		);
		return $rows;
	}

	/**
	 * Write json.
	 *
	 * @param string $name Name.
	 * @param mixed  $data Data.
	 *
	 * @return bool
	 */
	public static function write_json( string $name, $data ): bool {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		$path = self::path( $name );
		unset( self::$cache[ $path ] );
		return is_string( $json ) && false !== file_put_contents( $path, $json, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
	}

	/**
	 * Read json.
	 *
	 * @param string $name Name.
	 *
	 * @return mixed|null
	 */
	public static function read_json( string $name ) {
		$raw = ( is_readable( self::path( $name ) ) ? file_get_contents( self::path( $name ) ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		return is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
	}

	/**
	 * Build a value from a file once per request while the file is unchanged.
	 *
	 * @param string   $name  Name.
	 * @param callable $build Build.
	 *
	 * @return mixed
	 */
	public static function remember( string $name, callable $build ) {
		$path = self::path( $name );
		clearstatcache( true, $path );
		$key = (string) ( is_file( $path ) ? filesize( $path ) : false ) . ':' . (string) ( is_file( $path ) ? filemtime( $path ) : false );
		if ( isset( self::$cache[ $path ] ) && self::$cache[ $path ]['key'] === $key ) {
			return self::$cache[ $path ]['data'];
		}
		$data                 = $build();
		self::$cache[ $path ] = array(
			'key'  => $key,
			'data' => $data,
		);
		return $data;
	}

	/**
	 * Keep JSON lines for which $keep returns true. Used to drop stale source rows from a shared index.
	 *
	 * @param string   $name Name.
	 * @param callable $keep Keep.
	 *
	 * @return bool
	 */
	public static function filter( string $name, callable $keep ): bool {
		$src = self::path( $name );
		if ( ! is_file( $src ) ) {
			return false;
		}
		$tmp    = $src . '.tmp';
		$handle = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			return false;
		}
		self::each(
			$name,
			static function ( array $row ) use ( $keep, $handle ) {
				if ( ! $keep( $row ) ) {
					return;
				}
				$json = wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
				if ( is_string( $json ) ) {
					fwrite( $handle, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				}
			}
		);
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		unset( self::$cache[ $src ] );
		if ( is_file( $src ) ) {
			wp_delete_file( $src );
		}
		if ( rename( $tmp, $src ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			return true;
		}
		$copied = copy( $tmp, $src );
		wp_delete_file( $tmp );
		return $copied;
	}
}
