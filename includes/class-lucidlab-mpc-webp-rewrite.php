<?php
/**
 * Lucid Lab Media & Products Cleanup: webp rewrite class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Apache rules that serve WebP or AVIF copies for the original JPEG/PNG address. */
class LucidLab_MPC_Webp_Rewrite {

	public const BEGIN = '# BEGIN Media Products Cleanup WebP';
	public const END   = '# END Media Products Cleanup WebP';

	/**
	 * Rules.
	 *
	 * @param string $uploads_dir Uploads dir.
	 *
	 * @return string
	 */
	public static function rules( string $uploads_dir ): string {
		$dir  = rtrim( str_replace( '\\', '/', $uploads_dir ), '/' );
		$pro  = LucidLab_MPC_Edition::pro();
		$own  = LucidLab_MPC_Storage::SLUG . '/';
		$flag = 'E=lucidlab_mpc_image_vary:1,L]';
		// Copies made in this version live in uploads/lucidlab-mpc-cleanup/{webp,avif}/; older ones in uploads/{webp,avif}/ keep working.
		$lines = array(
			self::BEGIN,
			// Copies are named <original>.jpg.avif; Apache servers whose mime.types has no .avif entry would
			// otherwise send them with the .jpg type (image/jpeg). The rightmost known extension wins.
			'<IfModule mod_mime.c>',
			'AddType image/avif .avif',
			'AddType image/webp .webp',
			'</IfModule>',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteRule ^(' . $own . 'webp|' . $own . 'format-preview|' . $own . 'work|' . $own . 'quarantine|webp|_cleanup-quarantine|_cleanup-format-preview|_cleanup-fsindex|mpc-releases)/ - [L]',
			'RewriteRule ^' . $own . 'backups- - [L]',
		);
		// An AVIF address whose file is missing (Pro), or any AVIF address (Free, which stops selecting AVIF), gets its WebP copy.
		$avif_pass = 'RewriteRule ^(' . $own . ')?avif/.+\\.avif$ - [NC,T=image/avif,L]';
		if ( $pro ) {
			$lines[] = 'RewriteCond %{REQUEST_FILENAME} -s';
			$lines[] = $avif_pass;
			$lines[] = 'RewriteCond %{REQUEST_FILENAME} -s';
			$lines[] = 'RewriteRule ^(' . $own . ')?avif/ - [L]';
		}
		foreach ( array( $own, '' ) as $prefix ) {
			if ( ! $pro ) {
				$lines[] = 'RewriteCond %{REQUEST_FILENAME} -s';
			}
			$lines[] = 'RewriteCond "' . $dir . '/' . $prefix . 'webp/$1.webp" -s';
			$lines[] = 'RewriteRule ^' . $prefix . 'avif/(.+)\\.avif$ ' . $prefix . 'webp/$1.webp [NC,T=image/webp,' . $flag;
		}
		if ( ! $pro ) {
			$lines[] = $avif_pass;
			$lines[] = 'RewriteRule ^(' . $own . ')?avif/ - [L]';
		}
		$formats = $pro ? array( 'avif', 'webp' ) : array( 'webp' );
		foreach ( $formats as $fmt ) {
			foreach ( array( $own, '' ) as $prefix ) {
				$lines[] = 'RewriteCond %{HTTP_ACCEPT} image/' . $fmt . ' [NC]';
				$lines[] = 'RewriteCond "' . $dir . '/' . $prefix . $fmt . '/$1.' . $fmt . '" -s';
				$lines[] = 'RewriteRule ^(.+\\.(jpe?g|png))$ ' . $prefix . $fmt . '/$1.' . $fmt . ' [NC,T=image/' . $fmt . ',' . $flag;
			}
		}
		$lines = array_merge(
			$lines,
			array(
				'</IfModule>',
				'<IfModule mod_headers.c>',
				// Responses chosen by the Accept header must not be cached for browsers that sent another one.
				'Header append Vary Accept env=REDIRECT_lucidlab_mpc_image_vary',
				'Header append Vary Accept env=lucidlab_mpc_image_vary',
				'</IfModule>',
				self::END,
			)
		);
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Merge.
	 *
	 * @param string $existing Existing.
	 * @param string $rules    Rules.
	 *
	 * @return string
	 */
	public static function merge( string $existing, string $rules ): string {
		// Only the marker block changes: it is replaced where it stands (a duplicate block is dropped) and
		// everything before and after it is kept byte for byte. Without a block the rules are appended.
		$pattern = '/^' . preg_quote( self::BEGIN, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '[ \t]*(?:\r?\n)?/ms';
		if ( preg_match( $pattern, $existing ) ) {
			$first = true;
			$next  = preg_replace_callback(
				$pattern,
				static function () use ( &$first, $rules ): string {
					$out   = $first ? $rules : '';
					$first = false;
					return $out;
				},
				$existing
			);
			if ( ! is_string( $next ) ) {
				return $existing;
			}
			return '' === trim( $next ) ? '' : $next;
		}
		if ( '' === trim( $rules ) || '' === $existing ) {
			return '' === trim( $rules ) ? $existing : $rules;
		}
		return $existing . ( str_ends_with( $existing, "\n" ) ? "\n" : "\n\n" ) . $rules;
	}

	public const HASH_OPTION = 'lucidlab_mpc_webp_rewrite_hash';

	/**
	 * Sync.
	 */
	public static function sync(): void {
		if ( ! class_exists( 'LucidLab_MPC_Helpers', false ) ) {
			return;
		}
		$dir  = LucidLab_MPC_Helpers::uploads_basedir();
		$file = rtrim( $dir, '/\\' ) . '/.htaccess';
		$sig  = md5( self::rules( $dir ) . '|' . ( is_file( $file ) ? filemtime( $file ) . ':' . filesize( $file ) : 'none' ) );
		if ( get_option( self::HASH_OPTION ) === $sig ) {
			return;
		}
		$caps = LucidLab_MPC_Helpers::nextgen_caps();
		self::write( ! empty( $caps['webp'] ) || ! empty( $caps['avif'] ) );
		clearstatcache( true, $file );
		update_option( self::HASH_OPTION, md5( self::rules( $dir ) . '|' . ( is_file( $file ) ? filemtime( $file ) . ':' . filesize( $file ) : 'none' ) ), false );
	}

	/**
	 * Remove.
	 */
	public static function remove(): void {
		delete_option( self::HASH_OPTION );
		self::write( false );
	}

	/**
	 * Write.
	 *
	 * @param bool $enabled Enabled.
	 */
	private static function write( bool $enabled ): void {
		if ( ! class_exists( 'LucidLab_MPC_Helpers', false ) ) {
			return;
		}
		$dir  = LucidLab_MPC_Helpers::uploads_basedir();
		$file = rtrim( $dir, '/\\' ) . '/.htaccess';
		if ( ! is_dir( $dir ) || ( ! wp_is_writable( $dir ) && ! wp_is_writable( $file ) ) ) {
			return;
		}
		$current = is_file( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		$next    = self::merge( $current, $enabled ? self::rules( $dir ) : '' );
		if ( $next === $current ) {
			return;
		}
		// The file held nothing but this plugin's block: remove it instead of leaving an empty .htaccess.
		if ( '' === trim( $next ) ) {
			wp_delete_file( $file );
			return;
		}
		file_put_contents( $file, $next ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
	}
}
