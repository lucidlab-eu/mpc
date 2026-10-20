<?php
/**
 * Lucid Lab Media & Products Cleanup: uploads class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab 3 — uploads size scanner. Chunked so the AJAX scan can show a progress bar.
 */
class LucidLab_MPC_Uploads {
	use LucidLab_MPC_Uploads_Pro_Methods;

	/**
	 * Operational files are not orphaned media, even without an attachment record.
	 *
	 * @param string $rel Rel.
	 *
	 * @return bool
	 */
	public static function is_support_file( string $rel ): bool {
		$rel   = strtolower( str_replace( '\\', '/', $rel ) );
		$parts = explode( '/', trim( $rel, '/' ) );
		foreach ( $parts as $part ) {
			if ( in_array( $part, array( LucidLab_MPC_Storage::SLUG, '_cleanup-fsindex', '_cleanup-format-preview', 'mpc-releases', 'wc-logs', 'wflogs', 'xlplugins', 'font-awesome', 'logs', 'cache', 'et-cache', 'core-transient', 'rank-math', 'wp-mail-smtp', 'et_temp', 'woocommerce_transient_files', 'woocommerce_uploads', 'wpcf7_uploads' ), true ) || str_starts_with( $part, 'lucidlab-mpc-backups-' ) ) {
				return true;
			}
		}
		$name = basename( $rel );
		if ( LucidLab_MPC_Helpers::is_excluded_file( $name ) ) {
			return true;
		}
		return in_array( pathinfo( $name, PATHINFO_EXTENSION ), array( 'log', 'jsonl', 'json', 'css', 'js', 'map', 'sql', 'sqlite', 'db', 'lock', 'ini', 'woff', 'woff2', 'ttf', 'eot' ), true )
			|| strpos( $name, '_xlcore_transient_' ) === 0;
	}

	/**
	 * False when a directory entry cannot be stored in a UTF-8 database column.
	 *
	 * @param string $name Name.
	 *
	 * @return bool
	 */
	public static function filename_is_utf8( string $name ): bool {
		return '' !== $name && 1 === preg_match( '//u', $name );
	}

	/**
	 * Directories the uploads walker must not enter (caches, logs, hidden, plugin internals).
	 *
	 * @param string $name Name.
	 * @param string $rel  Rel.
	 *
	 * @return bool
	 */
	public static function skip_scan_dir( string $name, string $rel = '' ): bool {
		if ( '.' === $name || '..' === $name || '' === $name ) {
			return true;
		}
		if ( str_starts_with( $name, '.' ) ) {
			return true;
		}
		return self::is_support_file( '' !== $rel ? $rel : $name );
	}

	/**
	 * Work files for the Uploads Scanner live outside wp_options so large scans do not 502 MySQL.
	 *
	 * @return string
	 */
	public static function scan_work_dir(): string {
		// uploads/lucidlab-mpc-cleanup/work/, created with index.php, .htaccess and web.config deny files.
		return LucidLab_MPC_Storage::dir( 'work' );
	}

	/**
	 * Scan reg file.
	 *
	 * @param int $uid Uid.
	 *
	 * @return string
	 */
	public static function scan_reg_file( int $uid ): string {
		return self::scan_work_dir() . '/uploads-reg-' . max( 0, $uid ) . '.json';
	}

	/**
	 * Scan orphan file.
	 *
	 * @param int $uid Uid.
	 *
	 * @return string
	 */
	public static function scan_orphan_file( int $uid ): string {
		return self::scan_work_dir() . '/uploads-orphans-' . max( 0, $uid ) . '.jsonl';
	}

	/**
	 * Scan result file.
	 *
	 * @param int $uid Uid.
	 *
	 * @return string
	 */
	public static function scan_result_file( int $uid ): string {
		return self::scan_work_dir() . '/uploads-result-' . max( 0, $uid ) . '.jsonl';
	}

	/**
	 * Count quarantineable files in the finished result without loading the full list into memory.
	 *
	 * @param int $uid Uid.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return int
	 */
	public static function result_file_total( int $uid ): int {
		$file = self::scan_result_file( $uid );
		clearstatcache( true, $file );
		$handle = ( is_readable( $file ) ? fopen( $file, 'rb' ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			throw new RuntimeException( __( 'Uploads results are unavailable. Rescan before quarantining.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		try {
			$total = 0;
			while ( true ) {
				$line = fgets( $handle );
				if ( false === $line ) {
					break;
				}
				$data = json_decode( $line, true );
				if ( ! is_array( $data ) || ! is_array( $data['unit']['files'] ?? null ) ) {
					throw new RuntimeException( __( 'Uploads results contain an unreadable record. Rescan before quarantining.', 'lucidlab-mpc-cleanup' ) );
				}
				foreach ( $data['unit']['files'] as $row ) {
					$rel = (string) ( is_array( $row ) ? ( $row['rel'] ?? '' ) : '' );
					if ( '' !== $rel && ! self::is_support_file( $rel ) ) {
						++$total;
					}
				}
			}
			return $total;
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
	}

	/**
	 * Read a bounded selection without copying the entire uploads list into wp_options.
	 *
	 * @param int   $uid    Uid.
	 * @param array $cursor Cursor.
	 * @param int   $limit  Limit.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public static function result_file_batch( int $uid, array $cursor = array(), int $limit = 25 ): array {
		$file = self::scan_result_file( $uid );
		clearstatcache( true, $file );
		$handle = ( is_readable( $file ) ? fopen( $file, 'rb' ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			throw new RuntimeException( __( 'Uploads results are unavailable. Rescan before quarantining.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		try {
			$stat        = fstat( $handle );
			$fingerprint = (string) $stat['size'] . ':' . (string) $stat['mtime'];
			if ( isset( $cursor['fingerprint'] ) && $fingerprint !== $cursor['fingerprint'] ) {
				throw new RuntimeException( __( 'Uploads results changed. Start a new reviewed cleanup.', 'lucidlab-mpc-cleanup' ) );
			}
			$offset = max( 0, (int) ( $cursor['offset'] ?? 0 ) );
			$index  = max( 0, (int) ( $cursor['index'] ?? 0 ) );
			if ( $offset > $stat['size'] || 0 !== fseek( $handle, $offset ) ) {
				throw new RuntimeException( __( 'Uploads results cursor is invalid.', 'lucidlab-mpc-cleanup' ) ); }
			$files = array();
			$limit = max( 1, min( 100, $limit ) );
			$lines = 0;
			$taken = 0;
			while ( $taken < $limit && $lines++ < 200 ) {
				$line = fgets( $handle );
				if ( false === $line ) {
					break;
				}
				$data = json_decode( $line, true );
				if ( ! is_array( $data ) || ! is_array( $data['unit']['files'] ?? null ) ) {
					throw new RuntimeException( __( 'Uploads results contain an unreadable record. Rescan before quarantining.', 'lucidlab-mpc-cleanup' ) );
				}
				$rows      = array_values( $data['unit']['files'] );
				$row_count = count( $rows );
				while ( $index < $row_count && $taken < $limit ) {
					$rel = (string) ( $rows[ $index++ ]['rel'] ?? '' );
					if ( '' !== $rel && ! self::is_support_file( $rel ) ) {
						$files[] = $rel;
						++$taken;
					}
				}
				if ( $index < $row_count ) {
					break; }
				$offset = (int) ftell( $handle );
				$index  = 0;
			}
			return array(
				'items'    => $files,
				'cursor'   => array(
					'offset'      => $offset,
					'index'       => $index,
					'fingerprint' => $fingerprint,
				),
				'done'     => 0 === $index && $offset >= $stat['size'],
				'progress' => $stat['size'] > 0 ? 100 * $offset / $stat['size'] : 100,
			);
		} finally {
			fclose( $handle ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
	}

	/**
	 * Basenames referenced in content, widgets, theme options and product meta (not necessarily attachments).
	 *
	 * @param int $uid Uid.
	 *
	 * @return string
	 */
	public static function scan_ref_file( int $uid ): string {
		return self::scan_work_dir() . '/uploads-refs-' . max( 0, $uid ) . '.json';
	}

	/**
	 * Write ref file.
	 *
	 * @param int   $uid       Uid.
	 * @param array $basenames Basenames.
	 *
	 * @return bool
	 */
	public static function write_ref_file( int $uid, array $basenames ): bool {
		$list = array_values( array_unique( array_map( 'strval', array_keys( ( $basenames ? $basenames : array() ) ) ) ) );
		$json = self::encode_json( array( 'basenames' => $list ) );
		if ( '' === $json ) {
			return false;
		}
		return false !== file_put_contents( self::scan_ref_file( $uid ), $json, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
	}

	/**
	 * Read ref file.
	 *
	 * @param int $uid Uid.
	 *
	 * @return array<string,int> Lowercased basenames → 1
	 */
	public static function read_ref_file( int $uid ): array {
		$file = self::scan_ref_file( $uid );
		if ( ! is_file( $file ) ) {
			return array();
		}
		$raw = ( is_readable( $file ) ? file_get_contents( $file ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['basenames'] ) || ! is_array( $data['basenames'] ) ) {
			return array();
		}
		return array_fill_keys( array_map( 'strtolower', array_map( 'strval', $data['basenames'] ) ), 1 );
	}

	/**
	 * Work-file name (for LucidLab_MPC_Work) of this user's Uploads reference index.
	 *
	 * @param int $uid Uid.
	 *
	 * @return string
	 */
	public static function ref_work_file( int $uid ): string {
		return 'uploads-refs-' . max( 0, $uid ) . '.jsonl';
	}

	/**
	 * Flat basename map so each uploads walk step does not reparse the reference index.
	 *
	 * @param int $uid Uid.
	 *
	 * @return string
	 */
	private static function ref_basename_cache( int $uid ): string {
		return class_exists( 'LucidLab_MPC_Work', false ) ? LucidLab_MPC_Work::path( 'uploads-refs-bn-' . max( 0, $uid ) . '.json' ) : '';
	}

	/**
	 * Write ref basename cache.
	 *
	 * @param int               $uid       Uid.
	 * @param array<string,int> $basenames Basenames.
	 */
	public static function write_ref_basename_cache( int $uid, array $basenames ): void {
		$file = self::ref_basename_cache( $uid );
		if ( '' === $file ) {
			return;
		}
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$json = wp_json_encode( $basenames );
		if ( is_string( $json ) && '' !== $json ) {
			file_put_contents( $file, $json, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
	}

	/**
	 * Scan refs.
	 *
	 * @param int $uid Uid.
	 *
	 * @return array<string,int> Lowercased basenames; scans saved before the shared index keep their JSON file.
	 */
	public static function scan_refs( int $uid ): array {
		$source = self::ref_work_file( $uid );
		$cache  = self::ref_basename_cache( $uid );
		$src    = class_exists( 'LucidLab_MPC_Work', false ) ? LucidLab_MPC_Work::path( $source ) : '';
		if ( '' !== $cache && is_file( $cache ) && is_file( $src ) && filemtime( $cache ) >= filemtime( $src ) ) {
			$data = json_decode( (string) file_get_contents( $cache ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		$bn = LucidLab_MPC_Work::exists( $source ) ? LucidLab_MPC_Reference_Index::basenames( $source ) : self::read_ref_file( $uid );
		self::write_ref_basename_cache( $uid, $bn );
		return $bn;
	}

	/**
	 * Dir index.
	 *
	 * @var array<string,array>|null
	 */
	private static ?array $dir_index = null;

	/**
	 * Previous directory listing, keyed by uploads-relative path. Null until the first completed scan.
	 *
	 * @param string $rel Rel.
	 *
	 * @return array|null
	 */
	public static function dir_index_get( string $rel ): ?array {
		if ( null === self::$dir_index ) {
			self::$dir_index = array();
			$file            = class_exists( 'LucidLab_MPC_Work', false ) ? LucidLab_MPC_Work::path( 'uploads-dir-index.jsonl' ) : '';
			if ( '' !== $file && is_file( $file ) ) {
				foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
					$row = json_decode( (string) $line, true );
					if ( is_array( $row ) && isset( $row['d'] ) ) {
						self::$dir_index[ (string) $row['d'] ] = $row;
					}
				}
			}
		}
		return self::$dir_index[ $rel ] ?? null;
	}

	/**
	 * Append one finished directory to this scan's build file. Discarded with the inventory token unless published.
	 *
	 * @param string $token Token.
	 * @param array  $row   Row.
	 */
	public static function dir_index_note( string $token, array $row ): void {
		if ( '' === $token || ! class_exists( 'LucidLab_MPC_Work', false ) ) {
			return;
		}
		$path = LucidLab_MPC_Work::path( 'directory-' . hash( 'sha256', $token ) . '-dirindex.jsonl' );
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$json = wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( is_string( $json ) && '' !== $json ) {
			file_put_contents( $path, $json . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
	}

	/**
	 * Keep the build file as the next scan's unchanged-folder index.
	 *
	 * @param string $token Token.
	 */
	public static function dir_index_publish( string $token ): void {
		if ( '' === $token || ! class_exists( 'LucidLab_MPC_Work', false ) ) {
			return;
		}
		$build = LucidLab_MPC_Work::path( 'directory-' . hash( 'sha256', $token ) . '-dirindex.jsonl' );
		$final = LucidLab_MPC_Work::path( 'uploads-dir-index.jsonl' );
		if ( is_file( $build ) ) {
			copy( $build, $final );
		}
		self::$dir_index = null;
	}

	/**
	 * Pro 6.0.88 and earlier: reuse the shared reference index when it is current.
	 *
	 * @return array|null
	 */
	public static function cached_references(): ?array {
		return LucidLab_MPC_Reference_Index::fresh() ? LucidLab_MPC_Reference_Index::basenames( LucidLab_MPC_Reference_Index::SHARED ) : null;
	}

	/**
	 * Media path caches live on disk; only the generation marker lives in options.
	 *
	 * @return array|null
	 */
	public static function cached_registered(): ?array {
		$cache      = get_transient( 'lucidlab_mpc_cached_reg_v5' );
		$generation = (string) get_option( 'lucidlab_mpc_index_generation', 0 );
		if ( ! is_array( $cache ) || (string) $cache['generation'] !== $generation ) {
			return null; }
		$data = json_decode( (string) ( is_readable( self::scan_work_dir() . '/registered-' . sha1( $generation ) . '.json' ) ? file_get_contents( self::scan_work_dir() . '/registered-' . sha1( $generation ) . '.json' ) : false ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! is_array( $data ) || ! isset( $data['paths'] ) || ! is_array( $data['paths'] ) ) {
			return null; }
		return array(
			'paths' => array_fill_keys( $data['paths'], 1 ),
			'bytes' => (int) ( $data['bytes'] ?? 0 ),
		);
	}

	/**
	 * Cache registered.
	 *
	 * @param int   $uid        Uid.
	 * @param mixed $generation Generation.
	 */
	public static function cache_registered( int $uid, $generation ): void {
		if ( (string) get_option( 'lucidlab_mpc_index_generation', 0 ) !== (string) $generation ) {
			return; }
		if ( copy( self::scan_reg_file( $uid ), self::scan_work_dir() . '/registered-' . sha1( (string) $generation ) . '.json' ) ) {
			set_transient( 'lucidlab_mpc_cached_reg_v5', array( 'generation' => $generation ), 15 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Keep the finished file list after the in-progress scan files are removed.
	 *
	 * @param int $uid Uid.
	 *
	 * @return bool
	 */
	public static function keep_result_units( int $uid ): bool {
		$from = self::scan_orphan_file( $uid );
		$to   = self::scan_result_file( $uid );
		if ( ! is_file( $from ) ) {
			return is_file( $to );
		}
		if ( is_file( $to ) && $to !== $from ) {
			wp_delete_file( $to );
		}
		return rename( $from, $to ) || ( copy( $from, $to ) && LucidLab_MPC_Helpers::delete_file( $from ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
	}

	/**
	 * Replace the published uploads result with the units that are still on disk.
	 *
	 * @param int   $uid   Uid.
	 * @param array $units Units.
	 */
	public static function replace_result_units( int $uid, array $units ): void {
		$file = self::scan_result_file( $uid );
		$dir  = dirname( $file );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}
		$handle = fopen( $file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			return;
		}
		foreach ( $units as $key => $unit ) {
			if ( ! is_array( $unit ) || empty( $unit['files'] ) ) {
				continue;
			}
			$line = self::encode_json(
				array(
					'key'  => (string) $key,
					'unit' => $unit,
				)
			);
			if ( '' !== $line ) {
				fwrite( $handle, $line . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
	}

	/**
	 * After a streamed quarantine (Run All / Quarantine all), drop candidates whose files are no longer in uploads. Rewriting mid-stream would invalidate the byte cursor fingerprint.
	 *
	 * @param int $uid Uid.
	 *
	 * @return array{files:int,bytes:int,groups:int}
	 */
	public static function prune_result_to_existing( int $uid ): array {
		$basedir = trailingslashit( str_replace( '\\', '/', LucidLab_MPC_Helpers::uploads_basedir() ) );
		$units   = self::load_result_units( $uid );
		$kept    = array();
		$files   = 0;
		$bytes   = 0;
		foreach ( $units as $key => $unit ) {
			if ( ! is_array( $unit ) ) {
				continue;
			}
			$kept_files = array();
			$kept_bytes = 0;
			foreach ( (array) ( $unit['files'] ?? array() ) as $file ) {
				$rel = (string) ( is_array( $file ) ? ( $file['rel'] ?? '' ) : $file );
				if ( '' === $rel || ! is_file( $basedir . ltrim( str_replace( '\\', '/', $rel ), '/' ) ) ) {
					continue;
				}
				$kept_files[] = $file;
				$kept_bytes  += (int) ( is_array( $file ) ? ( $file['bytes'] ?? 0 ) : 0 );
			}
			if ( ! $kept_files ) {
				continue;
			}
			$kept[ $key ] = array(
				'files' => $kept_files,
				'bytes' => $kept_bytes,
			);
			$files       += count( $kept_files );
			$bytes       += $kept_bytes;
		}
		self::replace_result_units( $uid, $kept );
		return array(
			'files'  => $files,
			'bytes'  => $bytes,
			'groups' => count( $kept ),
		);
	}

	/**
	 * Clear result file.
	 *
	 * @param int $uid Uid.
	 */
	public static function clear_result_file( int $uid ): void {
		$file = self::scan_result_file( $uid );
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Encode json.
	 *
	 * @param mixed $data Data.
	 *
	 * @return string
	 */
	private static function encode_json( $data ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $data );
			if ( is_string( $json ) && '' !== $json ) {
				return $json;
			}
		}
		$json = wp_json_encode( $data );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * Check numeric list keys without relying on WordPress-version feature detection.
	 *
	 * @param array $items Items.
	 *
	 * @return bool
	 */
	private static function is_sequential_array( array $items ): bool {
		return ! $items || array_keys( $items ) === range( 0, count( $items ) - 1 );
	}

	/**
	 * Write reg file.
	 *
	 * @param int   $uid Uid.
	 * @param array $reg Reg.
	 *
	 * @return bool
	 */
	public static function write_reg_file( int $uid, array $reg ): bool {
		$paths = is_array( $reg['paths'] ?? null ) ? $reg['paths'] : array();
		$list  = ( $paths && self::is_sequential_array( $paths ) ) ? array_values( $paths ) : array_keys( $paths );
		$json  = self::encode_json(
			array(
				'bytes' => (int) ( $reg['bytes'] ?? 0 ),
				'paths' => $list,
			)
		);
		if ( '' === $json ) {
			return false;
		}
		return false !== file_put_contents( self::scan_reg_file( $uid ), $json, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
	}

	/**
	 * Read reg file.
	 *
	 * @param int $uid Uid.
	 *
	 * @return array|null
	 */
	public static function read_reg_file( int $uid ): ?array {
		$file = self::scan_reg_file( $uid );
		if ( ! is_file( $file ) ) {
			return null;
		}
		$raw = ( is_readable( $file ) ? file_get_contents( $file ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['paths'] ) || ! is_array( $data['paths'] ) ) {
			return null;
		}
		$paths = $data['paths'];
		if ( $paths && self::is_sequential_array( $paths ) ) {
			$paths = array_fill_keys( array_map( 'strval', $paths ), 1 );
		}
		return array(
			'bytes' => (int) ( $data['bytes'] ?? 0 ),
			'paths' => $paths,
		);
	}

	/**
	 * Load scan reg.
	 *
	 * @param int   $uid      Uid.
	 * @param mixed $fallback Fallback.
	 *
	 * @return array
	 */
	public static function load_scan_reg( int $uid, $fallback = null ): array {
		$file = self::read_reg_file( $uid );
		if ( is_array( $file ) ) {
			return $file;
		}
		if ( is_array( $fallback ) && isset( $fallback['paths'] ) && is_array( $fallback['paths'] ) ) {
			return array(
				'paths' => $fallback['paths'],
				'bytes' => (int) ( $fallback['bytes'] ?? 0 ),
			);
		}
		return array(
			'paths' => array(),
			'bytes' => 0,
		);
	}

	/**
	 * Append orphan units.
	 *
	 * @param int   $uid   Uid.
	 * @param array $units Units.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	public static function append_orphan_units( int $uid, array $units ): void {
		if ( ! $units ) {
			return;
		}
		$handle = fopen( self::scan_orphan_file( $uid ), 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			throw new RuntimeException( __( 'Could not write uploads inventory.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		foreach ( $units as $key => $unit ) {
			$line = self::encode_json(
				array(
					'key'  => (string) $key,
					'unit' => $unit,
				)
			);
			if ( '' !== $line ) {
				if ( fwrite( $handle, $line . "\n" ) !== strlen( $line ) + 1 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
					throw new RuntimeException( __( 'Uploads inventory write was incomplete.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
	}

	/**
	 * Flush scan orphans.
	 *
	 * @param int   $uid   Uid.
	 * @param array &$state State.
	 */
	public static function flush_scan_orphans( int $uid, array &$state ): void {
		$units = $state['orphan_units'] ?? array();
		if ( is_array( $units ) && $units ) {
			self::append_orphan_units( $uid, $units );
		}
		$state['orphan_units'] = array();
	}

	/**
	 * Collect scan orphans.
	 *
	 * @param int   $uid   Uid.
	 * @param array $state State.
	 *
	 * @return array
	 */
	public static function collect_scan_orphans( int $uid, array $state ): array {
		$out = self::load_orphan_units( $uid );
		foreach ( (array) ( $state['orphan_units'] ?? array() ) as $key => $unit ) {
			if ( ! is_array( $unit ) ) {
				continue;
			}
			$key = (string) $key;
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'files' => array(),
					'bytes' => 0,
				);
			}
			foreach ( (array) ( $unit['files'] ?? array() ) as $file_row ) {
				$out[ $key ]['files'][] = $file_row;
			}
			$out[ $key ]['bytes'] += (int) ( $unit['bytes'] ?? 0 );
		}
		return $out;
	}

	/**
	 * Load result units.
	 *
	 * @param int $uid Uid.
	 *
	 * @return array
	 */
	public static function load_result_units( int $uid ): array {
		$file = self::scan_result_file( $uid );
		if ( is_file( $file ) ) {
			return self::load_units_file( $file );
		}
		return self::load_orphan_units( $uid );
	}

	/**
	 * Load orphan units.
	 *
	 * @param int $uid Uid.
	 *
	 * @return array
	 */
	public static function load_orphan_units( int $uid ): array {
		return self::load_units_file( self::scan_orphan_file( $uid ) );
	}

	/**
	 * Load units file.
	 *
	 * @param string $file File.
	 *
	 * @return array
	 */
	private static function load_units_file( string $file ): array {
		$out = array();
		if ( ! is_file( $file ) ) {
			return $out;
		}
		$handle = ( is_readable( $file ) ? fopen( $file, 'rb' ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			return $out;
		}
		while ( true ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$row = json_decode( $line, true );
			if ( ! is_array( $row ) || empty( $row['key'] ) || ! is_array( $row['unit'] ?? null ) ) {
				continue;
			}
			$key  = (string) $row['key'];
			$unit = $row['unit'];
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'files' => array(),
					'bytes' => 0,
				);
			}
			foreach ( (array) ( $unit['files'] ?? array() ) as $file_row ) {
				$out[ $key ]['files'][] = $file_row;
			}
			$out[ $key ]['bytes'] += (int) ( $unit['bytes'] ?? 0 );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		return $out;
	}

	/**
	 * Clear scan files.
	 *
	 * @param int $uid Uid.
	 */
	public static function clear_scan_files( int $uid ): void {
		$cache = class_exists( 'LucidLab_MPC_Work', false ) ? LucidLab_MPC_Work::path( 'uploads-refs-bn-' . max( 0, $uid ) . '.json' ) : '';
		foreach ( array( self::scan_reg_file( $uid ), self::scan_orphan_file( $uid ), self::scan_ref_file( $uid ), self::scan_work_dir() . '/' . self::ref_work_file( $uid ), $cache ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Drop the directory at the front of the queue after a worker timeout.
	 *
	 * @param array &$state State.
	 *
	 * @return string
	 */
	public function abandon_queued_dir( array &$state ): string {
		if ( empty( $state['queue'] ) || ! is_array( $state['queue'] ) ) {
			return '';
		}
		$dir = array_shift( $state['queue'] );
		unset( $state['dir_offsets'][ $dir ] );
		$state['done_dirs']    = (int) ( $state['done_dirs'] ?? 0 ) + 1;
		$state['skipped_dirs'] = (int) ( $state['skipped_dirs'] ?? 0 ) + 1;
		$rel                   = (string) ( $state['current'] ?? '' );
		if ( '' === $rel ) {
			$rel = basename( str_replace( '\\', '/', (string) $dir ) );
		}
		$abandoned          = is_array( $state['abandoned'] ?? null ) ? $state['abandoned'] : array();
		$abandoned[]        = $rel;
		$state['abandoned'] = array_slice( $abandoned, -20 );
		$state['current']   = '';
		return $rel;
	}

	/**
	 * Apply current exclusions to old saved scans as well as newly finished scans.
	 *
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public static function filter_support_files( array $data ): array {
		$units = array();
		$bytes = 0;
		$count = 0;
		foreach ( ( $data['orphan_units'] ?? array() ) as $key => $unit ) {
			$unit['files'] = array_values(
				array_filter(
					$unit['files'] ?? array(),
					static function ( $file ) {
						$rel = (string) ( $file['rel'] ?? '' );
						if ( self::is_support_file( $rel ) ) {
							return false;
						}
						return ! class_exists( 'LucidLab_MPC_Exclusions', false ) || ! LucidLab_MPC_Exclusions::skip_rel( $rel );
					}
				)
			);
			if ( ! $unit['files'] ) {
				continue;
			}
			$unit['bytes'] = array_sum( array_column( $unit['files'], 'bytes' ) );
			$units[ $key ] = $unit;
			$bytes        += $unit['bytes'];
			$count        += count( $unit['files'] );
		}
		$data['orphan_units']  = $units;
		$data['orphan_bytes']  = $bytes;
		$data['orphan_files']  = $count;
		$data['orphan_groups'] = count( $units );
		return $data;
	}

	/**
	 * Build one batch of DB-registered absolute paths (originals + sizes + original_image).
	 *
	 * @param int   &$after_id After id.
	 * @param array &$reg      Reg.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return int
	 */
	public function registered_step( int &$after_id, array &$reg ): int {
		global $wpdb;
		$limit = (int) LucidLab_MPC_Settings::get( 'scan_batch_db' );
		if ( $limit < 1 ) {
			$limit = LucidLab_MPC_Helpers::BATCH;
		}
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
					$after_id,
					$limit
				)
			)
		);
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not index registered attachments.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		if ( ! $ids ) {
			return 0;
		}
		$after_id     = max( $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				 WHERE post_id IN ($placeholders) AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare().
				...$ids
			)
		);
		$files        = array();
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not index registered attachment files.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		$meta = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row->post_id;
			if ( '_wp_attached_file' === $row->meta_key ) {
				$files[ $id ] = (string) $row->meta_value;
			} else {
				$decoded = is_serialized( $row->meta_value ) ? unserialize( trim( (string) $row->meta_value ), array( 'allowed_classes' => false ) ) : $row->meta_value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Objects are disabled (allowed_classes false), so stored data cannot instantiate classes.
				if ( is_array( $decoded ) ) {
					$meta[ $id ] = $decoded;
				}
			}
		}
		$basedir = trailingslashit( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ) );
		// Count and protect a path only when that file is actually on disk.
		// Predicted sizes and missing originals used to inflate "registered paths".
		$add = static function ( string $path ) use ( &$reg ): void {
			$norm = LucidLab_MPC_Helpers::norm_path( $path );
			if ( '' === $norm || isset( $reg[ $norm ] ) || ! is_file( $norm ) ) {
				return;
			}
			$reg[ $norm ] = 1;
		};
		foreach ( $ids as $id ) {
			$rel = isset( $files[ $id ] ) ? (string) $files[ $id ] : '';
			if ( '' === $rel ) {
				continue;
			}
			$file = LucidLab_MPC_Helpers::uploads_path_from_meta( $rel );
			if ( '' === $file ) {
				continue;
			}
			$paths = array( $file );
			$dir   = trailingslashit( dirname( $file ) );
			$m     = $meta[ $id ] ?? array();
			if ( ! empty( $m['sizes'] ) && is_array( $m['sizes'] ) ) {
				foreach ( $m['sizes'] as $s ) {
					if ( ! empty( $s['file'] ) ) {
						$paths[] = $dir . $s['file'];
					}
				}
			}
			if ( ! empty( $m['original_image'] ) ) {
				$paths[] = $dir . $m['original_image'];
			}
			$paths = array_merge( $paths, $this->enabled_thumbnail_paths( $file, $m ) );
			foreach ( $paths as $p ) {
				$norm = LucidLab_MPC_Helpers::norm_path( $p );
				$add( $norm );
				$rel_path = ltrim( str_replace( '\\', '/', substr( $norm, strlen( rtrim( $basedir, '/' ) ) ) ), '/' );
				if ( '' === $rel_path || str_starts_with( $rel_path, '/' ) ) {
					$rel_path = ltrim( str_replace( $basedir, '', $norm ), '/' );
				}
				foreach ( array_unique( array_filter( array( $rel_path, LucidLab_MPC_Helpers::generated_copy_source( $rel_path ) ) ) ) as $source_rel ) {
					// Protect the logical JPEG/PNG original when the library file is already a WebP/AVIF copy.
					$add( $basedir . $source_rel );
					$unsized = (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $source_rel );
					if ( $unsized !== $source_rel ) {
						$add( $basedir . $unsized );
					}
					$scaled = (string) preg_replace( '/(?=\.[a-z0-9]+$)/i', '-scaled', $unsized );
					if ( $scaled !== $unsized ) {
						$add( $basedir . $scaled );
					}
					foreach ( LucidLab_MPC_Helpers::generated_copy_rels( $source_rel ) as $copy_rel ) {
						$add( $basedir . $copy_rel );
					}
					if ( $unsized !== $source_rel ) {
						foreach ( LucidLab_MPC_Helpers::generated_copy_rels( $unsized ) as $copy_rel ) {
							$add( $basedir . $copy_rel );
						}
					}
				}
			}
		}
		return count( $ids );
	}


	/**
	 * Protect only dimensions WordPress can generate for this registered source.
	 *
	 * @param string $file File.
	 * @param array  $meta Meta.
	 *
	 * @return array
	 */
	public function enabled_thumbnail_paths( string $file, array $meta ): array {
		$paths  = array();
		$width  = (int) ( $meta['width'] ?? 0 );
		$height = (int) ( $meta['height'] ?? 0 );
		if ( ! $width || ! $height ) {
			return $paths;
		}
		$sizes = array_diff_key( wp_get_registered_image_subsizes(), array_flip( (array) LucidLab_MPC_Settings::get( 'disabled_sizes' ) ) );
		$ext   = pathinfo( $file, PATHINFO_EXTENSION );
		$base  = substr( $file, 0, -strlen( $ext ) - 1 );
		foreach ( $sizes as $size ) {
			$d = image_resize_dimensions( $width, $height, (int) $size['width'], (int) $size['height'], $size['crop'] );
			if ( $d ) {
				$paths[] = $base . '-' . $d[4] . 'x' . $d[5] . '.' . $ext;
			}
		}
		return array_values( array_unique( $paths ) );
	}
	/**
	 * Recheck an upload candidate immediately before moving it; results may be stale.
	 *
	 * @param string $rel Rel.
	 *
	 * @return bool
	 */
	public function is_protected_rel( string $rel ): bool {
		return $this->protected_rels( array( $rel ) )[ $rel ];
	}

	/**
	 * Whether each upload-relative path belongs to a registered original or one of its sizes, a generated WebP/AVIF copy of those, or a basename still referenced in the database.
	 *
	 * @param string[] $rels Rels.
	 *
	 * @return array<string,bool> Keyed by the given path.
	 * @throws RuntimeException When the operation fails.
	 */
	public function protected_rels( array $rels ): array {
		global $wpdb;
		// Cache only protective decisions. A cached miss can never authorize quarantine.
		$cache_keys = array();
		$cached     = array();
		$generation = (string) get_option( 'lucidlab_mpc_index_generation', 0 );
		if ( class_exists( 'LucidLab_MPC_Result_Cache' ) ) {
			foreach ( $rels as $raw ) {
				$rel = LucidLab_MPC_Helpers::sanitize_rel( (string) $raw );
				if ( ! $rel ) {
					continue; }
				$signature = LucidLab_MPC_Result_Cache::file_signature( LucidLab_MPC_Helpers::uploads_basedir() . $rel );
				if ( null !== $signature ) {
					$cache_keys[ $raw ] = $generation . ':' . $signature; }
			}
			$cached = array_filter( LucidLab_MPC_Result_Cache::read( 'uploads-keep', $cache_keys ), static fn( $v ) => true === $v );
		}
		$out      = array();
		$targets  = array();
		$want     = array();
		$values   = array();
		$check_bn = array();
		$base     = LucidLab_MPC_Helpers::uploads_basedir();
		foreach ( $rels as $raw ) {
			$raw = (string) $raw;
			if ( isset( $cached[ $raw ] ) ) {
				$out[ $raw ] = true;
				continue;
			}$rel = LucidLab_MPC_Helpers::sanitize_rel( $raw );
			if ( ! $rel || self::is_support_file( $rel ) ) {
				$out[ $raw ] = true;
				continue;}
			$out[ $raw ]     = false;
			$targets[ $raw ] = LucidLab_MPC_Helpers::norm_path( $base . $rel );
			$source          = LucidLab_MPC_Helpers::generated_copy_source( $rel );
			$original        = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', '' !== $source ? $source : $rel );
			$scaled          = preg_replace( '/(?=\.[a-z0-9]+$)/i', '-scaled', $original );
			foreach ( array_unique( array_filter( array( $rel, $original, $scaled, $source ) ) ) as $candidate ) {
				$want[ strtolower( $candidate ) ][ $raw ] = true;
				$values[ $candidate ]                     = true;}
			// Library may store the WebP/AVIF path; still match the JPEG/PNG under test.
			foreach ( LucidLab_MPC_Helpers::generated_copy_rels( (string) $original ) as $copy_rel ) {
				$want[ strtolower( $copy_rel ) ][ $raw ] = true;
				$values[ $copy_rel ]                     = true;}
			$bn = strtolower( basename( (string) $original ) );
			if ( '' !== $bn ) {
				$check_bn[ $bn ][ $raw ] = true;}
			foreach ( LucidLab_MPC_Helpers::generated_copy_rels( (string) $original ) as $copy_rel ) {
				$cbn = strtolower( basename( $copy_rel ) );
				if ( '' !== $cbn ) {
					$check_bn[ $cbn ][ $raw ] = true;}
			}
		}
		$paths_by_id = array();
		foreach ( array_chunk( array_keys( $values ), 300 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows         = self::timed_query( 'media_sql', static fn()=>$wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value IN ($placeholders)", ...$chunk ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( $wpdb->last_error ) {
				throw new RuntimeException( __( 'Could not verify registered media. No files were quarantined in this batch.', 'lucidlab-mpc-cleanup' ) );} // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			foreach ( (array) $rows as $row ) {
				$id = (int) $row->post_id;
				if ( ! isset( $paths_by_id[ $id ] ) ) {
					$paths = array();
					$file  = get_attached_file( $id );
					if ( $file ) {
						$meta  = (array) wp_get_attachment_metadata( $id );
						$dir   = trailingslashit( dirname( $file ) );
						$paths = array_merge( array( $file ), $this->enabled_thumbnail_paths( $file, $meta ) );
						foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
							if ( ! empty( $size['file'] ) ) {
								$paths[] = $dir . $size['file'];
							}
						}
						if ( ! empty( $meta['original_image'] ) ) {
							$paths[] = $dir . $meta['original_image'];
						}
						$attached_rel = ltrim( str_replace( '\\', '/', (string) $row->meta_value ), '/' );
						$logical_src  = LucidLab_MPC_Helpers::generated_copy_source( $attached_rel );
						if ( '' !== $logical_src ) {
							$paths[]     = $base . $logical_src;
							$unsized_src = (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $logical_src );
							if ( $unsized_src !== $logical_src ) {
								$paths[] = $base . $unsized_src;
							}
						}
						foreach ( array_merge(
							array( $attached_rel ),
							array_map(
								static function ( $abs ) use ( $base ) {
									$abs = LucidLab_MPC_Helpers::norm_path( $abs );
									$root = rtrim( LucidLab_MPC_Helpers::norm_path( $base ), '/' );
									return str_starts_with( $abs, $root ) ? ltrim( substr( $abs, strlen( $root ) ), '/' ) : '';
								},
								$paths
							)
						) as $copy_src ) {
							if ( '' === $copy_src ) {
								continue;
							}
							foreach ( LucidLab_MPC_Helpers::generated_copy_rels( $copy_src ) as $gen_rel ) {
								$paths[] = $base . $gen_rel;}
						}
					}
					$paths_by_id[ $id ] = array_flip( array_map( array( 'LucidLab_MPC_Helpers', 'norm_path' ), $paths ) );
				}
				$attached_abs = LucidLab_MPC_Helpers::norm_path( $base . ltrim( str_replace( '\\', '/', (string) $row->meta_value ), '/' ) );
				foreach ( array_keys( $want[ strtolower( (string) $row->meta_value ) ] ?? array() ) as $raw ) {
					if ( isset( $paths_by_id[ $id ][ $targets[ $raw ] ] ) ) {
						$out[ $raw ] = true;
						continue;}
					// Extra on-disk size or generated copy of this registered original.
					$cand_rel  = LucidLab_MPC_Helpers::sanitize_rel( $raw );
					$cand_src  = LucidLab_MPC_Helpers::generated_copy_source( $cand_rel );
					$cand_orig = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', '' !== $cand_src ? $cand_src : $cand_rel );
					if ( LucidLab_MPC_Helpers::norm_path( $base . $cand_orig ) === $attached_abs || isset( $paths_by_id[ $id ][ LucidLab_MPC_Helpers::norm_path( $base . $cand_orig ) ] ) ) {
						$out[ $raw ] = true;}
				}
			}
		}
		// Preserve SQL collation and conservative LIKE semantics, but scan each
		// source once per group instead of issuing five queries per filename.
		// Any row containing a filename also contains its extension, so a single
		// extension LIKE safely skips rows before the per-filename checks run.
		$by_ext = array();
		foreach ( $check_bn as $bn => $raws ) {
			$dot = strrpos( (string) $bn, '.' );
			foreach ( $raws as $raw => $_ ) {
				if ( empty( $out[ $raw ] ) ) {
					$by_ext[ false === $dot ? '' : substr( (string) $bn, $dot ) ][ $bn ][ $raw ] = true;
				}
			}
		}
		$groups = array();
		foreach ( $by_ext as $ext => $pending ) {
			// Cluster related filenames so each query has a stronger common literal.
			ksort( $pending, SORT_STRING );
			foreach ( array_chunk( $pending, 100, true ) as $names ) {
				$groups[] = array( (string) $ext, $names );
			}
		}
		foreach ( $groups as [$ext, $names] ) {
			$sources = array(
				array( $wpdb->posts, "post_status NOT IN ('trash','auto-draft')", array( 'post_content', 'post_excerpt' ) ),
				array( $wpdb->postmeta, "meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes')", array( 'meta_value' ) ),
				array( $wpdb->term_taxonomy, '1=1', array( 'description' ) ),
				array( $wpdb->termmeta, '1=1', array( 'meta_value' ) ),
				array( $wpdb->options, $wpdb->prepare( 'option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s', $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( '_site_transient' ) . '%', $wpdb->esc_like( 'lucidlab_mpc_' ) . '%' ), array( 'option_value' ) ),
			);
			foreach ( $sources as [$table, $where, $columns] ) {
				$select = array();
				$keys   = array_keys( $names );
				$filter = '';
				$anchor = self::content_search_anchor( $keys, $ext );
				if ( '' !== $anchor ) {
					$filter = array();
					foreach ( $columns as $column ) {
						$condition = $wpdb->prepare( '%i LIKE %s', $column, '%' . $wpdb->esc_like( $anchor ) . '%' );
						if ( '' !== $ext && ! str_contains( $anchor, $ext ) ) {
							$condition = '(' . $wpdb->prepare( '%i LIKE %s', $column, '%' . $wpdb->esc_like( $ext ) . '%' ) . ' AND ' . $condition . ')';
						}
						$filter[] = $condition;
					}
					$filter = ' AND (' . implode( ' OR ', $filter ) . ')';
				}
				foreach ( $keys as $i => $bn ) {
					$conditions = array();
					foreach ( $columns as $column ) {
						$conditions[] = $wpdb->prepare( '%i LIKE %s', $column, '%' . $wpdb->esc_like( $bn ) . '%' );
					}
					$select[] = 'MAX(CASE WHEN (' . implode( ' OR ', $conditions ) . ') THEN 1 ELSE 0 END) AS hit_' . $i;
				}
				$rows = self::timed_query( 'content_sql', static fn()=>$wpdb->get_results( 'SELECT ' . implode( ',', $select ) . " FROM $table WHERE $where$filter" ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- columns and LIKE values are prepared; tables are WordPress core tables; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				if ( $wpdb->last_error || ! isset( $rows[0] ) ) {
					throw new RuntimeException( __( 'Could not verify current media references. No files were quarantined in this batch.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				}
				foreach ( $keys as $i => $bn ) {
					if ( ! empty( $rows[0]->{'hit_' . $i} ) ) {
						foreach ( $names[ $bn ] as $raw => $_ ) {
							$out[ $raw ] = true;
						}unset( $names[ $bn ] );}
				}
				if ( ! $names ) {
					break;
				}
			}
		}
		if ( $cache_keys && (string) get_option( 'lucidlab_mpc_index_generation', 0 ) === $generation ) {
			// Do not extend the lifetime of reused entries indefinitely.
			$fresh = array_diff_key( array_filter( $out, static fn( $v ) => true === $v ), $cached );
			LucidLab_MPC_Result_Cache::write( 'uploads-keep', $cache_keys, $fresh, 900 );
		}
		return $out;
	}

	/**
	 * A common literal is a necessary condition for every filename LIKE below.
	 *
	 * @param array  $names     Names.
	 * @param string $extension Extension.
	 *
	 * @return string
	 */
	private static function content_search_anchor( array $names, string $extension ): string {
		if ( ! $names ) {
			return $extension;
		}
		// Work in Unicode characters so a shared byte never becomes invalid UTF-8.
		$prefix = preg_split( '//u', (string) $names[0], -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $prefix ) {
			return $extension;
		}
		$suffix = array_reverse( $prefix );
		foreach ( array_slice( $names, 1 ) as $name ) {
			$chars = preg_split( '//u', (string) $name, -1, PREG_SPLIT_NO_EMPTY );
			if ( false === $chars ) {
				return $extension;
			}
			foreach ( array(
				'prefix' => $chars,
				'suffix' => array_reverse( $chars ),
			) as $kind => $other ) {
				$common = $$kind;
				$n      = 0;
				while ( isset( $common[ $n ], $other[ $n ] ) && $common[ $n ] === $other[ $n ] ) {
					++$n;
				}
				$$kind = array_slice( $common, 0, $n );
			}
		}
		$best = count( $prefix ) > count( $suffix ) ? implode( '', $prefix ) : implode( '', array_reverse( $suffix ) );
		// Exporters often add a varying numeric suffix after a shared descriptive word.
		foreach ( (array) preg_split( '/[-_.\s]+/u', (string) $names[0] ) as $word ) {
			if ( strlen( $word ) <= strlen( $best ) ) {
				continue;
			}
			foreach ( $names as $name ) {
				if ( ! str_contains( (string) $name, $word ) ) {
					continue 2;
				}
			}
			$best = $word;
		}
		return strlen( $best ) > strlen( $extension ) + 2 ? $best : $extension;
	}

	/**
	 * Timed query.
	 *
	 * @param string   $section Section.
	 * @param callable $query   Query.
	 */
	private static function timed_query( string $section, callable $query ) {
		return class_exists( 'LucidLab_MPC_Scan_Profile' ) ? LucidLab_MPC_Scan_Profile::timed( $section, $query ) : $query();
	}

	/**
	 * Process directories from $state['queue'] until ~AJAX_FILES files handled this step.
	 * Accumulates totals + orphan_units in $state. $reg = registered path set.
	 */

	/** Synchronous full scan (CSV export + weekly report). */
}
