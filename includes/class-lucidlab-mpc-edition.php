<?php
/**
 * Lucid Lab Media & Products Cleanup: edition class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Shared access policy. Recovery never depends on the Pro add-on. */
final class LucidLab_MPC_Edition {
	/**
	 * Pro.
	 *
	 * @return bool
	 */
	public static function pro(): bool {
		return class_exists( 'LucidLab_MPC_Pro', false ) && LucidLab_MPC_Pro::ready(); }
	/**
	 * Pro tabs.
	 *
	 * @return array
	 */
	public static function pro_tabs(): array {
		return array( 'uploads', 'duplicates', 'visual', 'review', 'rename_ai', 'product_copy', 'dbcleaner' );
	}
	/**
	 * Tabs whose primary workflow requires Pro. WebP conversion is available in Free.
	 *
	 * @return array
	 */
	public static function pro_badge_tabs(): array {
		return self::pro_tabs();
	}
	/**
	 * Address of the "Get Pro" links on locked Pro tabs.
	 *
	 * @return string
	 */
	public static function pro_url(): string {
		/**
		 * Filters the "Get Pro" link target.
		 *
		 * @param string $url Default the Lucid Lab product page.
		 */
		return (string) apply_filters( 'lucidlab_mpc_pro_url', 'https://lucidlab.eu/product/media-products-cleanup/' );
	}
	/**
	 * Pro quarantine source.
	 *
	 * @param string $source Source.
	 *
	 * @return bool
	 */
	public static function pro_quarantine_source( string $source ): bool {
		return in_array( $source, array( 'uploads', 'fs-sizes', 'webp', 'avif', 'originals', 'dupcontent', 'visual', 'dbcleaner' ), true );
	}
	/**
	 * Known tabs.
	 *
	 * @return array
	 */
	public static function known_tabs(): array {
		// Keep in sync with menu_tabs() order.
		return array( 'welcome', 'guide', 'orphans', 'sizes', 'uploads', 'shared', 'dupcontent', 'visual', 'duplicates', 'missing', 'regenerate', 'webp', 'rename', 'rename_ai', 'product_copy', 'review', 'dbcleaner', 'quarantine', 'backup', 'options', 'logs' );
	}
	/**
	 * Parallel jobs.
	 *
	 * @return bool
	 */
	public static function parallel_jobs(): bool {
		return self::pro(); }
	/**
	 * How many jobs may run at the same time. Free runs one job. Pro runs two by default; set
	 * LUCIDLAB_MPC_PRO_MAX_CONCURRENT or use the lucidlab_mpc_max_concurrent_jobs filter (1 to 3).
	 *
	 * @return int
	 */
	public static function max_concurrent_jobs(): int {
		if ( ! self::pro() ) {
			return 1;
		}
		$max = defined( 'LUCIDLAB_MPC_PRO_MAX_CONCURRENT' ) ? (int) constant( 'LUCIDLAB_MPC_PRO_MAX_CONCURRENT' ) : 2;
		/**
		 * Filters the number of Pro jobs that may run at the same time.
		 *
		 * @param int $max Maximum concurrent jobs, 1 to 3.
		 */
		$max = (int) apply_filters( 'lucidlab_mpc_max_concurrent_jobs', $max );
		return max( 1, min( 3, $max ) );
	}
	/**
	 * Tab.
	 *
	 * @param string $tab Tab.
	 *
	 * @return bool
	 */
	public static function tab( string $tab ): bool {
		if ( ! in_array( $tab, self::known_tabs(), true ) ) {
			return false;
		}
		if ( in_array( $tab, self::pro_tabs(), true ) ) {
			return self::pro();
		}
		return true;
	}
	/**
	 * Allows.
	 *
	 * @param array $p P.
	 *
	 * @return bool
	 */
	public static function allows( array $p ): bool {
		if ( self::pro() ) {
			return true;
		}
		$op      = $p['op'] ?? '';
		$allowed = in_array( $op, array( 'rename', 'regen', 'backup', 'inventory', 'scan_orphans', 'scan_shared', 'scan_sizes', 'send_test_report', 'email_emergency_restore', 'restore_quarantine', 'restore_one', 'purge_quarantine', 'purge_source', 'undo_merge', 'undo_rename', 'purge_missing', 'save_options', 'reset_plugin_data', 'save_logging', 'log_clear', 'rebuild_index', 'dismiss_setup', 'webp_preview', 'webp_preview_clear', 'convert', 'list_folders', 'core_check', 'lazy_refs', 'load_scan' ), true );
		// Free keeps registered-size disable/save; Other Sizes on Disk / quarantine stay Pro.
		if ( 'scan' === $op ) {
			$allowed = in_array( $p['tab'] ?? '', array( 'orphans', 'shared', 'dupcontent', 'missing', 'crawl', 'sizes' ), true );
		}
		if ( 'quarantine' === $op ) {
			$allowed = in_array( $p['tab'] ?? '', array( 'orphans', 'shared' ), true );
		}
		if ( 'preview' === $op ) {
			$allowed = ( ( $p['preview'] ?? '' ) === 'quarantine' && in_array( $p['tab'] ?? '', array( 'orphans', 'shared' ), true ) );
		}
		if ( 'lazy_refs' === $op ) {
			$allowed = in_array( $p['tab'] ?? '', array( 'dupcontent', 'shared' ), true );
		}
		if ( 'form' === $op ) {
			$allowed = in_array( $p['sub_action'] ?? ( $p['action'] ?? '' ), array( 'restore_quarantine', 'restore_one', 'purge_quarantine', 'purge_source', 'undo_merge' ), true );
		}
		if ( 'rename_job' === $op && in_array( $p['sub'] ?? '', array( 'status', 'stop', 'cancel' ), true ) ) {
			$allowed = true;
		}
		// Free may continue Free jobs in the background (single-job lock still applies).
		if ( 'regen' === $op && 'bg' === ( $p['sub'] ?? '' ) ) {
			$allowed = true;
		}
		if ( 'backup' === $op && 'bg' === ( $p['sub'] ?? '' ) ) {
			$allowed = true;
		}
		if ( 'taskjob' === $op && in_array( $p['sub'] ?? '', array( 'status', 'stop', 'cancel', 'retry', 'background' ), true ) ) {
			$allowed = true;
		}
		if ( 'crawl_rows' === $op ) {
			$allowed = true;
		}
		return $allowed;
	}
	/**
	 * Check.
	 *
	 * @param array $p P.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	public static function check( array $p ): void {
		if ( ! self::allows( $p ) ) {
			throw new RuntimeException( __( 'This action requires the Media & Products Cleanup Pro add-on. Restore and undo remain available in Free.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
	}
}
