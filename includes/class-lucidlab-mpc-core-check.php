<?php
/**
 * Lucid Lab Media & Products Cleanup: core check class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Compare installed WordPress core files to the official package for this version.
 *
 * Uses api.wordpress.org checksums (MD5) plus the release zip for sizes, replace and compare.
 */
final class LucidLab_MPC_Core_Check {
	public const RESULT_OPTION  = 'lucidlab_mpc_core_check_result';
	public const PACKAGE_OPTION = 'lucidlab_mpc_core_check_package';

	/**
	 * Scan.
	 *
	 * @return array{version:string,locale:string,ok:bool,counts:array,items:array,message:string,checked_at:int}
	 * @throws RuntimeException When the operation fails.
	 */
	public static function scan(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new RuntimeException( __( 'Permission denied.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once LucidLab_MPC_Helpers::admin_dir() . 'includes/update.php';
		}

		global $wp_version;
		$version   = (string) $wp_version;
		$locale    = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
		$checksums = get_core_checksums( $version, $locale );
		if ( ! is_array( $checksums ) || ! $checksums ) {
			$checksums = get_core_checksums( $version, 'en_US' );
		}
		if ( ! is_array( $checksums ) || ! $checksums ) {
			throw new RuntimeException( __( 'Could not download WordPress core checksums. Check that this site can reach api.wordpress.org.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		// Localized packages ship their own readme.html and wp-includes/version.php. A site installed in English
		// and later switched to another language still has the English files, so either official checksum is accepted.
		$alternate = 'en_US' !== $locale ? get_core_checksums( $version, 'en_US' ) : array();
		$alternate = is_array( $alternate ) ? $alternate : array();

		$package = self::ensure_package();
		$zip     = null;
		if ( ! empty( $package['zip'] ) && is_readable( (string) $package['zip'] ) && class_exists( 'ZipArchive', false ) ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( (string) $package['zip'] ) ) {
				$zip = null;
			}
		}

		$items = array();
		foreach ( $checksums as $rel => $md5 ) {
			$rel = self::normalize_rel( (string) $rel );
			if ( '' === $rel || str_starts_with( $rel, 'wp-content/' ) ) {
				continue;
			}
			$path          = ABSPATH . $rel;
			$expected_md5  = strtolower( (string) $md5 );
			$expected_size = self::zip_size( $zip, $rel );
			if ( ! is_file( $path ) ) {
				$items[] = self::item( $rel, 'changed', __( 'Missing from this install.', 'lucidlab-mpc-cleanup' ), 0, $expected_size, '', $expected_md5 );
				continue;
			}
			$local_size = (int) filesize( $path );
			$local_md5  = strtolower( (string) md5_file( $path ) );
			$size_diff  = null !== $expected_size && $local_size !== $expected_size;
			$alt_md5    = strtolower( (string) ( $alternate[ $rel ] ?? '' ) );
			$hash_diff  = $local_md5 !== $expected_md5 && ( '' === $alt_md5 || $local_md5 !== $alt_md5 );
			if ( ! $hash_diff ) {
				// The checksum is authoritative; the package size can come from another locale.
				continue;
			}
			if ( $size_diff ) {
				$items[] = self::item( $rel, 'size', __( 'File size differs from the official package.', 'lucidlab-mpc-cleanup' ), $local_size, $expected_size, $local_md5, $expected_md5 );
			} elseif ( $hash_diff ) {
				$items[] = self::item( $rel, 'changed', __( 'File contents differ from the official checksum.', 'lucidlab-mpc-cleanup' ), $local_size, $expected_size, $local_md5, $expected_md5 );
			}
		}

		self::collect_extras( untrailingslashit( LucidLab_MPC_Helpers::admin_dir() ), LucidLab_MPC_Helpers::admin_rel(), $checksums, $items );
		self::collect_extras( ABSPATH . WPINC, WPINC, $checksums, $items );
		self::collect_root_extras( $checksums, $items );

		if ( $zip instanceof ZipArchive ) {
			$zip->close();
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				$order = array(
					'extra'   => 0,
					'changed' => 1,
					'size'    => 2,
				);
				$ka    = $order[ $a['kind'] ] ?? 9;
				$kb    = $order[ $b['kind'] ] ?? 9;
				return $ka === $kb ? strcasecmp( (string) $a['path'], (string) $b['path'] ) : $ka <=> $kb;
			}
		);

		$counts = array(
			'extra'   => 0,
			'changed' => 0,
			'size'    => 0,
			'total'   => count( $items ),
		);
		foreach ( $items as $item ) {
			$kind = (string) ( $item['kind'] ?? '' );
			if ( isset( $counts[ $kind ] ) ) {
				++$counts[ $kind ];
			}
		}

		$ok     = 0 === (int) $counts['total'];
		$result = array(
			'version'    => $version,
			'locale'     => $locale,
			'ok'         => $ok,
			'counts'     => $counts,
			'items'      => $items,
			'package'    => ! empty( $package['zip'] ),
			'checked_at' => time(),
			'message'    => $ok
				? sprintf(
					/* translators: %s: WordPress version */
					__( 'WordPress %s core files match the official package.', 'lucidlab-mpc-cleanup' ),
					$version
				)
				: sprintf(
					/* translators: 1: WordPress version, 2: issue count */
					__( 'WordPress %1$s: %2$d file(s) need attention.', 'lucidlab-mpc-cleanup' ),
					$version,
					(int) $counts['total']
				),
			'headline'   => sprintf(
				/* translators: %s: WordPress version */
				__( 'WordPress %s', 'lucidlab-mpc-cleanup' ),
				$version
			),
			'attention'  => $ok
				? ''
				: sprintf(
					/* translators: %d: issue count */
					_n( '%d file(s) need attention.', '%d file(s) need attention.', (int) $counts['total'], 'lucidlab-mpc-cleanup' ),
					(int) $counts['total']
				),
		);
		update_option( self::RESULT_OPTION, $result, false );
		return $result;
	}

	/**
	 * Ensure package.
	 *
	 * @return array{zip?:string,version?:string}|array{}
	 */
	public static function ensure_package(): array {
		// Core checks use official WordPress checksums without writing a package into wp-content.
		return array();
	}

	/**
	 * Collect extras.
	 *
	 * @param string               $dir       Dir.
	 * @param string               $prefix    Prefix.
	 * @param array<string,string> $checksums Checksums.
	 * @param array                &$items     Items.
	 */
	private static function collect_extras( string $dir, string $prefix, array $checksums, array &$items ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}
			$full = $file->getPathname();
			$rel  = self::normalize_rel( $prefix . '/' . ltrim( str_replace( '\\', '/', substr( $full, strlen( $dir ) ) ), '/' ) );
			if ( '' === $rel || isset( $checksums[ $rel ] ) || isset( $checksums[ str_replace( '\\', '/', $rel ) ] ) ) {
				continue;
			}
			// Skip language packs and caches that hosts often add beside core.
			if ( preg_match( '#(^|/)(cache|node_modules|\.git)(/|$)#i', $rel ) ) {
				continue;
			}
			$items[] = self::item( $rel, 'extra', __( 'Not part of the official WordPress package.', 'lucidlab-mpc-cleanup' ), (int) $file->getSize(), null, '', '' );
		}
	}

	/**
	 * Collect root extras.
	 *
	 * @param array<string,string> $checksums Checksums.
	 * @param array                &$items     Items.
	 */
	private static function collect_root_extras( array $checksums, array &$items ): void {
		$skip = array(
			'wp-config.php',
			'wp-config-sample.php',
			'.htaccess',
			'nginx.conf',
			'web.config',
			'license.txt',
			'readme.html',
		);
		foreach ( (array) scandir( ABSPATH ) as $name ) {
			if ( '.' === $name || '..' === $name || in_array( $name, $skip, true ) ) {
				continue;
			}
			$path = ABSPATH . $name;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$rel = self::normalize_rel( $name );
			if ( '' === $rel || isset( $checksums[ $rel ] ) ) {
				continue;
			}
			// Only flag unexpected code-like files at the WordPress root.
			if ( ! preg_match( '/\.(php|phar|phtml|js)$/i', $name ) ) {
				continue;
			}
			$items[] = self::item( $rel, 'extra', __( 'Unexpected file in the WordPress root.', 'lucidlab-mpc-cleanup' ), (int) filesize( $path ), null, '', '' );
		}
	}

	/**
	 * Item.
	 *
	 * @param string   $path          Path.
	 * @param string   $kind          Kind.
	 * @param string   $reason        Reason.
	 * @param int      $size          Size.
	 * @param int|null $expected_size Expected size.
	 * @param string   $md5           Md5.
	 * @param string   $expected_md5  Expected md5.
	 *
	 * @return array{path:string,kind:string,reason:string,size:int,expected_size:?int,md5:string,expected_md5:string}
	 */
	private static function item( string $path, string $kind, string $reason, int $size, ?int $expected_size, string $md5, string $expected_md5 ): array {
		return array(
			'path'          => $path,
			'kind'          => $kind,
			'reason'        => $reason,
			'size'          => $size,
			'expected_size' => $expected_size,
			'md5'           => $md5,
			'expected_md5'  => $expected_md5,
			'actions'       => self::actions_for( $kind ),
		);
	}

	/**
	 * Actions for.
	 *
	 * @param string $kind Kind.
	 *
	 * @return list<string>
	 */
	private static function actions_for( string $kind ): array {
		return 'extra' === $kind ? array( 'view', 'quarantine', 'delete' ) : array( 'view' );
	}

	/**
	 * Normalize rel.
	 *
	 * @param string $rel Rel.
	 *
	 * @return string
	 */
	private static function normalize_rel( string $rel ): string {
		$rel = str_replace( '\\', '/', $rel );
		if ( str_contains( $rel, "\0" ) || str_contains( $rel, '..' ) || str_contains( $rel, ':' ) ) {
			return '';
		}
		// Drop empty and "." segments so ./wp-content/ or wp-admin//x cannot slip past prefix checks.
		$parts = array_filter(
			explode( '/', $rel ),
			static fn( $part ) => '' !== $part && '.' !== $part
		);
		return implode( '/', $parts );
	}

	/**
	 * Zip size.
	 *
	 * @param ZipArchive|null $zip Zip.
	 * @param string          $rel Rel.
	 *
	 * @return int|null
	 */
	private static function zip_size( ?ZipArchive $zip, string $rel ): ?int {
		if ( ! $zip instanceof ZipArchive ) {
			return null;
		}
		foreach ( array( 'wordpress/' . $rel, $rel ) as $name ) {
			$stat = $zip->statName( $name );
			if ( is_array( $stat ) && isset( $stat['size'] ) ) {
				return (int) $stat['size'];
			}
		}
		return null;
	}

	/**
	 * Zip contents.
	 *
	 * @param string $rel Rel.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return string
	 */
	private static function zip_contents( string $rel ): string {
		$version = (string) ( get_bloginfo( 'version' ) );
		$package = self::ensure_package();
		if ( empty( $package['zip'] ) || ! class_exists( 'ZipArchive', false ) ) {
			throw new RuntimeException( __( 'Official WordPress package is not available for compare or replace.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( (string) $package['zip'] ) ) {
			throw new RuntimeException( __( 'Could not open the official WordPress package.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$data = false;
		foreach ( array( 'wordpress/' . $rel, $rel ) as $name ) {
			$data = $zip->getFromName( $name );
			if ( false !== $data ) {
				break;
			}
		}
		$zip->close();
		if ( false === $data ) {
			throw new RuntimeException( __( 'That file is not in the official WordPress package.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		return (string) $data;
	}

	/**
	 * Replace.
	 *
	 * @param string $rel Rel.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	public static function replace( string $rel ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Public action signature; Free refuses core replacement for every path.
		throw new RuntimeException( __( 'Use WordPress Updates or your hosting provider to replace core files.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
	}

	/**
	 * Delete extra.
	 *
	 * @param string $rel Rel.
	 *
	 * @return array{ok:bool,message:string}
	 * @throws RuntimeException When the operation fails.
	 */
	public static function delete_extra( string $rel ): array {
		$rel = self::validate_extra_for_action( $rel );
		if ( ! LucidLab_MPC_Helpers::delete_file( ABSPATH . $rel ) ) {
			throw new RuntimeException( __( 'Could not delete the unexpected file. Check filesystem permissions.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		self::forget_item( $rel );
		return array(
			'ok'      => true,
			'message' => __( 'Unexpected file deleted.', 'lucidlab-mpc-cleanup' ),
		);
	}

	/**
	 * Delete extras.
	 *
	 * @param array $rels Rels.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	public static function delete_extras( array $rels ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Public action signature; bulk deletion is refused for any selection.
		throw new RuntimeException( __( 'Delete unexpected files one at a time after reviewing each finding.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
	}

	/**
	 * Quarantine.
	 *
	 * @param array $rels Rels.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public static function quarantine( array $rels ): array {
		if ( 1 !== count( $rels ) ) {
			throw new RuntimeException( __( 'Quarantine unexpected files one at a time after reviewing each finding.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$rel        = self::validate_extra_for_action( (string) $rels[0] );
		$quarantine = new LucidLab_MPC_Quarantine();
		$stamp      = $quarantine->new_stamp() . '-core-' . bin2hex( random_bytes( 3 ) );
		$result     = $quarantine->quarantine_core_files( array( $rel ), $stamp );
		if ( 1 !== (int) ( $result['ok'] ?? 0 ) ) {
			throw new RuntimeException( __( 'Could not quarantine the unexpected file. The original was kept.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		self::forget_item( $rel );
		return array(
			'ok'      => 1,
			'fail'    => 0,
			'stamp'   => $stamp,
			'message' => __( 'Unexpected file moved to quarantine.', 'lucidlab-mpc-cleanup' ),
		);
	}

	/**
	 * Only a fresh finding that is still outside the official WordPress package may be changed.
	 *
	 * @param string $rel Rel.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return string
	 */
	public static function validate_extra_for_action( string $rel ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new RuntimeException( __( 'Permission denied.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$rel = self::normalize_rel( $rel );
		self::assert_safe_core_rel( $rel );
		if ( ! preg_match( '#^(?:' . preg_quote( LucidLab_MPC_Helpers::admin_rel(), '#' ) . '/|' . preg_quote( WPINC, '#' ) . '/)[a-zA-Z0-9_./-]+$#', $rel )
			&& ! preg_match( '#^[a-zA-Z0-9_-]+\.(?:php|phar|phtml|js)$#i', $rel ) ) {
			throw new RuntimeException( __( 'That path is not eligible for this action.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$saved = self::saved_result();
		if ( (int) ( $saved['checked_at'] ?? 0 ) < time() - HOUR_IN_SECONDS ) {
			throw new RuntimeException( __( 'The file check is out of date. Check WordPress files again.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$finding = null;
		foreach ( (array) ( $saved['items'] ?? array() ) as $item ) {
			if ( is_array( $item ) && (string) ( $item['path'] ?? '' ) === $rel && 'extra' === ( $item['kind'] ?? '' ) ) {
				$finding = $item;
				break;
			}
		}
		if ( null === $finding ) {
			throw new RuntimeException( __( 'This is not a current unexpected-file finding. Run the check again.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$root     = realpath( ABSPATH );
		$path     = ABSPATH . $rel;
		$resolved = realpath( $path );
		if ( false === $root || false === $resolved || is_link( $path ) || ! is_file( $path )
			|| ! str_starts_with( wp_normalize_path( $resolved ), trailingslashit( wp_normalize_path( $root ) ) )
			|| (int) ( $finding['size'] ?? -1 ) !== (int) filesize( $path ) ) {
			throw new RuntimeException( __( 'The file changed or its path is unsafe. Run the check again.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once LucidLab_MPC_Helpers::admin_dir() . 'includes/update.php';
		}
		global $wp_version;
		$locale    = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
		$checksums = get_core_checksums( (string) $wp_version, $locale );
		if ( ! is_array( $checksums ) || ! $checksums ) {
			$checksums = get_core_checksums( (string) $wp_version, 'en_US' );
		}
		if ( ! is_array( $checksums ) || ! $checksums || isset( $checksums[ $rel ] ) ) {
			throw new RuntimeException( __( 'The official WordPress file list is unavailable or now includes this file.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		return $rel;
	}

	/**
	 * View.
	 *
	 * @param string $rel           Rel.
	 * @param bool   $with_original With original.
	 *
	 * @throws RuntimeException When the path is not a current finding or is unsafe.
	 * @return array{path:string,kind:string,local:string,original:?string,binary:bool,local_size:int,original_size:?int}
	 */
	public static function view( string $rel, bool $with_original = true ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new RuntimeException( __( 'Permission denied.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$rel = self::normalize_rel( $rel );
		self::assert_safe_core_rel( $rel );
		// Only files listed in the saved check result can be opened; this is not a general file browser.
		if ( '' === self::kind_of( $rel, self::saved_result() ) ) {
			throw new RuntimeException( __( 'This file is not in the latest check result. Run the check again.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$path  = ABSPATH . $rel;
		$local = '';
		if ( file_exists( $path ) ) {
			$root     = realpath( ABSPATH );
			$resolved = realpath( $path );
			if ( false === $root || false === $resolved || is_link( $path ) || ! is_file( $resolved )
				|| ! str_starts_with( wp_normalize_path( $resolved ), trailingslashit( wp_normalize_path( $root ) ) ) ) {
				throw new RuntimeException( __( 'That path is not allowed.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			self::assert_safe_core_rel( ltrim( substr( wp_normalize_path( $resolved ), strlen( trailingslashit( wp_normalize_path( $root ) ) ) ), '/' ) );
			$local = (string) file_get_contents( $resolved ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		$original      = null;
		$original_size = null;
		if ( $with_original ) {
			try {
				$original      = self::zip_contents( $rel );
				$original_size = strlen( $original );
			} catch ( Throwable $e ) {
				$original = null;
			}
		}
		$binary = self::looks_binary( $local ) || ( is_string( $original ) && self::looks_binary( $original ) );
		$max    = 120000;
		return array(
			'path'          => $rel,
			'kind'          => self::kind_of( $rel, self::saved_result() ),
			'binary'        => $binary,
			'local'         => $binary ? '' : self::clip( $local, $max ),
			'original'      => ( $binary || ! is_string( $original ) ) ? null : self::clip( $original, $max ),
			'local_size'    => is_file( $path ) ? (int) filesize( $path ) : 0,
			'original_size' => $original_size,
			'truncated'     => strlen( $local ) > $max || ( is_string( $original ) && strlen( $original ) > $max ),
		);
	}

	/**
	 * Saved result.
	 *
	 * @return array<string,mixed>
	 */
	public static function saved_result(): array {
		$saved = get_option( self::RESULT_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			return array();
		}
		// Older results may contain actions from a write-capable edition.
		foreach ( (array) ( $saved['items'] ?? array() ) as $index => $item ) {
			if ( is_array( $item ) ) {
				$saved['items'][ $index ]['actions'] = self::actions_for( (string) ( $item['kind'] ?? '' ) );
			}
		}
		return $saved;
	}

	/**
	 * Clear.
	 */
	public static function clear(): void {
		delete_option( self::RESULT_OPTION );
		delete_option( self::PACKAGE_OPTION );
	}

	/**
	 * Forget item.
	 *
	 * @param string $rel Rel.
	 */
	private static function forget_item( string $rel ): void {
		$result = self::saved_result();
		if ( empty( $result['items'] ) || ! is_array( $result['items'] ) ) {
			return;
		}
		$result['items'] = array_values(
			array_filter(
				$result['items'],
				static fn( $item ) => ! is_array( $item ) || (string) ( $item['path'] ?? '' ) !== $rel
			)
		);
		$counts          = array(
			'extra'   => 0,
			'changed' => 0,
			'size'    => 0,
			'total'   => count( $result['items'] ),
		);
		foreach ( $result['items'] as $item ) {
			$kind = (string) ( $item['kind'] ?? '' );
			if ( isset( $counts[ $kind ] ) ) {
				++$counts[ $kind ];
			}
		}
		$result['counts'] = $counts;
		$result['ok']     = 0 === $counts['total'];
		$version          = (string) ( $result['version'] ?? '' );
		/* translators: %s is the detected WordPress version. */
		$result['headline']  = sprintf( __( 'WordPress %s', 'lucidlab-mpc-cleanup' ), $version );
		$result['attention'] = $result['ok']
			? ''
			/* translators: %d is the number of core files needing attention. */
			: sprintf( _n( '%d file(s) need attention.', '%d file(s) need attention.', $counts['total'], 'lucidlab-mpc-cleanup' ), $counts['total'] );
		$result['message'] = $result['ok']
			/* translators: %s is the detected WordPress version. */
			? sprintf( __( 'WordPress %s core files match the official package.', 'lucidlab-mpc-cleanup' ), $version )
			/* translators: 1: WordPress version, 2: number of core files needing attention. */
			: sprintf( __( 'WordPress %1$s: %2$d file(s) need attention.', 'lucidlab-mpc-cleanup' ), $version, $counts['total'] );
		update_option( self::RESULT_OPTION, $result, false );
	}

	/**
	 * Kind of.
	 *
	 * @param string              $rel    Rel.
	 * @param array<string,mixed> $result Result.
	 *
	 * @return string
	 */
	private static function kind_of( string $rel, array $result ): string {
		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			if ( is_array( $item ) && (string) ( $item['path'] ?? '' ) === $rel ) {
				return (string) ( $item['kind'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Assert safe core rel.
	 *
	 * @param string $rel Rel.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	private static function assert_safe_core_rel( string $rel ): void {
		$lower = strtolower( $rel );
		if ( '' === $rel || str_contains( $rel, '..' ) || str_contains( $rel, "\0" ) ) {
			throw new RuntimeException( __( 'That path is not allowed.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		// Content folders (default and custom WP_CONTENT_DIR), compared case-insensitively.
		$blocked_dirs = array( 'wp-content/' );
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$abs     = trailingslashit( strtolower( wp_normalize_path( ABSPATH ) ) );
			$content = trailingslashit( strtolower( wp_normalize_path( WP_CONTENT_DIR ) ) );
			if ( str_starts_with( $content, $abs ) ) {
				$blocked_dirs[] = substr( $content, strlen( $abs ) );
			}
		}
		foreach ( $blocked_dirs as $dir ) {
			if ( '' !== $dir && ( str_starts_with( $lower . '/', $dir ) || str_contains( '/' . $lower . '/', '/' . $dir ) ) ) {
				throw new RuntimeException( __( 'That path is not allowed.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
		}
		foreach ( explode( '/', $lower ) as $segment ) {
			// Dotfiles and dot-folders (.env, .git/, .htaccess, .user.ini …).
			if ( '' === $segment || str_starts_with( $segment, '.' ) ) {
				throw new RuntimeException( __( 'That path is not allowed.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
		}
		$base = wp_basename( $lower );
		if ( str_starts_with( $base, 'wp-config' ) || preg_match( '/(?:^|[._-])(?:env|ini|htpasswd|sql|sqlite|db|bak|backup|old|orig|save|swp|log|key|pem|crt|p12|pfx|zip|gz|tgz|tar|7z|rar)$/', $base ) || str_contains( $base, '.env' ) ) {
			throw new RuntimeException( __( 'wp-config.php and secret or backup files cannot be opened or changed from this tool.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
	}

	/**
	 * Looks binary.
	 *
	 * @param string $data Data.
	 *
	 * @return bool
	 */
	private static function looks_binary( string $data ): bool {
		if ( '' === $data ) {
			return false;
		}
		return str_contains( substr( $data, 0, 8000 ), "\0" );
	}

	/**
	 * Clip.
	 *
	 * @param string $data Data.
	 * @param int    $max  Max.
	 *
	 * @return string
	 */
	private static function clip( string $data, int $max ): string {
		if ( strlen( $data ) <= $max ) {
			return $data;
		}
		return substr( $data, 0, $max ) . "\n…";
	}

	/**
	 * Protect dir.
	 *
	 * @param string $dir Dir.
	 */
	private static function protect_dir( string $dir ): void {
		// Read-only core checks do not create a package cache.
	}
}
