<?php
/**
 * Lucid Lab Media & Products Cleanup: helpers class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless helpers shared across every module.
 */
class LucidLab_MPC_Helpers {

	const BATCH            = 500; // DB chunk size.
	const AJAX_FILES       = 400; // Default files per uploads scan step.
	const SCAN_TIME_BUDGET = 1.2; // Seconds of filesystem work per uploads scan step (stay under live proxies).
	const QUAR_BATCH       = 25;  // Items per quarantine batch (JS-driven).

	/**
	 * Human tab name for logs. Unknown keys stay readable instead of raw slugs.
	 *
	 * @param string $tab Tab.
	 *
	 * @return string
	 */
	public static function scan_tab_label( string $tab ): string {
		$tab    = sanitize_key( $tab );
		$labels = array(
			'duplicates'   => __( 'Duplicate Products', 'lucidlab-mpc-cleanup' ),
			'orphans'      => __( 'Orphan Images', 'lucidlab-mpc-cleanup' ),
			'uploads'      => __( 'Uploads Scanner', 'lucidlab-mpc-cleanup' ),
			'sizes'        => __( 'Image Sizes', 'lucidlab-mpc-cleanup' ),
			'fs-sizes'     => __( 'Other Sizes on Disk', 'lucidlab-mpc-cleanup' ),
			'missing'      => __( 'Missing Images', 'lucidlab-mpc-cleanup' ),
			'crawl'        => __( 'Missing Images', 'lucidlab-mpc-cleanup' ),
			'shared'       => __( 'Shared Images', 'lucidlab-mpc-cleanup' ),
			'dupcontent'   => __( 'Byte Identical Images', 'lucidlab-mpc-cleanup' ),
			'visual'       => __( 'Visually Identical Images', 'lucidlab-mpc-cleanup' ),
			'regenerate'   => __( 'Regenerate Thumbnails', 'lucidlab-mpc-cleanup' ),
			'webp'         => __( 'WebP / AVIF', 'lucidlab-mpc-cleanup' ),
			'webpstats'    => __( 'WebP / AVIF', 'lucidlab-mpc-cleanup' ),
			'webpscan'     => __( 'WebP / AVIF', 'lucidlab-mpc-cleanup' ),
			'webpclean'    => __( 'WebP / AVIF', 'lucidlab-mpc-cleanup' ),
			'dbcleaner'    => __( 'Database Cleaner', 'lucidlab-mpc-cleanup' ),
			'product_copy' => __( 'Product AI', 'lucidlab-mpc-cleanup' ),
			'rename'       => __( 'Rename Images', 'lucidlab-mpc-cleanup' ),
			'rename_ai'    => __( 'Rename Images AI', 'lucidlab-mpc-cleanup' ),
		);
		if ( isset( $labels[ $tab ] ) ) {
			return $labels[ $tab ];
		}
		return $tab ? ucwords( str_replace( array( '-', '_' ), ' ', $tab ) ) : __( 'unknown', 'lucidlab-mpc-cleanup' );
	}

	/**
	 * Shared “27 September 2026 at 22:53” stamp for Completed on / Finished Jobs.
	 *
	 * @param int $timestamp Timestamp.
	 *
	 * @return string
	 */
	public static function job_datetime( int $timestamp ): string {
		if ( $timestamp < 1 ) {
			return '';
		}
		return wp_date( 'j F Y', $timestamp ) . ' ' . __( 'at', 'lucidlab-mpc-cleanup' ) . ' ' . wp_date( 'H:i', $timestamp );
	}

	/**
	 * Prepend one finished-job row under a site-wide lock so parallel Pro writers cannot drop each other.
	 *
	 * @param array<string,mixed> $entry Entry.
	 */
	public static function prepend_job_history( array $entry ): void {
		global $wpdb;
		$lock = 'lucidlab_mpc_job_history_' . md5( (string) $wpdb->prefix );
		$got  = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		try {
			$history = (array) get_option( 'lucidlab_mpc_job_history', array() );
			array_unshift( $history, $entry );
			update_option( 'lucidlab_mpc_job_history', array_slice( $history, 0, 50 ), false );
		} finally {
			if ( $got ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			}
		}
	}

	/**
	 * One size style everywhere: 0.0 B, 409.8 KB, 1.2 MB.
	 *
	 * @param mixed $bytes Bytes.
	 *
	 * @return string
	 */
	public static function bytes( $bytes ): string {
		return size_format( max( 0, (int) $bytes ), 1 );
	}

	/**
	 * Files per Uploads Scanner step, using the Options range. Time and memory limits remain independent.
	 *
	 * @return int
	 */
	public static function ajax_files(): int {
		$n = self::AJAX_FILES;
		if ( class_exists( 'LucidLab_MPC_Settings', false ) ) {
			$set = (int) LucidLab_MPC_Settings::get( 'batch_files' );
			if ( $set >= 50 ) {
				$n = $set;
			}
		}
		return max( 50, min( 5000, $n ) );
	}

	/**
	 * Files/extensions never touched by the uploads scanner.
	 *
	 * @return array
	 */
	public static function excluded_names(): array {
		return array( '.ds_store', 'thumbs.db', 'web.config', 'index.php', 'index.html', 'index.htm', '.htaccess' );
	}

	/**
	 * Whether excluded file.
	 *
	 * @param string $name Name.
	 *
	 * @return bool
	 */
	public static function is_excluded_file( string $name ): bool {
		if ( in_array( strtolower( $name ), self::excluded_names(), true ) ) {
			return true;
		}
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'php', 'html', 'htm', 'htaccess', 'config' ), true );
	}

	/**
	 * Norm path.
	 *
	 * @param string $p P.
	 *
	 * @return string
	 */
	public static function norm_path( string $p ): string {
		return rtrim( str_replace( '\\', '/', $p ), '/' );
	}

	/**
	 * Lowercased base filename with any -WxH size suffix + query stripped.
	 *
	 * @param string $url Url.
	 *
	 * @return string
	 */
	public static function url_to_basename( string $url ): string {
		$url  = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
		$url  = strtok( $url, '?#' );
		$name = strtolower( wp_basename( rawurldecode( (string) $url ) ) );
		if ( '' === $name ) {
			return '';
		}
		$name = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/', '', $name );
		return (string) $name;
	}

	/**
	 * Int ids.
	 *
	 * @param mixed $values Values.
	 *
	 * @return array
	 */
	public static function int_ids( $values ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', (array) $values ) ) ) );
	}

	/**
	 * Remember error.
	 *
	 * @param array  $errors  Errors.
	 * @param string $message Message.
	 * @param int    $limit   Limit.
	 *
	 * @return array
	 */
	public static function remember_error( array $errors, string $message, int $limit = 20 ): array {
		$errors[] = array(
			'time'    => time(),
			'message' => $message,
		);
		return array_slice( $errors, -$limit );
	}

	/**
	 * Pause for resource.
	 *
	 * @param array $job   Job.
	 * @param mixed $error Error.
	 *
	 * @return array
	 */
	public static function pause_for_resource( array $job, $error ): array {
		$message           = $error instanceof WP_Error ? $error->get_error_message() : (string) $error;
		$job['status']     = 'paused';
		$job['last_error'] = $message;
		$job['label']      = $message;
		$job['errors']     = self::remember_error( (array) ( $job['errors'] ?? array() ), $message );
		return $job;
	}

	/**
	 * Retry ids.
	 *
	 * @param array $job      Job.
	 * @param array $fallback Fallback.
	 *
	 * @return array
	 */
	public static function retry_ids( array $job, array $fallback = array() ): array {
		$ids = self::int_ids( $job['failed_ids'] ?? array() );
		if ( $ids ) {
			return $ids;
		}
		foreach ( $fallback as $item ) {
			if ( is_numeric( $item ) ) {
				$ids[] = (int) $item;
			} elseif ( is_array( $item ) && ! empty( $item['id'] ) ) {
				$ids[] = (int) $item['id'];
			}
		}
		return self::int_ids( $ids );
	}

	/**
	 * Split ids.
	 *
	 * @param string $csv Csv.
	 *
	 * @return array
	 */
	public static function split_ids( string $csv ): array {
		$out = array();
		foreach ( preg_split( '/[,\s]+/', trim( $csv ) ) as $p ) {
			$p = (int) $p;
			if ( $p > 0 ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Keep a relative upload path safe (no traversal).
	 *
	 * @param mixed $rel Rel.
	 *
	 * @return string
	 */
	public static function sanitize_rel( $rel ): string {
		$rel = str_replace( '\\', '/', (string) $rel );
		$rel = preg_replace( '#^[A-Za-z]:#', '', $rel );
		$rel = ltrim( $rel, '/' );
		if ( '' === $rel ) {
			return '';
		}
		if ( str_contains( $rel, '..' ) || preg_match( '#(^|/)\.\.(?:/|$)#', $rel ) ) {
			return '';
		}
		if ( str_starts_with( $rel, '/' ) || str_contains( $rel, "\0" ) ) {
			return '';
		}
		return $rel;
	}

	/**
	 * Folders (relative to uploads, with a trailing slash) that hold generated copies of one format.
	 * New copies go to the plugin's own folder; the second entry is where versions by older versions put them.
	 *
	 * @param string $fmt Fmt.
	 *
	 * @return array<int,string>
	 */
	public static function nextgen_prefixes( string $fmt ): array {
		$fmt = 'avif' === strtolower( $fmt ) ? 'avif' : 'webp';
		return array( LucidLab_MPC_Storage::SLUG . '/' . $fmt . '/', $fmt . '/' );
	}

	/**
	 * Folder prefix of $rel when it lies in a generated-copy folder of $fmt (any format when $fmt is ''), else ''.
	 *
	 * @param string $rel Rel.
	 * @param string $fmt Fmt.
	 *
	 * @return string
	 */
	public static function nextgen_prefix_of( string $rel, string $fmt = '' ): string {
		$rel = strtolower( ltrim( str_replace( '\\', '/', $rel ), '/' ) );
		foreach ( '' === $fmt ? array( 'webp', 'avif' ) : array( $fmt ) as $one ) {
			foreach ( self::nextgen_prefixes( $one ) as $prefix ) {
				if ( str_starts_with( $rel, $prefix ) ) {
					return $prefix;
				}
			}
		}
		return '';
	}

	/**
	 * Upload path a generated WebP/AVIF copy was made from, or '' when $rel is not such a copy. Handles folder copies (webp/2024/a.jpg.webp, avif/2024/a.jpg.avif) and sidecars (2024/a.jpg.webp).
	 *
	 * @param string $rel Rel.
	 *
	 * @return string
	 */
	public static function generated_copy_source( string $rel ): string {
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
		if ( ! preg_match( '/\.(webp|avif)$/i', $rel, $m ) ) {
			return '';
		}
		$fmt    = strtolower( $m[1] );
		$base   = substr( $rel, 0, -strlen( $m[0] ) );
		$prefix = self::nextgen_prefix_of( $base, $fmt );
		if ( '' !== $prefix ) {
			$base = substr( $base, strlen( $prefix ) );
			return preg_match( '/\.(jpe?g|png|gif|webp|bmp|tiff?)$/i', $base ) ? $base : '';
		}
		return preg_match( '/\.(jpe?g|png|gif|bmp|tiff?)$/i', $base ) ? $base : '';
	}

	/**
	 * True when a WebP or AVIF copy of this upload path, or of its full-size original, is on disk.
	 *
	 * @param string $source_rel Source rel.
	 *
	 * @return bool
	 */
	public static function has_generated_copy( string $source_rel ): bool {
		$source_rel = ltrim( str_replace( '\\', '/', $source_rel ), '/' );
		if ( '' === $source_rel ) {
			return false;
		}
		if ( self::generated_copy_rels( $source_rel ) ) {
			return true;
		}
		$stripped = (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $source_rel );
		return $stripped !== $source_rel && (bool) self::generated_copy_rels( $stripped );
	}

	/**
	 * Possible upload paths for one generated format, without checking the disk. Folder copy, sidecar, and a flat folder copy of the basename.
	 *
	 * @param string $source_rel Source rel.
	 * @param string $fmt        Fmt.
	 *
	 * @return array<int,string>
	 */
	public static function generated_copy_candidates( string $source_rel, string $fmt ): array {
		$source_rel = ltrim( str_replace( '\\', '/', $source_rel ), '/' );
		$fmt        = 'avif' === $fmt ? 'avif' : 'webp';
		if ( '' === $source_rel ) {
			return array();
		}
		$prefixes   = self::nextgen_prefixes( $fmt );
		$candidates = array();
		foreach ( $prefixes as $prefix ) {
			$candidates[] = $prefix . $source_rel . '.' . $fmt;
		}
		$candidates[] = $source_rel . '.' . $fmt;
		$flat_name    = basename( $source_rel );
		if ( $flat_name !== $source_rel ) {
			foreach ( $prefixes as $prefix ) {
				$candidates[] = $prefix . $flat_name . '.' . $fmt;
			}
		}
		return $candidates;
	}

	/**
	 * True when this generated file, or the full-size file of the same format, is the Media Library file.
	 *
	 * @param string             $rel     Rel.
	 * @param array<string,bool> $library _wp_attached_file values.
	 *
	 * @return bool
	 */
	public static function generated_rel_in_library( string $rel, array $library ): bool {
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
		if ( '' === $rel || isset( $library[ $rel ] ) ) {
			return '' !== $rel && isset( $library[ $rel ] );
		}
		$source = self::generated_copy_source( $rel );
		if ( '' === $source ) {
			return false;
		}
		$fmt         = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		$main_source = (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $source );
		if ( $main_source === $source || ! in_array( $fmt, array( 'webp', 'avif' ), true ) ) {
			return false;
		}
		$main = substr( $rel, 0, strlen( self::nextgen_prefix_of( $rel, $fmt ) ) ) . $main_source . '.' . $fmt;
		return isset( $library[ $main ] );
	}

	/**
	 * A library WebP/AVIF stays when it is the only remaining file. Anything else can be quarantined.
	 *
	 * @param bool $in_library   In library.
	 * @param bool $has_original Has original.
	 * @param bool $has_other    Has other.
	 *
	 * @return bool
	 */
	public static function generated_copy_may_quarantine( bool $in_library, bool $has_original, bool $has_other ): bool {
		if ( ! $in_library ) {
			return true;
		}
		return $has_original || $has_other;
	}

	/**
	 * Media Library main-file paths.
	 *
	 * @return array<string,bool>
	 */
	public static function attached_file_set(): array {
		global $wpdb;
		$set  = array();
		$rows = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value <> ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		foreach ( (array) $rows as $rel ) {
			$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
			if ( '' !== $rel ) {
				$set[ $rel ] = true;
			}
		}
		return $set;
	}

	/**
	 * Quarantine this WebP or AVIF unless it is the only file the Media Library still has.
	 *
	 * @param string             $rel     Rel.
	 * @param string             $fmt     Fmt.
	 * @param array<string,bool> $library Library.
	 * @param array<string,bool> $held    Held.
	 *
	 * @return bool
	 */
	public static function generated_copy_can_quarantine( string $rel, string $fmt, array $library, array $held ): bool {
		$fmt          = 'avif' === $fmt ? 'avif' : 'webp';
		$other        = 'webp' === $fmt ? 'avif' : 'webp';
		$source       = self::generated_copy_source( $rel );
		$has_original = false;
		$has_other    = false;
		if ( '' !== $source ) {
			$base         = trailingslashit( self::norm_path( self::uploads_basedir() ) );
			$has_original = is_file( $base . $source ) || isset( $held[ $source ] );
			foreach ( self::generated_copy_candidates( $source, $other ) as $candidate ) {
				if ( is_file( $base . $candidate ) || isset( $held[ $candidate ] ) ) {
					$has_other = true;
					break;
				}
			}
		}
		return self::generated_copy_may_quarantine( self::generated_rel_in_library( $rel, $library ), $has_original, $has_other );
	}

	/**
	 * Existing WebP/AVIF copies made from an upload-relative source path.
	 *
	 * @param string $source_rel Source rel.
	 *
	 * @return array
	 */
	public static function generated_copy_rels( string $source_rel ): array {
		$source_rel = ltrim( str_replace( '\\', '/', $source_rel ), '/' );
		$base       = trailingslashit( self::norm_path( self::uploads_basedir() ) );
		$out        = array();
		foreach ( array( 'webp', 'avif' ) as $fmt ) {
			foreach ( self::generated_copy_candidates( $source_rel, $fmt ) as $rel ) {
				if ( is_file( $base . $rel ) ) {
					$out[] = $rel;
				}
			}
		}
		return $out;
	}

	/**
	 * Next site-local timestamp for weekday + hour.
	 *
	 * @param int  $weekday 1 = Monday … 7 = Sunday (ISO-8601).
	 * @param int  $hour    0–23.
	 * @param bool $monthly Monthly.
	 *
	 * @return int
	 */
	public static function next_weekday_hour( int $weekday, int $hour, bool $monthly = false ): int {
		$weekday    = max( 1, min( 7, $weekday ) );
		$hour       = max( 0, min( 23, $hour ) );
		$tz         = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now        = new DateTimeImmutable( 'now', $tz );
		$candidate  = $now->setTime( $hour, 0, 0 );
		$days_ahead = ( $weekday - (int) $now->format( 'N' ) + 7 ) % 7;
		if ( 0 === $days_ahead && $candidate->getTimestamp() <= $now->getTimestamp() ) {
			$days_ahead = 7;
		}
		$candidate = $candidate->modify( '+' . $days_ahead . ' days' );
		if ( $monthly ) {
			// Same weekday about a month later (four weeks from the next occurrence).
			$candidate = $candidate->modify( '+4 weeks' );
		}
		return $candidate->getTimestamp();
	}

	/**
	 * Weekday choices.
	 *
	 * @return array<int,string>
	 */
	public static function weekday_choices(): array {
		return array(
			1 => __( 'Monday', 'lucidlab-mpc-cleanup' ),
			2 => __( 'Tuesday', 'lucidlab-mpc-cleanup' ),
			3 => __( 'Wednesday', 'lucidlab-mpc-cleanup' ),
			4 => __( 'Thursday', 'lucidlab-mpc-cleanup' ),
			5 => __( 'Friday', 'lucidlab-mpc-cleanup' ),
			6 => __( 'Saturday', 'lucidlab-mpc-cleanup' ),
			7 => __( 'Sunday', 'lucidlab-mpc-cleanup' ),
		);
	}

	/**
	 * Parse and sanitize one or more report recipient emails.
	 *
	 * @param mixed $raw Comma/space/semicolon-separated string or list.
	 *
	 * @return list<string>
	 */
	public static function parse_emails( $raw ): array {
		if ( is_array( $raw ) ) {
			$parts = $raw;
		} else {
			$parts = self::either( preg_split( '/[\s,;]+/', (string) $raw ), array() );
		}
		$out = array();
		foreach ( $parts as $part ) {
			$email = sanitize_email( is_string( $part ) ? $part : '' );
			if ( ! is_email( $email ) ) {
				continue;
			}
			$key = strtolower( $email );
			if ( isset( $out[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = $email;
		}
		return array_values( $out );
	}

	/**
	 * Store recipients as a comma-separated string.
	 *
	 * @param mixed $raw Raw.
	 *
	 * @return string
	 */
	public static function sanitize_report_recipients( $raw ): string {
		return implode( ', ', self::parse_emails( $raw ) );
	}

	/**
	 * Send a multipart HTML email with a plain-text fallback.
	 *
	 * @param string|string[] $to       To.
	 * @param string          $subject  Subject.
	 * @param string          $title    Title.
	 * @param array           $sections List of [ 'heading' => '', 'rows' => [ [label, value] or [label, value, 'href'=>url], ... ] ] or [ 'text' => '' ].
	 * @param string          $footer   Footer.
	 * @param array           $cta      Optional [ 'url' => '', 'label' => '' ] primary button under the body.
	 *
	 * @return bool
	 */
	public static function mail_html( $to, string $subject, string $title, array $sections, string $footer = '', array $cta = array() ): bool {
		$plain_lines = array( $title, '' );
		$rows_html   = '';
		foreach ( $sections as $section ) {
			if ( ! empty( $section['heading'] ) ) {
				$plain_lines[] = (string) $section['heading'];
				$rows_html    .= '<h2 style="margin:24px 0 8px;font-size:16px;color:#1d2327;">' . esc_html( (string) $section['heading'] ) . '</h2>';
			}
			if ( ! empty( $section['text'] ) ) {
				$plain_lines[] = (string) $section['text'];
				$rows_html    .= '<p style="margin:0 0 12px;line-height:1.5;color:#3c434a;">' . esc_html( (string) $section['text'] ) . '</p>';
			}
			if ( ! empty( $section['rows'] ) && is_array( $section['rows'] ) ) {
				$rows_html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 8px;">';
				foreach ( $section['rows'] as $row ) {
					$label         = (string) ( $row[0] ?? $row['label'] ?? '' );
					$value         = (string) ( $row[1] ?? $row['value'] ?? '' );
					$href          = (string) ( $row['href'] ?? '' );
					$plain_lines[] = $label . ( '' !== $value ? ': ' . $value : ( $href ? ': ' . $href : '' ) );
					$value_html    = $href
						? '<a href="' . esc_url( $href ) . '" style="color:#2271b1;word-break:break-all;">' . esc_html( '' !== $value ? $value : $href ) . '</a>'
						: esc_html( $value );
					$rows_html    .= '<tr><td style="padding:8px 12px;border:1px solid #dcdcde;background:#f6f7f7;width:42%;font-weight:600;color:#1d2327;">' . esc_html( $label ) . '</td><td style="padding:8px 12px;border:1px solid #dcdcde;color:#3c434a;">' . $value_html . '</td></tr>';
				}
				$rows_html .= '</table>';
			}
			$plain_lines[] = '';
		}
		if ( $footer ) {
			$plain_lines[] = $footer;
		}
		$cta_url   = esc_url( (string) ( $cta['url'] ?? '' ) );
		$cta_label = (string) ( $cta['label'] ?? '' );
		if ( $cta_url && $cta_label ) {
			$plain_lines[] = $cta_label . ': ' . $cta_url;
		}
		$site  = wp_parse_url( home_url(), PHP_URL_HOST );
		$html  = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen-Sans,Ubuntu,Cantarell,\'Helvetica Neue\',sans-serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f1;padding:24px 12px;"><tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #dcdcde;border-radius:8px;overflow:hidden;">'
			. ( '<tr><td style="background:#2271b1;color:#ffffff;padding:20px 24px;"><div style="font-size:13px;opacity:.9;">' . esc_html__( 'Media &amp; Products Cleanup', 'lucidlab-mpc-cleanup' ) . '</div><div style="font-size:20px;font-weight:600;margin-top:4px;">' ) . esc_html( $title ) . '</div>'
			. ( $site ? '<div style="font-size:13px;margin-top:6px;opacity:.9;">' . esc_html( (string) $site ) . '</div>' : '' )
			. '</td></tr><tr><td style="padding:24px;">' . $rows_html
			. ( $footer ? '<p style="margin:20px 0 0;font-size:13px;line-height:1.5;color:#646970;">' . esc_html( $footer ) . '</p>' : '' )
			. ( $cta_url && $cta_label ? '<p style="margin:16px 0 0;"><a href="' . $cta_url . '" style="display:inline-block;background:#2271b1;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:4px;font-size:14px;">' . esc_html( $cta_label ) . '</a></p>' : '' )
			. '<p style="margin:16px 0 0;"><a href="' . esc_url( admin_url( 'admin.php?page=lucidlab-mpc-cleanup' ) ) . ( '" style="display:inline-block;background:#2271b1;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:4px;font-size:14px;">' . esc_html__( 'Open Media &amp; Products Cleanup', 'lucidlab-mpc-cleanup' ) . '</a></p>' )
			. '</td></tr></table></td></tr></table></body></html>';
		$plain = implode( "\n", $plain_lines ) . "\n" . admin_url( 'admin.php?page=lucidlab-mpc-cleanup' );
		$alt   = static function ( $phpmailer ) use ( $plain ): void {
			if ( is_object( $phpmailer ) && empty( $phpmailer->AltBody ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
				$phpmailer->AltBody = $plain; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
			}
		};
		add_action( 'phpmailer_init', $alt, 20 );
		$sent = (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'phpmailer_init', $alt, 20 );
		return $sent;
	}

	/**
	 * Clamp image quality.
	 *
	 * @param mixed  $value    Value.
	 * @param string $fmt      Fmt.
	 * @param int    $fallback Fallback.
	 *
	 * @return int
	 */
	public static function clamp_image_quality( $value, string $fmt = 'webp', int $fallback = 0 ): int {
		$n = (int) $value;
		if ( $n >= 1 && $n <= 100 ) {
			return $n;
		}
		if ( $fallback >= 1 && $fallback <= 100 ) {
			return $fallback;
		}
		return self::default_quality( $fmt );
	}

	/**
	 * Imagick makes larger AVIF files than GD at the same number, so it starts lower.
	 *
	 * @param string $fmt       Fmt.
	 * @param bool   $thumbnail Thumbnail.
	 *
	 * @return int
	 */
	public static function default_quality( string $fmt, bool $thumbnail = false ): int {
		if ( 'avif' !== $fmt ) {
			return $thumbnail ? 70 : 80;
		}
		if ( 'Imagick' === self::nextgen_encoder( 'avif' ) ) {
			return $thumbnail ? 40 : 50;
		}
		return $thumbnail ? 50 : 60;
	}

	/**
	 * Library that encode_nextgen() uses for this format: GD, Imagick, WordPress or none.
	 *
	 * @param string $fmt Fmt.
	 *
	 * @return string
	 */
	public static function nextgen_encoder( string $fmt ): string {
		static $cache = array();
		$fmt          = 'avif' === $fmt ? 'avif' : 'webp';
		if ( isset( $cache[ $fmt ] ) ) {
			return $cache[ $fmt ];
		}
		if ( function_exists( 'avif' === $fmt ? 'imageavif' : 'imagewebp' ) ) {
			$cache[ $fmt ] = 'GD';
			return $cache[ $fmt ];
		}
		if ( class_exists( 'Imagick' ) ) {
			try {
				if ( in_array( strtoupper( $fmt ), array_map( 'strtoupper', (array) Imagick::queryFormats( strtoupper( $fmt ) ) ), true ) ) {
					$cache[ $fmt ] = 'Imagick';
					return $cache[ $fmt ];
				}
			} catch ( Throwable $e ) {
				// Imagick cannot list formats on this host; fall through to the WordPress check.
				unset( $e );
			}
		}
		if ( function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( array( 'mime_type' => 'image/' . $fmt ) ) ) {
			$cache[ $fmt ] = 'WordPress';
			return $cache[ $fmt ];
		}
		$cache[ $fmt ] = 'none';
		return $cache[ $fmt ];
	}

	/**
	 * Point local Ollama URLs at /api/chat unless the user already set a v1 OpenAI path.
	 *
	 * @param string $endpoint Endpoint.
	 *
	 * @return string
	 */
	public static function ollama_chat_url( string $endpoint ): string {
		$endpoint = trim( $endpoint );
		if ( '' === $endpoint ) {
			return 'http://127.0.0.1:11434/api/chat';
		}
		$endpoint = rtrim( $endpoint, '/' );
		if ( preg_match( '#/v1/chat/completions$#i', $endpoint ) ) {
			return $endpoint;
		}
		if ( preg_match( '#/v1$#i', $endpoint ) ) {
			return $endpoint . '/chat/completions';
		}
		if ( preg_match( '#/api/chat$#i', $endpoint ) ) {
			return $endpoint;
		}
		if ( preg_match( '#/api/generate$#i', $endpoint ) ) {
			return (string) preg_replace( '#/api/generate$#i', '/api/chat', $endpoint );
		}
		if ( ! preg_match( '#/(api|v1)(/|$)#i', $endpoint ) ) {
			return $endpoint . '/api/chat';
		}
		return $endpoint;
	}

	/**
	 * Encode a JPEG/PNG file to WebP or AVIF at an explicit quality. WordPress's image editor applies JPEG compression before changing format, so WebP/AVIF copies would look like the original even at quality 5–10.
	 *
	 * @return array
	 */
	public static function nextgen_caps(): array {
		$driver = 'none';
		if ( extension_loaded( 'imagick' ) || class_exists( 'Imagick' ) ) {
			$driver = 'Imagick';
		} elseif ( extension_loaded( 'gd' ) ) {
			$driver = 'GD';
		}
		return array(
			'driver'  => $driver,
			'webp'    => self::nextgen_supported( 'webp' ),
			'avif'    => self::nextgen_supported( 'avif' ),
			'encoder' => array(
				'webp' => self::nextgen_encoder( 'webp' ),
				'avif' => self::nextgen_encoder( 'avif' ),
			),
		);
	}

	/**
	 * Nextgen supported.
	 *
	 * @param string $fmt Fmt.
	 *
	 * @return bool
	 */
	public static function nextgen_supported( string $fmt ): bool {
		$fmt  = 'avif' === $fmt ? 'avif' : 'webp';
		$mime = 'image/' . $fmt;
		if ( function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( array( 'mime_type' => $mime ) ) ) {
			return true;
		}
		if ( 'webp' === $fmt && function_exists( 'imagewebp' ) ) {
			return true;
		}
		if ( 'avif' === $fmt && function_exists( 'imageavif' ) ) {
			return true;
		}
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}
		try {
			$formats = array_map( 'strtoupper', (array) Imagick::queryFormats( strtoupper( $fmt ) ) );
			return in_array( strtoupper( $fmt ), $formats, true );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * True when the file has bytes and the WebP (RIFF…WEBP) or AVIF (ftyp) signature.
	 *
	 * @param string $path Path.
	 * @param string $fmt  Fmt.
	 *
	 * @return bool
	 */
	public static function valid_nextgen_file( string $path, string $fmt = '' ): bool {
		if ( '' === $path || ! is_file( $path ) ) {
			return false;
		}
		clearstatcache( true, $path );
		if ( (int) ( is_file( $path ) ? filesize( $path ) : false ) < 32 ) {
			return false;
		}
		$fmt  = '' !== $fmt ? $fmt : strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$head = (string) ( is_readable( $path ) ? file_get_contents( $path, false, null, 0, 16 ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( 'webp' === $fmt ) {
			return 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 );
		}
		if ( 'avif' === $fmt ) {
			return 'ftyp' === substr( $head, 4, 4 );
		}
		return true;
	}

	/**
	 * Encode nextgen.
	 *
	 * @param string $source  Source.
	 * @param string $dest    Dest.
	 * @param string $fmt     Fmt.
	 * @param int    $quality Quality.
	 */
	public static function encode_nextgen( string $source, string $dest, string $fmt, int $quality ) {
		$result = self::encode_nextgen_raw( $source, $dest, $fmt, $quality );
		$fmt    = 'avif' === $fmt ? 'avif' : 'webp';
		if ( is_wp_error( $result ) ) {
			self::drop_invalid_nextgen( $dest, $fmt );
			return $result;
		}
		$path = is_array( $result ) ? (string) ( $result['path'] ?? '' ) : '';
		if ( ! self::valid_nextgen_file( $path, $fmt ) ) {
			self::drop_invalid_nextgen( $path, $fmt );
			self::drop_invalid_nextgen( $dest, $fmt );
			return new WP_Error( 'lucidlab_mpc_encode_empty', sprintf( /* translators: %s: image format such as WEBP or AVIF. */ __( 'The encoder wrote an empty or unreadable %s file. It was removed; the original is kept.', 'lucidlab-mpc-cleanup' ), strtoupper( $fmt ) ) );
		}
		return $result;
	}

	/**
	 * Remove a generated copy that is empty or not a real WebP/AVIF file.
	 *
	 * @param string $path Path.
	 * @param string $fmt  Fmt.
	 *
	 * @return bool
	 */
	public static function drop_invalid_nextgen( string $path, string $fmt = '' ): bool {
		if ( '' === $path || ! is_file( $path ) || self::valid_nextgen_file( $path, $fmt ) ) {
			return false;
		}
		return self::delete_file( $path );
	}

	/**
	 * Encode nextgen raw.
	 *
	 * @param string $source  Source.
	 * @param string $dest    Dest.
	 * @param string $fmt     Fmt.
	 * @param int    $quality Quality.
	 */
	private static function encode_nextgen_raw( string $source, string $dest, string $fmt, int $quality ) {
		$fmt     = 'avif' === $fmt ? 'avif' : 'webp';
		$quality = max( 1, min( 100, $quality ) );
		$mime    = 'image/' . $fmt;
		if ( ! is_file( $source ) ) {
			return new WP_Error( 'lucidlab_mpc_encode_missing', __( 'Source image is missing.', 'lucidlab-mpc-cleanup' ) );
		}
		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( dirname( $dest ) );
		} else {
			wp_mkdir_p( dirname( $dest ) );
		}
		$gd_saved = self::encode_nextgen_gd( $source, $dest, $fmt, $quality );
		if ( is_array( $gd_saved ) ) {
			return $gd_saved;
		}
		if ( class_exists( 'Imagick' ) ) {
			try {
				$im = new Imagick( $source );
				if ( $im->getNumberImages() > 1 ) {
					$im->setIteratorIndex( 0 );
					$single = $im->getImage();
					$im->clear();
					$im->destroy();
					$im = $single;
				}
				if ( defined( 'Imagick::COLORSPACE_CMYK' ) && $im->getImageColorspace() === Imagick::COLORSPACE_CMYK && defined( 'Imagick::COLORSPACE_SRGB' ) ) {
					$im->transformImageColorspace( Imagick::COLORSPACE_SRGB );
				}
				self::imagick_strip_metadata( $im );
				$im->setImageFormat( strtoupper( $fmt ) );
				if ( 'webp' === $fmt ) {
					$im->setOption( 'webp:lossless', 'false' );
					$im->setOption( 'webp:method', '4' );
				} else {
					$im->setOption( 'heic:speed', '6' );
				}
				$im->setImageCompressionQuality( $quality );
				if ( method_exists( $im, 'setCompressionQuality' ) ) {
					$im->setCompressionQuality( $quality );
				}
				$blob    = $im->getImagesBlob();
				$written = false;
				if ( is_string( $blob ) && '' !== $blob ) {
					$written = false !== file_put_contents( $dest, $blob ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				}
				if ( ! $written ) {
					$written = $im->writeImage( str_replace( '\\', '/', $dest ) );
				}
				$im->clear();
				$im->destroy();
				if ( $written && is_file( $dest ) && filesize( $dest ) > 0 ) {
					return array(
						'path'      => $dest,
						'mime-type' => $mime,
					);
				}
			} catch ( Throwable $e ) {
				unset( $im );
			}
		}
		$editor = function_exists( 'wp_get_image_editor' ) ? wp_get_image_editor( $source ) : null;
		// WordPress's GD editor hits a fatal "Palette image not supported by webp" on palette PNGs and leaves a 0-byte file.
		if ( $editor && ! is_wp_error( $editor ) && ! ( class_exists( 'WP_Image_Editor_GD', false ) && $editor instanceof WP_Image_Editor_GD ) ) {
			return self::editor_save_quality( $editor, $dest, $mime, $quality );
		}
		return new WP_Error( 'lucidlab_mpc_encode_failed', __( 'Could not encode the image at the requested quality.', 'lucidlab-mpc-cleanup' ) );
	}

	/**
	 * Drop EXIF/XMP/IPTC from a copy but keep the ICC profile, so colours do not shift. The EXIF orientation is applied to the pixels first because it is removed with EXIF.
	 *
	 * @param Imagick $im Im.
	 */
	private static function imagick_strip_metadata( Imagick $im ): void {
		try {
			$orientation = (int) $im->getImageOrientation();
			switch ( $orientation ) {
				case Imagick::ORIENTATION_TOPRIGHT:
					$im->flopImage();
					break;
				case Imagick::ORIENTATION_BOTTOMRIGHT:
					$im->rotateImage( '#000', 180 );
					break;
				case Imagick::ORIENTATION_BOTTOMLEFT:
					$im->flipImage();
					break;
				case Imagick::ORIENTATION_LEFTTOP:
					$im->transposeImage();
					break;
				case Imagick::ORIENTATION_RIGHTTOP:
					$im->rotateImage( '#000', 90 );
					break;
				case Imagick::ORIENTATION_RIGHTBOTTOM:
					$im->transverseImage();
					break;
				case Imagick::ORIENTATION_LEFTBOTTOM:
					$im->rotateImage( '#000', -90 );
					break;
			}
			if ( $orientation > Imagick::ORIENTATION_TOPLEFT ) {
				$im->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
			}
			$profiles = $im->getImageProfiles( 'icc', true );
			$im->stripImage();
			if ( ! empty( $profiles['icc'] ) ) {
				$im->profileImage( 'icc', $profiles['icc'] );
			}
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Encode nextgen gd.
	 *
	 * @param string $source  Source.
	 * @param string $dest    Dest.
	 * @param string $fmt     Fmt.
	 * @param int    $quality Quality.
	 */
	private static function encode_nextgen_gd( string $source, string $dest, string $fmt, int $quality ) {
		$can = ( 'webp' === $fmt && function_exists( 'imagewebp' ) ) || ( 'avif' === $fmt && function_exists( 'imageavif' ) );
		if ( ! $can ) {
			return null;
		}
		$ext = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
		$gd  = false;
		if ( in_array( $ext, array( 'jpg', 'jpeg' ), true ) && function_exists( 'imagecreatefromjpeg' ) ) {
			$gd = @imagecreatefromjpeg( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GD emits warnings for corrupt images; the return value is checked.
		} elseif ( 'png' === $ext && function_exists( 'imagecreatefrompng' ) ) {
			$gd = @imagecreatefrompng( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GD emits warnings for corrupt images; the return value is checked.
		}
		if ( ! $gd && function_exists( 'imagecreatefromstring' ) ) {
			$raw = ( is_readable( $source ) ? file_get_contents( $source ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			$gd  = $raw ? @imagecreatefromstring( $raw ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GD emits warnings for corrupt images; the return value is checked.
		}
		if ( ! $gd ) {
			return null;
		}
		if ( function_exists( 'imagepalettetotruecolor' ) && ! imageistruecolor( $gd ) ) {
			imagepalettetotruecolor( $gd );
		}
		if ( 'png' === $ext ) {
			imagealphablending( $gd, false );
			imagesavealpha( $gd, true );
		}
		$ok = 'avif' === $fmt ? @imageavif( $gd, $dest, $quality ) : @imagewebp( $gd, $dest, $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GD emits warnings for corrupt images; the return value is checked.
		imagedestroy( $gd );
		if ( $ok && self::valid_nextgen_file( $dest, $fmt ) ) {
			return array(
				'path'      => $dest,
				'mime-type' => 'image/' . $fmt,
			);
		}
		self::drop_invalid_nextgen( $dest, $fmt );
		return null;
	}

	/**
	 * WordPress resets quality when the output MIME changes (JPEG → WebP/AVIF). Keep the requested quality through save() via wp_editor_set_quality.
	 *
	 * @param mixed  $editor  Editor.
	 * @param string $dest    Dest.
	 * @param string $mime    Mime.
	 * @param int    $quality Quality.
	 */
	public static function editor_save_quality( $editor, string $dest, string $mime, int $quality ) {
		$quality = max( 1, min( 100, $quality ) );
		$filter  = static function ( $current, $type = '' ) use ( $quality, $mime ) {
			return ( $type === $mime || '' === $type ) ? $quality : $current;
		};
		add_filter( 'wp_editor_set_quality', $filter, 99, 3 );
		try {
			if ( is_object( $editor ) && method_exists( $editor, 'set_quality' ) ) {
				$editor->set_quality( $quality );
			}
			return $editor->save( $dest, $mime );
		} finally {
			remove_filter( 'wp_editor_set_quality', $filter, 99 );
		}
	}

	/**
	 * Uploads basedir.
	 *
	 * @return string
	 */
	public static function uploads_basedir(): string {
		$u = wp_get_upload_dir();
		return trailingslashit( $u['basedir'] );
	}

	/**
	 * Uploads baseurl.
	 *
	 * @return string
	 */
	public static function uploads_baseurl(): string {
		$u = wp_get_upload_dir();
		return trailingslashit( $u['baseurl'] ?? '' );
	}

	/**
	 * Map an uploads URL (absolute, protocol-relative, or /wp-content/uploads/…) to a local path. Returns '' when the URL is outside uploads or unsafe.
	 *
	 * @param string $url Url.
	 *
	 * @return string
	 */
	public static function uploads_url_to_path( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES, 'UTF-8' );
		$url = (string) strtok( $url, '?' );
		$url = (string) strtok( $url, '#' );
		if ( '' === $url ) {
			return '';
		}
		if ( str_starts_with( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( str_starts_with( $url, '/' ) ) {
			$origin = wp_parse_url( home_url() );
			$host   = (string) ( $origin['host'] ?? '' );
			if ( '' === $host ) {
				return '';
			}
			$url = ( $origin['scheme'] ?? 'https' ) . '://' . $host . ( isset( $origin['port'] ) ? ':' . $origin['port'] : '' ) . $url;
		}
		$path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '' );
		if ( '' === $path ) {
			return '';
		}
		$path      = '/' . ltrim( wp_normalize_path( rawurldecode( $path ) ), '/' );
		$base_path = (string) ( wp_parse_url( self::uploads_baseurl(), PHP_URL_PATH ) ?? '' );
		$base_path = '/' . trim( wp_normalize_path( $base_path ), '/' );
		if ( '/' === $base_path ) {
			return '';
		}
		$path_cmp = strtolower( $path );
		$base_cmp = strtolower( $base_path );
		$next     = $path_cmp[ strlen( $base_cmp ) ] ?? '';
		if ( ! str_starts_with( $path_cmp, $base_cmp ) || ( '' !== $next && '/' !== $next ) ) {
			return '';
		}
		$rel = ltrim( substr( $path, strlen( $base_path ) ), '/' );
		if ( '' === $rel || str_contains( $rel, '..' ) ) {
			return '';
		}
		return trailingslashit( wp_normalize_path( self::uploads_basedir() ) ) . $rel;
	}

	/**
	 * Absolute path to an attachment's main uploads file.
	 *
	 * WordPress core only treats "D:\..." as a Windows absolute path, so meta values
	 * stored as "D:/..." are wrongly path_join()'d onto basedir and look missing.
	 *
	 * @param int $id Id.
	 *
	 * @return string
	 */
	public static function attached_file_path( int $id ): string {
		if ( $id < 1 ) {
			return '';
		}
		$meta = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' === $meta ) {
			$file = (string) get_attached_file( $id, true );
			return $file ? wp_normalize_path( $file ) : '';
		}
		return self::uploads_path_from_meta( $meta );
	}

	/**
	 * Absolute uploads path for a stored _wp_attached_file value. Absolute Windows paths (D:/...) stay on that file instead of being joined onto basedir again.
	 *
	 * @param string $meta Meta.
	 *
	 * @return string
	 */
	public static function uploads_path_from_meta( string $meta ): string {
		$base = trailingslashit( wp_normalize_path( self::uploads_basedir() ) );
		$file = wp_normalize_path( $meta );
		if ( '' === $file ) {
			return '';
		}
		$base_l = strtolower( $base );
		$file_l = strtolower( $file );
		if ( str_starts_with( $file_l, $base_l ) ) {
			return $base . substr( $file, strlen( $base ) );
		}
		if ( preg_match( '|^[a-z]:/|i', $file ) || str_starts_with( $file, '/' ) ) {
			if ( preg_match( '~/wp-content/uploads/(.+)$~i', $file, $m ) ) {
				return $base . $m[1];
			}
			return $file;
		}
		return $base . ltrim( $file, '/' );
	}

	/**
	 * Relative path under uploads, or empty when the attachment is outside basedir.
	 *
	 * @param int $id Id.
	 *
	 * @return string
	 */
	public static function attached_file_relative( int $id ): string {
		$file = self::attached_file_path( $id );
		if ( '' === $file ) {
			return '';
		}
		$base = trailingslashit( wp_normalize_path( self::uploads_basedir() ) );
		if ( ! str_starts_with( strtolower( $file ), strtolower( $base ) ) ) {
			return '';
		}
		return substr( $file, strlen( $base ) );
	}

	/**
	 * Pause long jobs before PHP memory or max_execution_time is exhausted.
	 */
	public static function resource_error() {
		$memory_limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $memory_limit > 0 ) {
			$used      = memory_get_usage( true );
			$remaining = $memory_limit - $used;
			$cap_mb    = (int) LucidLab_MPC_Settings::get( 'memory_threshold' );
			if ( $cap_mb > 0 && $used >= $cap_mb * 1024 * 1024 ) {
				/* translators: %d is the configured PHP memory safety cap in megabytes. */
				return new WP_Error( 'lucidlab_mpc_resource', sprintf( __( 'Paused: PHP memory use reached the %d MB safety cap. Resume after the current request ends, or raise the cap in Options.', 'lucidlab-mpc-cleanup' ), $cap_mb ), array( 'reason' => 'memory' ) );
			}
			if ( $remaining < 48 * 1024 * 1024 ) {
				/* translators: %s is the remaining PHP memory in megabytes. */
				return new WP_Error( 'lucidlab_mpc_resource', sprintf( __( 'Paused: only %s MB of PHP memory remains. Increase memory_limit or reduce batch size before resuming.', 'lucidlab-mpc-cleanup' ), (string) round( $remaining / 1024 / 1024, 1 ) ), array( 'reason' => 'memory' ) );
			}
		}
		$max_execution = (int) ini_get( 'max_execution_time' );
		if ( $max_execution > 0 ) {
			$elapsed   = microtime( true ) - ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ) );
			$remaining = $max_execution - $elapsed;
			$threshold = (int) LucidLab_MPC_Settings::get( 'timeout_threshold' );
			$threshold = $threshold > 0 ? max( 3, min( 25, $threshold ) ) : 8;
			if ( $remaining < $threshold ) {
				return new WP_Error( 'lucidlab_mpc_resource', __( 'Paused near the PHP execution limit. Resume in a new request or increase max_execution_time.', 'lucidlab-mpc-cleanup' ), array( 'reason' => 'timeout' ) );
			}
		}
		return null;
	}

	/**
	 * Mark cron ok.
	 */
	public static function mark_cron_ok(): void {
		update_option( 'lucidlab_mpc_cron_last_ok', time(), false );
	}

	/**
	 * Cron health.
	 *
	 * @return array
	 */
	public static function cron_health(): array {
		$disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$alternate = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;
		$doing     = defined( 'DOING_CRON' ) && DOING_CRON;
		if ( $doing ) {
			self::mark_cron_ok();
		}
		$last   = (int) get_option( 'lucidlab_mpc_cron_last_ok', 0 );
		$fresh  = $last && ( time() - $last ) <= 2 * HOUR_IN_SECONDS;
		$status = 'ok';
		$label  = __( 'WP-Cron recently ran. Server cron or site visits are triggering wp-cron.php.', 'lucidlab-mpc-cleanup' );
		if ( $fresh && $disabled ) {
			$label = __( 'DISABLE_WP_CRON is set and wp-cron.php ran recently. Your server cron runner is working.', 'lucidlab-mpc-cleanup' );
		} elseif ( $disabled && ! $fresh ) {
			$status = 'disabled';
			$label  = __( 'DISABLE_WP_CRON is set and wp-cron.php has not run recently. Confirm the server cron job that calls wp-cron.php is active.', 'lucidlab-mpc-cleanup' );
		} elseif ( $last && ! $fresh ) {
			$status = 'stale';
			$label  = __( 'No wp-cron.php activity in over two hours. Confirm a visitor or system cron is triggering WP-Cron.', 'lucidlab-mpc-cleanup' );
		} elseif ( ! $last ) {
			$status = 'idle';
			$label  = $disabled
				? __( 'DISABLE_WP_CRON is set. A last-run time appears after the next server cron call to wp-cron.php.', 'lucidlab-mpc-cleanup' )
				: __( 'WP-Cron is enabled. A last-run time appears after wp-cron.php runs or the first background job tick.', 'lucidlab-mpc-cleanup' );
		}
		if ( $alternate && 'ok' === $status ) {
			$label .= ' ' . __( 'ALTERNATE_WP_CRON is enabled.', 'lucidlab-mpc-cleanup' );
		}
		return array(
			'status'     => $status,
			'label'      => $label,
			'disabled'   => $disabled,
			'alternate'  => $alternate,
			'doing'      => $doing,
			'last'       => $last,
			'last_label' => $last ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) : __( 'Never', 'lucidlab-mpc-cleanup' ),
		);
	}

	/**
	 * Whether image rel.
	 *
	 * @param string $rel Rel.
	 *
	 * @return bool
	 */
	public static function is_image_rel( string $rel ): bool {
		$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'svg' ), true );
	}

	/**
	 * Site language visitors see, not the administrator UI language.
	 *
	 * @param string $locale Locale.
	 *
	 * @return string
	 */
	public static function language_name_from_locale( string $locale ): string {
		$code  = strtolower( str_replace( '-', '_', $locale ) );
		$short = substr( $code, 0, 2 );
		$names = array(
			'nl' => __( 'Dutch', 'lucidlab-mpc-cleanup' ),
			'en' => __( 'English', 'lucidlab-mpc-cleanup' ),
			'de' => __( 'German', 'lucidlab-mpc-cleanup' ),
			'fr' => __( 'French', 'lucidlab-mpc-cleanup' ),
			'es' => __( 'Spanish', 'lucidlab-mpc-cleanup' ),
			'it' => __( 'Italian', 'lucidlab-mpc-cleanup' ),
			'pt' => __( 'Portuguese', 'lucidlab-mpc-cleanup' ),
			'pl' => __( 'Polish', 'lucidlab-mpc-cleanup' ),
			'ru' => __( 'Russian', 'lucidlab-mpc-cleanup' ),
			'uk' => __( 'Ukrainian', 'lucidlab-mpc-cleanup' ),
			'cs' => __( 'Czech', 'lucidlab-mpc-cleanup' ),
			'da' => __( 'Danish', 'lucidlab-mpc-cleanup' ),
			'sv' => __( 'Swedish', 'lucidlab-mpc-cleanup' ),
			'nb' => __( 'Norwegian', 'lucidlab-mpc-cleanup' ),
			'nn' => __( 'Norwegian', 'lucidlab-mpc-cleanup' ),
			'fi' => __( 'Finnish', 'lucidlab-mpc-cleanup' ),
			'hu' => __( 'Hungarian', 'lucidlab-mpc-cleanup' ),
			'ro' => __( 'Romanian', 'lucidlab-mpc-cleanup' ),
			'el' => __( 'Greek', 'lucidlab-mpc-cleanup' ),
			'tr' => __( 'Turkish', 'lucidlab-mpc-cleanup' ),
			'ar' => __( 'Arabic', 'lucidlab-mpc-cleanup' ),
			'he' => __( 'Hebrew', 'lucidlab-mpc-cleanup' ),
			'ja' => __( 'Japanese', 'lucidlab-mpc-cleanup' ),
			'ko' => __( 'Korean', 'lucidlab-mpc-cleanup' ),
			'zh' => __( 'Chinese', 'lucidlab-mpc-cleanup' ),
			'vi' => __( 'Vietnamese', 'lucidlab-mpc-cleanup' ),
			'id' => __( 'Indonesian', 'lucidlab-mpc-cleanup' ),
		);
		return $names[ $short ] ?? ( '' !== $locale ? $locale : __( 'English', 'lucidlab-mpc-cleanup' ) );
	}

	/**
	 * Upper first.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	public static function upper_first( string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( mb_substr( $text, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $text, 1, null, 'UTF-8' );
		}
		return strtoupper( $text[0] ) . substr( $text, 1 );
	}

	/**
	 * Normal sentence: capital first letter and after . ! ? Keep existing capitals (names/places).
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	public static function sentence_text( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		$text = sanitize_text_field( $text );
		if ( '' === $text ) {
			return '';
		}
		$out = preg_replace_callback(
			'/(^|[.!?]\s+)(\S)/u',
			static function ( $m ) {
				return $m[1] . self::upper_first( $m[2] );
			},
			$text
		);
		return is_string( $out ) ? $out : $text;
	}

	/**
	 * File stem for a suggested name. Strips every image extension, including generated copies such as photo.jpg.webp, then returns a lowercase hyphenated stem.
	 *
	 * @param string $name Name.
	 *
	 * @return string
	 */
	public static function filename_stem( string $name ): string {
		$name     = wp_basename( str_replace( '\\', '/', trim( $name ) ) );
		$previous = null;
		while ( $name !== $previous ) {
			$previous = $name;
			$stripped = preg_replace( '/(?:\.|-)(?:jpe?g|png|gif|webp|avif|bmp|tiff?)$/i', '', $name );
			$name     = is_string( $stripped ) ? $stripped : $name;
		}
		$name = sanitize_title( $name );
		$name = strtolower( (string) preg_replace( '/[^a-z0-9_-]/', '-', $name ) );
		return trim( (string) preg_replace( '/-+/', '-', $name ), '-' );
	}

	/**
	 * Turn a hyphenated or underscored token into a sentence. Leave real sentences alone.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	public static function human_phrase( string $text ): string {
		$text = self::sentence_text( $text );
		if ( '' === $text ) {
			return '';
		}
		if ( ! preg_match( '/\s/u', $text ) && ( str_contains( $text, '-' ) || str_contains( $text, '_' ) || str_contains( $text, '.' ) ) ) {
			return self::title_from_slug( $text );
		}
		return $text;
	}

	/**
	 * Alt text that is only the site name is not a description of the picture.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	public static function alt_phrase( string $text ): string {
		$text = self::sentence_text( $text );
		$site = function_exists( 'get_bloginfo' ) ? trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) ) : '';
		if ( '' !== $text && '' !== $site && 0 === strcasecmp( $text, $site ) ) {
			return '';
		}
		return self::human_phrase( $text );
	}

	/**
	 * Title-style phrase from a kebab filename, then sentence-cased.
	 *
	 * @param string $slug Slug.
	 *
	 * @return string
	 */
	public static function title_from_slug( string $slug ): string {
		$slug  = strtolower( str_replace( array( '_', '.' ), '-', $slug ) );
		$parts = preg_split( '/-+/', $slug, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $parts ) {
			return '';
		}
		$small = array( 'a', 'an', 'and', 'as', 'at', 'by', 'for', 'from', 'in', 'of', 'on', 'or', 'the', 'to', 'with' );
		$words = array();
		foreach ( $parts as $i => $part ) {
			$lower   = strtolower( $part );
			$words[] = ( $i > 0 && in_array( $lower, $small, true ) ) ? $lower : self::upper_first( $lower );
		}
		return self::sentence_text( implode( ' ', $words ) );
	}

	/**
	 * Recursively remove empty folders under uploads (skips the quarantine dir). Returns count removed.
	 *
	 * @param string $skip_dir Skip dir.
	 *
	 * @return int
	 */
	public static function remove_empty_dirs( string $skip_dir = '' ): int {
		$base = rtrim( self::uploads_basedir(), '/' );
		$skip = $skip_dir ? self::norm_path( $skip_dir ) : '';
		if ( ! is_dir( $base ) ) {
			return 0;
		}
		$removed = 0;
		$it      = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			if ( ! $item->isDir() ) {
				continue;
			}
			$path = self::norm_path( $item->getPathname() );
			if ( $skip && ( $path === $skip || str_starts_with( $path, $skip . '/' ) ) ) {
				continue;
			}
			$kids = ( is_dir( $item->getPathname() ) ? scandir( $item->getPathname() ) : false );
			if ( is_array( $kids ) && count( $kids ) <= 2 && rmdir( $item->getPathname() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Delete a local file through WordPress and report whether it is gone.
	 *
	 * @param string $path Absolute file path.
	 *
	 * @return bool True when the file no longer exists.
	 */
	public static function delete_file( string $path ): bool {
		if ( '' === $path || ! file_exists( $path ) ) {
			return true;
		}
		wp_delete_file( $path );
		clearstatcache( true, $path );
		return ! file_exists( $path );
	}

	/**
	 * Raise the PHP time limit for the current long-running operation only.
	 *
	 * Called from inside a specific backup, compression or AI request method, never on
	 * load or init. The value is bounded so a request can never run without a limit.
	 *
	 * @param int $seconds Seconds to allow (1-600).
	 *
	 * @return void
	 */
	public static function extend_time_limit( int $seconds ): void {
		$seconds = max( 1, min( 600, $seconds ) );
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( $seconds ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped to the calling long operation (backup/AI request), bounded to 600 s.
		}
	}

	/**
	 * Decode a JSON request value and sanitize the result against a schema.
	 *
	 * @param mixed        $raw    Slashed request value holding JSON text.
	 * @param string|array $schema See sanitize_decoded().
	 * @return mixed Sanitized value, or null when the input is not valid JSON.
	 */
	public static function json_input( $raw, $schema = 'auto' ) {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$decoded = json_decode( wp_unslash( $raw ), true );
		if ( null === $decoded && 'null' !== trim( wp_unslash( $raw ) ) ) {
			return null;
		}
		return self::sanitize_decoded( $decoded, $schema );
	}

	/**
	 * Recursively sanitize decoded JSON against an expected schema.
	 *
	 * Schema forms:
	 * - 'int', 'absint', 'float', 'bool', 'key', 'text', 'textarea', 'url', 'path': a scalar of that type.
	 * - 'auto': scalars keep their JSON type (strings pass sanitize_textarea_field), arrays recurse with 'auto'.
	 * - array( 'list' => schema ): a list; every item matches schema.
	 * - array( 'map' => schema ): string keys (sanitize_text_field) mapping to schema.
	 * - array( 'fields' => array( key => schema ) ): an object; unknown keys are dropped.
	 *
	 * @param mixed        $value  Decoded value.
	 * @param string|array $schema Expected schema.
	 * @param int          $depth  Current depth; nesting beyond 16 levels is dropped.
	 * @return mixed
	 */
	public static function sanitize_decoded( $value, $schema = 'auto', int $depth = 0 ) {
		if ( $depth > 16 ) {
			return null;
		}
		if ( is_array( $schema ) ) {
			if ( ! is_array( $value ) ) {
				return array();
			}
			$out = array();
			if ( isset( $schema['list'] ) ) {
				foreach ( array_values( $value ) as $item ) {
					$out[] = self::sanitize_decoded( $item, $schema['list'], $depth + 1 );
				}
			} elseif ( isset( $schema['map'] ) ) {
				foreach ( $value as $key => $item ) {
					$out[ is_int( $key ) ? $key : sanitize_text_field( (string) $key ) ] = self::sanitize_decoded( $item, $schema['map'], $depth + 1 );
				}
			} elseif ( isset( $schema['fields'] ) && is_array( $schema['fields'] ) ) {
				foreach ( $schema['fields'] as $key => $field_schema ) {
					if ( array_key_exists( $key, $value ) ) {
						$out[ $key ] = self::sanitize_decoded( $value[ $key ], $field_schema, $depth + 1 );
					}
				}
			}
			return $out;
		}
		if ( 'auto' === $schema ) {
			if ( is_array( $value ) ) {
				return self::sanitize_decoded( $value, array( 'map' => 'auto' ), $depth );
			}
			if ( is_string( $value ) ) {
				return sanitize_textarea_field( $value );
			}
			return is_int( $value ) || is_float( $value ) || is_bool( $value ) ? $value : null;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = '';
		}
		switch ( $schema ) {
			case 'int':
				return (int) $value;
			case 'absint':
				return absint( $value );
			case 'float':
				return (float) $value;
			case 'bool':
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			case 'key':
				return sanitize_key( (string) $value );
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			case 'url':
				return esc_url_raw( (string) $value );
			case 'path':
				return str_replace( chr( 0 ), '', wp_normalize_path( sanitize_text_field( (string) $value ) ) );
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Return the first value when truthy, otherwise the fallback.
	 *
	 * Replacement for the short ternary operator.
	 *
	 * @param mixed $value    Preferred value.
	 * @param mixed $fallback Value used when $value is falsy.
	 *
	 * @return mixed
	 */
	public static function either( $value, $fallback ) {
		return $value ? $value : $fallback;
	}

	/**
	 * Path of a directory relative to ABSPATH, or '' when it lies outside the WordPress root.
	 *
	 * @param string $dir Absolute directory.
	 * @return string
	 */
	public static function rel_to_root( string $dir ): string {
		$root = rtrim( wp_normalize_path( ABSPATH ), '/' ) . '/';
		$dir  = rtrim( wp_normalize_path( $dir ), '/' );
		return str_starts_with( $dir . '/', $root ) ? trim( substr( $dir, strlen( $root ) ), '/' ) : '';
	}

	/**
	 * Admin directory relative to ABSPATH, derived from admin_url() and site_url().
	 *
	 * @return string
	 */
	public static function admin_rel(): string {
		static $rel = null;
		if ( null === $rel ) {
			$admin = (string) wp_parse_url( admin_url(), PHP_URL_PATH );
			$site  = rtrim( (string) wp_parse_url( site_url(), PHP_URL_PATH ), '/' );
			$rel   = trim( '' !== $site && str_starts_with( $admin, $site ) ? substr( $admin, strlen( $site ) ) : $admin, '/' );
			if ( '' === $rel || ! is_dir( ABSPATH . $rel ) ) {
				$rel = basename( $admin );
			}
		}
		return $rel;
	}

	/**
	 * Absolute admin directory with a trailing slash.
	 *
	 * @return string
	 */
	public static function admin_dir(): string {
		return trailingslashit( ABSPATH . self::admin_rel() );
	}

	/**
	 * Standard WordPress folders (relative to ABSPATH) included in a full-site archive.
	 *
	 * @return array<int,string>
	 */
	public static function site_folders(): array {
		$uploads = wp_upload_dir( null, false );
		return array_values(
			array_filter(
				array_unique(
					array(
						self::admin_rel(),
						WPINC,
						self::rel_to_root( WP_PLUGIN_DIR ),
						self::rel_to_root( get_theme_root() ),
						self::rel_to_root( (string) ( $uploads['basedir'] ?? '' ) ),
					)
				)
			)
		);
	}
}
