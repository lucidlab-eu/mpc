<?php
/**
 * Lucid Lab Media & Products Cleanup: directory inventory class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Per-scan names snapshot. File metadata and references are always checked live. */
final class LucidLab_MPC_Directory_Inventory {
	/**
	 * Path.
	 *
	 * @param string $directory Directory.
	 * @param string $token     Token.
	 *
	 * @return string
	 */
	public static function path( string $directory, string $token ): string {
		return LucidLab_MPC_Work::path( 'directory-' . hash( 'sha256', $token ) . '-' . hash( 'sha256', $directory ) . '.jsonl' );
	}

	/**
	 * Discard.
	 *
	 * @param string $token Token.
	 */
	public static function discard( string $token ): void {
		if ( '' === $token ) {
			return; }
		$prefix = LucidLab_MPC_Work::path( 'directory-' . hash( 'sha256', $token ) . '-' );
		foreach ( (array) glob( $prefix . '*.jsonl*' ) as $path ) {
			wp_delete_file( $path ); }
	}

	/**
	 * Build a private manifest in timed batches. The published file is immutable. PHP cannot retain an open directory handle between requests, so later batches replay previously read entries before appending new ones. The replay is bounded by the saved entry count; unlike a fresh listing, completed batches are retained.
	 *
	 * @param string     $directory Directory.
	 * @param string     $token     Token.
	 * @param int        $offset    Offset.
	 * @param float|null $deadline  Deadline.
	 *
	 * @return resource|null Published snapshot, or null if the current batch timed out.
	 * @throws RuntimeException When the operation fails.
	 */
	public static function open( string $directory, string $token, int $offset = 0, ?float $deadline = null ) {
		$path = self::path( $directory, $token );
		if ( ! is_file( $path ) ) {
			if ( $offset > 0 ) {
				throw new RuntimeException( __( 'Directory inventory expired. Start a new scan.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			if ( null !== $deadline && microtime( true ) >= $deadline ) {
				return null; }
			$lock = fopen( $path . '.lock', 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			if ( ! $lock ) {
				throw new RuntimeException( __( 'Cannot lock directory inventory.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			try {
				if ( ! flock( $lock, LOCK_EX ) ) {
					throw new RuntimeException( __( 'Cannot lock directory inventory.', 'lucidlab-mpc-cleanup' ) ); }
				if ( ! is_file( $path ) ) {
					$partial    = $path . '.partial';
					$state_path = $path . '.state';
					$state      = json_decode( (string) ( is_readable( $state_path ) ? file_get_contents( $state_path ) : false ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
					$count      = is_array( $state ) ? max( 0, (int) ( $state['count'] ?? 0 ) ) : 0;
					$bytes      = is_array( $state ) ? max( 0, (int) ( $state['bytes'] ?? 0 ) ) : 0;
					clearstatcache( true, $directory );
					$stat      = ( file_exists( $directory ) ? stat( $directory ) : false );
					$signature = $stat ? implode( ':', array( $stat['dev'], $stat['ino'], $stat['mtime'], $stat['ctime'] ) ) : '';
					if ( $count > 0 && ( $state['directory'] ?? '' ) !== $signature ) {
						throw new RuntimeException( __( 'Directory changed while listing. Start a new scan.', 'lucidlab-mpc-cleanup' ) );
					}
					$dir = ( is_dir( $directory ) ? opendir( $directory ) : false );
					if ( ! $dir ) {
						throw new RuntimeException( sprintf( /* translators: %s: folder path. */ __( 'Cannot read uploads directory: %s', 'lucidlab-mpc-cleanup' ), $directory ) ); }
					$out = fopen( $partial, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
					if ( ! $out ) {
						closedir( $dir );
						throw new RuntimeException( __( 'Cannot create directory inventory.', 'lucidlab-mpc-cleanup' ) ); }
					$complete = false;
					try {
						if ( ! ftruncate( $out, $bytes ) || fseek( $out, $bytes ) !== 0 ) {
							throw new RuntimeException( __( 'Cannot resume directory inventory.', 'lucidlab-mpc-cleanup' ) );
						}
						for ( $i = 0; $i < $count; $i++ ) {
							if ( readdir( $dir ) === false ) {
								throw new RuntimeException( __( 'Directory changed while listing. Start a new scan.', 'lucidlab-mpc-cleanup' ) );
							}
						}
						$started = microtime( true );
						while ( true ) {
							if ( null !== $deadline && microtime( true ) >= $deadline ) {
								break; }
							if ( null === $deadline && microtime( true ) - $started > 10 ) {
								throw new RuntimeException( sprintf( /* translators: %s: folder path. */ __( 'Directory listing exceeded 10 seconds: %s', 'lucidlab-mpc-cleanup' ), $directory ) );
							}
							$name = readdir( $dir );
							if ( false === $name ) {
								$complete = true;
								break; }
							++$count;
							if ( '.' === $name || '..' === $name ) {
								continue; }
							// Base64 preserves names that are not valid UTF-8.
							$line = base64_encode( $name ) . "\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes raw file names so any byte sequence fits on one line.
							if ( fwrite( $out, $line ) !== strlen( $line ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
								throw new RuntimeException( __( 'Directory inventory write failed.', 'lucidlab-mpc-cleanup' ) );
							}
						}
						if ( ! fflush( $out ) ) {
							throw new RuntimeException( __( 'Directory inventory write failed.', 'lucidlab-mpc-cleanup' ) ); }
						$state_tmp = $state_path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
						$next      = array(
							'count'     => $count,
							'bytes'     => (int) ftell( $out ),
							'directory' => $signature,
						);
						if ( file_put_contents( $state_tmp, wp_json_encode( $next ) ) === false || ! rename( $state_tmp, $state_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
							wp_delete_file( $state_tmp );
							throw new RuntimeException( __( 'Cannot save directory inventory progress.', 'lucidlab-mpc-cleanup' ) );
						}
					} finally {
						fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
						closedir( $dir );
					}
					if ( ! $complete ) {
						return null; }
					if ( ! rename( $partial, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
						throw new RuntimeException( __( 'Cannot publish directory inventory.', 'lucidlab-mpc-cleanup' ) ); }
					wp_delete_file( $state_path );
				}
			} finally {
				flock( $lock, LOCK_UN );
				fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			}
		}
		$handle = ( is_readable( $path ) ? fopen( $path, 'rb' ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			throw new RuntimeException( __( 'Cannot read directory inventory.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		$size = fstat( $handle )['size'];
		if ( $offset < 0 || $offset > $size || fseek( $handle, $offset ) !== 0 ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			throw new RuntimeException( __( 'Invalid directory cursor.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		return $handle;
	}

	/**
	 * Next.
	 *
	 * @param mixed $handle Handle.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	public static function next( $handle ) {
		$line = fgets( $handle );
		if ( false === $line ) {
			return false; }
		$name = base64_decode( rtrim( $line, "\r\n" ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Encodes raw file names so any byte sequence fits on one line.
		if ( false === $name || '' === $name || '.' === $name || '..' === $name || false !== strpbrk( $name, '/\\' ) ) {
			throw new RuntimeException( __( 'Invalid directory inventory entry.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		return $name;
	}
}
