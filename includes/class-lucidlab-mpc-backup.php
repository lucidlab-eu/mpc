<?php
/**
 * Lucid Lab Media & Products Cleanup: backup class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Site backups stored in a protected, randomly named folder under uploads: SQL dumps, uploads archives, and full-site archives with SQL. */
class LucidLab_MPC_Backup {
	const JOB_OPTION       = 'lucidlab_mpc_backup_job';
	const CANCEL_OPTION    = 'lucidlab_mpc_backup_cancel';
	const PID_OPTION       = 'lucidlab_mpc_backup_pid';
	const CRON_HOOK        = 'lucidlab_mpc_backup_tick';
	const KEEP             = 5;
	const SQL_IN_SITE      = '__lucidlab_mpc_database.sql';
	const SQL_IN_SITE_GZ   = '__lucidlab_mpc_database.sql.gz';
	const PART_SUFFIX      = '.part';
	const RESTORE_SUFFIX   = '.restore.tmp';
	const SIGN_OPTION      = 'lucidlab_mpc_backup_sign_key';
	const SIG_CACHE_OPTION = 'lucidlab_mpc_backup_sig_cache';
	// Full signature checks that passed, keyed by file name: path, size, mtime, signing key and sha256 of the stored file.
	const VERIFIED_OPTION = 'lucidlab_mpc_backup_verified';
	const SIGN_PREFIX     = '-- LUCIDLAB-MPC-SIGNATURE v1 ';

	/**
	 * Keep.
	 *
	 * @return int
	 */
	public static function keep(): int {
		$n = class_exists( 'LucidLab_MPC_Settings', false ) ? (int) LucidLab_MPC_Settings::get( 'backup_keep' ) : 0;
		return $n > 0 ? max( 1, min( 50, $n ) ) : self::KEEP;
	}

	/**
	 * Site root.
	 *
	 * @return string
	 */
	public static function site_root(): string {
		$root = defined( 'ABSPATH' ) ? ABSPATH : dirname( LUCIDLAB_MPC_DIR, 3 );
		return rtrim( str_replace( '\\', '/', $root ), '/' );
	}

	/**
	 * Dir.
	 *
	 * @return string
	 */
	public static function dir(): string {
		// Backups live under uploads in a folder with an unguessable name, plus deny rules from protect().
		$key = (string) get_option( 'lucidlab_mpc_backup_dir_key', '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			$key = bin2hex( random_bytes( 16 ) );
			update_option( 'lucidlab_mpc_backup_dir_key', $key, false );
		}
		// uploads/lucidlab-mpc-cleanup/backups-<random>/ (an older uploads/lucidlab-mpc-backups-<random>/ is used until it is moved).
		return LucidLab_MPC_Storage::dir( 'backups-' . $key );
	}

	/**
	 * Protect.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return string
	 */
	public static function protect(): string {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( sprintf( /* translators: %s: folder path. */ __( 'Could not create the backup folder at %s.', 'lucidlab-mpc-cleanup' ), $dir ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( ! is_writable( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			throw new RuntimeException( sprintf( /* translators: %s: folder path. */ __( 'The backup folder is not writable: %s', 'lucidlab-mpc-cleanup' ), $dir ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( ! is_file( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		if ( ! is_file( $dir . '/web.config' ) ) {
			file_put_contents( $dir . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		return $dir;
	}

	/**
	 * Restore key.
	 *
	 * @return string
	 */
	public static function restore_key(): string {
		$dir  = self::protect();
		$file = $dir . '/restore.key';
		if ( is_readable( $file ) ) {
			$key = trim( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			if ( '' !== $key ) {
				return $key;
			}
		}
		$key = bin2hex( random_bytes( 24 ) );
		file_put_contents( $file, $key ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		return $key;
	}

	/**
	 * Restore url.
	 *
	 * @return string
	 */
	public static function restore_url(): string {
		return '';
	}

	/**
	 * Secret key that signs database backups made on this site. Created once; kept by Reset so older backups stay restorable.
	 *
	 * @return string
	 */
	private static function sign_key(): string {
		$key = (string) get_option( self::SIGN_OPTION, '' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
			$key = bin2hex( random_bytes( 32 ) );
			update_option( self::SIGN_OPTION, $key, false );
		}
		return $key;
	}

	/**
	 * Length in bytes of the signature line appended to a signed SQL dump.
	 *
	 * @return int
	 */
	private static function signature_length(): int {
		return strlen( self::SIGN_PREFIX ) + 64 + 1;
	}

	/**
	 * HMAC-SHA256 of the first $length bytes of a file with this site's backup key.
	 *
	 * @param string $path   File.
	 * @param int    $length Bytes to hash.
	 *
	 * @throws RuntimeException When the file cannot be read.
	 * @return string
	 */
	private static function hmac_prefix( string $path, int $length ): string {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $fh ) {
			throw new RuntimeException( __( 'Could not read the database backup.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$ctx = hash_init( 'sha256', HASH_HMAC, self::sign_key() );
		if ( $length > 0 ) {
			hash_update_stream( $ctx, $fh, $length );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		return hash_final( $ctx );
	}

	/**
	 * Append this site's signature line to a finished, uncompressed SQL dump (idempotent).
	 *
	 * @param string $path Uncompressed .sql file.
	 *
	 * @throws RuntimeException When the dump cannot be signed.
	 */
	private static function sign_sql_file( string $path ): void {
		if ( '' === $path || ! is_file( $path ) ) {
			return;
		}
		LucidLab_MPC_Helpers::extend_time_limit( 300 );
		clearstatcache( true, $path );
		if ( self::sql_signature_valid( $path ) ) {
			return;
		}
		$size = (int) filesize( $path );
		if ( $size > 0 ) {
			$fh   = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			$last = '';
			if ( $fh ) {
				fseek( $fh, $size - 1 );
				$last = (string) fread( $fh, 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			}
			if ( "\n" !== $last ) {
				file_put_contents( $path, "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				clearstatcache( true, $path );
				$size = (int) filesize( $path );
			}
		}
		if ( false === file_put_contents( $path, self::SIGN_PREFIX . self::hmac_prefix( $path, $size ) . "\n", FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			throw new RuntimeException( __( 'Could not sign the database backup.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
	}

	/**
	 * Whether an uncompressed SQL dump ends with a valid signature from this site's backup key.
	 *
	 * @param string $path Uncompressed .sql file.
	 *
	 * @return bool
	 */
	private static function sql_signature_valid( string $path ): bool {
		clearstatcache( true, $path );
		$size = is_file( $path ) ? (int) filesize( $path ) : 0;
		$len  = self::signature_length();
		if ( $size <= $len ) {
			return false;
		}
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $fh ) {
			return false;
		}
		fseek( $fh, $size - $len );
		$tail = (string) fread( $fh, $len ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! preg_match( '/^' . preg_quote( self::SIGN_PREFIX, '/' ) . '([a-f0-9]{64})\n$/', $tail, $m ) ) {
			return false;
		}
		try {
			return hash_equals( self::hmac_prefix( $path, $size - $len ), $m[1] );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Refuse SQL that this site's plugin did not create. Restoring runs every statement in the file, so a
	 * foreign or edited dump could change any data on the site.
	 *
	 * Emergency override for administrators with server access only: define( 'LUCIDLAB_MPC_ALLOW_UNSIGNED_DB_RESTORE', true ).
	 *
	 * @param string $path Uncompressed .sql file.
	 *
	 * @throws RuntimeException When the dump is not signed by this site.
	 */
	private static function assert_restorable_sql( string $path ): void {
		if ( self::allow_unsigned_restore() ) {
			return;
		}
		LucidLab_MPC_Helpers::extend_time_limit( 300 );
		if ( ! self::sql_signature_valid( $path ) ) {
			throw new RuntimeException( self::unsigned_message( 'restore' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
	}

	/**
	 * Whether the emergency override for unsigned database restores is set in wp-config.php.
	 *
	 * @return bool
	 */
	public static function allow_unsigned_restore(): bool {
		return defined( 'LUCIDLAB_MPC_ALLOW_UNSIGNED_DB_RESTORE' ) && LUCIDLAB_MPC_ALLOW_UNSIGNED_DB_RESTORE;
	}

	/**
	 * Message shown when a database backup is not signed by this site.
	 *
	 * @param string $context 'import' or 'restore'.
	 *
	 * @return string
	 */
	public static function unsigned_message( string $context = 'restore' ): string {
		if ( 'import' === $context ) {
			return __( 'This database backup was not created by Media & Products Cleanup on this site, or it was changed after it was made, so it was not imported. Restore it with your hosting provider\'s database tools (for example phpMyAdmin) instead.', 'lucidlab-mpc-cleanup' );
		}
		return __( 'This database backup was not created by Media & Products Cleanup on this site, or it was changed after it was made, so it cannot be restored here. Restore it with your hosting provider\'s database tools (for example phpMyAdmin) instead.', 'lucidlab-mpc-cleanup' );
	}

	/**
	 * Whether a gzip-compressed SQL dump ends with a valid signature from this site's backup key.
	 * Streams the archive once; nothing is written to disk.
	 *
	 * @param string $archive .sql.gz file.
	 *
	 * @return bool
	 */
	private static function gz_signature_valid( string $archive ): bool {
		if ( ! function_exists( 'gzopen' ) || ! is_file( $archive ) ) {
			return false;
		}
		LucidLab_MPC_Helpers::extend_time_limit( 300 );
		$in = gzopen( $archive, 'rb' );
		if ( ! $in ) {
			return false;
		}
		$len  = self::signature_length();
		$ctx  = hash_init( 'sha256', HASH_HMAC, self::sign_key() );
		$tail = '';
		while ( ! gzeof( $in ) ) {
			$chunk = gzread( $in, 1048576 );
			if ( false === $chunk ) {
				gzclose( $in );
				return false;
			}
			$data = $tail . $chunk;
			if ( strlen( $data ) > $len ) {
				hash_update( $ctx, substr( $data, 0, -$len ) );
				$tail = substr( $data, -$len );
			} else {
				$tail = $data;
			}
		}
		gzclose( $in );
		if ( strlen( $tail ) !== $len || ! preg_match( '/^' . preg_quote( self::SIGN_PREFIX, '/' ) . '([a-f0-9]{64})\n$/', $tail, $m ) ) {
			return false;
		}
		return hash_equals( hash_final( $ctx ), $m[1] );
	}

	/**
	 * Whether a stored database backup (.sql or .sql.gz) is signed by this site and unchanged.
	 *
	 * @param string $path Backup file.
	 *
	 * @return bool
	 */
	public static function backup_signature_valid( string $path ): bool {
		$ext = self::file_ext( $path );
		try {
			if ( 'sql' === $ext ) {
				return self::sql_signature_valid( $path );
			}
			if ( 'sql.gz' === $ext ) {
				return self::gz_signature_valid( $path );
			}
		} catch ( Throwable $e ) {
			return false;
		}
		return false;
	}

	/**
	 * Refuse a stored database backup (.sql or .sql.gz) that this site's plugin did not create.
	 * Runs before any restore step, so nothing is decompressed, changed or executed for a foreign file.
	 *
	 * @param string $path    Backup file.
	 * @param string $context 'import' or 'restore'.
	 *
	 * @throws RuntimeException When the backup is not signed by this site.
	 */
	public static function assert_restorable_backup( string $path, string $context = 'restore' ): void {
		if ( self::allow_unsigned_restore() ) {
			return;
		}
		$valid = self::backup_signature_valid( $path );
		self::remember_signature( basename( $path ), $path, $valid );
		self::remember_verified( $path, $valid );
		if ( ! $valid ) {
			throw new RuntimeException( self::unsigned_message( $context ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
	}

	/**
	 * Fingerprint of a stored backup for the verification cache: full path, size, mtime, signing key and the
	 * sha256 of the stored (compressed) bytes. Hashing a 40 MB .sql.gz takes a fraction of a second; the full
	 * signature check has to decompress and HMAC the whole dump (300+ MB).
	 *
	 * @param string $path Backup file.
	 *
	 * @return string Empty when the file cannot be read.
	 */
	private static function verified_fingerprint( string $path ): string {
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) {
			return '';
		}
		$sha = hash_file( 'sha256', $path );
		if ( ! is_string( $sha ) ) {
			return '';
		}
		return hash( 'sha256', str_replace( '\\', '/', $path ) ) . ':' . self::signature_cache_stamp( $path ) . ':' . $sha;
	}

	/**
	 * Record (or forget) a passed full signature check.
	 *
	 * @param string $path  Backup file.
	 * @param bool   $valid Whether the full check passed.
	 */
	private static function remember_verified( string $path, bool $valid ): void {
		$name  = basename( $path );
		$cache = get_option( self::VERIFIED_OPTION, array() );
		$cache = is_array( $cache ) ? $cache : array();
		unset( $cache[ $name ] );
		if ( $valid && self::safe_name( $name ) ) {
			$print = self::verified_fingerprint( $path );
			if ( '' !== $print ) {
				$cache[ $name ] = $print;
			}
		}
		$existing = array_flip( array_map( 'basename', (array) glob( self::dir() . '/db-*' ) ) );
		update_option( self::VERIFIED_OPTION, array_intersect_key( $cache, $existing ), false );
	}

	/**
	 * Whether this exact file (same path, size, mtime, signing key and content hash) already passed a full check.
	 * Used only by the pre-check before the RESTORE prompt; the restore itself always runs the full check.
	 *
	 * @param string $path Backup file.
	 *
	 * @return bool
	 */
	private static function verified_before( string $path ): bool {
		$cache = get_option( self::VERIFIED_OPTION, array() );
		$hit   = is_array( $cache ) ? (string) ( $cache[ basename( $path ) ] ?? '' ) : '';
		return '' !== $hit && hash_equals( $hit, self::verified_fingerprint( $path ) );
	}

	/**
	 * Cache key for a backup's signature check: file size, mtime and the current signing key.
	 *
	 * @param string $path Backup file.
	 *
	 * @return string
	 */
	private static function signature_cache_stamp( string $path ): string {
		clearstatcache( true, $path );
		return (int) filesize( $path ) . ':' . (int) filemtime( $path ) . ':' . substr( hash( 'sha256', self::sign_key() ), 0, 12 );
	}

	/**
	 * Remember a signature check result for the backup list (display only; restore always re-checks the file).
	 *
	 * @param string $name  Backup file name.
	 * @param string $path  Backup file.
	 * @param bool   $valid Whether the signature is valid.
	 */
	private static function remember_signature( string $name, string $path, bool $valid ): void {
		if ( ! self::safe_name( $name ) || ! is_file( $path ) ) {
			return;
		}
		$cache          = get_option( self::SIG_CACHE_OPTION, array() );
		$cache          = is_array( $cache ) ? $cache : array();
		$cache[ $name ] = self::signature_cache_stamp( $path ) . ':' . ( $valid ? 'signed' : 'unsigned' );
		$existing       = array_flip( array_map( 'basename', (array) glob( self::dir() . '/db-*' ) ) );
		$cache          = array_intersect_key( $cache, $existing + array( $name => 1 ) );
		update_option( self::SIG_CACHE_OPTION, $cache, false );
	}

	/**
	 * Signature state of a stored database backup for the backup list: 'signed', 'unsigned' or 'unchecked'.
	 * Uses the cached result when the file is unchanged; checks at most a few seconds' worth of files per request.
	 *
	 * @param string $name     Backup file name.
	 * @param string $path     Backup file.
	 * @param float  $deadline Microtime after which uncached files are left unchecked.
	 *
	 * @return string
	 */
	private static function signature_state( string $name, string $path, float $deadline ): string {
		$cache = get_option( self::SIG_CACHE_OPTION, array() );
		$hit   = is_array( $cache ) ? (string) ( $cache[ $name ] ?? '' ) : '';
		$stamp = self::signature_cache_stamp( $path );
		if ( '' !== $hit && str_starts_with( $hit, $stamp . ':' ) ) {
			return substr( $hit, strlen( $stamp ) + 1 );
		}
		if ( microtime( true ) > $deadline ) {
			return 'unchecked';
		}
		$valid = self::backup_signature_valid( $path );
		self::remember_signature( $name, $path, $valid );
		return $valid ? 'signed' : 'unsigned';
	}

	/**
	 * Remove a trailing signature line from the unparsed SQL buffer at the end of a restore.
	 *
	 * @param string $buffer Remaining SQL buffer.
	 *
	 * @return string
	 */
	private static function strip_signature_line( string $buffer ): string {
		return (string) preg_replace( '/(?:^|\n)' . preg_quote( self::SIGN_PREFIX, '/' ) . '[a-f0-9]{64}\s*$/', '', $buffer );
	}

	/**
	 * Write restore script.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	public static function write_restore_script(): string {
		throw new RuntimeException( __( 'Download the backup and restore it with your hosting provider or WordPress tools. A web-accessible emergency restore script is not provided.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
	}

	/**
	 * Safe name.
	 *
	 * @param string $name Name.
	 *
	 * @return string
	 */
	public static function safe_name( string $name ): string {
		$name = str_replace( '\\', '/', $name );
		if ( '' === $name || basename( $name ) !== $name ) {
			return '';
		}
		return preg_match( '/^(db|uploads|site)-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.(sql|sql\.gz|sql\.7z|zip|7z)$/', $name ) ? $name : '';
	}

	/**
	 * Whether there is backups.
	 *
	 * @return bool
	 */
	public static function has_backups(): bool {
		return (bool) self::list_backups();
	}

	/**
	 * Require an existing, recent database-capable backup before dropping unused tables.
	 *
	 * @return bool
	 */
	public static function recent_database_backup(): bool {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$entries = ( is_dir( $dir ) ? scandir( $dir ) : false );
		if ( ! is_array( $entries ) ) {
			return false;
		}
		$cutoff      = time() - DAY_IN_SECONDS;
		$job         = self::job();
		$in_progress = in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'paused' ), true )
			? basename( (string) ( $job['raw_path'] ?? '' ) ) : '';
		foreach ( $entries as $name ) {
			if ( ! self::safe_name( $name ) || ! preg_match( '/^(?:db|site)-/', $name ) || $name === $in_progress ) {
				continue;
			}
			$file = $dir . '/' . $name;
			if ( is_file( $file ) && (int) ( is_file( $file ) ? filesize( $file ) : false ) > 0 && (int) ( is_file( $file ) ? filemtime( $file ) : false ) >= $cutoff ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * File ext.
	 *
	 * @param string $name Name.
	 *
	 * @return string
	 */
	public static function file_ext( string $name ): string {
		$name = strtolower( basename( str_replace( '\\', '/', $name ) ) );
		foreach ( array( 'sql.7z', 'sql.gz', 'sql', '7z', 'zip' ) as $ext ) {
			if ( str_ends_with( $name, '.' . $ext ) ) {
				return $ext;
			}
		}
		return '';
	}

	/**
	 * Engine.
	 *
	 * @return array
	 */
	public static function engine(): array {
		$gzip = function_exists( 'gzopen' );
		$zip  = class_exists( 'ZipArchive' );
		return array(
			'gzip'         => $gzip,
			'zip'          => $zip,
			// Database dumps are only ever written gzip-compressed, so plain SQL never sits on disk.
			'db_ext'       => 'sql.gz',
			'uploads_ext'  => 'zip',
			'site_ext'     => 'zip',
			'db_note'      => $gzip ? __( 'SQL dump + gzip', 'lucidlab-mpc-cleanup' ) : __( 'Database backups need the PHP zlib extension (gzip).', 'lucidlab-mpc-cleanup' ),
			'uploads_note' => $zip ? __( 'ZIP store (no extra compression)', 'lucidlab-mpc-cleanup' ) : '',
			'site_note'    => $zip ? __( 'WordPress files + SQL, ZIP', 'lucidlab-mpc-cleanup' ) : '',
		);
	}






	/**
	 * Path.
	 *
	 * @param string $name Name.
	 *
	 * @return string
	 */
	public static function path( string $name ): string {
		$safe = self::safe_name( $name );
		return $safe ? self::dir() . '/' . $safe : '';
	}

	/**
	 * Size detail.
	 *
	 * @param string $kind         Kind.
	 * @param int    $raw_bytes    Raw bytes.
	 * @param int    $packed_bytes Packed bytes.
	 *
	 * @return string
	 */
	public static function size_detail( string $kind, int $raw_bytes, int $packed_bytes ): string {
		$packed = $packed_bytes > 0 ? self::bytes_label( $packed_bytes ) : '';
		$raw    = $raw_bytes > 0 ? self::bytes_label( $raw_bytes ) : '';
		if ( $raw && $packed && $raw_bytes > $packed_bytes + 1024 ) {
			return 'db' === $kind
				? sprintf( /* translators: 1: uncompressed SQL size, 2: archive size. */ __( '%1$s SQL · %2$s archive', 'lucidlab-mpc-cleanup' ), $raw, $packed )
				: sprintf( /* translators: 1: uncompressed size, 2: archive size. */ __( '%1$s · %2$s archive', 'lucidlab-mpc-cleanup' ), $raw, $packed );
		}
		return ( $packed ? $packed : $raw );
	}

	/**
	 * Bytes label.
	 *
	 * @param int $bytes Bytes.
	 *
	 * @return string
	 */
	private static function bytes_label( int $bytes ): string {
		return LucidLab_MPC_Helpers::bytes( $bytes );
	}

	/**
	 * Meta path.
	 *
	 * @param string $name Name.
	 *
	 * @return string
	 */
	private static function meta_path( string $name ): string {
		$path = self::path( $name );
		return $path ? $path . '.meta.json' : '';
	}

	/**
	 * Write meta.
	 *
	 * @param string $name         Name.
	 * @param int    $raw_bytes    Raw bytes.
	 * @param int    $packed_bytes Packed bytes.
	 */
	private static function write_meta( string $name, int $raw_bytes, int $packed_bytes ): void {
		$file = self::meta_path( $name );
		if ( ! $file ) {
			return;
		}
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			$file,
			wp_json_encode(
				array(
					'raw_bytes'    => $raw_bytes,
					'packed_bytes' => $packed_bytes,
				)
			)
		);
	}

	/**
	 * Read meta.
	 *
	 * @param string $name Name.
	 *
	 * @return array
	 */
	private static function read_meta( string $name ): array {
		$file = self::meta_path( $name );
		if ( ! $file || ! is_file( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Gzip isize.
	 *
	 * @param string $path Path.
	 *
	 * @return int
	 */
	private static function gzip_isize( string $path ): int {
		if ( ! $path || ! is_file( $path ) ) {
			return 0;
		}
		// Incomplete gzip (header + FNAME only) ends with "...sql\0", which unpacks to a fake ~7MB ISIZE.
		if ( (int) filesize( $path ) < 64 ) {
			return 0;
		}
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $fh ) {
			return 0;
		}
		fseek( $fh, -4, SEEK_END );
		$tail = fread( $fh, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! is_string( $tail ) || strlen( $tail ) !== 4 ) {
			return 0;
		}
		$raw = (int) ( unpack( 'V', $tail )[1] ?? 0 );
		return $raw > 0 ? $raw : 0;
	}

	/**
	 * Uncompressed payload size for a finished backup archive (0 while still being written).
	 *
	 * @param string $path   Path.
	 * @param string $ext    Ext.
	 * @param int    $packed Packed.
	 *
	 * @return int
	 */
	private static function archive_raw_bytes( string $path, string $ext, int $packed ): int {
		if ( 'sql' === $ext ) {
			return $packed;
		}
		if ( $packed < 64 ) {
			return 0;
		}
		$raw = 0;
		// Read the uncompressed size from the gzip footer.
		if ( 'sql.gz' === $ext ) {
			$raw = self::gzip_isize( $path );
		}
		// Reject header-misreads and unfinished streams: SQL dumps must expand past the archive.
		if ( $raw > 0 && in_array( $ext, array( 'sql.gz', 'sql.7z' ), true ) && $raw <= $packed ) {
			return 0;
		}
		return $raw > 0 ? $raw : 0;
	}

	/**
	 * Raw bytes for.
	 *
	 * @param string $name   Name.
	 * @param int    $packed Packed.
	 *
	 * @return int
	 */
	private static function raw_bytes_for( string $name, int $packed ): int {
		$path = self::path( $name );
		$ext  = self::file_ext( $name );
		// Always measure the file on disk so mid-backup polls cannot poison meta forever.
		$raw = self::archive_raw_bytes( $path, $ext, $packed );
		if ( $raw > 0 ) {
			$meta = self::read_meta( $name );
			if ( (int) ( $meta['raw_bytes'] ?? 0 ) !== $raw || (int) ( $meta['packed_bytes'] ?? 0 ) !== $packed ) {
				self::write_meta( $name, $raw, $packed );
			}
			return $raw;
		}
		$meta        = self::read_meta( $name );
		$meta_raw    = (int) ( $meta['raw_bytes'] ?? 0 );
		$meta_packed = (int) ( $meta['packed_bytes'] ?? 0 );
		if ( $meta_raw > 0 && $meta_packed === $packed && ( 'sql' === $ext || $meta_raw > $packed ) ) {
			return $meta_raw;
		}
		return 0;
	}


	/**
	 * List backups.
	 *
	 * @return array
	 */
	public static function list_backups(): array {
		$dir = self::dir();
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		// A ZIP can finish earlier in this request; do not reuse a cached file stat.
		clearstatcache( true );
		self::prune_orphan_sidecars();
		self::sweep_temp_files();
		$entries = ( is_dir( $dir ) ? scandir( $dir ) : false );
		if ( ! is_array( $entries ) ) {
			return $out;
		}
		$job      = self::job();
		$hide     = '';
		$hide_raw = '';
		if ( in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'paused' ), true ) ) {
			$hide = basename( (string) ( $job['file'] ?? '' ) );
			$raw  = (string) ( $job['raw_path'] ?? '' );
			if ( $raw && preg_match( '/\.sql$/i', $raw ) ) {
				$hide_raw = basename( $raw );
			}
		}
		$deadline = microtime( true ) + 8;
		$override = self::allow_unsigned_restore();
		foreach ( $entries as $name ) {
			if ( ! self::safe_name( $name ) || ( $hide && $name === $hide ) || ( $hide_raw && $name === $hide_raw ) ) {
				continue;
			}
			$file = $dir . '/' . $name;
			if ( ! is_file( $file ) ) {
				continue;
			}
			$kind   = str_starts_with( $name, 'db-' ) ? 'db' : ( str_starts_with( $name, 'site-' ) ? 'site' : 'uploads' );
			$packed = (int) filesize( $file );
			$raw    = self::raw_bytes_for( $name, $packed );
			$sig    = 'db' === $kind && in_array( self::file_ext( $name ), array( 'sql', 'sql.gz' ), true ) ? self::signature_state( $name, $file, $deadline ) : '';
			$out[]  = array(
				// Display only: 'signed', 'unsigned' or 'unchecked' for database dumps. Restore always verifies the file again.
				'signature'  => $sig,
				'restorable' => 'unsigned' !== $sig || $override,
				'file'       => $name,
				'kind'       => $kind,
				'ext'        => self::file_ext( $name ),
				'bytes'      => $packed,
				'raw_bytes'  => $raw,
				'size'       => LucidLab_MPC_Helpers::bytes( $packed ),
				'raw_size'   => $raw ? LucidLab_MPC_Helpers::bytes( $raw ) : '',
				'detail'     => self::size_detail( $kind, $raw, $packed ),
				'modified'   => (int) filemtime( $file ),
				'when'       => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) filemtime( $file ) ),
				// Keep a raw URL; the template escapes it once for HTML.
				'download'   => add_query_arg( '_wpnonce', wp_create_nonce( 'lucidlab_mpc_backup_download' ), admin_url( 'admin-post.php?action=lucidlab_mpc_backup_download&file=' . rawurlencode( $name ) ) ),
			);
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return (int) $b['modified'] <=> (int) $a['modified'];
			}
		);
		return $out;
	}

	/**
	 * Job.
	 *
	 * @return array
	 */
	public static function job(): array {
		$j        = get_option( self::JOB_OPTION );
		$defaults = array(
			'status'   => 'idle',
			'driver'   => 'ajax',
			'kind'     => '',
			'file'     => '',
			'total'    => 0,
			'done'     => 0,
			'label'    => '',
			'updated'  => 0,
			'progress' => 0,
		);
		return is_array( $j ) ? array_merge( $defaults, $j ) : $defaults;
	}

	/**
	 * Save.
	 *
	 * @param array $j J.
	 */
	private static function save( array $j ): void {
		$previous = get_option( self::JOB_OPTION, array() );
		wp_cache_delete( self::CANCEL_OPTION, 'options' );
		if ( self::cancel_requested() && ( $j['status'] ?? '' ) !== 'cancelled' ) {
			$j = self::apply_cancel( $j );
		}
		$j['updated'] = time();
		update_option( self::JOB_OPTION, $j, false );
		wp_cache_delete( self::CANCEL_OPTION, 'options' );
		if ( self::cancel_requested() && ( $j['status'] ?? '' ) !== 'cancelled' ) {
			$j            = self::apply_cancel( $j );
			$j['updated'] = time();
			update_option( self::JOB_OPTION, $j, false );
		}
		$status = (string) ( $j['status'] ?? '' );
		if ( in_array( $status, array( 'done', 'failed', 'cancelled' ), true ) && (string) ( $previous['status'] ?? '' ) !== $status ) {
			self::remember_history(
				(string) ( $j['label'] ?? __( 'Backup', 'lucidlab-mpc-cleanup' ) ),
				$status,
				'failed' === $status ? (string) ( $j['label'] ?? '' ) : '',
				(int) ( $j['updated'] ?? time() )
			);
		}
	}

	/**
	 * Remember history.
	 *
	 * @param string $label   Label.
	 * @param string $status  Status.
	 * @param string $error   Error.
	 * @param int    $updated Updated.
	 */
	private static function remember_history( string $label, string $status, string $error = '', int $updated = 0 ): void {
		LucidLab_MPC_Helpers::prepend_job_history(
			array(
				'key'        => 'lucidlab_mpc_backup_job',
				'label'      => $label,
				'status'     => $status,
				'error'      => $error,
				'updated'    => $updated > 0 ? $updated : time(),
				'failed'     => 'failed' === $status ? 1 : 0,
				'failed_ids' => array(),
				'panel'      => 'backup',
				'scan_tab'   => 'backup',
				'can_retry'  => false,
				'special'    => 'backup',
			)
		);
		if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
			LucidLab_MPC_Logger::log(
				sprintf(
					/* translators: 1: backup action, 2: status */
					__( 'Backup: %1$s (%2$s).', 'lucidlab-mpc-cleanup' ),
					$label,
					$status
				),
				'failed' === $status ? 'error' : 'info'
			);
		}
	}

	/**
	 * Cancel requested.
	 *
	 * @return bool
	 */
	public static function cancel_requested(): bool {
		return (int) get_option( self::CANCEL_OPTION, 0 ) > 0;
	}

	/**
	 * Clear cancel.
	 */
	private static function clear_cancel(): void {
		delete_option( self::CANCEL_OPTION );
		delete_option( self::PID_OPTION );
	}

	/**
	 * Apply cancel.
	 *
	 * @param array $j J.
	 *
	 * @return array
	 */
	private static function apply_cancel( array $j ): array {
		if ( ( $j['status'] ?? '' ) !== 'cancelled' ) {
			self::cleanup_partial( $j );
		}
		$j['status'] = 'cancelled';
		$j['label']  = __( 'Cancelled.', 'lucidlab-mpc-cleanup' );
		return $j;
	}

	/**
	 * Request cancel.
	 */
	public static function request_cancel(): void {
		update_option( self::CANCEL_OPTION, time(), false );
		wp_cache_delete( self::JOB_OPTION, 'options' );
		$j = self::job();
		if ( in_array( $j['status'] ?? '', array( 'running', 'paused' ), true ) ) {
			$j            = self::apply_cancel( $j );
			$j['updated'] = time();
			update_option( self::JOB_OPTION, $j, false );
		}
	}




	/**
	 * Public job.
	 *
	 * @param array $j J.
	 *
	 * @return array
	 */
	public static function public_job( array $j = array() ): array {
		$j        = ( $j ? $j : self::job() );
		$total    = (int) ( $j['total'] ?? 0 );
		$done_raw = (float) ( $j['done'] ?? 0 );
		$done     = (int) $done_raw;
		$progress = $total > 0 ? min( 100, (int) ( 100 * $done_raw / $total ) ) : (int) ( $j['progress'] ?? 0 );
		$running  = in_array( (string) ( $j['status'] ?? '' ), array( 'running', 'paused' ), true );
		$blocking = in_array( (string) ( $j['phase'] ?? '' ), array( 'compress', 'sql', 'inflate' ), true );
		$kind     = (string) ( $j['kind'] ?? '' );
		$label    = (string) ( $j['label'] ?? '' );
		// Never surface a leftover restore banner while a backup is in progress.
		if ( $running && in_array( $kind, array( 'db', 'uploads', 'site' ), true ) && preg_match( '/restore finished/i', $label ) ) {
			$label = __( 'Working on backup…', 'lucidlab-mpc-cleanup' );
		}
		return array(
			'status'        => $j['status'] ?? 'idle',
			'driver'        => $j['driver'] ?? 'ajax',
			'kind'          => $kind,
			'file'          => $j['file'] ?? '',
			'total'         => $total,
			'done'          => $done,
			'label'         => $label,
			'progress'      => $progress,
			'indeterminate' => $running && ( $blocking || $progress < 1 ),
			'updated'       => (int) ( $j['updated'] ?? 0 ),
			'started'       => (int) ( $j['started'] ?? 0 ),
			'now'           => time(),
			'elapsed'       => ( $running && ! empty( $j['started'] ) ) ? max( 0, time() - (int) $j['started'] ) : null,
			'backups'       => self::list_backups(),
			'folder'        => self::dir(),
			'engine'        => self::engine(),
		);
	}

	/**
	 * Command.
	 *
	 * @param string $action Action.
	 * @param array  $input  Input.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public static function command( string $action, array $input = array() ): array {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new RuntimeException( __( 'Permission denied', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( 'cancel' === $action ) {
			self::request_cancel();
		}
		if ( 'verify' === $action ) {
			return self::precheck_restore( (string) ( $input['kind'] ?? '' ), (string) ( $input['file'] ?? '' ) );
		}
		$lock = 'lucidlab_mpc_backup_' . md5( $wpdb->prefix );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			$pub = self::public_job();
			if ( 'cancel' === $action ) {
				$pub['status'] = 'cancelled';
				$pub['label']  = __( 'Cancelled.', 'lucidlab-mpc-cleanup' );
				return $pub;
			}
			return array_merge( $pub, array( 'busy' => true ) );
		}
		try {
			wp_cache_delete( self::JOB_OPTION, 'options' );
			$j = self::job();
			if ( self::cancel_requested() && in_array( $j['status'] ?? '', array( 'running', 'paused' ), true ) ) {
				$j = self::apply_cancel( $j );
				self::save( $j );
			}
			if ( 'list' === $action || 'status' === $action ) {
				return self::public_job( $j );
			}
			if ( 'delete' === $action ) {
				$name    = (string) ( $input['file'] ?? '' );
				$safe    = self::safe_name( $name );
				$path    = $safe ? self::dir() . '/' . $safe : '';
				$existed = $path && is_file( $path );
				if ( ! $existed ) {
					throw new RuntimeException( __( 'Backup file not found.', 'lucidlab-mpc-cleanup' ) );
				}
				self::delete_file( $name );
				if ( is_file( $path ) ) {
					throw new RuntimeException( __( 'Could not delete the file.', 'lucidlab-mpc-cleanup' ) );
				}
				if ( $existed ) {
					// The completed-job banner must not advertise a backup that no longer exists.
					if ( basename( (string) ( $j['file'] ?? '' ) ) === $safe && in_array( (string) ( $j['status'] ?? '' ), array( 'done', 'failed', 'cancelled' ), true ) ) {
						$j['status'] = 'idle';
						$j['label']  = '';
						$j['file']   = '';
						update_option( self::JOB_OPTION, $j, false );
					}
					self::remember_history(
						sprintf( /* translators: %s: backup filename */ __( 'Deleted %s', 'lucidlab-mpc-cleanup' ), $safe ),
						'done'
					);
				}
				$public = self::public_job();
				if ( $existed ) {
					$public['notice'] = sprintf(
						/* translators: %s: backup filename */
						__( 'Deleted %s', 'lucidlab-mpc-cleanup' ),
						$safe
					);
				}
				return $public;
			}
			if ( 'start' === $action ) {
				if ( in_array( $j['status'] ?? '', array( 'running', 'paused' ), true ) ) {
					throw new RuntimeException( __( 'A backup or restore job is already running. Finish or cancel it first.', 'lucidlab-mpc-cleanup' ) );
				}
				if ( class_exists( 'LucidLab_MPC_Plugin', false ) ) {
					LucidLab_MPC_Plugin::assert_job_slot( 'backup' );
				}
				self::clear_cancel();
				$j = self::start( (string) ( $input['kind'] ?? '' ), (string) ( $input['file'] ?? '' ) );
				return self::public_job( $j );
			}
			if ( 'pause' === $action && 'running' === ( $j['status'] ?? '' ) ) {
				$j['status'] = 'paused';
			} elseif ( 'cancel' === $action ) {
				$j = self::apply_cancel( $j );
			} elseif ( in_array( $action, array( 'resume', 'bg' ), true ) && in_array( $j['status'] ?? '', array( 'running', 'paused' ), true ) ) {
				self::clear_cancel();
				$j['status'] = 'running';
				$j['driver'] = 'bg' === $action ? 'cron' : 'ajax';
			} elseif ( ( ( 'step' === $action && 'ajax' === ( $j['driver'] ?? '' ) ) || ( 'cron' === $action && 'cron' === ( $j['driver'] ?? '' ) ) ) && 'running' === ( $j['status'] ?? '' ) ) {
				if ( self::cancel_requested() ) {
					$j = self::apply_cancel( $j );
				} else {
					try {
						$j = self::step( $j );
					} catch ( Throwable $e ) {
						if ( ! self::cancel_requested() && in_array( (string) ( $j['kind'] ?? '' ), array( 'db', 'uploads', 'site' ), true ) ) {
							self::cleanup_partial( $j );
						}
						$j = self::cancel_requested()
							? self::apply_cancel( $j )
							: array_merge(
								$j,
								array(
									'status' => 'failed',
									'label'  => $e->getMessage(),
								)
							);
					}
				}
			}
			self::save( $j );
			if ( ( $j['status'] ?? '' ) === 'running' && ( $j['driver'] ?? '' ) === 'cron' ) {
				if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_schedule_single_event( time() + 2, self::CRON_HOOK );
				}
			} else {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return self::public_job( $j );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		}
	}

	/**
	 * Pre-check for the Restore button: refuses a database backup that is not signed by this site
	 * before the RESTORE confirmation is shown. Read-only; the restore itself checks again.
	 *
	 * @param string $kind 'restore_db' or 'restore_uploads'.
	 * @param string $file Backup file name.
	 *
	 * @throws RuntimeException When the file is missing or not signed by this site.
	 * @return array
	 */
	private static function precheck_restore( string $kind, string $file ): array {
		$source = self::path( $file );
		if ( ! $source || ! is_file( $source ) ) {
			throw new RuntimeException( __( 'That backup file was not found.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( 'restore_db' === $kind ) {
			if ( ! str_starts_with( basename( $source ), 'db-' ) ) {
				throw new RuntimeException( __( 'The selected file does not match this restore type.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			// An unchanged file that already passed the full check skips the slow decompress-and-HMAC here.
			// The restore job still runs the full check on the server before anything is imported.
			$cached = ! self::allow_unsigned_restore() && self::verified_before( $source );
			if ( ! $cached ) {
				self::assert_restorable_backup( $source );
			}
			return array(
				'ok'     => true,
				'file'   => basename( $source ),
				'cached' => $cached,
			);
		}
		return array(
			'ok'   => true,
			'file' => basename( $source ),
		);
	}

	/**
	 * Cron tick.
	 */
	public static function cron_tick(): void {
		$j = self::command( 'cron' );
		if ( ( $j['status'] ?? '' ) === 'running' && ( $j['driver'] ?? '' ) === 'cron' && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 2, self::CRON_HOOK );
		}
	}

	/**
	 * Import upload.
	 *
	 * @param string $kind Kind.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @throws Throwable When a plain .sql upload cannot be checked or compressed (the upload is deleted first).
	 * @return array
	 */
	public static function import_upload( string $kind ): array {
		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by the AJAX/admin-post router before this handler runs.
			throw new RuntimeException( __( 'Choose a backup file to import.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$file = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Upload array: name is sanitized below and tmp_name is passed to wp_handle_upload(); Nonce is verified by the AJAX/admin-post router before this handler runs.
		if ( ! empty( $file['error'] ) ) {
			throw new RuntimeException( __( 'The upload failed. Check PHP upload limits.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$kind = 'uploads' === $kind ? 'uploads' : ( 'site' === $kind ? 'site' : 'db' );
		$orig = isset( $file['name'] ) && is_string( $file['name'] )
			? strtolower( sanitize_file_name( wp_unslash( $file['name'] ) ) )
			: '';
		// sanitize_file_name() turns "name.sql.gz" into "name.sql_.gz" because .sql is not an allowed upload type.
		$orig = (string) preg_replace( '/\.sql_\.gz$/', '.sql.gz', $orig );
		$ext  = '';
		if ( 'db' === $kind ) {
			foreach ( array( 'sql.gz', 'sql' ) as $try ) {
				if ( str_ends_with( $orig, '.' . $try ) ) {
					$ext = $try;
					break;
				}
			}
			if ( ! $ext ) {
				throw new RuntimeException( __( 'Import a .sql or .sql.gz database backup.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
		} else {
			foreach ( array( 'zip' ) as $try ) {
				if ( str_ends_with( $orig, '.' . $try ) ) {
					$ext = $try;
					break;
				}
			}
			if ( ! $ext ) {
				throw new RuntimeException( 'site' === $kind ? __( 'Import a .zip full-site backup.', 'lucidlab-mpc-cleanup' ) : __( 'Import a .zip uploads backup.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
		}
		if ( 'db' === $kind && 'sql' === $ext && ! function_exists( 'gzopen' ) ) {
			throw new RuntimeException( __( 'Database backups need the PHP zlib extension (gzip).', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$dir  = self::protect();
		$name = $kind . '-' . wp_date( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) ) . '.' . $ext;
		// A plain .sql upload is kept under a temporary name until it is checked and compressed.
		$plain = 'db' === $kind && 'sql' === $ext;
		$name  = $plain ? $name . self::PART_SUFFIX : $name;
		$dest  = $dir . '/' . $name;
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once LucidLab_MPC_Helpers::admin_dir() . 'includes/file.php';
		}
		// Route this upload directly into the protected backup directory while retaining
		// WordPress's upload checks. The filter exists only for this synchronous call.
		$backup_upload_dir = static function ( array $uploads ) use ( $dir ): array {
			$uploads['path']    = $dir;
			$uploads['basedir'] = $dir;
			$uploads['subdir']  = '';
			$uploads['url']     = '';
			$uploads['baseurl'] = '';
			$uploads['error']   = false;
			return $uploads;
		};
		add_filter( 'upload_dir', $backup_upload_dir, PHP_INT_MAX );
		try {
			$stored = wp_handle_upload(
				$file,
				array(
					'test_form'                => false,
					// The extension was restricted to the backup formats above.
					'test_type'                => false,
					'unique_filename_callback' => static fn() => $name,
				)
			);
		} finally {
			remove_filter( 'upload_dir', $backup_upload_dir, PHP_INT_MAX );
		}
		if ( ! is_array( $stored ) || isset( $stored['error'] ) || (string) ( $stored['file'] ?? '' ) !== $dest || ! is_file( $dest ) ) {
			throw new RuntimeException( __( 'Could not store the imported backup.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( $plain ) {
			$final = substr( $dest, 0, -strlen( self::PART_SUFFIX ) ) . '.gz';
			try {
				if ( ! self::allow_unsigned_restore() && ! self::sql_signature_valid( $dest ) ) {
					throw new RuntimeException( self::unsigned_message( 'import' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				}
				$raw_bytes = (int) filesize( $dest );
				self::compress_sql( $dest, $final );
			} catch ( Throwable $e ) {
				foreach ( array( $dest, $final ) as $leftover ) {
					if ( is_file( $leftover ) ) {
						wp_delete_file( $leftover );
					}
				}
				throw $e;
			} finally {
				if ( is_file( $dest ) ) {
					wp_delete_file( $dest );
				}
			}
			clearstatcache( true, $final );
			self::write_meta( basename( $final ), $raw_bytes, (int) filesize( $final ) );
			if ( ! self::allow_unsigned_restore() ) {
				self::remember_verified( $final, true );
			}
			$dest = $final;
		}
		if ( 'db' === $kind ) {
			try {
				self::assert_restorable_backup( $dest, 'import' );
			} catch ( RuntimeException $e ) {
				wp_delete_file( $dest );
				throw $e;
			}
		}
		self::prune( $kind );
		return self::public_job();
	}

	/**
	 * Start.
	 *
	 * @param string $kind Kind.
	 * @param string $file File.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	private static function start( string $kind, string $file = '' ): array {
		$dir = self::protect();
		if ( 'restore_site' === $kind ) {
			throw new RuntimeException( __( 'Full-site restore requires your hosting provider or WordPress recovery tools. Download the archive before restoring.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( in_array( $kind, array( 'restore_db', 'restore_uploads' ), true ) ) {
			$source = self::path( $file );
			if ( ! $source || ! is_file( $source ) ) {
				throw new RuntimeException( __( 'That backup file was not found.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$expect = 'restore_db' === $kind ? 'db-' : 'uploads-';
			if ( ! str_starts_with( basename( $source ), $expect ) ) {
				throw new RuntimeException( __( 'The selected file does not match this restore type.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$ext = self::file_ext( $source );
			if ( in_array( $ext, array( '7z', 'sql.7z' ), true ) ) {
				throw new RuntimeException( __( '7-Zip backups are no longer supported. Restore this archive with your hosting tools.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			if ( 'restore_db' === $kind && 'sql.gz' === $ext && ! function_exists( 'gzopen' ) ) {
				throw new RuntimeException( __( 'Restoring a .sql.gz database backup needs PHP gzip support.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			// Verify the signature before anything else happens: no job, no decompression, no SQL.
			// .sql.gz is checked again after it is decompressed, and every SQL file again before its first statement.
			if ( 'restore_db' === $kind ) {
				self::assert_restorable_backup( $source );
			}
			$j = array(
				'status'  => 'running',
				'driver'  => 'ajax',
				'kind'    => $kind,
				'file'    => basename( $source ),
				'path'    => $source,
				'phase'   => 'restore_db' === $kind ? ( 'sql.gz' === $ext ? 'inflate' : 'sql' ) : 'extract',
				'total'   => max( 1, (int) filesize( $source ) ),
				'done'    => 0,
				'offset'  => 0,
				'buffer'  => '',
				'label'   => 'restore_db' === $kind ? __( 'Preparing database restore…', 'lucidlab-mpc-cleanup' ) : __( 'Preparing uploads restore…', 'lucidlab-mpc-cleanup' ),
				'started' => time(),
			);
			self::save( $j );
			return $j;
		}
		if ( ! in_array( $kind, array( 'db', 'uploads', 'site' ), true ) ) {
			throw new RuntimeException( __( 'Choose Backup Database, Backup uploads or Backup full site.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$engine = self::engine();
		if ( ( 'db' === $kind || 'site' === $kind ) && ! $engine['gzip'] ) {
			throw new RuntimeException( __( 'Database backups need the PHP zlib extension (gzip).', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( 'db' !== $kind && ! $engine['zip'] ) {
			throw new RuntimeException( __( 'File backups need the PHP ZipArchive extension.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$ext  = 'db' === $kind ? $engine['db_ext'] : ( 'site' === $kind ? $engine['site_ext'] : $engine['uploads_ext'] );
		$name = $kind . '-' . wp_date( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) ) . '.' . $ext;
		$path = $dir . '/' . $name;
		$j    = array(
			'status'  => 'running',
			'driver'  => 'ajax',
			'kind'    => $kind,
			'file'    => $name,
			'path'    => $path,
			'total'   => 1,
			'done'    => 0,
			'started' => time(),
		);
		if ( 'db' === $kind || 'site' === $kind ) {
			// Create the signing key before the options table is dumped, so a restored site keeps the same key.
			self::sign_key();
			// The dump is written straight into gzip (one member per request) under a name that is never listed or served.
			$j['raw_path']    = 'site' === $kind ? $dir . '/site-' . wp_date( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) ) . '.sql.gz' . self::PART_SUFFIX : $path . self::PART_SUFFIX;
			$j['tables']      = array();
			$j['table_index'] = 0;
			$j['row_offset']  = 0;
			$j['phase']       = 'tables';
			$j['label']       = __( 'Starting database backup…', 'lucidlab-mpc-cleanup' );
			$j['sql_bytes']   = self::dump_append( $j['raw_path'], "-- Media & Products Cleanup database backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n-- MPC\n" );
		} else {
			$j['phase']     = 'list';
			$j['label']     = __( 'Listing files in uploads…', 'lucidlab-mpc-cleanup' );
			$j['stack']     = array( rtrim( str_replace( '\\', '/', LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) );
			$j['list_file'] = $dir . '/' . $name . '.lst';
			$j['zip_index'] = 0;
			file_put_contents( $j['list_file'], '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		self::save( $j );
		return $j;
	}

	/**
	 * Step.
	 *
	 * @param array $j J.
	 *
	 * @return array
	 */
	private static function step( array $j ): array {
		$kind = (string) ( $j['kind'] ?? '' );
		if ( 'db' === $kind ) {
			return self::step_db( $j );
		}
		if ( 'site' === $kind ) {
			return self::step_site( $j );
		}
		if ( 'uploads' === $kind ) {
			return self::step_uploads( $j );
		}
		if ( 'restore_db' === $kind ) {
			return self::step_restore_db( $j );
		}
		if ( 'restore_uploads' === $kind ) {
			return self::step_restore_uploads( $j );
		}
		$j['status'] = 'failed';
		$j['label']  = __( 'Unknown backup job.', 'lucidlab-mpc-cleanup' );
		return $j;
	}

	/**
	 * Step db.
	 *
	 * @param array $j J.
	 *
	 * @return array
	 */
	private static function step_db( array $j ): array {
		global $wpdb;
		$raw = (string) ( $j['raw_path'] ?? $j['path'] ?? '' );
		if ( ( $j['phase'] ?? '' ) === 'compress' ) {
			return self::finish_db_file( $j );
		}
		if ( ( $j['phase'] ?? '' ) === 'tables' ) {
			$tables           = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			$j['tables']      = is_array( $tables ) ? array_values( array_filter( array_map( 'strval', $tables ) ) ) : array();
			$j['total']       = max( 1, count( $j['tables'] ) );
			$j['phase']       = 'dump';
			$j['table_index'] = 0;
			$j['row_offset']  = 0;
			$j['label']       = sprintf( /* translators: %s: number of database tables. */ __( 'Dumping %s database tables…', 'lucidlab-mpc-cleanup' ), number_format_i18n( count( $j['tables'] ) ) );
			return $j;
		}
		$tables = (array) ( $j['tables'] ?? array() );
		$index  = (int) ( $j['table_index'] ?? 0 );
		if ( $index >= count( $tables ) ) {
			$j['sql_bytes'] = (int) ( $j['sql_bytes'] ?? 0 ) + self::dump_append( $raw, "SET FOREIGN_KEY_CHECKS=1;\n-- MPC\n" );
			$bytes          = str_ends_with( $raw, self::PART_SUFFIX ) ? (int) $j['sql_bytes'] : ( is_file( $raw ) ? (int) filesize( $raw ) : 0 );
			$j['raw_bytes'] = $bytes;
			$j['phase']     = 'compress';
			$j['label']     = $bytes
				? ( ( $j['kind'] ?? '' ) === 'site'
					? sprintf( /* translators: %s: size of the SQL dump. */ __( 'Dumped %s of SQL. Preparing the full-site archive…', 'lucidlab-mpc-cleanup' ), LucidLab_MPC_Helpers::bytes( $bytes ) )
					: sprintf( /* translators: %s: size of the SQL dump. */ __( 'Dumped %s of SQL. Compressing…', 'lucidlab-mpc-cleanup' ), LucidLab_MPC_Helpers::bytes( $bytes ) ) )
				: ( ( $j['kind'] ?? '' ) === 'site' ? __( 'Preparing the full-site archive…', 'lucidlab-mpc-cleanup' ) : __( 'Compressing the SQL dump…', 'lucidlab-mpc-cleanup' ) );
			return $j;
		}
		$table  = $tables[ $index ];
		$offset = (int) ( $j['row_offset'] ?? 0 );
		if ( 0 === $offset && empty( $j['row_key'] ) ) {
			$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			$sql    = 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . "`;\n-- MPC\n";
			if ( is_array( $create ) && ! empty( $create[1] ) ) {
				$sql .= $create[1] . ";\n-- MPC\n";
			}
			$j['sql_bytes']  = (int) ( $j['sql_bytes'] ?? 0 ) + self::dump_append( $raw, $sql );
			$j['pk']         = self::integer_primary_key( $table );
			$j['row_key']    = 0;
			$j['table_rows'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		}
		// Dump for a few seconds per request in large pages. Tables with an integer primary key are read by key range, so late pages cost the same as early ones.
		$deadline = microtime( true ) + self::DUMP_BUDGET;
		$pk       = (string) ( $j['pk'] ?? '' );
		$finished = false;
		do {
			if ( '' !== $pk ) {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %d ORDER BY %i ASC LIMIT %d', $table, $pk, (int) $j['row_key'], $pk, self::DUMP_PAGE ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			} else {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table, self::DUMP_PAGE, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			}
			if ( ! is_array( $rows ) || ! $rows ) {
				$finished = true;
				break;
			}
			$chunk = '';
			$cols  = null;
			foreach ( $rows as $row ) {
				if ( null === $cols ) {
					$cols = implode( ',', array_map( static fn( $col ) => '`' . str_replace( '`', '``', (string) $col ) . '`', array_keys( $row ) ) );
				}
				$vals = array();
				foreach ( $row as $value ) {
					$vals[] = null === $value ? 'NULL' : "'" . esc_sql( (string) $value ) . "'";
				}
				$chunk .= 'INSERT INTO `' . str_replace( '`', '``', $table ) . '` (' . $cols . ') VALUES (' . implode( ',', $vals ) . ");\n-- MPC\n";
			}
			$j['sql_bytes'] = (int) ( $j['sql_bytes'] ?? 0 ) + self::dump_append( $raw, $chunk );
			$offset        += count( $rows );
			if ( '' !== $pk ) {
				$last         = end( $rows );
				$j['row_key'] = (int) ( $last[ $pk ] ?? $j['row_key'] );
			}
			if ( count( $rows ) < self::DUMP_PAGE ) {
				$finished = true;
				break;
			}
		} while ( microtime( true ) < $deadline );
		if ( $finished ) {
			$j['table_index'] = $index + 1;
			$j['row_offset']  = 0;
			$j['row_key']     = 0;
			$j['pk']          = '';
			$j['done']        = $index + 1;
			$j['label']       = sprintf( /* translators: 1: tables dumped so far, 2: total tables. */ __( 'Dumped %1$s / %2$s tables', 'lucidlab-mpc-cleanup' ), number_format_i18n( $index + 1 ), number_format_i18n( count( $tables ) ) );
			return $j;
		}
		$j['row_offset'] = $offset;
		$estimate        = (int) ( $j['table_rows'] ?? 0 );
		$share           = $estimate > 0 ? ' — ' . min( 99, (int) floor( 100 * $offset / $estimate ) ) . '%' : '';
		$j['done']       = $index + ( $estimate > 0 ? min( 0.99, $offset / $estimate ) : 0 );
		$j['label']      = sprintf( /* translators: 1: table name, 2: rows dumped so far, 3: optional share of the table, such as ", 40%". */ __( 'Dumping %1$s (%2$s rows%3$s)…', 'lucidlab-mpc-cleanup' ), $table, number_format_i18n( $offset ), $share );
		return $j;
	}

	/** Seconds of dumping per request. */
	const DUMP_BUDGET = 8.0;

	/** Rows per dump query. */
	const DUMP_PAGE = 2000;

	/**
	 * Single-column integer primary key of a table, or an empty string.
	 *
	 * @param string $table Table.
	 *
	 * @return string
	 */
	private static function integer_primary_key( string $table ): string {
		global $wpdb;
		$keys = $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		if ( ! is_array( $keys ) || 1 !== count( $keys ) ) {
			return '';
		}
		$column = (string) ( $keys[0]['Column_name'] ?? '' );
		$cols   = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $table, $column ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$type   = is_array( $cols ) && $cols ? strtolower( (string) ( $cols[0]['Type'] ?? '' ) ) : '';
		return preg_match( '/^(tiny|small|medium|big)?int\b/', $type ) ? $column : '';
	}

	/**
	 * Finish db file.
	 *
	 * @param array $j J.
	 *
	 * @return array
	 */
	private static function finish_db_file( array $j ): array {
		if ( ( $j['kind'] ?? '' ) === 'site' ) {
			return self::after_sql_ready( $j );
		}
		$raw       = (string) ( $j['raw_path'] ?? $j['path'] ?? '' );
		$path      = (string) ( $j['path'] ?? '' );
		$file      = (string) ( $j['file'] ?? '' );
		$raw_bytes = 0;
		if ( $raw && is_file( $raw ) && str_ends_with( $raw, self::PART_SUFFIX ) ) {
			// Sign the finished dump so only backups made here can be restored here, then publish it under its real name.
			$raw_bytes = self::seal_dump( $raw, $path );
		} elseif ( $raw && is_file( $raw ) ) {
			// A dump started by an older version as plain .sql: sign, compress, delete the .sql.
			self::sign_sql_file( $raw );
		}
		if ( $raw && is_file( $raw ) && $raw !== $path && ! str_ends_with( $raw, self::PART_SUFFIX ) ) {
			$raw_bytes = (int) filesize( $raw );
			self::compress_sql( $raw, $path );
			if ( is_file( $raw ) ) {
				wp_delete_file( $raw );
			}
		}
		$packed = ( $path && is_file( $path ) ) ? (int) filesize( $path ) : 0;
		$ext    = self::file_ext( '' !== $file ? $file : $path );
		// Always re-read the finished archive (ignore stale meta from mid-pipe polls).
		$from_archive = self::archive_raw_bytes( $path, $ext, $packed );
		if ( $from_archive > $raw_bytes ) {
			$raw_bytes = $from_archive;
		} elseif ( $raw_bytes <= 0 && 'sql' === $ext ) {
			$raw_bytes = $packed;
		}
		if ( '' !== $file && $raw_bytes > 0 ) {
			self::write_meta( $file, $raw_bytes, $packed );
		}
		if ( '' !== $file && $packed > 0 ) {
			self::remember_signature( $file, $path, true );
			// Signed here a moment ago: the Restore pre-check can use this result for the unchanged file.
			self::remember_verified( $path, true );
		}
		$j['status']    = 'done';
		$j['done']      = max( 1, (int) ( $j['total'] ?? 1 ) );
		$j['raw_bytes'] = $raw_bytes;
		$j['label']     = $raw_bytes
			? sprintf( /* translators: 1: uncompressed SQL size, 2: backup file name, 3: archive size. */ __( 'Database backup ready: %1$s SQL in %2$s (%3$s archive)', 'lucidlab-mpc-cleanup' ), LucidLab_MPC_Helpers::bytes( $raw_bytes ), $j['file'], LucidLab_MPC_Helpers::bytes( $packed ) )
			: sprintf( /* translators: %s: backup file name. */ __( 'Database backup ready: %s', 'lucidlab-mpc-cleanup' ), $j['file'] );
		self::prune( 'db' );
		return $j;
	}

	/**
	 * Step uploads.
	 *
	 * @param array $j J.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	private static function step_uploads( array $j ): array {
		$uploads = rtrim( str_replace( '\\', '/', LucidLab_MPC_Helpers::uploads_basedir() ), '/' );
		if ( ( $j['phase'] ?? '' ) === 'list' ) {
			$stack  = (array) ( $j['stack'] ?? array() );
			$listed = 0;
			$handle = fopen( (string) $j['list_file'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			if ( ! $handle ) {
				throw new RuntimeException( __( 'Could not write the uploads file list.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			while ( $stack && $listed < 1500 ) {
				$dir     = (string) array_pop( $stack );
				$entries = ( is_dir( $dir ) ? scandir( $dir ) : false );
				if ( ! is_array( $entries ) ) {
					throw new RuntimeException( __( 'Uploads folder is not available.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				}
				foreach ( $entries as $name ) {
					if ( '.' === $name || '..' === $name ) {
						continue;
					}
					$path = $dir . '/' . $name;
					if ( is_dir( $path ) ) {
						// Never archive earlier backups into a new uploads backup.
						if ( str_replace( '\\', '/', $path ) === rtrim( str_replace( '\\', '/', self::dir() ), '/' ) ) {
							continue;
						}
						$stack[] = $path;
						continue;
					}
					if ( ! is_file( $path ) ) {
						continue;
					}
					$rel = ltrim( substr( str_replace( '\\', '/', $path ), strlen( $uploads ) ), '/' );
					if ( $rel ) {
						fwrite( $handle, $rel . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
						++$listed;
					}
				}
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			$j['stack'] = $stack;
			$j['done']  = (int) ( $j['done'] ?? 0 ) + $listed;
			$j['total'] = max( (int) $j['done'], 1 );
			$j['label'] = sprintf( /* translators: %s: number of files. */ __( 'Listed %s files in uploads…', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $j['done'] ) );
			if ( ! $stack ) {
				$j['phase'] = 'zip';
				$j['total'] = max( 1, (int) $j['done'] );
				$j['done']  = 0;
				$j['label'] = __( 'Storing uploads in a ZIP archive…', 'lucidlab-mpc-cleanup' );
				// ZipArchive removes an archive closed without entries. Create it with the first file below.
			}
			return $j;
		}
		$lines = ( is_readable( (string) $j['list_file'] ) ? file( (string) $j['list_file'], FILE_IGNORE_NEW_LINES ) : false );
		$lines = is_array( $lines ) ? $lines : array();
		$index = (int) ( $j['zip_index'] ?? 0 );
		if ( $index >= count( $lines ) ) {
			$path = (string) ( $j['path'] ?? '' );
			clearstatcache( true, $path );
			$check = new ZipArchive();
			if ( ! $path || ! is_file( $path ) ) {
				throw new RuntimeException( __( 'Could not create the uploads ZIP file.', 'lucidlab-mpc-cleanup' ) . ' [ZIP-MISSING]' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$open = $check->open( $path );
			if ( true !== $open ) {
				throw new RuntimeException( __( 'Could not create the uploads ZIP file.', 'lucidlab-mpc-cleanup' ) . ' [ZIP-STATUS:' . (int) $open . ']' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$stored = $check->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
			$check->close();
			if ( $stored < 1 || count( $lines ) !== $stored ) {
				throw new RuntimeException( __( 'Could not create the uploads ZIP file.', 'lucidlab-mpc-cleanup' ) . ' [ZIP-COUNT:' . $stored . '/' . count( $lines ) . ']' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			wp_delete_file( (string) $j['list_file'] );
			$j['status'] = 'done';
			$j['done']   = count( $lines );
			$j['label']  = sprintf( /* translators: %s: backup file name. */ __( 'Uploads backup ready: %s', 'lucidlab-mpc-cleanup' ), $j['file'] );
			self::prune( 'uploads' );
			return $j;
		}
		$zip   = new ZipArchive();
		$flags = 0 === $index ? ( ZipArchive::CREATE | ZipArchive::OVERWRITE ) : 0;
		$open  = $zip->open( (string) $j['path'], $flags );
		if ( true !== $open ) {
			throw new RuntimeException( __( 'Could not open the uploads ZIP file.', 'lucidlab-mpc-cleanup' ) . ' [ZIP-STATUS:' . (int) $open . ']' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$store = defined( 'ZipArchive::CM_STORE' ) ? ZipArchive::CM_STORE : 0;
		$chunk = array_slice( $lines, $index, 400 );
		foreach ( $chunk as $rel ) {
			$abs = $uploads . '/' . ltrim( str_replace( '\\', '/', $rel ), '/' );
			if ( ! is_file( $abs ) || ! $zip->addFile( $abs, $rel ) ) {
				$zip->close();
				throw new RuntimeException( __( 'Could not create the uploads ZIP file.', 'lucidlab-mpc-cleanup' ) . ' ' . $rel ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			if ( method_exists( $zip, 'setCompressionName' ) ) {
				$zip->setCompressionName( $rel, $store );
			}
		}
		if ( ! $zip->close() ) {
			throw new RuntimeException( __( 'Could not create the uploads ZIP file.', 'lucidlab-mpc-cleanup' ) . ' ' . (string) reset( $chunk ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$j['zip_index'] = $index + count( $chunk );
		$j['done']      = $j['zip_index'];
		$j['total']     = max( 1, count( $lines ) );
		$j['label']     = sprintf( /* translators: 1: files stored so far, 2: total files. */ __( 'Stored %1$s / %2$s files', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $j['done'] ), number_format_i18n( (int) $j['total'] ) );
		return $j;
	}

	/**
	 * After sql ready.
	 *
	 * @param array $j J.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	private static function after_sql_ready( array $j ): array {
		$raw = (string) ( $j['raw_path'] ?? '' );
		if ( ! $raw || ! is_file( $raw ) ) {
			throw new RuntimeException( __( 'The database dump for the full-site backup is missing.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( str_ends_with( $raw, self::PART_SUFFIX ) ) {
			// The compressed dump goes into the ZIP as is (__lucidlab_mpc_database.sql.gz); no SQL file is staged.
			$j['sql_gz']      = $raw;
			$j['sql_in_site'] = '';
			$j['raw_bytes']   = (int) ( $j['sql_bytes'] ?? 0 );
		}
		$staging = self::dir() . '/' . self::SQL_IN_SITE;
		if ( ! empty( $j['sql_gz'] ) ) {
			$staging = '';
		} elseif ( ! copy( $raw, $staging ) ) {
			throw new RuntimeException( __( 'Could not stage the SQL dump for the full-site archive.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( '' !== $staging ) {
			$j['sql_in_site'] = $staging;
			$j['raw_bytes']   = (int) filesize( $raw );
		}
		$j['status'] = 'running';
		$j['done']   = 0;
		$j['total']  = 1;
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( __( 'Full-site backups need the PHP ZipArchive extension.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$j['phase']     = 'list';
		$j['stack']     = array( self::site_root() );
		$j['list_file'] = (string) $j['path'] . '.lst';
		$j['zip_index'] = 0;
		file_put_contents( $j['list_file'], '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		$j['label'] = __( 'Listing files for the full-site backup…', 'lucidlab-mpc-cleanup' );
		return $j;
	}

	/**
	 * Step site.
	 *
	 * @param array $j J.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	private static function step_site( array $j ): array {
		$phase = (string) ( $j['phase'] ?? '' );
		if ( in_array( $phase, array( 'tables', 'dump', 'compress' ), true ) ) {
			return self::step_db( $j );
		}
		$root = self::site_root();
		if ( 'list' === $phase ) {
			$stack  = (array) ( $j['stack'] ?? array() );
			$listed = 0;
			$handle = fopen( (string) $j['list_file'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			if ( ! $handle ) {
				throw new RuntimeException( __( 'Could not write the full-site file list.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			while ( $stack && $listed < 1500 ) {
				$dir     = (string) array_pop( $stack );
				$entries = ( is_dir( $dir ) ? scandir( $dir ) : false );
				if ( ! is_array( $entries ) ) {
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
					throw new RuntimeException( __( 'Could not write the full-site file list.', 'lucidlab-mpc-cleanup' ) . ' ' . ltrim( substr( $dir, strlen( $root ) ), '/' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				}
				foreach ( $entries as $name ) {
					if ( '.' === $name || '..' === $name ) {
						continue;
					}
					$path = $dir . '/' . $name;
					if ( self::skip_site_path( $path ) ) {
						continue;
					}
					if ( is_dir( $path ) ) {
						$stack[] = $path;
						continue;
					}
					if ( ! is_file( $path ) ) {
						continue;
					}
					$rel = ltrim( substr( str_replace( '\\', '/', $path ), strlen( $root ) ), '/' );
					if ( $rel ) {
						fwrite( $handle, $rel . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
						++$listed;
					}
				}
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			$j['stack'] = $stack;
			$j['done']  = (int) ( $j['done'] ?? 0 ) + $listed;
			$j['total'] = max( (int) $j['done'], 1 );
			$j['label'] = sprintf( /* translators: %s: number of files. */ __( 'Listed %s files for the full-site backup…', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $j['done'] ) );
			if ( ! $stack ) {
				$j['phase'] = 'zip';
				$j['total'] = max( 1, (int) $j['done'] );
				$j['done']  = 0;
				$j['label'] = __( 'Storing the full site in a ZIP archive…', 'lucidlab-mpc-cleanup' );
				$zip        = new ZipArchive();
				if ( true !== $zip->open( (string) $j['path'], ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
					throw new RuntimeException( __( 'Could not create the full-site ZIP file.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				}
				$staging = ! empty( $j['sql_gz'] ) ? (string) $j['sql_gz'] : (string) ( $j['sql_in_site'] ?? ( self::dir() . '/' . self::SQL_IN_SITE ) );
				$entry   = ! empty( $j['sql_gz'] ) ? self::SQL_IN_SITE_GZ : self::SQL_IN_SITE;
				if ( is_file( $staging ) ) {
					if ( ! $zip->addFile( $staging, $entry ) ) {
						$zip->close();
						throw new RuntimeException( __( 'Could not stage the SQL dump for the full-site archive.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
					}
					if ( method_exists( $zip, 'setCompressionName' ) ) {
						$zip->setCompressionName( $entry, defined( 'ZipArchive::CM_STORE' ) ? ZipArchive::CM_STORE : 0 );
					}
				}
				foreach ( self::site_folders() as $folder ) {
					$absolute = $root . '/' . $folder;
					if ( is_dir( $absolute ) && ! self::skip_site_path( $absolute ) ) {
						$zip->addEmptyDir( $folder );
					}
				}
				if ( ! $zip->close() ) {
					throw new RuntimeException( __( 'Could not create the full-site ZIP file.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				}
			}
			return $j;
		}
		$lines = ( is_readable( (string) $j['list_file'] ) ? file( (string) $j['list_file'], FILE_IGNORE_NEW_LINES ) : false );
		$lines = is_array( $lines ) ? $lines : array();
		$index = (int) ( $j['zip_index'] ?? 0 );
		if ( $index >= count( $lines ) ) {
			return self::finish_site_file( $j );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( (string) $j['path'] ) ) {
			throw new RuntimeException( __( 'Could not open the full-site ZIP file.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$store = defined( 'ZipArchive::CM_STORE' ) ? ZipArchive::CM_STORE : 0;
		$chunk = array_slice( $lines, $index, 400 );
		foreach ( $chunk as $rel ) {
			$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
			if ( '' === $rel || self::SQL_IN_SITE === $rel || self::SQL_IN_SITE_GZ === $rel ) {
				continue;
			}
			$abs = $root . '/' . $rel;
			if ( ! is_file( $abs ) || self::skip_site_path( $abs ) ) {
				$zip->close();
				throw new RuntimeException( __( 'Could not create the full-site ZIP file.', 'lucidlab-mpc-cleanup' ) . ' ' . $rel ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			if ( ! $zip->addFile( $abs, $rel ) ) {
				$zip->close();
				throw new RuntimeException( __( 'Could not create the full-site ZIP file.', 'lucidlab-mpc-cleanup' ) . ' ' . $rel ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			if ( method_exists( $zip, 'setCompressionName' ) ) {
				$zip->setCompressionName( $rel, $store );
			}
		}
		if ( ! $zip->close() ) {
			throw new RuntimeException( __( 'Could not create the full-site ZIP file.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$j['zip_index'] = $index + count( $chunk );
		$j['done']      = $j['zip_index'];
		$j['total']     = max( 1, count( $lines ) );
		$j['label']     = sprintf( /* translators: 1: files stored so far, 2: total files. */ __( 'Stored %1$s / %2$s site files', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $j['done'] ), number_format_i18n( (int) $j['total'] ) );
		return $j;
	}

	/**
	 * Finish site file.
	 *
	 * @param array $j J.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	private static function finish_site_file( array $j ): array {
		$path      = (string) ( $j['path'] ?? '' );
		$raw       = (string) ( $j['raw_path'] ?? '' );
		$raw_bytes = (int) ( $j['raw_bytes'] ?? 0 );
		$packed    = ( $path && is_file( $path ) ) ? (int) filesize( $path ) : 0;
		if ( ! $packed ) {
			throw new RuntimeException( __( 'The full-site archive was not created.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( str_ends_with( strtolower( $path ), '.zip' ) ) {
			$check = new ZipArchive();
			if ( true !== $check->open( $path ) ) {
				throw new RuntimeException( __( 'Could not open the full-site ZIP file.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$required  = array( ( ! empty( $j['sql_gz'] ) ? self::SQL_IN_SITE_GZ : self::SQL_IN_SITE ) => false );
			$list_file = (string) ( $j['list_file'] ?? '' );
			$listed    = $list_file ? ( is_readable( $list_file ) ? file( $list_file, FILE_IGNORE_NEW_LINES ) : false ) : false;
			if ( ! is_array( $listed ) ) {
				$check->close();
				throw new RuntimeException( __( 'The full-site ZIP file is incomplete.', 'lucidlab-mpc-cleanup' ) . ' [FILE-LIST-MISSING]' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$missing_files = array_fill_keys( $listed, true );
			foreach ( self::site_folders() as $folder ) {
				if ( is_dir( self::site_root() . '/' . $folder ) && ! self::skip_site_path( self::site_root() . '/' . $folder ) ) {
					$required[ $folder . '/' ] = false;
				}
			}
			for ( $i = 0; $i < $check->numFiles; ++$i ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
				$name = (string) $check->getNameIndex( $i );
				unset( $missing_files[ $name ] );
				foreach ( $required as $prefix => $found ) {
					if ( ! $found && ( $name === $prefix || str_starts_with( $name, $prefix ) ) ) {
						$required[ $prefix ] = true;
					}
				}
			}
			$check->close();
			$missing = array_keys(
				array_filter(
					$required,
					static function ( bool $found ): bool {
						return ! $found;
					}
				)
			);
			if ( $missing ) {
				throw new RuntimeException( __( 'The full-site ZIP file is incomplete.', 'lucidlab-mpc-cleanup' ) . ' ' . implode( ', ', $missing ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			if ( $missing_files ) {
				throw new RuntimeException( __( 'The full-site ZIP file is incomplete.', 'lucidlab-mpc-cleanup' ) . ' ' . (string) array_key_first( $missing_files ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
		}
		if ( $raw && is_file( $raw ) ) {
			if ( ! str_ends_with( $raw, self::PART_SUFFIX ) ) {
				$raw_bytes = max( $raw_bytes, (int) filesize( $raw ) );
			}
			wp_delete_file( $raw );
		}
		self::forget_site_sql( $j );
		if ( ! empty( $j['list_file'] ) ) {
			wp_delete_file( (string) $j['list_file'] );
		}
		$raw_meta = $raw_bytes;
		self::write_meta( (string) ( $j['file'] ?? '' ), $raw_meta, $packed );
		$j['status']    = 'done';
		$j['done']      = max( 1, (int) ( $j['total'] ?? 1 ) );
		$j['raw_bytes'] = $raw_meta;
		$j['label']     = $packed
			? sprintf( /* translators: 1: backup file name, 2: archive size. */ __( 'Full-site backup ready: %1$s (%2$s)', 'lucidlab-mpc-cleanup' ), $j['file'], LucidLab_MPC_Helpers::bytes( $packed ) )
			: sprintf( /* translators: %s: backup file name. */ __( 'Full-site backup ready: %s', 'lucidlab-mpc-cleanup' ), $j['file'] );
		self::prune( 'site' );
		return $j;
	}

	/**
	 * Standard WordPress folders archived by a full-site backup, relative to the site root.
	 *
	 * @return array<int,string>
	 */
	public static function site_folders(): array {
		return LucidLab_MPC_Helpers::site_folders();
	}

	/**
	 * Skip site path.
	 *
	 * @param string $abs Abs.
	 *
	 * @return bool
	 */
	public static function skip_site_path( string $abs ): bool {
		$abs     = rtrim( str_replace( '\\', '/', $abs ), '/' );
		$root    = self::site_root();
		$backups = rtrim( str_replace( '\\', '/', self::dir() ), '/' );
		if ( ! str_starts_with( $abs, $root . '/' ) ) {
			return true;
		}
		$relative = substr( $abs, strlen( $root ) + 1 );
		// Managed hosts may symlink the standard WordPress folders. Follow only these named roots.
		$folders = self::site_folders();
		$content = LucidLab_MPC_Helpers::rel_to_root( WP_CONTENT_DIR );
		if ( is_link( $abs ) && ! in_array( $relative, array_merge( $folders, array( $content ) ), true ) ) {
			return true;
		}
		if ( $abs === $backups || str_starts_with( $abs, $backups . '/' ) ) {
			return true;
		}
		$base = basename( $abs );
		if ( $abs === $root . '/lucidlab-mpc-restore.php' || $abs === $root . '/lucidlab-mpc-restore-paths.php' ) {
			return true;
		}
		if ( in_array( $base, array( self::SQL_IN_SITE, self::SQL_IN_SITE_GZ ), true ) && rtrim( str_replace( '\\', '/', dirname( $abs ) ), '/' ) === $root ) {
			return true;
		}
		if ( '' !== $content && $content === $relative ) {
			return false;
		}
		foreach ( $folders as $folder ) {
			if ( $relative === $folder || str_starts_with( $relative, $folder . '/' ) ) {
				return false;
			}
		}
		if ( ! str_contains( $relative, '/' ) && ( preg_match( '/^wp-[a-z0-9-]+\.php$/i', $relative ) || in_array( $relative, array( 'index.php', 'xmlrpc.php', 'license.txt', 'readme.html', '.htaccess', 'web.config', '.user.ini' ), true ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Forget site sql.
	 *
	 * @param array $j J.
	 */
	private static function forget_site_sql( array $j = array() ): void {
		$staging = (string) ( $j['sql_in_site'] ?? ( self::dir() . '/' . self::SQL_IN_SITE ) );
		if ( $staging && is_file( $staging ) ) {
			wp_delete_file( $staging );
		}
		$root_sql = self::site_root() . '/' . self::SQL_IN_SITE;
		if ( is_file( $root_sql ) ) {
			wp_delete_file( $root_sql );
		}
	}

	/**
	 * Step restore db.
	 *
	 * @param array $j J.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	private static function step_restore_db( array $j ): array {
		if ( ( $j['phase'] ?? '' ) === 'inflate' ) {
			$j['sql_path'] = self::inflate_sql( (string) ( $j['path'] ?? '' ) );
			try {
				self::assert_restorable_sql( $j['sql_path'] );
			} catch ( RuntimeException $e ) {
				self::forget_temp_sql( $j );
				throw $e;
			}
			$j['phase']  = 'sql';
			$j['offset'] = 0;
			$j['buffer'] = '';
			$j['total']  = max( 1, (int) filesize( $j['sql_path'] ) );
			$j['label']  = __( 'Restoring the database…', 'lucidlab-mpc-cleanup' );
			return $j;
		}
		$sql_path = (string) ( $j['sql_path'] ?? $j['path'] ?? '' );
		// Last gate before SQL runs: check the exact file whose statements are about to be executed.
		if ( 0 === (int) ( $j['offset'] ?? 0 ) && '' === (string) ( $j['buffer'] ?? '' ) ) {
			try {
				self::assert_restorable_sql( $sql_path );
			} catch ( RuntimeException $e ) {
				self::forget_temp_sql( $j );
				throw $e;
			}
		}
		global $wpdb;
		$fh = fopen( $sql_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $fh ) {
			throw new RuntimeException( __( 'Could not read the database backup.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		fseek( $fh, (int) ( $j['offset'] ?? 0 ) );
		$buffer  = (string) ( $j['buffer'] ?? '' );
		$ran     = 0;
		$marker  = "\n-- MPC";
		$use_mpc = ( $j['use_mpc'] ?? null );
		while ( $ran < 40 && ! feof( $fh ) ) {
			$buffer .= (string) fread( $fh, 131072 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			if ( null === $use_mpc ) {
				$use_mpc      = str_contains( $buffer, $marker );
				$j['use_mpc'] = $use_mpc;
			}
			if ( $use_mpc ) {
				while ( true ) {
					$pos = strpos( $buffer, $marker );
					if ( false === $pos ) {
						break;
					}
					$sql    = trim( substr( $buffer, 0, $pos ) );
					$buffer = ltrim( substr( $buffer, $pos + strlen( $marker ) ) );
					if ( '' !== $sql && ! str_contains( $sql, 'lucidlab_mpc_backup_job' ) ) {
						$wpdb->query( $sql ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- restoring an administrator SQL dump; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
						++$ran;
					}
					if ( $ran >= 40 ) {
						break;
					}
				}
			} else {
				while ( true ) {
					$pos = strpos( $buffer, ";\n" );
					if ( false === $pos ) {
						break;
					}
					$sql    = trim( substr( $buffer, 0, $pos + 1 ) );
					$buffer = ltrim( substr( $buffer, $pos + 2 ) );
					if ( '' !== $sql && ! str_starts_with( $sql, '--' ) && ! str_contains( $sql, 'lucidlab_mpc_backup_job' ) ) {
						$wpdb->query( $sql ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- restoring an administrator SQL dump; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
						++$ran;
					}
					if ( $ran >= 40 ) {
						break;
					}
				}
			}
		}
		$j['offset'] = ftell( $fh );
		$j['buffer'] = $buffer;
		$j['done']   = (int) $j['offset'];
		$j['label']  = sprintf( /* translators: %s: amount of SQL restored so far. */ __( 'Restored %s of the SQL file…', 'lucidlab-mpc-cleanup' ), LucidLab_MPC_Helpers::bytes( (int) $j['offset'] ) );
		$eof         = feof( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( $eof ) {
			$j['buffer'] = self::strip_signature_line( (string) $j['buffer'] );
		}
		if ( $eof && trim( (string) $j['buffer'] ) === '' ) {
			self::forget_temp_sql( $j );
			$j['status'] = 'done';
			$j['label']  = __( 'Database restore finished. Reload the admin if you are signed out.', 'lucidlab-mpc-cleanup' );
		}
		return $j;
	}

	/**
	 * Step restore uploads.
	 *
	 * @param array $j J.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	private static function step_restore_uploads( array $j ): array {
		$uploads = rtrim( str_replace( '\\', '/', LucidLab_MPC_Helpers::uploads_basedir() ), '/' );
		$zip     = new ZipArchive();
		if ( true !== $zip->open( (string) $j['path'] ) ) {
			throw new RuntimeException( __( 'Could not open the uploads ZIP file.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$index      = (int) ( $j['offset'] ?? 0 );
		$total      = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
		$j['total'] = max( 1, $total );
		if ( $index >= $total ) {
			$zip->close();
			$j['status'] = 'done';
			$j['done']   = $total;
			$j['label']  = __( 'Uploads restore finished.', 'lucidlab-mpc-cleanup' );
			return $j;
		}
		$end = min( $total, $index + 40 );
		for ( $i = $index; $i < $end; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = str_replace( '\\', '/', (string) ( $stat['name'] ?? '' ) );
			if ( '' === $name || str_contains( $name, '..' ) || str_starts_with( $name, '/' ) ) {
				continue;
			}
			$dest = $uploads . '/' . ltrim( $name, '/' );
			if ( str_ends_with( $name, '/' ) ) {
				wp_mkdir_p( $dest );
				continue;
			}
			wp_mkdir_p( dirname( $dest ) );
			$stream = $zip->getStream( $name );
			if ( ! $stream ) {
				continue;
			}
			$out = fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			if ( $out ) {
				stream_copy_to_stream( $stream, $out );
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			}
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		$zip->close();
		$j['offset'] = $end;
		$j['done']   = $end;
		$j['label']  = sprintf( /* translators: 1: files restored so far, 2: total files. */ __( 'Restored %1$s / %2$s files', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $end ), number_format_i18n( (int) $total ) );
		return $j;
	}










	/**
	 * Compress sql.
	 *
	 * @param string $raw  Raw.
	 * @param string $dest Dest.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	private static function compress_sql( string $raw, string $dest ): void {
		LucidLab_MPC_Helpers::extend_time_limit( 300 );
		$ext = self::file_ext( $dest );
		if ( 'sql.gz' === $ext ) {
			$in  = fopen( $raw, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			$out = function_exists( 'gzopen' ) ? gzopen( $dest, 'wb1' ) : false;
			if ( ! $in || ! $out ) {
				throw new RuntimeException( __( 'Could not gzip the SQL dump.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			stream_copy_to_stream( $in, $out );
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			gzclose( $out );
			return;
		}
		if ( $raw !== $dest && ! rename( $raw, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			throw new RuntimeException( __( 'Could not store the SQL dump.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
	}

	/**
	 * Append SQL to a dump that is being written. A .part dump is gzip: every call adds one gzip member,
	 * so the SQL is compressed as it is produced and never stored as plain text. Older plain .sql jobs
	 * (started before an update) are appended as they were.
	 *
	 * @param string $raw  Dump file.
	 * @param string $data SQL.
	 *
	 * @throws RuntimeException When the dump cannot be written.
	 * @return int Uncompressed bytes added.
	 */
	private static function dump_append( string $raw, string $data ): int {
		if ( '' === $data ) {
			return 0;
		}
		if ( str_ends_with( $raw, self::PART_SUFFIX ) ) {
			$gz = function_exists( 'gzopen' ) ? gzopen( $raw, 'ab6' ) : false;
			$ok = $gz && false !== gzwrite( $gz, $data );
			if ( $gz ) {
				$ok = gzclose( $gz ) && $ok;
			}
		} else {
			$ok = false !== file_put_contents( $raw, $data, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		if ( ! $ok ) {
			throw new RuntimeException( __( 'Could not write the database backup.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		return strlen( $data );
	}

	/**
	 * Sign a finished gzip dump while streaming it (nothing is decompressed to disk), append the signature
	 * as a last gzip member and move the file to its final .sql.gz name.
	 *
	 * @param string $part .sql.gz.part file.
	 * @param string $dest Final .sql.gz path.
	 *
	 * @throws RuntimeException When the dump cannot be read, signed or stored.
	 * @return int Uncompressed size including the signature line.
	 */
	private static function seal_dump( string $part, string $dest ): int {
		LucidLab_MPC_Helpers::extend_time_limit( 300 );
		$in = gzopen( $part, 'rb' );
		if ( ! $in ) {
			throw new RuntimeException( __( 'Could not read the database backup.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$ctx   = hash_init( 'sha256', HASH_HMAC, self::sign_key() );
		$bytes = 0;
		$last  = '';
		while ( ! gzeof( $in ) ) {
			$chunk = gzread( $in, 1048576 );
			if ( false === $chunk ) {
				gzclose( $in );
				throw new RuntimeException( __( 'Could not read the database backup.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			if ( '' === $chunk ) {
				break;
			}
			hash_update( $ctx, $chunk );
			$bytes += strlen( $chunk );
			$last   = substr( $chunk, -1 );
		}
		gzclose( $in );
		$newline = $bytes > 0 && "\n" !== $last ? "\n" : '';
		if ( '' !== $newline ) {
			hash_update( $ctx, $newline );
		}
		$bytes += self::dump_append( $part, $newline . self::SIGN_PREFIX . hash_final( $ctx ) . "\n" );
		if ( ! rename( $part, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			throw new RuntimeException( __( 'Could not store the SQL dump.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		return $bytes;
	}

	/**
	 * Compress database dumps that older versions stored as plain .sql into .sql.gz (same content, so
	 * the signature stays valid) and delete the .sql. Works for up to $budget seconds per call.
	 *
	 * @param float $budget Seconds.
	 *
	 * @return bool True when no plain .sql dump is left.
	 */
	public static function compress_legacy_sql( float $budget = 15.0 ): bool {
		$dir = self::dir();
		if ( ! is_dir( $dir ) || ! function_exists( 'gzopen' ) ) {
			return ! function_exists( 'gzopen' ) || ! is_dir( $dir );
		}
		$job = self::job();
		if ( in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'paused' ), true ) ) {
			return false;
		}
		$until = microtime( true ) + $budget;
		foreach ( (array) glob( $dir . '/db-*.sql' ) as $sql ) {
			$sql  = str_replace( '\\', '/', (string) $sql );
			$name = basename( $sql );
			if ( ! self::safe_name( $name ) || ! is_file( $sql ) ) {
				continue;
			}
			if ( microtime( true ) > $until ) {
				return false;
			}
			LucidLab_MPC_Helpers::extend_time_limit( 300 );
			$dest = $sql . '.gz';
			$part = $dest . self::PART_SUFFIX;
			$in   = fopen( $sql, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			$out  = gzopen( $part, 'wb6' );
			$ok   = $in && $out && false !== stream_copy_to_stream( $in, $out );
			if ( $in ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			}
			if ( $out ) {
				$ok = gzclose( $out ) && $ok;
			}
			if ( ! $ok || file_exists( $dest ) || ! rename( $part, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				if ( is_file( $part ) ) {
					wp_delete_file( $part );
				}
				return false;
			}
			$raw = (int) filesize( $sql );
			touch( $dest, (int) filemtime( $sql ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Keep the backup date of the compressed copy; WP_Filesystem::touch() needs credentials in background requests.
			wp_delete_file( $sql );
			foreach ( array( $sql . '.meta.json', $sql . '.lst' ) as $sidecar ) {
				if ( is_file( $sidecar ) ) {
					wp_delete_file( $sidecar );
				}
			}
			clearstatcache( true, $dest );
			self::write_meta( $name . '.gz', $raw, (int) filesize( $dest ) );
		}
		return true;
	}

	/**
	 * Remove temporary files (dumps being written, restore copies, staged SQL) that a crashed or
	 * abandoned job left behind, once no job is using them.
	 */
	private static function sweep_temp_files(): void {
		$dir = self::dir();
		$job = get_option( self::JOB_OPTION, array() );
		if ( ! is_dir( $dir ) || ( is_array( $job ) && in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'paused' ), true ) ) ) {
			return;
		}
		$old = time() - 3600; // One hour.
		foreach ( array( '*' . self::PART_SUFFIX, '*' . self::RESTORE_SUFFIX, '*.restore.sql', self::SQL_IN_SITE ) as $pattern ) {
			foreach ( (array) glob( $dir . '/' . $pattern ) as $file ) {
				$file = (string) $file;
				if ( is_file( $file ) && (int) filemtime( $file ) < $old ) {
					wp_delete_file( $file );
				}
			}
		}
	}

	/**
	 * Inflate sql.
	 *
	 * @param string $archive Archive.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return string
	 */
	private static function inflate_sql( string $archive ): string {
		LucidLab_MPC_Helpers::extend_time_limit( 300 );
		$ext = self::file_ext( $archive );
		$dir = self::protect();
		// Short-lived: the protected backup folder, a name that is never listed or served, deleted when the restore ends or fails.
		$out = $dir . '/' . preg_replace( '/\.(sql\.gz|sql\.7z|sql)$/', '', basename( $archive ) ) . '-' . bin2hex( random_bytes( 6 ) ) . self::RESTORE_SUFFIX;
		if ( 'sql' === $ext ) {
			return $archive;
		}
		if ( 'sql.gz' === $ext ) {
			$in = gzopen( $archive, 'rb' );
			$fh = fopen( $out, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			if ( ! $in || ! $fh ) {
				if ( $in ) {
					gzclose( $in );
				}
				if ( $fh ) {
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
					wp_delete_file( $out );
				}
				throw new RuntimeException( __( 'Could not decompress the gzip SQL backup.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$copied = stream_copy_to_stream( $in, $fh );
			gzclose( $in );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			if ( false === $copied ) {
				wp_delete_file( $out );
				throw new RuntimeException( __( 'Could not decompress the gzip SQL backup.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			return $out;
		}
		throw new RuntimeException( __( 'Unsupported database backup format.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
	}




	/**
	 * Forget temp sql.
	 *
	 * @param array $j J.
	 */
	private static function forget_temp_sql( array $j ): void {
		$temp = (string) ( $j['sql_path'] ?? '' );
		$src  = (string) ( $j['path'] ?? '' );
		// Only ever remove a temporary file made by inflate_sql(), never a stored backup.
		if ( $temp && $temp !== $src && ( str_ends_with( $temp, self::RESTORE_SUFFIX ) || str_ends_with( $temp, '.restore.sql' ) ) && dirname( $temp ) === self::dir() && is_file( $temp ) ) {
			wp_delete_file( $temp );
		}
	}




	/**
	 * Prune.
	 *
	 * @param string $kind Kind.
	 */
	private static function prune( string $kind ): void {
		$items = array_values(
			array_filter(
				self::list_backups(),
				static function ( array $item ) use ( $kind ): bool {
					return ( $item['kind'] ?? '' ) === $kind;
				}
			)
		);
		foreach ( array_slice( $items, self::keep() ) as $item ) {
			self::delete_file( (string) ( $item['file'] ?? '' ) );
		}
	}

	/**
	 * Delete file.
	 *
	 * @param string $name Name.
	 */
	private static function delete_file( string $name ): void {
		$safe = self::safe_name( $name );
		$path = $safe ? self::dir() . '/' . $safe : '';
		if ( $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
		// Sidecars written next to the archive (size meta, 7-Zip list files).
		foreach ( array( $path . '.lst', $path . '.meta.json', self::meta_path( $safe ) ) as $sidecar ) {
			if ( $sidecar && is_file( $sidecar ) ) {
				wp_delete_file( $sidecar );
			}
		}
		self::prune_orphan_sidecars();
	}

	/**
	 * Remove leftover .meta.json / .lst files whose archive is already gone.
	 */
	private static function prune_orphan_sidecars(): void {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$job         = get_option( self::JOB_OPTION, array() );
		$active_list = is_array( $job ) && in_array( (string) ( $job['status'] ?? '' ), array( 'running', 'paused' ), true )
			? (string) ( $job['list_file'] ?? '' ) : '';
		$entries     = ( is_dir( $dir ) ? scandir( $dir ) : false );
		if ( ! is_array( $entries ) ) {
			return;
		}
		foreach ( $entries as $name ) {
			if ( str_ends_with( $name, '.meta.json' ) ) {
				$archive = substr( $name, 0, -strlen( '.meta.json' ) );
			} elseif ( str_ends_with( $name, '.lst' ) ) {
				$archive = substr( $name, 0, -strlen( '.lst' ) );
			} else {
				continue;
			}
			if ( ! self::safe_name( $archive ) ) {
				continue;
			}
			if ( str_ends_with( $name, '.lst' ) && '' !== $active_list && str_replace( '\\', '/', $active_list ) === $dir . '/' . $name ) {
				continue;
			}
			if ( ! is_file( $dir . '/' . $archive ) ) {
				wp_delete_file( $dir . '/' . $name );
			}
		}
	}

	/**
	 * Cleanup partial.
	 *
	 * @param array $j J.
	 */
	private static function cleanup_partial( array $j ): void {
		if ( in_array( $j['kind'] ?? '', array( 'db', 'uploads', 'site' ), true ) && ! empty( $j['file'] ) && ( $j['status'] ?? '' ) === 'running' ) {
			self::delete_file( (string) $j['file'] );
		}
		if ( ! empty( $j['raw_path'] ) && is_file( (string) $j['raw_path'] ) && (string) ( $j['path'] ?? '' ) !== (string) $j['raw_path'] ) {
			wp_delete_file( (string) $j['raw_path'] );
		}
		if ( ! empty( $j['list_file'] ) && is_file( (string) $j['list_file'] ) ) {
			wp_delete_file( (string) $j['list_file'] );
		}
		if ( ( $j['kind'] ?? '' ) === 'site' ) {
			self::forget_site_sql( $j );
		}
		self::forget_temp_sql( $j );
	}

	/**
	 * Send download.
	 *
	 * @param string $name Name.
	 */
	public static function send_download( string $name ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lucidlab-mpc-cleanup' ) );
		}
		check_admin_referer( 'lucidlab_mpc_backup_download' );
		$path = self::path( $name );
		if ( ! $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'lucidlab-mpc-cleanup' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		exit;
	}
}
