<?php
/**
 * Lucid Lab Media & Products Cleanup: quarantine class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared quarantine engine used by BOTH the orphan-image tab and the uploads tab.
 * Moves real files into wp-content/uploads/lucidlab-mpc-cleanup/quarantine/<random key>/<stamp>/ (paths preserved)
 * and records a manifest.json so everything is restorable. No MEDIA_TRASH dependency.
 */
class LucidLab_MPC_Quarantine {

	const DIRNAME        = '_cleanup-quarantine';
	const DIR_KEY_OPTION = 'lucidlab_mpc_quarantine_dir_key';
	/** Suffix that keeps quarantined server-side scripts and server config files inert. */
	const HELD_SUFFIX = '.quarantined';
	/** Extensions that get HELD_SUFFIX while they sit in quarantine. */
	const HELD_EXTENSIONS    = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc', 'cgi', 'pl', 'py', 'sh', 'shtml', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd', 'ini' );
	const RETENTION_DAYS     = 30;
	const LAST_ACTION_OPTION = 'lucidlab_mpc_last_action';
	/** Fixture sets used in local/staging tests — never auto-purge or health-purge. */
	const PROTECTED_HEALTH_SETS = array( '2026-09-29_101714', '2026-09-29_122928' );
	/** Soft wall-clock budget for post-quarantine OPTIMIZE TABLE work (seconds). */
	const OPTIMIZE_BUDGET_SECONDS = 8;
	/** Longer budget after large Action Scheduler batch deletes. */
	const OPTIMIZE_AS_BUDGET_SECONDS = 60;
	/** Run OPTIMIZE when at least this many rows were deleted in the request. */
	const OPTIMIZE_MIN_ROWS = 50;
	/** Batch size for Action Scheduler clear-finished / orphan-log DELETE loops. */
	const AS_DELETE_BATCH = 2000;
	/** Rows per restorable JSON chunk when dumping Action Scheduler data. */
	const AS_DUMP_BATCH = 500;

	/**
	 * Writable dirs.
	 *
	 * @var array<string,bool> Destination dirs already confirmed writable in this request.
	 */
	private array $writable_dirs = array();

	/**
	 * Whether health protected.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return bool
	 */
	public static function is_health_protected( string $stamp ): bool {
		return in_array( sanitize_file_name( $stamp ), self::PROTECTED_HEALTH_SETS, true );
	}

	/**
	 * Retention days.
	 *
	 * @return int
	 */
	public static function retention_days(): int {
		$days = (int) LucidLab_MPC_Settings::get( 'quarantine_days' );
		return max( 1, min( 365, ( $days ? $days : self::RETENTION_DAYS ) ) );
	}

	/**
	 * Record last action.
	 *
	 * @param string $action_type Action type.
	 * @param string $stamp       Stamp.
	 * @param array  $details     Details.
	 */
	public static function record_last_action( string $action_type, string $stamp, array $details = array() ): void {
		$last_action = array(
			'action_type' => $action_type,
			'stamp'       => $stamp,
			'timestamp'   => time(),
			'details'     => $details,
		);
		update_option( self::LAST_ACTION_OPTION, $last_action, false );
	}

	/**
	 * Get last action.
	 *
	 * @return array
	 */
	public static function get_last_action(): array {
		return (array) get_option( self::LAST_ACTION_OPTION, array() );
	}

	/**
	 * Clear last action.
	 */
	public static function clear_last_action(): void {
		delete_option( self::LAST_ACTION_OPTION );
	}

	/**
	 * Root.
	 *
	 * @return string
	 */
	public function root(): string {
		$outer = LucidLab_MPC_Storage::dir( 'quarantine' );
		$key   = (string) get_option( self::DIR_KEY_OPTION, '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			$key = bin2hex( random_bytes( 16 ) );
			update_option( self::DIR_KEY_OPTION, $key, false );
		}
		$dir = $outer . '/' . $key;
		$this->protect_dir( $outer );
		$this->protect_dir( $dir );
		$this->migrate_legacy_sets( $outer, $dir );
		return $dir;
	}

	/**
	 * Move sets from the old guessable layout (_cleanup-quarantine/<stamp>/) into the random key folder.
	 *
	 * @param string $outer Outer folder.
	 * @param string $dir   Key folder.
	 */
	private function migrate_legacy_sets( string $outer, string $dir ): void {
		static $done = array();
		if ( isset( $done[ $dir ] ) || ! is_dir( $dir ) ) {
			return;
		}
		$done[ $dir ] = true;
		$key          = wp_basename( $dir );
		foreach ( (array) glob( trailingslashit( $outer ) . '*', GLOB_ONLYDIR ) as $legacy ) {
			$name = wp_basename( (string) $legacy );
			if ( $name === $key || preg_match( '/^[a-f0-9]{32}$/', $name ) ) {
				continue;
			}
			if ( ! file_exists( $dir . '/' . $name ) && rename( (string) $legacy, $dir . '/' . $name ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				$this->neutralize_set( $dir . '/' . $name );
			}
		}
	}

	/**
	 * Give executable files in a migrated set the inert HELD_SUFFIX.
	 *
	 * @param string $set Set dir.
	 */
	private function neutralize_set( string $set ): void {
		$base = trailingslashit( wp_normalize_path( $set ) );
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $set, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}
			$path = wp_normalize_path( $file->getPathname() );
			if ( in_array( $file->getFilename(), array( 'index.php', '.htaccess', 'web.config' ), true ) && ! str_contains( substr( $path, strlen( $base ) ), '/' ) ) {
				continue;
			}
			$held = self::held_name( $path );
			if ( $held !== $path && ! file_exists( $held ) ) {
				rename( $path, $held ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			}
		}
		$this->protect_dir( $set );
	}

	/**
	 * Whether a file name needs HELD_SUFFIX while in quarantine.
	 *
	 * @param string $path Path.
	 *
	 * @return bool
	 */
	public static function needs_hold_suffix( string $path ): bool {
		$base = strtolower( wp_basename( $path ) );
		if ( in_array( $base, array( '.htaccess', '.htpasswd', '.user.ini', 'web.config' ), true ) ) {
			return true;
		}
		$parts = explode( '.', $base );
		array_shift( $parts );
		// Any executable extension anywhere in the name (shell.php.jpg runs on some Apache setups).
		foreach ( $parts as $ext ) {
			if ( in_array( $ext, self::HELD_EXTENSIONS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * On-disk name of a file while it is held in quarantine.
	 *
	 * @param string $path Path.
	 *
	 * @return string
	 */
	public static function held_name( string $path ): string {
		return self::needs_hold_suffix( $path ) ? $path . self::HELD_SUFFIX : $path;
	}

	/**
	 * On-disk file for a logical path inside quarantine (the held name when that is what exists).
	 *
	 * @param string $path Path.
	 *
	 * @return string
	 */
	public static function held_file( string $path ): string {
		$held = self::held_name( $path );
		return ( $held !== $path && ! file_exists( $path ) && file_exists( $held ) ) ? $held : $path;
	}

	/**
	 * Strip HELD_SUFFIX from an on-disk relative path.
	 *
	 * @param string $rel Rel.
	 *
	 * @return string
	 */
	public static function logical_rel( string $rel ): string {
		if ( str_ends_with( $rel, self::HELD_SUFFIX ) ) {
			$plain = substr( $rel, 0, -strlen( self::HELD_SUFFIX ) );
			if ( self::needs_hold_suffix( $plain ) ) {
				return $plain;
			}
		}
		return $rel;
	}

	/**
	 * Whether a path is inside the quarantine tree.
	 *
	 * @param string $path Path.
	 *
	 * @return bool
	 */
	private function in_quarantine( string $path ): bool {
		$path = wp_normalize_path( $path );
		foreach ( array( LucidLab_MPC_Storage::dir( 'quarantine', false ), LucidLab_MPC_Storage::legacy( 'quarantine' ) ) as $root ) {
			if ( '' !== $root && str_starts_with( $path, trailingslashit( wp_normalize_path( $root ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Map a move into or out of quarantine to the held on-disk names.
	 *
	 * @param string $src  Src.
	 * @param string $dest Dest.
	 *
	 * @return array{0:string,1:string}
	 */
	private function held_pair( string $src, string $dest ): array {
		$src_in  = $this->in_quarantine( $src );
		$dest_in = $this->in_quarantine( $dest );
		if ( $src_in ) {
			$src = self::held_file( $src );
		}
		if ( $dest_in && ! $src_in ) {
			$dest = self::held_name( $dest );
		}
		return array( $src, $dest );
	}

	/**
	 * Admin warning when the quarantine and backup folders can be downloaded over HTTP (Nginx and other servers that ignore .htaccess).
	 *
	 * @return string HTML (kses-safe) or ''.
	 */
	public static function public_access_notice(): string {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$nginx    = false !== stripos( $software, 'nginx' );
		$status   = (string) get_transient( 'lucidlab_mpc_quarantine_probe' );
		if ( '' === $status ) {
			$status = ( new self() )->probe_public_access();
			set_transient( 'lucidlab_mpc_quarantine_probe', $status, 'exposed' === $status ? HOUR_IN_SECONDS : DAY_IN_SECONDS );
		}
		if ( 'exposed' !== $status && ( ! $nginx || 'protected' === $status ) ) {
			return '';
		}
		$path    = (string) wp_parse_url( LucidLab_MPC_Helpers::uploads_baseurl(), PHP_URL_PATH );
		$path    = '/' . trim( $path, '/' );
		$snippet = 'location ~* ^' . str_replace( '.', '\\.', $path ) . '/(' . LucidLab_MPC_Storage::SLUG . '/(work|quarantine|backups-[a-f0-9]+)|' . self::DIRNAME . '|_cleanup-fsindex|lucidlab-mpc-backups-[a-f0-9]+)(/|$) { deny all; }';
		$lead    = 'exposed' === $status
			? __( 'Security: files in the quarantine folder can be downloaded over the web on this server. The .htaccess deny rules are ignored here (for example on Nginx).', 'lucidlab-mpc-cleanup' )
			: __( 'Security: this site runs on Nginx, which ignores the .htaccess deny rules that protect the quarantine and backup folders.', 'lucidlab-mpc-cleanup' );
		return esc_html( $lead ) . ' ' . esc_html__( 'Add this rule to the server block of your Nginx configuration (or ask your host) and reload Nginx:', 'lucidlab-mpc-cleanup' ) . '<br><code>' . esc_html( $snippet ) . '</code>';
	}

	/**
	 * Request a probe file in the quarantine folder over HTTP.
	 *
	 * @return string exposed|protected|unknown
	 */
	private function probe_public_access(): string {
		$dir = $this->root();
		if ( ! is_dir( $dir ) ) {
			return 'unknown';
		}
		$name  = 'probe-' . bin2hex( random_bytes( 6 ) ) . '.txt';
		$token = bin2hex( random_bytes( 8 ) );
		if ( false === file_put_contents( $dir . '/' . $name, $token ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			return 'unknown';
		}
		$url      = LucidLab_MPC_Storage::url_for( $dir . '/' . $name );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 3,
				'redirection' => 0,
			)
		);
		wp_delete_file( $dir . '/' . $name );
		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}
		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) && trim( (string) wp_remote_retrieve_body( $response ) ) === $token ) {
			return 'exposed';
		}
		return 'protected';
	}

	/**
	 * Unix time encoded in a set stamp (Y-m-d_His prefix), or 0.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return int
	 */
	public static function stamp_time( string $stamp ): int {
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})/', $stamp, $m ) ) {
			return 0;
		}
		return (int) strtotime( $m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . $m[4] );
	}

	/**
	 * Deny HTTP access to the quarantine tree (Apache/IIS; Nginx needs a matching location).
	 *
	 * @param string $dir Dir.
	 */
	public function protect_dir( string $dir ): void {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}
		if ( ! is_file( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				$dir . '/.htaccess',
				"Require all denied\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\nOptions -Indexes\n"
			);
		}
		if ( ! is_file( $dir . '/web.config' ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				$dir . '/web.config',
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization><directoryBrowse enabled=\"false\" /></system.webServer></configuration>\n"
			);
		}
	}

	/**
	 * New stamp.
	 *
	 * @return string
	 */
	public function new_stamp(): string {
		// Date prefix keeps sets sortable; the random part makes set folders unguessable.
		return wp_date( 'Y-m-d_His' ) . '-' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Set source.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return string
	 */
	public function set_source( string $stamp ): string {
		$manifest = $this->manifest( $stamp );
		return sanitize_key( (string) ( $manifest['source'] ?? '' ) );
	}

	/**
	 * Source label.
	 *
	 * @param string $source Source.
	 *
	 * @return string
	 */
	public static function source_label( string $source ): string {
		$labels = array(
			'orphans'    => __( 'Orphan Images', 'lucidlab-mpc-cleanup' ),
			'uploads'    => __( 'Uploads Scanner', 'lucidlab-mpc-cleanup' ),
			'webp'       => __( 'Generated WebP', 'lucidlab-mpc-cleanup' ),
			'originals'  => __( 'Original Images', 'lucidlab-mpc-cleanup' ),
			'avif'       => __( 'Generated AVIF', 'lucidlab-mpc-cleanup' ),
			'fs-sizes'   => __( 'Other Sizes on Disk', 'lucidlab-mpc-cleanup' ),
			'sizes'      => __( 'Image Sizes', 'lucidlab-mpc-cleanup' ),
			'dupcontent' => __( 'Byte Identical Images', 'lucidlab-mpc-cleanup' ),
			'visual'     => __( 'Visually Identical Images', 'lucidlab-mpc-cleanup' ),
			'missing'    => __( 'Missing Images', 'lucidlab-mpc-cleanup' ),
			'shared'     => __( 'Shared Images', 'lucidlab-mpc-cleanup' ),
			'dbcleaner'  => __( 'Database Cleaner', 'lucidlab-mpc-cleanup' ),
			'core_check' => __( 'WordPress files', 'lucidlab-mpc-cleanup' ),
		);
		return $labels[ sanitize_key( $source ) ] ?? '';
	}

	/**
	 * Set dir.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return string
	 */
	private function set_dir( string $stamp ): string {
		return trailingslashit( $this->root() ) . $stamp;
	}

	/**
	 * Manifest path.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return string
	 */
	private function manifest_path( string $stamp ): string {
		return $this->set_dir( $stamp ) . '/manifest.json';
	}

	/**
	 * Read manifest.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	private function read_manifest( string $stamp ): array {
		$path = $this->manifest_path( $stamp );
		if ( ! file_exists( $path ) ) {
			return array(
				'created_gmt' => gmdate( 'c' ),
				'site'        => home_url(),
				'plugin'      => LUCIDLAB_MPC_VERSION,
				'basedir'     => LucidLab_MPC_Helpers::uploads_basedir(),
				'files'       => array(),
				'attachments' => array(),
			);
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( __( 'Cannot read the quarantine manifest. The existing recovery record was preserved.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		return $data;
	}

	/**
	 * Manifest.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return array
	 */
	public function manifest( string $stamp ): array {
		$stamp = sanitize_file_name( $stamp );
		if ( '' === $stamp || ! file_exists( $this->manifest_path( $stamp ) ) ) {
			return array();
		}
		return $this->read_manifest( $stamp );
	}

	/**
	 * Drop invalid UTF-8 so a manifest can keep image-size metadata.
	 *
	 * @param mixed $value Value.
	 *
	 * @return mixed
	 */
	private function utf8_manifest_value( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->utf8_manifest_value( $item );
			}
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			return wp_check_invalid_utf8( $value, true );
		}
		$clean = iconv( 'UTF-8', 'UTF-8//IGNORE', $value );
		return is_string( $clean ) ? $clean : '';
	}

	/**
	 * Write manifest.
	 *
	 * @param string $stamp Stamp.
	 * @param array  $data  Data.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	private function write_manifest( string $stamp, array $data ): void {
		$manifest_path = $this->manifest_path( $stamp );
		$manifest_dir  = dirname( $manifest_path );
		$uploads_base  = wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() );
		$manifest_norm = wp_normalize_path( $manifest_path );
		if ( ! is_dir( $manifest_dir ) ) {
			wp_mkdir_p( $manifest_dir );
		}
		if ( ! is_dir( $manifest_dir ) || ! is_writable( $manifest_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			throw new RuntimeException( __( 'Quarantine manifest directory is not writable.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( ! str_starts_with( $manifest_norm, $uploads_base ) ) {
			throw new RuntimeException( __( 'Quarantine manifest is outside uploads.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}

		$json_flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$json       = wp_json_encode( $data, $json_flags );
		if ( false === $json ) {
			// Invalid UTF-8 in one attachment (odd EXIF) used to drop metadata for every
			// file in the set, so a restore came back with no image sizes.
			foreach ( $data['attachments'] ?? array() as $i => $a ) {
				$data['attachments'][ $i ]['metadata'] = $this->utf8_manifest_value( $a['metadata'] ?? null );
			}
			$json = wp_json_encode( $data, $json_flags );
		}
		if ( false === $json && ! empty( $data['attachments'] ) ) {
			foreach ( $data['attachments'] as $i => $a ) {
				$kept                                  = $data['attachments'][ $i ]['metadata'] ?? null;
				$data['attachments'][ $i ]['metadata'] = null;
				$json                                  = wp_json_encode( $data, $json_flags );
				if ( is_string( $json ) ) {
					break;
				}
				$data['attachments'][ $i ]['metadata'] = $kept;
				$json                                  = false;
			}
		}
		if ( ! is_string( $json ) ) {
			throw new RuntimeException( __( 'Cannot encode the quarantine recovery record.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$temporary = $manifest_path . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		try {
			if ( file_put_contents( $temporary, $json, LOCK_EX ) !== strlen( $json ) || ! rename( $temporary, $manifest_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				throw new RuntimeException( __( 'Cannot save the quarantine recovery record. The previous manifest was preserved.', 'lucidlab-mpc-cleanup' ) );
			}
		} finally {
			if ( is_file( $temporary ) ) {
				wp_delete_file( $temporary ); }
		}
	}

	/**
	 * Ensure set.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return bool
	 */
	private function ensure_set( string $stamp ): bool {
		$dir = $this->set_dir( $stamp );
		$ok  = wp_mkdir_p( $dir );
		if ( $ok ) {
			$this->protect_dir( $this->root() );
			$this->protect_dir( $dir );
		}
		return $ok;
	}

	/**
	 * Capture the warning text emitted by a filesystem operation without leaking it to the page.
	 *
	 * @param callable     $callback Callback.
	 * @param string&|null $warning  Warning.
	 */
	private function fs_call( callable $callback, ?string &$warning = null ) {
		$warning = null;
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Dynamic IN lists use one placeholder per id. fs_call() captures filesystem warnings and restores the previous handler.
			static function ( $severity, $message ) use ( &$warning ) {
				$warning = (string) $message;
				return true;
			}
		);
		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Find the nearest existing parent directory for a path.
	 *
	 * @param string $path Path.
	 *
	 * @return string
	 */
	private function existing_parent_dir( string $path ): string {
		$dir  = dirname( $path );
		$last = '';
		while ( ! is_dir( $dir ) && $dir !== $last ) {
			$last = $dir;
			$dir  = dirname( $dir );
		}
		return $dir;
	}

	/**
	 * Checked move with a structured failure reason. rename() is attempted first and copy+unlink is used as a cross-filesystem fallback. The source is considered moved only when it is gone.
	 *
	 * @param string $src       Src.
	 * @param string $dest      Dest.
	 * @param bool   $overwrite Overwrite.
	 *
	 * @return array{ok:bool,code?:string,label?:string,detail?:string}
	 */
	private function move_diagnostic( string $src, string $dest, bool $overwrite = false ): array {
		$src  = LucidLab_MPC_Helpers::norm_path( $src );
		$dest = LucidLab_MPC_Helpers::norm_path( $dest );

		if ( '' === $src || ! file_exists( $src ) ) {
			return array(
				'ok'     => false,
				'code'   => 'source_missing',
				'label'  => __( 'File does not exist', 'lucidlab-mpc-cleanup' ),
				'detail' => $src,
			);
		}
		if ( ! is_file( $src ) ) {
			return array(
				'ok'     => false,
				'code'   => 'source_not_file',
				'label'  => __( 'Path is not a regular file', 'lucidlab-mpc-cleanup' ),
				'detail' => $src,
			);
		}
		if ( ! is_readable( $src ) ) {
			return array(
				'ok'     => false,
				'code'   => 'source_not_readable',
				'label'  => __( 'File is not readable', 'lucidlab-mpc-cleanup' ),
				'detail' => __( 'PHP cannot read the source file.', 'lucidlab-mpc-cleanup' ),
			);
		}
		$src_dir = dirname( $src );
		if ( ! is_writable( $src_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			return array(
				'ok'     => false,
				'code'   => 'source_directory_not_writable',
				'label'  => __( 'Uploads/source directory is not writable', 'lucidlab-mpc-cleanup' ),
				'detail' => $src_dir,
			);
		}
		if ( file_exists( $dest ) ) {
			if ( $overwrite && is_file( $dest ) ) {
				// Only discard a quarantine copy after verifying an identical restored file.
				$source_hash      = ( is_readable( $src ) ? hash_file( 'sha256', $src ) : false );
				$destination_hash = ( is_readable( $dest ) ? hash_file( 'sha256', $dest ) : false );
				if ( ! $source_hash || ! $destination_hash || ! hash_equals( $source_hash, $destination_hash ) ) {
					return array(
						'ok'     => false,
						'code'   => 'destination_exists',
						'label'  => __( 'A different file already exists at the restore destination', 'lucidlab-mpc-cleanup' ),
						'detail' => __( 'Both files were preserved. Resolve the destination conflict before retrying.', 'lucidlab-mpc-cleanup' ),
					);
				}
				$unlink_warning = null;
				$removed        = $this->fs_call( static fn() => LucidLab_MPC_Helpers::delete_file( $src ), $unlink_warning );
				return $removed && ! file_exists( $src ) ? array( 'ok' => true ) : array(
					'ok'     => false,
					'code'   => 'source_delete_failed',
					'label'  => __( 'Could not remove identical quarantine copy', 'lucidlab-mpc-cleanup' ),
					'detail' => ( $unlink_warning ? $unlink_warning : $src ),
				);
			} else {
				return array(
					'ok'     => false,
					'code'   => 'destination_exists',
					'label'  => __( 'Destination file already exists', 'lucidlab-mpc-cleanup' ),
					'detail' => $dest,
				);
			}
		}

		$dest_dir = dirname( $dest );
		if ( ! is_dir( $dest_dir ) ) {
			$parent = $this->existing_parent_dir( $dest_dir );
			if ( '' === $parent || ! is_dir( $parent ) ) {
				return array(
					'ok'     => false,
					'code'   => 'destination_parent_missing',
					'label'  => __( 'Destination parent directory does not exist', 'lucidlab-mpc-cleanup' ),
					'detail' => $dest_dir,
				);
			}
			if ( ! is_writable( $parent ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				return array(
					'ok'     => false,
					'code'   => 'quarantine_not_writable',
					'label'  => __( 'Quarantine directory is not writable', 'lucidlab-mpc-cleanup' ),
					'detail' => $parent,
				);
			}
			$mkdir_warning = null;
			$made          = $this->fs_call( static fn() => wp_mkdir_p( $dest_dir ), $mkdir_warning );
			if ( ! $made || ! is_dir( $dest_dir ) ) {
				return array(
					'ok'     => false,
					'code'   => 'quarantine_create_failed',
					'label'  => __( 'Failed to create quarantine directory', 'lucidlab-mpc-cleanup' ),
					'detail' => ( $mkdir_warning ? $mkdir_warning : $dest_dir ),
				);
			}
		}
		if ( ! is_writable( $dest_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			return array(
				'ok'     => false,
				'code'   => 'quarantine_not_writable',
				'label'  => __( 'Quarantine directory is not writable', 'lucidlab-mpc-cleanup' ),
				'detail' => $dest_dir,
			);
		}

		$rename_warning = null;
		$renamed        = $this->fs_call( static fn() => rename( $src, $dest ), $rename_warning ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( $renamed && ! file_exists( $src ) && is_file( $dest ) ) {
			return array( 'ok' => true );
		}

		// A failed rename can be normal across filesystems, so try copy+unlink before reporting it.
		$copy_warning = null;
		$copied       = $this->fs_call( static fn() => copy( $src, $dest ), $copy_warning );
		if ( ! $copied || ! is_file( $dest ) ) {
			$detail = array_filter(
				array(
					$rename_warning ? 'rename(): ' . $rename_warning : '',
					$copy_warning ? 'copy(): ' . $copy_warning : '',
				)
			);
			return array(
				'ok'     => false,
				'code'   => 'move_copy_failed',
				'label'  => __( 'Unable to move file (rename/copy failed)', 'lucidlab-mpc-cleanup' ),
				'detail' => $detail ? implode( ' | ', $detail ) : __( 'rename() and copy() both failed.', 'lucidlab-mpc-cleanup' ),
			);
		}

		$unlink_warning = null;
		$deleted        = $this->fs_call( static fn() => LucidLab_MPC_Helpers::delete_file( $src ), $unlink_warning );
		if ( ! $deleted || file_exists( $src ) ) {
			// Copy succeeded but source deletion did not: remove the duplicate destination where possible.
			if ( is_file( $dest ) ) {
				$cleanup_warning = null;
				$this->fs_call( static fn() => LucidLab_MPC_Helpers::delete_file( $dest ), $cleanup_warning );
			}
			return array(
				'ok'     => false,
				'code'   => 'source_delete_failed',
				'label'  => __( 'Copied file but could not delete source', 'lucidlab-mpc-cleanup' ),
				'detail' => ( $unlink_warning ? $unlink_warning : __( 'unlink() failed; source file is still present.', 'lucidlab-mpc-cleanup' ) ),
			);
		}

		return array( 'ok' => true );
	}

	/**
	 * Boolean wrapper retained for restore and older callers.
	 *
	 * @param string $src       Src.
	 * @param string $dest      Dest.
	 * @param bool   $overwrite Overwrite.
	 *
	 * @return bool
	 */
	private function move( string $src, string $dest, bool $overwrite = false ): bool {
		list( $src, $dest ) = $this->held_pair( $src, $dest );
		$result             = $this->move_diagnostic( $src, $dest, $overwrite );
		return ! empty( $result['ok'] );
	}

	/**
	 * Add one error to a group keyed by a stable machine code.
	 *
	 * @param array  &$errors        Errors.
	 * @param string $code          Code.
	 * @param string $label         Label.
	 * @param string $file          File.
	 * @param int    $attachment_id Attachment id.
	 * @param string $detail        Detail.
	 */
	private function add_error( array &$errors, string $code, string $label, string $file, int $attachment_id = 0, string $detail = '' ): void {
		if ( ! isset( $errors[ $code ] ) ) {
			$errors[ $code ] = array(
				'code'  => $code,
				'label' => $label,
				'count' => 0,
				'files' => array(),
			);
		}
		++$errors[ $code ]['count'];
		$errors[ $code ]['files'][] = array(
			'id'     => $attachment_id,
			'file'   => $file,
			'detail' => $detail,
		);
	}

	/**
	 * Legacy flat strings retained for callers that still consume the old `fails` key.
	 *
	 * @param array $errors Errors.
	 *
	 * @return array
	 */
	private function errors_to_strings( array $errors ): array {
		$out = array();
		foreach ( $errors as $group ) {
			$label = (string) ( $group['label'] ?? __( 'Filesystem error', 'lucidlab-mpc-cleanup' ) );
			foreach ( (array) ( $group['files'] ?? array() ) as $item ) {
				$file  = (string) ( $item['file'] ?? '' );
				$out[] = trim( $file . ' (' . $label . ')' );
			}
		}
		return $out;
	}


	/**
	 * Move a batch of loose upload files (Tab 3). $rels are paths relative to uploads basedir.
	 * Returns grouped filesystem errors for the UI.
	 */
	/**
	 * Remember the image-size metadata entry each quarantined size file had, so a restore can put it back. Without it, restored files are on disk but no longer counted as in use.
	 *
	 * @param string                                             $stamp  Stamp.
	 * @param array<string,array{id:int,name:string,size:array}> $by_rel By rel.
	 */
	public function note_size_meta( string $stamp, array $by_rel ): void {
		if ( ! $by_rel || ! file_exists( $this->manifest_path( $stamp ) ) ) {
			return;
		}
		$manifest = $this->read_manifest( $stamp );
		$changed  = false;
		foreach ( (array) ( $manifest['files'] ?? array() ) as $i => $f ) {
			$rel = (string) ( $f['rel'] ?? '' );
			if ( isset( $by_rel[ $rel ] ) && empty( $f['size_meta'] ) ) {
				$manifest['files'][ $i ]['size_meta'] = array(
					'id'   => (int) $by_rel[ $rel ]['id'],
					'name' => (string) $by_rel[ $rel ]['name'],
					'size' => (array) $by_rel[ $rel ]['size'],
				);
				$changed                              = true;
			}
		}
		if ( $changed ) {
			$this->write_manifest( $stamp, $manifest );
		}
	}

	/**
	 * Put back the size entry removed when this file was quarantined.
	 *
	 * @param array $f F.
	 */
	private function restore_size_meta( array $f ): void {
		$sm = $f['size_meta'] ?? null;
		if ( ! is_array( $sm ) || empty( $sm['id'] ) || '' === (string) ( $sm['name'] ?? '' ) || ! is_array( $sm['size'] ?? null ) ) {
			return;
		}
		$id   = (int) $sm['id'];
		$name = (string) $sm['name'];
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return;
		}
		if ( wp_basename( (string) ( $sm['size']['file'] ?? '' ) ) !== wp_basename( (string) ( $f['rel'] ?? '' ) ) ) {
			return;
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) || isset( $meta['sizes'][ $name ] ) ) {
			return;
		}
		$meta['sizes']          = is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array();
		$meta['sizes'][ $name ] = $sm['size'];
		wp_update_attachment_metadata( $id, $meta );
	}

	/**
	 * Quarantine files.
	 *
	 * @param array  $rels   Rels.
	 * @param string $stamp  Stamp.
	 * @param string $source Source.
	 *
	 * @throws Throwable When a file move or manifest step fails.
	 * @return array
	 */
	public function quarantine_files( array $rels, string $stamp, string $source = 'uploads' ): array {
		$manifest = $this->read_manifest( $stamp );
		if ( empty( $manifest['source'] ) ) {
			$manifest['source'] = $source;
		}
		$basedir             = LucidLab_MPC_Helpers::uploads_basedir();
		$ok                  = 0;
		$fail                = 0;
		$skip                = 0;
		$errors              = array();
		$this->writable_dirs = array();
		$this->protect_dir( $this->set_dir( $stamp ) );
		$protected = 'uploads' === $source ? ( new LucidLab_MPC_Uploads() )->protected_rels( array_map( 'strval', $rels ) ) : array();
		$listed    = array_flip( array_column( (array) ( $manifest['files'] ?? array() ), 'rel' ) );
		$moved     = array();

		foreach ( $rels as $raw_rel ) {
			$rel = LucidLab_MPC_Helpers::sanitize_rel( $raw_rel );
			if ( '' === $rel ) {
				++$fail;
				$this->add_error( $errors, 'invalid_path', __( 'Invalid file path', 'lucidlab-mpc-cleanup' ), (string) $raw_rel, 0, __( 'The relative upload path was empty or unsafe.', 'lucidlab-mpc-cleanup' ) );
				continue;
			}

			if ( ! empty( $protected[ (string) $raw_rel ] ) || ! empty( $protected[ $rel ] ) ) {
				++$skip;
				$this->add_error( $errors, 'protected_media', __( 'Protected media', 'lucidlab-mpc-cleanup' ), $rel, 0, __( 'This file belongs to a registered original, a generated WebP/AVIF copy, or content still referencing it. It was not moved; rescan Uploads Scanner.', 'lucidlab-mpc-cleanup' ) );
				continue;
			}
			$src  = $basedir . $rel;
			$dest = $this->set_dir( $stamp ) . '/' . $rel;
			// A batch sent again after an interrupted request: the file is already in this set.
			if ( ! is_file( $src ) && is_file( $dest ) ) {
				++$ok;
				if ( ! isset( $listed[ $rel ] ) ) {
					$manifest['files'][] = array(
						'rel'   => $rel,
						'bytes' => (int) filesize( $dest ),
					);
					$listed[ $rel ]      = true;
				}
				continue;
			}
			if ( ! is_file( $src ) ) {
				// Stale scan list, earlier quarantine, or originals already replaced — not a move failure.
				++$skip;
				$this->add_error( $errors, 'source_missing', __( 'Already missing from uploads', 'lucidlab-mpc-cleanup' ), $rel, 0, __( 'The file is not in uploads (already quarantined, deleted, or the scan list is stale). Rescan.', 'lucidlab-mpc-cleanup' ) );
				continue;
			}
			$bytes = 0;
			$move  = $this->move_file( $src, $dest, $bytes );
			if ( ! empty( $move['ok'] ) ) {
				$moved[]        = array(
					'src'  => $src,
					'dest' => $dest,
					'rel'  => $rel,
				);
				$listed[ $rel ] = true;
				++$ok;
				$manifest['files'][] = array(
					'rel'   => $rel,
					'bytes' => $bytes,
				);
				continue;
			}

			++$fail;
			$this->add_error(
				$errors,
				(string) ( $move['code'] ?? 'unknown_filesystem_error' ),
				(string) ( $move['label'] ?? __( 'Unexpected filesystem error', 'lucidlab-mpc-cleanup' ) ),
				$rel,
				0,
				(string) ( $move['detail'] ?? '' )
			);
		}

		try {
			$this->write_manifest( $stamp, $manifest );
		} catch ( Throwable $error ) {
			$this->rollback_moved( $moved, $errors, 0 );
			throw $error;
		}

		// Record last action for undo functionality.
		if ( $ok > 0 ) {
			self::record_last_action(
				'quarantine_files',
				$stamp,
				array(
					'source'            => $source,
					'items_quarantined' => $ok,
					'items_failed'      => $fail,
				)
			);
		}

		return array(
			'ok'     => $ok,
			'fail'   => $fail,
			'skip'   => $skip,
			'errors' => array_values( $errors ),
			'fails'  => $this->errors_to_strings( $errors ),
		);
	}

	/**
	 * Move WordPress core files (paths relative to ABSPATH) into a quarantine set.
	 *
	 * @param array  $rels   Paths relative to ABSPATH (e.g. wp-admin/foo.php).
	 * @param string $stamp  Stamp.
	 * @param string $source Source.
	 *
	 * @return array{ok:int,fail:int,skip:int,errors:array,fails:array}
	 * @throws RuntimeException When the operation fails.
	 */
	public function quarantine_core_files( array $rels, string $stamp, string $source = 'core_check' ): array {
		if ( 1 !== count( $rels ) || 'core_check' !== $source ) {
			throw new RuntimeException( __( 'Review one unexpected file at a time.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$rel   = LucidLab_MPC_Core_Check::validate_extra_for_action( (string) $rels[0] );
		$stamp = sanitize_file_name( $stamp );
		if ( '' === $stamp || is_dir( $this->set_dir( $stamp ) ) || ! $this->ensure_set( $stamp ) ) {
			throw new RuntimeException( __( 'Could not create a new quarantine set.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$manifest            = $this->read_manifest( $stamp );
		$manifest['source']  = 'core_check';
		$manifest['basedir'] = trailingslashit( ABSPATH );
		$source_path         = ABSPATH . $rel;
		$dest                = $this->set_dir( $stamp ) . '/' . $rel;
		// Save recovery information before moving a file outside the WordPress root.
		$manifest['files'][] = array(
			'rel'   => $rel,
			'bytes' => (int) filesize( $source_path ),
		);
		$this->write_manifest( $stamp, $manifest );
		$bytes = 0;
		$move  = $this->move_file( $source_path, $dest, $bytes );
		if ( empty( $move['ok'] ) ) {
			$manifest['files'] = array();
			try {
				$this->write_manifest( $stamp, $manifest );
			} catch ( Throwable $ignored ) {
				// The files are already quarantined; a manifest write failure is retried on the next save.
				unset( $ignored );
			}
				throw new RuntimeException( (string) ( $move['label'] ?? __( 'Could not move the unexpected file.', 'lucidlab-mpc-cleanup' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		self::record_last_action(
			'quarantine_files',
			$stamp,
			array(
				'source'            => 'core_check',
				'items_quarantined' => 1,
			)
		);
		return array(
			'ok'     => 1,
			'fail'   => 0,
			'skip'   => 0,
			'errors' => array(),
			'fails'  => array(),
		);
	}

	/**
	 * Quarantine orphan image ATTACHMENTS (Tab 2). Every referenced file must move successfully before the media DB row is deleted. If one file fails, already-moved files are rolled back.
	 *
	 * @param array  $ids    Ids.
	 * @param string $stamp  Stamp.
	 * @param string $source Source.
	 *
	 * @throws Throwable When a file move or manifest step fails.
	 * @return array
	 */
	public function quarantine_attachments( array $ids, string $stamp, string $source = 'orphans' ): array {
		$manifest = $this->read_manifest( $stamp );
		if ( empty( $manifest['source'] ) ) {
			$manifest['source'] = $source;
		}
		$basedir_norm = trailingslashit( LucidLab_MPC_Helpers::norm_path( LucidLab_MPC_Helpers::uploads_basedir() ) );
		$set_dir      = $this->set_dir( $stamp );
		$this->protect_dir( $set_dir );
		$ok                  = 0;
		$fail                = 0;
		$skip                = 0;
		$errors              = array();
		$ready               = array();
		$this->writable_dirs = array();
		// Fetch posts and their metadata once for the batch, rather than on every attachment.
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_map( 'intval', $ids ), false, true ); }

		if ( class_exists( 'LucidLab_MPC_Index', false ) ) {
			LucidLab_MPC_Index::begin_bulk();
		}
		if ( function_exists( 'wp_defer_term_counting' ) ) {
			wp_defer_term_counting( true );
		}
		if ( function_exists( 'wp_defer_comment_counting' ) ) {
			wp_defer_comment_counting( true );
		}

		$done_ids = array_flip( array_map( 'intval', array_column( (array) ( $manifest['attachments'] ?? array() ), 'orig_id' ) ) );
		$leaving  = array_map( 'intval', $ids );
		try {
			foreach ( $ids as $id ) {
				$id   = (int) $id;
				$post = get_post( $id );
				if ( ! $post && isset( $done_ids[ $id ] ) ) {
					++$ok;
					continue;
				}
				if ( ! $post || 'attachment' !== $post->post_type ) {
					++$fail;
					$this->add_error( $errors, 'not_attachment', __( 'Media record is not an attachment', 'lucidlab-mpc-cleanup' ), '#' . $id, $id, __( 'The selected WordPress post is not an attachment.', 'lucidlab-mpc-cleanup' ) );
					continue;
				}

				$meta     = wp_get_attachment_metadata( $id );
				$attached = (string) get_post_meta( $id, '_wp_attached_file', true );
				$file     = get_attached_file( $id );
				$files    = $this->attachment_abs_files( $id, is_array( $meta ) ? $meta : null, is_string( $file ) ? $file : null );
				if ( empty( $files ) ) {
					++$fail;
					$this->add_error( $errors, 'attachment_path_missing', __( 'Attachment file path is missing', 'lucidlab-mpc-cleanup' ), '#' . $id, $id, __( 'WordPress has no usable _wp_attached_file path for this media item.', 'lucidlab-mpc-cleanup' ) );
					continue;
				}

				$moved       = array();
				$kept        = array();
				$item_failed = false;
				$item_bytes  = 0;
				$live_files  = 0;
				// Several media records can point at one file on disk. Such a file stays in place for the
				// records that remain; moving it would break them (and the survivor of a merge).
				$in_use = $this->files_in_use_elsewhere( $id, $files, $leaving );
				foreach ( $files as $abs ) {
					$norm_abs = LucidLab_MPC_Helpers::norm_path( $abs );
					if ( 0 !== strpos( $norm_abs, $basedir_norm ) ) {
						$item_failed = true;
						$this->add_error( $errors, 'outside_uploads', __( 'File path is outside the uploads directory', 'lucidlab-mpc-cleanup' ), $norm_abs, $id, $norm_abs );
						break;
					}
					$rel     = ltrim( substr( $norm_abs, strlen( $basedir_norm ) ), '/' );
					$display = '' !== $rel ? $rel : $norm_abs;
					$dest    = $set_dir . '/' . $rel;
					if ( isset( $in_use[ $norm_abs ] ) && is_file( $norm_abs ) ) {
						$kept[] = $rel;
						++$live_files;
						continue;
					}
					if ( ! is_file( $norm_abs ) && is_file( $dest ) ) {
						$item_bytes += (int) filesize( $dest );
						$moved[]     = array(
							'rel'  => $rel,
							'src'  => $norm_abs,
							'dest' => $dest,
						);
						++$live_files;
						continue;
					}
					if ( ! is_file( $norm_abs ) ) {
						// Size already gone; keep quarantining the remaining files.
						continue;
					}
					++$live_files;
					$move = $this->move_file( $norm_abs, $dest, $item_bytes );
					if ( empty( $move['ok'] ) ) {
						$item_failed = true;
						$this->add_error(
							$errors,
							(string) ( $move['code'] ?? 'unknown_filesystem_error' ),
							(string) ( $move['label'] ?? __( 'Unexpected filesystem error', 'lucidlab-mpc-cleanup' ) ),
							$display,
							$id,
							(string) ( $move['detail'] ?? '' )
						);
						break;
					}
					$moved[] = array(
						'rel'  => $rel,
						'src'  => $norm_abs,
						'dest' => $dest,
					);
				}

				if ( ! $item_failed && $live_files < 1 ) {
					++$skip;
					$this->add_error(
						$errors,
						'already_missing',
						__( 'Already missing from uploads', 'lucidlab-mpc-cleanup' ),
						'' !== $attached ? $attached : ( '#' . $id ),
						$id,
						__( 'No files remain in uploads for this media record. Use Missing Images to purge the record.', 'lucidlab-mpc-cleanup' )
					);
					continue;
				}

				if ( $item_failed ) {
					$this->rollback_moved( $moved, $errors, $id );
					++$fail;
					continue;
				}

				$ready[ $id ] = array(
					'post'    => $post,
					'moved'   => $moved,
					'payload' => array(
						'orig_id'  => $id,
						'post'     => array(
							'post_author'    => (int) $post->post_author,
							'post_date'      => (string) $post->post_date,
							'post_date_gmt'  => (string) $post->post_date_gmt,
							'post_title'     => $post->post_title,
							'post_content'   => $post->post_content,
							'post_excerpt'   => $post->post_excerpt,
							'post_mime_type' => $post->post_mime_type,
							'post_name'      => $post->post_name,
							'post_parent'    => (int) $post->post_parent,
						),
						'attached' => $attached,
						'metadata' => $meta,
						'alt'      => get_post_meta( $id, '_wp_attachment_image_alt', true ),
						'files'    => array_values( array_map( static fn( $entry ) => $entry['rel'], $moved ) ),
						'kept'     => $kept,
						'bytes'    => $item_bytes,
					),
				);
			}

			// Persist recovery metadata before deleting any WordPress media records.
			$recovery = $manifest;
			foreach ( $ready as $row ) {
				$recovery['attachments'][] = $row['payload']; }
			try {
				$this->write_manifest( $stamp, $recovery );
			} catch ( Throwable $error ) {
				foreach ( $ready as $id => $row ) {
					$this->rollback_moved( $row['moved'], $errors, $id ); }
				throw $error;
			}
			$deleted = $this->delete_attachment_records( array_map( static fn( $row ) => $row['post'], $ready ) );
			foreach ( $ready as $id => $row ) {
				if ( ! empty( $deleted[ $id ] ) ) {
					$manifest['attachments'][] = $row['payload'];
					++$ok;
					continue;
				}
				$this->add_error( $errors, 'attachment_delete_failed', __( 'Files moved but media record could not be deleted', 'lucidlab-mpc-cleanup' ), '#' . $id, $id, __( 'The attachment row was not removed. Files are being rolled back.', 'lucidlab-mpc-cleanup' ) );
				$this->rollback_moved( $row['moved'], $errors, $id );
				++$fail;
			}
		} finally {
			if ( function_exists( 'wp_defer_comment_counting' ) ) {
				wp_defer_comment_counting( false );
			}
			if ( function_exists( 'wp_defer_term_counting' ) ) {
				wp_defer_term_counting( false );
			}
			if ( class_exists( 'LucidLab_MPC_Index', false ) ) {
				LucidLab_MPC_Index::end_bulk();
			}
			$this->writable_dirs = array();
		}

		$this->write_manifest( $stamp, $manifest );

		if ( $ok > 0 ) {
			self::record_last_action(
				'quarantine_attachments',
				$stamp,
				array(
					'source'                  => $source,
					'attachments_quarantined' => $ok,
					'attachments_failed'      => $fail,
				)
			);
		}

		$files_total = 0;
		foreach ( (array) ( $manifest['attachments'] ?? array() ) as $attachment ) {
			$files_total += count( (array) ( $attachment['files'] ?? array() ) );
		}
		return array(
			'ok'                => $ok,
			'fail'              => $fail,
			'skip'              => $skip,
			'files_total'       => $files_total,
			'attachments_total' => count( (array) ( $manifest['attachments'] ?? array() ) ),
			'errors'            => array_values( $errors ),
			'fails'             => $this->errors_to_strings( $errors ),
		);
	}

	/**
	 * Dump selected WordPress-prefix tables to SQL inside the quarantine set, then DROP them. Restorable via restore() / Quarantine tab. Never accepts core or MPC own tables.
	 *
	 * @param array<int,string> $tables Tables.
	 * @param string            $stamp  Stamp.
	 * @param string            $source Source.
	 *
	 * @return array{ok:int,fail:int,skip:int,stamp:string,bytes:int,errors:array<int,array<string,string>>}
	 * @throws RuntimeException When the operation fails.
	 */
	public function quarantine_tables( array $tables, string $stamp, string $source = 'dbcleaner' ): array {
		// Enforce the backup gate at the final DROP boundary as well as in the
		// preview and AJAX handlers. Other callers must not bypass it.
		if ( ! class_exists( 'LucidLab_MPC_Backup' ) || ! LucidLab_MPC_Backup::recent_database_backup() ) {
			throw new RuntimeException( __( 'Create a database or full-site backup in the Backup tab before quarantining tables. The backup must be less than 24 hours old.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		global $wpdb;
		$stamp = sanitize_file_name( $stamp );
		if ( '' === $stamp ) {
			$stamp = $this->new_stamp();
		}
		if ( ! $this->ensure_set( $stamp ) ) {
			throw new RuntimeException( __( 'Could not create the quarantine set directory.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$manifest = $this->read_manifest( $stamp );
		if ( empty( $manifest['source'] ) ) {
			$manifest['source'] = $source;
		}
		$manifest['kind'] = 'db_tables';
		$set_dir          = $this->set_dir( $stamp );
		$tables_dir       = $set_dir . '/tables';
		if ( ! is_dir( $tables_dir ) && ! wp_mkdir_p( $tables_dir ) ) {
			throw new RuntimeException( __( 'Could not create the quarantine tables directory.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$this->protect_dir( $set_dir );
		$this->protect_dir( $tables_dir );
		$ok          = 0;
		$fail        = 0;
		$skip        = 0;
		$bytes       = 0;
		$errors      = array();
		$listed      = array();
		$dropped_now = array();
		foreach ( (array) ( $manifest['files'] ?? array() ) as $f ) {
			$listed[ (string) ( $f['rel'] ?? '' ) ] = true;
		}
		$core   = array( 'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'term_relationships', 'term_taxonomy', 'termmeta', 'terms', 'usermeta', 'users', 'blogs', 'blogmeta', 'registration_log', 'signups', 'site', 'sitemeta', 'blog_versions' );
		$own    = array( 'lucidlab_mpc_media_index', 'lucidlab_mpc_rename_log', 'lucidlab_mpc_visual' );
		$prefix = (string) $wpdb->prefix;

		foreach ( $tables as $raw ) {
			$table = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $raw ) ?? '';
			if ( '' === $table || ! str_starts_with( $table, $prefix ) ) {
				++$fail;
				$this->add_error( $errors, 'bad_table', __( 'Invalid table name', 'lucidlab-mpc-cleanup' ), (string) $raw, 0, __( 'Outside the WordPress table prefix.', 'lucidlab-mpc-cleanup' ) );
				continue;
			}
			$suffix = substr( $table, strlen( $prefix ) );
			if ( in_array( $suffix, $core, true ) || in_array( $suffix, $own, true ) ) {
				++$skip;
				$this->add_error( $errors, 'protected_table', __( 'Protected table', 'lucidlab-mpc-cleanup' ), $table, 0, __( 'Core WordPress or Media & Products Cleanup tables cannot be quarantined.', 'lucidlab-mpc-cleanup' ) );
				continue;
			}
			$rel  = 'tables/' . $table . '.sql';
			$dest = $set_dir . '/' . $rel;
			if ( ! empty( $listed[ $rel ] ) && is_file( $dest ) ) {
				++$ok;
				continue;
			}
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( $exists !== $table ) {
				if ( is_file( $dest ) ) {
					++$ok;
					continue;
				}
				++$fail;
				$this->add_error( $errors, 'missing_table', __( 'Table does not exist', 'lucidlab-mpc-cleanup' ), $table, 0, '' );
				continue;
			}
			try {
				$dump_bytes = $this->dump_table_sql( $table, $dest );
			} catch ( Throwable $e ) {
				++$fail;
				$this->add_error( $errors, 'dump_failed', __( 'Could not dump table', 'lucidlab-mpc-cleanup' ), $table, 0, $e->getMessage() );
				if ( is_file( $dest ) ) {
					wp_delete_file( $dest );
				}
				continue;
			}
			$dropped = $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( false === $dropped ) {
				++$fail;
				$this->add_error( $errors, 'drop_failed', __( 'Could not drop table after dump', 'lucidlab-mpc-cleanup' ), $table, 0, (string) $wpdb->last_error );
				// Keep the dump so the operator can still restore; leave the live table if DROP failed.
				continue;
			}
			$manifest['files'][] = array(
				'rel'   => $rel,
				'bytes' => $dump_bytes,
				'table' => $table,
			);
			$manifest['tables']  = array_values(
				array_merge(
					(array) ( $manifest['tables'] ?? array() ),
					array(
						array(
							'table' => $table,
							'rel'   => $rel,
							'bytes' => $dump_bytes,
						),
					)
				)
			);
			$listed[ $rel ]      = true;
			$bytes              += $dump_bytes;
			$dropped_now[]       = $table;
			++$ok;
		}
		$this->write_manifest( $stamp, $manifest );
		// DROP TABLE already reclaims file-per-table space; report that instead of OPTIMIZE.
		$optimize = array();
		foreach ( $dropped_now as $tname ) {
			$optimize[] = array(
				'table'  => $tname,
				'ok'     => true,
				'status' => 'dropped',
				'detail' => __( 'Table dropped; InnoDB file-per-table space is reclaimed without OPTIMIZE.', 'lucidlab-mpc-cleanup' ),
			);
		}
		if ( $ok > 0 ) {
			self::record_last_action(
				'quarantine',
				$stamp,
				array(
					'source'   => $source,
					'ok'       => $ok,
					'fail'     => $fail,
					'bytes'    => $bytes,
					'optimize' => $optimize,
				)
			);
		}
		return array(
			'ok'       => $ok,
			'fail'     => $fail,
			'skip'     => $skip,
			'stamp'    => $stamp,
			'bytes'    => $bytes,
			'errors'   => array_values( $errors ),
			'optimize' => $optimize,
		);
	}

	/**
	 * Dump selected database rows to JSON inside the quarantine set, then DELETE them. Restorable via restore() / Quarantine tab. Never accepts core-protected option names.
	 *
	 * @param array<int,array<string,mixed>> $specs  Specs.
	 * @param string                         $stamp  Stamp.
	 * @param string                         $source Source.
	 *
	 * @return array{ok:int,fail:int,skip:int,stamp:string,bytes:int,errors:array<int,array<string,string>>}
	 * @throws RuntimeException When the operation fails.
	 */
	public function quarantine_rows( array $specs, string $stamp, string $source = 'dbcleaner' ): array {
		$stamp = sanitize_file_name( $stamp );
		if ( '' === $stamp ) {
			$stamp = $this->new_stamp();
		}
		if ( ! $this->ensure_set( $stamp ) ) {
			throw new RuntimeException( __( 'Could not create the quarantine set directory.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$manifest = $this->read_manifest( $stamp );
		if ( empty( $manifest['source'] ) ) {
			$manifest['source'] = $source;
		}
		$manifest['kind'] = 'db_rows';
		$set_dir          = $this->set_dir( $stamp );
		$rows_dir         = $set_dir . '/rows';
		if ( ! is_dir( $rows_dir ) && ! wp_mkdir_p( $rows_dir ) ) {
			throw new RuntimeException( __( 'Could not create the quarantine rows directory.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$this->protect_dir( $set_dir );
		$this->protect_dir( $rows_dir );
		$ok                 = 0;
		$fail               = 0;
		$skip               = 0;
		$bytes              = 0;
		$errors             = array();
		$listed             = array();
		$touched_tables     = array();
		$deleted_rows       = 0;
		$as_deleted_actions = 0;
		$as_deleted_logs    = 0;
		foreach ( (array) ( $manifest['files'] ?? array() ) as $f ) {
			$listed[ (string) ( $f['rel'] ?? '' ) ] = true;
		}

		foreach ( $specs as $spec ) {
			if ( ! is_array( $spec ) ) {
				++$fail;
				continue;
			}
			$finding_id = sanitize_file_name( (string) ( $spec['finding_id'] ?? ( $spec['mode'] ?? 'rows' ) . '-' . wp_generate_password( 6, false ) ) );
			$rel        = 'rows/' . $finding_id . '.json';
			$dest       = $set_dir . '/' . $rel;
			if ( ! empty( $listed[ $rel ] ) && is_file( $dest ) ) {
				++$ok;
				continue;
			}
			try {
				$mode = (string) ( $spec['mode'] ?? '' );
				// Action Scheduler clear / orphan logs: restorable JSON chunks then DELETE (pending/in-progress untouched).
				if ( in_array( $mode, array( 'as_clear_finished', 'as_trim_keep_last', 'as_orphan_logs' ), true ) ) {
					$as_result   = $this->quarantine_as_shrink_spec( $spec, $set_dir, $finding_id );
					$row_count   = (int) ( $as_result['deleted_rows'] ?? 0 );
					$chunk_files = (array) ( $as_result['files'] ?? array() );
					if ( $row_count < 1 && ! $chunk_files ) {
						++$skip;
						if ( is_file( $dest ) ) {
							wp_delete_file( $dest );
						}
						continue;
					}
					$as_deleted_actions += (int) ( $as_result['deleted_actions'] ?? 0 );
					$as_deleted_logs    += (int) ( $as_result['deleted_logs'] ?? 0 );
					foreach ( (array) ( $as_result['tables'] ?? array() ) as $tname ) {
						if ( is_string( $tname ) && '' !== $tname ) {
							$touched_tables[ $tname ] = true;
						}
					}
					$deleted_rows += $row_count;
					foreach ( $chunk_files as $file_meta ) {
						if ( ! is_array( $file_meta ) ) {
							continue;
						}
						$file_rel = (string) ( $file_meta['rel'] ?? '' );
						if ( '' === $file_rel || ! empty( $listed[ $file_rel ] ) ) {
							continue;
						}
						$dump_bytes          = (int) ( $file_meta['bytes'] ?? 0 );
						$manifest['files'][] = array(
							'rel'        => $file_rel,
							'bytes'      => $dump_bytes,
							'finding_id' => $finding_id,
							'category'   => (string) ( $spec['category'] ?? 'action_scheduler' ),
							'label'      => (string) ( $spec['label'] ?? $finding_id ),
							'count'      => (int) ( $file_meta['count'] ?? 0 ),
							'mode'       => $mode,
						);
						$listed[ $file_rel ] = true;
						$bytes              += $dump_bytes;
					}
					++$ok;
					continue;
				}
				$payload = $this->collect_rows_for_spec( $spec );
				if ( empty( $payload['chunks'] ) ) {
					++$skip;
					continue;
				}
				$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( false === $json || false === file_put_contents( $dest, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
					throw new RuntimeException( __( 'Could not write row dump.', 'lucidlab-mpc-cleanup' ) );
				}
				$this->delete_rows_for_payload( $payload );
				$dump_bytes = (int) filesize( $dest );
				$row_count  = 0;
				foreach ( (array) $payload['chunks'] as $chunk ) {
					$row_count += count( (array) ( $chunk['rows'] ?? array() ) );
					$tname      = (string) ( $chunk['table'] ?? '' );
					if ( '' !== $tname && 'cron_patch' !== ( $chunk['delete_mode'] ?? '' ) ) {
						$touched_tables[ $tname ] = true;
					}
				}
				$deleted_rows       += $row_count;
				$manifest['files'][] = array(
					'rel'        => $rel,
					'bytes'      => $dump_bytes,
					'finding_id' => $finding_id,
					'category'   => (string) ( $spec['category'] ?? '' ),
					'label'      => (string) ( $spec['label'] ?? $finding_id ),
					'count'      => $row_count,
				);
				$listed[ $rel ]      = true;
				$bytes              += $dump_bytes;
				++$ok;
			} catch ( Throwable $e ) {
				++$fail;
				$this->add_error( $errors, 'row_quarantine_failed', __( 'Could not quarantine rows', 'lucidlab-mpc-cleanup' ), (string) ( $spec['label'] ?? $finding_id ), 0, $e->getMessage() );
				if ( is_file( $dest ) ) {
					wp_delete_file( $dest );
				}
			}
		}

		$this->write_manifest( $stamp, $manifest );
		$optimize   = array();
		$as_touched = false;
		foreach ( (array) ( $manifest['files'] ?? array() ) as $f ) {
			if ( in_array( (string) ( $f['mode'] ?? '' ), array( 'as_clear_finished', 'as_trim_keep_last', 'as_orphan_logs' ), true ) ) {
				$as_touched = true;
				break;
			}
		}
		if ( $ok > 0 && $touched_tables && ( $deleted_rows >= self::OPTIMIZE_MIN_ROWS || $as_touched ) ) {
			$budget   = $as_touched ? self::OPTIMIZE_AS_BUDGET_SECONDS : 0;
			$optimize = $this->optimize_tables( array_keys( $touched_tables ), $budget );
		} elseif ( $ok > 0 && $touched_tables && $deleted_rows > 0 && $deleted_rows < self::OPTIMIZE_MIN_ROWS ) {
			foreach ( array_keys( $touched_tables ) as $tname ) {
				$optimize[] = array(
					'table'  => $tname,
					'ok'     => true,
					'status' => 'skipped_small',
					'detail' => sprintf(
						/* translators: 1: deleted row count, 2: minimum rows before OPTIMIZE */
						__( 'Skipped OPTIMIZE (%1$d rows deleted; threshold is %2$d).', 'lucidlab-mpc-cleanup' ),
						$deleted_rows,
						self::OPTIMIZE_MIN_ROWS
					),
				);
			}
		}
		if ( $ok > 0 ) {
			self::record_last_action(
				'quarantine',
				$stamp,
				array(
					'source'   => $source,
					'kind'     => 'db_rows',
					'ok'       => $ok,
					'fail'     => $fail,
					'bytes'    => $bytes,
					'optimize' => $optimize,
				)
			);
		}
		return array(
			'ok'              => $ok,
			'fail'            => $fail,
			'skip'            => $skip,
			'stamp'           => $stamp,
			'bytes'           => $bytes,
			'errors'          => array_values( $errors ),
			'optimize'        => $optimize,
			'deleted_rows'    => $deleted_rows,
			'deleted_actions' => $as_deleted_actions,
			'deleted_logs'    => $as_deleted_logs,
		);
	}

	/**
	 * Best-effort OPTIMIZE TABLE (InnoDB rebuild) with a soft time budget. Never throws; lock waits / timeouts are reported per table.
	 *
	 * @param array<int,string> $tables         Tables.
	 * @param int               $budget_seconds Budget seconds.
	 *
	 * @return array<int,array{table:string,ok:bool,status:string,detail:string}>
	 */
	public function optimize_tables( array $tables, int $budget_seconds = 0 ): array {
		global $wpdb;
		$budget = $budget_seconds > 0 ? $budget_seconds : self::OPTIMIZE_BUDGET_SECONDS;
		$start  = microtime( true );
		$out    = array();
		$seen   = array();
		$prefix = (string) $wpdb->prefix;
		// Short lock waits so a busy table cannot stall the whole request.
		$wpdb->query( 'SET SESSION lock_wait_timeout = 3' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 3' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		// Zero-date / strict modes break InnoDB rebuild on older WP columns (post_date, etc.).
		$prev_mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$relaxed   = preg_replace( '/\bNO_ZERO_(?:IN_)?DATE\b,?|,?\bNO_ZERO_(?:IN_)?DATE\b/', '', $prev_mode ) ?? $prev_mode;
		$relaxed   = preg_replace( '/\bSTRICT_TRANS_TABLES\b,?|,?\bSTRICT_TRANS_TABLES\b/', '', $relaxed ) ?? $relaxed;
		$relaxed   = trim( (string) $relaxed, ',' );
		$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $relaxed ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.

		try {
			foreach ( $tables as $raw ) {
				if ( ( microtime( true ) - $start ) >= $budget ) {
					$out[] = array(
						'table'  => (string) $raw,
						'ok'     => false,
						'status' => 'budget',
						'detail' => __( 'Skipped: OPTIMIZE time budget reached.', 'lucidlab-mpc-cleanup' ),
					);
					continue;
				}
				$table = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $raw ) ?? '';
				if ( '' === $table || isset( $seen[ $table ] ) ) {
					continue;
				}
				$seen[ $table ] = true;
				if ( ! str_starts_with( $table, $prefix ) ) {
					$out[] = array(
						'table'  => $table,
						'ok'     => false,
						'status' => 'rejected',
						'detail' => __( 'Outside the WordPress table prefix.', 'lucidlab-mpc-cleanup' ),
					);
					continue;
				}
				$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				if ( $exists !== $table ) {
					$out[] = array(
						'table'  => $table,
						'ok'     => true,
						'status' => 'missing',
						'detail' => __( 'Table no longer exists (already dropped).', 'lucidlab-mpc-cleanup' ),
					);
					continue;
				}
				$before = microtime( true );
				// OPTIMIZE returns a result set — parse Msg_type; query() alone hides rebuild errors.
				$rows    = $wpdb->get_results( $wpdb->prepare( 'OPTIMIZE TABLE %i', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$elapsed = round( ( microtime( true ) - $before ) * 1000 );
				$err     = (string) $wpdb->last_error;
				foreach ( (array) $rows as $msg ) {
					if ( 'error' === strtolower( (string) ( $msg['Msg_type'] ?? '' ) ) ) {
						$err = (string) ( $msg['Msg_text'] ?? $err );
						break;
					}
				}
				if ( null === $rows || '' !== $err ) {
					$status = ( false !== stripos( $err, 'lock' ) || false !== stripos( $err, 'timeout' ) ) ? 'lock' : 'error';
					$out[]  = array(
						'table'  => $table,
						'ok'     => false,
						'status' => $status,
						'detail' => '' !== $err ? $err : __( 'OPTIMIZE TABLE failed.', 'lucidlab-mpc-cleanup' ),
					);
					continue;
				}
				$out[] = array(
					'table'  => $table,
					'ok'     => true,
					'status' => 'optimized',
					'detail' => sprintf(
						/* translators: %d: milliseconds */
						__( 'Optimized in %d ms.', 'lucidlab-mpc-cleanup' ),
						$elapsed
					),
				);
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $prev_mode ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		}
		return $out;
	}

	/**
	 * Dump finished Action Scheduler rows (or orphan logs) into restorable JSON chunks, then DELETE. Never touches pending / in-progress actions.
	 *
	 * @param array<string,mixed> $spec       Spec.
	 * @param string              $set_dir    Set dir.
	 * @param string              $finding_id Finding id.
	 *
	 * @return array{wrote:bool,deleted_rows:int,deleted_actions:int,deleted_logs:int,tables:array<int,string>,files:array<int,array<string,mixed>>}
	 * @throws RuntimeException When the operation fails.
	 */
	private function quarantine_as_shrink_spec( array $spec, string $set_dir, string $finding_id ): array {
		global $wpdb;
		$mode            = (string) ( $spec['mode'] ?? '' );
		$prefix          = (string) $wpdb->prefix;
		$actions_table   = $prefix . 'actionscheduler_actions';
		$logs_table      = $prefix . 'actionscheduler_logs';
		$terminal        = array( 'complete', 'failed', 'canceled' );
		$status_ph       = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
		$deleted_actions = 0;
		$deleted_logs    = 0;
		$tables          = array();
		$files           = array();
		$chunk_n         = 0;
		$dump_batch      = self::AS_DUMP_BATCH;
		$finding_id      = sanitize_file_name( $finding_id );
		if ( '' === $finding_id ) {
			$finding_id = 'as-shrink';
		}
		$rows_dir = $set_dir . '/rows';
		if ( ! is_dir( $rows_dir ) && ! wp_mkdir_p( $rows_dir ) ) {
			throw new RuntimeException( __( 'Could not create the quarantine rows directory.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$this->protect_dir( $set_dir );
		$this->protect_dir( $rows_dir );

		$write_chunk = function ( array $chunks ) use ( &$files, &$chunk_n, $rows_dir, $finding_id ): int {
			$row_count = 0;
			foreach ( $chunks as $chunk ) {
				$row_count += count( (array) ( $chunk['rows'] ?? array() ) );
			}
			if ( $row_count < 1 ) {
				return 0;
			}
			++$chunk_n;
			$rel     = 'rows/' . $finding_id . '-chunk-' . str_pad( (string) $chunk_n, 4, '0', STR_PAD_LEFT ) . '.json';
			$dest    = $rows_dir . '/' . basename( $rel );
			$payload = array(
				'version' => 1,
				'mode'    => 'as_restorable',
				'chunks'  => array_values( $chunks ),
			);
			$json    = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $json || false === file_put_contents( $dest, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				throw new RuntimeException( __( 'Could not write Action Scheduler quarantine chunk.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$files[] = array(
				'rel'   => $rel,
				'bytes' => (int) filesize( $dest ),
				'count' => $row_count,
			);
			return $row_count;
		};

		if ( in_array( $mode, array( 'as_clear_finished', 'as_trim_keep_last' ), true ) ) {
			$actions_table = $this->assert_row_table( (string) ( $spec['table'] ?? $actions_table ) );
			$logs_table    = $this->assert_row_table( (string) ( $spec['logs_table'] ?? $logs_table ) );
			$tables[]      = $actions_table;
			$has_logs      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) ) === $logs_table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( $has_logs ) {
				$tables[] = $logs_table;
			}
			// as_clear_finished: keep=0 (empty all terminal). Legacy as_trim_keep_last may still pass keep.
			$keep     = 'as_clear_finished' === $mode
				? 0
				: max( 0, min( 100, (int) ( $spec['keep'] ?? 0 ) ) );
			$keep_ids = array();
			if ( $keep > 0 ) {
				$keep_ids = array_values(
					array_filter(
						array_map(
							'intval',
							(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
								$wpdb->prepare( "SELECT action_id FROM %i WHERE status IN ({$status_ph}) ORDER BY action_id DESC LIMIT %d", array_merge( array( $actions_table ), $terminal, array( $keep ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() placeholder list built with array_fill().
							)
						)
					)
				);
			}
			$keep_ids   = $keep_ids ? $keep_ids : array( 0 );
			$exclude    = $keep > 0 ? ' AND action_id NOT IN (' . implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) ) . ')' : '';
			$exclude_by = $keep > 0 ? $keep_ids : array();

			do {
				$ids = array_values(
					array_filter(
						array_map(
							'intval',
							(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
								$wpdb->prepare( "SELECT action_id FROM %i WHERE status IN ({$status_ph}){$exclude} ORDER BY action_id ASC LIMIT %d", array_merge( array( $actions_table ), $terminal, $exclude_by, array( $dump_batch ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() placeholder list built with array_fill().
							)
						)
					)
				);
				if ( ! $ids ) {
					break;
				}
				$in          = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$action_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					$wpdb->prepare( "SELECT * FROM %i WHERE status IN ({$status_ph}) AND action_id IN ({$in})", array_merge( array( $actions_table ), $terminal, $ids ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() placeholder list built with array_fill().
					ARRAY_A
				);
				$action_rows = is_array( $action_rows ) ? $action_rows : array();
				$chunks      = array();
				if ( $action_rows ) {
					$chunks[] = array(
						'table' => $actions_table,
						'pk'    => 'action_id',
						'rows'  => $action_rows,
					);
				}
				if ( $has_logs ) {
					$log_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
						$wpdb->prepare( "SELECT * FROM %i WHERE action_id IN ({$in})", array_merge( array( $logs_table ), $ids ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() placeholder list built with array_fill().
						ARRAY_A
					);
					$log_rows = is_array( $log_rows ) ? $log_rows : array();
					if ( $log_rows ) {
						$chunks[] = array(
							'table' => $logs_table,
							'pk'    => 'log_id',
							'rows'  => $log_rows,
						);
					}
				}
				$write_chunk( $chunks );
				if ( $has_logs ) {
					$n = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE action_id IN ({$in})", array_merge( array( $logs_table ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					if ( false !== $n ) {
						$deleted_logs += (int) $n;
					}
				}
				$n = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					$wpdb->prepare( "DELETE FROM %i WHERE status IN ({$status_ph}) AND action_id IN ({$in})", array_merge( array( $actions_table ), $terminal, $ids ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() placeholder list built with array_fill().
				);
				if ( false === $n || $n < 1 ) {
					break;
				}
				$deleted_actions += (int) $n;
				$ids_seen         = count( $ids );
			} while ( $ids_seen >= $dump_batch );

			// Sweep leftover orphan logs into restorable chunks (safety net).
			if ( $has_logs ) {
				$sweep         = $this->quarantine_orphan_as_logs( $logs_table, $actions_table, $write_chunk, $dump_batch );
				$deleted_logs += (int) ( $sweep['deleted_logs'] ?? 0 );
			}
		} elseif ( 'as_orphan_logs' === $mode ) {
			$logs_table = $this->assert_row_table( (string) ( $spec['table'] ?? $logs_table ) );
			$tables[]   = $logs_table;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions_table ) ) !== $actions_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				throw new RuntimeException( __( 'Action Scheduler actions table is missing.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$sweep         = $this->quarantine_orphan_as_logs( $logs_table, $actions_table, $write_chunk, $dump_batch );
			$deleted_logs += (int) ( $sweep['deleted_logs'] ?? 0 );
		} else {
			throw new RuntimeException( sprintf( /* translators: %s: mode name. */ __( 'Unknown Action Scheduler shrink mode: %s', 'lucidlab-mpc-cleanup' ), $mode ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}

		$deleted_rows = $deleted_actions + $deleted_logs;
		return array(
			'wrote'           => $deleted_rows > 0 || ! empty( $files ),
			'deleted_rows'    => $deleted_rows,
			'deleted_actions' => $deleted_actions,
			'deleted_logs'    => $deleted_logs,
			'tables'          => $tables,
			'files'           => $files,
		);
	}

	/**
	 * Dump + delete Action Scheduler logs with no matching action (batched).
	 *
	 * @param string   $logs_table    Logs table.
	 * @param string   $actions_table Actions table.
	 * @param callable $write_chunk   Write chunk.
	 * @param int      $batch         Batch.
	 *
	 * @return array{deleted_logs:int}
	 */
	private function quarantine_orphan_as_logs( string $logs_table, string $actions_table, callable $write_chunk, int $batch ): array {
		global $wpdb;
		$deleted = 0;
		$batch   = max( 1, min( 5000, $batch ) );
		do {
			$log_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare( 'SELECT l.* FROM %i l LEFT JOIN %i a ON a.action_id = l.action_id WHERE a.action_id IS NULL ORDER BY l.log_id ASC LIMIT %d', $logs_table, $actions_table, $batch ),
				ARRAY_A
			);
			$log_rows = is_array( $log_rows ) ? $log_rows : array();
			if ( ! $log_rows ) {
				break;
			}
			$ids = array_values(
				array_filter(
					array_map(
						static fn( $row ) => (int) ( $row['log_id'] ?? 0 ),
						$log_rows
					)
				)
			);
			if ( ! $ids ) {
				break;
			}
			$write_chunk(
				array(
					array(
						'table' => $logs_table,
						'pk'    => 'log_id',
						'rows'  => $log_rows,
					),
				)
			);
			$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$n  = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE log_id IN ({$in})", array_merge( array( $logs_table ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( false === $n || $n < 1 ) {
				break;
			}
			$deleted += (int) $n;
			$ids_seen = count( $ids );
		} while ( $ids_seen >= $batch );
		return array( 'deleted_logs' => $deleted );
	}

	/**
	 * Delete Action Scheduler logs with no matching action (batched by log_id — portable LIMIT).
	 *
	 * @param string $logs_table    Logs table.
	 * @param string $actions_table Actions table.
	 * @param int    $batch         Batch.
	 *
	 * @return int
	 */
	private function delete_orphan_as_logs( string $logs_table, string $actions_table, int $batch ): int {
		global $wpdb;
		$deleted = 0;
		$batch   = max( 1, min( 5000, $batch ) );
		do {
			$ids = array_map(
				'intval',
				(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					$wpdb->prepare( 'SELECT l.log_id FROM %i l LEFT JOIN %i a ON a.action_id = l.action_id WHERE a.action_id IS NULL ORDER BY l.log_id ASC LIMIT %d', $logs_table, $actions_table, $batch )
				)
			);
			$ids = array_values( array_filter( $ids ) );
			if ( ! $ids ) {
				break;
			}
			$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$n  = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE log_id IN ({$in})", array_merge( array( $logs_table ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( false === $n || $n < 1 ) {
				break;
			}
			$deleted += (int) $n;
			$ids_seen = count( $ids );
		} while ( $ids_seen >= $batch );
		return $deleted;
	}

	/**
	 * Collect rows for spec.
	 *
	 * @param array<string,mixed> $spec Spec.
	 *
	 * @return array{version:int,chunks:array<int,array{table:string,pk:string,rows:array<int,array<string,mixed>>}>}
	 * @throws RuntimeException When the operation fails.
	 */
	private function collect_rows_for_spec( array $spec ): array {
		global $wpdb;
		$mode   = (string) ( $spec['mode'] ?? '' );
		$chunks = array();
		if ( 'options_by_id' === $mode || 'meta_by_id' === $mode ) {
			$table = $this->assert_core_row_table( (string) ( $spec['table'] ?? '' ) );
			$pk    = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $spec['pk'] ?? '' ) ) ?? '';
			$ids   = array_values( array_filter( array_map( 'intval', (array) ( $spec['ids'] ?? array() ) ) ) );
			if ( '' === $pk || ! $ids ) {
				return array(
					'version' => 1,
					'chunks'  => array(),
				);
			}
			$rows = array();
			foreach ( array_chunk( $ids, 200 ) as $chunk_ids ) {
				$in  = implode( ',', array_fill( 0, count( $chunk_ids ), '%d' ) );
				$got = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE %i IN ($in)", array_merge( array( $table, $pk ), $chunk_ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				if ( is_array( $got ) ) {
					foreach ( $got as $row ) {
						if ( $wpdb->options === $table && $this->is_protected_option_name( (string) ( $row['option_name'] ?? '' ) ) ) {
							continue;
						}
						$rows[] = $row;
					}
				}
			}
			if ( $rows ) {
				$chunks[] = array(
					'table' => $table,
					'pk'    => $pk,
					'rows'  => $rows,
				);
			}
			return array(
				'version' => 1,
				'chunks'  => $chunks,
			);
		}
		if ( 'meta_by_prefix' === $mode ) {
			$table   = $this->assert_core_row_table( (string) ( $spec['table'] ?? '' ) );
			$pk      = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $spec['pk'] ?? 'meta_id' ) ) ?? 'meta_id';
			$key_col = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $spec['key_col'] ?? 'meta_key' ) ) ?? 'meta_key';
			$prefix  = (string) ( $spec['prefix'] ?? '' );
			$limit   = max( 1, min( 20000, (int) ( $spec['limit'] ?? 5000 ) ) );
			if ( strlen( $prefix ) < 3 ) {
				return array(
					'version' => 1,
					'chunks'  => array(),
				);
			}
			$got  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare(
					'SELECT * FROM %i WHERE %i LIKE %s LIMIT %d',
					$table,
					$key_col,
					$wpdb->esc_like( $prefix ) . '%',
					$limit
				),
				ARRAY_A
			);
			$rows = is_array( $got ) ? $got : array();
			if ( $rows ) {
				$chunks[] = array(
					'table' => $table,
					'pk'    => $pk,
					'rows'  => $rows,
				);
			}
			return array(
				'version' => 1,
				'chunks'  => $chunks,
			);
		}
		if ( 'orphan_meta' === $mode ) {
			$table     = $this->assert_core_row_table( (string) ( $spec['table'] ?? '' ) );
			$parent    = $this->assert_core_row_table( (string) ( $spec['parent_table'] ?? '' ) );
			$pk        = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $spec['pk'] ?? 'meta_id' ) ) ?? 'meta_id';
			$fk        = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $spec['fk'] ?? '' ) ) ?? '';
			$parent_pk = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $spec['parent_pk'] ?? '' ) ) ?? '';
			$limit     = max( 1, min( 20000, (int) ( $spec['limit'] ?? 5000 ) ) );
			if ( '' === $fk || '' === $parent_pk ) {
				return array(
					'version' => 1,
					'chunks'  => array(),
				);
			}
			$got  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare( 'SELECT m.* FROM %i m LEFT JOIN %i p ON p.%i = m.%i WHERE p.%i IS NULL LIMIT %d', $table, $parent, $parent_pk, $fk, $parent_pk, $limit ),
				ARRAY_A
			);
			$rows = is_array( $got ) ? $got : array();
			if ( $rows ) {
				$chunks[] = array(
					'table' => $table,
					'pk'    => $pk,
					'rows'  => $rows,
				);
			}
			return array(
				'version' => 1,
				'chunks'  => $chunks,
			);
		}
		if ( 'posts_by_type' === $mode ) {
			$post_type = sanitize_key( (string) ( $spec['post_type'] ?? '' ) );
			$limit     = max( 1, min( 5000, (int) ( $spec['limit'] ?? 500 ) ) );
			$core      = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );
			if ( '' === $post_type || in_array( $post_type, $core, true ) || in_array( $post_type, get_post_types(), true ) ) {
				throw new RuntimeException( __( 'Refusing to quarantine registered or core post types.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$posts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_type = %s LIMIT %d", $post_type, $limit ),
				ARRAY_A
			);
			$posts = is_array( $posts ) ? $posts : array();
			if ( ! $posts ) {
				return array(
					'version' => 1,
					'chunks'  => array(),
				);
			}
			return array(
				'version' => 1,
				'chunks'  => $this->chunks_for_posts( $posts ),
			);
		}
		if ( 'posts_by_id' === $mode ) {
			$ids            = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $spec['ids'] ?? array() ) ) ) ) );
			$allow_types    = array_values( array_filter( array_map( 'sanitize_key', (array) ( $spec['allow_types'] ?? array() ) ) ) );
			$allow_statuses = array_values( array_filter( array_map( 'sanitize_key', (array) ( $spec['allow_statuses'] ?? array() ) ) ) );
			if ( ! $ids ) {
				return array(
					'version' => 1,
					'chunks'  => array(),
				);
			}
			$posts = array();
			foreach ( array_chunk( $ids, 200 ) as $chunk_ids ) {
				$in  = implode( ',', array_fill( 0, count( $chunk_ids ), '%d' ) );
				$got = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID IN ($in)", $chunk_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				if ( ! is_array( $got ) ) {
					continue;
				}
				foreach ( $got as $row ) {
					$type   = sanitize_key( (string) ( $row['post_type'] ?? '' ) );
					$status = sanitize_key( (string) ( $row['post_status'] ?? '' ) );
					if ( $allow_types && ! in_array( $type, $allow_types, true ) ) {
						continue;
					}
					if ( $allow_statuses && ! in_array( $status, $allow_statuses, true ) ) {
						continue;
					}
					// Never quarantine live published content via this mode.
					if ( in_array( $status, array( 'publish', 'private', 'future', 'pending' ), true ) && 'revision' !== $type && 'oembed_cache' !== $type ) {
						continue;
					}
					$posts[] = $row;
				}
			}
			if ( ! $posts ) {
				return array(
					'version' => 1,
					'chunks'  => array(),
				);
			}
			return array(
				'version' => 1,
				'chunks'  => $this->chunks_for_posts( $posts ),
			);
		}
		if ( 'rows_by_id' === $mode ) {
			$table = $this->assert_row_table( (string) ( $spec['table'] ?? '' ) );
			$pk    = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $spec['pk'] ?? '' ) ) ?? '';
			$ids   = array_values( array_filter( array_map( 'intval', (array) ( $spec['ids'] ?? array() ) ) ) );
			if ( '' === $pk || ! $ids ) {
				return array(
					'version' => 1,
					'chunks'  => array(),
				);
			}
			$rows = array();
			foreach ( array_chunk( $ids, 200 ) as $chunk_ids ) {
				$in  = implode( ',', array_fill( 0, count( $chunk_ids ), '%d' ) );
				$got = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE %i IN ($in)", array_merge( array( $table, $pk ), $chunk_ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				if ( is_array( $got ) ) {
					$rows = array_merge( $rows, $got );
				}
			}
			if ( ! $rows ) {
				return array(
					'version' => 1,
					'chunks'  => array(),
				);
			}
			$chunks[] = array(
				'table' => $table,
				'pk'    => $pk,
				'rows'  => $rows,
			);
			// Optional related child rows (e.g. Action Scheduler logs).
			$child_table = (string) ( $spec['child_table'] ?? '' );
			$child_fk    = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $spec['child_fk'] ?? '' ) ) ?? '';
			$child_pk    = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $spec['child_pk'] ?? '' ) ) ?? '';
			if ( $child_table && $child_fk && $child_pk ) {
				$child_table = $this->assert_row_table( $child_table );
				$parent_ids  = array_values( array_unique( array_filter( array_map( static fn( $r ) => (int) ( $r[ $pk ] ?? 0 ), $rows ) ) ) );
				$child_rows  = array();
				foreach ( array_chunk( $parent_ids, 200 ) as $chunk_ids ) {
					$in  = implode( ',', array_fill( 0, count( $chunk_ids ), '%d' ) );
					$got = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE %i IN ($in)", array_merge( array( $child_table, $child_fk ), $chunk_ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					if ( is_array( $got ) ) {
						$child_rows = array_merge( $child_rows, $got );
					}
				}
				if ( $child_rows ) {
					$chunks[] = array(
						'table' => $child_table,
						'pk'    => $child_pk,
						'rows'  => $child_rows,
					);
				}
			}
			// Optional extra ID sets (e.g. Action Scheduler claims linked by claim_id).
			foreach ( (array) ( $spec['extra'] ?? array() ) as $extra ) {
				if ( ! is_array( $extra ) ) {
					continue;
				}
				$ex_table = (string) ( $extra['table'] ?? '' );
				$ex_pk    = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $extra['pk'] ?? '' ) ) ?? '';
				$ex_ids   = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $extra['ids'] ?? array() ) ) ) ) );
				if ( '' === $ex_table || '' === $ex_pk || ! $ex_ids ) {
					continue;
				}
				$ex_table = $this->assert_row_table( $ex_table );
				$ex_rows  = array();
				foreach ( array_chunk( $ex_ids, 200 ) as $chunk_ids ) {
					$in  = implode( ',', array_fill( 0, count( $chunk_ids ), '%d' ) );
					$got = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE %i IN ($in)", array_merge( array( $ex_table, $ex_pk ), $chunk_ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					if ( is_array( $got ) ) {
						$ex_rows = array_merge( $ex_rows, $got );
					}
				}
				if ( $ex_rows ) {
					$chunks[] = array(
						'table' => $ex_table,
						'pk'    => $ex_pk,
						'rows'  => $ex_rows,
					);
				}
			}
			return array(
				'version' => 1,
				'chunks'  => $chunks,
			);
		}
		if ( 'comments_by_id' === $mode ) {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $spec['ids'] ?? array() ) ) ) ) );
			if ( ! $ids ) {
				return array(
					'version' => 1,
					'chunks'  => array(),
				);
			}
			$allow_approved = array_values( array_filter( array_map( 'strval', (array) ( $spec['allow_approved'] ?? array( 'spam', 'trash' ) ) ) ) );
			$allow_types    = array_values( array_filter( array_map( 'sanitize_key', (array) ( $spec['allow_types'] ?? array() ) ) ) );
			$comments       = array();
			foreach ( array_chunk( $ids, 200 ) as $chunk_ids ) {
				$in  = implode( ',', array_fill( 0, count( $chunk_ids ), '%d' ) );
				$got = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE comment_ID IN ($in)", $chunk_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				if ( ! is_array( $got ) ) {
					continue;
				}
				foreach ( $got as $row ) {
					$approved = (string) ( $row['comment_approved'] ?? '' );
					$type     = sanitize_key( (string) ( $row['comment_type'] ?? '' ) );
					if ( $allow_types ) {
						if ( ! in_array( $type, $allow_types, true ) ) {
							continue;
						}
					} elseif ( $allow_approved && ! in_array( $approved, $allow_approved, true ) ) {
						continue;
					}
					$comments[] = $row;
				}
			}
			if ( ! $comments ) {
				return array(
					'version' => 1,
					'chunks'  => array(),
				);
			}
			$chunks[] = array(
				'table' => $wpdb->comments,
				'pk'    => 'comment_ID',
				'rows'  => $comments,
			);
			$cids     = array_map( static fn( $r ) => (int) ( $r['comment_ID'] ?? 0 ), $comments );
			foreach ( array_chunk( $cids, 200 ) as $chunk_ids ) {
				$in   = implode( ',', array_fill( 0, count( $chunk_ids ), '%d' ) );
				$meta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->commentmeta} WHERE comment_id IN ($in)", $chunk_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				if ( is_array( $meta ) && $meta ) {
					$chunks[] = array(
						'table' => $wpdb->commentmeta,
						'pk'    => 'meta_id',
						'rows'  => $meta,
					);
				}
			}
			return array(
				'version' => 1,
				'chunks'  => $chunks,
			);
		}
		if ( 'orphan_term_relationships' === $mode ) {
			$table = $this->assert_row_table( (string) ( $spec['table'] ?? $wpdb->term_relationships ) );
			$limit = max( 1, min( 20000, (int) ( $spec['limit'] ?? 5000 ) ) );
			$got   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				"SELECT tr.* FROM {$wpdb->term_relationships} tr
				LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE p.ID IS NULL OR tt.term_taxonomy_id IS NULL
				LIMIT {$limit}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			$rows  = is_array( $got ) ? $got : array();
			// Skip product-attribute relationships when the product (object) still exists — only drop true orphans.
			$filtered = array();
			foreach ( $rows as $row ) {
				$tt_id = (int) ( $row['term_taxonomy_id'] ?? 0 );
				$obj   = (int) ( $row['object_id'] ?? 0 );
				$tax   = $tt_id ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt_id ) ) : ''; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				if ( $tax && str_starts_with( $tax, 'pa_' ) ) {
					$post_exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $obj ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					if ( $post_exists ) {
						continue;
					}
				}
				$filtered[] = $row;
			}
			if ( $filtered ) {
				$chunks[] = array(
					'table'       => $table,
					'pk'          => 'object_id',
					'rows'        => $filtered,
					'delete_mode' => 'composite',
				);
			}
			return array(
				'version' => 1,
				'chunks'  => $chunks,
			);
		}
		if ( 'cron_hooks' === $mode ) {
			$hooks  = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $spec['hooks'] ?? array() ) ) ) ) );
			$events = array();
			foreach ( (array) ( $spec['remove_events'] ?? array() ) as $ev ) {
				if ( ! is_array( $ev ) ) {
					continue;
				}
				$ts   = (int) ( $ev['timestamp'] ?? 0 );
				$hook = (string) ( $ev['hook'] ?? '' );
				$hash = (string) ( $ev['hash'] ?? '' );
				if ( $ts < 1 || '' === $hook || '' === $hash ) {
					continue;
				}
				$events[] = array(
					'timestamp' => $ts,
					'hook'      => $hook,
					'hash'      => $hash,
				);
			}
			if ( ! $hooks && ! $events ) {
				return array(
					'version' => 1,
					'chunks'  => array(),
				);
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->options} WHERE option_name = %s", 'cron' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( ! is_array( $row ) ) {
				return array(
					'version' => 1,
					'chunks'  => array(),
				);
			}
			$chunks[] = array(
				'table'         => $wpdb->options,
				'pk'            => 'option_id',
				'rows'          => array( $row ),
				'delete_mode'   => 'cron_patch',
				'remove_hooks'  => $hooks,
				'remove_events' => $events,
			);
			return array(
				'version' => 1,
				'chunks'  => $chunks,
			);
		}
		throw new RuntimeException( sprintf( /* translators: %s: mode name. */ __( 'Unknown row quarantine mode: %s', 'lucidlab-mpc-cleanup' ), $mode ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
	}

	/**
	 * Chunks for posts.
	 *
	 * @param array<int,array<string,mixed>> $posts Posts.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function chunks_for_posts( array $posts ): array {
		global $wpdb;
		$chunks   = array();
		$chunks[] = array(
			'table' => $wpdb->posts,
			'pk'    => 'ID',
			'rows'  => $posts,
		);
		$ids      = array_values( array_filter( array_map( static fn( $r ) => (int) ( $r['ID'] ?? 0 ), $posts ) ) );
		foreach ( array_chunk( $ids, 200 ) as $chunk_ids ) {
			$in   = implode( ',', array_fill( 0, count( $chunk_ids ), '%d' ) );
			$meta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ($in)", $chunk_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( is_array( $meta ) && $meta ) {
				$chunks[] = array(
					'table' => $wpdb->postmeta,
					'pk'    => 'meta_id',
					'rows'  => $meta,
				);
			}
			$rels = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->term_relationships} WHERE object_id IN ($in)", $chunk_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifiers are internal table names; values are prepared; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( is_array( $rels ) && $rels ) {
				$chunks[] = array(
					'table'       => $wpdb->term_relationships,
					'pk'          => 'object_id',
					'rows'        => $rels,
					'delete_mode' => 'composite',
				);
			}
		}
		return $chunks;
	}

	/**
	 * Delete rows for payload.
	 *
	 * @param array{chunks:array<int,array<string,mixed>>} $payload Payload.
	 */
	private function delete_rows_for_payload( array $payload ): void {
		global $wpdb;
		foreach ( (array) ( $payload['chunks'] ?? array() ) as $chunk ) {
			$table = $this->assert_row_table( (string) ( $chunk['table'] ?? '' ) );
			$pk    = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $chunk['pk'] ?? '' ) ) ?? '';
			$rows  = (array) ( $chunk['rows'] ?? array() );
			if ( 'cron_patch' === ( $chunk['delete_mode'] ?? '' ) ) {
				$hooks  = array_fill_keys( array_map( 'strval', (array) ( $chunk['remove_hooks'] ?? array() ) ), true );
				$events = array();
				foreach ( (array) ( $chunk['remove_events'] ?? array() ) as $ev ) {
					if ( ! is_array( $ev ) ) {
						continue;
					}
					$ts   = (int) ( $ev['timestamp'] ?? 0 );
					$hook = (string) ( $ev['hook'] ?? '' );
					$hash = (string) ( $ev['hash'] ?? '' );
					if ( $ts > 0 && '' !== $hook && '' !== $hash ) {
						$events[ $ts . "\0" . $hook . "\0" . $hash ] = array( $ts, $hook, $hash );
					}
				}
				if ( ! $hooks && ! $events ) {
					continue;
				}
				$cron = get_option( 'cron' );
				if ( ! is_array( $cron ) ) {
					continue;
				}
				$changed = false;
				foreach ( $events as $triple ) {
					[ $ts, $hook, $hash ] = $triple;
					if ( isset( $cron[ $ts ][ $hook ][ $hash ] ) ) {
						unset( $cron[ $ts ][ $hook ][ $hash ] );
						$changed = true;
						if ( empty( $cron[ $ts ][ $hook ] ) ) {
							unset( $cron[ $ts ][ $hook ] );
						}
						if ( empty( $cron[ $ts ] ) ) {
							unset( $cron[ $ts ] );
						}
					}
				}
				if ( $hooks ) {
					foreach ( $cron as $timestamp => $hooks_at ) {
						if ( 'version' === $timestamp || ! is_array( $hooks_at ) ) {
							continue;
						}
						foreach ( array_keys( $hooks_at ) as $hook ) {
							if ( isset( $hooks[ (string) $hook ] ) ) {
								unset( $cron[ $timestamp ][ $hook ] );
								$changed = true;
							}
						}
						if ( empty( $cron[ $timestamp ] ) ) {
							unset( $cron[ $timestamp ] );
						}
					}
				}
				if ( $changed ) {
					update_option( 'cron', $cron );
				}
				continue;
			}
			if ( 'composite' === ( $chunk['delete_mode'] ?? '' ) && $wpdb->term_relationships === $table ) {
				foreach ( $rows as $row ) {
					$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
						$table,
						array(
							'object_id'        => (int) ( $row['object_id'] ?? 0 ),
							'term_taxonomy_id' => (int) ( $row['term_taxonomy_id'] ?? 0 ),
						),
						array( '%d', '%d' )
					);
				}
				continue;
			}
			if ( '' === $pk ) {
				continue;
			}
			$ids = array();
			foreach ( $rows as $row ) {
				if ( $wpdb->options === $table && $this->is_protected_option_name( (string) ( $row['option_name'] ?? '' ) ) ) {
					// cron_patch dumps the cron option; never DELETE it via pk path.
					continue;
				}
				$ids[] = (int) ( $row[ $pk ] ?? 0 );
			}
			$ids = array_values( array_unique( array_filter( $ids ) ) );
			foreach ( array_chunk( $ids, 200 ) as $chunk_ids ) {
				$in = implode( ',', array_fill( 0, count( $chunk_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $in is a list of %d placeholders; identifiers use %i; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE %i IN ({$in})", array_merge( array( $table, $pk ), array_map( 'intval', $chunk_ids ) ) ) );
			}
		}
	}

	/**
	 * Assert core row table.
	 *
	 * @param string $table Table.
	 *
	 * @return string
	 */
	private function assert_core_row_table( string $table ): string {
		return $this->assert_row_table( $table );
	}

	/**
	 * Core WP row tables plus a small whitelist of safe cleanup tables (AS / WC / SEO / Elementor leftovers).
	 *
	 * @param string $table Table.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return string
	 */
	private function assert_row_table( string $table ): string {
		global $wpdb;
		$table  = preg_replace( '/[^A-Za-z0-9_]/', '', $table ) ?? '';
		$prefix = (string) $wpdb->prefix;
		if ( '' === $table || ! str_starts_with( $table, $prefix ) ) {
			throw new RuntimeException( __( 'Table is outside the WordPress prefix scope.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$allowed = array(
			$wpdb->options,
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->usermeta,
			$wpdb->termmeta,
			$wpdb->comments,
			$wpdb->commentmeta,
			$wpdb->term_relationships,
			$prefix . 'actionscheduler_actions',
			$prefix . 'actionscheduler_logs',
			$prefix . 'actionscheduler_claims',
			$prefix . 'woocommerce_sessions',
			$prefix . 'wc_download_log',
			$prefix . 'woocommerce_downloadable_product_permissions',
			$prefix . 'yoast_indexable',
			$prefix . 'yoast_indexable_hierarchy',
			$prefix . 'yoast_seo_links',
			$prefix . 'e_events',
		);
		if ( ! in_array( $table, $allowed, true ) ) {
			throw new RuntimeException( __( 'Row quarantine is limited to approved WordPress and cleanup tables.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		return $table;
	}

	/**
	 * Whether protected option name.
	 *
	 * @param string $name Name.
	 *
	 * @return bool
	 */
	private function is_protected_option_name( string $name ): bool {
		$protected = array(
			'siteurl',
			'home',
			'blogname',
			'blogdescription',
			'admin_email',
			'active_plugins',
			'template',
			'stylesheet',
			'permalink_structure',
			'rewrite_rules',
			'cron',
			'db_version',
			'WPLANG',
			'users_can_register',
			'default_role',
			'wp_user_roles',
			'sidebars_widgets',
		);
		if ( in_array( $name, $protected, true ) ) {
			return true;
		}
		if ( str_starts_with( $name, 'lucidlab_mpc_' ) || str_starts_with( $name, '_lucidlab_mpc_' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Restore rows set.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return array{ok:int,fail:int}
	 */
	private function restore_rows_set( string $stamp ): array {
		$slice = $this->restore_rows_slice( $stamp, 500, true );
		while ( (int) ( $slice['remaining'] ?? 0 ) > 0 && (int) ( $slice['processed'] ?? 0 ) > 0 ) {
			$next               = $this->restore_rows_slice( $stamp, 500, false );
			$slice['ok']        = (int) $slice['ok'] + (int) $next['ok'];
			$slice['fail']      = (int) $slice['fail'] + (int) $next['fail'];
			$slice['remaining'] = (int) $next['remaining'];
			$slice['processed'] = (int) $next['processed'];
			if ( (int) $next['processed'] < 1 ) {
				break;
			}
		}
		return array(
			'ok'   => (int) $slice['ok'],
			'fail' => (int) $slice['fail'],
		);
	}

	/**
	 * Restore rows slice.
	 *
	 * @param string $stamp        Stamp.
	 * @param int    $limit        Limit.
	 * @param bool   $retry_failed Retry failed.
	 *
	 * @return array{ok:int,fail:int,processed:int,remaining:int,error?:string}
	 */
	private function restore_rows_slice( string $stamp, int $limit = 5, bool $retry_failed = false ): array {
		$m = $this->read_manifest( $stamp );
		if ( $retry_failed && ! empty( $m['failed_files'] ) ) {
			$m['files']        = array_merge( array_values( (array) ( $m['files'] ?? array() ) ), array_values( (array) $m['failed_files'] ) );
			$m['failed_files'] = array();
		}
		$set          = $this->set_dir( $stamp );
		$files        = array_values( (array) ( $m['files'] ?? array() ) );
		$ok           = 0;
		$fail         = 0;
		$processed    = 0;
		$kept         = array();
		$failed_files = array_values( (array) ( $m['failed_files'] ?? array() ) );
		foreach ( $files as $f ) {
			if ( $processed >= $limit ) {
				$kept[] = $f;
				continue;
			}
			++$processed;
			$rel  = LucidLab_MPC_Helpers::sanitize_rel( (string) ( $f['rel'] ?? '' ) );
			$path = $set . '/' . $rel;
			try {
				$this->import_rows_json( $path );
				wp_delete_file( $path );
				++$ok;
			} catch ( Throwable $e ) {
				++$fail;
				$failed_files[] = $f;
				LucidLab_MPC_Logger::error(
					'dbcleaner_rows_restore_failed',
					array(
						'rel'   => $rel,
						'error' => $e->getMessage(),
					)
				);
			}
		}
		$m['files']        = $kept;
		$m['failed_files'] = $failed_files;
		$remaining         = count( $kept ) + count( $failed_files );
		if ( $remaining < 1 ) {
			$this->purge( $stamp );
		} else {
			$this->write_manifest( $stamp, $m );
		}
		return array(
			'ok'        => $ok,
			'fail'      => $fail,
			'processed' => $processed,
			'remaining' => $remaining,
		);
	}

	/**
	 * Restore one rows file.
	 *
	 * @param string $stamp Stamp.
	 * @param string $key   Key.
	 *
	 * @return array{ok:int,fail:int,error?:string}
	 */
	private function restore_one_rows_file( string $stamp, string $key ): array {
		$m   = $this->read_manifest( $stamp );
		$key = LucidLab_MPC_Helpers::sanitize_rel( $key );
		$set = $this->set_dir( $stamp );
		foreach ( (array) ( $m['files'] ?? array() ) as $i => $f ) {
			$rel     = LucidLab_MPC_Helpers::sanitize_rel( (string) ( $f['rel'] ?? '' ) );
			$finding = (string) ( $f['finding_id'] ?? '' );
			if ( $rel !== $key && $finding !== $key && basename( $rel ) !== $key ) {
				continue;
			}
			try {
				$this->import_rows_json( $set . '/' . $rel );
				wp_delete_file( $set . '/' . $rel );
				unset( $m['files'][ $i ] );
				$m['files'] = array_values( (array) $m['files'] );
				$this->write_manifest( $stamp, $m );
				if ( ! $m['files'] ) {
					$this->purge( $stamp );
				}
				return array(
					'ok'   => 1,
					'fail' => 0,
				);
			} catch ( Throwable $e ) {
				return array(
					'ok'    => 0,
					'fail'  => 1,
					'error' => $e->getMessage(),
				);
			}
		}
		return array(
			'ok'    => 0,
			'fail'  => 0,
			'error' => 'missing',
		);
	}

	/**
	 * Import rows json.
	 *
	 * @param string $path Path.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	private function import_rows_json( string $path ): void {
		global $wpdb;
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( __( 'Row dump missing.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( is_array( $data ) && 'audit_only' === (string) ( $data['kind'] ?? '' ) ) {
			throw new RuntimeException( __( 'This Action Scheduler shrink set is audit-only and cannot restore deleted rows.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( ! is_array( $data ) || empty( $data['chunks'] ) || ! is_array( $data['chunks'] ) ) {
			throw new RuntimeException( __( 'Invalid row dump.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		foreach ( $data['chunks'] as $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}
			$table = $this->assert_row_table( (string) ( $chunk['table'] ?? '' ) );
			foreach ( (array) ( $chunk['rows'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) || ! $row ) {
					continue;
				}
				$name = (string) ( $row['option_name'] ?? '' );
				if ( $wpdb->options === $table && $this->is_protected_option_name( $name ) ) {
					// Allow restoring a quarantined cron snapshot from cron_hooks mode.
					if ( 'cron' === $name && 'cron_patch' === (string) ( $chunk['delete_mode'] ?? '' ) ) {
						$result = $wpdb->replace( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
						if ( false === $result ) {
							throw new RuntimeException( sprintf( /* translators: %s: database error message. */ __( 'Cron restore failed: %s', 'lucidlab-mpc-cleanup' ), (string) $wpdb->last_error ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
						}
						wp_cache_delete( 'alloptions', 'options' );
						continue;
					}
					continue;
				}
				// Prefer replace so primary keys round-trip; fall back to insert.
				$result = $wpdb->replace( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				if ( false === $result ) {
					throw new RuntimeException( sprintf( /* translators: %s: database error message. */ __( 'Row restore failed: %s', 'lucidlab-mpc-cleanup' ), (string) $wpdb->last_error ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				}
			}
		}
	}

	/**
	 * Write CREATE + INSERT statements for one table into $dest. Returns dump byte size.
	 *
	 * @param string $table Table.
	 * @param string $dest  Dest.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return int
	 */
	private function dump_table_sql( string $table, string $dest ): int {
		global $wpdb;
		$table  = preg_replace( '/[^A-Za-z0-9_]/', '', $table ) ?? '';
		$safe   = str_replace( '`', '``', $table );
		$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		if ( ! is_array( $create ) || empty( $create[1] ) ) {
			throw new RuntimeException( 'SHOW CREATE TABLE failed.' );
		}
		$sql  = "-- Media & Products Cleanup table quarantine\n";
		$sql .= "SET NAMES utf8mb4;\n-- MPC\nSET FOREIGN_KEY_CHECKS=0;\n-- MPC\n";
		$sql .= 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . "`;\n-- MPC\n";
		$sql .= $create[1] . ";\n-- MPC\n";
		$tmp  = $dest . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		if ( false === file_put_contents( $tmp, $sql ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			throw new RuntimeException( __( 'Could not write SQL dump.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$offset = 0;
		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table, 400, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( ! is_array( $rows ) || ! $rows ) {
				break;
			}
			$chunk = '';
			foreach ( $rows as $row ) {
				$cols = array();
				$vals = array();
				foreach ( $row as $col => $value ) {
					$cols[] = '`' . str_replace( '`', '``', (string) $col ) . '`';
					$vals[] = null === $value ? 'NULL' : "'" . esc_sql( (string) $value ) . "'";
				}
				$chunk .= 'INSERT INTO `' . $safe . '` (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ");\n-- MPC\n";
			}
			if ( false === file_put_contents( $tmp, $chunk, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				wp_delete_file( $tmp );
				throw new RuntimeException( __( 'Could not append SQL dump rows.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$offset += count( $rows );
			if ( count( $rows ) < 400 ) {
				break;
			}
		}
		file_put_contents( $tmp, "SET FOREIGN_KEY_CHECKS=1;\n-- MPC\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			wp_delete_file( $tmp );
			throw new RuntimeException( __( 'Could not finalize SQL dump.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		return (int) filesize( $dest );
	}

	/**
	 * Restore tables set.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return array{ok:int,fail:int}
	 */
	private function restore_tables_set( string $stamp ): array {
		$slice = $this->restore_tables_slice( $stamp, 500, true );
		while ( (int) ( $slice['remaining'] ?? 0 ) > 0 && (int) ( $slice['processed'] ?? 0 ) > 0 ) {
			$next               = $this->restore_tables_slice( $stamp, 500, false );
			$slice['ok']        = (int) $slice['ok'] + (int) $next['ok'];
			$slice['fail']      = (int) $slice['fail'] + (int) $next['fail'];
			$slice['remaining'] = (int) $next['remaining'];
			$slice['processed'] = (int) $next['processed'];
			if ( (int) $next['processed'] < 1 ) {
				break;
			}
		}
		return array(
			'ok'   => (int) $slice['ok'],
			'fail' => (int) $slice['fail'],
		);
	}

	/**
	 * Restore tables slice.
	 *
	 * @param string $stamp        Stamp.
	 * @param int    $limit        Limit.
	 * @param bool   $retry_failed Retry failed.
	 *
	 * @return array{ok:int,fail:int,processed:int,remaining:int,error?:string}
	 */
	private function restore_tables_slice( string $stamp, int $limit = 5, bool $retry_failed = false ): array {
		$m = $this->read_manifest( $stamp );
		if ( $retry_failed && ! empty( $m['failed_files'] ) ) {
			$m['files']        = array_merge( array_values( (array) ( $m['files'] ?? array() ) ), array_values( (array) $m['failed_files'] ) );
			$m['failed_files'] = array();
		}
		$set          = $this->set_dir( $stamp );
		$files        = array_values( (array) ( $m['files'] ?? array() ) );
		$ok           = 0;
		$fail         = 0;
		$processed    = 0;
		$kept         = array();
		$failed_files = array_values( (array) ( $m['failed_files'] ?? array() ) );
		foreach ( $files as $f ) {
			if ( $processed >= $limit ) {
				$kept[] = $f;
				continue;
			}
			++$processed;
			$rel   = LucidLab_MPC_Helpers::sanitize_rel( (string) ( $f['rel'] ?? '' ) );
			$path  = $set . '/' . $rel;
			$table = (string) ( $f['table'] ?? pathinfo( $rel, PATHINFO_FILENAME ) );
			try {
				$this->import_table_sql( $path, $table );
				wp_delete_file( $path );
				++$ok;
			} catch ( Throwable $e ) {
				++$fail;
				$failed_files[] = $f;
				LucidLab_MPC_Logger::error(
					'dbcleaner_restore_failed',
					array(
						'table' => $table,
						'error' => $e->getMessage(),
					)
				);
			}
		}
		$m['files']        = $kept;
		$m['failed_files'] = $failed_files;
		$m['tables']       = array_values(
			array_filter(
				(array) ( $m['tables'] ?? array() ),
				static function ( $row ) use ( $kept ) {
					$rel = is_array( $row ) ? (string) ( $row['rel'] ?? '' ) : '';
					foreach ( $kept as $f ) {
						if ( ( $f['rel'] ?? '' ) === $rel ) {
							return true;
						}
					}
					return false;
				}
			)
		);
		$remaining         = count( $kept ) + count( $failed_files );
		if ( $remaining < 1 ) {
			$this->purge( $stamp );
		} else {
			$this->write_manifest( $stamp, $m );
		}
		return array(
			'ok'        => $ok,
			'fail'      => $fail,
			'processed' => $processed,
			'remaining' => $remaining,
		);
	}

	/**
	 * Restore one table.
	 *
	 * @param string $stamp Stamp.
	 * @param string $key   Key.
	 *
	 * @return array{ok:int,fail:int,error?:string}
	 */
	private function restore_one_table( string $stamp, string $key ): array {
		$m   = $this->read_manifest( $stamp );
		$key = LucidLab_MPC_Helpers::sanitize_rel( $key );
		$set = $this->set_dir( $stamp );
		foreach ( (array) ( $m['files'] ?? array() ) as $i => $f ) {
			$rel   = LucidLab_MPC_Helpers::sanitize_rel( (string) ( $f['rel'] ?? '' ) );
			$table = (string) ( $f['table'] ?? '' );
			if ( $rel !== $key && $table !== $key && pathinfo( $key, PATHINFO_FILENAME ) !== $table ) {
				continue;
			}
			try {
				$this->import_table_sql( $set . '/' . $rel, ( $table ? $table : pathinfo( $rel, PATHINFO_FILENAME ) ) );
				wp_delete_file( $set . '/' . $rel );
				unset( $m['files'][ $i ] );
				$m['files'] = array_values( (array) $m['files'] );
				$this->write_manifest( $stamp, $m );
				if ( ! $m['files'] ) {
					$this->purge( $stamp );
				}
				return array(
					'ok'   => 1,
					'fail' => 0,
				);
			} catch ( Throwable $e ) {
				return array(
					'ok'    => 0,
					'fail'  => 1,
					'error' => $e->getMessage(),
				);
			}
		}
		return array(
			'ok'    => 0,
			'fail'  => 0,
			'error' => 'missing',
		);
	}

	/**
	 * Import table sql.
	 *
	 * @param string $path  Path.
	 * @param string $table Table.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	private function import_table_sql( string $path, string $table ): void {
		global $wpdb;
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( sprintf( /* translators: %s: database table name. */ __( 'SQL dump missing for %s', 'lucidlab-mpc-cleanup' ), $table ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$table  = preg_replace( '/[^A-Za-z0-9_]/', '', $table ) ?? '';
		$prefix = (string) $wpdb->prefix;
		if ( '' === $table || ! str_starts_with( $table, $prefix ) ) {
			throw new RuntimeException( __( 'Refusing to restore a table outside the WordPress prefix.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$sql = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( '' === trim( $sql ) ) {
			throw new RuntimeException( __( 'Empty SQL dump.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$marker = "\n-- MPC";
		$parts  = str_contains( $sql, $marker ) ? explode( $marker, $sql ) : ( LucidLab_MPC_Helpers::either( preg_split( '/;\s*\r?\n/', $sql ), array() ) );
		foreach ( $parts as $stmt ) {
			$stmt = trim( $stmt );
			if ( '' === $stmt || str_starts_with( $stmt, '--' ) ) {
				continue;
			}
			if ( str_ends_with( $stmt, ';' ) ) {
				$stmt = rtrim( substr( $stmt, 0, -1 ) );
			}
			$upper = strtoupper( $stmt );
			if ( str_starts_with( $upper, 'SET ' ) ) {
				$wpdb->query( $stmt ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statement built from validated table identifiers via %i placeholders; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				continue;
			}
			if ( ! preg_match( '/\b' . preg_quote( $table, '/' ) . '\b/i', $stmt ) ) {
				continue;
			}
			if ( ! preg_match( '/^(DROP\s+TABLE|CREATE\s+TABLE|INSERT\s+INTO)\b/i', $stmt ) ) {
				throw new RuntimeException( __( 'Unexpected SQL statement in dump.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$result = $wpdb->query( $stmt ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statement built from validated table identifiers via %i placeholders; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( false === $result ) {
				throw new RuntimeException( sprintf( /* translators: %s: database error message. */ __( 'SQL restore failed: %s', 'lucidlab-mpc-cleanup' ), (string) $wpdb->last_error ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
		}
	}

	/**
	 * Fast same-volume rename. Falls back to move_diagnostic when rename is not enough.
	 *
	 * @param string $src   Src.
	 * @param string $dest  Dest.
	 * @param int    &$bytes Bytes.
	 *
	 * @return array{ok:bool,code?:string,label?:string,detail?:string}
	 */
	private function move_file( string $src, string $dest, int &$bytes = 0 ): array {
		$src                = LucidLab_MPC_Helpers::norm_path( $src );
		$dest               = LucidLab_MPC_Helpers::norm_path( $dest );
		list( $src, $dest ) = $this->held_pair( $src, $dest );
		if ( '' === $src || ! is_file( $src ) ) {
			return array(
				'ok'     => false,
				'code'   => 'source_missing',
				'label'  => __( 'File does not exist', 'lucidlab-mpc-cleanup' ),
				'detail' => $src,
			);
		}
		$bytes += (int) filesize( $src );

		$dest_dir = dirname( $dest );
		if ( ! isset( $this->writable_dirs[ $dest_dir ] ) ) {
			if ( ! is_dir( $dest_dir ) ) {
				wp_mkdir_p( $dest_dir );
			}
			$this->writable_dirs[ $dest_dir ] = is_dir( $dest_dir ) && is_writable( $dest_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		if ( empty( $this->writable_dirs[ $dest_dir ] ) ) {
			return $this->move_diagnostic( $src, $dest );
		}
		if ( rename( $src, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			return array( 'ok' => true );
		}
		return $this->move_diagnostic( $src, $dest );
	}

	/**
	 * Rollback moved.
	 *
	 * @param array $moved  Moved.
	 * @param array &$errors Errors.
	 * @param int   $id     Id.
	 */
	private function rollback_moved( array $moved, array &$errors, int $id ): void {
		foreach ( array_reverse( $moved ) as $entry ) {
			$rollback = $this->move_file( $entry['dest'], $entry['src'] );
			if ( empty( $rollback['ok'] ) ) {
				$rollback = $this->move_diagnostic( $entry['dest'], $entry['src'] );
			}
			if ( empty( $rollback['ok'] ) ) {
				$this->add_error(
					$errors,
					'rollback_failed',
					__( 'Rollback failed after quarantine error', 'lucidlab-mpc-cleanup' ),
					$entry['rel'],
					$id,
					(string) ( $rollback['label'] ?? '' ) . ( ! empty( $rollback['detail'] ) ? ': ' . $rollback['detail'] : '' )
				);
			}
		}
	}

	/**
	 * Delete attachment records after their files are already in quarantine.
	 *
	 * Uses wp_delete_attachment() so WordPress core does all record cleanup (post meta, comments,
	 * term relationships and counts, featured-image references, caches and every core hook).
	 * File deletion is blocked for the duration of the call: moved files already sit in the
	 * quarantine set, and files that another media record still uses ("kept") must stay in uploads.
	 *
	 * @param array<int,\WP_Post> $posts_by_id Posts by id.
	 *
	 * @return array<int,bool>
	 */
	private function delete_attachment_records( array $posts_by_id ): array {
		$deleted    = array();
		$keep_files = static fn() => '';
		add_filter( 'wp_delete_file', $keep_files, PHP_INT_MAX );
		try {
			foreach ( $posts_by_id as $id => $post ) {
				$id     = (int) $id;
				$result = wp_delete_attachment( $id, true );
				if ( $result instanceof WP_Post ) {
					clean_post_cache( $id );
					$deleted[ $id ] = ( null === get_post( $id ) );
					continue;
				}
				// A pre_delete_attachment filter may short-circuit; a truthy answer means another plugin handled it.
				if ( $result ) {
					$deleted[ $id ] = true;
				}
			}
		} finally {
			remove_filter( 'wp_delete_file', $keep_files, PHP_INT_MAX );
		}
		return $deleted;
	}

	/**
	 * Files of an attachment that another media record still uses: the same main file (records that
	 * were duplicated in the database or by an importer) or a size, original or main file of a record
	 * with the same file name stem in the same folder. Records in $leaving are being removed in the
	 * same batch and do not count.
	 *
	 * @param int   $id      Attachment being removed.
	 * @param array $files   Its absolute files (attachment_abs_files()).
	 * @param array $leaving IDs removed in the same batch.
	 *
	 * @return array<string,int> Normalised absolute path => ID of a record that still uses it.
	 */
	public function files_in_use_elsewhere( int $id, array $files, array $leaving = array() ): array {
		global $wpdb;
		$basedir = trailingslashit( LucidLab_MPC_Helpers::norm_path( LucidLab_MPC_Helpers::uploads_basedir() ) );
		$mine    = array();
		$stems   = array();
		foreach ( $files as $abs ) {
			$norm = LucidLab_MPC_Helpers::norm_path( (string) $abs );
			if ( 0 !== strpos( $norm, $basedir ) ) {
				continue;
			}
			$rel          = ltrim( substr( $norm, strlen( $basedir ) ), '/' );
			$mine[ $rel ] = $norm;
			$dir          = dirname( $rel );
			$stem         = (string) preg_replace( '/(?:-\d+x\d+|-scaled|-rotated|-e\d{10,})+$/', '', pathinfo( $rel, PATHINFO_FILENAME ) );
			if ( '' !== $stem ) {
				$stems[ ( '.' === $dir ? '' : $dir . '/' ) . $stem ] = true;
			}
		}
		if ( ! $mine ) {
			return array();
		}
		$skip   = array_flip( array_map( 'intval', $leaving ) );
		$others = array();
		foreach ( array_chunk( array_keys( $mine ), 50 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// $in is generated solely from %s placeholders; every path is bound by prepare().
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value IN ({$in})", $chunk ) ) as $other ) {
				$others[ (int) $other ] = true;
			}
		}
		foreach ( array_keys( $stems ) as $stem ) {
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value LIKE %s LIMIT 500", $wpdb->esc_like( $stem ) . '%' ) ) as $other ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				$others[ (int) $other ] = true;
			}
		}
		unset( $others[ $id ] );
		$others = array_diff_key( $others, $skip );
		if ( ! $others ) {
			return array();
		}
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_keys( $others ), false, true );
		}
		$out = array();
		foreach ( array_keys( $others ) as $other ) {
			if ( $other === $id || isset( $skip[ $other ] ) || 'attachment' !== get_post_type( $other ) ) {
				continue;
			}
			foreach ( $this->attachment_abs_files( $other ) as $abs ) {
				$norm = LucidLab_MPC_Helpers::norm_path( (string) $abs );
				if ( 0 !== strpos( $norm, $basedir ) ) {
					continue;
				}
				$rel = ltrim( substr( $norm, strlen( $basedir ) ), '/' );
				if ( isset( $mine[ $rel ] ) && ! isset( $out[ $mine[ $rel ] ] ) ) {
					$out[ $mine[ $rel ] ] = $other;
				}
			}
		}
		return $out;
	}

	/**
	 * Absolute paths for an attachment: original + generated sizes + original_image.
	 *
	 * @param int         $id   Id.
	 * @param array|null  $meta Meta.
	 * @param string|null $file File.
	 *
	 * @return array
	 */
	public function attachment_abs_files( int $id, ?array $meta = null, ?string $file = null ): array {
		$out  = array();
		$file = ( null !== $file && '' !== $file ) ? $file : get_attached_file( $id );
		if ( ! $file ) {
			return $out;
		}
		$out[] = $file;
		$dir   = trailingslashit( dirname( $file ) );
		$meta  = is_array( $meta ) ? $meta : wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $s ) {
					if ( ! empty( $s['file'] ) ) {
						$out[] = $dir . $s['file'];
					}
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$out[] = $dir . $meta['original_image'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sum attachment originals and thumbnails; older manifests have no stored total.
	 *
	 * @param string $stamp      Stamp.
	 * @param array  $attachment Attachment.
	 *
	 * @return int
	 */
	private function attachment_bytes( string $stamp, array $attachment ): int {
		if ( isset( $attachment['bytes'] ) ) {
			return (int) $attachment['bytes']; }
		$bytes = 0;
		foreach ( (array) ( $attachment['files'] ?? array() ) as $file ) {
			$rel = LucidLab_MPC_Helpers::sanitize_rel( (string) $file );
			if ( '' === $rel ) {
				continue; }
			$path = self::held_file( $this->set_dir( $stamp ) . '/' . $rel );
			if ( is_file( $path ) ) {
				$bytes += (int) filesize( $path ); }
		}
		return $bytes;
	}

	/**
	 * List sets.
	 *
	 * @return array
	 */
	public function list_sets(): array {
		$root = $this->root();
		$sets = array();
		if ( ! is_dir( $root ) ) {
			return $sets;
		}
		foreach ( (array) glob( trailingslashit( $root ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$stamp    = wp_basename( $dir );
			$manifest = $this->read_manifest_soft( $stamp );
			if ( null === $manifest ) {
				// Invalid / unreadable manifest with possible files — leave for Quarantine Health.
				continue;
			}
			$files  = array_merge( array_values( (array) ( $manifest['files'] ?? array() ) ), array_values( (array) ( $manifest['failed_files'] ?? array() ) ) );
			$atts   = array_merge( array_values( (array) ( $manifest['attachments'] ?? array() ) ), array_values( (array) ( $manifest['failed_attachments'] ?? array() ) ) );
			$file_n = count( $files );
			$att_n  = count( $atts );
			$bytes  = 0;
			foreach ( $files as $f ) {
				$bytes += (int) ( $f['bytes'] ?? 0 );
			}
			foreach ( $atts as $a ) {
				$file_n += count( (array) ( $a['files'] ?? array() ) );
				$bytes  += $this->attachment_bytes( $stamp, $a );
			}
			// Attachment quarantine: count media records (not every thumbnail file).
			$count = $att_n > 0 && 0 === count( $files ) ? $att_n : $file_n;
			$media = $att_n > 0 ? $att_n : 0;
			if ( $media < 1 ) {
				foreach ( $files as $f ) {
					if ( LucidLab_MPC_Helpers::is_image_rel( (string) ( $f['rel'] ?? '' ) ) ) {
						++$media;
					}
				}
			}
			// Drop sets with no files left (after full restore or a no-op quarantine).
			// Never auto-delete when the folder still has payload files but the manifest is empty
			// or invalid — that edge case is handled by Quarantine Health.
			if ( 0 === $count ) {
				if ( self::is_health_protected( $stamp ) || $this->set_has_disk_payload( $stamp ) ) {
					continue;
				}
				$this->purge( $stamp );
				continue;
			}
			$t      = self::stamp_time( $stamp );
			$age    = $t ? (int) floor( ( time() - $t ) / DAY_IN_SECONDS ) : 0;
			$sets[] = array(
				'stamp'       => $stamp,
				'source'      => (string) ( $manifest['source'] ?? 'uploads' ),
				'count'       => $count,
				'attachments' => count( $atts ),
				'media'       => $media,
				'bytes'       => $bytes,
				'age'         => $age,
				'expires'     => max( 0, self::retention_days() - $age ),
			);
		}
		usort(
			$sets,
			static function ( array $left, array $right ): int {
				return LucidLab_MPC_Helpers::either( ( (int) $left['expires'] <=> (int) $right['expires'] ), ( (string) $right['stamp'] <=> (string) $left['stamp'] ) );
			}
		);
		return $sets;
	}

	/**
	 * Soft manifest read for listing/health. Returns null when JSON is invalid (file preserved).
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return array<string,mixed>|null
	 */
	private function read_manifest_soft( string $stamp ): ?array {
		$path = $this->manifest_path( $stamp );
		if ( ! file_exists( $path ) ) {
			return array(
				'created_gmt'       => gmdate( 'c' ),
				'site'              => home_url(),
				'plugin'            => LUCIDLAB_MPC_VERSION,
				'basedir'           => LucidLab_MPC_Helpers::uploads_basedir(),
				'files'             => array(),
				'attachments'       => array(),
				'_missing_manifest' => true,
			);
		}
		$raw  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return $data;
	}

	/**
	 * True when the set folder holds payload files (not just manifest / empties).
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return bool
	 */
	private function set_has_disk_payload( string $stamp ): bool {
		$dir = $this->set_dir( $stamp );
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$skip = array( 'manifest.json', '.', '..' );
		$it   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}
			$name = $file->getFilename();
			if ( in_array( $name, $skip, true ) || str_ends_with( $name, '.tmp' ) || ( wp_normalize_path( $file->getPath() ) === wp_normalize_path( $dir ) && in_array( $name, array( 'index.php', '.htaccess', 'web.config' ), true ) ) ) {
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * Scan quarantine folders for broken manifests / disk mismatches. Never deletes anything.
	 *
	 * @return array{counts:array<string,int>,issues:array<int,array<string,mixed>>,protected:array<int,string>}
	 */
	public function health_scan(): array {
		$root      = $this->root();
		$counts    = array(
			'folders'              => 0,
			'invalid_manifest'     => 0,
			'missing_manifest'     => 0,
			'empty_manifest_files' => 0,
			'missing_files'        => 0,
			'orphan_disk_files'    => 0,
			'protected_skipped'    => 0,
		);
		$issues    = array();
		$protected = self::PROTECTED_HEALTH_SETS;
		if ( ! is_dir( $root ) ) {
			return array(
				'counts'    => $counts,
				'issues'    => $issues,
				'protected' => $protected,
			);
		}
		foreach ( (array) glob( trailingslashit( $root ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$stamp = wp_basename( $dir );
			++$counts['folders'];
			$is_protected      = self::is_health_protected( $stamp );
			$manifest_path     = $this->manifest_path( $stamp );
			$has_manifest_file = is_file( $manifest_path );
			$manifest          = $this->read_manifest_soft( $stamp );

			if ( null === $manifest ) {
				++$counts['invalid_manifest'];
				$issues[] = array(
					'stamp'     => $stamp,
					'kind'      => 'invalid_manifest',
					'label'     => __( 'Broken manifest (invalid JSON)', 'lucidlab-mpc-cleanup' ),
					'count'     => 1,
					'bytes'     => $this->dir_bytes( $dir ),
					'protected' => $is_protected,
					'samples'   => array( 'manifest.json' ),
				);
				if ( $is_protected ) {
					++$counts['protected_skipped'];
				}
				continue;
			}

			if ( ! $has_manifest_file || ! empty( $manifest['_missing_manifest'] ) ) {
				$disk = $this->list_set_disk_rels( $stamp );
				++$counts['missing_manifest'];
				$issues[] = array(
					'stamp'     => $stamp,
					'kind'      => 'missing_manifest',
					'label'     => __( 'Folder with no manifest', 'lucidlab-mpc-cleanup' ),
					'count'     => max( 1, count( $disk ) ),
					'bytes'     => $this->dir_bytes( $dir ),
					'protected' => $is_protected,
					'samples'   => array_slice( $disk, 0, 8 ),
				);
				if ( $is_protected ) {
					++$counts['protected_skipped'];
				}
				continue;
			}

			$listed_rels = $this->manifest_listed_rels( $manifest );
			$disk_rels   = $this->list_set_disk_rels( $stamp );
			$item_count  = count( (array) ( $manifest['files'] ?? array() ) ) + count( (array) ( $manifest['attachments'] ?? array() ) );

			if ( 0 === $item_count && $disk_rels ) {
				++$counts['empty_manifest_files'];
				$issues[] = array(
					'stamp'     => $stamp,
					'kind'      => 'empty_manifest_files',
					'label'     => __( 'Empty/invalid manifest but files on disk', 'lucidlab-mpc-cleanup' ),
					'count'     => count( $disk_rels ),
					'bytes'     => $this->dir_bytes( $dir ),
					'protected' => $is_protected,
					'samples'   => array_slice( $disk_rels, 0, 8 ),
				);
				if ( $is_protected ) {
					++$counts['protected_skipped'];
				}
			}

			$missing = array();
			foreach ( $listed_rels as $rel ) {
				$path = self::held_file( $dir . '/' . ltrim( str_replace( '\\', '/', $rel ), '/' ) );
				if ( ! is_file( $path ) ) {
					$missing[] = $rel;
				}
			}
			if ( $missing ) {
				++$counts['missing_files'];
				$issues[] = array(
					'stamp'     => $stamp,
					'kind'      => 'missing_files',
					'label'     => __( 'Manifest rows pointing at missing files', 'lucidlab-mpc-cleanup' ),
					'count'     => count( $missing ),
					'bytes'     => 0,
					'protected' => $is_protected,
					'samples'   => array_slice( $missing, 0, 8 ),
				);
				if ( $is_protected ) {
					++$counts['protected_skipped'];
				}
			}

			$listed_map = array_fill_keys( $listed_rels, true );
			$orphans    = array();
			foreach ( $disk_rels as $rel ) {
				if ( empty( $listed_map[ $rel ] ) ) {
					$orphans[] = $rel;
				}
			}
			// empty_manifest_files already covers the all-orphan case when item_count is 0.
			if ( $orphans && $item_count > 0 ) {
				++$counts['orphan_disk_files'];
				$issues[] = array(
					'stamp'     => $stamp,
					'kind'      => 'orphan_disk_files',
					'label'     => __( 'Files on disk not listed in manifest', 'lucidlab-mpc-cleanup' ),
					'count'     => count( $orphans ),
					'bytes'     => 0,
					'protected' => $is_protected,
					'samples'   => array_slice( $orphans, 0, 8 ),
				);
				if ( $is_protected ) {
					++$counts['protected_skipped'];
				}
			}
		}
		return array(
			'counts'    => $counts,
			'issues'    => $issues,
			'protected' => $protected,
		);
	}

	/**
	 * Permanently remove broken quarantine artifacts. Not restoreable. Protected fixture sets are never touched.
	 *
	 * @param array<int,string> $stamps Empty = all broken non-protected sets from a fresh scan.
	 * @param array<int,string> $kinds  Optional issue kinds to target; empty = all kinds.
	 *
	 * @return array{ok:int,skip:int,protected:int,errors:array<int,string>}
	 */
	public function health_purge_broken( array $stamps = array(), array $kinds = array() ): array {
		$report       = $this->health_scan();
		$kind_filter  = array_fill_keys( array_filter( array_map( 'sanitize_key', $kinds ) ), true );
		$stamp_filter = array_fill_keys( array_filter( array_map( 'sanitize_file_name', $stamps ) ), true );
		$ok           = 0;
		$skip         = 0;
		$protected    = 0;
		$errors       = array();
		$seen         = array();
		foreach ( (array) ( $report['issues'] ?? array() ) as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			$stamp = sanitize_file_name( (string) ( $issue['stamp'] ?? '' ) );
			$kind  = sanitize_key( (string) ( $issue['kind'] ?? '' ) );
			if ( '' === $stamp || '' === $kind ) {
				continue;
			}
			if ( $stamp_filter && empty( $stamp_filter[ $stamp ] ) ) {
				continue;
			}
			if ( $kind_filter && empty( $kind_filter[ $kind ] ) ) {
				continue;
			}
			if ( ! empty( $issue['protected'] ) || self::is_health_protected( $stamp ) ) {
				++$protected;
				continue;
			}
			$key = $stamp . '|' . $kind;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			// Whole-folder purge kinds.
			if ( in_array( $kind, array( 'invalid_manifest', 'missing_manifest', 'empty_manifest_files' ), true ) ) {
				if ( isset( $seen[ $stamp . '|folder' ] ) ) {
					continue;
				}
				$seen[ $stamp . '|folder' ] = true;
				if ( $this->purge( $stamp ) ) {
					++$ok;
				} else {
					++$skip;
					$errors[] = $stamp . __( ': purge failed', 'lucidlab-mpc-cleanup' );
				}
				continue;
			}
			if ( 'orphan_disk_files' === $kind ) {
				$removed = $this->health_purge_orphan_files( $stamp, (array) ( $issue['samples'] ?? array() ), true );
				if ( $removed > 0 ) {
					++$ok;
				} else {
					++$skip;
				}
				continue;
			}
			if ( 'missing_files' === $kind ) {
				if ( $this->health_prune_missing_manifest_rows( $stamp ) ) {
					++$ok;
				} else {
					++$skip;
				}
			}
		}
		return array(
			'ok'        => $ok,
			'skip'      => $skip,
			'protected' => $protected,
			'errors'    => $errors,
		);
	}

	/**
	 * Manifest listed rels.
	 *
	 * @param array $manifest Manifest.
	 *
	 * @return array<int,string>
	 */
	private function manifest_listed_rels( array $manifest ): array {
		$rels = array();
		foreach ( (array) ( $manifest['files'] ?? array() ) as $f ) {
			$rel = ltrim( str_replace( '\\', '/', (string) ( $f['rel'] ?? '' ) ), '/' );
			if ( '' !== $rel ) {
				$rels[] = $rel;
			}
		}
		foreach ( (array) ( $manifest['attachments'] ?? array() ) as $a ) {
			foreach ( (array) ( $a['files'] ?? array() ) as $rel ) {
				$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
				if ( '' !== $rel ) {
					$rels[] = $rel;
				}
			}
			foreach ( (array) ( $a['moved'] ?? array() ) as $pair ) {
				$rel = ltrim( str_replace( '\\', '/', (string) ( is_array( $pair ) ? ( $pair['dest'] ?? $pair['rel'] ?? '' ) : $pair ) ), '/' );
				if ( '' !== $rel ) {
					$rels[] = $rel;
				}
			}
		}
		foreach ( (array) ( $manifest['failed_files'] ?? array() ) as $f ) {
			$rel = ltrim( str_replace( '\\', '/', (string) ( $f['rel'] ?? '' ) ), '/' );
			if ( '' !== $rel ) {
				$rels[] = $rel;
			}
		}
		return array_values( array_unique( $rels ) );
	}

	/**
	 * List set disk rels.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return array<int,string> Relative paths under the set dir (excluding manifest).
	 */
	private function list_set_disk_rels( string $stamp ): array {
		$dir = $this->set_dir( $stamp );
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$base = wp_normalize_path( $dir );
		$it   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}
			$name = $file->getFilename();
			if ( 'manifest.json' === $name || str_ends_with( $name, '.tmp' ) ) {
				continue;
			}
			$full = wp_normalize_path( $file->getPathname() );
			if ( ! str_starts_with( $full, $base ) ) {
				continue;
			}
			// Every set (and its tables/ and rows/ folders) gets these access-denial files from protect_dir().
			// They are infrastructure, not orphaned payload: real payload with these names carries HELD_SUFFIX.
			if ( in_array( $name, array( 'index.php', '.htaccess', 'web.config' ), true ) ) {
				continue;
			}
			$rel = self::logical_rel( ltrim( substr( $full, strlen( $base ) ), '/' ) );
			if ( '' !== $rel ) {
				$out[] = $rel;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Dir bytes.
	 *
	 * @param string $dir Dir.
	 *
	 * @return int
	 */
	private function dir_bytes( string $dir ): int {
		$bytes = 0;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( $file instanceof SplFileInfo && $file->isFile() ) {
				$bytes += (int) $file->getSize();
			}
		}
		return $bytes;
	}

	/**
	 * Health purge orphan files.
	 *
	 * @param string $stamp       Stamp.
	 * @param array  $sample_rels Sample rels.
	 * @param bool   $all         All.
	 *
	 * @return int Files removed.
	 */
	private function health_purge_orphan_files( string $stamp, array $sample_rels, bool $all = false ): int {
		$dir      = $this->set_dir( $stamp );
		$rels     = $all ? $this->list_set_disk_rels( $stamp ) : array_map( 'strval', $sample_rels );
		$manifest = $this->read_manifest_soft( $stamp );
		$listed   = is_array( $manifest ) ? array_fill_keys( $this->manifest_listed_rels( $manifest ), true ) : array();
		$n        = 0;
		foreach ( $rels as $rel ) {
			$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
			if ( '' === $rel || ! empty( $listed[ $rel ] ) ) {
				continue;
			}
			$path = self::held_file( $dir . '/' . $rel );
			if ( is_file( $path ) && LucidLab_MPC_Helpers::delete_file( $path ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Health prune missing manifest rows.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return bool
	 */
	private function health_prune_missing_manifest_rows( string $stamp ): bool {
		$path = $this->manifest_path( $stamp );
		if ( ! is_file( $path ) ) {
			return false;
		}
		try {
			$m = $this->read_manifest( $stamp );
		} catch ( Throwable $e ) {
			return false;
		}
		$dir     = $this->set_dir( $stamp );
		$changed = false;
		$files   = array();
		foreach ( (array) ( $m['files'] ?? array() ) as $f ) {
			$rel = ltrim( str_replace( '\\', '/', (string) ( $f['rel'] ?? '' ) ), '/' );
			if ( '' !== $rel && is_file( self::held_file( $dir . '/' . $rel ) ) ) {
				$files[] = $f;
			} else {
				$changed = true;
			}
		}
		$atts = array();
		foreach ( (array) ( $m['attachments'] ?? array() ) as $a ) {
			$kept_files = array();
			foreach ( (array) ( $a['files'] ?? array() ) as $rel ) {
				$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
				if ( '' !== $rel && is_file( self::held_file( $dir . '/' . $rel ) ) ) {
					$kept_files[] = $rel;
				} else {
					$changed = true;
				}
			}
			if ( $kept_files ) {
				$a['files'] = $kept_files;
				$atts[]     = $a;
			} else {
				$changed = true;
			}
		}
		if ( ! $changed ) {
			return false;
		}
		$m['files']       = $files;
		$m['attachments'] = $atts;
		$this->write_manifest( $stamp, $m );
		return true;
	}

	/**
	 * Export manifest.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return array
	 */
	public function export_manifest( string $stamp ): array {
		$stamp = sanitize_file_name( $stamp );
		if ( '' === $stamp || ! file_exists( $this->manifest_path( $stamp ) ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Manifest not found', 'lucidlab-mpc-cleanup' ),
			);
		}

		$manifest    = $this->read_manifest( $stamp );
		$export_data = array(
			'exported_at'      => gmdate( 'c' ),
			'quarantine_stamp' => $stamp,
			'manifest'         => $manifest,
			'summary'          => array(
				'files_count'       => count( $manifest['files'] ?? array() ),
				'attachments_count' => count( $manifest['attachments'] ?? array() ),
				'total_bytes'       => 0,
				'source'            => $manifest['source'] ?? 'unknown',
			),
		);

		// Calculate total bytes.
		foreach ( (array) ( $manifest['files'] ?? array() ) as $file ) {
			$export_data['summary']['total_bytes'] += (int) ( $file['bytes'] ?? 0 );
		}
		foreach ( (array) ( $manifest['attachments'] ?? array() ) as $attachment ) {
			$export_data['summary']['total_bytes'] += $this->attachment_bytes( $stamp, $attachment );
		}

		return array(
			'success' => true,
			'data'    => $export_data,
		);
	}

	/**
	 * Restore.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return array
	 */
	public function restore( string $stamp ): array {
		$stamp = sanitize_file_name( $stamp );
		if ( '' === $stamp || ! file_exists( $this->manifest_path( $stamp ) ) ) {
			return array(
				'ok'    => 0,
				'fail'  => 0,
				'error' => __( 'manifest missing', 'lucidlab-mpc-cleanup' ),
			);
		}
		$manifest = $this->read_manifest( $stamp );
		$kind     = sanitize_key( (string) ( $manifest['kind'] ?? '' ) );
		if ( 'db_rows' === $kind ) {
			return $this->restore_rows_set( $stamp );
		}
		if ( 'db_tables' === $kind || 'dbcleaner' === sanitize_key( (string) ( $manifest['source'] ?? '' ) ) ) {
			return $this->restore_tables_set( $stamp );
		}
		$basedir       = trailingslashit( (string) ( $manifest['basedir'] ?? LucidLab_MPC_Helpers::uploads_basedir() ) );
		$set           = $this->set_dir( $stamp );
		$ok            = 0;
		$fail          = 0;
		$restored_rels = array();
		$is_originals  = ( 'originals' === sanitize_key( (string) ( $manifest['source'] ?? '' ) ) );

		// Loose files.
		foreach ( (array) ( $manifest['files'] ?? array() ) as $f ) {
			$rel = LucidLab_MPC_Helpers::sanitize_rel( $f['rel'] ?? '' );
			if ( '' === $rel ) {
				++$fail;
				continue; }
			$core_rel = isset( $f['core_rel'] ) ? str_replace( '\\', '/', ltrim( (string) $f['core_rel'], '/' ) ) : '';
			if ( '' !== $core_rel ) {
				// Legacy core-file sets remain stored for recovery by the hosting provider.
				++$fail;
				continue;
			}
			$target = $basedir . $rel;
			if ( $this->move( $set . '/' . $rel, $target, true ) ) {
				++$ok;
				$this->restore_size_meta( $f );
				if ( $is_originals && '' === $core_rel ) {
					$restored_rels[] = $rel;
				}
			} else {
				++$fail;
			}
		}

		// Attachments: move files back + recreate the media record.
		foreach ( (array) ( $manifest['attachments'] ?? array() ) as $a ) {
			$moved_back = true;
			foreach ( (array) ( $a['files'] ?? array() ) as $rel ) {
				$rel = LucidLab_MPC_Helpers::sanitize_rel( $rel );
				if ( '' === $rel ) {
					$moved_back = false;
					continue; }
				if ( ! $this->move( $set . '/' . $rel, $basedir . $rel, true ) ) {
					$moved_back = false;
				}
			}
			if ( $moved_back && $this->recreate_attachment( $a, $basedir ) ) {
				++$ok;
			} else {
				++$fail;
			}
		}

		if ( $is_originals && $restored_rels ) {
			$this->after_originals_restored( $stamp, $restored_rels );
		}

		$this->purge( $stamp );
		return array(
			'ok'   => $ok,
			'fail' => $fail,
		);
	}

	/**
	 * How many restorable items (loose files + attachments) remain in a set.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return int
	 */
	public function count_items( string $stamp ): int {
		$stamp = sanitize_file_name( $stamp );
		if ( '' === $stamp || ! file_exists( $this->manifest_path( $stamp ) ) ) {
			return 0;
		}
		$m = $this->read_manifest( $stamp );
		return count( (array) ( $m['files'] ?? array() ) )
			+ count( (array) ( $m['attachments'] ?? array() ) )
			+ count( (array) ( $m['failed_files'] ?? array() ) )
			+ count( (array) ( $m['failed_attachments'] ?? array() ) );
	}

	/**
	 * Restore up to $limit remaining items from a set (manifest is rewritten each slice). Used for large Uploads Scanner sets so the task job does not store thousands of paths.
	 *
	 * @param string $stamp        Stamp.
	 * @param int    $limit        Limit.
	 * @param bool   $retry_failed Retry failed.
	 *
	 * @return array{ok:int,fail:int,processed:int,remaining:int,error?:string}
	 */
	public function restore_slice( string $stamp, int $limit = 200, bool $retry_failed = false ): array {
		$stamp = sanitize_file_name( $stamp );
		$limit = max( 1, min( 500, $limit ) );
		if ( '' === $stamp || ! file_exists( $this->manifest_path( $stamp ) ) ) {
			return array(
				'ok'        => 0,
				'fail'      => 0,
				'processed' => 0,
				'remaining' => 0,
				'error'     => 'missing',
			);
		}
		$m    = $this->read_manifest( $stamp );
		$kind = sanitize_key( (string) ( $m['kind'] ?? '' ) );
		if ( 'db_rows' === $kind ) {
			return $this->restore_rows_slice( $stamp, max( 1, min( 20, $limit ) ), $retry_failed );
		}
		if ( 'db_tables' === $kind || 'dbcleaner' === sanitize_key( (string) ( $m['source'] ?? '' ) ) ) {
			return $this->restore_tables_slice( $stamp, max( 1, min( 20, $limit ) ), $retry_failed );
		}
		$basedir = trailingslashit( wp_normalize_path( (string) ( $m['basedir'] ?? LucidLab_MPC_Helpers::uploads_basedir() ) ) );
		// Prefer the live uploads path when the stored basedir is gone (moved site / renamed drive).
		$live = trailingslashit( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ) );
		if ( $basedir !== $live && is_dir( $live ) && ! is_dir( $basedir ) ) {
			$basedir = $live;
		}
		if ( $retry_failed ) {
			if ( ! empty( $m['failed_files'] ) ) {
				$m['files']        = array_merge( array_values( (array) ( $m['files'] ?? array() ) ), array_values( (array) $m['failed_files'] ) );
				$m['failed_files'] = array();
			}
			if ( ! empty( $m['failed_attachments'] ) ) {
				$m['attachments']        = array_merge( array_values( (array) ( $m['attachments'] ?? array() ) ), array_values( (array) $m['failed_attachments'] ) );
				$m['failed_attachments'] = array();
			}
		}
		$set          = $this->set_dir( $stamp );
		$ok           = 0;
		$fail         = 0;
		$left         = $limit;
		$failed_files = array_values( (array) ( $m['failed_files'] ?? array() ) );
		$failed_atts  = array_values( (array) ( $m['failed_attachments'] ?? array() ) );

		$files         = array_values( (array) ( $m['files'] ?? array() ) );
		$kept_files    = array();
		$restored_rels = array();
		$is_originals  = ( 'originals' === sanitize_key( (string) ( $m['source'] ?? '' ) ) );
		foreach ( $files as $f ) {
			if ( $left < 1 ) {
				$kept_files[] = $f;
				continue;
			}
			$rel = LucidLab_MPC_Helpers::sanitize_rel( (string) ( $f['rel'] ?? '' ) );
			--$left;
			if ( '' === $rel ) {
				++$fail;
				continue;
			}
			$core_rel = isset( $f['core_rel'] ) ? str_replace( '\\', '/', ltrim( (string) $f['core_rel'], '/' ) ) : '';
			if ( '' !== $core_rel ) {
				++$fail;
				$failed_files[] = $f;
				continue;
			}
			$target = $basedir . $rel;
			if ( $this->move( $set . '/' . $rel, $target, true ) ) {
				++$ok;
				$this->restore_size_meta( $f );
				if ( $is_originals && '' === $core_rel ) {
					$restored_rels[] = $rel;
				}
			} else {
				++$fail;
				$failed_files[] = $f;
			}
		}

		$atts      = array_values( (array) ( $m['attachments'] ?? array() ) );
		$kept_atts = array();
		foreach ( $atts as $a ) {
			if ( $left < 1 ) {
				$kept_atts[] = $a;
				continue;
			}
			--$left;
			$moved = true;
			foreach ( (array) ( $a['files'] ?? array() ) as $rel ) {
				$rel = LucidLab_MPC_Helpers::sanitize_rel( (string) $rel );
				if ( '' !== $rel && ! is_file( $set . '/' . $rel ) && is_file( $basedir . $rel ) ) {
					continue; // Another record of this set used the same file and brought it back already.
				}
				if ( '' === $rel || ! $this->move( $set . '/' . $rel, $basedir . $rel, true ) ) {
					$moved = false;
				}
			}
			if ( $moved && $this->recreate_attachment( $a, $basedir ) ) {
				++$ok;
			} else {
				++$fail;
				$failed_atts[] = $a;
			}
		}

		$m['files']              = $kept_files;
		$m['attachments']        = $kept_atts;
		$m['failed_files']       = $failed_files;
		$m['failed_attachments'] = $failed_atts;
		$remaining               = count( $kept_files ) + count( $kept_atts );
		if ( $is_originals && $restored_rels ) {
			$this->after_originals_restored( $stamp, $restored_rels );
		}
		if ( $remaining < 1 && empty( $failed_files ) && empty( $failed_atts ) ) {
			$this->purge( $stamp );
		} else {
			$this->write_manifest( $stamp, $m );
		}
		return array(
			'ok'        => $ok,
			'fail'      => $fail,
			'processed' => $ok + $fail,
			'remaining' => $remaining,
		);
	}

	/**
	 * Recreate a media-library record from a manifest attachment payload (files already moved back).
	 *
	 * @param array  $a       A.
	 * @param string $basedir Basedir.
	 *
	 * @return bool
	 */
	private function recreate_attachment( array $a, string $basedir ): bool {
		return $this->recreate_attachment_id( $a, $basedir ) > 0;
	}

	/**
	 * Same as recreate_attachment but returns the new attachment ID (0 on failure).
	 *
	 * @param array  $a       A.
	 * @param string $basedir Basedir.
	 *
	 * @return int
	 */
	private function recreate_attachment_id( array $a, string $basedir ): int {
		global $wpdb;
		$attached = LucidLab_MPC_Helpers::sanitize_rel( $a['attached'] ?? '' );
		if ( '' === $attached ) {
			return 0;
		}
		$orig_id = (int) ( $a['orig_id'] ?? 0 );
		$file    = $basedir . $attached;
		$mime    = $a['post']['post_mime_type'] ?? '';
		if ( '' === $mime && $file && function_exists( 'wp_check_filetype' ) ) {
			$mime = (string) ( wp_check_filetype( $file )['type'] ?? '' );
		}
		$now    = current_time( 'mysql' );
		$gmt    = current_time( 'mysql', true );
		$row    = array(
			'post_author'           => (int) ( $a['post']['post_author'] ?? get_current_user_id() ),
			'post_date'             => (string) ( $a['post']['post_date'] ?? $now ),
			'post_date_gmt'         => (string) ( $a['post']['post_date_gmt'] ?? $gmt ),
			'post_content'          => (string) ( $a['post']['post_content'] ?? '' ),
			'post_title'            => (string) ( $a['post']['post_title'] ?? '' ),
			'post_excerpt'          => (string) ( $a['post']['post_excerpt'] ?? '' ),
			'post_status'           => 'inherit',
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => (string) ( $a['post']['post_name'] ?? '' ),
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => $now,
			'post_modified_gmt'     => $gmt,
			'post_content_filtered' => '',
			'post_parent'           => (int) ( $a['post']['post_parent'] ?? 0 ),
			'guid'                  => esc_url_raw( (string) ( wp_get_upload_dir()['baseurl'] ?? '' ) . '/' . $attached ),
			'menu_order'            => 0,
			'post_type'             => 'attachment',
			'post_mime_type'        => $mime,
			'comment_count'         => 0,
		);
		$new_id = 0;
		// Prefer the original primary key so content references keep working.
		if ( $orig_id > 0 ) {
			clean_post_cache( $orig_id );
			$id_free = ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $orig_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			if ( $id_free ) {
				$insert       = $row;
				$insert['ID'] = $orig_id;
				$inserted     = $wpdb->insert( $wpdb->posts, $insert ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				if ( false !== $inserted ) {
					$new_id = $orig_id;
				}
			} else {
				LucidLab_MPC_Logger::error(
					'quarantine_restore_id_conflict',
					array(
						'orig_id'  => $orig_id,
						'attached' => $attached,
					)
				);
			}
		}
		if ( $new_id < 1 ) {
			$post = array(
				'post_title'     => $row['post_title'],
				'post_content'   => $row['post_content'],
				'post_excerpt'   => $row['post_excerpt'],
				'post_mime_type' => $row['post_mime_type'],
				'post_status'    => 'inherit',
				'post_parent'    => $row['post_parent'],
				'post_type'      => 'attachment',
				'guid'           => $row['guid'],
			);
			if ( '' !== $row['post_name'] ) {
				$post['post_name'] = $row['post_name'];
			}
			if ( $orig_id > 0 ) {
				$post['import_id'] = $orig_id;
			}
			$created = wp_insert_post( $post, true );
			if ( ! $created || is_wp_error( $created ) ) {
				return 0;
			}
			$new_id = (int) $created;
			if ( $orig_id > 0 && $new_id !== $orig_id ) {
				$id_free = ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $orig_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
				if ( $id_free && false !== $wpdb->update( $wpdb->posts, array( 'ID' => $orig_id ), array( 'ID' => $new_id ), array( '%d' ), array( '%d' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					$wpdb->update( $wpdb->postmeta, array( 'post_id' => $orig_id ), array( 'post_id' => $new_id ), array( '%d' ), array( '%d' ) );
					$wpdb->update( $wpdb->term_relationships, array( 'object_id' => $orig_id ), array( 'object_id' => $new_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
					clean_post_cache( $new_id );
					clean_post_cache( $orig_id );
					$new_id = $orig_id;
				} else {
					// Keep the restored attachment even if the original ID could not be reused.
					LucidLab_MPC_Logger::error(
						'quarantine_restore_id_mismatch',
						array(
							'orig_id'  => $orig_id,
							'new_id'   => $new_id,
							'attached' => $attached,
						)
					);
				}
			}
		}
		update_attached_file( $new_id, $file );
		update_post_meta( $new_id, '_wp_attached_file', $attached );
		if ( is_array( $a['metadata'] ?? null ) ) {
			wp_update_attachment_metadata( $new_id, $a['metadata'] );
		} elseif ( $file && is_file( $file ) && wp_attachment_is_image( $new_id ) ) {
			$generated = wp_generate_attachment_metadata( $new_id, $file );
			if ( is_array( $generated ) && $generated ) {
				wp_update_attachment_metadata( $new_id, $generated );
			}
		}
		if ( ! empty( $a['alt'] ) ) {
			update_post_meta( $new_id, '_wp_attachment_image_alt', $a['alt'] );
		}
		clean_post_cache( $new_id );
		return $new_id;
	}

	/**
	 * Merge extra data (e.g. a reversible merge changeset) into a manifest attachment entry.
	 *
	 * @param string $stamp   Stamp.
	 * @param int    $orig_id Orig id.
	 * @param array  $extra   Extra.
	 *
	 * @return bool
	 */
	public function annotate_attachment( string $stamp, int $orig_id, array $extra ): bool {
		$stamp = sanitize_file_name( $stamp );
		if ( '' === $stamp || ! file_exists( $this->manifest_path( $stamp ) ) ) {
			return false;
		}
		$m = $this->read_manifest( $stamp );
		foreach ( (array) ( $m['attachments'] ?? array() ) as $i => $a ) {
			if ( (int) ( $a['orig_id'] ?? 0 ) === $orig_id ) {
				$m['attachments'][ $i ] = array_merge( $a, $extra );
				$this->write_manifest( $stamp, $m );
				return true;
			}
		}
		return false;
	}

	/**
	 * Restore a whole set and report the new attachment IDs + any stored extras (merge changeset). Used by "Undo merge". Purges the set afterwards, like restore().
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return array
	 */
	public function restore_set_detailed( string $stamp ): array {
		$stamp = sanitize_file_name( $stamp );
		if ( '' === $stamp || ! file_exists( $this->manifest_path( $stamp ) ) ) {
			return array(
				'ok'          => 0,
				'fail'        => 0,
				'attachments' => array(),
				'error'       => __( 'manifest missing', 'lucidlab-mpc-cleanup' ),
			);
		}
		$manifest = $this->read_manifest( $stamp );
		$basedir  = trailingslashit( (string) ( $manifest['basedir'] ?? LucidLab_MPC_Helpers::uploads_basedir() ) );
		$set      = $this->set_dir( $stamp );
		$ok       = 0;
		$fail     = 0;
		$out      = array();

		foreach ( (array) ( $manifest['files'] ?? array() ) as $f ) {
			$rel = LucidLab_MPC_Helpers::sanitize_rel( $f['rel'] ?? '' );
			if ( '' === $rel ) {
				++$fail;
				continue; }
			if ( $this->move( $set . '/' . $rel, $basedir . $rel, true ) ) {
				++$ok;
				$this->restore_size_meta( $f );
			} else {
				++$fail;
			}
		}

		foreach ( (array) ( $manifest['attachments'] ?? array() ) as $a ) {
			$moved_back = true;
			foreach ( (array) ( $a['files'] ?? array() ) as $rel ) {
				$rel = LucidLab_MPC_Helpers::sanitize_rel( $rel );
				if ( '' !== $rel && ! is_file( $set . '/' . $rel ) && is_file( $basedir . $rel ) ) {
					continue; // Another record of this set used the same file and brought it back already.
				}
				if ( '' === $rel || ! $this->move( $set . '/' . $rel, $basedir . $rel, true ) ) {
					$moved_back = false;
				}
			}
			$new_id = $moved_back ? $this->recreate_attachment_id( $a, $basedir ) : 0;
			if ( $new_id > 0 ) {
				++$ok;
				$out[] = array(
					'orig_id'     => (int) ( $a['orig_id'] ?? 0 ),
					'new_id'      => $new_id,
					'merged_into' => (int) ( $a['merged_into'] ?? 0 ),
					'ref_changes' => (array) ( $a['ref_changes'] ?? array() ),
				);
			} else {
				++$fail;
			}
		}

		$this->purge( $stamp );
		return array(
			'ok'          => $ok,
			'fail'        => $fail,
			'attachments' => $out,
		);
	}

	/**
	 * Flat, searchable list of every quarantined item across all sets.
	 *
	 * @param string $selected_set Selected set.
	 * @param string $query        Query.
	 *
	 * @return array
	 */
	public function list_files( string $selected_set = '', string $query = '' ): array {
		$root  = $this->root();
		$items = array();
		if ( ! is_dir( $root ) ) {
			return $items;
		}
		foreach ( (array) glob( trailingslashit( $root ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$stamp = wp_basename( $dir );
			if ( '' !== $selected_set && $stamp !== $selected_set ) {
				continue; }
			$m       = $this->read_manifest( $stamp );
			$set_url = LucidLab_MPC_Storage::url_for( trailingslashit( wp_normalize_path( $root ) ) . $stamp ) . '/';
			foreach ( (array) ( $m['files'] ?? array() ) as $f ) {
				$rel = (string) ( $f['rel'] ?? '' );
				if ( '' === $rel ) {
					continue; }
				if ( '' !== $query && false === stripos( $rel . ' ' . $stamp, $query ) ) {
					continue; }
				$items[] = array(
					'stamp'   => $stamp,
					'type'    => 'file',
					'key'     => $rel,
					'label'   => $rel,
					'bytes'   => (int) ( $f['bytes'] ?? 0 ),
					'count'   => 1,
					'preview' => LucidLab_MPC_Helpers::is_image_rel( $rel ) ? $set_url . $rel : '',
					'source'  => sanitize_key( (string) ( $m['source'] ?? '' ) ),
				);
			}
			foreach ( (array) ( $m['failed_files'] ?? array() ) as $f ) {
				$rel = (string) ( $f['rel'] ?? '' );
				if ( '' === $rel ) {
					continue; }
				if ( '' !== $query && false === stripos( $rel . ' ' . $stamp, $query ) ) {
					continue; }
				$items[] = array(
					'stamp'   => $stamp,
					'type'    => 'file',
					'key'     => $rel,
					'label'   => $rel,
					'bytes'   => (int) ( $f['bytes'] ?? 0 ),
					'count'   => 1,
					'preview' => LucidLab_MPC_Helpers::is_image_rel( $rel ) ? $set_url . $rel : '',
					'source'  => sanitize_key( (string) ( $m['source'] ?? '' ) ),
				);
			}
			foreach ( (array) ( $m['attachments'] ?? array() ) as $a ) {
				$files = (array) ( $a['files'] ?? array() );
				$label = ( '' !== ( $a['post']['post_title'] ?? '' ) ) ? $a['post']['post_title'] : ( $files[0] ?? ( '#' . ( $a['orig_id'] ?? '' ) ) );
				if ( '' !== $query && false === stripos( $label . ' ' . implode( ' ', $files ) . ' ' . $stamp, $query ) ) {
					continue; }
				$preview = '';
				foreach ( $files as $rel ) {
					if ( LucidLab_MPC_Helpers::is_image_rel( (string) $rel ) && preg_match( '/-\d+x\d+\.[a-z0-9]+$/i', (string) $rel ) ) {
						$preview = $set_url . $rel; // Prefer a smaller generated size.
						break;
					}
				}
				if ( '' === $preview ) {
					foreach ( $files as $rel ) {
						if ( LucidLab_MPC_Helpers::is_image_rel( (string) $rel ) ) {
							$preview = $set_url . $rel;
							break;
						}
					}
				}
				$items[] = array(
					'stamp'   => $stamp,
					'type'    => 'attachment',
					'key'     => (string) ( $a['orig_id'] ?? '' ),
					'label'   => (string) $label,
					'bytes'   => $this->attachment_bytes( $stamp, $a ),
					'count'   => count( $files ),
					'preview' => $preview,
					'source'  => sanitize_key( (string) ( $m['source'] ?? '' ) ),
				);
			}
		}
		return $items;
	}

	/**
	 * Restore many items from one set with a single manifest write.
	 *
	 * @param string                                     $stamp Stamp.
	 * @param array<int,array{type?:string,key?:string}> $items Items.
	 *
	 * @return array
	 */
	public function restore_many( string $stamp, array $items ): array {
		$stamp = sanitize_file_name( $stamp );
		if ( '' === $stamp || ! file_exists( $this->manifest_path( $stamp ) ) ) {
			return array(
				'ok'    => 0,
				'fail'  => count( $items ),
				'error' => 'missing',
			);
		}
		$m          = $this->read_manifest( $stamp );
		$basedir    = trailingslashit( (string) ( $m['basedir'] ?? LucidLab_MPC_Helpers::uploads_basedir() ) );
		$set        = $this->set_dir( $stamp );
		$ok         = 0;
		$fail       = 0;
		$file_index = array();
		foreach ( (array) ( $m['files'] ?? array() ) as $i => $f ) {
			$rel = (string) ( $f['rel'] ?? '' );
			if ( '' !== $rel ) {
				$file_index[ $rel ] = $i;
			}
		}
		$attachment_index = array();
		foreach ( (array) ( $m['attachments'] ?? array() ) as $i => $a ) {
			$attachment_index[ (string) ( $a['orig_id'] ?? '' ) ] = $i;
		}
		foreach ( $items as $item ) {
			$type = ( $item['type'] ?? '' ) === 'attachment' ? 'attachment' : 'file';
			$key  = (string) ( $item['key'] ?? '' );
			if ( 'file' === $type ) {
				if ( ! isset( $file_index[ $key ] ) ) {
					++$ok;
					continue;
				}
				$i   = $file_index[ $key ];
				$rel = LucidLab_MPC_Helpers::sanitize_rel( $key );
				if ( '' !== $rel && $this->move( $set . '/' . $rel, $basedir . $rel, true ) ) {
					++$ok;
					$this->restore_size_meta( (array) $m['files'][ $i ] );
					unset( $m['files'][ $i ], $file_index[ $key ] );
				} else {
					++$fail;
				}
				continue;
			}
			if ( ! isset( $attachment_index[ $key ] ) ) {
				++$ok;
				continue;
			}
			$i     = $attachment_index[ $key ];
			$a     = $m['attachments'][ $i ];
			$moved = true;
			foreach ( (array) ( $a['files'] ?? array() ) as $rel ) {
				$rel = LucidLab_MPC_Helpers::sanitize_rel( $rel );
				if ( '' === $rel || ! $this->move( $set . '/' . $rel, $basedir . $rel, true ) ) {
					$moved = false;
				}
			}
			if ( $moved && $this->recreate_attachment( $a, $basedir ) ) {
				++$ok;
				unset( $m['attachments'][ $i ], $attachment_index[ $key ] );
			} else {
				++$fail;
			}
		}
		$m['files']       = array_values( (array) ( $m['files'] ?? array() ) );
		$m['attachments'] = array_values( (array) ( $m['attachments'] ?? array() ) );
		if ( empty( $m['files'] ) && empty( $m['attachments'] ) ) {
			$this->purge( $stamp );
		} else {
			$this->write_manifest( $stamp, $m );
		}
		return array(
			'ok'   => $ok,
			'fail' => $fail,
		);
	}

	/**
	 * Restore a single quarantined item (one loose file, or one whole attachment).
	 *
	 * @param string $stamp Stamp.
	 * @param string $type  Type.
	 * @param string $key   Key.
	 *
	 * @return array
	 */
	public function restore_one( string $stamp, string $type, string $key ): array {
		$stamp = sanitize_file_name( $stamp );
		if ( '' === $stamp || ! file_exists( $this->manifest_path( $stamp ) ) ) {
			return array(
				'ok'    => 0,
				'fail'  => 0,
				'error' => 'missing',
			);
		}
		$m    = $this->read_manifest( $stamp );
		$kind = sanitize_key( (string) ( $m['kind'] ?? '' ) );
		if ( 'db_rows' === $kind ) {
			return $this->restore_one_rows_file( $stamp, $key );
		}
		if ( 'db_tables' === $kind || 'dbcleaner' === sanitize_key( (string) ( $m['source'] ?? '' ) ) ) {
			return $this->restore_one_table( $stamp, $key );
		}
		$basedir = trailingslashit( (string) ( $m['basedir'] ?? LucidLab_MPC_Helpers::uploads_basedir() ) );
		$set     = $this->set_dir( $stamp );
		$ok      = 0;
		$fail    = 0;

		if ( 'file' === $type ) {
			foreach ( (array) ( $m['files'] ?? array() ) as $i => $f ) {
				if ( ( $f['rel'] ?? '' ) !== $key ) {
					continue; }
				$rel = LucidLab_MPC_Helpers::sanitize_rel( $key );
				if ( '' !== $rel && $this->move( $set . '/' . $rel, $basedir . $rel, true ) ) {
					++$ok;
					unset( $m['files'][ $i ] );
					$this->restore_size_meta( $f );
					if ( 'originals' === sanitize_key( (string) ( $m['source'] ?? '' ) ) ) {
						$this->after_originals_restored( $stamp, array( $rel ) );
					}
				} else {
					++$fail;
				}
				break;
			}
			$m['files'] = array_values( (array) ( $m['files'] ?? array() ) );
		} else {
			foreach ( (array) ( $m['attachments'] ?? array() ) as $i => $a ) {
				if ( (string) ( $a['orig_id'] ?? '' ) !== $key ) {
					continue; }
				$moved = true;
				foreach ( (array) ( $a['files'] ?? array() ) as $rel ) {
					$rel = LucidLab_MPC_Helpers::sanitize_rel( $rel );
					if ( '' === $rel || ! $this->move( $set . '/' . $rel, $basedir . $rel, true ) ) {
						$moved = false;
					}
				}
				if ( $moved && $this->recreate_attachment( $a, $basedir ) ) {
					++$ok;
					unset( $m['attachments'][ $i ] );
				} else {
					++$fail;
				}
				break;
			}
			$m['attachments'] = array_values( (array) ( $m['attachments'] ?? array() ) );
		}

		if ( empty( $m['files'] ) && empty( $m['attachments'] ) ) {
			$this->purge( $stamp );
		} else {
			$this->write_manifest( $stamp, $m );
		}
		return array(
			'ok'   => $ok,
			'fail' => $fail,
		);
	}

	/**
	 * Purge.
	 *
	 * @param string $stamp Stamp.
	 *
	 * @return bool
	 */
	public function purge( string $stamp ): bool {
		$stamp = sanitize_file_name( $stamp );
		if ( '' === $stamp || self::is_health_protected( $stamp ) ) {
			return false;
		}
		return $this->rrmdir( $this->set_dir( $stamp ) );
	}

	/**
	 * Purge all.
	 *
	 * @return int
	 */
	public function purge_all(): int {
		$n = 0;
		foreach ( (array) glob( trailingslashit( $this->root() ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$stamp = wp_basename( $dir );
			if ( self::is_health_protected( $stamp ) ) {
				continue;
			}
			if ( $this->rrmdir( $dir ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Purge only the sets whose manifest source matches.
	 *
	 * @param string $source Source.
	 *
	 * @return int
	 */
	public function purge_all_source( string $source ): int {
		$n = 0;
		foreach ( (array) glob( trailingslashit( $this->root() ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$stamp = wp_basename( $dir );
			if ( self::is_health_protected( $stamp ) ) {
				continue;
			}
			$m = $this->read_manifest_soft( $stamp );
			if ( ! is_array( $m ) ) {
				continue;
			}
			if ( (string) ( $m['source'] ?? 'uploads' ) === $source && $this->rrmdir( $dir ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Whether any quarantine set holds a file at this uploads-relative path.
	 *
	 * @param string $rel Rel.
	 *
	 * @return bool
	 */
	public function holds( string $rel ): bool {
		$rel = LucidLab_MPC_Helpers::sanitize_rel( $rel );
		if ( '' === $rel || ! is_dir( $this->root() ) ) {
			return false;
		}
		foreach ( (array) glob( trailingslashit( $this->root() ) . '*', GLOB_ONLYDIR ) as $dir ) {
			if ( file_exists( self::held_file( $dir . '/' . $rel ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Upload-relative paths stored in any quarantine set.
	 *
	 * @return array<string,bool>
	 */
	public function held_rel_set(): array {
		$set = array();
		if ( ! is_dir( $this->root() ) ) {
			return $set;
		}
		foreach ( (array) glob( trailingslashit( $this->root() ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$m = $this->read_manifest( wp_basename( $dir ) );
			foreach ( (array) ( $m['files'] ?? array() ) as $file ) {
				$rel = LucidLab_MPC_Helpers::sanitize_rel( (string) ( $file['rel'] ?? '' ) );
				if ( '' !== $rel ) {
					$set[ $rel ] = true;
				}
			}
		}
		return $set;
	}

	/**
	 * Rename a matching file in any quarantine set, so a later restore uses the new name.
	 *
	 * @param array<string,string> $pairs Old_rel => new_rel.
	 *
	 * @return array<string,string> Pairs that were renamed.
	 */
	public function rename_held( array $pairs ): array {
		$done = array();
		if ( ! $pairs || ! is_dir( $this->root() ) ) {
			return $done;
		}
		foreach ( (array) glob( trailingslashit( $this->root() ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$hits = array();
			foreach ( $pairs as $old => $new ) {
				$old = LucidLab_MPC_Helpers::sanitize_rel( (string) $old );
				$new = LucidLab_MPC_Helpers::sanitize_rel( (string) $new );
				if ( '' !== $old && '' !== $new && ! isset( $done[ $old ] ) && is_file( self::held_file( $dir . '/' . $old ) ) && ! file_exists( self::held_name( $dir . '/' . $new ) ) ) {
					$hits[ $old ] = $new;
				}
			}
			if ( ! $hits ) {
				continue;
			}
			$stamp   = wp_basename( $dir );
			$m       = $this->read_manifest( $stamp );
			$renamed = array();
			foreach ( $hits as $old => $new ) {
				wp_mkdir_p( dirname( $dir . '/' . $new ) );
				if ( rename( self::held_file( $dir . '/' . $old ), self::held_name( $dir . '/' . $new ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
					$renamed[ $old ] = $new;
					$done[ $old ]    = $new;
				}
			}
			if ( $renamed ) {
				$m = $this->retarget_manifest( $m, $renamed );
				foreach ( $renamed as $new ) {
					if ( ! $this->manifest_lists_rel( $m, (string) $new ) ) {
						$m['files'][] = array(
							'rel'   => $new,
							'bytes' => (int) ( is_file( self::held_name( $dir . '/' . $new ) ) ? filesize( self::held_name( $dir . '/' . $new ) ) : false ),
						);
					}
				}
				$this->write_manifest( $stamp, $m );
			}
		}
		return $done;
	}

	/**
	 * Manifest lists rel.
	 *
	 * @param array<string,mixed> $m   M.
	 * @param string              $rel Rel.
	 *
	 * @return bool
	 */
	private function manifest_lists_rel( array $m, string $rel ): bool {
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
		foreach ( array( 'files', 'failed_files' ) as $key ) {
			foreach ( (array) ( $m[ $key ] ?? array() ) as $file ) {
				if ( is_array( $file ) && ltrim( str_replace( '\\', '/', (string) ( $file['rel'] ?? '' ) ), '/' ) === $rel ) {
					return true;
				}
			}
		}
		foreach ( array( 'attachments', 'failed_attachments' ) as $key ) {
			foreach ( (array) ( $m[ $key ] ?? array() ) as $attachment ) {
				if ( ! is_array( $attachment ) ) {
					continue;
				}
				if ( ltrim( str_replace( '\\', '/', (string) ( $attachment['attached'] ?? '' ) ), '/' ) === $rel ) {
					return true;
				}
				foreach ( (array) ( $attachment['files'] ?? array() ) as $file ) {
					if ( ltrim( str_replace( '\\', '/', (string) $file ), '/' ) === $rel ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Point a quarantine manifest at the renamed upload paths so restore puts files back under the new names.
	 *
	 * @param array<string,mixed>  $m    M.
	 * @param array<string,string> $hits Old_rel => new_rel.
	 *
	 * @return array<string,mixed>
	 */
	private function retarget_manifest( array $m, array $hits ): array {
		foreach ( array( 'files', 'failed_files' ) as $key ) {
			if ( empty( $m[ $key ] ) || ! is_array( $m[ $key ] ) ) {
				continue;
			}
			foreach ( $m[ $key ] as $i => $file ) {
				if ( ! is_array( $file ) ) {
					continue;
				}
				$rel  = (string) ( $file['rel'] ?? '' );
				$next = $this->retarget_exact( $rel, $hits );
				if ( $next !== $rel ) {
					$m[ $key ][ $i ]['rel'] = $next;
				}
			}
		}
		foreach ( array( 'attachments', 'failed_attachments' ) as $key ) {
			if ( empty( $m[ $key ] ) || ! is_array( $m[ $key ] ) ) {
				continue;
			}
			foreach ( $m[ $key ] as $i => $attachment ) {
				if ( is_array( $attachment ) ) {
					$m[ $key ][ $i ] = $this->retarget_attachment_payload( $attachment, $hits );
				}
			}
		}
		return $m;
	}

	/**
	 * Retarget attachment payload.
	 *
	 * @param array<string,mixed>  $a    A.
	 * @param array<string,string> $hits Hits.
	 *
	 * @return array<string,mixed>
	 */
	private function retarget_attachment_payload( array $a, array $hits ): array {
		if ( isset( $a['attached'] ) ) {
			$a['attached'] = $this->retarget_exact( (string) $a['attached'], $hits );
		}
		if ( ! empty( $a['files'] ) && is_array( $a['files'] ) ) {
			foreach ( $a['files'] as $i => $rel ) {
				$a['files'][ $i ] = $this->retarget_exact( (string) $rel, $hits );
			}
		}
		if ( is_array( $a['metadata'] ?? null ) ) {
			$meta = $a['metadata'];
			if ( isset( $meta['file'] ) ) {
				$meta['file'] = $this->retarget_exact( (string) $meta['file'], $hits );
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$meta['original_image'] = $this->retarget_basename( (string) $meta['original_image'], $hits );
			}
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $k => $size ) {
					if ( is_array( $size ) && ! empty( $size['file'] ) ) {
						$meta['sizes'][ $k ]['file'] = $this->retarget_basename( (string) $size['file'], $hits );
					}
				}
			}
			$a['metadata'] = $meta;
		}
		if ( is_array( $a['post'] ?? null ) ) {
			foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
				if ( ! empty( $a['post'][ $field ] ) && is_string( $a['post'][ $field ] ) ) {
					$a['post'][ $field ] = $this->retarget_substrings( $a['post'][ $field ], $hits );
				}
			}
		}
		return $a;
	}

	/**
	 * Retarget exact.
	 *
	 * @param string               $value Value.
	 * @param array<string,string> $hits  Hits.
	 *
	 * @return string
	 */
	private function retarget_exact( string $value, array $hits ): string {
		$norm = ltrim( str_replace( '\\', '/', $value ), '/' );
		foreach ( $hits as $old => $new ) {
			if ( $value === (string) $old || $norm === (string) $old ) {
				return (string) $new;
			}
		}
		return $value;
	}

	/**
	 * Retarget basename.
	 *
	 * @param string               $value Value.
	 * @param array<string,string> $hits  Hits.
	 *
	 * @return string
	 */
	private function retarget_basename( string $value, array $hits ): string {
		$exact = $this->retarget_exact( $value, $hits );
		if ( $exact !== $value ) {
			return $exact;
		}
		foreach ( $hits as $old => $new ) {
			if ( wp_basename( (string) $old ) === $value ) {
				return wp_basename( (string) $new );
			}
		}
		return $value;
	}

	/**
	 * Retarget substrings.
	 *
	 * @param string               $text Text.
	 * @param array<string,string> $hits Hits.
	 *
	 * @return string
	 */
	private function retarget_substrings( string $text, array $hits ): string {
		uksort(
			$hits,
			static function ( $a, $b ): int {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);
		foreach ( $hits as $old => $new ) {
			if ( '' !== (string) $old ) {
				$text = str_replace( (string) $old, (string) $new, $text );
			}
		}
		return $text;
	}

	/**
	 * Rename originals.
	 *
	 * @param array<string,string> $pairs Pairs.
	 *
	 * @return array<string,string>
	 */
	public function rename_originals( array $pairs ): array {
		return $this->rename_held( $pairs );
	}

	/**
	 * Absolute path of a quarantined original, or an empty string.
	 *
	 * @param string $rel Rel.
	 *
	 * @return string
	 */
	public function find_original( string $rel ): string {
		$rel = ltrim( wp_normalize_path( $rel ), '/' );
		if ( '' === $rel || strpos( $rel, '../' ) !== false || ! is_dir( $this->root() ) ) {
			return '';
		}
		foreach ( (array) glob( trailingslashit( $this->root() ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$m = $this->read_manifest( wp_basename( $dir ) );
			if ( 'originals' !== (string) ( $m['source'] ?? '' ) ) {
				continue;
			}
			$path = wp_normalize_path( self::held_file( $dir . '/' . $rel ) );
			if ( is_file( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Remember which Media Library attachment owned originals moved into this set, so restore can retarget the record back to JPEG/PNG.
	 *
	 * @param string   $stamp         Stamp.
	 * @param int      $attachment_id Attachment id.
	 * @param string[] $rels          Rels.
	 */
	public function remember_originals_attachment( string $stamp, int $attachment_id, array $rels = array() ): void {
		$stamp         = sanitize_file_name( $stamp );
		$attachment_id = (int) $attachment_id;
		if ( '' === $stamp || $attachment_id < 1 || ! file_exists( $this->manifest_path( $stamp ) ) ) {
			return;
		}
		$m = $this->read_manifest( $stamp );
		if ( 'originals' !== (string) ( $m['source'] ?? '' ) ) {
			return;
		}
		$ids                 = array_map( 'intval', (array) ( $m['attachment_ids'] ?? array() ) );
		$ids[]               = $attachment_id;
		$m['attachment_ids'] = array_values( array_unique( array_filter( $ids ) ) );
		$map                 = is_array( $m['attachment_map'] ?? null ) ? $m['attachment_map'] : array();
		foreach ( $rels as $rel ) {
			$rel = ltrim( wp_normalize_path( (string) $rel ), '/' );
			if ( '' !== $rel && strpos( $rel, '../' ) === false ) {
				$map[ $rel ] = $attachment_id;
			}
		}
		$m['attachment_map'] = $map;
		$this->write_manifest( $stamp, $m );
	}

	/**
	 * After originals files are restored to uploads, point attachments back at JPEG/PNG.
	 *
	 * @param string   $stamp Stamp.
	 * @param string[] $rels  Rels.
	 */
	public function after_originals_restored( string $stamp, array $rels ): void {
		$stamp = sanitize_file_name( $stamp );
		$rels  = array_values(
			array_filter(
				array_map(
					static function ( $rel ) {
						$rel = ltrim( wp_normalize_path( (string) $rel ), '/' );
						return ( '' !== $rel && strpos( $rel, '../' ) === false ) ? $rel : '';
					},
					$rels
				)
			)
		);
		if ( ! $rels || ! class_exists( 'LucidLab_MPC_Webp', false ) ) {
			return;
		}
		$ids = array();
		if ( '' !== $stamp && file_exists( $this->manifest_path( $stamp ) ) ) {
			$m   = $this->read_manifest( $stamp );
			$ids = array_map( 'intval', (array) ( $m['attachment_ids'] ?? array() ) );
			foreach ( (array) ( $m['attachment_map'] ?? array() ) as $rel => $id ) {
				if ( in_array( ltrim( wp_normalize_path( (string) $rel ), '/' ), $rels, true ) ) {
					$ids[] = (int) $id;
				}
			}
		}
		( new LucidLab_MPC_Webp() )->retarget_attachments_to_originals( $rels, $ids );
	}

	/**
	 * Relative path => bytes for originals moved aside during conversion.
	 *
	 * @return array<string,int>
	 */
	public function original_byte_map(): array {
		$map = array();
		if ( ! is_dir( $this->root() ) ) {
			return $map;
		}
		foreach ( (array) glob( trailingslashit( $this->root() ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$m = $this->read_manifest( wp_basename( $dir ) );
			if ( 'originals' !== (string) ( $m['source'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $m['files'] ?? array() ) as $file ) {
				$rel = ltrim( wp_normalize_path( (string) ( $file['rel'] ?? '' ) ), '/' );
				if ( '' === $rel ) {
					continue;
				}
				$map[ $rel ] = (int) ( $file['bytes'] ?? 0 );
			}
		}
		return $map;
	}

	/**
	 * Purge expired.
	 */
	public function purge_expired(): void {
		if ( ! LucidLab_MPC_Edition::pro() ) {
			return;
		}
		if ( empty( LucidLab_MPC_Settings::get( 'quarantine_auto_purge' ) ) ) {
			return;
		}
		if ( ! is_dir( $this->root() ) ) {
			return;
		}
		$this->protect_dir( $this->root() );
		foreach ( (array) glob( trailingslashit( $this->root() ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$stamp = wp_basename( $dir );
			$m     = $this->read_manifest( $stamp );
			if ( 'originals' === (string) ( $m['source'] ?? '' ) ) {
				continue;
			}
			$t = self::stamp_time( $stamp );
			if ( $t && ( time() - $t ) > ( self::retention_days() * DAY_IN_SECONDS ) ) {
				$this->rrmdir( $dir );
			}
		}
	}

	/**
	 * Rrmdir.
	 *
	 * @param string $dir Dir.
	 *
	 * @return bool
	 */
	private function rrmdir( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : LucidLab_MPC_Helpers::delete_file( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		return rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
	}
}
