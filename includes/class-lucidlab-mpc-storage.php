<?php
/**
 * Lucid Lab Media & Products Cleanup: storage locations.
 *
 * Everything the plugin writes to disk lives under one folder in the uploads directory of the
 * current site: wp_upload_dir()['basedir'] . '/lucidlab-mpc-cleanup/'. On multisite every site
 * has its own uploads basedir, so every site has its own folder.
 *
 * Private subfolders (work files, quarantine, backups) get index.php, .htaccess (deny) and
 * web.config (deny). Public subfolders (WebP/AVIF copies and the format comparison images that
 * the browser has to load) only get index.php against directory listing.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin storage folders under uploads, plus the one-time move from the old locations. */
class LucidLab_MPC_Storage {

	/** Folder name under uploads (the plugin slug). */
	public const SLUG = 'lucidlab-mpc-cleanup';

	/** Option with the result of the one-time move from the old folders. */
	public const MIGRATION_OPTION = 'lucidlab_mpc_storage_migration';

	/** Version of the folder layout; bump to run the move again. */
	public const LAYOUT = 1;

	/** Subfolders the browser must be able to load. */
	public const PUBLIC_DIRS = array( 'webp', 'avif', 'format-preview' );

	/**
	 * Folders this plugin created directly in uploads by older versions, keyed by their new subfolder.
	 * Backups are listed by legacy_backups() because their names carry a random key.
	 */
	public const LEGACY = array(
		'work'           => '_cleanup-fsindex',
		'quarantine'     => '_cleanup-quarantine',
		'format-preview' => '_cleanup-format-preview',
		'webp'           => 'webp',
		'avif'           => 'avif',
	);

	/**
	 * Resolved folders per uploads base (multisite switches the base).
	 *
	 * @var array<string,string>
	 */
	private static array $resolved = array();

	/**
	 * Uploads basedir of the current site, normalised, without a trailing slash.
	 *
	 * @return string
	 */
	public static function uploads(): string {
		return rtrim( str_replace( '\\', '/', LucidLab_MPC_Helpers::uploads_basedir() ), '/' );
	}

	/**
	 * Plugin folder in uploads (not created).
	 *
	 * @return string
	 */
	public static function base(): string {
		return self::uploads() . '/' . self::SLUG;
	}

	/**
	 * Public URL of the plugin folder in uploads.
	 *
	 * @return string
	 */
	public static function base_url(): string {
		return rtrim( LucidLab_MPC_Helpers::uploads_baseurl(), '/' ) . '/' . self::SLUG;
	}

	/**
	 * Public URL of a path inside uploads, or '' when the path is outside uploads.
	 *
	 * @param string $abs Absolute path.
	 *
	 * @return string
	 */
	public static function url_for( string $abs ): string {
		$abs = str_replace( '\\', '/', $abs );
		$up  = self::uploads();
		return '' !== $up && str_starts_with( $abs, $up . '/' ) ? rtrim( LucidLab_MPC_Helpers::uploads_baseurl(), '/' ) . substr( $abs, strlen( $up ) ) : '';
	}

	/**
	 * Whether a subfolder must stay readable over the web.
	 *
	 * @param string $sub Subfolder.
	 *
	 * @return bool
	 */
	public static function is_public( string $sub ): bool {
		return in_array( $sub, self::PUBLIC_DIRS, true );
	}

	/**
	 * Old location of a subfolder directly in uploads, or ''.
	 *
	 * @param string $sub Subfolder.
	 *
	 * @return string
	 */
	public static function legacy( string $sub ): string {
		if ( isset( self::LEGACY[ $sub ] ) ) {
			return self::uploads() . '/' . self::LEGACY[ $sub ];
		}
		if ( preg_match( '/^backups-([a-f0-9]{16,64})$/', $sub, $m ) ) {
			return self::uploads() . '/lucidlab-mpc-backups-' . $m[1];
		}
		return '';
	}

	/**
	 * Absolute path of a plugin subfolder. Uses the new location, except while an old folder
	 * still exists because moving it failed: then the old folder keeps being used so no data is lost.
	 *
	 * @param string $sub    Subfolder (a-z, 0-9 and dashes).
	 * @param bool   $create Create and protect the folder.
	 *
	 * @throws InvalidArgumentException When the subfolder name is not allowed.
	 * @return string
	 */
	public static function dir( string $sub, bool $create = true ): string {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,80}$/', $sub ) ) {
			throw new InvalidArgumentException( 'Invalid storage folder.' );
		}
		$key = self::uploads() . '|' . $sub;
		if ( isset( self::$resolved[ $key ] ) && ( ! $create || is_dir( self::$resolved[ $key ] ) ) ) {
			return self::$resolved[ $key ];
		}
		$new    = self::base() . '/' . $sub;
		$legacy = self::legacy( $sub );
		// WebP/AVIF copies are always written to the new folder; old copies are only read (see LucidLab_MPC_Webp).
		$nextgen = in_array( $sub, array( 'webp', 'avif' ), true );
		if ( ! $nextgen && ! is_dir( $new ) && '' !== $legacy && is_dir( $legacy ) && ! self::legacy_is_shadowed( $sub ) ) {
			$path = $legacy;
		} else {
			$path = $new;
			if ( $create ) {
				self::ensure( $sub );
			}
		}
		self::$resolved[ $key ] = $path;
		return $path;
	}

	/**
	 * Path of a file in a plugin subfolder.
	 *
	 * @param string $sub  Subfolder.
	 * @param string $name File name.
	 *
	 * @return string
	 */
	public static function path( string $sub, string $name ): string {
		return self::dir( $sub ) . '/' . sanitize_file_name( $name );
	}

	/**
	 * Whether a path lies inside the plugin folder or one of its old locations.
	 *
	 * @param string $path Path.
	 *
	 * @return bool
	 */
	public static function owns( string $path ): bool {
		$path = str_replace( '\\', '/', $path );
		$dirs = array( self::base() );
		foreach ( array_keys( self::LEGACY ) as $sub ) {
			if ( ! self::is_public( $sub ) ) {
				$dirs[] = self::legacy( $sub );
			}
		}
		foreach ( (array) glob( self::uploads() . '/lucidlab-mpc-backups-*', GLOB_ONLYDIR ) as $dir ) {
			$dirs[] = str_replace( '\\', '/', (string) $dir );
		}
		foreach ( $dirs as $dir ) {
			if ( '' !== $dir && ( $path === $dir || str_starts_with( $path, $dir . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Create the plugin folder and a subfolder with its protection files.
	 *
	 * @param string $sub Subfolder.
	 *
	 * @throws RuntimeException When the folder cannot be created.
	 */
	private static function ensure( string $sub ): void {
		$base = self::base();
		$dir  = $base . '/' . $sub;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( sprintf( /* translators: %s: folder path. */ __( 'Could not create the folder %s.', 'lucidlab-mpc-cleanup' ), $dir ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		self::protect( $base, false );
		self::protect( $dir, ! self::is_public( $sub ) );
	}

	/**
	 * Write index.php (no directory listing) and, for private folders, deny rules for Apache and IIS.
	 *
	 * @param string $dir  Folder.
	 * @param bool   $deny Deny all web access.
	 */
	public static function protect( string $dir, bool $deny ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = array( 'index.php' => "<?php\n// Silence is golden.\n" );
		if ( $deny ) {
			$files['.htaccess']  = "Require all denied\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
			$files['web.config'] = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n";
		}
		foreach ( $files as $name => $body ) {
			if ( ! is_file( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Protection files in the plugin's own uploads folder; WP_Filesystem needs credentials during background requests.
			}
		}
	}

	/**
	 * An old folder is not used again once the move recorded it as moved (a later empty copy,
	 * for example from restoring an old full-site backup, must not hide the new folder).
	 *
	 * @param string $sub Subfolder.
	 *
	 * @return bool
	 */
	private static function legacy_is_shadowed( string $sub ): bool {
		$state = get_option( self::MIGRATION_OPTION, array() );
		return is_array( $state ) && 'moved' === ( $state['dirs'][ $sub ] ?? '' );
	}

	/**
	 * Old backup folders (uploads/lucidlab-mpc-backups-<key>) mapped to their new subfolder.
	 *
	 * @return array<string,string> New subfolder => old absolute path.
	 */
	public static function legacy_backups(): array {
		$out = array();
		foreach ( (array) glob( self::uploads() . '/lucidlab-mpc-backups-*', GLOB_ONLYDIR ) as $dir ) {
			$dir = str_replace( '\\', '/', (string) $dir );
			if ( preg_match( '/\/lucidlab-mpc-backups-([a-f0-9]{16,64})$/', $dir, $m ) ) {
				$out[ 'backups-' . $m[1] ] = $dir;
			}
		}
		return $out;
	}

	/**
	 * Whether the one-time move still has to run on this site.
	 *
	 * @return bool
	 */
	public static function needs_migration(): bool {
		$state = get_option( self::MIGRATION_OPTION, array() );
		return ! is_array( $state ) || (int) ( $state['layout'] ?? 0 ) < self::LAYOUT || ! empty( $state['retry'] );
	}

	/**
	 * Move the folders of older versions into uploads/lucidlab-mpc-cleanup/. Runs once per site
	 * (again on a later admin request when a move failed). A folder is moved with one rename, so
	 * its files, manifests and random folder names stay as they are. If a rename fails, the old
	 * folder stays where it is and keeps being used (see dir()); nothing is copied or deleted.
	 *
	 * @param bool $force Run even when the move is recorded as done.
	 *
	 * @return array{layout:int,dirs:array<string,string>,retry:bool,time:int}
	 */
	public static function migrate( bool $force = false ): array {
		$state = get_option( self::MIGRATION_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		if ( ! $force && ! self::needs_migration() ) {
			return $state + array(
				'layout' => self::LAYOUT,
				'dirs'   => array(),
				'retry'  => false,
				'time'   => 0,
			);
		}
		// One request at a time; never move a folder while a backup or restore is writing to it.
		if ( ! $force && ! add_option( self::MIGRATION_OPTION . '_lock', time(), '', false ) ) {
			$since = (int) get_option( self::MIGRATION_OPTION . '_lock', 0 );
			if ( $since > time() - 300 ) {
				return $state + array(
					'layout' => 0,
					'dirs'   => array(),
					'retry'  => true,
					'time'   => 0,
				);
			}
			update_option( self::MIGRATION_OPTION . '_lock', time(), false );
		}
		if ( class_exists( 'LucidLab_MPC_Backup', false ) && in_array( (string) ( LucidLab_MPC_Backup::job()['status'] ?? '' ), array( 'running', 'paused' ), true ) ) {
			$state['retry'] = true;
			delete_option( self::MIGRATION_OPTION . '_lock' );
			return $state + array(
				'layout' => 0,
				'dirs'   => array(),
				'time'   => 0,
			);
		}
		$dirs  = is_array( $state['dirs'] ?? null ) ? $state['dirs'] : array();
		$retry = false;
		$pairs = array();
		foreach ( array_keys( self::LEGACY ) as $sub ) {
			$pairs[ $sub ] = self::legacy( $sub );
		}
		$pairs = array_merge( $pairs, self::legacy_backups() );
		foreach ( $pairs as $sub => $legacy ) {
			if ( '' === $legacy || ! is_dir( $legacy ) ) {
				if ( ! isset( $dirs[ $sub ] ) ) {
					$dirs[ $sub ] = 'none';
				}
				continue;
			}
			if ( in_array( $sub, array( 'webp', 'avif' ), true ) ) {
				// Existing WebP/AVIF copies stay where they are: Media Library files and post content can point at
				// their URLs. They keep being served and found there; only new copies go to the plugin folder.
				$dirs[ $sub ] = 'kept';
				continue;
			}
			$dirs[ $sub ] = self::move_dir( $legacy, self::base() . '/' . $sub, ! self::is_public( $sub ) );
			if ( 'failed' === $dirs[ $sub ] ) {
				$retry = true;
			}
		}
		// Older stored database dumps (.sql) are compressed to .sql.gz, a few at a time.
		if ( class_exists( 'LucidLab_MPC_Backup' ) && ! LucidLab_MPC_Backup::compress_legacy_sql( 15.0 ) ) {
			$retry = true;
		}
		$state = array(
			'layout' => self::LAYOUT,
			'dirs'   => $dirs,
			'retry'  => $retry,
			'time'   => time(),
		);
		update_option( self::MIGRATION_OPTION, $state, true );
		delete_option( self::MIGRATION_OPTION . '_lock' );
		self::$resolved = array();
		if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
			LucidLab_MPC_Logger::log( 'Plugin folders in uploads/' . self::SLUG . '/: ' . (string) wp_json_encode( $dirs ) );
		}
		return $state;
	}

	/**
	 * Move one old folder to its new place.
	 *
	 * @param string $from Old folder.
	 * @param string $to   New folder.
	 * @param bool   $deny Protect the new folder against web access.
	 *
	 * @return string moved|merged|failed
	 */
	private static function move_dir( string $from, string $to, bool $deny ): string {
		if ( ! is_dir( self::base() ) && ! wp_mkdir_p( self::base() ) ) {
			return 'failed';
		}
		self::protect( self::base(), false );
		if ( ! file_exists( $to ) ) {
			if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- One atomic folder rename inside uploads; a failure is handled (the old folder stays in use).
				self::protect( $to, $deny );
				return 'moved';
			}
			return 'failed';
		}
		// Both exist (for example the new folder was created by a newer request first): move the
		// entries that do not exist yet, keep the rest in the old folder.
		$left = 0;
		foreach ( (array) scandir( $from ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			if ( file_exists( $to . '/' . $name ) || ! @rename( $from . '/' . $name, $to . '/' . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Rename inside uploads; a failure keeps the entry in place.
				if ( ! in_array( $name, array( 'index.php', '.htaccess', 'web.config' ), true ) ) {
					++$left;
				}
			}
		}
		self::protect( $to, $deny );
		if ( 0 === $left ) {
			foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $name ) {
				if ( is_file( $from . '/' . $name ) ) {
					wp_delete_file( $from . '/' . $name );
				}
			}
			@rmdir( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the now empty old folder; leaving it is harmless.
			return 'moved';
		}
		return 'failed';
	}

	/**
	 * Every folder the plugin uses or used on this site (for Reset and uninstall).
	 *
	 * @return array<int,string>
	 */
	public static function all_dirs(): array {
		$dirs = array( self::base() );
		foreach ( array_keys( self::LEGACY ) as $sub ) {
			if ( ! in_array( $sub, array( 'webp', 'avif' ), true ) ) {
				$dirs[] = self::legacy( $sub );
			}
		}
		return array_merge( $dirs, array_values( self::legacy_backups() ) );
	}

	/**
	 * Forget resolved folders (tests, switch_to_blog()).
	 */
	public static function flush(): void {
		self::$resolved = array();
	}
}
