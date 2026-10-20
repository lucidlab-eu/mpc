<?php
/**
 * Lucid Lab Media & Products Cleanup: exclusions class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** User-configured scan skip rules for folders, MIME types, recency and featured images. */
class LucidLab_MPC_Exclusions {

	/**
	 * Folders.
	 *
	 * @return array
	 */
	public static function folders(): array {
		$folders = self::parse_folders( (string) LucidLab_MPC_Settings::get( 'exclude_folders' ) );
		foreach ( array( LucidLab_MPC_Storage::SLUG, '_cleanup-quarantine', '_cleanup-fsindex', '_cleanup-format-preview', 'mpc-releases' ) as $always ) {
			$folders[] = $always;
		}
		if ( class_exists( 'LucidLab_MPC_Backup' ) ) {
			$folders[] = basename( LucidLab_MPC_Backup::dir() );
		}
		return array_values( array_unique( $folders ) );
	}

	/**
	 * Mimes.
	 *
	 * @return array
	 */
	public static function mimes(): array {
		return self::parse_mimes( (string) LucidLab_MPC_Settings::get( 'exclude_mimes' ) );
	}

	/**
	 * Newer days.
	 *
	 * @return int
	 */
	public static function newer_days(): int {
		return max( 0, min( 3650, (int) LucidLab_MPC_Settings::get( 'exclude_newer_days' ) ) );
	}

	/**
	 * Skip featured.
	 *
	 * @return bool
	 */
	public static function skip_featured(): bool {
		return ! empty( LucidLab_MPC_Settings::get( 'exclude_featured' ) );
	}

	/**
	 * Parse folders.
	 *
	 * @param string $raw Raw.
	 *
	 * @return array
	 */
	public static function parse_folders( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $line ) {
			$line = strtolower( str_replace( '\\', '/', trim( $line ) ) );
			if ( '' === $line || str_contains( $line, '..' ) ) {
				continue;
			}
			$line = trim( $line, '/' );
			$line = preg_replace( '#^\.+/#', '', $line );
			if ( '' === $line ) {
				continue;
			}
			$out[] = $line;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Upload root folders.
	 *
	 * @return array
	 */
	public static function upload_root_folders(): array {
		$base = class_exists( 'LucidLab_MPC_Helpers', false ) ? rtrim( str_replace( '\\', '/', LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) : '';
		if ( ! $base || ! is_dir( $base ) ) {
			return array();
		}
		$skip  = array( '.', '..', LucidLab_MPC_Storage::SLUG, '_cleanup-quarantine', '_cleanup-fsindex', '_cleanup-format-preview', 'mpc-releases' );
		$out   = array();
		$limit = 150;
		$roots = glob( $base . '/*', GLOB_ONLYDIR );
		if ( ! is_array( $roots ) || ! $roots ) {
			$entries = ( is_dir( $base ) ? scandir( $base ) : false );
			$roots   = array();
			if ( is_array( $entries ) ) {
				foreach ( $entries as $name ) {
					if ( in_array( $name, $skip, true ) || str_starts_with( $name, 'lucidlab-mpc-backups-' ) || str_starts_with( $name, '.' ) || ! is_dir( $base . '/' . $name ) ) {
						continue;
					}
					$roots[] = $base . '/' . $name;
				}
			}
		}
		foreach ( $roots as $path ) {
			$path = str_replace( '\\', '/', $path );
			$name = basename( $path );
			if ( in_array( $name, $skip, true ) || str_starts_with( $name, 'lucidlab-mpc-backups-' ) || str_starts_with( $name, '.' ) || ! is_dir( $path ) ) {
				continue;
			}
			$out[] = $name;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		natcasesort( $out );
		return array_values( $out );
	}

	/**
	 * Parse mimes.
	 *
	 * @param string $raw Raw.
	 *
	 * @return array
	 */
	public static function parse_mimes( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', strtolower( $raw ) ) as $mime ) {
			$mime = trim( $mime );
			if ( $mime && preg_match( '#^[a-z0-9.+-]+/[a-z0-9.+*-]+$#', $mime ) ) {
				$out[] = $mime;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Path is excluded.
	 *
	 * @param string $rel     Rel.
	 * @param array  $folders Folders.
	 *
	 * @return bool
	 */
	public static function path_is_excluded( string $rel, array $folders ): bool {
		$rel = strtolower( str_replace( '\\', '/', ltrim( $rel, '/' ) ) );
		if ( '' === $rel ) {
			return false;
		}
		foreach ( $folders as $folder ) {
			if ( $rel === $folder || str_starts_with( $rel, $folder . '/' ) ) {
				return true;
			}
			foreach ( explode( '/', $rel ) as $part ) {
				if ( $part === $folder ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Mime is excluded.
	 *
	 * @param string $mime  Mime.
	 * @param array  $mimes Mimes.
	 *
	 * @return bool
	 */
	public static function mime_is_excluded( string $mime, array $mimes ): bool {
		$mime = strtolower( trim( $mime ) );
		if ( '' === $mime ) {
			return false;
		}
		foreach ( $mimes as $skip ) {
			if ( $mime === $skip ) {
				return true;
			}
			if ( str_ends_with( $skip, '/*' ) && str_starts_with( $mime, substr( $skip, 0, -1 ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Skip rel.
	 *
	 * @param string $rel Rel.
	 *
	 * @return bool
	 */
	public static function skip_rel( string $rel ): bool {
		return self::path_is_excluded( $rel, self::folders() );
	}

	/**
	 * Skip abs.
	 *
	 * @param string $abs Abs.
	 *
	 * @return bool
	 */
	public static function skip_abs( string $abs ): bool {
		$base = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::uploads_basedir() : '';
		$abs  = str_replace( '\\', '/', $abs );
		$base = str_replace( '\\', '/', (string) $base );
		$rel  = $base && str_starts_with( $abs, rtrim( $base, '/' ) . '/' ) ? substr( $abs, strlen( rtrim( $base, '/' ) ) + 1 ) : $abs;
		return self::skip_rel( $rel );
	}

	/**
	 * Skip attachment.
	 *
	 * @param int $id Id.
	 *
	 * @return bool
	 */
	public static function skip_attachment( int $id ): bool {
		$id = absint( $id );
		if ( ! $id ) {
			return true;
		}
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return true;
		}
		if ( self::mime_is_excluded( (string) $post->post_mime_type, self::mimes() ) ) {
			return true;
		}
		$days = self::newer_days();
		if ( $days > 0 ) {
			$gmt = (string) ( ( $post->post_date_gmt ? $post->post_date_gmt : $post->post_date ) );
			$ts  = $gmt ? strtotime( $gmt . ' UTC' ) : 0;
			if ( $ts && $ts >= time() - ( $days * DAY_IN_SECONDS ) ) {
				return true;
			}
		}
		if ( self::skip_featured() && isset( self::featured_ids()[ $id ] ) ) {
			return true;
		}
		$file = get_attached_file( $id );
		return $file ? self::skip_abs( $file ) : false;
	}

	/**
	 * Featured ids.
	 *
	 * @return array
	 */
	public static function featured_ids(): array {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}
		global $wpdb;
		$ids = array();
		if ( ! isset( $wpdb ) ) {
			return $ids;
		}
		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			"SELECT DISTINCT CAST(m.meta_value AS UNSIGNED)
			 FROM {$wpdb->postmeta} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_key = '_thumbnail_id'
			   AND p.post_type IN ('product','product_variation')
			   AND p.post_status NOT IN ('trash','auto-draft')"
		);
		foreach ( (array) $rows as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}
		return $ids;
	}
}
