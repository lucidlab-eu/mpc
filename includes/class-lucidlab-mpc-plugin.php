<?php
/**
 * Lucid Lab Media & Products Cleanup: plugin class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main orchestrator: menu, assets, page rendering, synchronous POST ops, AJAX scan/quarantine,
 * CSV export, cron scheduling.
 */
class LucidLab_MPC_Plugin {
	use LucidLab_MPC_Plugin_Pro_Methods;
	use LucidLab_MPC_Plugin_Formats;

	const SLUG                = 'lucidlab-mpc-cleanup';
	const NONCE               = 'lucidlab_mpc_ajax';
	const CRON_PURGE          = 'lucidlab_mpc_daily_purge';
	const CRON_REPORT         = 'lucidlab_mpc_weekly_report';
	const CRON_REPORT_MONTHLY = 'lucidlab_mpc_monthly_report';

	/** Saved result list format. Bump only when the stored structure changes; older lists then read as never run. */
	const RESULT_FORMAT = 1;

	/**
	 * Instance.
	 *
	 * @var LucidLab_MPC_Plugin|null
	 */
	private static ?LucidLab_MPC_Plugin $instance = null;

	/**
	 * Duplicates.
	 *
	 * @var LucidLab_MPC_Duplicates
	 */
	private LucidLab_MPC_Duplicates $duplicates;
	/**
	 * Orphans.
	 *
	 * @var LucidLab_MPC_Orphans
	 */
	private LucidLab_MPC_Orphans $orphans;
	/**
	 * Uploads.
	 *
	 * @var LucidLab_MPC_Uploads
	 */
	private LucidLab_MPC_Uploads $uploads;
	/**
	 * Shared.
	 *
	 * @var LucidLab_MPC_Shared
	 */
	private LucidLab_MPC_Shared $shared;
	/**
	 * Sizes.
	 *
	 * @var LucidLab_MPC_Sizes
	 */
	private LucidLab_MPC_Sizes $sizes;
	/**
	 * Fs sizes.
	 *
	 * @var LucidLab_MPC_Fs_Sizes
	 */
	private LucidLab_MPC_Fs_Sizes $fs_sizes;
	/**
	 * Dupcontent.
	 *
	 * @var LucidLab_MPC_DupContent
	 */
	private LucidLab_MPC_DupContent $dupcontent;
	/**
	 * Rename.
	 *
	 * @var LucidLab_MPC_Rename
	 */
	private LucidLab_MPC_Rename $rename;
	/**
	 * Webp.
	 *
	 * @var LucidLab_MPC_Webp
	 */
	private LucidLab_MPC_Webp $webp;
	/**
	 * Regen.
	 *
	 * @var LucidLab_MPC_Regenerate
	 */
	private LucidLab_MPC_Regenerate $regen;
	/**
	 * Quar.
	 *
	 * @var LucidLab_MPC_Quarantine
	 */
	private LucidLab_MPC_Quarantine $quar;
	/**
	 * Reports.
	 *
	 * @var LucidLab_MPC_Reports
	 */
	private LucidLab_MPC_Reports $reports;

	/**
	 * Whether an overview Lucid Lab card is currently open in the active panel.
	 *
	 * @var bool
	 */
	private bool $lucidlab_mpc_card_overview_open = false;
	/**
	 * Whether a results Lucid Lab card is currently open in the active panel.
	 *
	 * @var bool
	 */
	private bool $lucidlab_mpc_card_results_open = false;

	/**
	 * Instance.
	 *
	 * @return LucidLab_MPC_Plugin
	 */
	public static function instance(): LucidLab_MPC_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		LucidLab_MPC_Hooks::register();
		if ( LucidLab_MPC_Edition::pro() ) {
			add_action(
				'lucidlab_mpc_automation_retry',
				static function ( $frequency, $choice ) {
					LucidLab_MPC_Automation::run( $frequency, array( $choice ) );
				},
				10,
				2
			);
			foreach ( array( 'weekly', 'monthly' ) as $frequency ) {
				add_action(
					'lucidlab_mpc_automation_' . $frequency,
					static function () use ( $frequency ) {
						LucidLab_MPC_Automation::run( $frequency );
					}
				);
			}
			$this->duplicates = new LucidLab_MPC_Duplicates(); }
		$this->orphans = new LucidLab_MPC_Orphans();
		$this->uploads = new LucidLab_MPC_Uploads();
		$this->shared  = new LucidLab_MPC_Shared();
		$this->sizes   = new LucidLab_MPC_Sizes();
		if ( LucidLab_MPC_Edition::pro() ) {
			$this->fs_sizes = new LucidLab_MPC_Fs_Sizes();
		}
		$this->dupcontent = new LucidLab_MPC_DupContent();
		$this->rename     = new LucidLab_MPC_Rename();
		$this->webp       = new LucidLab_MPC_Webp();
		$this->regen      = new LucidLab_MPC_Regenerate();
		$this->quar       = new LucidLab_MPC_Quarantine();

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			LucidLab_MPC_Helpers::mark_cron_ok();
		}
		add_action(
			'lucidlab_mpc_task_tick',
			function ( $owner, $key ) {
				( new LucidLab_MPC_Task_Job( $this ) )->tick( (int) $owner, (string) $key );
			},
			10,
			2
		);
		add_action( LucidLab_MPC_Webp::CRON_HOOK, array( $this->webp, 'cron_tick' ) );
		add_action( self::CRON_PURGE, array( $this->webp, 'recover_stale' ), 3 );
		if ( LucidLab_MPC_Edition::pro() ) {
			add_action( LucidLab_MPC_Rename::CRON_HOOK, array( $this->rename, 'cron_tick' ) );
		}
		add_action( LucidLab_MPC_Regenerate::CRON_HOOK, array( $this->regen, 'cron_tick' ) );
		add_action( LucidLab_MPC_Backup::CRON_HOOK, array( 'LucidLab_MPC_Backup', 'cron_tick' ) );
		$this->reports = new LucidLab_MPC_Reports();

		add_action( 'admin_init', array( $this, 'redirect_legacy_menu' ) );
		// A removed Pro menu page can be rejected by WordPress before admin_init.
		add_action( 'init', array( $this, 'redirect_legacy_menu' ), 1 );
		add_action( 'admin_init', array( 'LucidLab_MPC_AI', 'register_privacy_policy' ) );
		add_action( 'admin_init', array( __CLASS__, 'stop_autoloading_scan_state' ) );
		self::store_large_transient_in_file( 'lucidlab_mpc_cached_reg_v2' );
		// WordPress 6.8 renamed setted_transient to set_transient; keep the old hook for 6.4-6.7.
		add_action(
			version_compare( get_bloginfo( 'version' ), '6.8', '>=' ) ? 'set_transient' : 'setted_transient',
			static function ( $transient, $value ) {
				if ( ! is_string( $transient ) || ! preg_match( '/^lucidlab_mpc_result_([a-z0-9-]+)_\d+$/', $transient, $match ) ) {
					return;
				}
				$counts = array();
				foreach ( (array) $value as $key => $v ) {
					if ( is_int( $v ) || is_float( $v ) ) {
						$counts[ $key ] = $v;
					}
				}
				// File-backed results keep only scalars in the transient marker; recover list length when present.
				if ( 'missing' === $match[1] ) {
					if ( isset( $value['images'] ) && is_array( $value['images'] ) && ! isset( $counts['missing'] ) ) {
						$counts['missing'] = count( $value['images'] );
					}
					if ( isset( $value['urls'] ) && is_array( $value['urls'] ) && ! isset( $counts['broken_urls'] ) ) {
						$counts['broken_urls'] = count( $value['urls'] );
					}
				}
				if ( isset( $value['images'] ) && is_array( $value['images'] ) && ! isset( $counts['image_count'] ) && 'shared' === $match[1] ) {
					$counts['image_count'] = count( $value['images'] );
				}
				if ( $counts ) {
					update_option(
						'lucidlab_mpc_summary_' . $match[1],
						array(
							'updated' => time(),
							'counts'  => $counts,
						),
						false
					);
				}
			},
			10,
			2
		);
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_lucidlab_mpc_export_csv', array( $this, 'handle_csv_export' ) );
		add_action( 'admin_post_lucidlab_mpc_export_manifest', array( $this, 'handle_manifest_export' ) );
		add_action( 'admin_post_lucidlab_mpc_export_stats', array( $this, 'handle_stats_export' ) );
		add_action( 'admin_post_lucidlab_mpc_export_pdf', array( $this, 'handle_pdf_export' ) );
		add_action( 'admin_post_lucidlab_mpc_export_log', array( $this, 'handle_log_export' ) );
		add_action( 'admin_post_lucidlab_mpc_backup_download', array( $this, 'handle_backup_download' ) );
		add_action( 'wp_ajax_lucidlab_mpc_op', array( $this, 'handle_ajax' ) );
		if ( LucidLab_MPC_Edition::pro() ) {
			add_action( self::CRON_PURGE, array( $this->quar, 'purge_expired' ) );
		}
		add_action( self::CRON_REPORT, array( $this->reports, 'cron_send' ) );
		add_action(
			self::CRON_REPORT_MONTHLY,
			function () {
				$this->reports->cron_send( 'monthly' );
			}
		);
		add_filter(
			'plugin_action_links_' . plugin_basename( LUCIDLAB_MPC_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( $this->tab_url( 'options' ) ) . ( '">' . esc_html__( 'Settings', 'lucidlab-mpc-cleanup' ) . '</a>' ) );
				return $links;
			}
		);
		add_filter( 'debug_information', array( $this, 'site_health_info' ) );
		add_action( 'activated_plugin', array( $this, 'on_pro_plugin_activated' ) );
	}

	/**
	 * Pro add-on plugin basename under wp-content/plugins.
	 *
	 * @return string
	 */
	private function pro_plugin_basename(): string {
		return 'lucidlab-mpc-cleanup-pro/lucidlab-mpc-cleanup-pro.php';
	}

	/**
	 * Whether the Pro add-on files are present (active or not).
	 *
	 * @return bool
	 */
	private function pro_plugin_installed(): bool {
		return is_readable( WP_PLUGIN_DIR . '/' . $this->pro_plugin_basename() );
	}

	/**
	 * Whether the Pro add-on plugin is activated in WordPress.
	 *
	 * @return bool
	 */
	private function pro_plugin_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once LucidLab_MPC_Helpers::admin_dir() . 'includes/plugin.php';
		}
		return is_plugin_active( $this->pro_plugin_basename() );
	}

	/**
	 * Clear the dismiss flag so the notice can return if Pro is later deactivated.
	 *
	 * @param string $plugin Plugin.
	 */
	public function on_pro_plugin_activated( string $plugin ): void {
		if ( 'lucidlab-mpc-cleanup-pro/lucidlab-mpc-cleanup-pro.php' !== $plugin ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id ) {
			delete_user_meta( $user_id, 'lucidlab_mpc_hide_pro_inactive_notice' );
		}
	}

	/**
	 * Installed-but-inactive Pro notice: hide while dismissed for this user.
	 *
	 * @return bool
	 */
	private function should_show_pro_inactive_notice(): bool {
		if ( LucidLab_MPC_Edition::pro() || $this->pro_plugin_active() || ! $this->pro_plugin_installed() ) {
			return false;
		}
		$user_id = get_current_user_id();
		return $user_id > 0 && ! get_user_meta( $user_id, 'lucidlab_mpc_hide_pro_inactive_notice', true );
	}

	/**
	 * Soft yellow card above the hero when Pro is installed but not activated.
	 */
	private function render_pro_inactive_notice(): void {
		if ( ! $this->should_show_pro_inactive_notice() ) {
			return;
		}
		$basename = $this->pro_plugin_basename();
		$activate = wp_nonce_url(
			admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $basename ) . '&plugin_status=all&paged=1&s' ),
			'activate-plugin_' . $basename
		);
		$dismiss  = wp_nonce_url(
			add_query_arg( 'lucidlab_mpc_dismiss_pro_inactive', '1' ),
			'lucidlab_mpc_dismiss_pro_inactive'
		);
		?>
		<section class="lucidlab-mpc-card lucidlab-mpc-card--pro-inactive" id="lucidlab-mpc-pro-inactive-notice" data-testid="pro-inactive-notice" role="status">
			<div class="lucidlab-mpc-pro-notice lucidlab-mpc-pro-notice--inactive">
				<p><?php esc_html_e( 'Media & Products Cleanup Pro is installed but not activated. Activate it to unlock Pro tools on this site.', 'lucidlab-mpc-cleanup' ); ?></p>
				<p class="lucidlab-mpc-pro-inactive-actions">
					<a class="button button-primary" href="<?php echo esc_url( $activate ); ?>" data-testid="activate-pro"><?php esc_html_e( 'Activate Pro', 'lucidlab-mpc-cleanup' ); ?></a>
					<a class="lucidlab-mpc-pro-inactive-dismiss" href="<?php echo esc_url( $dismiss ); ?>" data-testid="dismiss-pro-inactive"><?php esc_html_e( 'Hide this message', 'lucidlab-mpc-cleanup' ); ?></a>
				</p>
			</div>
		</section>
		<?php
	}

	/**
	 * Surface reclaimable space + breakdown in Site Health → Info.
	 *
	 * @param mixed $info Info.
	 */
	public function site_health_info( $info ) {
		$fields = array(
			'total' => array(
				'label' => __( 'Total reclaimable (last scans)', 'lucidlab-mpc-cleanup' ),
				'value' => LucidLab_MPC_Helpers::bytes( LucidLab_MPC_Stats::total_bytes() ) . ( LucidLab_MPC_Stats::last_updated() ? ' — ' . sprintf( /* translators: %s: human-readable time difference, such as "3 hours". */ __( 'as of %s ago', 'lucidlab-mpc-cleanup' ), human_time_diff( LucidLab_MPC_Stats::last_updated() ) ) : '' ),
			),
		);
		foreach ( LucidLab_MPC_Stats::all() as $slice => $s ) {
			$fields[ $slice ] = array(
				'label' => LucidLab_MPC_Stats::label( $slice ),
				'value' => sprintf( '%s (%d items)', LucidLab_MPC_Helpers::bytes( (int) ( $s['bytes'] ?? 0 ) ), (int) ( $s['count'] ?? 0 ) ),
			);
		}
		$info['lucidlab-mpc-cleanup'] = array(
			'label'       => __( 'Media & Products Cleanup', 'lucidlab-mpc-cleanup' ),
			'description' => __( 'Reclaimable space found by recent scans. Run scans in Tools → Media & Products Cleanup to refresh.', 'lucidlab-mpc-cleanup' ),
			'fields'      => $fields,
		);
		return $info;
	}

	/* ---------- activation ---------- */

	/**
	 * Activate.
	 */
	public static function activate(): void {
		if ( LucidLab_MPC_Edition::pro() && ! empty( LucidLab_MPC_Settings::get( 'quarantine_auto_purge' ) ) && ! wp_next_scheduled( self::CRON_PURGE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE );
		}
		if ( false === get_option( 'lucidlab_mpc_setup_done', false ) ) {
			add_option( 'lucidlab_mpc_setup_pending', 1, '', false );
		}
		// Build the media index in the background when it is missing or empty.
		delete_option( LucidLab_MPC_Index::SEEN_VERSION );
		LucidLab_MPC_Index::maybe_schedule_build();
		LucidLab_MPC_Site_Ping::mark_installed();
		$plugin = self::instance();
		if ( isset( $plugin->reports ) && method_exists( $plugin->reports, 'configure' ) ) {
			$plugin->reports->configure();
		}
		if ( LucidLab_MPC_Edition::pro() && class_exists( 'LucidLab_MPC_Automation', false ) ) {
			LucidLab_MPC_Automation::configure();
		}
	}

	/**
	 * Deactivate.
	 */
	public static function deactivate(): void {
		foreach ( array( self::CRON_PURGE, self::CRON_REPORT, self::CRON_REPORT_MONTHLY, 'lucidlab_mpc_rename_tick', 'lucidlab_mpc_webp_tick' ) as $hook ) {
			$ts = wp_next_scheduled( $hook );
			if ( $ts ) {
				wp_unschedule_event( $ts, $hook );
			}
		}
		LucidLab_MPC_Index::stop_autobuild();
		LucidLab_MPC_Site_Ping::stop();
		// A deactivated plugin must not keep rewriting image requests; admin_init adds the block back on reactivation.
		LucidLab_MPC_Webp_Rewrite::remove();
	}

	/* ---------- menu + assets ---------- */

	/**
	 * Redirect legacy menu.
	 */
	public function redirect_legacy_menu(): void {
		global $pagenow;
		$request_page = ( $pagenow ? $pagenow : basename( sanitize_file_name( wp_unslash( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ) ) ) );
		if ( 'admin.php' === $request_page && 'GET' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && 'lucidlab-mpc-pro-license' === sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ) && current_user_can( 'manage_options' ) && ! $this->pro_plugin_active() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
			wp_safe_redirect( $this->tab_url( 'welcome' ) );
			exit;
		}
		if ( 'tools.php' === $pagenow && 'GET' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && self::SLUG === sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
			wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	/**
	 * Menu tabs.
	 *
	 * @return array
	 */
	private function menu_tabs(): array {
		// Cleanup flow first; Backup and Options sit directly under Quarantine.
		$tabs = array(
			'welcome'      => __( 'Getting Started', 'lucidlab-mpc-cleanup' ),
			'guide'        => __( 'How scans work', 'lucidlab-mpc-cleanup' ),
			'orphans'      => __( 'Orphan Images', 'lucidlab-mpc-cleanup' ),
			'sizes'        => __( 'Image Sizes', 'lucidlab-mpc-cleanup' ),
			'uploads'      => __( 'Uploads Scanner', 'lucidlab-mpc-cleanup' ),
			'shared'       => __( 'Shared Images', 'lucidlab-mpc-cleanup' ),
			'dupcontent'   => __( 'Byte Identical Images', 'lucidlab-mpc-cleanup' ),
			'visual'       => __( 'Visually Identical Images', 'lucidlab-mpc-cleanup' ),
			'duplicates'   => __( 'Duplicate Products', 'lucidlab-mpc-cleanup' ),
			'missing'      => __( 'Missing Images', 'lucidlab-mpc-cleanup' ),
			'regenerate'   => __( 'Regenerate Thumbnails', 'lucidlab-mpc-cleanup' ),
			'webp'         => __( 'WebP / AVIF', 'lucidlab-mpc-cleanup' ),
			'rename'       => __( 'Rename Images', 'lucidlab-mpc-cleanup' ),
			'rename_ai'    => __( 'Rename Images AI', 'lucidlab-mpc-cleanup' ),
			'product_copy' => __( 'Product AI', 'lucidlab-mpc-cleanup' ),
			'review'       => __( 'Review Queue', 'lucidlab-mpc-cleanup' ),
			'dbcleaner'    => __( 'Database Cleaner', 'lucidlab-mpc-cleanup' ),
			'quarantine'   => __( 'Quarantine', 'lucidlab-mpc-cleanup' ),
			'backup'       => __( 'Backup', 'lucidlab-mpc-cleanup' ),
			'options'      => __( 'Options', 'lucidlab-mpc-cleanup' ),
			'logs'         => __( 'Logs / Diagnostics', 'lucidlab-mpc-cleanup' ),
		);

		return $tabs;
	}

	/**
	 * Short help blurbs for the compact page header (non-Getting Started tabs).
	 *
	 * @return array
	 */
	private function tab_blurbs(): array {
		return array(
			'guide'        => __( 'How each scan decides what is used, what it checks, and what it never deletes on its own.', 'lucidlab-mpc-cleanup' ),
			'orphans'      => __( 'Find Media Library images nothing still references, then move selected ones to restorable quarantine.', 'lucidlab-mpc-cleanup' ),
			'sizes'        => __( 'Review registered thumbnail sizes, disk use, and leftover resized files on disk.', 'lucidlab-mpc-cleanup' ),
			'uploads'      => __( 'Find files in uploads that are not in the Media Library and are not still named in content.', 'lucidlab-mpc-cleanup' ),
			'shared'       => __( 'See images used in two or more places before you remove or quarantine anything.', 'lucidlab-mpc-cleanup' ),
			'dupcontent'   => __( 'Group byte-identical originals, review where each copy is used, and merge extras safely.', 'lucidlab-mpc-cleanup' ),
			'visual'       => __( 'Review look-alike image candidates. Nothing merges automatically.', 'lucidlab-mpc-cleanup' ),
			'duplicates'   => __( 'Find WooCommerce products that share a title, slug, or title and SKU.', 'lucidlab-mpc-cleanup' ),
			'missing'      => __( 'Spot missing attachment files and broken upload URLs, including live-page checks.', 'lucidlab-mpc-cleanup' ),
			'regenerate'   => __( 'Rebuild missing WordPress thumbnail sizes for selected images.', 'lucidlab-mpc-cleanup' ),
			'webp'         => LucidLab_MPC_Edition::pro() ? __( 'Create WebP or AVIF copies, compare formats, and clean orphaned generated files.', 'lucidlab-mpc-cleanup' ) : __( 'Create WebP copies and compare ten sample images.', 'lucidlab-mpc-cleanup' ),
			'rename'       => __( 'Rename image files and titles with patterns. Recent renames stay restorable.', 'lucidlab-mpc-cleanup' ),
			'rename_ai'    => __( 'Review AI filename and alt-text suggestions before anything is applied.', 'lucidlab-mpc-cleanup' ),
			'product_copy' => __( 'Create and review AI product titles, short descriptions and long descriptions from featured images.', 'lucidlab-mpc-cleanup' ),
			'review'       => __( 'Triage scanner and AI findings before you quarantine or rename.', 'lucidlab-mpc-cleanup' ),
			'dbcleaner'    => __( 'Quarantine leftover tables and orphan records from inactive or missing plugins.', 'lucidlab-mpc-cleanup' ),
			'quarantine'   => __( 'Restore items, undo merges, or permanently purge sets you already moved aside.', 'lucidlab-mpc-cleanup' ),
			'backup'       => __( 'Copy the database, uploads, or full site before large cleanup jobs.', 'lucidlab-mpc-cleanup' ),
			'options'      => __( 'Batch sizes, scan exclusions, email reports, Media Index and Pro automation.', 'lucidlab-mpc-cleanup' ),
			'logs'         => __( 'Diagnostics, job history, reclaimable-space snapshots and logging controls.', 'lucidlab-mpc-cleanup' ),
		);
	}

	/**
	 * Compact branded header for every admin tab except Getting Started.
	 *
	 * @param string $tab   Tab.
	 * @param string $label Label.
	 */
	private function render_page_hero( string $tab, string $label ): void {
		if ( 'welcome' === $tab ) {
			return;
		}
		$blurb = $this->tab_blurbs()[ $tab ] ?? '';
		?>
		<section class="lucidlab-mpc-page-hero lucidlab-mpc-page-hero--compact" aria-labelledby="lucidlab-mpc-page-hero-title-<?php echo esc_attr( $tab ); ?>">
			<div>
				<span class="lucidlab-mpc-welcome-kicker"><?php echo in_array( $tab, LucidLab_MPC_Edition::pro_tabs(), true ) ? esc_html__( 'Media & Products Cleanup Pro', 'lucidlab-mpc-cleanup' ) : esc_html__( 'Media & Products Cleanup', 'lucidlab-mpc-cleanup' ); ?></span>
				<h2 id="lucidlab-mpc-page-hero-title-<?php echo esc_attr( $tab ); ?>"><?php echo esc_html( $label ); ?></h2>
				<?php
				if ( $blurb ) :
					?>
					<p><?php echo esc_html( $blurb ); ?></p><?php endif; ?>
			</div>
			<div class="lucidlab-mpc-welcome-hero-icon" aria-hidden="true"><img class="lucidlab-mpc-welcome-hero-logo" src="<?php echo esc_url( $this->brand_logo_url() ); ?>" alt="" width="64" height="64" /></div>
		</section>
		<?php
	}

	/**
	 * Register menu.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Media & Products Cleanup', 'lucidlab-mpc-cleanup' ),
			__( 'Media & Products Cleanup', 'lucidlab-mpc-cleanup' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-images-alt2',
			10.1
		);
		remove_submenu_page( self::SLUG, self::SLUG );
		add_filter(
			'submenu_file',
			function ( $file ) {
				if ( sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ) === self::SLUG ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
					return 'admin.php?page=' . self::SLUG . '&tab=' . $this->current_tab();
				}
				return $file;
			}
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook Hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'lucidlab-mpc-admin', LUCIDLAB_MPC_URL . 'assets/css/admin.css', array(), LUCIDLAB_MPC_VERSION . '.' . filemtime( LUCIDLAB_MPC_DIR . 'assets/css/admin.css' ) );
		wp_enqueue_script( 'lucidlab-mpc-admin', LUCIDLAB_MPC_URL . 'assets/js/admin.js', array(), LUCIDLAB_MPC_VERSION . '.' . filemtime( LUCIDLAB_MPC_DIR . 'assets/js/admin.js' ), true );
		wp_localize_script(
			'lucidlab-mpc-admin',
			'LucidLabMPC',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'restUrl'         => rest_url( 'wp/v2/media/' ),
				'nonce'           => wp_create_nonce( self::NONCE ),
				'tab'             => $this->current_tab(),
				'pro'             => LucidLab_MPC_Edition::pro(),
				'quarBatch'       => LucidLab_MPC_Helpers::QUAR_BATCH,
				'aiMinConfidence' => (float) ( LucidLab_MPC_Settings::get( 'ai_min_confidence' ) ?? 0.65 ),
				'hasReportEmail'  => (bool) LucidLab_MPC_Helpers::parse_emails( LucidLab_MPC_Settings::get( 'report_recipient' ) ?? '' ),
				'optionsUrl'      => $this->tab_url( 'options' ),
				'tabUrls'         => array(
					'welcome'      => $this->tab_url( 'welcome' ),
					'guide'        => $this->tab_url( 'guide' ),
					'backup'       => $this->tab_url( 'backup' ),
					'options'      => $this->tab_url( 'options' ),
					'orphans'      => $this->tab_url( 'orphans' ),
					'uploads'      => $this->tab_url( 'uploads' ),
					'shared'       => $this->tab_url( 'shared' ),
					'missing'      => $this->tab_url( 'missing' ),
					'crawl'        => $this->tab_url( 'missing' ),
					'product_copy' => $this->tab_url( 'product_copy' ),
					'sizes'        => $this->tab_url( 'sizes' ),
					'dupcontent'   => $this->tab_url( 'dupcontent' ),
					'visual'       => $this->tab_url( 'visual' ),
					'duplicates'   => $this->tab_url( 'duplicates' ),
					'webp'         => $this->tab_url( 'webp' ),
					'dbcleaner'    => $this->tab_url( 'dbcleaner' ),
					'quarantine'   => $this->tab_url( 'quarantine' ),
					'regenerate'   => $this->tab_url( 'regenerate' ),
					'logs'         => $this->tab_url( 'logs' ),
				),
				'messages'        => require LUCIDLAB_MPC_DIR . 'includes/admin-translations.php',
				'locale'          => str_replace( '_', '-', get_user_locale() ),
				'decimalPoint'    => ( isset( $GLOBALS['wp_locale'] ) && is_object( $GLOBALS['wp_locale'] ) )
					? (string) ( $GLOBALS['wp_locale']->number_format['decimal_point'] ?? '.' )
					: '.',
				'i18n'            => array(
					'enableFirst'                => __( 'Enable the deletion toggle first.', 'lucidlab-mpc-cleanup' ),
					'confirmDup'                 => __( 'Trash the selected duplicate products (and cascade their variations)? Recoverable from Products → Trash.', 'lucidlab-mpc-cleanup' ),
					'confirmQuar'                => __( 'Move the selected items to quarantine? They stay fully restorable.', 'lucidlab-mpc-cleanup' ),
					'confirmDbQuar'              => __( 'Move the selected database tables to quarantine? Structure and data are saved as SQL so you can restore them. They stay fully restorable until you purge.', 'lucidlab-mpc-cleanup' ),
					'confirmDbRowsQuar'          => __( 'Move the selected database records to quarantine? Rows are exported as JSON so you can restore them. They stay fully restorable until you purge.', 'lucidlab-mpc-cleanup' ),
					'confirmDbAdvancedQuar'      => __( 'Move higher-risk database records to quarantine? Review samples carefully. A restorable dump is saved first, but revisions, cron patches and autoload options can affect the site until restored.', 'lucidlab-mpc-cleanup' ),
					'confirmDbAdvancedSelectAll' => __( 'Select all higher-risk Database Cleaner items? Review each group carefully before quarantining.', 'lucidlab-mpc-cleanup' ),
					/* translators: 1: selected reclaimable size, 2: total reclaimable size. */
					'dbSizeSelectedAll'          => __( 'Selected: %1$s · All: %2$s', 'lucidlab-mpc-cleanup' ),
					'confirmImg'                 => __( 'Quarantine this image? Products using it will lose it until you restore it. Fully reversible.', 'lucidlab-mpc-cleanup' ),
					'confirmMerge'               => __( 'Merge each group into its "Keep" image? References are repointed and the duplicates are moved to the restorable quarantine.', 'lucidlab-mpc-cleanup' ),
					'previewNote'                => __( 'This is a preview. Nothing has been moved yet.', 'lucidlab-mpc-cleanup' ),
					/* translators: %d is the number of additional preview items. */
					'previewMore'                => __( 'and %d more', 'lucidlab-mpc-cleanup' ),
					'scanning'                   => __( 'Scanning…', 'lucidlab-mpc-cleanup' ),
					'quarantining'               => __( 'Quarantining…', 'lucidlab-mpc-cleanup' ),
					'purging'                    => __( 'Purging… Please keep this page open until the action finishes.', 'lucidlab-mpc-cleanup' ),
					'restoring'                  => __( 'Restoring set… Please keep this page open until the action finishes.', 'lucidlab-mpc-cleanup' ),
					'restoringItem'              => __( 'Restoring item… Please keep this page open until the action finishes.', 'lucidlab-mpc-cleanup' ),
					'undoingMerge'               => __( 'Restoring duplicate images and their references… Please keep this page open until the action finishes.', 'lucidlab-mpc-cleanup' ),
					'cleaningEmpty'              => __( 'Removing empty folders… Please keep this page open until the action finishes.', 'lucidlab-mpc-cleanup' ),
					'merging'                    => __( 'Merging…', 'lucidlab-mpc-cleanup' ),
					'trashing'                   => __( 'Trashing…', 'lucidlab-mpc-cleanup' ),
					'done'                       => __( 'Done — reloading…', 'lucidlab-mpc-cleanup' ),
					'error'                      => __( 'Something went wrong — try the standard action.', 'lucidlab-mpc-cleanup' ),
					'nothing'                    => __( 'Nothing selected.', 'lucidlab-mpc-cleanup' ),
					'retryFailed'                => __( 'Retry failed', 'lucidlab-mpc-cleanup' ),
					'retrying'                   => __( 'Retrying failed items…', 'lucidlab-mpc-cleanup' ),
					'viewCurrentResults'         => __( 'View current results', 'lucidlab-mpc-cleanup' ),
					'completedOn'                => __( 'Completed on:', 'lucidlab-mpc-cleanup' ),
					'completedOnPrefix'          => __( 'Completed on', 'lucidlab-mpc-cleanup' ),
					'jobStale'                   => __( 'No saved progress for over 10 minutes. Check WP-Cron and server errors in Logs / Diagnostics.', 'lucidlab-mpc-cleanup' ),
					'pausedResume'               => __( 'Paused; Resume to continue', 'lucidlab-mpc-cleanup' ),
					'lastUpdate'                 => __( 'Last update:', 'lucidlab-mpc-cleanup' ),
					'cancelledOnPrefix'          => __( 'Cancelled on', 'lucidlab-mpc-cleanup' ),
					'failedOnPrefix'             => __( 'Failed on', 'lucidlab-mpc-cleanup' ),
					'currentlyRunning'           => __( 'Currently running', 'lucidlab-mpc-cleanup' ),
					'paused'                     => __( 'Paused', 'lucidlab-mpc-cleanup' ),
					'stop'                       => __( 'Stop', 'lucidlab-mpc-cleanup' ),
					'resume'                     => __( 'Resume', 'lucidlab-mpc-cleanup' ),
					'skip'                       => __( 'Skip', 'lucidlab-mpc-cleanup' ),
					'cancel'                     => __( 'Cancel', 'lucidlab-mpc-cleanup' ),
					'continueBackground'         => __( 'Continue in background', 'lucidlab-mpc-cleanup' ),
					'noSavedResults'             => __( 'No saved result list is available. Run a new scan to refresh the findings; the counters may show an older snapshot.', 'lucidlab-mpc-cleanup' ),
					'noSavedResultsShort'        => __( 'No saved results. Run a new scan.', 'lucidlab-mpc-cleanup' ),
					'scanOrphans'                => __( 'Scan orphan images', 'lucidlab-mpc-cleanup' ),
					'analyzeSizes'               => __( 'Analyze sizes', 'lucidlab-mpc-cleanup' ),
					'scanDuplicates'             => __( 'Scan duplicate products', 'lucidlab-mpc-cleanup' ),
					'scanUploads'                => __( 'Scan uploads', 'lucidlab-mpc-cleanup' ),
					'scanDatabase'               => __( 'Scan database', 'lucidlab-mpc-cleanup' ),
					'scanMissing'                => __( 'Scan missing images', 'lucidlab-mpc-cleanup' ),
					'checkLivePages'             => __( 'Check live pages', 'lucidlab-mpc-cleanup' ),
					'open'                       => __( 'Open', 'lucidlab-mpc-cleanup' ),
					'edit'                       => __( 'Edit', 'lucidlab-mpc-cleanup' ),
					'editMedia'                  => __( 'Edit media', 'lucidlab-mpc-cleanup' ),
					'crawlFoundIn'               => __( 'Found in stylesheet:', 'lucidlab-mpc-cleanup' ),
					'crawlOtherSite'             => __( 'Other site', 'lucidlab-mpc-cleanup' ),
					'scanProducts'               => __( 'Scan products', 'lucidlab-mpc-cleanup' ),
					'findShared'                 => __( 'Find shared images', 'lucidlab-mpc-cleanup' ),
					'findByteIdentical'          => __( 'Find byte-identical images', 'lucidlab-mpc-cleanup' ),
					'findVisual'                 => __( 'Find visually identical images', 'lucidlab-mpc-cleanup' ),
					'scanOtherSizes'             => __( 'Scan other sizes', 'lucidlab-mpc-cleanup' ),
					'reviewThisTab'              => __( 'Review this tab', 'lucidlab-mpc-cleanup' ),
					'openTab'                    => __( 'Open', 'lucidlab-mpc-cleanup' ),
					'runAgain'                   => __( 'Run again', 'lucidlab-mpc-cleanup' ),
					'at'                         => __( 'at', 'lucidlab-mpc-cleanup' ),
					'dbShrinkEmpty'              => __( 'Nothing to shrink right now — no finished Action Scheduler jobs, orphan logs, or reclaimable free space.', 'lucidlab-mpc-cleanup' ),
					'dbShrinkRun'                => __( 'Run', 'lucidlab-mpc-cleanup' ),
					'dbShrinkTotal'              => __( 'Estimated savings', 'lucidlab-mpc-cleanup' ),
					'dbShrinkConfirmAs'          => __( 'Quarantine and remove all finished Action Scheduler jobs (complete / failed / canceled)? Pending and in-progress jobs are kept. Rows are saved in restorable quarantine chunks first.', 'lucidlab-mpc-cleanup' ),
					'dbShrinkConfirmOrphans'     => __( 'Quarantine and remove orphan Action Scheduler log rows with no matching action? Rows are saved in restorable quarantine first.', 'lucidlab-mpc-cleanup' ),
					'dbShrinkOptimized'          => __( 'OPTIMIZE finished.', 'lucidlab-mpc-cleanup' ),
					'dbBackupConfirm'            => __( 'Create a SQL dump of this site\'s database? The file is stored with other backups and listed on the Backup page.', 'lucidlab-mpc-cleanup' ),
					/* translators: %s is the filename of the completed backup. */
					'dbBackupMade'               => __( 'Backup made: %s', 'lucidlab-mpc-cleanup' ),
				),
				'webpSettings'    => $this->webp_replay_settings(),
			)
		);
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	private function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'welcome'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
		// Other Sizes on Disk is a section of Image Sizes, not its own screen.
		if ( 'fs-sizes' === $tab ) {
			$tab = 'sizes';
		}
		return in_array( $tab, LucidLab_MPC_Edition::known_tabs(), true ) ? $tab : 'welcome';
	}

	/**
	 * Filter disabled sizes.
	 *
	 * @param array $sizes Sizes.
	 *
	 * @return array
	 */
	public function filter_disabled_sizes( array $sizes ): array {
		return LucidLab_MPC_Hooks::disabled_sizes( $sizes );
	}

	/**
	 * Tab url.
	 *
	 * @param string $tab Tab.
	 *
	 * @return string
	 */
	private function tab_url( string $tab ): string {
		if ( 'fs-sizes' === $tab ) {
			$tab = 'sizes';
		}
		return admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab );
	}

	/* ---------- transient keys ---------- */

	/**
	 * Uid.
	 *
	 * @return int
	 */
	private function uid(): int {
		return get_current_user_id();
	}
	/**
	 * K state.
	 *
	 * @param string $tab Tab.
	 *
	 * @return string
	 */
	private function k_state( string $tab ): string {
		return 'lucidlab_mpc_state_' . $tab . '_' . $this->uid();
	}
	/**
	 * K ref.
	 *
	 * @return string
	 */
	private function k_ref(): string {
		return 'lucidlab_mpc_ref_' . $this->uid();
	}
	/**
	 * K reg.
	 *
	 * @return string
	 */
	private function k_reg(): string {
		return 'lucidlab_mpc_reg_' . $this->uid();
	}
	/**
	 * K result.
	 *
	 * @param string $tab Tab.
	 *
	 * @return string
	 */
	private function k_result( string $tab ): string {
		// Site-wide (owner 0) so every admin and background job sees the same saved list.
		$name = 'lucidlab_mpc_result_' . $tab . '_0';
		self::store_large_transient_in_file( $name, true );
		return $name;
	}

	/**
	 * A large transient (the Uploads Scanner result lists every file) can exceed the database packet limit, which silently drops the row. Values over 256 KB are kept in a work file; the transient keeps the scalar counters and the file name, so readers still get the full array.
	 *
	 * Saved scan results ($always) always go to the file, carry RESULT_FORMAT, and are dropped when the format changes.
	 *
	 * @param string $name   Name.
	 * @param bool   $always Always use the file and check the result format.
	 */
	private static function store_large_transient_in_file( string $name, bool $always = false ): void {
		static $hooked = array();
		if ( isset( $hooked[ $name ] ) ) {
			return;
		}
		$hooked[ $name ] = true;
		$file            = $name . '.result';
		add_filter(
			'pre_set_transient_' . $name,
			static function ( $value ) use ( $file, $always ) {
				if ( ! is_array( $value ) ) {
					return $value;
				}
				if ( ( $value['__lucidlab_mpc_work'] ?? '' ) === $file ) {
					return $value;
				}
				$raw = serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Byte-exact copy of a stored option; unserialize disallows classes.
				if ( ! $always && strlen( $raw ) < 256 * KB_IN_BYTES ) {
					LucidLab_MPC_Work::delete( $file );
					return $value;
				}
				$path = LucidLab_MPC_Work::path( $file );
				$tmp  = $path . '.tmp';
				if ( false === file_put_contents( $tmp, $raw, LOCK_EX ) || ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
					wp_delete_file( $tmp );
					return $value;
				}
				$marker                          = array_filter( $value, 'is_scalar' );
				$marker['__lucidlab_mpc_work']   = $file;
				$marker['__lucidlab_mpc_format'] = self::RESULT_FORMAT;
				return $marker;
			}
		);
		add_filter(
			'transient_' . $name,
			static function ( $value ) use ( $file, $always ) {
				if ( $always && is_array( $value ) && (int) ( $value['__lucidlab_mpc_format'] ?? 0 ) !== self::RESULT_FORMAT ) {
					return false;
				}
				if ( ! is_array( $value ) || ( $value['__lucidlab_mpc_work'] ?? '' ) !== $file ) {
					return $value;
				}
				$raw  = ( is_readable( LucidLab_MPC_Work::path( $file ) ) ? file_get_contents( LucidLab_MPC_Work::path( $file ) ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				$data = is_string( $raw ) ? ( is_serialized( $raw ) ? unserialize( $raw, array( 'allowed_classes' => false ) ) : false ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Byte-exact copy of a stored option; unserialize disallows classes.
				return is_array( $data ) ? $data : false;
			}
		);
		add_action(
			'delete_transient_' . $name,
			static function () use ( $file ) {
				LucidLab_MPC_Work::delete( $file );
			}
		);
	}
	/**
	 * Get scan result.
	 *
	 * @param string $tab Tab.
	 */
	public function get_scan_result( string $tab ) {
		$tab  = sanitize_key( $tab );
		$data = get_transient( $this->k_result( $tab ) );
		if ( ! is_array( $data ) ) {
			return $data;
		}
		if ( 'uploads' === $tab && empty( $data['orphan_units'] ) ) {
			$data['orphan_units'] = LucidLab_MPC_Uploads::load_result_units( $this->uid() );
			if ( $data['orphan_units'] ) {
				$data = LucidLab_MPC_Uploads::filter_support_files( $data );
			}
		}
		if ( 'shared' === $tab && isset( $data['images'] ) && is_array( $data['images'] ) ) {
			$this->set_scan_result( 'shared', $data, 0, true );
			$saved = get_transient( $this->k_result( 'shared' ) );
			if ( is_array( $saved ) ) {
				$data = $saved;
			}
		}
		return $data;
	}

	/**
	 * Set scan result.
	 *
	 * @param string $tab              Tab.
	 * @param array  $result           Result.
	 * @param int    $ttl              Ttl.
	 * @param bool   $preserve_updated When true (e.g. after quarantine prune), keep the scan Completed-on stamp.
	 */
	private function set_scan_result( string $tab, array $result, int $ttl = 0, bool $preserve_updated = false ): void {
		if ( 'uploads' === $tab ) {
			unset( $result['orphan_units'] );
			$result['orphan_units_file'] = 1;
		}
		if ( 'shared' === $tab && isset( $result['images'] ) && is_array( $result['images'] ) ) {
			$result = $this->detach_shared_products( $result );
			if ( ! isset( $result['image_count'] ) ) {
				$result['image_count'] = count( $result['images'] );
			}
			if ( ! $this->shared_result_has_products( $result ) && $this->shared_write_images( $result['images'] ) ) {
				unset( $result['images'] );
				$result['images_file'] = 1;
			}
		}
		// Capture before set_transient: the set_transient hook rewrites lucidlab_mpc_summary_* with time().
		$preserved_updated = 0;
		if ( $preserve_updated ) {
			$prev = get_option( 'lucidlab_mpc_summary_' . $tab, array() );
			if ( is_array( $prev ) && ! empty( $prev['updated'] ) ) {
				$preserved_updated = (int) $prev['updated'];
			}
		}
		set_transient( $this->k_result( $tab ), $result, $ttl );
		// Always refresh the summary so Completed on / snapshot counters work even when
		// the transient is file-backed or the set_transient listener is skipped.
		$counts = array();
		foreach ( $result as $key => $value ) {
			if ( is_int( $value ) || is_float( $value ) ) {
				$counts[ $key ] = $value;
			}
		}
		if ( isset( $result['images'] ) && is_array( $result['images'] ) ) {
			if ( 'missing' === $tab && ! isset( $counts['missing'] ) ) {
				$counts['missing'] = count( $result['images'] );
			}
			if ( 'shared' === $tab && ! isset( $counts['image_count'] ) ) {
				$counts['image_count'] = count( $result['images'] );
			}
		}
		if ( 'missing' === $tab && isset( $result['urls'] ) && is_array( $result['urls'] ) && ! isset( $counts['broken_urls'] ) ) {
			$counts['broken_urls'] = count( $result['urls'] );
		}
		if ( isset( $result['groups'] ) && is_array( $result['groups'] ) && ! isset( $counts['group_count'] ) && ! isset( $counts['candidate_groups'] ) ) {
			$counts[ 'visual' === $tab ? 'candidate_groups' : 'group_count' ] = count( $result['groups'] );
		}
		if ( ! empty( $result['outcome'] ) && is_string( $result['outcome'] ) ) {
			$counts['outcome'] = $result['outcome'];
		}
		$updated = $preserved_updated > 0 ? $preserved_updated : time();
		update_option(
			'lucidlab_mpc_summary_' . $tab,
			array(
				'updated' => $updated,
				'counts'  => $counts,
			),
			false
		);
		if ( ! $preserve_updated ) {
			$this->clear_scan_stale( $tab );
		}
		// Scan responses often contain only progress flags; the saved result is the source
		// of truth for reclaimable totals and the daily storage snapshot.
		if ( 'orphans' === $tab ) {
			LucidLab_MPC_Stats::set( 'orphans', (int) ( $result['reclaim_bytes'] ?? 0 ), (int) ( $result['orphan_count'] ?? 0 ) );
		} elseif ( 'uploads' === $tab ) {
			LucidLab_MPC_Stats::set( 'uploads', (int) ( $result['orphan_bytes'] ?? 0 ), (int) ( $result['orphan_files'] ?? 0 ) );
		} elseif ( 'visual' === $tab ) {
			LucidLab_MPC_Stats::set( 'visual', (int) ( $result['reclaim_bytes'] ?? 0 ), (int) ( $result['candidate_groups'] ?? 0 ) );
		} elseif ( 'dbcleaner' === $tab ) {
			$db_counts = is_array( $result['counts'] ?? null ) ? $result['counts'] : array();
			LucidLab_MPC_Stats::set(
				'dbcleaner',
				(int) ( $db_counts['bytes'] ?? 0 ),
				(int) ( $db_counts['total'] ?? 0 ) + (int) ( $db_counts['orphan_records'] ?? 0 )
			);
		}
	}

	/**
	 * Delete scan result.
	 *
	 * @param string $tab Tab.
	 */
	private function delete_scan_result( string $tab ): void {
		delete_transient( $this->k_result( $tab ) );
		if ( 'uploads' === $tab ) {
			LucidLab_MPC_Uploads::clear_result_file( $this->uid() );
		}
		if ( 'shared' === $tab ) {
			LucidLab_MPC_Work::delete( $this->shared_usage_file(), $this->shared_usage_lines_file(), $this->shared_images_file() );
		}
	}

	/**
	 * Shared usage file.
	 *
	 * @return string
	 */
	private function shared_usage_file(): string {
		return 'shared-usage-' . $this->uid() . '.json';
	}

	/**
	 * Shared usage lines file.
	 *
	 * @return string
	 */
	private function shared_usage_lines_file(): string {
		return 'shared-usage-' . $this->uid() . '.jsonl';
	}

	/**
	 * Shared images file.
	 *
	 * @return string
	 */
	private function shared_images_file(): string {
		return 'shared-images-' . $this->uid() . '.jsonl';
	}

	/**
	 * The results page reads one page from this file. The saved option keeps the counts only.
	 *
	 * @param array<int,array<string,mixed>> $images Images.
	 *
	 * @return bool
	 */
	private function shared_write_images( array $images ): bool {
		$path   = LucidLab_MPC_Work::path( $this->shared_images_file() );
		$handle = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			return false;
		}
		foreach ( $images as $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}
			$line = wp_json_encode(
				array(
					'id'    => (int) ( $image['id'] ?? 0 ),
					'title' => (string) ( $image['title'] ?? '' ),
					'count' => (int) ( $image['count'] ?? 0 ),
				)
			);
			if ( ! is_string( $line ) || fwrite( $handle, $line . "\n" ) !== strlen( $line ) + 1 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				wp_delete_file( $path );
				return false;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		return true;
	}

	/**
	 * Shared read page.
	 *
	 * @param int $page Page.
	 * @param int $per  Per.
	 *
	 * @return array{items:array<int,array<string,mixed>>,page:int,pages:int,total:int}
	 */
	private function shared_read_page( int $page, int $per = 50 ): array {
		$empty = array(
			'items' => array(),
			'page'  => 1,
			'pages' => 1,
			'total' => 0,
		);
		$path  = LucidLab_MPC_Work::path( $this->shared_images_file() );
		if ( ! is_file( $path ) ) {
			return $empty;
		}
		$handle = ( is_readable( $path ) ? fopen( $path, 'rb' ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			return $empty;
		}
		$total = 0;
		while ( false !== fgets( $handle ) ) {
			++$total;
		}
		$pages = max( 1, (int) ceil( $total / $per ) );
		$page  = min( $pages, max( 1, $page ) );
		rewind( $handle );
		$index = 0;
		$start = ( $page - 1 ) * $per;
		$items = array();
		while ( true ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			if ( $index++ < $start ) {
				continue;
			}
			if ( count( $items ) >= $per ) {
				break;
			}
			$row = json_decode( trim( $line ), true );
			if ( is_array( $row ) ) {
				$items[] = $row;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		return array(
			'items' => $items,
			'page'  => $page,
			'pages' => $pages,
			'total' => $total,
		);
	}

	/**
	 * Shared forget ids.
	 *
	 * @param array<string,bool> $gone      Gone.
	 * @param bool               $clear_all Clear all.
	 *
	 * @return int
	 */
	private function shared_forget_ids( array $gone, bool $clear_all ): int {
		if ( $clear_all ) {
			LucidLab_MPC_Work::delete( $this->shared_images_file(), $this->shared_usage_file(), $this->shared_usage_lines_file() );
			return 0;
		}
		$path = LucidLab_MPC_Work::path( $this->shared_images_file() );
		if ( ! is_file( $path ) ) {
			return 0;
		}
		$tmp = $path . '.tmp';
		$in  = ( is_readable( $path ) ? fopen( $path, 'rb' ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		$out = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $in || ! $out ) {
			if ( $in ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			}
			if ( $out ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			}
			return -1;
		}
		$left = 0;
		while ( true ) {
			$line = fgets( $in );
			if ( false === $line ) {
				break;
			}
			$row = json_decode( trim( $line ), true );
			$id  = (string) ( is_array( $row ) ? ( $row['id'] ?? '' ) : '' );
			if ( '' === $id || isset( $gone[ $id ] ) ) {
				continue;
			}
			if ( fwrite( $out, trim( $line ) . "\n" ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				wp_delete_file( $tmp );
				return -1;
			}
			++$left;
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
		if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			wp_delete_file( $tmp );
			return -1;
		}
		return $left;
	}

	/**
	 * Thumbnail and edit links are built for the visible page, not stored for every shared image.
	 *
	 * @param array<string,mixed> $img Img.
	 *
	 * @return array<string,mixed>
	 */
	private function shared_row_links( array $img ): array {
		$id = (int) ( $img['id'] ?? 0 );
		if ( $id < 1 ) {
			return $img;
		}
		if ( empty( $img['thumb'] ) ) {
			$img['thumb'] = (string) wp_get_attachment_image_url( $id, array( 120, 120 ) );
		}
		if ( empty( $img['full'] ) ) {
			$img['full'] = (string) wp_get_attachment_url( $id );
		}
		if ( empty( $img['edit'] ) ) {
			$img['edit'] = (string) get_edit_post_link( $id, 'raw' );
		}
		if ( '' === (string) ( $img['title'] ?? '' ) ) {
			$img['title'] = LucidLab_MPC_Helpers::either( get_the_title( $id ), ( '#' . $id ) );
		}
		return $img;
	}

	/**
	 * Shared result has products.
	 *
	 * @param array $result Result.
	 *
	 * @return bool
	 */
	private function shared_result_has_products( array $result ): bool {
		foreach ( (array) ( $result['images'] ?? array() ) as $image ) {
			if ( is_array( $image ) && ! empty( $image['products'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Keep the shared-image list small enough to render. Product rows load when a row is expanded.
	 *
	 * @param array<string,mixed> $result Result.
	 *
	 * @return array<string,mixed>
	 */
	private function detach_shared_products( array $result ): array {
		$usage = array();
		$dirty = false;
		foreach ( (array) ( $result['images'] ?? array() ) as $image ) {
			if ( ! is_array( $image ) || empty( $image['products'] ) ) {
				continue;
			}
			$id = (int) ( $image['id'] ?? 0 );
			if ( $id > 0 ) {
				$usage[ (string) $id ] = array_values( (array) $image['products'] );
			}
			$dirty = true;
		}
		if ( ! $dirty || ! $this->shared_write_usage( $usage ) ) {
			return $result;
		}
		foreach ( $result['images'] as $i => $image ) {
			if ( is_array( $image ) ) {
				unset( $result['images'][ $i ]['products'] );
			}
		}
		return $result;
	}

	/**
	 * Task result exists.
	 *
	 * @param string $tab Tab.
	 *
	 * @return bool
	 */
	public function task_result_exists( string $tab ): bool {
		$tab = sanitize_key( $tab );
		if ( 'uploads' === $tab ) {
			$data = get_transient( $this->k_result( $tab ) );
			return is_array( $data ) && ( empty( $data['orphan_files'] ) || ! empty( $data['orphan_units'] ) || ! empty( $data['orphan_units_file'] ) );
		}
		// Prefer a lightweight options probe, then fall back for object-cache hosts
		// that never write `_transient_*` rows to the database.
		$name = $this->k_result( $tab );
		global $wpdb;
		$timeout = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", '_transient_timeout_' . $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		if ( null !== $timeout && '' !== (string) $timeout && (int) $timeout < time() ) {
			return false;
		}
		$marker = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", '_transient_' . $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		if ( null !== $marker ) {
			// A list only counts when it has the current format and its work file is still there.
			$marker = maybe_unserialize( $marker );
			return is_array( $marker )
				&& (int) ( $marker['__lucidlab_mpc_format'] ?? 0 ) === self::RESULT_FORMAT
				&& LucidLab_MPC_Work::exists( (string) ( $marker['__lucidlab_mpc_work'] ?? '' ) );
		}
		$probe = get_transient( $name );
		return is_array( $probe );
	}

	/**
	 * Whether View current results should show — only when a loadable saved list exists.
	 *
	 * @param string $tab Tab.
	 *
	 * @return bool
	 */
	protected function can_view_saved_results( string $tab ): bool {
		return $this->task_result_exists( sanitize_key( $tab ) );
	}

	/**
	 * Link that fetches the saved list into the panel (spinner via .lucidlab-mpc-current-results-link).
	 *
	 * @param string $tab           Tab.
	 * @param string $loading_label Loading label.
	 */
	protected function render_view_saved_results( string $tab, string $loading_label = '' ): void {
		if ( ! $this->can_view_saved_results( $tab ) ) {
			return;
		}
		if ( '' === $loading_label ) {
			$loading_label = __( 'Loading results…', 'lucidlab-mpc-cleanup' );
		}
		printf(
			'<a class="button lucidlab-mpc-current-results-link" data-mpc-saved-results="1" data-mpc-loading-text="%1$s" href="%2$s">%3$s</a>',
			esc_attr( $loading_label ),
			esc_url( $this->tab_url( $tab ) . '&lucidlab_mpc_load=1&lucidlab_mpc_scanned=1' ),
			esc_html__( 'View current results', 'lucidlab-mpc-cleanup' )
		);
	}

	/**
	 * Open the overview Lucid Lab card (status, counters, primary actions).
	 *
	 * @param bool $dbcleaner Dbcleaner.
	 */
	protected function begin_overview_card( bool $dbcleaner = false ): void {
		$this->end_overview_card();
		if ( $dbcleaner ) {
			echo '<section class="lucidlab-mpc-card lucidlab-mpc-card--overview" data-mpc-card-overview data-mpc-db-overview>';
		} else {
			echo '<section class="lucidlab-mpc-card lucidlab-mpc-card--overview" data-mpc-card-overview>';
		}
		$this->lucidlab_mpc_card_overview_open = true;
	}

	/**
	 * Close the overview card when still open.
	 */
	protected function end_overview_card(): void {
		if ( $this->lucidlab_mpc_card_overview_open ) {
			echo '</section>';
			$this->lucidlab_mpc_card_overview_open = false;
		}
	}

	/**
	 * Close overview (if open) and open the results/content Lucid Lab card.
	 *
	 * @param string $extra_attrs Extra attrs.
	 */
	protected function begin_results_card( string $extra_attrs = '' ): void {
		$this->end_overview_card();
		$this->end_results_card();
		echo '<section class="lucidlab-mpc-card lucidlab-mpc-card--results"';
		if ( preg_match_all( '/\s+(data-[a-z0-9-]+|id)="([^"]*)"/', $extra_attrs, $attributes, PREG_SET_ORDER ) ) {
			foreach ( $attributes as $attribute ) {
				echo ' ' . esc_attr( $attribute[1] ) . '="' . esc_attr( html_entity_decode( $attribute[2], ENT_QUOTES, 'UTF-8' ) ) . '"';
			}
		}
		echo '>';
		$this->lucidlab_mpc_card_results_open = true;
	}

	/**
	 * Close the results card when still open.
	 */
	protected function end_results_card(): void {
		if ( $this->lucidlab_mpc_card_results_open ) {
			echo '</section>';
			$this->lucidlab_mpc_card_results_open = false;
		}
	}

	/**
	 * Close any open Lucid Lab cards for the active panel.
	 */
	protected function end_panel_cards(): void {
		$this->end_overview_card();
		$this->end_results_card();
	}

	/**
	 * A restore put files back, so this scan's saved result must not be treated as current.
	 *
	 * @param string $tab Tab.
	 */
	private function mark_scan_stale( string $tab ): void {
		$tab   = sanitize_key( $tab );
		$stale = get_option( 'lucidlab_mpc_scan_stale', array() );
		if ( ! is_array( $stale ) ) {
			$stale = array();
		}
		$stale[ $tab ] = time();
		update_option( 'lucidlab_mpc_scan_stale', $stale, false );
	}

	/**
	 * Clear scan stale.
	 *
	 * @param string $tab Tab.
	 */
	private function clear_scan_stale( string $tab ): void {
		$stale = get_option( 'lucidlab_mpc_scan_stale', array() );
		if ( ! is_array( $stale ) || ! isset( $stale[ $tab ] ) ) {
			return;
		}
		unset( $stale[ $tab ] );
		update_option( 'lucidlab_mpc_scan_stale', $stale, false );
	}

	/**
	 * Scan is stale.
	 *
	 * @param string $tab Tab.
	 *
	 * @return bool
	 */
	private function scan_is_stale( string $tab ): bool {
		$stale = get_option( 'lucidlab_mpc_scan_stale', array() );
		return is_array( $stale ) && ! empty( $stale[ sanitize_key( $tab ) ] );
	}

	/**
	 * True when the last scan finished cleanly with zero findings for this tab.
	 *
	 * @param string $tab    Tab.
	 * @param array  $counts Counts.
	 *
	 * @return bool
	 */
	private function summary_is_clean_scan( string $tab, array $counts ): bool {
		if ( 'missing' === $tab ) {
			if ( ! array_key_exists( 'missing', $counts ) && ! array_key_exists( 'broken_urls', $counts ) ) {
				return false;
			}
			$missing = array_key_exists( 'missing', $counts ) ? (int) $counts['missing'] : 0;
			$broken  = array_key_exists( 'broken_urls', $counts ) ? (int) $counts['broken_urls'] : 0;
			return 0 === $missing && 0 === $broken;
		}
		$map  = array(
			'orphans'      => array( 'orphan_count', 'orphans' ),
			'shared'       => array( 'image_count', 'images' ),
			'dupcontent'   => array( 'group_count', 'groups', 'dupe_count' ),
			'duplicates'   => array( 'group_count', 'groups', 'dupe_count' ),
			'visual'       => array( 'candidate_groups', 'groups' ),
			'uploads'      => array( 'orphan_files' ),
			'product_copy' => array( 'products_missing' ),
			'dbcleaner'    => array( 'total', 'orphan_records', 'bytes', 'rows', 'record_bytes', 'record_rows', 'optimize_bytes', 'inactive_plugins', 'missing_plugins' ),
		);
		$keys = $map[ $tab ] ?? array();
		if ( 'dbcleaner' === $tab ) {
			$seen = false;
			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $counts ) ) {
					continue;
				}
				$seen = true;
				if ( (int) $counts[ $key ] > 0 ) {
					return false;
				}
			}
			return $seen;
		}
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $counts ) ) {
				return 0 === (int) $counts[ $key ];
			}
		}
		return false;
	}

	/**
	 * Whether this request should render the full saved list (images, groups, merge controls).
	 *
	 * @param string $tab Tab.
	 *
	 * @return bool
	 */
	protected function results_list_requested( string $tab ): bool {
		if ( $this->current_tab() !== $tab ) {
			return false;
		}
		return ! empty( $_GET['lucidlab_mpc_load'] ) || ! empty( $_GET['lucidlab_mpc_scanned'] ) || ! empty( $_GET['lucidlab_mpc_shared_page'] ) || ! empty( $_GET['visual_page'] ) || ! empty( $_GET['lucidlab_mpc_uploads_page'] ) || ! empty( $_GET['lucidlab_mpc_orphans_page'] ) || ! empty( $_GET['lucidlab_mpc_dupc_page'] ) || ! empty( $_GET['lucidlab_mpc_missing_page'] ) || ! empty( $_GET['lucidlab_mpc_missing_urls_page'] ) || ! empty( $_GET['lucidlab_mpc_copy_page'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
	}

	/**
	 * Results page.
	 *
	 * @param array  $items Items.
	 * @param string $arg   Arg.
	 *
	 * @return array
	 */
	protected function results_page( array $items, string $arg ): array {
		$per   = 50;
		$total = count( $items );
		$pages = max( 1, (int) ceil( $total / $per ) );
		$page  = min( $pages, max( 1, absint( $_GET[ $arg ] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
		return array(
			'items' => array_slice( $items, ( $page - 1 ) * $per, $per, true ),
			'page'  => $page,
			'pages' => $pages,
			'total' => $total,
			'per'   => $per,
		);
	}

	/**
	 * Render results pager.
	 *
	 * @param array  $p    P.
	 * @param string $tab  Tab.
	 * @param string $arg  Arg.
	 * @param string $noun Noun.
	 */
	protected function render_results_pager( array $p, string $tab, string $arg, string $noun ): void {
		if ( (int) ( $p['pages'] ?? 1 ) < 2 ) {
			return;
		}
		/* translators: 1: items shown per page, 2: name of those items. */
		echo '<p>' . esc_html( sprintf( __( 'Showing %1$d %2$s per page. Selection applies to this page.', 'lucidlab-mpc-cleanup' ), (int) $p['per'], $noun ) ) . '</p><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => $this->tab_url( $tab ) . '&lucidlab_mpc_scanned=1&' . $arg . '=%#%',
					'format'  => '',
					'current' => (int) $p['page'],
					'total'   => (int) $p['pages'],
				)
			)
		);
		echo '</div>';
	}
	/**
	 * K quar.
	 *
	 * @return string
	 */
	private function k_quar(): string {
		return 'lucidlab_mpc_quar_' . $this->uid();
	}
	/**
	 * K trash.
	 *
	 * @return string
	 */
	private function k_trash(): string {
		return 'lucidlab_mpc_trash_' . $this->uid();
	}

	// Page render + synchronous POST ops.

	/**
	 * Render page.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this tool.', 'lucidlab-mpc-cleanup' ) );
		}
		if ( isset( $_GET['lucidlab_mpc_dismiss_pro_inactive'] ) ) {
			check_admin_referer( 'lucidlab_mpc_dismiss_pro_inactive' );
			$user_id = get_current_user_id();
			if ( $user_id ) {
				update_user_meta( $user_id, 'lucidlab_mpc_hide_pro_inactive_notice', 1 );
			}
			wp_safe_redirect( remove_query_arg( array( 'lucidlab_mpc_dismiss_pro_inactive', '_wpnonce' ) ) );
			exit;
		}

		$tab     = $this->current_tab();
		$notices = array();
		$result  = null;

		// Results produced by the AJAX scan.
		$result_tabs = array( 'orphans', 'uploads', 'sizes', 'dupcontent', 'duplicates', 'shared', 'visual', 'missing', 'dbcleaner' );
		if ( in_array( $tab, $result_tabs, true ) && ( ! empty( $_GET['lucidlab_mpc_scanned'] ) || ! empty( $_GET['lucidlab_mpc_load'] ) ) ) {
			$stored = $this->get_scan_result( $tab );
			if ( is_array( $stored ) ) {
				$result = $stored;
				if ( 'uploads' === $tab ) {
					$result = LucidLab_MPC_Uploads::filter_support_files( $result );
				}
				// Keep saved results available after a background job and on reload.
			}
		}
		// Notice after an AJAX quarantine / merge run.
		if ( ! empty( $_GET['lucidlab_mpc_quarantined'] ) ) {
			$acc = get_transient( $this->k_quar() );
			delete_transient( $this->k_quar() );
			$stamp = isset( $_GET['lucidlab_mpc_set'] ) ? sanitize_file_name( wp_unslash( (string) $_GET['lucidlab_mpc_set'] ) ) : '';
			if ( '' === $stamp && is_array( $acc ) ) {
				$stamp = (string) ( $acc['stamp'] ?? '' );
			}
			$ok   = is_array( $acc ) ? (int) ( $acc['ok'] ?? 0 ) : max( 0, ( isset( $_GET['lucidlab_mpc_ok'] ) ? (int) $_GET['lucidlab_mpc_ok'] : 0 ) );
			$fail = is_array( $acc ) ? (int) ( $acc['fail'] ?? 0 ) : max( 0, ( isset( $_GET['lucidlab_mpc_fail'] ) ? (int) $_GET['lucidlab_mpc_fail'] : 0 ) );
			if ( $stamp || $ok || $fail ) {
				/* translators: 1: items quarantined, 2: quarantine set name, 3: failed items. */
				$notices[] = array( 'success', sprintf( __( 'Quarantined %1$d item(s) to set %2$s. %3$d failed. Restore or purge them on the Quarantine tab.', 'lucidlab-mpc-cleanup' ), $ok, esc_html( $stamp ), $fail ) );
			}
		}
		if ( isset( $_GET['lucidlab_mpc_restored'] ) ) {
			$restored  = max( 0, (int) $_GET['lucidlab_mpc_restored'] );
			$notices[] = array(
				'success',
				sprintf(
					/* translators: %d: restored item count */
					_n( 'Restored %d item to its original location.', 'Restored %d items to their original locations.', $restored, 'lucidlab-mpc-cleanup' ),
					$restored
				),
			);
		}
		if ( ! empty( $_GET['lucidlab_mpc_undone'] ) ) {
			$msg       = isset( $_GET['lucidlab_mpc_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['lucidlab_mpc_msg'] ) ) : '';
			$notices[] = array( 'success', '' !== $msg ? $msg : __( 'Merge undone. Images were restored and references repointed.', 'lucidlab-mpc-cleanup' ) );
		}
		// Notice after an AJAX duplicate-trash run.
		if ( ! empty( $_GET['lucidlab_mpc_trashed'] ) ) {
			$acc = get_transient( $this->k_trash() );
			delete_transient( $this->k_trash() );
			if ( is_array( $acc ) ) {
				/* translators: 1: products moved to Trash, 2: variations moved to Trash. */
				$notices[] = array( 'success', sprintf( __( 'Trashed %1$d product(s) and %2$d variation(s). Recoverable from Products → Trash.', 'lucidlab-mpc-cleanup' ), (int) $acc['products'], (int) $acc['variations'] ) );
			}
		}

		$posted_op = isset( $_POST['lucidlab_mpc_op'] ) ? sanitize_key( wp_unslash( $_POST['lucidlab_mpc_op'] ) ) : '';
		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && '' !== $posted_op && ( 'welcome' !== $tab || 'dismiss_setup' === $posted_op ) ) {
			$op = $posted_op;
			if ( 'log_clear' === $op && isset( $_POST['lucidlab_mpc_log_nonce'] ) ) {
				check_admin_referer( 'lucidlab_mpc_op_log_clear', 'lucidlab_mpc_log_nonce' );
			} else {
				check_admin_referer( 'lucidlab_mpc_op_' . ( 'rebuild_index' === $op ? 'save_options' : $op ) );
			}
			$this->accept_request();
			try {
				LucidLab_MPC_Edition::check( $this->edition_params( array( 'op' => $op ) ) );
			} catch ( Throwable $e ) {
				$this->render_shell( $tab, null, array( array( 'error', $e->getMessage() ) ) );
				return;}

			switch ( $op ) {
				case 'rebuild_index':
					$idx       = LucidLab_MPC_Index::rebuild();
					$notices[] = array(
						'info',
						sprintf(
							/* translators: %d: image count */
							__( 'Media index rebuilt: %d image(s).', 'lucidlab-mpc-cleanup' ),
							(int) $idx['count']
						),
						'rebuild_index',
					);
					break;
				case 'dismiss_setup':
					update_option( 'lucidlab_mpc_setup_done', 1, false );
					delete_option( 'lucidlab_mpc_setup_pending' );
					$notices[] = array( 'success', __( 'Setup checklist dismissed. You can still rebuild the Media Index from Options.', 'lucidlab-mpc-cleanup' ) );
					break;
				case 'review_status':
						$raw_sources = isset( $this->req['review_source'] ) ? map_deep( wp_unslash( $this->req['review_source'] ), 'sanitize_key' ) : array();
					if ( ! is_array( $raw_sources ) ) {
						$raw_sources = '' !== (string) $raw_sources ? array( $raw_sources ) : array();
					}
						$sources       = array_filter( array_map( 'sanitize_key', $raw_sources ) );
					$status            = sanitize_key( wp_unslash( $this->req['lucidlab_mpc_review_set_status'] ?? $this->req['review_status'] ?? '' ) );
						$valid_sources = array( 'duplicates', 'orphans', 'uploads', 'shared', 'sizes', 'dupcontent', 'visual', 'missing' );
						$sources       = array_values( array_intersect( $sources, $valid_sources ) );
					if ( ! $sources || ! in_array( $status, array( 'dismissed', 'later', 'active' ), true ) ) {
						throw new RuntimeException( __( 'Invalid Review Queue status.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
					$states = (array) get_option( 'lucidlab_mpc_review_status', array() );
					foreach ( $sources as $source ) {
						if ( 'active' === $status ) {
							unset( $states[ $source ] );
						} else {
							$states[ $source ] = $status; }
					}
					update_option( 'lucidlab_mpc_review_status', $states, false );
						/* translators: %d is the number of Review Queue sources updated. */
						$notices[] = array( 'success', sprintf( __( 'Review Queue status saved for %d source(s).', 'lucidlab-mpc-cleanup' ), count( $sources ) ) );
					break;
				case 'run_all':
					$notices[] = array( 'warning', __( 'Use the Run All button with JavaScript enabled to run batched cleanup.', 'lucidlab-mpc-cleanup' ) );
					break;

				case 'scan_duplicates':
					$result = $this->duplicates->scan( $this->posted_mode() );
					break;

				case 'trash_duplicates':
					$notices = array_merge( $notices, $this->op_trash_duplicates() );
					$result  = $this->duplicates->scan( $this->posted_mode() );
					break;

				case 'scan_orphans': // No-JS fallback (synchronous).
					$result = $this->orphans->scan_full();
					break;

				case 'scan_uploads': // No-JS fallback (synchronous).
					$result = $this->uploads->scan_full();
					break;

				case 'scan_shared':
					$result = $this->shared->scan_indexed();
					break;

				case 'restore_quarantine':
					$notices = array_merge( $notices, $this->op_restore() );
					break;

				case 'restore_one':
					$notices = array_merge( $notices, $this->op_restore_one() );
					break;

				case 'purge_quarantine':
					$notices = array_merge( $notices, $this->op_purge() );
					break;

				case 'purge_quarantine_health':
					$notices = array_merge( $notices, $this->op_purge_quarantine_health() );
					break;

				case 'save_settings':
					$this->reports->save(
						! empty( $this->req['lucidlab_mpc_report_enabled'] ) ? 1 : 0,
						LucidLab_MPC_Helpers::sanitize_report_recipients( sanitize_textarea_field( wp_unslash( $this->req['lucidlab_mpc_report_recipient'] ?? '' ) ) )
					);
					$notices[] = array( 'success', __( 'Settings saved.', 'lucidlab-mpc-cleanup' ) );
					break;

				case 'send_test_report':
					$to        = LucidLab_MPC_Helpers::sanitize_report_recipients( sanitize_textarea_field( wp_unslash( $this->req['lucidlab_mpc_report_recipient'] ?? '' ) ) );
					$sent      = $this->reports->send( ( $to ? $to : null ) );
					$notices[] = $sent
						? array( 'success', __( 'Test report sent.', 'lucidlab-mpc-cleanup' ) )
						: array( 'error', __( 'Could not send — check the recipient email and your site mailer.', 'lucidlab-mpc-cleanup' ) );
					break;

				case 'scan_sizes':
					$result = $this->sizes_scan_full();
					break;

				case 'save_options':
					$notices = array_merge( $notices, $this->op_save_options() );
					break;

				case 'reset_plugin_data':
					$notices = array_merge( $notices, $this->op_reset_plugin_data() );
					break;

				case 'save_logging':
					$was_logging    = ! empty( LucidLab_MPC_Settings::get( 'logging' ) );
					$enable_logging = ! empty( $this->req['lucidlab_mpc_logging'] );
					if ( $was_logging && ! $enable_logging && class_exists( 'LucidLab_MPC_Logger', false ) ) {
						LucidLab_MPC_Logger::log( __( 'Logging settings saved; logging disabled.', 'lucidlab-mpc-cleanup' ) );
					}
					LucidLab_MPC_Settings::update( array( 'logging' => empty( $this->req['lucidlab_mpc_logging'] ) ? 0 : 1 ) );
					if ( $enable_logging && class_exists( 'LucidLab_MPC_Logger', false ) ) {
						LucidLab_MPC_Logger::log( __( 'Logging settings saved.', 'lucidlab-mpc-cleanup' ) );
					}
					$notices[] = array( 'success', __( 'Logging settings saved.', 'lucidlab-mpc-cleanup' ) );
					break;

				case 'log_clear':
					LucidLab_MPC_Logger::clear();
					$notices[] = array( 'success', __( 'Log cleared.', 'lucidlab-mpc-cleanup' ) );
					break;

				case 'clean_empty':
					$removed = LucidLab_MPC_Helpers::remove_empty_dirs( $this->quar->root() );
					/* translators: %d is the number of empty upload folders removed. */
					$notices[] = array( 'success', sprintf( __( 'Removed %d empty folder(s) from uploads.', 'lucidlab-mpc-cleanup' ), $removed ) );
					break;

				case 'purge_source':
					$src = isset( $this->req['lucidlab_mpc_source'] ) ? sanitize_key( wp_unslash( $this->req['lucidlab_mpc_source'] ) ) : '';
					/* translators: %d is the number of quarantine sets purged. */
					$notices[] = array( 'success', sprintf( __( 'Purged %d set(s) in this section.', 'lucidlab-mpc-cleanup' ), $this->quar->purge_all_source( $src ) ) );
					break;

				case 'undo_merge':
					$notices = array_merge( $notices, $this->op_undo_merge() );
					break;

				case 'purge_missing':
					$notices = array_merge( $notices, $this->op_purge_missing() );
					break;
				case 'undo_rename':
					$r         = $this->rename->undo( isset( $this->req['lucidlab_mpc_index'] ) ? absint( $this->req['lucidlab_mpc_index'] ) : 0 );
					$notices[] = array( ! empty( $r['ok'] ) ? 'success' : 'error', $r['message'] );
					break;
			}
		}

		$this->render_shell( $tab, $result, $notices );
	}

	/**
	 * Saved match mode.
	 *
	 * @return string
	 */
	public function saved_match_mode(): string {
		$m = sanitize_key( (string) get_option( 'lucidlab_mpc_match_mode', 'title_sku' ) );
		return in_array( $m, array( 'title', 'title_sku', 'slug' ), true ) ? $m : 'title_sku';
	}

	/**
	 * Posted mode.
	 *
	 * @return string
	 */
	private function posted_mode(): string {
		if ( isset( $this->req['lucidlab_mpc_match_mode'] ) ) {
			$m = sanitize_key( wp_unslash( $this->req['lucidlab_mpc_match_mode'] ) );
			if ( in_array( $m, array( 'title', 'title_sku', 'slug' ), true ) ) {
				update_option( 'lucidlab_mpc_match_mode', $m, false );
				return $m;
			}
		}
		return $this->saved_match_mode();
	}

	/**
	 * Label shared by View current results and Review Queue after the saved list is emptied.
	 *
	 * @return string
	 */
	protected function quarantine_done_label(): string {
		return __( 'Done / Quarantined', 'lucidlab-mpc-cleanup' );
	}

	/**
	 * Shown when a saved scan list has been emptied by quarantine. Review Queue uses the same list.
	 *
	 * @param mixed $data Data.
	 *
	 * @return bool
	 */
	protected function render_if_quarantined( $data ): bool {
		if ( ! is_array( $data ) || ( $data['outcome'] ?? '' ) !== 'quarantined' ) {
			return false;
		}
		echo '<p class="lucidlab-mpc-empty lucidlab-mpc-good"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html( $this->quarantine_done_label() ) . '</p>';
		return true;
	}

	/**
	 * Render cron note.
	 */
	private function render_cron_note(): void {
		$health   = LucidLab_MPC_Helpers::cron_health();
		$standard = __( 'Standard', 'lucidlab-mpc-cleanup' );
		$server   = __( 'Server cron', 'lucidlab-mpc-cleanup' );
		$off      = __( 'Disabled', 'lucidlab-mpc-cleanup' );
		if ( empty( $health['disabled'] ) ) {
			$state = $standard;
		} elseif ( 'ok' === ( $health['status'] ?? '' ) ) {
			$state = $server;
		} else {
			$state = $off;
		}
		$this->begin_results_card( ' data-mpc-options-section="wp-cron"' );
		echo '<h3 class="lucidlab-mpc-card__title">' . esc_html__( 'WP-Cron', 'lucidlab-mpc-cleanup' ) . '</h3>';
		echo '<p class="lucidlab-mpc-hint lucidlab-mpc-card__lede"><strong>' . esc_html( $state ) . '.</strong> ' . esc_html( (string) ( $health['label'] ?? '' ) ) . '</p>';
		$this->end_results_card();
	}

	/**
	 * Drop quarantined rows from the saved scan so View current results and Review Queue stay in step.
	 *
	 * @param string           $tab       Tab.
	 * @param array<int,mixed> $removed   Attachment IDs or upload-relative paths that moved.
	 * @param bool             $clear_all Clear all.
	 */
	private function forget_quarantined_items( string $tab, array $removed, bool $clear_all = false ): void {
		$tab  = sanitize_key( $tab );
		$data = $this->get_scan_result( $tab );
		if ( ! is_array( $data ) ) {
			return;
		}
		$gone = array();
		foreach ( $removed as $item ) {
			$gone[ (string) $item ] = true;
		}
		if ( 'orphans' === $tab && isset( $data['orphans'] ) && is_array( $data['orphans'] ) ) {
			$keep  = array();
			$bytes = 0;
			foreach ( $data['orphans'] as $row ) {
				$id = (string) ( is_array( $row ) ? ( $row['id'] ?? '' ) : $row );
				if ( ! $clear_all && isset( $gone[ $id ] ) ) {
					continue;
				}
				if ( $clear_all ) {
					continue;
				}
				$keep[] = $row;
				$bytes += (int) ( is_array( $row ) ? ( $row['size'] ?? $row['bytes'] ?? 0 ) : 0 );
			}
			$data['orphans']       = $keep;
			$data['orphan_count']  = count( $keep );
			$data['reclaim_bytes'] = $bytes;
			if ( ! $keep ) {
				$data['outcome'] = 'quarantined';
			}
		} elseif ( 'shared' === $tab && ! empty( $data['images_file'] ) && ! isset( $data['images'] ) ) {
			$left = $this->shared_forget_ids( $gone, $clear_all );
			if ( $left >= 0 ) {
				$data['image_count'] = $left;
				if ( $left < 1 ) {
					$data['outcome'] = 'quarantined';
				}
			}
		} elseif ( 'shared' === $tab && isset( $data['images'] ) && is_array( $data['images'] ) ) {
			$keep = array();
			foreach ( $data['images'] as $row ) {
				$id = (string) ( is_array( $row ) ? ( $row['id'] ?? '' ) : $row );
				if ( $clear_all || isset( $gone[ $id ] ) ) {
					continue;
				}
				$keep[] = $row;
			}
			$data['images']      = $keep;
			$data['image_count'] = count( $keep );
			if ( ! $keep ) {
				$data['outcome'] = 'quarantined';
			}
		} elseif ( 'uploads' === $tab ) {
			if ( $clear_all ) {
				LucidLab_MPC_Uploads::clear_result_file( $this->uid() );
				$data['orphan_units']  = array();
				$data['orphan_files']  = 0;
				$data['orphan_bytes']  = 0;
				$data['orphan_groups'] = 0;
				$data['outcome']       = 'quarantined';
			} else {
				$units = LucidLab_MPC_Uploads::load_result_units( $this->uid() );
				if ( ! $units && ! empty( $data['orphan_units'] ) && is_array( $data['orphan_units'] ) ) {
					$units = $data['orphan_units'];
				}
				$files = 0;
				$bytes = 0;
				foreach ( $units as $key => $unit ) {
					$kept_files = array();
					$kept_bytes = 0;
					foreach ( (array) ( $unit['files'] ?? array() ) as $file ) {
						$rel = (string) ( is_array( $file ) ? ( $file['rel'] ?? '' ) : $file );
						if ( '' === $rel || isset( $gone[ $rel ] ) ) {
							continue;
						}
						$kept_files[] = $file;
						$kept_bytes  += (int) ( is_array( $file ) ? ( $file['bytes'] ?? 0 ) : 0 );
					}
					if ( ! $kept_files ) {
						unset( $units[ $key ] );
						continue;
					}
					$units[ $key ]['files'] = $kept_files;
					$units[ $key ]['bytes'] = $kept_bytes;
					$files                 += count( $kept_files );
					$bytes                 += $kept_bytes;
				}
				LucidLab_MPC_Uploads::replace_result_units( $this->uid(), $units );
				$data['orphan_units']      = array();
				$data['orphan_units_file'] = 1;
				$data['orphan_files']      = $files;
				$data['orphan_bytes']      = $bytes;
				$data['orphan_groups']     = count( $units );
				if ( ! $units ) {
					$data['outcome'] = 'quarantined';
				}
			}
		} elseif ( in_array( $tab, array( 'visual', 'dupcontent' ), true ) && isset( $data['groups'] ) && is_array( $data['groups'] ) ) {
			$groups       = array();
			$dupes        = 0;
			$bytes        = 0;
			$file_copies  = 0;
			$same_records = 0;
			foreach ( $data['groups'] as $group ) {
				if ( $clear_all ) {
					continue;
				}
				if ( 'dupcontent' === $tab && is_array( $group ) && isset( $group['members'] ) ) {
					$members = array();
					foreach ( (array) $group['members'] as $member ) {
						$id = (string) ( is_array( $member ) ? ( $member['id'] ?? '' ) : $member );
						if ( isset( $gone[ $id ] ) ) {
							continue;
						}
						$members[] = $member;
					}
					if ( count( $members ) < 2 ) {
						continue;
					}
					// A record that shared its file with a removed record now holds that file itself.
					$files = 0;
					foreach ( $members as $member ) {
						$same = is_array( $member ) ? (int) ( $member['same_file'] ?? 0 ) : 0;
						if ( ! $same || isset( $gone[ (string) $same ] ) ) {
							++$files;
						}
					}
					$files              = max( 1, $files );
					$group['members']   = $members;
					$group['count']     = count( $members );
					$group['files']     = $files;
					$group['same_file'] = count( $members ) - $files;
					$group['reclaim']   = (int) ( $group['bytes'] ?? 0 ) * ( $files - 1 );
					$groups[]           = $group;
					$dupes             += max( 0, count( $members ) - 1 );
					$file_copies       += $files - 1;
					$same_records      += $group['same_file'];
					$bytes             += $group['reclaim'];
					continue;
				}
				$ids = array();
				foreach ( (array) ( is_array( $group ) && isset( $group['members'] ) ? $group['members'] : $group ) as $member ) {
					$id = (int) ( is_array( $member ) ? ( $member['id'] ?? 0 ) : $member );
					if ( $id > 0 && ! isset( $gone[ (string) $id ] ) ) {
						$ids[] = $id;
					}
				}
				if ( count( $ids ) < 2 ) {
					continue;
				}
				$groups[] = $ids;
			}
			$data['groups'] = $groups;
			if ( 'visual' === $tab ) {
				$data['candidate_groups'] = count( $groups );
			} else {
				$data['group_count']       = count( $groups );
				$data['dupe_count']        = $dupes;
				$data['file_copies']       = $file_copies;
				$data['same_file_records'] = $same_records;
				$data['reclaim_bytes']     = $bytes;
			}
			if ( ! $groups ) {
				$data['outcome'] = 'quarantined';
			}
		} else {
			return;
		}
		$this->set_scan_result( $tab, $data, 0, true );
	}

	/**
	 * Finish a streamed Uploads quarantine without rewriting uploads-result between batches. Clear when every candidate moved; otherwise keep rows whose files are still on disk.
	 *
	 * @param bool $all_moved All moved.
	 */
	private function finalize_uploads_stream_result( bool $all_moved ): void {
		if ( $all_moved ) {
			$this->forget_quarantined_items( 'uploads', array(), true );
			return;
		}
		$counts = LucidLab_MPC_Uploads::prune_result_to_existing( $this->uid() );
		$data   = $this->get_scan_result( 'uploads' );
		if ( ! is_array( $data ) ) {
			return;
		}
		$data['orphan_units']      = array();
		$data['orphan_units_file'] = 1;
		$data['orphan_files']      = (int) $counts['files'];
		$data['orphan_bytes']      = (int) $counts['bytes'];
		$data['orphan_groups']     = (int) $counts['groups'];
		if ( empty( $counts['groups'] ) ) {
			$data['outcome'] = 'quarantined';
		}
		$this->set_scan_result( 'uploads', $data, 0, true );
	}

	/**
	 * Op restore.
	 *
	 * @return array
	 */
	private function op_restore(): array {
		$stamp = isset( $this->req['lucidlab_mpc_set'] ) ? sanitize_file_name( wp_unslash( $this->req['lucidlab_mpc_set'] ) ) : '';
		$r     = $this->quar->restore( $stamp );
		if ( ! empty( $r['error'] ) ) {
			return array( array( 'error', __( 'Restore failed: manifest missing.', 'lucidlab-mpc-cleanup' ) ) );
		}

		// Clear last action if this was undoing the last action.
		$last_action = LucidLab_MPC_Quarantine::get_last_action();
		if ( ! empty( $last_action ) && $last_action['stamp'] === $stamp ) {
			LucidLab_MPC_Quarantine::clear_last_action();
		}

		/* translators: %d is the number of items restored from quarantine. */
		$out = array( array( 'success', sprintf( __( 'Restored %d item(s) to their original locations.', 'lucidlab-mpc-cleanup' ), (int) $r['ok'] ) ) );
		if ( ! empty( $r['fail'] ) ) {
			/* translators: %d is the number of items that could not be restored. */
			$out[] = array( 'warning', sprintf( __( '%d item(s) could not be restored.', 'lucidlab-mpc-cleanup' ), (int) $r['fail'] ) );
		}
		if ( (int) ( $r['ok'] ?? 0 ) > 0 ) {
			$this->mark_scan_stale( 'orphans' );
			$source = $this->quar->set_source( $stamp );
			if ( in_array( $source, array( 'uploads', 'shared', 'missing', 'dupcontent', 'visual' ), true ) ) {
				$this->mark_scan_stale( $source );
			}
			$out[] = array( 'warning', __( 'Restored files can change earlier scan results. Run the affected scan again before relying on it.', 'lucidlab-mpc-cleanup' ) );
		}
		return $out;
	}

	/**
	 * Op restore one.
	 *
	 * @return array
	 */
	private function op_restore_one(): array {
		$stamp = isset( $this->req['lucidlab_mpc_set'] ) ? sanitize_file_name( wp_unslash( $this->req['lucidlab_mpc_set'] ) ) : '';
		$type  = isset( $this->req['lucidlab_mpc_type'] ) ? sanitize_key( wp_unslash( $this->req['lucidlab_mpc_type'] ) ) : 'file';
		$key   = isset( $this->req['lucidlab_mpc_key'] ) ? sanitize_text_field( wp_unslash( $this->req['lucidlab_mpc_key'] ) ) : '';
		$type  = in_array( $type, array( 'file', 'attachment' ), true ) ? $type : 'file';
		$r     = $this->quar->restore_one( $stamp, $type, (string) $key );
		if ( ! empty( $r['error'] ) ) {
			return array( array( 'error', __( 'Restore failed: item not found.', 'lucidlab-mpc-cleanup' ) ) );
		}
		if ( (int) ( $r['ok'] ?? 0 ) > 0 ) {
			$this->mark_scan_stale( 'orphans' );
			$source = $this->quar->set_source( $stamp );
			if ( in_array( $source, array( 'uploads', 'shared', 'missing', 'dupcontent', 'visual' ), true ) ) {
				$this->mark_scan_stale( $source );
			}
		}
		return ( (int) $r['ok'] > 0 )
			? array( array( 'success', __( 'Restored the selected item to its original location.', 'lucidlab-mpc-cleanup' ) ) )
			: array( array( 'error', __( 'Could not restore the item (check permissions).', 'lucidlab-mpc-cleanup' ) ) );
	}

	/**
	 * Permanently delete one or all missing-image media records from the saved scan.
	 *
	 * @return array
	 */
	private function op_purge_missing(): array {
		$raw = sanitize_text_field( wp_unslash( $this->req['lucidlab_mpc_image_id'] ?? '' ) );
		$ids = array();
		if ( 'ALL' === $raw ) {
			$data = $this->get_scan_result( 'missing' );
			foreach ( (array) ( is_array( $data ) ? ( $data['images'] ?? array() ) : array() ) as $image ) {
				$id = absint( $image['id'] ?? 0 );
				if ( $id ) {
					$ids[] = $id;
				}
			}
			$ids = array_values( array_unique( $ids ) );
			if ( ! $ids ) {
				return array( array( 'error', __( 'No missing media records to purge. Run a scan first.', 'lucidlab-mpc-cleanup' ) ) );
			}
		} else {
			$id = absint( $raw );
			if ( ! $id ) {
				return array( array( 'error', __( 'Missing image ID.', 'lucidlab-mpc-cleanup' ) ) );
			}
			$ids = array( $id );
		}
		$ok      = 0;
		$fail    = 0;
		$removed = array();
		foreach ( $ids as $id ) {
			if ( $this->purge_missing_record( $id ) ) {
				++$ok;
				$removed[] = $id;
			} else {
				++$fail;
			}
		}
		$this->refresh_missing_scan_after_purge( $removed );
		$out = array();
		if ( $ok ) {
			$out[] = array(
				'success',
				1 === $ok
					? __( 'Missing image media record permanently removed. Remaining files were kept.', 'lucidlab-mpc-cleanup' )
					/* translators: %d is the number of missing-image media records deleted. */
					: sprintf( __( '%d missing image media records permanently removed. Remaining files were kept.', 'lucidlab-mpc-cleanup' ), $ok ),
			);
		}
		if ( $fail ) {
			$out[] = array(
				'error',
				1 === count( $ids )
					? __( 'Purge refused: the image exists, its path cannot be verified, or you cannot delete this record.', 'lucidlab-mpc-cleanup' )
					/* translators: %d is the number of media records that could not be deleted. */
					: sprintf( __( '%d record(s) could not be purged (file exists, path invalid, or insufficient permission).', 'lucidlab-mpc-cleanup' ), $fail ),
			);
		}
		return ( $out ? $out : array( array( 'error', __( 'WordPress could not delete the media record.', 'lucidlab-mpc-cleanup' ) ) ) );
	}

	/**
	 * Purge missing record.
	 *
	 * @param int $id Id.
	 *
	 * @return bool True when the media record was deleted.
	 */
	private function purge_missing_record( int $id ): bool {
		$file = LucidLab_MPC_Helpers::attached_file_path( $id );
		$base = trailingslashit( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ) );
		if ( ! $id || ! current_user_can( 'delete_post', $id ) || ! wp_attachment_is_image( $id ) || ! $file || ! str_starts_with( strtolower( $file ), strtolower( $base ) ) || preg_match( '~(^|/)\.\.(/|$)~', $file ) || is_file( $file ) ) {
			return false;
		}
		$keep_files = static fn() => '';
		add_filter( 'wp_delete_file', $keep_files, PHP_INT_MAX );
		try {
			$deleted = wp_delete_attachment( $id, true );
		} finally {
			remove_filter( 'wp_delete_file', $keep_files, PHP_INT_MAX );
		}
		return (bool) $deleted;
	}

	/**
	 * A saved broken URL whose WebP or AVIF copy is on disk is not broken.
	 */
	private function drop_converted_broken_urls(): void {
		$data = $this->get_scan_result( 'missing' );
		if ( ! is_array( $data ) || empty( $data['urls'] ) || ! is_array( $data['urls'] ) ) {
			return;
		}
		$kept = array();
		foreach ( $data['urls'] as $item ) {
			$rel = ltrim( wp_normalize_path( (string) ( $item['file'] ?? '' ) ), '/' );
			if ( '' !== $rel && LucidLab_MPC_Helpers::has_generated_copy( $rel ) ) {
				continue;
			}
			$kept[] = $item;
		}
		if ( count( $kept ) === count( $data['urls'] ) ) {
			return;
		}
		$data['urls']        = array_values( $kept );
		$data['broken_urls'] = count( $kept );
		$this->set_scan_result( 'missing', $data, 0, true );
		$summary = get_option( 'lucidlab_mpc_summary_missing', array() );
		if ( ! is_array( $summary ) ) {
			$summary = array();
		}
		$counts                = is_array( $summary['counts'] ?? null ) ? $summary['counts'] : array();
		$counts['broken_urls'] = count( $kept );
		$summary['counts']     = $counts;
		update_option( 'lucidlab_mpc_summary_missing', $summary, false );
	}

	/**
	 * Drop purged IDs from the saved Missing Images scan result.
	 *
	 * @param array $purged_ids Purged ids.
	 */
	private function refresh_missing_scan_after_purge( array $purged_ids ): void {
		$purged = array_fill_keys( array_map( 'intval', $purged_ids ), true );
		$data   = $this->get_scan_result( 'missing' );
		if ( ! is_array( $data ) || empty( $data['images'] ) ) {
			return;
		}
		$kept = array();
		foreach ( (array) $data['images'] as $image ) {
			$id = absint( $image['id'] ?? 0 );
			if ( ! $id || ! empty( $purged[ $id ] ) ) {
				continue;
			}
			$kept[] = $image;
		}
		$data['images']      = $kept;
		$data['missing']     = count( $kept );
		$urls                = is_array( $data['urls'] ?? null ) ? $data['urls'] : array();
		$data['broken_urls'] = count( $urls );
		$this->set_scan_result( 'missing', $data, 0, true );
		$summary = get_option( 'lucidlab_mpc_summary_missing', array() );
		if ( ! is_array( $summary ) ) {
			$summary = array();
		}
		$summary['counts'] = array(
			'checked'     => (int) ( $data['checked'] ?? ( $summary['counts']['checked'] ?? 0 ) ),
			'missing'     => count( $kept ),
			'broken_urls' => count( $urls ),
		);
		update_option( 'lucidlab_mpc_summary_missing', $summary, false );
	}

	/**
	 * Op save options.
	 *
	 * @return array
	 */
	private function op_save_options(): array {
		$scope = isset( $this->req['lucidlab_mpc_options_scope'] ) ? sanitize_key( wp_unslash( $this->req['lucidlab_mpc_options_scope'] ) ) : 'all';
		// Free and Pro both manage registered sizes; filesystem "Other Sizes" stays Pro-only.
		if ( 'disabled_sizes' === $scope ) {
			$ds = isset( $this->req['lucidlab_mpc_disabled_sizes'] ) ? array_map( 'sanitize_key', array_map( 'sanitize_text_field', (array) wp_unslash( $this->req['lucidlab_mpc_disabled_sizes'] ) ) ) : array();
			LucidLab_MPC_Settings::update( array( 'disabled_sizes' => array_values( $ds ) ) );
			return array( array( 'success', __( 'Disabled sizes saved.', 'lucidlab-mpc-cleanup' ) ) );
		}
		if ( ! LucidLab_MPC_Edition::pro() ) {
			LucidLab_MPC_Settings::update(
				array(
					'batch_db'                 => max( 50, min( 5000, ( isset( $this->req['lucidlab_mpc_batch_db'] ) ? (int) $this->req['lucidlab_mpc_batch_db'] : 500 ) ) ),
					'batch_files'              => max( 50, min( 5000, ( isset( $this->req['lucidlab_mpc_batch_files'] ) ? (int) $this->req['lucidlab_mpc_batch_files'] : 400 ) ) ),
					'scan_batch_db'            => max( 50, min( 5000, ( isset( $this->req['lucidlab_mpc_scan_batch_db'] ) ? (int) $this->req['lucidlab_mpc_scan_batch_db'] : 500 ) ) ),
					'missing_batch'            => max( 1, min( 100, ( isset( $this->req['lucidlab_mpc_missing_batch'] ) ? (int) $this->req['lucidlab_mpc_missing_batch'] : 50 ) ) ),
					'regen_batch'              => max( 1, min( 100, ( isset( $this->req['lucidlab_mpc_regen_batch'] ) ? (int) $this->req['lucidlab_mpc_regen_batch'] : 15 ) ) ),
					'list_size'                => isset( $this->req['lucidlab_mpc_list_size'] ) ? max( 20, min( 2000, (int) $this->req['lucidlab_mpc_list_size'] ) ) : (int) LucidLab_MPC_Settings::get( 'list_size' ),
					'jpeg_quality'             => max( 1, min( 100, ( isset( $this->req['lucidlab_mpc_jpeg_quality'] ) ? (int) $this->req['lucidlab_mpc_jpeg_quality'] : 85 ) ) ),
					'report_enabled'           => empty( $this->req['lucidlab_mpc_report_enabled'] ) ? 0 : 1,
					'report_recipient'         => LucidLab_MPC_Helpers::sanitize_report_recipients( sanitize_textarea_field( wp_unslash( $this->req['lucidlab_mpc_report_recipient'] ?? '' ) ) ),
					'report_frequency'         => sanitize_text_field( wp_unslash( $this->req['lucidlab_mpc_report_frequency'] ?? '' ) ) === 'monthly' ? 'monthly' : 'weekly',
					'schedule_weekday'         => max( 1, min( 7, ( isset( $this->req['lucidlab_mpc_schedule_weekday'] ) ? (int) $this->req['lucidlab_mpc_schedule_weekday'] : 1 ) ) ),
					'schedule_hour'            => max( 0, min( 23, ( isset( $this->req['lucidlab_mpc_schedule_hour'] ) ? (int) $this->req['lucidlab_mpc_schedule_hour'] : 3 ) ) ),
					'exclude_folders'          => sanitize_textarea_field( wp_unslash( $this->req['lucidlab_mpc_exclude_folders'] ?? '' ) ),
					'exclude_mimes'            => sanitize_text_field( wp_unslash( $this->req['lucidlab_mpc_exclude_mimes'] ?? '' ) ),
					'exclude_newer_days'       => max( 0, min( 3650, ( isset( $this->req['lucidlab_mpc_exclude_newer_days'] ) ? (int) $this->req['lucidlab_mpc_exclude_newer_days'] : 0 ) ) ),
					'exclude_featured'         => empty( $this->req['lucidlab_mpc_exclude_featured'] ) ? 0 : 1,
					'backup_keep'              => max( 1, min( 50, ( isset( $this->req['lucidlab_mpc_backup_keep'] ) ? (int) $this->req['lucidlab_mpc_backup_keep'] : 5 ) ) ),
					'install_ping'             => empty( $this->req['lucidlab_mpc_install_ping'] ) ? 0 : 1,
					'delete_data_on_uninstall' => empty( $this->req['lucidlab_mpc_delete_data_on_uninstall'] ) ? 0 : 1,
				)
			);
			$this->reports->configure();
			if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
				LucidLab_MPC_Logger::log( __( 'Options saved.', 'lucidlab-mpc-cleanup' ) );
			}
			return array( array( 'info', __( 'Options saved.', 'lucidlab-mpc-cleanup' ) ) );
		}
		$patch = array(
			'report_enabled'           => ! empty( $this->req['lucidlab_mpc_report_enabled'] ) ? 1 : 0,
			'report_recipient'         => LucidLab_MPC_Helpers::sanitize_report_recipients( sanitize_textarea_field( wp_unslash( $this->req['lucidlab_mpc_report_recipient'] ?? '' ) ) ),
			'report_frequency'         => ( 'monthly' === sanitize_text_field( wp_unslash( $this->req['lucidlab_mpc_report_frequency'] ?? '' ) ) ) ? 'monthly' : 'weekly',
			'schedule_weekday'         => max( 1, min( 7, ( isset( $this->req['lucidlab_mpc_schedule_weekday'] ) ? (int) $this->req['lucidlab_mpc_schedule_weekday'] : 1 ) ) ),
			'schedule_hour'            => max( 0, min( 23, ( isset( $this->req['lucidlab_mpc_schedule_hour'] ) ? (int) $this->req['lucidlab_mpc_schedule_hour'] : 3 ) ) ),
			'batch_db'                 => max( 50, ( isset( $this->req['lucidlab_mpc_batch_db'] ) ? (int) $this->req['lucidlab_mpc_batch_db'] : 500 ) ),
			'batch_files'              => max( 50, ( isset( $this->req['lucidlab_mpc_batch_files'] ) ? (int) $this->req['lucidlab_mpc_batch_files'] : 400 ) ),
			'scan_batch_db'            => max( 50, min( 5000, ( isset( $this->req['lucidlab_mpc_scan_batch_db'] ) ? (int) $this->req['lucidlab_mpc_scan_batch_db'] : 500 ) ) ),
			'missing_batch'            => max( 1, min( 100, ( isset( $this->req['lucidlab_mpc_missing_batch'] ) ? (int) $this->req['lucidlab_mpc_missing_batch'] : 50 ) ) ),
			'regen_batch'              => max( 1, min( 100, ( isset( $this->req['lucidlab_mpc_regen_batch'] ) ? (int) $this->req['lucidlab_mpc_regen_batch'] : 15 ) ) ),
			'list_size'                => isset( $this->req['lucidlab_mpc_list_size'] ) ? max( 20, min( 2000, (int) $this->req['lucidlab_mpc_list_size'] ) ) : (int) LucidLab_MPC_Settings::get( 'list_size' ),
			'jpeg_quality'             => max( 1, min( 100, ( isset( $this->req['lucidlab_mpc_jpeg_quality'] ) ? (int) $this->req['lucidlab_mpc_jpeg_quality'] : 85 ) ) ),
			'quarantine_days'          => max( 1, min( 365, ( isset( $this->req['lucidlab_mpc_quarantine_days'] ) ? (int) $this->req['lucidlab_mpc_quarantine_days'] : 30 ) ) ),
			'quarantine_auto_purge'    => empty( $this->req['lucidlab_mpc_quarantine_auto_purge'] ) ? 0 : 1,
			'ai_provider'              => in_array( ( $this->req['lucidlab_mpc_ai_provider'] ?? 'none' ), array( 'none', 'openai', 'anthropic', 'local' ), true ) ? sanitize_key( wp_unslash( $this->req['lucidlab_mpc_ai_provider'] ) ) : 'none',
			// The saved key is never sent back to the browser: an empty field keeps it, the checkbox removes it.
			'ai_api_key'               => ! empty( $this->req['lucidlab_mpc_ai_api_key_clear'] ) ? '' : LucidLab_MPC_Helpers::either( sanitize_text_field( wp_unslash( $this->req['lucidlab_mpc_ai_api_key'] ?? '' ) ), (string) LucidLab_MPC_Settings::get( 'ai_api_key' ) ),
			'ai_endpoint'              => esc_url_raw( wp_unslash( $this->req['lucidlab_mpc_ai_endpoint'] ?? '' ) ),
			'ai_model'                 => sanitize_text_field( wp_unslash( $this->req['lucidlab_mpc_ai_model'] ?? '' ) ),
			'ai_output_locale'         => self::sanitize_ai_output_locale( sanitize_text_field( wp_unslash( $this->req['lucidlab_mpc_ai_output_locale'] ?? '' ) ), sanitize_text_field( wp_unslash( $this->req['lucidlab_mpc_ai_output_locale_other'] ?? '' ) ) ),
			'ai_daily_requests'        => max( 1, min( 100000, ( isset( $this->req['lucidlab_mpc_ai_daily_requests'] ) ? (int) $this->req['lucidlab_mpc_ai_daily_requests'] : 100 ) ) ),
			'ai_request_estimate'      => max( 0, ( isset( $this->req['lucidlab_mpc_ai_request_estimate'] ) ? (float) $this->req['lucidlab_mpc_ai_request_estimate'] : 0 ) ),
			'ai_daily_budget'          => max( 0, ( isset( $this->req['lucidlab_mpc_ai_daily_budget'] ) ? (float) $this->req['lucidlab_mpc_ai_daily_budget'] : 0 ) ),
			'ai_min_confidence'        => max( 0, min( 1, ( isset( $this->req['lucidlab_mpc_ai_min_confidence'] ) ? (float) $this->req['lucidlab_mpc_ai_min_confidence'] : 0.65 ) ) ),
			'memory_threshold'         => max( 64, min( 4096, ( isset( $this->req['lucidlab_mpc_memory_threshold'] ) ? (int) $this->req['lucidlab_mpc_memory_threshold'] : 256 ) ) ),
			'timeout_threshold'        => max( 3, min( 25, ( isset( $this->req['lucidlab_mpc_timeout_threshold'] ) ? (int) $this->req['lucidlab_mpc_timeout_threshold'] : 8 ) ) ),
			'exclude_folders'          => sanitize_textarea_field( wp_unslash( $this->req['lucidlab_mpc_exclude_folders'] ?? '' ) ),
			'exclude_mimes'            => sanitize_text_field( wp_unslash( $this->req['lucidlab_mpc_exclude_mimes'] ?? '' ) ),
			'exclude_newer_days'       => max( 0, min( 3650, ( isset( $this->req['lucidlab_mpc_exclude_newer_days'] ) ? (int) $this->req['lucidlab_mpc_exclude_newer_days'] : 0 ) ) ),
			'exclude_featured'         => empty( $this->req['lucidlab_mpc_exclude_featured'] ) ? 0 : 1,
			'backup_keep'              => max( 1, min( 50, ( isset( $this->req['lucidlab_mpc_backup_keep'] ) ? (int) $this->req['lucidlab_mpc_backup_keep'] : 5 ) ) ),
			'install_ping'             => empty( $this->req['lucidlab_mpc_install_ping'] ) ? 0 : 1,
			'delete_data_on_uninstall' => empty( $this->req['lucidlab_mpc_delete_data_on_uninstall'] ) ? 0 : 1,
			'autorun_weekly'           => isset( $this->req['lucidlab_mpc_autorun_weekly'] ) ? array_map( 'sanitize_key', array_map( 'sanitize_text_field', (array) wp_unslash( $this->req['lucidlab_mpc_autorun_weekly'] ) ) ) : array(),
			'autorun_monthly'          => isset( $this->req['lucidlab_mpc_autorun_monthly'] ) ? array_map( 'sanitize_key', array_map( 'sanitize_text_field', (array) wp_unslash( $this->req['lucidlab_mpc_autorun_monthly'] ) ) ) : array(),
		);
		$patch['automation_owner'] = get_current_user_id();
		foreach ( array( 'weekly', 'monthly' ) as $frequency ) {
			$patch[ 'autorun_' . $frequency ] = array_values( array_intersect( $patch[ 'autorun_' . $frequency ], array_keys( LucidLab_MPC_Automation::choices() ) ) );
		}
		$notices = array();
		if ( '' !== $patch['ai_endpoint'] ) {
			$patch['ai_endpoint'] = LucidLab_MPC_AI::normalize_endpoint( $patch['ai_provider'], $patch['ai_endpoint'] );
			$valid_endpoint       = LucidLab_MPC_AI::validate_endpoint( $patch['ai_provider'], $patch['ai_endpoint'] );
			if ( is_wp_error( $valid_endpoint ) ) {
				$patch['ai_endpoint'] = (string) LucidLab_MPC_Settings::get( 'ai_endpoint' );
				$notices[]            = array( 'error', sprintf( /* translators: %s: validation error message. */ __( 'The AI endpoint was not saved: %s', 'lucidlab-mpc-cleanup' ), $valid_endpoint->get_error_message() ) );
			}
		}
		LucidLab_MPC_Settings::update( $patch );
		LucidLab_MPC_Automation::configure();
		if ( method_exists( $this->reports, 'configure' ) ) {
			$this->reports->configure(); }
		$this->configure_quarantine_purge_cron();
		if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
			LucidLab_MPC_Logger::log( __( 'Options saved.', 'lucidlab-mpc-cleanup' ) );
		}
		return array_merge( array( array( 'info', __( 'Options saved.', 'lucidlab-mpc-cleanup' ) ) ), $notices );
	}

	/**
	 * Schedule or clear the daily quarantine purge based on Pro + the Options toggle.
	 */
	private function configure_quarantine_purge_cron(): void {
		$enabled = LucidLab_MPC_Edition::pro() && ! empty( LucidLab_MPC_Settings::get( 'quarantine_auto_purge' ) );
		$next    = wp_next_scheduled( self::CRON_PURGE );
		if ( $enabled && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE );
		} elseif ( ! $enabled && $next ) {
			wp_unschedule_event( $next, self::CRON_PURGE );
		}
	}

	/**
	 * Sanitize ai output locale.
	 *
	 * @param string $locale Locale.
	 * @param string $other  Custom language name, used when $locale is __other.
	 *
	 * @return string
	 */
	private static function sanitize_ai_output_locale( string $locale, string $other = '' ): string {
		if ( ! class_exists( 'LucidLab_MPC_AI', false ) ) {
			return '';
		}
		if ( '__other' === $locale ) {
			return LucidLab_MPC_AI::sanitize_custom_language( sanitize_text_field( $other ) );
		}
		$locale  = str_replace( '-', '_', sanitize_text_field( $locale ) );
		$choices = LucidLab_MPC_AI::output_locale_choices();
		return isset( $choices[ $locale ] ) ? $locale : '';
	}

	/**
	 * Options → Reset plugin data (settings defaults + scan artifacts).
	 *
	 * @return array
	 */
	private function op_reset_plugin_data(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( array( 'error', __( 'Permission denied.', 'lucidlab-mpc-cleanup' ) ) );
		}
		$confirm = isset( $this->req['lucidlab_mpc_reset_confirm'] ) ? strtoupper( sanitize_text_field( wp_unslash( $this->req['lucidlab_mpc_reset_confirm'] ) ) ) : '';
		if ( 'CONFIRM' !== $confirm ) {
			return array( array( 'error', __( 'Reset cancelled: type CONFIRM in the confirmation box to proceed.', 'lucidlab-mpc-cleanup' ) ) );
		}
		$clear_quarantine = ! empty( $this->req['lucidlab_mpc_reset_quarantine'] );
		$report           = LucidLab_MPC_Reset::run(
			array(
				'dry_run'          => false,
				'clear_quarantine' => $clear_quarantine,
			)
		);
		$parts            = array(
			__( 'Settings restored to defaults.', 'lucidlab-mpc-cleanup' ),
			__( 'Logging is off by default. Turn it back on under Logs / Diagnostics if you want new scan entries.', 'lucidlab-mpc-cleanup' ),
			__( 'The next Visually Identical scan must rebuild its fingerprints and may take longer.', 'lucidlab-mpc-cleanup' ),
			sprintf(
				/* translators: 1: options deleted, 2: transients deleted, 3: files removed */
				__( 'Removed %1$d options, %2$d transients and %3$d work files.', 'lucidlab-mpc-cleanup' ),
				(int) ( $report['options_deleted'] ?? 0 ),
				(int) ( $report['transients_deleted'] ?? 0 ),
				(int) ( $report['files_removed'] ?? 0 )
			),
		);
		if ( ! empty( $report['tables_truncated'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated table names */
				__( 'Cleared tables: %s.', 'lucidlab-mpc-cleanup' ),
				implode( ', ', array_map( 'strval', (array) $report['tables_truncated'] ) )
			);
		}
		if ( $clear_quarantine ) {
			$parts[] = sprintf(
				/* translators: 1: purged set count, 2: protected set IDs */
				__( 'Quarantine: purged %1$d set(s); protected sets kept (%2$s).', 'lucidlab-mpc-cleanup' ),
				(int) ( $report['quarantine_purged'] ?? 0 ),
				implode( ', ', LucidLab_MPC_Quarantine::PROTECTED_HEALTH_SETS )
			);
		} else {
			$parts[] = __( 'Quarantine and user backups were kept.', 'lucidlab-mpc-cleanup' );
		}
		$parts[] = __( 'License keys and rename undo history were kept.', 'lucidlab-mpc-cleanup' );
		return array( array( 'success', implode( ' ', $parts ) ) );
	}

	/**
	 * Op undo merge.
	 *
	 * @return array
	 */
	private function op_undo_merge(): array {
		$stamp = isset( $this->req['lucidlab_mpc_set'] ) ? sanitize_file_name( wp_unslash( $this->req['lucidlab_mpc_set'] ) ) : '';
		$r     = $this->dupcontent->undo_merge( $stamp, $this->quar );
		if ( ! empty( $r['error'] ) ) {
			return array( array( 'error', __( 'Undo failed: this merge set could not be found.', 'lucidlab-mpc-cleanup' ) ) );
		}
		/* translators: 1: restored images, 2: references pointed back to them. */
		return array( array( 'success', sprintf( __( 'Undid the merge: restored %1$d image(s) and repointed %2$d reference(s) back to them.', 'lucidlab-mpc-cleanup' ), (int) $r['restored'], (int) $r['repointed'] ) ) );
	}

	/**
	 * Op purge.
	 *
	 * @return array
	 */
	private function op_purge(): array {
		$set = isset( $this->req['lucidlab_mpc_set'] ) ? sanitize_text_field( wp_unslash( $this->req['lucidlab_mpc_set'] ) ) : '';
		if ( ! LucidLab_MPC_Edition::pro() ) {
			foreach ( $this->quar->list_sets() as $row ) {
				if ( ( 'ALL' === $set || (string) ( $row['stamp'] ?? '' ) === (string) $set ) && LucidLab_MPC_Edition::pro_quarantine_source( (string) ( $row['source'] ?? '' ) ) ) {
					return array( array( 'error', __( 'This quarantine source requires the Pro add-on. Restore remains available.', 'lucidlab-mpc-cleanup' ) ) );
				}
			}
		}
		if ( 'ALL' === $set ) {
			$purged = $this->quar->purge_all();
			/* translators: %d is the number of quarantine sets permanently purged. */
			$msg = sprintf( __( 'Purged %d quarantine set(s) permanently.', 'lucidlab-mpc-cleanup' ), $purged );
			if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
				LucidLab_MPC_Logger::log( $msg );
			}
			return array( array( 'success', $msg ) );
		}
		$stamp = sanitize_file_name( (string) $set );
		if ( LucidLab_MPC_Quarantine::is_health_protected( $stamp ) ) {
			return array( array( 'error', __( 'That quarantine set is protected and cannot be purged.', 'lucidlab-mpc-cleanup' ) ) );
		}
		if ( ! $this->quar->purge( $stamp ) ) {
			return array( array( 'error', __( 'Could not purge the quarantine set.', 'lucidlab-mpc-cleanup' ) ) );
		}
		/* translators: %s is the name of the quarantine set permanently purged. */
		$msg = sprintf( __( 'Purged quarantine set %s permanently.', 'lucidlab-mpc-cleanup' ), $stamp );
		if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
			LucidLab_MPC_Logger::log( $msg );
		}
		return array( array( 'success', $msg ) );
	}

	/**
	 * Op purge quarantine health.
	 *
	 * @return array
	 */
	private function op_purge_quarantine_health(): array {
		$kinds  = isset( $this->req['lucidlab_mpc_health_kinds'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $this->req['lucidlab_mpc_health_kinds'] ) ) : array();
		$kinds  = array_values( array_filter( array_map( 'sanitize_key', $kinds ) ) );
		$stamps = isset( $this->req['lucidlab_mpc_health_stamps'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $this->req['lucidlab_mpc_health_stamps'] ) ) : array();
		$stamps = array_values( array_filter( array_map( 'sanitize_file_name', $stamps ) ) );
		if ( ! $kinds && ! $stamps ) {
			return array( array( 'error', __( 'Select at least one Quarantine Health issue group to purge.', 'lucidlab-mpc-cleanup' ) ) );
		}
		$res = $this->quar->health_purge_broken( $stamps, $kinds );
		$msg = sprintf(
			/* translators: 1: purged count, 2: protected skipped count */
			__( 'Purged %1$d broken quarantine artifact(s). Protected sets skipped: %2$d. These items are not restoreable.', 'lucidlab-mpc-cleanup' ),
			(int) ( $res['ok'] ?? 0 ),
			(int) ( $res['protected'] ?? 0 )
		);
		return array( array( 'success', $msg ) );
	}

	// AJAX — stepped scans + batched quarantine.

	/**
	 * Handle ajax.
	 */
	public function handle_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->accept_request();
		LucidLab_MPC_Logger::watch_fatal( 'ajax ' . sanitize_key( wp_unslash( $this->req['op'] ?? '' ) ) );

		if ( sanitize_text_field( wp_unslash( $this->req['op'] ?? '' ) ) === 'save_match_mode' ) {
			$match_mode = sanitize_key( wp_unslash( $this->req['lucidlab_mpc_match_mode'] ?? '' ) );
			if ( ! in_array( $match_mode, array( 'title', 'title_sku', 'slug' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid match mode.', 'lucidlab-mpc-cleanup' ) ), 400 );
			}
			update_option( 'lucidlab_mpc_match_mode', $match_mode, false );
			wp_send_json_success( array( 'mode' => $match_mode ) );
		}

		// Install ping opt-in notice: "Yes, count this site" and "No thanks".
		$ping_op = sanitize_key( wp_unslash( $this->req['op'] ?? '' ) );
		if ( 'ping_save' === $ping_op ) {
			$ping_on = isset( $this->req['on'] ) && is_string( $this->req['on'] ) && '1' === sanitize_text_field( wp_unslash( $this->req['on'] ) );
			wp_send_json_success( LucidLab_MPC_Site_Ping::set( $ping_on ) );
		}
		if ( 'ping_dismiss' === $ping_op ) {
			LucidLab_MPC_Site_Ping::dismiss_notice();
			wp_send_json_success( array( 'dismissed' => true ) );
		}

		if ( sanitize_text_field( wp_unslash( $this->req['op'] ?? '' ) ) === 'taskjob' ) {
			try {
				$sub     = sanitize_key( wp_unslash( $this->req['sub'] ?? 'status' ) );
				$request = LucidLab_MPC_Helpers::either( LucidLab_MPC_Helpers::json_input( $this->req['request'] ?? '{}', 'auto' ), array() );
				$job     = ( new LucidLab_MPC_Task_Job( $this ) )->command( $sub, $request );
				if ( 'list' === $sub ) {
					$job['jobs'] = array_merge( (array) ( $job['jobs'] ?? array() ), $this->special_job_states() );
				}
				wp_send_json_success( $job );
			} catch ( Throwable $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ), 400 ); }
		}
		try {
			LucidLab_MPC_Edition::check( $this->edition_params() );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 403 );}
		$op = isset( $this->req['op'] ) ? sanitize_key( wp_unslash( $this->req['op'] ) ) : '';
		if ( ! in_array( $op, array( 'scan', 'rename_ids', 'webpscan', 'ai_analyze', 'ai_test', 'ai_job', 'ai_saved_result', 'ai_saved_rename_ids', 'product_copy_suggest', 'product_copy_apply', 'product_copy_undo', 'product_copy_about', 'load_scan', 'preview', 'webp_preview', 'webp_preview_clear', 'list_folders', 'backup', 'save_page_settings', 'inventory', 'lazy_refs', 'email_emergency_restore', 'backup_restore_key', 'crawl_rows', 'db_shrink_preview', 'db_shrink_run', 'core_check' ), true ) && ( sanitize_text_field( wp_unslash( $this->req['sub'] ?? '' ) ) !== 'status' ) ) {
			delete_transient( 'lucidlab_mpc_cached_reg_v2' );
			// Quarantine of unused files does not change content references; keep the shared index for Run All reuse.
			if ( 'quarantine' !== $op ) {
				delete_transient( 'lucidlab_mpc_cached_ref' );
			}
		}
		if ( 'scan' === $op && ! LucidLab_MPC_Index::get() ) {
			LucidLab_MPC_Index::rebuild(); }
		$tab = isset( $this->req['tab'] ) ? sanitize_key( wp_unslash( $this->req['tab'] ) ) : '';
		if ( in_array( $op, array( 'ai_job', 'ai_saved_result', 'ai_analyze', 'ai_saved_rename_ids' ), true ) && LucidLab_MPC_Edition::pro() && ! class_exists( 'LucidLab_MPC_AI_Job' ) ) {
			wp_send_json_error( array( 'message' => __( 'Update the Pro add-on to version 6.0.4 as well as the Free core to use AI analysis.', 'lucidlab-mpc-cleanup' ) ), 400 );
		}
		if ( 'ai_job' === $op ) {
			if ( ! LucidLab_MPC_Edition::pro() ) {
				wp_send_json_error( array( 'message' => __( 'AI library analysis requires Pro.', 'lucidlab-mpc-cleanup' ) ), 403 );
			}
			$ids = LucidLab_MPC_Helpers::json_input( $this->req['ids'] ?? '[]', array( 'list' => 'absint' ) );
			try {
				wp_send_json_success( LucidLab_MPC_AI_Job::command( sanitize_key( wp_unslash( $this->req['sub'] ?? 'status' ) ), array( 'ids' => is_array( $ids ) ? $ids : array() ) ) ); } catch ( Throwable $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ), 400 ); }
		}
		if ( 'ai_saved_result' === $op ) {
			$id = absint( $this->req['attachment_id'] ?? 0 );
			if ( ! LucidLab_MPC_Edition::pro() || ! $id || ! current_user_can( 'edit_post', $id ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lucidlab-mpc-cleanup' ) ), 403 );
			}
			$saved = get_post_meta( $id, LucidLab_MPC_AI_Job::RESULT, true );
			if ( ! is_array( $saved ) || ! empty( $saved['error'] ) || ( $saved['fingerprint'] ?? '' ) !== LucidLab_MPC_AI_Job::fingerprint( $id ) ) {
				wp_send_json_error( array( 'message' => __( 'The saved suggestion is unavailable or the file changed. Analyze again.', 'lucidlab-mpc-cleanup' ) ), 400 );
			}
			$saved['filename']            = LucidLab_MPC_AI_Job::suggestion_filename( $saved );
			$saved['title']               = LucidLab_MPC_AI_Job::suggestion_title( $saved );
			$saved['alt_text']            = LucidLab_MPC_AI_Job::suggestion_alt( $saved );
			$saved['current_caption']     = (string) get_post_field( 'post_excerpt', $id );
			$saved['current_description'] = (string) get_post_field( 'post_content', $id );
			wp_send_json_success( $saved );
		}
		if ( 'ai_saved_rename_ids' === $op ) {
			if ( ! LucidLab_MPC_Edition::pro() || ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lucidlab-mpc-cleanup' ) ), 403 );
			}
			wp_send_json_success( array( 'items' => LucidLab_MPC_AI_Job::renameable_items() ) );
		}
		if ( 'ai_analyze' === $op ) {
			$id = absint( $this->req['attachment_id'] ?? 0 );
			if ( ! LucidLab_MPC_Edition::pro() || ! $id || ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				wp_send_json_error( array( 'message' => __( 'AI analysis requires Pro and an editable image.', 'lucidlab-mpc-cleanup' ) ), 403 );
			}

			// Try to enhance analysis with similarity context.
			$similarity_context = class_exists( 'LucidLab_MPC_Review' ) ? LucidLab_MPC_Review::get_similarity_context( $id ) : array();
			$result             = $similarity_context && ! empty( $similarity_context['duplicate_group_ids'] )
				? LucidLab_MPC_AI::analyze_with_similarity( $id, $similarity_context )
				: LucidLab_MPC_AI::analyze_image( $id );

			if ( is_wp_error( $result ) ) {
				if ( ! in_array( $result->get_error_code(), array( 'lucidlab_mpc_ai_limit', 'lucidlab_mpc_ai_resource', 'lucidlab_mpc_ai_usage_busy' ), true ) ) {
					LucidLab_MPC_AI_Job::save_result( $id, $result );
				}
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}
			wp_send_json_success( LucidLab_MPC_AI_Job::save_result( $id, $result ) );
		}
		if ( 'ai_test' === $op ) {
			if ( ! LucidLab_MPC_Edition::pro() ) {
				wp_send_json_error( array( 'message' => __( 'AI provider testing requires Pro.', 'lucidlab-mpc-cleanup' ) ), 403 ); }
			$provider = sanitize_key( wp_unslash( $this->req['ai_provider'] ?? '' ) );
			$api_key  = sanitize_text_field( wp_unslash( $this->req['ai_api_key'] ?? '' ) );
			$endpoint = LucidLab_MPC_AI::normalize_endpoint( $provider, esc_url_raw( wp_unslash( $this->req['ai_endpoint'] ?? '' ) ) );
			// An empty key field means "use the saved key", but only against the saved provider and endpoint,
			// so a changed endpoint can never receive the stored key.
			if ( '' === $api_key && LucidLab_MPC_AI::provider() === $provider && untrailingslashit( LucidLab_MPC_AI::endpoint() ) === untrailingslashit( $endpoint ) ) {
				$api_key = LucidLab_MPC_AI::api_key();
			}
			$model = sanitize_text_field( wp_unslash( $this->req['ai_model'] ?? '' ) );
			$r     = LucidLab_MPC_AI::request(
				'Return the word OK as JSON.',
				array(),
				array(
					'provider' => $provider,
					'api_key'  => $api_key,
					'endpoint' => $endpoint,
					'model'    => $model,
				)
			);
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ), 400 ); }
			if ( ! is_array( $r ) || ! ( isset( $r['choices'] ) || isset( $r['content'] ) || isset( $r['message'] ) || isset( $r['response'] ) ) ) {
				wp_send_json_error( array( 'message' => __( 'The endpoint answered, but not like a chat API. Check the endpoint path.', 'lucidlab-mpc-cleanup' ) ), 400 );
			}
			wp_send_json_success(
				array(
					/* translators: %s: endpoint URL that was tested. */
					'message'  => '' !== $endpoint ? sprintf( __( 'Provider responded successfully at %s.', 'lucidlab-mpc-cleanup' ), $endpoint ) : __( 'Provider responded successfully.', 'lucidlab-mpc-cleanup' ),
					'endpoint' => $endpoint,
				)
			);
		}
		if ( in_array( $op, array( 'ai_apply_caption', 'ai_apply_description' ), true ) ) {
			if ( ! LucidLab_MPC_Edition::pro() ) {
				wp_send_json_error( array( 'message' => __( 'AI actions require Pro.', 'lucidlab-mpc-cleanup' ) ), 403 ); }
			$id = absint( $this->req['attachment_id'] ?? 0 );
			if ( ! $id || ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				wp_send_json_error( array( 'message' => __( 'Image is missing or cannot be edited.', 'lucidlab-mpc-cleanup' ) ), 403 ); }
			if ( ! empty( $this->req['ai_fingerprint'] ) && sanitize_text_field( wp_unslash( $this->req['ai_fingerprint'] ) ) !== LucidLab_MPC_AI_Job::fingerprint( $id ) ) {
				wp_send_json_error( array( 'message' => __( 'The image file changed. Analyze again before applying.', 'lucidlab-mpc-cleanup' ) ), 409 ); }
			$field = 'ai_apply_caption' === $op ? 'post_excerpt' : 'post_content';
			$text  = 'post_excerpt' === $field ? sanitize_text_field( wp_unslash( $this->req['caption'] ?? '' ) ) : sanitize_textarea_field( wp_unslash( $this->req['description'] ?? '' ) );
			if ( '' === $text ) {
				wp_send_json_error( array( 'message' => __( 'The suggested text is empty.', 'lucidlab-mpc-cleanup' ) ), 400 ); }
			$updated = wp_update_post(
				wp_slash(
					array(
						'ID'   => $id,
						$field => $text,
					)
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				wp_send_json_error( array( 'message' => $updated->get_error_message() ), 400 ); }
			// Refresh only this row; a full invalidate emptied the whole index after one edit.
			LucidLab_MPC_Index::upsert( $id );
			wp_send_json_success( array( 'message' => __( 'Image text updated.', 'lucidlab-mpc-cleanup' ) ) );
		}
		if ( 'ai_apply_title' === $op ) {
			if ( ! LucidLab_MPC_Edition::pro() ) {
				wp_send_json_error( array( 'message' => __( 'AI actions require Pro.', 'lucidlab-mpc-cleanup' ) ), 403 ); }
			$id    = absint( $this->req['attachment_id'] ?? 0 );
			$title = LucidLab_MPC_Helpers::sentence_text( sanitize_text_field( wp_unslash( $this->req['title'] ?? '' ) ) );
			if ( ! $id || ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) || '' === $title ) {
				wp_send_json_error( array( 'message' => __( 'Invalid attachment or title.', 'lucidlab-mpc-cleanup' ) ), 400 );
			}
			if ( ! empty( $this->req['ai_fingerprint'] ) && sanitize_text_field( wp_unslash( $this->req['ai_fingerprint'] ) ) !== LucidLab_MPC_AI_Job::fingerprint( $id ) ) {
				wp_send_json_error( array( 'message' => __( 'The image file changed. Analyze again before applying.', 'lucidlab-mpc-cleanup' ) ), 409 );
			}
			$updated = wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $title,
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				wp_send_json_error( array( 'message' => $updated->get_error_message() ), 400 );
			}
			LucidLab_MPC_Index::upsert( $id );
			wp_send_json_success(
				array(
					'message' => __( 'Image title updated.', 'lucidlab-mpc-cleanup' ),
					'title'   => $title,
				)
			);
		}
		if ( in_array( $op, array( 'ai_apply_alt', 'ai_undo_alt', 'ai_apply_filename' ), true ) ) {
			if ( ! LucidLab_MPC_Edition::pro() ) {
				wp_send_json_error( array( 'message' => __( 'AI actions require Pro.', 'lucidlab-mpc-cleanup' ) ), 403 );
			}
			$id = absint( $this->req['attachment_id'] ?? 0 );
			if ( ! $id || ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				wp_send_json_error( array( 'message' => __( 'Image is missing or cannot be edited.', 'lucidlab-mpc-cleanup' ) ), 403 );
			}
			if ( ! empty( $this->req['ai_fingerprint'] ) && sanitize_text_field( wp_unslash( $this->req['ai_fingerprint'] ) ) !== LucidLab_MPC_AI_Job::fingerprint( $id ) ) {
				wp_send_json_error( array( 'message' => __( 'The image file changed. Analyze again before applying.', 'lucidlab-mpc-cleanup' ) ), 409 );
			}
			if ( 'ai_apply_filename' === $op ) {
				$name   = sanitize_text_field( wp_unslash( $this->req['filename'] ?? '' ) );
				$result = $this->rename->rename( $id, $name, true );
				if ( empty( $result['ok'] ) ) {
					wp_send_json_error( $result, 400 );
				}
				wp_send_json_success( $result );
			}
			$alt_in = sanitize_textarea_field( wp_unslash( $this->req['alt_text'] ?? '' ) );
			if ( 'ai_apply_alt' === $op ) {
				$alt_in = LucidLab_MPC_Helpers::sentence_text( $alt_in );
			}
			$result = LucidLab_MPC_AI::change_alt( $id, 'ai_undo_alt' === $op, $alt_in );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}
			wp_send_json_success( $result );
		}
		if ( in_array( $op, array( 'product_copy_suggest', 'product_copy_apply', 'product_copy_undo', 'product_copy_about' ), true ) ) {
			$this->ajax_product_copy( $op );
		}

		if ( 'rename_job' === $op ) {
			$sub = sanitize_key( wp_unslash( $this->req['sub'] ?? 'status' ) );
			if ( ! in_array( $sub, array( 'status', 'start', 'stop', 'resume', 'cancel', 'background', 'step' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid action.', 'lucidlab-mpc-cleanup' ) ), 400 ); }
			$items = LucidLab_MPC_Helpers::json_input( $this->req['items'] ?? '[]', 'auto' );
			try {
				$r = $this->rename->job_action(
					$sub,
					array(
						'pattern'         => sanitize_text_field( wp_unslash( $this->req['pattern'] ?? '' ) ),
						'search'          => sanitize_text_field( wp_unslash( $this->req['search'] ?? '' ) ),
						'items'           => is_array( $items ) ? $items : array(),
						'update_filename' => ! empty( $this->req['update_filename'] ),
						'update_title'    => ! empty( $this->req['update_title'] ),
						'update_alt'      => ! empty( $this->req['update_alt'] ),
					)
				);
				wp_send_json_success( $r );
			} catch ( Throwable $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ), 400 ); }
		}
		if ( 'rename_ids' === $op ) {
			$search = isset( $this->req['search'] ) ? sanitize_text_field( wp_unslash( $this->req['search'] ) ) : '';
			wp_send_json_success( array( 'ids' => $this->rename->matching_ids( $search ) ) );
		}
		if ( 'run_all_items' === $op ) {
			if ( 'sizes' === $tab ) {
				wp_send_json_success( array( 'items' => array_values( (array) LucidLab_MPC_Settings::get( 'disabled_sizes' ) ) ) );
			}
			if ( ! in_array( $tab, array( 'orphans', 'uploads' ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid cleanup tab.', 'lucidlab-mpc-cleanup' ) ), 400 ); }
			$result = $this->get_scan_result( $tab );
			if ( ! is_array( $result ) ) {
				wp_send_json_error( array( 'message' => __( 'Scan expired. Please run again.', 'lucidlab-mpc-cleanup' ) ), 400 ); }
			$items = array();
			if ( 'orphans' === $tab ) {
				if ( ! empty( $result['orphan_count'] ) || ! empty( $result['orphans'] ) ) {
					$items = array_values( array_filter( array_map( 'intval', wp_list_pluck( (array) ( $result['orphans'] ?? array() ), 'id' ) ) ) );
				}
			} else {
				foreach ( (array) ( $result['orphan_units'] ?? array() ) as $unit ) {
					foreach ( (array) ( $unit['files'] ?? array() ) as $file ) {
						if ( ! empty( $file['rel'] ) ) {
							$items[] = $file['rel'];
						}
					}
				}
			}
			wp_send_json_success( array( 'items' => array_values( array_unique( $items ) ) ) );
		}
		if ( 'crawl_rows' === $op ) {
			$slice = LucidLab_MPC_Crawler::findings_after( $this->uid(), max( 0, ( isset( $this->req['offset'] ) ? (int) $this->req['offset'] : 0 ) ), 40 );
			wp_send_json_success( $slice );
		}
		if ( 'db_shrink_preview' === $op ) {
			if ( method_exists( $this, 'ajax_db_shrink_preview' ) ) {
				$this->ajax_db_shrink_preview();
			}
			wp_send_json_error( array( 'message' => __( 'Database Cleaner shrink tools require Pro.', 'lucidlab-mpc-cleanup' ) ), 403 );
		}
		if ( 'db_shrink_run' === $op ) {
			if ( method_exists( $this, 'ajax_db_shrink_run' ) ) {
				$this->ajax_db_shrink_run();
			}
			wp_send_json_error( array( 'message' => __( 'Database Cleaner shrink tools require Pro.', 'lucidlab-mpc-cleanup' ) ), 403 );
		}
		if ( 'scan' === $op && 'missing' === $tab ) {
			$this->ajax_scan_missing();
		} elseif ( 'scan' === $op && 'crawl' === $tab ) {
			$this->ajax_scan_crawl();} elseif ( 'scan' === $op && 'uploads' === $tab ) {
			$this->ajax_scan_uploads();
			} elseif ( 'scan' === $op && 'orphans' === $tab ) {
				$this->ajax_scan_orphans();
			} elseif ( 'quarantine' === $op && 'sizes' === $tab ) {
				$this->ajax_quar_sizes();
			} elseif ( 'quarantine' === $op && 'fs-sizes' === $tab ) {
				$this->ajax_quar_fs_sizes();
			} elseif ( 'quarantine' === $op && 'dbcleaner' === $tab ) {
				$this->ajax_quarantine_dbcleaner();
			} elseif ( 'quarantine' === $op ) {
				$this->ajax_quarantine( $tab );
			} elseif ( 'trash_dup' === $op ) {
				$this->ajax_trash_dup();
			} elseif ( 'scan' === $op && 'sizes' === $tab ) {
				$this->ajax_scan_sizes();
			} elseif ( 'scan' === $op && 'webpstats' === $tab ) {
				$this->ajax_scan_webpstats();
			} elseif ( 'scan' === $op && 'fs-sizes' === $tab ) {
				$this->ajax_scan_fs_sizes();
			} elseif ( 'scan' === $op && 'duplicates' === $tab ) {
				$this->ajax_scan_duplicates();
			} elseif ( 'scan' === $op && 'shared' === $tab ) {
				$this->ajax_scan_shared();
			} elseif ( 'scan' === $op && 'dupcontent' === $tab ) {
				$this->ajax_scan_dupcontent();
			} elseif ( 'merge' === $op ) {
				$this->ajax_merge_dup();
			} elseif ( 'preview' === $op ) {
				$this->ajax_preview();
			} elseif ( 'rename' === $op ) {
				$this->ajax_rename();
			} elseif ( 'webp_preview' === $op ) {
				$this->ajax_webp_preview();
			} elseif ( 'webp_preview_clear' === $op ) {
				$this->ajax_webp_preview_clear();
			} elseif ( 'list_folders' === $op ) {
				$this->ajax_list_folders();
			} elseif ( 'save_page_settings' === $op ) {
				$this->ajax_save_page_settings();
			} elseif ( 'inventory' === $op ) {
				$this->ajax_inventory();
			} elseif ( 'lazy_refs' === $op ) {
				$this->ajax_lazy_refs();
			} elseif ( 'email_emergency_restore' === $op ) {
				$this->ajax_email_emergency_restore();
			} elseif ( 'backup_restore_key' === $op ) {
				wp_send_json_error( array( 'message' => __( 'Web-accessible emergency restore is unavailable.', 'lucidlab-mpc-cleanup' ) ), 410 );
			} elseif ( 'convert' === $op ) {
				$this->ajax_convert_webp();
			} elseif ( 'webpscan' === $op ) {
				$this->ajax_webp_scan();
			} elseif ( 'webpclean' === $op ) {
				$this->ajax_webp_clean();
			} elseif ( 'webpprune' === $op && LucidLab_MPC_Edition::pro() ) {
				$this->ajax_webp_prune();
			} elseif ( 'load_scan' === $op ) {
				$stored = $this->get_scan_result( $tab );
				if ( ! is_array( $stored ) && 'fs-sizes' === $tab && isset( $this->fs_sizes ) ) {
					$stored = $this->fs_sizes->result_from_index( $this->uid() );
					if ( empty( $stored['dimensions'] ) ) {
						$stored = null;
					}
				}
				if ( ! is_array( $stored ) ) {
					wp_send_json_error( array( 'message' => __( 'No saved results. Run a new scan.', 'lucidlab-mpc-cleanup' ) ), 404 );
				}
				wp_send_json_success( $stored );
			} elseif ( 'purge_missing' === $op ) {
				$notices  = $this->op_purge_missing();
				$messages = array();
				$ok       = 0;
				$fail     = 0;
				foreach ( $notices as $notice ) {
					$messages[] = (string) ( $notice[1] ?? '' );
					if ( ( $notice[0] ?? '' ) === 'success' ) {
						++$ok;
					} elseif ( in_array( (string) ( $notice[0] ?? '' ), array( 'error', 'warning' ), true ) ) {
						++$fail;
					}
				}
				$payload = array(
					'ok'       => $ok,
					'fail'     => $fail,
					'label'    => trim( implode( ' ', array_filter( $messages ) ) ),
					'image_id' => sanitize_text_field( wp_unslash( $this->req['lucidlab_mpc_image_id'] ?? '' ) ),
				);
				if ( $ok < 1 ) {
					wp_send_json_error( array( 'message' => ( $payload['label'] ? $payload['label'] : __( 'WordPress could not delete the media record.', 'lucidlab-mpc-cleanup' ) ) ), 400 );
				}
				wp_send_json_success( $payload );
			} elseif ( 'regen' === $op ) {
				$this->ajax_regen();
			} elseif ( 'backup' === $op ) {
				$this->ajax_backup();
			} elseif ( 'core_check' === $op ) {
				$this->ajax_core_check();
			}
			wp_send_json_error( array( 'message' => 'bad-request' ), 400 );
	}

	/**
	 * Special job states.
	 *
	 * @return array
	 */
	public function special_job_states(): array {
		$jobs = array();
		$ai   = ( class_exists( 'LucidLab_MPC_AI_Job', false ) && method_exists( 'LucidLab_MPC_AI_Job', 'snapshot' ) ) ? LucidLab_MPC_AI_Job::snapshot() : array();
		if ( ! empty( $ai ) && in_array( $ai['status'] ?? '', array( 'running', 'paused' ), true ) ) {
			$total  = (int) ( $ai['total'] ?? 0 );
			$jobs[] = array_merge(
				$ai,
				array(
					'key'      => 'lucidlab_mpc_ai_library',
					'panel'    => 'rename_ai',
					'scan_tab' => 'rename_ai',
					'special'  => 'ai',
					'progress' => $total > 0 ? min( 100, 100 * (int) ( $ai['processed'] ?? 0 ) / $total ) : (int) ( $ai['progress'] ?? 0 ),
					'message'  => (string) ( $ai['label'] ?? '' ),
					'label'    => (string) ( $ai['label'] ?? __( 'AI library analysis', 'lucidlab-mpc-cleanup' ) ),
				)
			);
		}
		if ( isset( $this->regen ) ) {
			$regen = $this->regen->job();
			if ( ( $regen['status'] ?? 'idle' ) !== 'idle' ) {
				$regen_total = (int) ( $regen['total'] ?? 0 );
				$regen_done  = max( (int) ( $regen['offset'] ?? 0 ), (int) ( $regen['done'] ?? 0 ) );
				$jobs[]      = array_merge(
					$regen,
					array(
						'key'       => 'lucidlab_mpc_regen_job',
						'panel'     => 'regenerate',
						'scan_tab'  => 'regenerate',
						'special'   => 'regen',
						'label'     => sprintf(
							/* translators: 1: processed count, 2: total count */
							__( 'Regenerate thumbnails — %1$d/%2$d images', 'lucidlab-mpc-cleanup' ),
							$regen_done,
							$regen_total
						),
						'progress'  => (float) ( $regen['progress'] ?? 0 ),
						'can_retry' => in_array( $regen['status'] ?? '', array( 'done', 'failed' ), true ) && (int) ( $regen['failed'] ?? 0 ) > 0,
					)
				);
			}
		}
		if ( class_exists( 'LucidLab_MPC_Backup' ) ) {
			$backup = LucidLab_MPC_Backup::job();
			if ( in_array( $backup['status'] ?? '', array( 'running', 'paused' ), true ) ) {
				$pub    = LucidLab_MPC_Backup::public_job( $backup );
				$jobs[] = array_merge(
					$pub,
					array(
						'key'      => 'lucidlab_mpc_backup_job',
						'panel'    => 'backup',
						'scan_tab' => 'backup',
						'special'  => 'backup',
						'label'    => (string) ( $pub['label'] ?? __( 'Backup', 'lucidlab-mpc-cleanup' ) ),
					)
				);
			}
		}
		if ( isset( $this->rename ) ) {
			$rename = $this->rename->job();
			if ( ( $rename['status'] ?? 'idle' ) !== 'idle' ) {
				$rename_total  = (int) ( $rename['total'] ?? 0 );
				$rename_offset = (int) ( $rename['offset'] ?? 0 );
				$jobs[]        = array_merge(
					$rename,
					array(
						'key'      => 'lucidlab_mpc_rename_job',
						'panel'    => 'rename',
						'scan_tab' => 'rename',
						'special'  => 'rename',
						'label'    => sprintf( 'Renaming: %1$d/%2$d processed. %3$d renamed, %4$d skipped, %5$d failed', $rename_offset, $rename_total, (int) ( $rename['renamed'] ?? 0 ), (int) ( $rename['skipped'] ?? 0 ), (int) ( $rename['failed'] ?? 0 ) ),
						'progress' => $rename_total > 0 ? min( 100, 100 * $rename_offset / $rename_total ) : ( in_array( $rename['status'] ?? '', array( 'done', 'failed', 'cancelled' ), true ) ? 100 : 0 ),
					)
				);
			}
		}
		if ( isset( $this->webp ) && method_exists( $this->webp, 'job' ) ) {
			if ( method_exists( $this->webp, 'recover_stale' ) ) {
				// A conversion whose worker is gone must not block other jobs (and Run All) forever.
				$this->webp->recover_stale();
			}
			$webp = (array) $this->webp->job();
			if ( ( $webp['status'] ?? 'idle' ) !== 'idle' ) {
				$jobs[] = array_merge(
					$webp,
					array(
						'key'       => 'lucidlab_mpc_webp_job',
						'panel'     => 'webp',
						'scan_tab'  => 'webp',
						'special'   => 'webp',
						'label'     => (string) ( $webp['label'] ?? LucidLab_MPC_Webp::conversion_progress_label( $webp ) ),
						'progress'  => (float) ( $webp['progress'] ?? LucidLab_MPC_Webp::conversion_progress_percent( $webp ) ),
						'can_retry' => in_array( $webp['status'] ?? '', array( 'done', 'failed' ), true ) && (int) ( $webp['failed'] ?? 0 ) > 0,
					)
				);
			}
		}
		return $jobs;
	}

	/**
	 * Running task jobs.
	 *
	 * @return array
	 */
	public function running_task_jobs(): array {
		$owner = get_current_user_id();
		if ( ! $owner ) {
			return array();
		}
		$jobs = array();
		$keys = array_map( 'sanitize_key', (array) get_user_meta( $owner, 'lucidlab_mpc_task_keys', true ) );
		if ( class_exists( 'LucidLab_MPC_Task_Job' ) ) {
			$keys = array_merge( $keys, ( new LucidLab_MPC_Task_Job( $this ) )->active_saved_keys( $owner ) );
		}
		foreach ( array_values( array_unique( array_filter( $keys ) ) ) as $key ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$j = get_option( 'lucidlab_mpc_task_' . $owner . '_' . $key );
			if ( ! is_array( $j ) || ! in_array( $j['status'] ?? '', array( 'running', 'paused', 'queued' ), true ) ) {
				continue;
			}
			$jobs[] = array(
				'key'     => (string) ( $j['key'] ?? $key ),
				'status'  => (string) $j['status'],
				'label'   => (string) ( $j['label'] ?? '' ),
				'op'      => (string) ( $j['op'] ?? ( $j['request']['op'] ?? '' ) ),
				'special' => '',
			);
		}
		return $jobs;
	}

	/**
	 * Single-job gate: Free always one active job; Pro allows parallel except when Run All is involved. Restore is intentionally not exempt — it moves files and recreates attachments, which races with quarantine / Run All / uploads walks and clears shared scan caches.
	 *
	 * @param string                               $ignore_special Ignore special.
	 * @param bool                                 $run_all        Run all.
	 * @param array{op?:string,sub_action?:string} $incoming       Job being started (for clearer errors).
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	public function assert_can_start_job( string $ignore_special = '', bool $run_all = false, array $incoming = array() ): void {
		self::hold_slot_lock();
		$active = array();
		foreach ( array_merge( $this->running_task_jobs(), $this->special_job_states() ) as $job ) {
			if ( ! in_array( $job['status'] ?? '', array( 'running', 'paused' ), true ) ) {
				continue;
			}
			if ( '' !== $ignore_special && ( $job['special'] ?? '' ) === $ignore_special ) {
				continue;
			}
			$active[] = $job;
		}
		// Jobs started by another admin also hold a slot.
		if ( ! $active && 0 === self::running_task_jobs_sitewide() ) {
			return;
		}
		$has_run_all = false;
		foreach ( $active as $job ) {
			$key = (string) ( $job['key'] ?? '' );
			if ( ( $job['op'] ?? '' ) === 'run_all' || str_starts_with( $key, 'run_all' ) ) {
				$has_run_all = true;
				break;
			}
		}
		if ( LucidLab_MPC_Edition::parallel_jobs() && ! $run_all && ! $has_run_all ) {
			$max     = LucidLab_MPC_Edition::max_concurrent_jobs();
			$running = self::running_task_jobs_sitewide() + $this->running_job_count( array_filter( $this->special_job_states(), static fn( $j ) => '' === $ignore_special || ( $j['special'] ?? '' ) !== $ignore_special ) );
			if ( $running < $max ) {
				return;
			}
			throw new RuntimeException(
				sprintf(
					/* translators: %d: maximum number of jobs that may run at the same time. */
					__( 'Pro runs at most %d jobs at the same time. Wait until a running job finishes, or stop one under Current Running Jobs, then try again.', 'lucidlab-mpc-cleanup' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
					$max // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				),
				409
			);
		}
		$first = $active[0] ?? array();
		$label = trim( (string) ( $first['label'] ?? '' ) );
		if ( '' === $label ) {
			$label = 'a job';
		}
		$sub        = sanitize_key( (string) ( $incoming['sub_action'] ?? '' ) );
		$is_restore = ( (string) ( $incoming['op'] ?? '' ) === 'form' && in_array( $sub, array( 'restore_quarantine', 'restore_one' ), true ) )
			|| in_array( sanitize_key( (string) ( $incoming['op'] ?? '' ) ), array( 'restore_quarantine', 'restore_one' ), true );
		if ( $is_restore ) {
			throw new RuntimeException(
				sprintf( /* translators: %s: name of the running job. */ __( 'Cannot restore while "%s" is still running. Cancel that job under Current Running Jobs (or wait until it finishes), then try Restore again.', 'lucidlab-mpc-cleanup' ), $label ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			);
		}
		throw new RuntimeException( sprintf( /* translators: %s: name of the pending job. */ __( 'Another cleanup job is pending: %s. Finish or cancel it under Current Running Jobs before starting another cleanup.', 'lucidlab-mpc-cleanup' ), $label ), 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
	}

	/**
	 * Running task jobs of all users, read straight from the database so a cached copy cannot hide one.
	 *
	 * @param string $except Option name of the job asking for a slot (not counted).
	 *
	 * @return int
	 */
	public static function running_task_jobs_sitewide( string $except = '' ): int {
		global $wpdb;
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'lucidlab_mpc_task_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$count = 0;
		foreach ( $rows as $row ) {
			if ( (string) $row->option_name === $except || ! str_contains( (string) $row->option_value, 'running' ) ) {
				continue;
			}
			$job = maybe_unserialize( $row->option_value );
			if ( is_array( $job ) && isset( $job['key'] ) && 'running' === ( $job['status'] ?? '' ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Serialise job-slot decisions: two Start clicks (or a start and a queue promotion) must not both see a free slot.
	 * MySQL keeps the named lock until this request ends, after the new job has been saved.
	 */
	public static function hold_slot_lock(): void {
		global $wpdb;
		static $held = false;
		if ( $held ) {
			return;
		}
		$held = true;
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', 'lucidlab_mpc_slots_' . md5( $wpdb->prefix ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
	}

	/**
	 * Count jobs that are actually running. Paused and queued jobs do not hold a slot.
	 *
	 * @param array|null $jobs Jobs to count, or null to read the saved jobs.
	 *
	 * @return int
	 */
	public function running_job_count( ?array $jobs = null ): int {
		if ( null === $jobs ) {
			// Every job type holds a slot: scans and cleanups of every admin, plus backup, regenerate, WebP, AI and rename.
			return self::running_task_jobs_sitewide() + $this->running_job_count( $this->special_job_states() );
		}
		$count = 0;
		foreach ( $jobs as $job ) {
			if ( 'running' === ( $job['status'] ?? '' ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Whether a new Pro task job should wait in the queue instead of starting now.
	 *
	 * @param array $incoming Job being started.
	 *
	 * @return bool
	 */
	public function job_should_queue( array $incoming ): bool {
		self::hold_slot_lock();
		if ( ! LucidLab_MPC_Edition::parallel_jobs() || ( $incoming['op'] ?? '' ) === 'run_all' ) {
			return false;
		}
		$jobs = array_merge( $this->running_task_jobs(), $this->special_job_states() );
		foreach ( $jobs as $job ) {
			$key = (string) ( $job['key'] ?? '' );
			if ( in_array( $job['status'] ?? '', array( 'running', 'paused', 'queued' ), true ) && ( ( $job['op'] ?? '' ) === 'run_all' || str_starts_with( $key, 'run_all' ) ) ) {
				return false;
			}
		}
		return $this->running_job_count( $jobs ) >= LucidLab_MPC_Edition::max_concurrent_jobs();
	}

	/**
	 * Assert job slot.
	 *
	 * @param string $ignore_special Ignore special.
	 * @param bool   $run_all        Run all.
	 */
	public static function assert_job_slot( string $ignore_special = '', bool $run_all = false ): void {
		if ( null === self::$instance ) {
			return;
		}
		self::$instance->assert_can_start_job( $ignore_special, $run_all );
	}

	/**
	 * Task capture.
	 *
	 * @var bool
	 */
	private bool $task_capture = false;

	/**
	 * Request parameters (slashed, as received) for the current verified request or saved task.
	 *
	 * Filled only after the nonce and capability checks pass, or with the explicit parameters
	 * of a saved task. Handlers sanitize each value they read. The $_POST superglobal itself
	 * is never modified.
	 *
	 * @var array<string,mixed>
	 */
	private array $req = array();

	/**
	 * Whether the current HTTP request passed a nonce check in a router.
	 *
	 * @var bool
	 */
	private bool $request_verified = false;

	/**
	 * Accept the posted parameters after the router has verified the nonce and capability.
	 */
	private function accept_request(): void {
		$this->request_verified = true;
		$this->req              = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only directly after check_ajax_referer()/check_admin_referer(); each value is unslashed and sanitized where it is read.
	}

	/**
	 * Edition-gate parameters taken from the current request.
	 *
	 * @param array<string,string> $extra Values that override the request.
	 * @return array<string,string>
	 */
	private function edition_params( array $extra = array() ): array {
		$out = array();
		foreach ( array( 'action', 'op', 'preview', 'sub', 'sub_action', 'tab' ) as $key ) {
			if ( isset( $this->req[ $key ] ) && is_scalar( $this->req[ $key ] ) ) {
				$out[ $key ] = sanitize_key( wp_unslash( (string) $this->req[ $key ] ) );
			}
		}
		return array_merge( $out, array_map( 'sanitize_key', $extra ) );
	}

	/**
	 * Verify that a saved task may run: the acting user must manage options, and an HTTP
	 * request must already have passed a router nonce check. Cron and WP-CLI run saved jobs
	 * as their owner, set by LucidLab_MPC_Task_Job before dispatch.
	 *
	 * @throws RuntimeException When the caller is not allowed.
	 */
	private function assert_task_allowed(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new RuntimeException( esc_html__( 'Permission denied.', 'lucidlab-mpc-cleanup' ) );
		}
		$background = wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
		if ( ! $background && ! $this->request_verified ) {
			throw new RuntimeException( esc_html__( 'The request could not be verified. Reload the page and try again.', 'lucidlab-mpc-cleanup' ) );
		}
	}
	/**
	 * Task success.
	 *
	 * @param mixed $data   Data.
	 * @param mixed $status Status.
	 *
	 * @throws LucidLab_MPC_Task_Response When the operation fails.
	 */
	private function task_success( $data, $status = null ): void {
		if ( $this->task_capture ) {
			throw new LucidLab_MPC_Task_Response( (array) $data ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		wp_send_json_success( $data, $status );
	}
	/**
	 * Task error.
	 *
	 * @param mixed $data   Data.
	 * @param mixed $status Status.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	private function task_error( $data, $status = null ): void {
		if ( $this->task_capture ) {
			throw new RuntimeException( $data['message'] ?? __( 'Action failed', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		wp_send_json_error( $data, $status );
	}

	/**
	 * Prepare task.
	 *
	 * @param array $input Input.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public function prepare_task( array $input ): array {
		LucidLab_MPC_Edition::check( $input );
		if ( ( $input['op'] ?? '' ) === 'scan' && ( $input['tab'] ?? '' ) === 'crawl' ) {
			LucidLab_MPC_Crawler::reset( $this->uid() );
		}
		if ( ( $input['op'] ?? '' ) !== 'form' ) {
			return $input;
		}
		$action   = $input['sub_action'] ?? '';
		$commands = array();
		$set      = sanitize_file_name( $input['lucidlab_mpc_set'] ?? '' );
		$source   = sanitize_key( $input['lucidlab_mpc_source'] ?? '' );
		if ( ! $source && $set && 'ALL' !== $set ) {
			$source = $this->quar->set_source( $set );
		}
		$input['lucidlab_mpc_source'] = $source;
		if ( ! LucidLab_MPC_Edition::pro() && in_array( $action, array( 'purge_quarantine', 'purge_source' ), true ) ) {
			foreach ( $this->quar->list_sets() as $row ) {
				$matches = 'ALL' === $set || ( 'purge_source' === $action ? (string) ( $row['source'] ?? '' ) === $source : (string) ( $row['stamp'] ?? '' ) === $set );
				if ( $matches && LucidLab_MPC_Edition::pro_quarantine_source( (string) ( $row['source'] ?? '' ) ) ) {
					throw new RuntimeException( __( 'This quarantine source requires the Pro add-on. Restore remains available.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
				}
			}
		}
		if ( 'restore_quarantine' === $action ) {
			if ( ! $set ) {
				throw new RuntimeException( __( 'Missing quarantine set', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			// Large Uploads Scanner sets can hold 10k+ files — never expand every path into the job payload.
			$total = $this->quar->count_items( $set );
			if ( $total < 1 ) {
				throw new RuntimeException( __( 'No items remain for this action. Refresh the list.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$input['restore_total'] = $total;
			$commands[]             = array(
				'action'           => 'restore_quarantine',
				'lucidlab_mpc_set' => $set,
			);
		} elseif ( 'purge_source' === $action || ( 'purge_quarantine' === $action && 'ALL' === $set ) ) {
			foreach ( $this->quar->list_sets() as $row ) {
				if ( 'purge_source' === $action && ( $row['source'] ?? '' ) !== ( $input['lucidlab_mpc_source'] ?? '' ) ) {
					continue;
				}
				$commands[] = array(
					'action'           => 'purge_quarantine',
					'lucidlab_mpc_set' => $row['stamp'],
				);
			}
		} elseif ( in_array( $action, array( 'restore_one', 'purge_quarantine', 'undo_merge', 'clean_empty' ), true ) ) {
			$commands[] = array(
				'action'            => $action,
				'lucidlab_mpc_set'  => $set,
				'lucidlab_mpc_type' => sanitize_key( $input['lucidlab_mpc_type'] ?? 'file' ),
				'lucidlab_mpc_key'  => $input['lucidlab_mpc_key'] ?? '',
			);
		} else {
			throw new RuntimeException( __( 'Unsupported form job', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		if ( ! $commands ) {
			throw new RuntimeException( __( 'No items remain for this action. Refresh the list.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}
		$input['commands'] = wp_json_encode( $commands );
		return $input;
	}

	/**
	 * Internal worker dispatch for saved jobs only. HTTP requests enter through handle_ajax(), which checks manage_options and the AJAX nonce before a task can be created or stepped. LucidLab_MPC_Task_Job validates the owner and operation again before calling this method; cron replays saved jobs.
	 *
	 * @param array $params Params.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array
	 */
	public function execute_task( array $params ): array {
		$this->assert_task_allowed();
		LucidLab_MPC_Edition::check( $params );
		$profile        = null;
		$profile_failed = true;
		$profile_phase  = 'batch';
		$profile_tab    = (string) ( $params['tab'] ?? '' );
		if ( ( $params['op'] ?? '' ) === 'scan' || ( $params['op'] ?? '' ) === 'webpscan' ) {
			$state         = get_transient( $this->k_state( (string) ( $params['tab'] ?? '' ) ) );
			$profile_phase = ! empty( $params['reset'] ) ? 'start' : (string) ( $state['refs']['phase'] ?? $state['ref_phase'] ?? $state['phase'] ?? 'batch' );
			if ( ( $params['op'] ?? '' ) === 'webpscan' ) {
				$profile_tab   = 'webpstats';
				$profile_phase = 'scan';}
			$profile = LucidLab_MPC_Scan_Profile::start();
		}
		$old                = $this->req;
		$this->req          = wp_slash( $params );
		$this->task_capture = true;
		try {
			$op  = $params['op'] ?? '';
			$tab = $params['tab'] ?? '';
			if ( 'scan' === $op ) {
				$methods = array(
					'uploads'      => 'ajax_scan_uploads',
					'orphans'      => 'ajax_scan_orphans',
					'sizes'        => 'ajax_scan_sizes',
					'fs-sizes'     => 'ajax_scan_fs_sizes',
					'webpstats'    => 'ajax_scan_webpstats',
					'duplicates'   => 'ajax_scan_duplicates',
					'shared'       => 'ajax_scan_shared',
					'visual'       => 'ajax_scan_visual',
					'dupcontent'   => 'ajax_scan_dupcontent',
					'missing'      => 'ajax_scan_missing',
					'crawl'        => 'ajax_scan_crawl',
					'product_copy' => 'ajax_scan_product_copy',
					'dbcleaner'    => 'ajax_scan_dbcleaner',
				);
				if ( ! isset( $methods[ $tab ] ) ) {
					throw new RuntimeException( __( 'Unknown scan', 'lucidlab-mpc-cleanup' ) ); }
				$this->{$methods[ $tab ]}();
			} elseif ( 'quarantine' === $op ) {
				if ( ! in_array( $tab, array( 'orphans', 'uploads', 'shared', 'sizes', 'fs-sizes', 'dbcleaner' ), true ) ) {
					throw new RuntimeException( __( 'Unknown quarantine source', 'lucidlab-mpc-cleanup' ) ); }
				// Registered paths can change when attachments move. Content reference
				// generation is unchanged for unused-file quarantine, so keep lucidlab_mpc_cached_ref
				// so Run All (Orphans → Uploads) reuses the published index.
				delete_transient( 'lucidlab_mpc_cached_reg_v2' );
				if ( 'sizes' === $tab ) {
					$this->ajax_quar_sizes();
				} elseif ( 'fs-sizes' === $tab ) {
					$this->ajax_quar_fs_sizes();
				} elseif ( 'dbcleaner' === $tab ) {
					$this->ajax_quarantine_dbcleaner();
				} else {
					$this->ajax_quarantine( $tab );
				}
			} elseif ( 'merge' === $op ) {
				$this->ajax_merge_dup();
			} elseif ( 'trash_dup' === $op ) {
				$this->ajax_trash_dup();
			} elseif ( 'webpscan' === $op ) {
				$this->ajax_webp_scan();
			} elseif ( 'webpclean' === $op ) {
				$this->ajax_webp_clean();
			} elseif ( 'form' === $op ) {
				if ( ( $params['sub_action'] ?? '' ) === 'restore_quarantine' ) {
					$stamp = sanitize_file_name( (string) ( $params['lucidlab_mpc_set'] ?? '' ) );
					$r     = $this->quar->restore_slice( $stamp, 200, ! empty( $params['restore_retry_failed'] ) );
					if ( (int) ( $r['ok'] ?? 0 ) > 0 ) {
						$this->mark_scan_stale( 'orphans' );
						$source = $this->quar->set_source( $stamp );
						if ( in_array( $source, array( 'uploads', 'shared', 'missing', 'dupcontent', 'visual' ), true ) ) {
							$this->mark_scan_stale( $source );
						}
					}
					delete_transient( 'lucidlab_mpc_cached_reg_v2' );
					delete_transient( 'lucidlab_mpc_cached_ref' );
					$remaining = (int) ( $r['remaining'] ?? 0 );
					return array(
						'ok'        => (int) ( $r['ok'] ?? 0 ),
						'fail'      => (int) ( $r['fail'] ?? 0 ),
						'processed' => (int) ( $r['processed'] ?? 0 ),
						'remaining' => $remaining,
						'done'      => $remaining < 1,
						'label'     => sprintf( '%d restored', (int) ( $r['ok'] ?? 0 ) ),
					);
				}
				$commands = LucidLab_MPC_Helpers::sanitize_decoded( json_decode( (string) ( $params['commands'] ?? '[]' ), true ), array( 'list' => 'auto' ) );
				$c        = $commands[0] ?? array();
				LucidLab_MPC_Edition::check( array( 'op' => $c['action'] ?? '' ) );
				$restore_batch = is_array( $commands ) && count( $commands ) > 1 && ( $c['action'] ?? '' ) === 'restore_one';
				if ( $restore_batch ) {
					$items = array();
					foreach ( $commands as $command ) {
						if ( ( $command['action'] ?? '' ) !== 'restore_one' ) {
							continue; }
						$items[] = array(
							'type' => sanitize_key( $command['lucidlab_mpc_type'] ?? 'file' ),
							'key'  => (string) ( $command['lucidlab_mpc_key'] ?? '' ),
						);
					}
					$r = $this->quar->restore_many( sanitize_file_name( $c['lucidlab_mpc_set'] ?? '' ), $items );
					if ( (int) ( $r['ok'] ?? 0 ) > 0 ) {
						$this->mark_scan_stale( 'orphans' );
						$source = $this->quar->set_source( sanitize_file_name( $c['lucidlab_mpc_set'] ?? '' ) );
						if ( in_array( $source, array( 'uploads', 'shared', 'missing', 'dupcontent', 'visual' ), true ) ) {
							$this->mark_scan_stale( $source );
						}
					}
					delete_transient( 'lucidlab_mpc_cached_reg_v2' );
					delete_transient( 'lucidlab_mpc_cached_ref' );
					return array(
						'ok'    => (int) ( $r['ok'] ?? 0 ),
						'fail'  => (int) ( $r['fail'] ?? 0 ),
						'label' => sprintf( '%d restored', (int) ( $r['ok'] ?? 0 ) ),
					);
				}
				$this->req = wp_slash( $c );
				switch ( $c['action'] ?? '' ) {
					case 'restore_one':
						$notices = $this->op_restore_one();
						break;
					case 'purge_quarantine':
						$notices = $this->op_purge();
						break;
					case 'undo_merge':
						$notices = $this->op_undo_merge();
						break;
					case 'clean_empty':
						$n       = LucidLab_MPC_Helpers::remove_empty_dirs( $this->quar->root() );
						$notices = array( array( 'success', sprintf( /* translators: %s: number of folders. */ _n( '%s empty folder removed.', '%s empty folders removed.', (int) $n, 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $n ) ) ) );
						break;
					default:
						throw new RuntimeException( __( 'Invalid form action', 'lucidlab-mpc-cleanup' ) );
				}
				delete_transient( 'lucidlab_mpc_cached_reg_v2' );
				delete_transient( 'lucidlab_mpc_cached_ref' );
				$fail = 0;
				foreach ( $notices as $notice ) {
					if ( 'error' === $notice[0] || 'warning' === $notice[0] ) {
						++$fail;
					}
				}
				return array(
					'ok'    => $fail ? 0 : 1,
					'fail'  => $fail,
					'label' => implode( ' ', array_column( $notices, 1 ) ),
				);
			} elseif ( 'run_all_items' === $op ) {
				if ( 'sizes' === $tab ) {
					return array( 'items' => array_values( (array) LucidLab_MPC_Settings::get( 'disabled_sizes' ) ) );
				}
				$r = $this->get_scan_result( $tab );
				if ( ! is_array( $r ) ) {
					throw new RuntimeException( __( 'Scan results expired; rescan first.', 'lucidlab-mpc-cleanup' ) );
				}
				$items = array();
				if ( 'orphans' === $tab ) {
					// Honour the same list the Orphans tab shows — never invent IDs when the scan found none.
					if ( ! empty( $r['orphan_count'] ) || ! empty( $r['orphans'] ) ) {
						$items = array_values( array_filter( array_map( 'intval', wp_list_pluck( (array) ( $r['orphans'] ?? array() ), 'id' ) ) ) );
					}
				} elseif ( 'uploads' === $tab ) {
					foreach ( (array) ( $r['orphan_units'] ?? array() ) as $unit ) {
						foreach ( (array) ( $unit['files'] ?? array() ) as $file ) {
							if ( ! empty( $file['rel'] ) ) {
								$items[] = $file['rel'];
							}
						}
					}
				}
				return array( 'items' => array_values( array_unique( $items ) ) );
			} else {
				throw new RuntimeException( __( 'Unsupported job action', 'lucidlab-mpc-cleanup' ) ); }
			throw new RuntimeException( __( 'Action returned no result', 'lucidlab-mpc-cleanup' ) );
		} catch ( LucidLab_MPC_Task_Response $r ) {
			$profile_failed = false;
			return $r->data; } finally {
			$this->req          = $old;
			$this->task_capture = false;
			if ( null !== $profile ) {
				try {
					LucidLab_MPC_Scan_Profile::finish( $profile_tab, $profile_phase, $profile, $profile_failed );} catch ( Throwable $ignored ) {
					// Profiling is diagnostic only and must never break the task response.
					unset( $ignored );
					/* Measurements must never interrupt a scan. */}
			}
			}
	}

	/**
	 * Scan progress lifetime. A transient without expiry is autoloaded on every page, so background jobs keep theirs for 30 days instead of forever.
	 *
	 * @return int
	 */
	private function state_ttl(): int {
		return $this->task_capture ? MONTH_IN_SECONDS : HOUR_IN_SECONDS;
	}

	/**
	 * Earlier versions saved background scan progress without expiry, which WordPress loads on every page.
	 */
	public static function stop_autoloading_scan_state(): void {
		if ( (int) get_option( 'lucidlab_mpc_state_autoload_fix', 0 ) >= 3 ) {
			return;
		}
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->options} SET autoload='no' WHERE option_name LIKE '\\_transient\\_lucidlab\\_mpc\\_%' AND autoload IN ('yes','on','auto','auto-on')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		wp_cache_delete( 'alloptions', 'options' );
		$names = $wpdb->get_col( "SELECT SUBSTRING(o.option_name, 12) FROM {$wpdb->options} o LEFT JOIN {$wpdb->options} t ON t.option_name = CONCAT('_transient_timeout_', SUBSTRING(o.option_name, 12)) WHERE o.option_name LIKE '\\_transient\\_lucidlab\\_mpc\\_%' AND o.option_name NOT LIKE '\\_transient\\_lucidlab\\_mpc\\_result\\_%' AND t.option_id IS NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		foreach ( $names as $name ) {
			add_option( '_transient_timeout_' . $name, time() + MONTH_IN_SECONDS, '', false );
		}
		update_option( 'lucidlab_mpc_state_autoload_fix', 3, false );
	}

	/**
	 * Reset scan.
	 *
	 * @param string $tab Tab.
	 */
	private function reset_scan( string $tab ): void {
		if ( 'uploads' === $tab ) {
			$previous = get_transient( $this->k_state( $tab ) );
			LucidLab_MPC_Directory_Inventory::discard( (string) ( $previous['inventory_token'] ?? '' ) );
		}
		delete_transient( $this->k_state( $tab ) );
		$this->delete_scan_result( $tab );
		if ( 'uploads' === $tab ) {
			delete_transient( $this->k_reg() );
			LucidLab_MPC_Uploads::clear_scan_files( $this->uid() );
		} else {
			delete_transient( $this->k_ref() );
		}
		if ( 'orphans' === $tab ) {
			LucidLab_MPC_Work::delete( $this->orphan_ref_file(), $this->orphan_found_file() );
		}
	}

	/**
	 * Orphan ref file.
	 *
	 * @return string
	 */
	private function orphan_ref_file(): string {
		return 'orphans-ref-' . $this->uid() . '.jsonl';
	}

	/**
	 * Orphan found file.
	 *
	 * @return string
	 */
	private function orphan_found_file(): string {
		return 'orphans-found-' . $this->uid() . '.jsonl';
	}

	/**
	 * Append newly found references; the full map is only rebuilt when images are checked.
	 *
	 * @param array $ids Ids.
	 * @param array $bn  Bn.
	 */
	private function orphan_ref_append( array $ids, array $bn ): void {
		if ( $ids || $bn ) {
			LucidLab_MPC_Work::append(
				$this->orphan_ref_file(),
				array(
					array(
						'i' => array_map( 'intval', array_keys( $ids ) ),
						'b' => array_map( 'strval', array_keys( $bn ) ),
					),
				)
			);
		}
	}

	/**
	 * AJAX handler: scan orphans.
	 */
	private function ajax_scan_orphans(): void {
		$skey = $this->k_state( 'orphans' );

		if ( ! empty( $this->req['reset'] ) ) {
			$this->reset_scan( 'orphans' );
			$state = array(
				'phase'     => 'refs',
				'refs'      => LucidLab_MPC_Reference_Index::begin( $this->orphan_ref_file() ),
				'offset'    => 0,
				'found'     => 0,
				'protected' => 0,
				'scanned'   => 0,
				'bytes'     => 0,
				'total'     => $this->orphans->total_image_attachments(),
			);
			if ( 'done' === $state['refs']['phase'] ) {
				$state['phase'] = 'scan';
				unset( $state['refs'] );
				set_transient( $skey, $state, $this->state_ttl() );
				$this->task_success(
					array(
						'progress' => 0,
						'label'    => __( 'Reusing recent reference index; checking images…', 'lucidlab-mpc-cleanup' ),
						'done'     => false,
					)
				);
			}
			set_transient( $skey, $state, $this->state_ttl() );
			$start_label = ! empty( $state['refs']['patched'] ) ? __( 'Updating changed posts and terms…', 'lucidlab-mpc-cleanup' ) : __( 'Indexing references…', 'lucidlab-mpc-cleanup' );
			$this->task_success(
				array_merge(
					$this->reference_index_start( $state['refs'], 55 ),
					array(
						'progress'      => 0,
						'indeterminate' => true,
						'label'         => $start_label,
						'done'          => false,
					)
				)
			);
		}

		$state = get_transient( $skey );
		if ( ! is_array( $state ) ) {
			$this->task_error( array( 'message' => 'no-state' ), 400 );
		}
		$legacy_ref = get_transient( $this->k_ref() );
		if ( is_array( $legacy_ref ) ) {
			$this->orphan_ref_append( (array) ( $legacy_ref['ids'] ?? array() ), (array) ( $legacy_ref['bn'] ?? array() ) );
			delete_transient( $this->k_ref() );
		}

		// Scans saved before the shared reference index restart reference collection once.
		if ( in_array( $state['phase'], array( 'static', 'terms', 'posts', 'meta' ), true ) ) {
			$state['phase']  = 'refs';
			$state['refs']   = LucidLab_MPC_Reference_Index::begin( $this->orphan_ref_file() );
			$state['offset'] = 0;
			$state['cursor'] = 0;
		}

		if ( 'refs' === $state['phase'] ) {
			$ran = LucidLab_MPC_Reference_Index::label_phase( $state['refs'] );
			LucidLab_MPC_Reference_Index::pump( $state['refs'] );
			$assemble = 'done' === $state['refs']['phase'];
			$tick     = $this->reference_index_tick( $state['refs'], $ran, false, 50 );
			if ( $assemble ) {
				$state['phase'] = 'scan';
				unset( $state['refs'] );
				$tick['progress']      = 50;
				$tick['indeterminate'] = false;
				$tick['label']         = __( 'Checking images…', 'lucidlab-mpc-cleanup' );
			}
			set_transient( $skey, $state, $this->state_ttl() );
			$this->task_success( array_merge( $tick, array( 'done' => false ) ) );
		}

		// phase scan.
		$scan_batch          = (int) LucidLab_MPC_Settings::get( 'scan_batch_db' );
		$cursor              = (int) ( $state['cursor'] ?? 0 );
		$ref                 = LucidLab_MPC_Reference_Index::load( $this->orphan_ref_file() );
		$res                 = $this->orphans->scan_step( $ref['ids'], $ref['bn'], $cursor );
		$state['cursor']     = $cursor;
		$state['offset']     = (int) ( $state['offset'] ?? 0 ) + $res['count'];
		$state['scanned']   += $res['count'];
		$state['protected'] += $res['protected'];
		// Scans started before this version kept found orphans in the state.
		if ( ! empty( $state['orphans'] ) ) {
			LucidLab_MPC_Work::append( $this->orphan_found_file(), $state['orphans'] );
			$state['found'] = (int) ( $state['found'] ?? 0 ) + count( $state['orphans'] );
			unset( $state['orphans'] );
		}
		LucidLab_MPC_Work::append( $this->orphan_found_file(), $res['orphans'] );
		foreach ( $res['orphans'] as $o ) {
			$state['found']  = (int) ( $state['found'] ?? 0 ) + 1;
			$state['bytes'] += (int) $o['size'];
		}

		if ( $res['count'] < $scan_batch ) {
			$orphans = LucidLab_MPC_Work::lines( $this->orphan_found_file() );
			$result  = array(
				'type'          => 'orphans',
				'orphans'       => $orphans,
				'orphan_count'  => count( $orphans ),
				'protected'     => (int) $state['protected'],
				'scanned'       => (int) $state['scanned'],
				'reclaim_bytes' => (int) $state['bytes'],
				'acf_active'    => $this->orphans->acf_active(),
			);
			$this->set_scan_result( 'orphans', $result );
			$heavy = (array) get_option( LucidLab_MPC_Orphans::HEAVY_OPTION, array() );
			LucidLab_MPC_Logger::diag(
				'orphans',
				'Scan finished.',
				array(
					'checked'        => (int) $state['scanned'],
					'in_use'         => (int) $state['protected'],
					'orphans'        => count( $orphans ),
					'ref_ids'        => count( $ref['ids'] ),
					'ref_files'      => count( $ref['bn'] ),
					'heavy_settings' => implode( ', ', array_map( static fn( $k, $v ) => $k . '=' . $v, array_keys( $heavy ), $heavy ) ),
				),
				( 0 === count( $orphans ) && (int) $state['scanned'] > 100 ) ? 'warning' : 'info'
			);
			delete_transient( $skey );
			delete_transient( $this->k_ref() );
			LucidLab_MPC_Work::delete( $this->orphan_ref_file(), $this->orphan_found_file() );
			$this->task_success(
				array(
					'progress' => 100,
					'done'     => true,
				)
			);
		}

		set_transient( $skey, $state, $this->state_ttl() );
		$pct = $state['total'] > 0 ? (int) ( 100 * $state['scanned'] / $state['total'] ) : 0;
		$this->task_success(
			array(
				'progress' => min( 99, 50 + $pct / 2 ),
				/* translators: 1: images checked so far, 2: total images to check. */
				'label'    => sprintf( __( 'Checking images (%1$s/%2$s)…', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $state['scanned'] ), number_format_i18n( (int) $state['total'] ) ),
				'done'     => false,
			)
		);
	}

	/**
	 * Shared ref file.
	 *
	 * @return string
	 */
	private function shared_ref_file(): string {
		return 'shared-ref-' . $this->uid() . '.jsonl';
	}

	/**
	 * Missing ref file.
	 *
	 * @return string
	 */
	private function missing_ref_file(): string {
		return 'missing-ref-' . $this->uid() . '.jsonl';
	}

	/**
	 * Progress for one reference-index batch. $ran is the phase that just executed. Indexing fills 0–$share of the bar; the rest belongs to the step after it.
	 *
	 * @param array  $refs     Refs.
	 * @param string $ran      Ran.
	 * @param bool   $assemble Assemble.
	 * @param int    $share    Share.
	 *
	 * @return array
	 */
	private function reference_index_tick( array $refs, string $ran, bool $assemble, int $share = 90 ): array {
		if ( $assemble ) {
			return array(
				'progress' => $share,
				'label'    => __( 'Checking the indexed references…', 'lucidlab-mpc-cleanup' ),
			);
		}
		$read    = LucidLab_MPC_Reference_Index::progress( $refs );
		$records = number_format_i18n( (int) ( $refs['records'] ?? 0 ) );
		/* translators: %s is the formatted count of content records checked. */
		$indexing_content = __( 'Indexing content: %s records checked…', 'lucidlab-mpc-cleanup' );
		/* translators: %s is the formatted count of builder metadata records checked. */
		$indexing_meta = __( 'Indexing builder metadata: %s records checked…', 'lucidlab-mpc-cleanup' );
		/* translators: %s is the formatted count of settings records checked. */
		$indexing_options = __( 'Indexing settings: %s records checked…', 'lucidlab-mpc-cleanup' );
		/* translators: %s is the formatted count of changed posts checked. */
		$patch_posts = __( 'Updating changed posts: %s records checked…', 'lucidlab-mpc-cleanup' );
		/* translators: %s is the formatted count of changed fields checked. */
		$patch_meta = __( 'Updating changed fields: %s records checked…', 'lucidlab-mpc-cleanup' );
		/* translators: %s is the formatted count of changed taxonomy terms checked. */
		$patch_terms  = __( 'Updating changed terms: %s records checked…', 'lucidlab-mpc-cleanup' );
		$patch_static = __( 'Updating featured images and widgets…', 'lucidlab-mpc-cleanup' );
		// Shared Images and Missing Images used to park at 59% or 70% while this count kept rising.
		$labels = array(
			'static'       => __( 'Scanning page & product content…', 'lucidlab-mpc-cleanup' ),
			'terms'        => __( 'Checking category descriptions and fields…', 'lucidlab-mpc-cleanup' ),
			'posts'        => sprintf( $indexing_content, $records ),
			'meta'         => sprintf( $indexing_meta, $records ),
			'options'      => sprintf( $indexing_options, $records ),
			'patch-static' => $patch_static,
			'patch-posts'  => sprintf( $patch_posts, $records ),
			'patch-meta'   => sprintf( $patch_meta, $records ),
			'patch-terms'  => sprintf( $patch_terms, $records ),
		);
		return array(
			'progress'          => null === $read ? 0 : $share * $read / 100,
			'indeterminate'     => null === $read,
			'progress_estimate' => null !== $read && empty( $refs['patched'] ) && 'done' !== ( $refs['phase'] ?? '' ),
			'label'             => $labels[ $ran ] ?? __( 'Indexing references…', 'lucidlab-mpc-cleanup' ),
		);
	}

	/**
	 * First response of a scan that starts with the reference index.
	 *
	 * @param array $refs  Refs.
	 * @param int   $share Share.
	 *
	 * @return array
	 */
	private function reference_index_start( array $refs, int $share = 90 ): array {
		$read = LucidLab_MPC_Reference_Index::progress( $refs );
		return array(
			'progress'          => null === $read ? 0 : $share * $read / 100,
			'indeterminate'     => null === $read,
			'progress_estimate' => null !== $read && empty( $refs['patched'] ) && 'done' !== ( $refs['phase'] ?? '' ),
		);
	}

	/**
	 * AJAX handler: scan shared.
	 */
	private function ajax_scan_shared(): void {
		$skey = $this->k_state( 'shared' );
		$file = $this->shared_ref_file();
		if ( ! empty( $this->req['reset'] ) ) {
			delete_transient( $skey );
			$this->delete_scan_result( 'shared' );
			$refs  = LucidLab_MPC_Reference_Index::begin( $file );
			$state = array(
				'phase' => 'done' === ( $refs['phase'] ?? '' ) ? 'assemble' : 'refs',
				'refs'  => $refs,
			);
			if ( 'assemble' === $state['phase'] ) {
				unset( $state['refs'] );
			}
			set_transient( $skey, $state, $this->state_ttl() );
			$this->task_success(
				array_merge(
					$this->reference_index_start( $refs ),
					array(
						'label' => 'assemble' === $state['phase'] ? __( 'Reusing recent reference index…', 'lucidlab-mpc-cleanup' ) : ( ! empty( $refs['patched'] ) ? __( 'Updating changed posts and terms…', 'lucidlab-mpc-cleanup' ) : __( 'Indexing references…', 'lucidlab-mpc-cleanup' ) ),
						'done'  => false,
					)
				)
			);
		}
		$state = get_transient( $skey );
		if ( ! is_array( $state ) || empty( $state['phase'] ) ) {
			$this->task_error( array( 'message' => 'no-state' ), 400 );
		}
		if ( 'refs' === $state['phase'] ) {
			$ran = LucidLab_MPC_Reference_Index::label_phase( (array) ( $state['refs'] ?? array() ) );
			LucidLab_MPC_Reference_Index::pump( $state['refs'] );
			$assemble = 'done' === ( $state['refs']['phase'] ?? '' );
			if ( $assemble ) {
				$state['phase'] = 'assemble';
				unset( $state['refs'] );
			}
			set_transient( $skey, $state, $this->state_ttl() );
			$this->task_success( array_merge( $this->reference_index_tick( (array) ( $state['refs'] ?? array() ), $ran, $assemble ), array( 'done' => false ) ) );
		}
		$result = $this->shared->from_reference( $file, true, false );
		$this->set_scan_result( 'shared', $result );
		delete_transient( $skey );
		LucidLab_MPC_Work::delete( $file );
		$this->task_success(
			array(
				'progress'         => 100,
				'done'             => true,
				'result_available' => true,
			)
		);
	}

	/**
	 * AJAX handler: scan dupcontent.
	 */
	private function ajax_scan_dupcontent(): void {
		$skey = $this->k_state( 'dupcontent' );
		$mkey = 'lucidlab_mpc_dupmap_' . $this->uid();
		if ( ! empty( $this->req['reset'] ) ) {
			delete_transient( $skey );
			$this->delete_scan_result( 'dupcontent' );
			set_transient( $mkey, array(), $this->state_ttl() );
			set_transient(
				$skey,
				array(
					'offset' => 0,
					'cursor' => 0,
					'total'  => $this->dupcontent->total_images(),
				),
				$this->state_ttl()
			);
			$this->task_success(
				array(
					'progress' => 3,
					'label'    => __( 'Hashing images…', 'lucidlab-mpc-cleanup' ),
					'done'     => false,
				)
			);
		}
		$state = get_transient( $skey );
		if ( ! is_array( $state ) ) {
			$this->task_error( array( 'message' => 'no-state' ), 400 );
		}
		$map = get_transient( $mkey );
		$map = is_array( $map ) ? $map : array();

		$n                = $this->dupcontent->hash_step( (int) ( $state['cursor'] ?? 0 ), $map );
		$state['cursor']  = (int) ( $state['cursor'] ?? 0 );
		$state['offset'] += (int) LucidLab_MPC_Settings::get( 'batch_db' );
		if ( $n > 0 ) {
			$state['cursor'] = $this->dupcontent->last_cursor(); }
		set_transient( $mkey, $map, $this->state_ttl() );

		if ( $n < (int) LucidLab_MPC_Settings::get( 'batch_db' ) ) {
			$result = $this->dupcontent->build_groups( $map );
			LucidLab_MPC_Stats::set( 'dupcontent', (int) ( $result['reclaim_bytes'] ?? 0 ), (int) ( $result['dupe_count'] ?? 0 ) );
			$this->set_scan_result( 'dupcontent', $result );
			delete_transient( $skey );
			delete_transient( $mkey );
			$this->task_success(
				array(
					'progress' => 100,
					'done'     => true,
				)
			);
		}
		set_transient( $skey, $state, $this->state_ttl() );
		$pct = $state['total'] > 0 ? min( 99, (int) ( $state['offset'] / $state['total'] * 100 ) ) : 50;
		/* translators: %d is the number of images hashed so far. */
		$this->task_success(
			array(
				'progress' => $pct,
				'label'    => sprintf( /* translators: %d: number of images processed so far. */ __( 'Hashing images (%d)…', 'lucidlab-mpc-cleanup' ), (int) $state['offset'] ),
				'done'     => false,
			)
		);
	}

	/**
	 * AJAX handler: rename.
	 */
	private function ajax_rename(): void {
		$id   = isset( $this->req['id'] ) ? (int) $this->req['id'] : 0;
		$name = isset( $this->req['name'] ) ? sanitize_file_name( wp_unslash( $this->req['name'] ) ) : '';
		if ( isset( $this->req['pattern'] ) ) {
			$pattern = sanitize_text_field( wp_unslash( $this->req['pattern'] ) );
			if ( preg_match( '/\{[^}]+\}/', strtr( $pattern, $this->rename->pattern_tags( $id ) ) ) ) {
				$this->task_error( array( 'message' => __( 'Unknown naming tag.', 'lucidlab-mpc-cleanup' ) ), 400 );
			}
			$name = $this->rename->suggest_base( $id, '', $pattern );
		}
		$r = $this->rename->rename_with_fields( $id, $name, ! empty( $this->req['update_title'] ), ! empty( $this->req['update_alt'] ), ! isset( $this->req['update_filename'] ) || ! empty( $this->req['update_filename'] ) );
		$this->task_success(
			array(
				'ok'      => ! empty( $r['ok'] ),
				'skipped' => ! empty( $r['skipped'] ),
				'message' => $r['message'],
				'new'     => $r['new'] ?? '',
			)
		);
	}

	/**
	 * AJAX handler: backup.
	 */
	private function ajax_backup(): void {
		$sub = sanitize_key( wp_unslash( $this->req['sub'] ?? 'status' ) );
		if ( ! in_array( $sub, array( 'status', 'list', 'start', 'step', 'pause', 'cancel', 'resume', 'bg', 'delete', 'import', 'verify' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid backup command', 'lucidlab-mpc-cleanup' ) ), 400 );
		}
		try {
			if ( 'import' === $sub ) {
				wp_send_json_success( array_merge( LucidLab_MPC_Backup::import_upload( sanitize_key( wp_unslash( $this->req['kind'] ?? 'db' ) ) ), array( 'busy' => false ) ) );
			}
			$from_dbcleaner = ! empty( $this->req['dbcleaner'] );
			$j              = LucidLab_MPC_Backup::command(
				$sub,
				array(
					'kind' => sanitize_key( wp_unslash( $this->req['kind'] ?? '' ) ),
					'file' => LucidLab_MPC_Backup::safe_name( sanitize_text_field( wp_unslash( $this->req['file'] ?? '' ) ) ),
				)
			);
			$extra          = array( 'busy' => ! empty( $j['busy'] ) );
			if ( $from_dbcleaner && 'db' === (string) ( $j['kind'] ?? '' ) && 'done' === (string) ( $j['status'] ?? '' ) ) {
				$ts = time();
				update_option( 'lucidlab_mpc_dbcleaner_last_backup', $ts, false );
				$extra['dbcleaner_last_backup']       = $ts;
				$extra['dbcleaner_last_backup_label'] = sprintf(
					/* translators: %s: localized date/time */
					__( 'Backup made: %s', 'lucidlab-mpc-cleanup' ),
					LucidLab_MPC_Helpers::job_datetime( $ts )
				);
			}
			wp_send_json_success( array_merge( $j, $extra ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}
	}

	/**
	 * AJAX handler: regen.
	 */
	private function ajax_regen(): void {
		$sub = sanitize_key( wp_unslash( $this->req['sub'] ?? 'status' ) );
		if ( ! in_array( $sub, array( 'status', 'start', 'step', 'pause', 'cancel', 'reset', 'resume', 'bg', 'retry' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid regeneration command', 'lucidlab-mpc-cleanup' ) ), 400 );
		}
		$sizes = LucidLab_MPC_Helpers::json_input( $this->req['sizes'] ?? '[]', 'auto' );
		try {
			$input = array(
				'mode'    => sanitize_key( wp_unslash( $this->req['mode'] ?? 'skip' ) ),
				'sizes'   => is_array( $sizes ) ? array_map( 'sanitize_key', $sizes ) : array(),
				'quality' => ( isset( $this->req['quality'] ) ? (int) $this->req['quality'] : 85 ),
			);
			if ( 'start' === $sub ) {
				LucidLab_MPC_Settings::save_page_regen( $input );}
			$j = 'retry' === $sub ? $this->regen->retry( absint( $this->req['id'] ?? 0 ) ) : $this->regen->command( $sub, $input );
			wp_send_json_success( array_merge( $this->regen_public( $j ), array( 'busy' => ! empty( $j['busy'] ) ) ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 ); }
	}

	/**
	 * AJAX handler: save page settings.
	 */
	private function ajax_save_page_settings(): void {
		$page = sanitize_key( wp_unslash( $this->req['page'] ?? '' ) );
		$raw  = LucidLab_MPC_Helpers::json_input( $this->req['settings'] ?? '{}', 'auto' );
		$raw  = is_array( $raw ) ? $raw : array();
		if ( 'webp' === $page ) {
			wp_send_json_success( array( 'page_webp' => LucidLab_MPC_Settings::save_page_webp( $raw ) ) );
		}
		if ( 'regen' === $page ) {
			if ( isset( $raw['sizes'] ) && is_string( $raw['sizes'] ) ) {
				$decoded      = LucidLab_MPC_Helpers::sanitize_decoded( json_decode( $raw['sizes'], true ), 'auto' );
				$raw['sizes'] = is_array( $decoded ) ? $decoded : array();
			}
			wp_send_json_success( array( 'page_regen' => LucidLab_MPC_Settings::save_page_regen( $raw ) ) );
		}
		wp_send_json_error( array( 'message' => __( 'Unknown page settings.', 'lucidlab-mpc-cleanup' ) ), 400 );
	}

	/**
	 * AJAX handler: inventory.
	 */
	private function ajax_inventory(): void {
		$sub     = sanitize_key( wp_unslash( $this->req['sub'] ?? 'disk' ) );
		$reset   = ! empty( $this->req['reset'] );
		$profile = ( 'status' !== $sub && class_exists( 'LucidLab_MPC_Scan_Profile' ) ) ? LucidLab_MPC_Scan_Profile::start() : null;
		try {
			$inv = new LucidLab_MPC_Inventory();
			if ( 'refs' === $sub ) {
				$result = $inv->refs_step( $reset );
				if ( null !== $profile ) {
					LucidLab_MPC_Scan_Profile::count( (int) ( $result['step_items'] ?? 0 ) );
					LucidLab_MPC_Scan_Profile::finish( 'inventory', 'refs', $profile, false );
				}
				if ( ! empty( $result['done'] ) ) {
					$this->record_inventory_scan( 'refs', $result );
				}
				$this->task_success( $result );
			}
			if ( 'status' === $sub ) {
				$r = LucidLab_MPC_Inventory::result();
				$this->task_success(
					array(
						'done'    => true,
						'disk'    => $r['disk'] ?? null,
						'refs'    => $r['refs'] ?? null,
						'updated' => (int) ( $r['updated'] ?? 0 ),
					)
				);
			}
			$result = $inv->disk_step( $reset );
			if ( null !== $profile ) {
				LucidLab_MPC_Scan_Profile::count( (int) ( $result['step_items'] ?? 0 ) );
				LucidLab_MPC_Scan_Profile::finish( 'inventory', 'disk', $profile, false );
			}
			if ( ! empty( $result['done'] ) ) {
				$this->record_inventory_scan( 'disk', $result );
			}
			$this->task_success( $result );
		} catch ( Throwable $e ) {
			$this->task_error( array( 'message' => $e->getMessage() ), 400 );
		}
	}

	/**
	 * Record a completed storage-overview inventory in the same history as scans.
	 *
	 * @param string $sub    Sub.
	 * @param array  $result Result.
	 */
	private function record_inventory_scan( string $sub, array $result ): void {
		$label  = 'refs' === $sub
			? __( 'Library references inventory', 'lucidlab-mpc-cleanup' )
			: __( 'Uploads folder inventory', 'lucidlab-mpc-cleanup' );
		$detail = trim( (string) ( $result['label'] ?? '' ) );
		LucidLab_MPC_Helpers::prepend_job_history(
			array(
				'key'      => 'inventory_' . $sub . '_' . time(),
				'label'    => $label . ( $detail ? ' — ' . $detail : '' ),
				'status'   => 'done',
				'error'    => '',
				'updated'  => time(),
				'failed'   => 0,
				'panel'    => 'welcome',
				'scan_tab' => 'welcome',
				'special'  => 'inventory',
			)
		);
		if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
			LucidLab_MPC_Logger::log( $label . ': ' . __( 'finished', 'lucidlab-mpc-cleanup' ) . '. ' . $detail, 'info' );
		}
	}

	/**
	 * AJAX handler: lazy refs.
	 */
	private function ajax_lazy_refs(): void {
		$tab = sanitize_key( wp_unslash( $this->req['tab'] ?? '' ) );
		$id  = absint( $this->req['id'] ?? 0 );
		$ids = array();
		if ( isset( $this->req['ids'] ) ) {
			foreach ( explode( ',', sanitize_text_field( wp_unslash( (string) $this->req['ids'] ) ) ) as $part ) {
				$part = absint( $part );
				if ( $part > 0 ) {
					$ids[] = $part;
				}
			}
		}
		if ( $id > 0 ) {
			$ids[] = $id;
		}
		$ids = array_values( array_unique( $ids ) );
		if ( ! $ids || ! in_array( $tab, array( 'dupcontent', 'shared' ), true ) ) {
			$this->task_error( array( 'message' => __( 'Invalid reference request.', 'lucidlab-mpc-cleanup' ) ), 400 );
		}
		if ( 'dupcontent' === $tab ) {
			$target = $id > 0 ? $id : (int) ( $ids[0] ?? 0 );
			if ( $target < 1 ) {
				$this->task_error( array( 'message' => __( 'Invalid reference request.', 'lucidlab-mpc-cleanup' ) ), 400 );
			}
			$refs = $this->dupcontent->reference_index( array( $target ) )[ $target ] ?? array();
			$this->task_success( array( 'refs' => array_values( (array) $refs ) ) );
		}
		$lists = $this->shared_usage_for_ids( $ids );
		if ( ! $lists && 1 === count( $ids ) ) {
			$data = $this->get_scan_result( 'shared' );
			foreach ( (array) ( is_array( $data ) ? ( $data['images'] ?? array() ) : array() ) as $img ) {
				if ( (int) ( $img['id'] ?? 0 ) === $ids[0] ) {
					$lists[ (string) $ids[0] ] = array_values( (array) ( $img['products'] ?? array() ) );
					break;
				}
			}
		}
		$this->task_success(
			array(
				'products' => $lists[ (string) $id ] ?? array(),
				'lists'    => $lists,
			)
		);
	}

	/**
	 * One pass over the usage file for every requested image.
	 *
	 * @param array<int,int> $ids Ids.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	private function shared_usage_for_ids( array $ids ): array {
		$want = array();
		foreach ( $ids as $image_id ) {
			$image_id = (int) $image_id;
			if ( $image_id > 0 ) {
				$want[ (string) $image_id ] = true;
			}
		}
		if ( ! $want ) {
			return array();
		}
		$found = array();
		$path  = LucidLab_MPC_Work::path( $this->shared_usage_lines_file() );
		if ( is_file( $path ) ) {
			$handle = ( is_readable( $path ) ? fopen( $path, 'rb' ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			if ( $handle ) {
				while ( true ) {
					$line = fgets( $handle );
					if ( false === $line ) {
						break;
					}
					$row = json_decode( trim( $line ), true );
					$key = (string) ( is_array( $row ) ? ( $row['id'] ?? '' ) : '' );
					if ( '' === $key || ! isset( $want[ $key ] ) ) {
						continue;
					}
					$found[ $key ] = array_values( (array) ( $row['products'] ?? array() ) );
					if ( count( $found ) >= count( $want ) ) {
						break;
					}
				}
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			}
			return $found;
		}
		$usage = LucidLab_MPC_Work::read_json( $this->shared_usage_file() );
		if ( ! is_array( $usage ) ) {
			return array();
		}
		foreach ( $want as $key => $unused ) {
			if ( isset( $usage[ $key ] ) ) {
				$found[ $key ] = array_values( (array) $usage[ $key ] );
			}
		}
		return $found;
	}

	/**
	 * Shared write usage.
	 *
	 * @param array<string,array<int,mixed>> $usage Usage.
	 *
	 * @return bool
	 */
	private function shared_write_usage( array $usage ): bool {
		$path   = LucidLab_MPC_Work::path( $this->shared_usage_lines_file() );
		$handle = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			return false;
		}
		foreach ( $usage as $image_id => $products ) {
			$line = wp_json_encode(
				array(
					'id'       => (int) $image_id,
					'products' => array_values( (array) $products ),
				)
			);
			if ( ! is_string( $line ) || fwrite( $handle, $line . "\n" ) !== strlen( $line ) + 1 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				wp_delete_file( $path );
				return false;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		LucidLab_MPC_Work::delete( $this->shared_usage_file() );
		return true;
	}

	/**
	 * AJAX handler: email emergency restore.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	private function ajax_email_emergency_restore(): void {
		try {
			$sent = ( new LucidLab_MPC_Reports() )->send_emergency_restore();
			if ( ! $sent ) {
				throw new RuntimeException( __( 'Could not send — check the recipient email and your site mailer.', 'lucidlab-mpc-cleanup' ) );
			}
			$to = ( new LucidLab_MPC_Reports() )->option_recipients();
			$this->task_success(
				array(
					'sent'    => true,
					'to'      => $to,
					'message' => sprintf(
						/* translators: %s: email address list */
						__( 'Emergency restore link and key emailed to %s.', 'lucidlab-mpc-cleanup' ),
						implode( ', ', $to )
					),
				)
			);
		} catch ( Throwable $e ) {
			$this->task_error( array( 'message' => $e->getMessage() ), 400 );
		}
	}

	/**
	 * Regen public.
	 *
	 * @param array $j J.
	 *
	 * @return array
	 */
	private function regen_public( array $j ): array {
		$pct = isset( $j['progress'] )
			? round( (float) $j['progress'], 2 )
			: ( $j['total'] > 0 ? round( min( 100, (int) $j['done'] / (int) $j['total'] * 100 ), 2 ) : ( 'done' === $j['status'] ? 100 : 0 ) );
		return array(
			'status'        => $j['status'],
			'driver'        => $j['driver'],
			'progress'      => $pct,
			'done'          => $j['done'],
			'total'         => $j['total'],
			'regenerated'   => $j['regenerated'],
			'skipped'       => $j['skipped'],
			'failed'        => $j['failed'],
			'failed_ids'    => array_values( array_filter( array_map( 'intval', (array) ( $j['failed_ids'] ?? array() ) ) ) ),
			'reasons'       => array_values( (array) ( $j['reasons'] ?? array() ) ),
			'updated'       => (int) ( $j['updated'] ?? 0 ),
			'updated_label' => ! empty( $j['updated'] ) ? LucidLab_MPC_Helpers::job_datetime( (int) $j['updated'] ) : '',
			'finished'      => 'done' === $j['status'],
		);
	}

	/**
	 * AJAX handler: preview.
	 */
	private function ajax_preview(): void {
		$action = sanitize_key( wp_unslash( $this->req['preview'] ?? '' ) );
		$tab    = sanitize_key( wp_unslash( $this->req['tab'] ?? '' ) );
		if ( 'merge' === $action ) {
			$pairs = LucidLab_MPC_Helpers::json_input( $this->req['pairs'] ?? '[]', 'auto' );
			$pairs = is_array( $pairs ) ? $pairs : array();
			foreach ( $pairs as &$pair ) {
				if ( ! is_array( $pair ) ) {
					continue;
				}
				$dup = (int) ( $pair['dup'] ?? 0 );
				if ( $dup && empty( $pair['bytes'] ) ) {
					$file          = get_attached_file( $dup );
					$pair['bytes'] = ( $file && is_file( $file ) ) ? (int) filesize( $file ) : 0;
				}
			}
			unset( $pair );
			$this->task_success( LucidLab_MPC_Preview::from_merge_pairs( $pairs ) );
		}
		if ( 'quarantine' !== $action ) {
			$this->task_error( array( 'message' => __( 'Unknown preview action.', 'lucidlab-mpc-cleanup' ) ), 400 );
		}
		if ( 'uploads' === $tab && ! empty( $this->req['all'] ) ) {
			$total = LucidLab_MPC_Uploads::result_file_total( $this->uid() );
			$this->task_success(
				array(
					'count'       => $total,
					'files'       => $total,
					'bytes'       => 0,
					'samples'     => array(),
					'more'        => 0,
					'compact'     => true,
					'files_label' => $this->uploads_files_label( $total ),
				)
			);
		}
		$items   = LucidLab_MPC_Helpers::json_input( $this->req['items'] ?? '[]', 'auto' );
		$items   = is_array( $items ) ? $items : array();
		$summary = LucidLab_MPC_Preview::summarize( $this->preview_quarantine_items( $tab, $items ) );
		if ( 'dbcleaner' === $tab && ( ! isset( $this->req['db_mode'] ) || 'rows' !== sanitize_key( wp_unslash( $this->req['db_mode'] ) ) )
			&& ! LucidLab_MPC_Backup::recent_database_backup() ) {
			$this->task_error( array( 'message' => __( 'Create a database or full-site backup in the Backup tab before quarantining tables. The backup must be less than 24 hours old.', 'lucidlab-mpc-cleanup' ) ), 409 );
		}
		if ( 'uploads' === $tab ) {
			$summary['compact']     = true;
			$summary['samples']     = array();
			$summary['more']        = 0;
			$summary['files_label'] = $this->uploads_files_label( (int) $summary['files'] );
		}
		$this->task_success( $summary );
	}

	/**
	 * Uploads files label.
	 *
	 * @param int $total Total.
	 *
	 * @return string
	 */
	private function uploads_files_label( int $total ): string {
		return sprintf(
			/* translators: %s is a localized file count; singular and plural forms are selected separately. */
			_n( '%s file', '%s files', $total, 'lucidlab-mpc-cleanup' ),
			number_format_i18n( $total )
		);
	}

	/**
	 * Preview quarantine items.
	 *
	 * @param string $tab   Tab.
	 * @param array  $items Items.
	 *
	 * @return array
	 */
	private function preview_quarantine_items( string $tab, array $items ): array {
		if ( in_array( $tab, array( 'orphans', 'shared' ), true ) ) {
			$out = array();
			foreach ( LucidLab_MPC_Helpers::int_ids( $items ) as $id ) {
				$title = (string) ( LucidLab_MPC_Helpers::either( get_the_title( $id ), ( '#' . $id ) ) );
				$out[] = array(
					'id'    => $id,
					// The preview is inserted with textContent; decode stored title entities for display.
					'label' => html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'bytes' => $this->orphans->attachment_size( $id ),
				);
			}
			return $out;
		}
		if ( 'uploads' === $tab ) {
			$out = array();
			foreach ( $items as $item ) {
				$rel   = is_array( $item ) ? (string) ( $item['rel'] ?? $item['file'] ?? '' ) : (string) $item;
				$bytes = is_array( $item ) ? (int) ( $item['bytes'] ?? 0 ) : 0;
				if ( $rel && ! $bytes ) {
					$abs   = trailingslashit( LucidLab_MPC_Helpers::uploads_basedir() ) . ltrim( str_replace( '\\', '/', $rel ), '/' );
					$bytes = is_file( $abs ) ? (int) filesize( $abs ) : 0;
				}
				if ( $rel ) {
					$out[] = array(
						'file'  => $rel,
						'label' => $rel,
						'bytes' => $bytes,
					);
				}
			}
			return $out;
		}
		if ( in_array( $tab, array( 'sizes', 'fs-sizes' ), true ) ) {
			$stored = $this->get_scan_result( $tab );
			$lookup = array();
			if ( 'fs-sizes' === $tab ) {
				foreach ( (array) ( is_array( $stored ) ? ( $stored['dimensions'] ?? array() ) : array() ) as $row ) {
					$lookup[ (string) ( $row['dim'] ?? '' ) ] = $row;
				}
			} else {
				foreach ( (array) ( is_array( $stored ) ? ( $stored['counts'] ?? array() ) : array() ) as $name => $row ) {
					$lookup[ (string) $name ] = array(
						'bytes' => $row['bytes'] ?? 0,
						'count' => $row['n'] ?? 0,
					);
				}
			}
			$out = array();
			foreach ( $items as $key ) {
				$key   = is_array( $key ) ? (string) ( $key['dim'] ?? $key['file'] ?? '' ) : (string) $key;
				$row   = $lookup[ $key ] ?? array();
				$out[] = array(
					'label' => $key,
					'bytes' => (int) ( $row['bytes'] ?? 0 ),
					'count' => (int) ( $row['count'] ?? 1 ),
				);
			}
			return $out;
		}
		if ( 'dbcleaner' === $tab ) {
			$stored = $this->get_scan_result( 'dbcleaner' );
			$mode   = isset( $this->req['db_mode'] ) ? sanitize_key( wp_unslash( $this->req['db_mode'] ) ) : '';
			if ( '' === $mode && $items && str_contains( is_array( $items[0] ) ? (string) ( $items[0]['id'] ?? '' ) : (string) $items[0], ':' ) ) {
				$mode = 'rows';
			}
			if ( 'rows' === $mode ) {
				$lookup = array();
				foreach ( (array) ( is_array( $stored ) ? ( $stored['rows'] ?? array() ) : array() ) as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$id = (string) ( $row['id'] ?? '' );
					if ( $id ) {
						$lookup[ $id ] = $row;
					}
				}
				$out = array();
				foreach ( $items as $key ) {
					$key   = is_array( $key ) ? (string) ( $key['id'] ?? $key['label'] ?? '' ) : (string) $key;
					$row   = $lookup[ $key ] ?? array();
					$out[] = array(
						'label' => (string) ( $row['key'] ?? $key ),
						'bytes' => (int) ( $row['bytes'] ?? 0 ),
						'count' => (int) ( $row['count'] ?? 1 ),
					);
				}
				return $out;
			}
			$lookup = array();
			foreach ( (array) ( is_array( $stored ) ? ( $stored['tables'] ?? array() ) : array() ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$name = (string) ( $row['table'] ?? '' );
				if ( $name ) {
					$lookup[ $name ] = $row;
				}
			}
			$out = array();
			foreach ( $items as $key ) {
				$key   = is_array( $key ) ? (string) ( $key['table'] ?? $key['label'] ?? '' ) : (string) $key;
				$row   = $lookup[ $key ] ?? array();
				$out[] = array(
					'label' => $key,
					'bytes' => (int) ( $row['bytes'] ?? 0 ),
					'count' => 1,
				);
			}
			return $out;
		}
		return array_map( static fn( $item ) => is_array( $item ) ? $item : array( 'label' => (string) $item ), $items );
	}

	/**
	 * AJAX handler: quarantine.
	 *
	 * @param string $tab Tab.
	 */
	private function ajax_quarantine( string $tab ): void {
		if ( ! in_array( $tab, array( 'orphans', 'uploads', 'shared' ), true ) ) {
			$this->task_error( array( 'message' => 'bad-tab' ), 400 );
		}
		if ( ! empty( $this->req['finalize_stream'] ) && 'uploads' === $tab ) {
			$this->finalize_uploads_stream_result( ! empty( $this->req['stream_clean'] ) );
			$stamp = isset( $this->req['stamp'] ) ? sanitize_file_name( wp_unslash( $this->req['stamp'] ) ) : '';
			$this->task_success(
				array(
					'done'  => true,
					'stamp' => $stamp,
				)
			);
		}
		$all_uploads = 'uploads' === $tab && ! empty( $this->req['all'] );
		$stamp       = isset( $this->req['stamp'] ) ? sanitize_file_name( wp_unslash( $this->req['stamp'] ) ) : '';
		if ( '' === $stamp ) {
			$stamp = $this->quar->new_stamp();
			$fresh = array(
				'ok'    => 0,
				'fail'  => 0,
				'stamp' => $stamp,
			);
			if ( $all_uploads ) {
				$fresh['uploads_all'] = 1;
				$fresh['cursor']      = array();
				$fresh['total']       = max( 0, absint( $this->req['file_total'] ?? 0 ) );
				if ( $fresh['total'] < 1 ) {
					$fresh['total'] = LucidLab_MPC_Uploads::result_file_total( $this->uid() );
				}
			}
			set_transient( $this->k_quar(), $fresh, $this->state_ttl() );
		}
		if ( $all_uploads ) {
			$acc = get_transient( $this->k_quar() );
			$acc = is_array( $acc ) ? $acc : array(
				'ok'    => 0,
				'fail'  => 0,
				'stamp' => $stamp,
			);
			if ( (string) ( $acc['stamp'] ?? '' ) !== $stamp ) {
				$this->task_error( array( 'message' => __( 'Quarantine session expired. Start again from the results.', 'lucidlab-mpc-cleanup' ) ), 409 );
			}
			$batch              = LucidLab_MPC_Uploads::result_file_batch( $this->uid(), is_array( $acc['cursor'] ?? null ) ? $acc['cursor'] : array(), 100 );
			$res                = $batch['items']
				? $this->quar->quarantine_files( array_map( 'strval', $batch['items'] ), $stamp )
				: array(
					'ok'     => 0,
					'fail'   => 0,
					'skip'   => 0,
					'errors' => array(),
				);
			$acc['cursor']      = $batch['cursor'];
			$acc['ok']          = (int) ( $acc['ok'] ?? 0 ) + (int) $res['ok'];
			$acc['fail']        = (int) ( $acc['fail'] ?? 0 ) + (int) $res['fail'];
			$acc['skip']        = (int) ( $acc['skip'] ?? 0 ) + (int) ( $res['skip'] ?? 0 );
			$acc['stamp']       = $stamp;
			$acc['uploads_all'] = 1;
			set_transient( $this->k_quar(), $acc, $this->state_ttl() );
			// Do not rewrite uploads-result mid-stream: size/mtime is the cursor fingerprint.
			if ( ! empty( $batch['done'] ) ) {
				$this->finalize_uploads_stream_result( empty( $acc['fail'] ) && empty( $acc['skip'] ) );
			}
			$this->task_success(
				array(
					'stamp'  => $stamp,
					'ok'     => (int) $res['ok'],
					'fail'   => (int) $res['fail'],
					'skip'   => (int) ( $res['skip'] ?? 0 ),
					'errors' => array_values( (array) ( $res['errors'] ?? array() ) ),
					'batch'  => count( $batch['items'] ),
					'done'   => (bool) $batch['done'],
					'total'  => (int) ( $acc['total'] ?? 0 ),
				)
			);
		}
		$items = LucidLab_MPC_Helpers::json_input( $this->req['items'] ?? '[]', 'auto' );
		$items = is_array( $items ) ? $items : array();

		if ( 'uploads' === $tab ) {
			$res = $this->quar->quarantine_files( array_map( 'strval', $items ), $stamp );
		} else { // orphans or shared → attachment quarantine.
			$res = $this->quar->quarantine_attachments( array_map( 'intval', $items ), $stamp );
		}

		$acc          = get_transient( $this->k_quar() );
		$acc          = is_array( $acc ) ? $acc : array(
			'ok'    => 0,
			'fail'  => 0,
			'stamp' => $stamp,
		);
		$acc['ok']   += (int) $res['ok'];
		$acc['fail'] += (int) $res['fail'];
		$acc['skip']  = (int) ( $acc['skip'] ?? 0 ) + (int) ( $res['skip'] ?? 0 );
		$acc['stamp'] = $stamp;
		set_transient( $this->k_quar(), $acc, $this->state_ttl() );
		// Run All / streamed Uploads keep the result file until the cursor finishes.
		if ( empty( $this->req['keep_result'] ) && empty( $res['fail'] ) && empty( $res['skip'] ) && $items ) {
			$this->forget_quarantined_items( $tab, $items );
		}

		$this->task_success(
			array(
				'stamp'             => $stamp,
				'ok'                => (int) $res['ok'],
				'fail'              => (int) $res['fail'],
				'skip'              => (int) ( $res['skip'] ?? 0 ),
				'files_total'       => isset( $res['files_total'] ) ? (int) $res['files_total'] : null,
				'attachments_total' => isset( $res['attachments_total'] ) ? (int) $res['attachments_total'] : null,
				'errors'            => array_values( (array) ( $res['errors'] ?? array() ) ),
			)
		);
	}

	/**
	 * Review Queue counts from the saved summary, without loading scan lists or stating files.
	 *
	 * @param string $tab Tab.
	 *
	 * @return array{count:int,bytes:int,done:bool}|null
	 */
	protected function review_source_metrics( string $tab ): ?array {
		$tab     = sanitize_key( $tab );
		$summary = get_option( 'lucidlab_mpc_summary_' . $tab, array() );
		$counts  = is_array( $summary['counts'] ?? null ) ? $summary['counts'] : array();
		if ( ! $counts ) {
			return null;
		}
		$count_keys = array(
			'duplicates' => array( 'group_count', 'dupe_count' ),
			'orphans'    => array( 'orphan_count' ),
			'uploads'    => array( 'orphan_files', 'orphan_groups' ),
			'shared'     => array( 'image_count' ),
			'dupcontent' => array( 'group_count', 'dupe_count' ),
			'visual'     => array( 'candidate_groups' ),
		);
		$byte_keys  = array(
			'duplicates' => array( 'reclaim_bytes' ),
			'orphans'    => array( 'reclaim_bytes', 'orphan_bytes' ),
			'uploads'    => array( 'orphan_bytes' ),
			'dupcontent' => array( 'reclaim_bytes' ),
			'visual'     => array( 'reclaim_bytes' ),
			'sizes'      => array( 'bytes', 'reclaim_bytes' ),
		);
		if ( 'missing' === $tab ) {
			if ( ! array_key_exists( 'missing', $counts ) && ! array_key_exists( 'broken_urls', $counts ) ) {
				return null;
			}
			$count = (int) ( $counts['missing'] ?? 0 ) + (int) ( $counts['broken_urls'] ?? 0 );
		} else {
			$count = null;
			foreach ( $count_keys[ $tab ] ?? array() as $key ) {
				if ( array_key_exists( $key, $counts ) ) {
					$count = (int) $counts[ $key ];
					break;
				}
			}
			if ( null === $count ) {
				return null;
			}
		}
		$bytes = 0;
		if ( 'shared' !== $tab ) {
			foreach ( $byte_keys[ $tab ] ?? array() as $key ) {
				if ( array_key_exists( $key, $counts ) && is_numeric( $counts[ $key ] ) ) {
					$bytes = (int) $counts[ $key ];
					break;
				}
			}
		}
		return array(
			'count' => $count,
			'bytes' => $bytes,
			'done'  => ( $counts['outcome'] ?? '' ) === 'quarantined',
		);
	}

	// CSV export.

	/**
	 * Handle csv export.
	 */
	public function handle_csv_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lucidlab-mpc-cleanup' ) );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'duplicates';
		check_admin_referer( 'lucidlab_mpc_export_' . $tab );
		$this->accept_request();
		if ( 'crawl' === $tab ? ! LucidLab_MPC_Edition::pro() : ! LucidLab_MPC_Edition::tab( $tab ) ) {
			wp_die( esc_html__( 'This report requires Pro.', 'lucidlab-mpc-cleanup' ), '', array( 'response' => 403 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lucidlab-mpc-' . $tab . '-' . wp_date( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		if ( 'review' === $tab ) {
			self::csv_row( $out, array( __( 'Source', 'lucidlab-mpc-cleanup' ), __( 'Findings', 'lucidlab-mpc-cleanup' ), __( 'Estimated reclaimable', 'lucidlab-mpc-cleanup' ) ) );
			$review_filter    = sanitize_key( wp_unslash( $_GET['review_source'] ?? 'all' ) );
			$review_min       = max( 0, absint( $_GET['review_min'] ?? 0 ) );
			$review_min_bytes = max( 0, ( isset( $_GET['review_min_bytes'] ) ? (int) $_GET['review_min_bytes'] : 0 ) );
			$review_risk      = sanitize_key( wp_unslash( $_GET['review_risk'] ?? 'all' ) );
			$review_status    = sanitize_key( wp_unslash( $_GET['review_status'] ?? 'active' ) );
			$review_states    = (array) get_option( 'lucidlab_mpc_review_status', array() );
			foreach ( array(
				'duplicates' => __( 'Duplicate Products', 'lucidlab-mpc-cleanup' ),
				'orphans'    => __( 'Orphan Images', 'lucidlab-mpc-cleanup' ),
				'uploads'    => __( 'Uploads Scanner', 'lucidlab-mpc-cleanup' ),
				'shared'     => __( 'Shared Images', 'lucidlab-mpc-cleanup' ),
				'sizes'      => __( 'Image Sizes', 'lucidlab-mpc-cleanup' ),
				'dupcontent' => __( 'Byte Identical Images', 'lucidlab-mpc-cleanup' ),
				'visual'     => __( 'Visually Identical Images', 'lucidlab-mpc-cleanup' ),
				'missing'    => __( 'Missing Images', 'lucidlab-mpc-cleanup' ),
			) as $source => $label ) {
				if ( 'all' !== $review_filter && $review_filter !== $source ) {
					continue; }
				if ( 'all' !== $review_status && ( $review_states[ $source ] ?? 'active' ) !== $review_status ) {
					continue; }
				$metrics = $this->review_source_metrics( $source );
				if ( ! $metrics || ! empty( $metrics['done'] ) ) {
					continue; }
				$count = (int) $metrics['count'];
				$bytes = (int) $metrics['bytes'];
				if ( $count < 1 || $count < $review_min || $bytes < $review_min_bytes ) {
					continue; }
				$finding          = LucidLab_MPC_Review::finding( $source, 0, '', $bytes );
				$finding['count'] = $count;
				$level            = LucidLab_MPC_Review::risk( $finding );
				if ( 'all' !== $review_risk && $review_risk !== $level ) {
					continue; }
				self::csv_row( $out, array( $label, $count, $bytes ) );
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			exit;
		}

		if ( 'duplicates' === $tab ) {
			$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'title';
			$mode = in_array( $mode, array( 'title', 'title_sku', 'slug' ), true ) ? $mode : 'title';
			$data = $this->duplicates->scan( $mode );
			self::csv_row( $out, array( 'group', 'match', 'product_id', 'title', 'sku', 'slug', 'orders', 'reviews', 'variations', 'role' ) );
			$g = 0;
			foreach ( $data['groups'] as $group ) {
				++$g;
				foreach ( $group['members'] as $m ) {
					self::csv_row( $out, array( $g, $group['match'], $m['id'], $m['title'], $m['sku'], $m['slug'], $m['orders'], $m['reviews'], count( $m['variations'] ), $m['is_survivor'] ? 'KEEP' : 'duplicate' ) );
				}
			}
		} elseif ( 'crawl' === $tab ) {
			self::csv_row( $out, array( 'page', 'page_title', 'address', 'type', 'other_site', 'status', 'problem', 'redirected_to', 'found_in_stylesheet' ) );
			for ( $csv_page = 1; ; $csv_page++ ) {
				$slice = LucidLab_MPC_Crawler::findings_slice( $this->uid(), $csv_page, 500 );
				foreach ( $slice['items'] as $row ) {
					self::csv_row( $out, array( $row['page'], $row['title'], $row['url'], $row['kind'], $row['external'] ? 'yes' : 'no', $row['status'], $row['problem_label'], $row['final'], $row['via'] ) );
				}
				if ( $csv_page >= (int) $slice['pages'] ) {
					break;
				}
			}
		} elseif ( 'orphans' === $tab ) {
			$data = $this->orphans->scan_full();
			self::csv_row( $out, array( 'attachment_id', 'title', 'url', 'size_bytes', 'size_human' ) );
			foreach ( $data['orphans'] as $o ) {
				self::csv_row( $out, array( $o['id'], $o['title'], $o['url'], $o['size'], LucidLab_MPC_Helpers::bytes( $o['size'] ) ) );
			}
		} elseif ( 'shared' === $tab ) {
			$data = $this->shared->scan_indexed();
			self::csv_row( $out, array( 'image_id', 'image_title', 'used_by_items', 'item_id', 'item_title', 'usage' ) );
			foreach ( $data['images'] as $img ) {
				foreach ( $img['products'] as $p ) {
					self::csv_row(
						$out,
						array(
							$img['id'],
							html_entity_decode( (string) $img['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
							$img['count'],
							$p['id'],
							html_entity_decode( (string) $p['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
							html_entity_decode( (string) $p['usage'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
						)
					);
				}
			}
		} elseif ( 'dupcontent' === $tab ) {
			$data = $this->dupcontent->scan_full();
			self::csv_row( $out, array( 'group', 'sha256', 'file_bytes', 'attachment_id', 'title', 'references', 'role' ) );
			$g = 0;
			foreach ( $data['groups'] as $group ) {
				++$g;
				foreach ( $group['members'] as $m ) {
					self::csv_row( $out, array( $g, $group['hash'], $group['bytes'], $m['id'], $m['title'], $m['ref_count'], $m['is_survivor'] ? 'KEEP' : 'duplicate' ) );
				}
			}
		} else {
			$data = $this->uploads->scan_full();
			self::csv_row( $out, array( 'unit_base', 'file_rel', 'size_bytes', 'size_human' ) );
			foreach ( (array) $data['orphan_units'] as $base => $unit ) {
				foreach ( $unit['files'] as $f ) {
					self::csv_row( $out, array( $base, $f['rel'], $f['bytes'], LucidLab_MPC_Helpers::bytes( $f['bytes'] ) ) );
				}
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		exit;
	}

	/**
	 * Handle manifest export.
	 */
	public function handle_manifest_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lucidlab-mpc-cleanup' ) );
		}
		$stamp = sanitize_file_name( wp_unslash( $_GET['set'] ?? '' ) );
		check_admin_referer( 'lucidlab_mpc_export_manifest_' . $stamp );
		$this->accept_request();
		$export = $this->quar->export_manifest( $stamp );
		if ( empty( $export['success'] ) ) {
			wp_die( esc_html__( 'Quarantine manifest not found.', 'lucidlab-mpc-cleanup' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lucidlab-mpc-quarantine-' . $stamp . '-export.json"' );
		echo wp_json_encode( $export['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Handle stats export.
	 */
	public function handle_stats_export(): void {
		if ( ! LucidLab_MPC_Edition::pro() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lucidlab-mpc-cleanup' ) ); }
		check_admin_referer( 'lucidlab_mpc_export_stats' );
		$this->accept_request();
		LucidLab_MPC_Stats::sync_from_summaries();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lucidlab-mpc-storage-history-' . wp_date( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		self::csv_row( $out, array( 'date', 'reclaimable_bytes', 'reclaimable_human', 'items', 'scanner_breakdown' ) );
		foreach ( (array) get_option( 'lucidlab_mpc_stats_history', array() ) as $day => $snapshot ) {
			$breakdown = array();
			foreach ( (array) ( $snapshot['slices'] ?? array() ) as $slice => $slice_data ) {
				if ( ! empty( $slice_data['bytes'] ) ) {
					$breakdown[] = LucidLab_MPC_Stats::label( (string) $slice ) . ': ' . LucidLab_MPC_Helpers::bytes( (int) $slice_data['bytes'] );
				}
			} self::csv_row( $out, array( $day, (int) ( $snapshot['bytes'] ?? 0 ), LucidLab_MPC_Helpers::bytes( (int) ( $snapshot['bytes'] ?? 0 ) ), (int) ( $snapshot['count'] ?? 0 ), implode( ' · ', $breakdown ) ) ); }
		self::csv_row( $out, array() );
		self::csv_row( $out, array( 'scanner', 'reclaimable_bytes', 'reclaimable_human', 'items', 'updated' ) );
		foreach ( LucidLab_MPC_Stats::all() as $slice => $data ) {
			self::csv_row( $out, array( LucidLab_MPC_Stats::label( (string) $slice ), (int) ( $data['bytes'] ?? 0 ), LucidLab_MPC_Helpers::bytes( (int) ( $data['bytes'] ?? 0 ) ), (int) ( $data['count'] ?? 0 ), ! empty( $data['updated'] ) ? wp_date( 'c', (int) $data['updated'] ) : '' ) ); }
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		exit;
	}

	/**
	 * Handle pdf export.
	 */
	public function handle_pdf_export(): void {
		if ( ! LucidLab_MPC_Edition::pro() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lucidlab-mpc-cleanup' ) );
		}
		check_admin_referer( 'lucidlab_mpc_export_pdf' );
		$this->accept_request();
		$slices  = LucidLab_MPC_Stats::all();
		$history = array_reverse( array_slice( (array) get_option( 'lucidlab_mpc_stats_history', array() ), -30, 30, true ), true );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Media Cleanup report', 'lucidlab-mpc-cleanup' ) . '</title>';
		wp_enqueue_style( 'lucidlab-mpc-report', LUCIDLAB_MPC_URL . 'assets/css/report.css', array(), LUCIDLAB_MPC_VERSION );
		wp_print_styles( 'lucidlab-mpc-report' );
		wp_enqueue_script( 'lucidlab-mpc-report', LUCIDLAB_MPC_URL . 'assets/js/report.js', array(), LUCIDLAB_MPC_VERSION, false );
		wp_print_scripts( 'lucidlab-mpc-report' );
		echo '</head><body>';
		echo ( '<p class="no-print"><button type="button" id="lucidlab-mpc-print-report">' . esc_html__( 'Print / save as PDF', 'lucidlab-mpc-cleanup' ) . '</button></p>' );
		echo ( '<h1>' . esc_html__( 'Media &amp; Products Cleanup report', 'lucidlab-mpc-cleanup' ) . '</h1><p>' ) . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) . ' · ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</p>';
		echo ( '<h2>' . esc_html__( 'Reclaimable space by scanner', 'lucidlab-mpc-cleanup' ) . '</h2><table><thead><tr><th>' . esc_html__( 'Scanner', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Bytes', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Items', 'lucidlab-mpc-cleanup' ) . '</th></tr></thead><tbody>' );
		foreach ( $slices as $slice => $data ) {
			echo '<tr><td>' . esc_html( LucidLab_MPC_Stats::label( (string) $slice ) ) . '</td><td>' . esc_html( LucidLab_MPC_Helpers::bytes( (int) ( $data['bytes'] ?? 0 ) ) ) . '</td><td>' . esc_html( number_format_i18n( (int) ( $data['count'] ?? 0 ) ) ) . '</td></tr>';
		}
		if ( ! $slices ) {
			echo ( '<tr><td colspan="3">' . esc_html__( 'No cached scanner totals.', 'lucidlab-mpc-cleanup' ) . '</td></tr>' );
		}
		echo ( '</tbody></table><h2>' . esc_html__( 'Storage history', 'lucidlab-mpc-cleanup' ) . '</h2><table><thead><tr><th>' . esc_html__( 'Date', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Reclaimable', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Items', 'lucidlab-mpc-cleanup' ) . '</th></tr></thead><tbody>' );
		foreach ( $history as $day => $snapshot ) {
			echo '<tr><td>' . esc_html( (string) $day ) . '</td><td>' . esc_html( LucidLab_MPC_Helpers::bytes( (int) ( $snapshot['bytes'] ?? 0 ) ) ) . '</td><td>' . esc_html( number_format_i18n( (int) ( $snapshot['count'] ?? 0 ) ) ) . '</td></tr>';
		}
		if ( ! $history ) {
			echo ( '<tr><td colspan="3">' . esc_html__( 'No history snapshots.', 'lucidlab-mpc-cleanup' ) . '</td></tr>' );
		}
		echo '</tbody></table></body></html>';
		exit;
	}

	// Rendering.

	/**
	 * Write one CSV row with spreadsheet formula injection neutralised.
	 *
	 * Cells that start with = + - @ tab or carriage return get a leading single quote, so Excel,
	 * LibreOffice and Google Sheets show them as text instead of running them as formulas.
	 *
	 * @param resource $out   Output handle.
	 * @param array    $cells Cells.
	 */
	public static function csv_row( $out, array $cells ): void {
		fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $cells ) );
	}

	/**
	 * Neutralise one CSV cell.
	 *
	 * @param mixed $cell Cell.
	 *
	 * @return mixed
	 */
	public static function csv_cell( $cell ) {
		if ( is_string( $cell ) && '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ) {
			return "'" . $cell;
		}
		return $cell;
	}

	/**
	 * Handle backup download.
	 */
	public function handle_backup_download(): void {
		LucidLab_MPC_Backup::send_download( sanitize_text_field( wp_unslash( $_GET['file'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
	}

	/**
	 * Handle log export.
	 */
	public function handle_log_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lucidlab-mpc-cleanup' ) ); }
			check_admin_referer( 'lucidlab_mpc_export_log' );
			$this->accept_request();
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="lucidlab-mpc-log-' . wp_date( 'Ymd-His' ) . '.csv"' );
			$out = fopen( 'php://output', 'w' );
			self::csv_row( $out, array( 'time', 'level', 'message' ) );
		foreach ( array_reverse( LucidLab_MPC_Logger::get() ) as $line ) {
			self::csv_row( $out, array( $line['t'] ?? '', strtoupper( (string) ( $line['lvl'] ?? '' ) ), $line['m'] ?? '' ) ); }
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			exit;
	}

	/**
	 * Keep a previous replay when a later status update does not include one.
	 *
	 * @param array  $history History.
	 * @param string $source  Source.
	 * @param string $status  Status.
	 * @param int    $updated Updated.
	 * @param array  $replay  Replay.
	 *
	 * @return array
	 */
	private function finished_entry( array $history, string $source, string $status, int $updated, array $replay = array() ): array {
		$prev = is_array( $history[ $source ] ?? null ) ? $history[ $source ] : array();
		$kept = is_array( $prev['replay'] ?? null ) ? $prev['replay'] : array();
		return array(
			'status'  => $status,
			'updated' => $updated,
			'replay'  => $replay ? $replay : $kept,
		);
	}

	/**
	 * Newest terminal status for a Finished Jobs source from the live job list.
	 *
	 * @param array<int,array<string,mixed>> $jobs   Jobs.
	 * @param string                         $source Source.
	 *
	 * @return string
	 */
	private function latest_finished_job_status( array $jobs, string $source ): string {
		$best   = '';
		$best_t = 0;
		foreach ( $jobs as $job ) {
			$status = (string) ( $job['status'] ?? '' );
			if ( 'quarantine' === (string) ( $job['op'] ?? '' ) ) {
				continue;
			}
			if ( ! in_array( $status, array( 'cancelled', 'failed', 'done' ), true ) ) {
				continue;
			}
			$panel = (string) ( $job['scan_tab'] ?? $job['panel'] ?? '' );
			$panel = array( 'webpstats' => 'webp' )[ $panel ] ?? $panel;
			$match = $panel === $source;
			if ( ! $match && str_starts_with( (string) ( $job['key'] ?? '' ), 'run_all' ) ) {
				foreach ( (array) ( $job['stages'] ?? array() ) as $stage ) {
					$stage_source = array( 'webpstats' => 'webp' )[ $stage['panel'] ?? '' ] ?? ( $stage['panel'] ?? '' );
					if ( $stage_source === $source && in_array( (string) ( $stage['status'] ?? '' ), array( 'cancelled', 'failed', 'done', 'running', 'queued' ), true ) ) {
						// Whole Run All cancelled while this stage was active → treat source as cancelled.
						if ( 'cancelled' === $status && in_array( (string) ( $stage['status'] ?? '' ), array( 'running', 'queued', 'cancelled' ), true ) ) {
							$match = true;
							break;
						}
						if ( 'done' === ( $stage['status'] ?? '' ) ) {
							$match  = true;
							$status = 'done';
							break;
						}
					}
				}
			}
			if ( ! $match ) {
				continue;
			}
			$updated = (int) ( $job['updated'] ?? 0 );
			if ( $updated >= $best_t ) {
				$best_t = $updated;
				$best   = $status;
			}
		}
		return $best;
	}

	/**
	 * Settings needed to start the same job again.
	 *
	 * @param array $job Job.
	 *
	 * @return array
	 */
	private function replay_from_job( array $job ): array {
		if ( ! empty( $job['replay'] ) && is_array( $job['replay'] ) ) {
			return $job['replay'];
		}
		$special = (string) ( $job['special'] ?? '' );
		if ( 'regen' === $special ) {
			return array(
				'special' => 'regen',
				'mode'    => (string) ( $job['mode'] ?? 'skip' ),
				'sizes'   => array_values( (array) ( $job['sizes'] ?? array() ) ),
				'quality' => (int) ( $job['quality'] ?? 85 ),
			);
		}
		if ( 'webp' === $special ) {
			return array( 'special' => 'webp' );
		}
		if ( 'rename' === $special ) {
			return array(
				'special'         => 'rename',
				'pattern'         => (string) ( $job['pattern'] ?? '' ),
				'search'          => (string) ( $job['search'] ?? '' ),
				'update_filename' => ! isset( $job['update_filename'] ) || ! empty( $job['update_filename'] ) ? 1 : 0,
				'update_title'    => ! empty( $job['update_title'] ) ? 1 : 0,
				'update_alt'      => ! empty( $job['update_alt'] ) ? 1 : 0,
			);
		}
		if ( 'ai' === $special ) {
			return array( 'special' => 'ai' );
		}
		$op  = sanitize_key( (string) ( $job['op'] ?? '' ) );
		$tab = sanitize_key( (string) ( $job['scan_tab'] ?? '' ) );
		if ( '' === $op || '' === $tab ) {
			return array();
		}
		$replay = array(
			'op'  => $op,
			'tab' => $tab,
		);
		if ( ! empty( $job['format'] ) ) {
			$replay['format'] = sanitize_key( (string) $job['format'] );
		}
		if ( ! empty( $job['progress_id'] ) ) {
			$replay['progress_id'] = sanitize_key( (string) $job['progress_id'] );
		}
		return $replay;
	}

	/**
	 * Saved page settings used when a job has not stored its own replay yet.
	 *
	 * @param string $source Source.
	 *
	 * @return array
	 */
	private function default_finished_replay( string $source ): array {
		$scans = array( 'orphans', 'uploads', 'shared', 'dupcontent', 'visual', 'duplicates', 'missing', 'product_copy', 'sizes', 'fs-sizes' );
		if ( in_array( $source, $scans, true ) ) {
			$replay = array(
				'op'  => 'scan',
				'tab' => $source,
			);
			if ( 'duplicates' === $source ) {
				$replay['lucidlab_mpc_match_mode'] = $this->saved_match_mode();
			}
			if ( 'product_copy' === $source ) {
				$data = $this->get_scan_result( 'product_copy' );
				if ( is_array( $data ) && ! empty( $data['include_empty_gallery'] ) ) {
					$replay['include_empty_gallery'] = 1;
				}
			}
			return $replay;
		}
		if ( 'regenerate' === $source && class_exists( 'LucidLab_MPC_Settings', false ) ) {
			$page = LucidLab_MPC_Settings::page_regen();
			return array(
				'special' => 'regen',
				'mode'    => (string) ( $page['mode'] ?? 'skip' ),
				'sizes'   => array_values( (array) ( $page['sizes'] ?? array() ) ),
				'quality' => (int) ( $page['quality'] ?? 85 ),
			);
		}
		if ( 'webp' === $source ) {
			return array( 'special' => 'webp' );
		}
		if ( 'rename' === $source ) {
			$job = get_option( 'lucidlab_mpc_rename_job', array() );
			$job = is_array( $job ) ? $job : array();
			return array(
				'special'         => 'rename',
				'pattern'         => (string) ( $job['pattern'] ?? '' ),
				'search'          => (string) ( $job['search'] ?? '' ),
				'update_filename' => ! isset( $job['update_filename'] ) || ! empty( $job['update_filename'] ) ? 1 : 0,
				'update_title'    => ! empty( $job['update_title'] ) ? 1 : 0,
				'update_alt'      => ! empty( $job['update_alt'] ) ? 1 : 0,
			);
		}
		if ( 'rename_ai' === $source ) {
			return array( 'special' => 'ai' );
		}
		return array(
			'op'  => 'scan',
			'tab' => $source,
		);
	}

	/**
	 * Webp replay settings.
	 *
	 * @return array
	 */
	private function webp_replay_settings(): array {
		$page     = class_exists( 'LucidLab_MPC_Settings', false ) ? LucidLab_MPC_Settings::page_webp() : array();
		$settings = array(
			'background'        => false,
			'replace_originals' => ! empty( $page['replace_originals'] ),
			'skip_existing'     => ! isset( $page['skip_existing'] ) || ! empty( $page['skip_existing'] ),
		);
		foreach ( array( 'webp', 'avif' ) as $fmt ) {
			$settings[ $fmt ] = array(
				'enabled'    => ! empty( $page[ $fmt . '_enabled' ] ),
				'mode'       => ( ( $page[ $fmt . '_mode' ] ?? 'all' ) === 'featured' ) ? 'featured' : 'all',
				'images'     => (int) ( $page[ $fmt . '_images' ] ?? 0 ),
				'thumbnails' => (int) ( $page[ $fmt . '_thumbnails' ] ?? 0 ),
			);
		}
		return $settings;
	}

	/**
	 * Finished job sources.
	 *
	 * @return array
	 */
	private function finished_job_sources(): array {
		$skip = array( 'welcome', 'guide', 'backup', 'options', 'review', 'quarantine', 'logs' );
		$out  = array();
		foreach ( $this->menu_tabs() as $source => $label ) {
			if ( in_array( $source, $skip, true ) ) {
				continue;
			}
			// Free-only: hide Pro scanners from Finished Jobs (sidebar still lists them as locked).
			if ( ! LucidLab_MPC_Edition::pro() && in_array( $source, LucidLab_MPC_Edition::pro_tabs(), true ) ) {
				continue;
			}
			$out[ $source ] = $label;
		}
		if ( LucidLab_MPC_Edition::pro() ) {
			$ordered = array();
			foreach ( $out as $source => $label ) {
				$ordered[ $source ] = $label;
				if ( 'sizes' === $source ) {
					$ordered['fs-sizes'] = __( 'Other Sizes on Disk', 'lucidlab-mpc-cleanup' );
				}
			}
			$out = $ordered;
		}
		return $out;
	}

	/**
	 * Run All still has this scanner queued, running, or waiting to retry. That is not a cancellation just because the row is no longer the active stage.
	 *
	 * @param array<int,array<string,mixed>> $jobs   Jobs.
	 * @param string                         $source Source.
	 *
	 * @return bool
	 */
	private function run_all_still_owns_source( array $jobs, string $source ): bool {
		foreach ( $jobs as $job ) {
			if ( ! str_starts_with( (string) ( $job['key'] ?? '' ), 'run_all' ) ) {
				continue;
			}
			if ( ! in_array( $job['status'] ?? '', array( 'running', 'paused' ), true ) ) {
				continue;
			}
			foreach ( (array) ( $job['stages'] ?? array() ) as $stage ) {
				$stage_source = (string) ( $stage['tab'] ?? $stage['panel'] ?? '' );
				$stage_source = array( 'webpstats' => 'webp' )[ $stage_source ] ?? $stage_source;
				if ( $stage_source === $source && in_array( (string) ( $stage['status'] ?? '' ), array( 'queued', 'running', 'failed' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Sync finished jobs.
	 *
	 * @param array $jobs Jobs.
	 */
	private function sync_finished_jobs( array $jobs ): void {
		$history = (array) get_user_meta( get_current_user_id(), 'lucidlab_mpc_finished_jobs', true );
		$allowed = $this->finished_job_sources();
		// Older releases recorded a quarantine task as the source scan. Restore the
		// actual scan timestamp before merging the current job list.
		foreach ( $history as $source => $entry ) {
			if ( 'quarantine' !== (string) ( $entry['replay']['op'] ?? '' ) ) {
				continue;
			}
			$summary = get_option( 'lucidlab_mpc_summary_' . $source, array() );
			if ( ! empty( $summary['updated'] ) ) {
				$history[ $source ] = $this->finished_entry( $history, $source, 'done', (int) $summary['updated'], $this->default_finished_replay( $source ) );
			} else {
				unset( $history[ $source ] );
			}
		}
		foreach ( $jobs as $job ) {
			$source = $job['scan_tab'] ?? $job['panel'] ?? '';
			$source = array( 'webpstats' => 'webp' )[ $source ] ?? $source;
			if ( str_starts_with( $job['key'] ?? '', 'run_all' ) ) {
				foreach ( (array) ( $job['stages'] ?? array() ) as $stage ) {
					if ( 'done' !== ( $stage['status'] ?? '' ) || 'cleanup' === ( $stage['kind'] ?? '' ) || empty( $stage['panel'] ) ) {
						continue;
					}
					$stage_source = (string) ( $stage['tab'] ?? $stage['panel'] );
					$stage_source = array( 'webpstats' => 'webp' )[ $stage_source ] ?? $stage_source;
					if ( ! isset( $allowed[ $stage_source ] ) ) {
						continue;
					}
					$completed_at = (int) ( $stage['completed_at'] ?? 0 );
					if ( $completed_at < 1 ) {
						$completed_at = (int) ( $job['updated'] ?? time() );
					}
					$prev_status  = (string) ( $history[ $stage_source ]['status'] ?? '' );
					$prev_updated = (int) ( $history[ $stage_source ]['updated'] ?? 0 );
					// A running row stores the job clock, which is newer than this stage's own finish time.
					if ( in_array( $prev_status, array( 'running', 'paused', '' ), true ) || $completed_at >= $prev_updated ) {
						$history[ $stage_source ] = $this->finished_entry( $history, $stage_source, 'done', $completed_at );
					}
				}
				continue;
			}
			if ( 'quarantine' === (string) ( $job['op'] ?? '' ) ) {
				continue;
			}
			if ( ! $source || ! isset( $allowed[ $source ] ) ) {
				continue;
			}
			if ( (int) ( $job['updated'] ?? 0 ) >= (int) ( $history[ $source ]['updated'] ?? 0 ) || 'done' === ( $job['status'] ?? '' ) ) {
				$stamp = (int) ( $job['updated'] ?? 0 );
				if ( 'done' === ( $job['status'] ?? '' ) ) {
					$scan_summary = get_option( 'lucidlab_mpc_summary_' . $source, array() );
					if ( ! empty( $scan_summary['updated'] ) ) {
						$stamp = max( $stamp, (int) $scan_summary['updated'] );
					}
				}
				$history[ $source ] = $this->finished_entry( $history, $source, (string) ( $job['status'] ?? '' ), $stamp, $this->replay_from_job( $job ) );
			}
		}
		$active = array();
		foreach ( $jobs as $job ) {
			if ( 'quarantine' === (string) ( $job['op'] ?? '' ) ) {
				continue;
			}
			$source = $job['scan_tab'] ?? $job['panel'] ?? '';
			$source = array( 'webpstats' => 'webp' )[ $source ] ?? $source;
			if ( str_starts_with( $job['key'] ?? '', 'run_all' ) && in_array( $job['status'] ?? '', array( 'running', 'paused' ), true ) ) {
				foreach ( (array) ( $job['stages'] ?? array() ) as $stage ) {
					if ( 'running' !== ( $stage['status'] ?? '' ) || empty( $stage['panel'] ) ) {
						continue;
					}
					$stage_source = array( 'webpstats' => 'webp' )[ $stage['panel'] ] ?? $stage['panel'];
					if ( ! isset( $allowed[ $stage_source ] ) ) {
						continue;
					}
					$active[ $stage_source ]  = true;
					$history[ $stage_source ] = $this->finished_entry( $history, $stage_source, (string) $job['status'], (int) ( $job['updated'] ?? 0 ) );
				}
			}
			if ( $source && isset( $allowed[ $source ] ) && ( ! empty( $job['pending'] ) || in_array( $job['status'] ?? '', array( 'running', 'paused' ), true ) ) ) {
				$active[ $source ] = true;
			}
		}
		foreach ( $history as $source => $entry ) {
			if ( ! isset( $allowed[ $source ] ) ) {
				unset( $history[ $source ] );
				continue;
			}
			$entry = is_array( $entry ) ? $entry : array();
			if ( empty( $active[ $source ] ) && in_array( $entry['status'] ?? '', array( 'running', 'paused' ), true ) ) {
				if ( $this->run_all_still_owns_source( $jobs, (string) $source ) ) {
					continue;
				}
				$terminal           = $this->latest_finished_job_status( $jobs, $source );
				$summary            = get_option( 'lucidlab_mpc_summary_' . $source, array() );
				$status             = in_array( $terminal, array( 'cancelled', 'failed', 'done' ), true ) ? $terminal : 'cancelled';
				$history[ $source ] = $this->finished_entry( $history, $source, $status, (int) ( $summary['updated'] ?? ( $entry['updated'] ?? 0 ) ) );
			}
		}
		update_user_meta( get_current_user_id(), 'lucidlab_mpc_finished_jobs', $history );
	}

	/**
	 * Render finished jobs.
	 *
	 * @param array $jobs Jobs.
	 */
	private function render_finished_jobs( array $jobs ): void {
		$this->sync_finished_jobs( $jobs );
		$history   = (array) get_user_meta( get_current_user_id(), 'lucidlab_mpc_finished_jobs', true );
		$allowed   = $this->finished_job_sources();
		$run_again = __( 'Run again', 'lucidlab-mpc-cleanup' );
		echo '<aside class="lucidlab-mpc-finished-jobs lucidlab-mpc-card"><h2 class="lucidlab-mpc-card__title">' . esc_html__( 'Finished Jobs', 'lucidlab-mpc-cleanup' ) . '</h2><dl>';
		foreach ( $allowed as $source => $label ) {
			$entry   = $history[ $source ] ?? array();
			$summary = get_option( 'lucidlab_mpc_summary_' . $source, array() );
			// Prefer a real finished-job record over inventing "Completed" from a partial summary stamp.
			if ( ! $entry ) {
				$terminal = $this->latest_finished_job_status( $jobs, $source );
				if ( in_array( $terminal, array( 'cancelled', 'failed', 'done' ), true ) ) {
					$entry = array(
						'status'  => $terminal,
						'updated' => (int) ( $summary['updated'] ?? 0 ),
					);
				} elseif ( ! empty( $summary['updated'] ) ) {
					// The tab shows this saved summary, so Finished Jobs must not say "Never run".
					// Older scans, other administrators and Run All saved results without a per-user Finished Jobs row.
					$entry = array(
						'status'  => 'done',
						'updated' => (int) $summary['updated'],
					);
				}
			}
			$state = $entry['status'] ?? '';
			if ( 'done' === $state ) {
				$entry['updated'] = $this->aligned_scan_stamp( (int) ( $entry['updated'] ?? 0 ), (int) ( $summary['updated'] ?? 0 ) );
			}
			$date = ! empty( $entry['updated'] ) ? LucidLab_MPC_Helpers::job_datetime( (int) $entry['updated'] ) : '';
			if ( '' === $state && 'product_copy' === $source && ! empty( $summary['updated'] ) ) {
				$state            = 'done';
				$entry['updated'] = (int) $summary['updated'];
				$date             = LucidLab_MPC_Helpers::job_datetime( (int) $summary['updated'] );
			}
			$text = match ( $state ) {
				'running'   => __( 'Currently running', 'lucidlab-mpc-cleanup' ),
				'paused'    => __( 'Paused', 'lucidlab-mpc-cleanup' ),
				'done'      => __( 'Completed on', 'lucidlab-mpc-cleanup' ) . ' ' . $date,
				'cancelled' => __( 'Cancelled on', 'lucidlab-mpc-cleanup' ) . ' ' . $date,
				'failed'    => __( 'Failed on', 'lucidlab-mpc-cleanup' ) . ' ' . $date,
				default     => ( 'product_copy' === $source && $this->product_copy_suggestions_saved() )
					? __( 'Suggestions saved. The product scan has not been run.', 'lucidlab-mpc-cleanup' )
					: __( 'Never run', 'lucidlab-mpc-cleanup' ),
			};
			$tone = match ( $state ) {
				'running'   => 'running',
				'paused'    => 'paused',
				'done'      => 'done',
				'cancelled' => 'cancelled',
				'failed'    => 'failed',
				default     => 'never',
			};
			$actions = '';
			if ( ! in_array( $tone, array( 'running', 'paused' ), true ) ) {
				if ( 'never' === $tone ) {
					// Never-run rows only need Open — Run again would start a job with no prior result.
					$actions = '<span class="lucidlab-mpc-finished-actions">' . sprintf(
						'<a class="lucidlab-mpc-finished-open" href="%1$s">%2$s</a>',
						esc_url( $this->tab_url( $source ) ),
						esc_html__( 'Open', 'lucidlab-mpc-cleanup' )
					) . '</span>';
				} else {
					$actions = '<span class="lucidlab-mpc-finished-actions">' . sprintf(
						'<a class="lucidlab-mpc-finished-rerun" href="#">%s</a>',
						esc_html( $run_again )
					) . '</span>';
				}
			}
			$replay          = is_array( $entry['replay'] ?? null ) && $entry['replay'] ? $entry['replay'] : $this->default_finished_replay( $source );
			$conversion_note = '';
			if ( 'webp' === $source && isset( $this->webp ) && method_exists( $this->webp, 'job' ) ) {
				$conversion      = (array) $this->webp->job();
				$conversion_time = (int) ( $conversion['updated'] ?? 0 );
				if ( $conversion_time > 0 && (int) ( $entry['updated'] ?? 0 ) !== $conversion_time && in_array( $conversion['status'] ?? '', array( 'done', 'cancelled', 'failed' ), true ) ) {
					$conversion_status = match ( $conversion['status'] ) {
						'cancelled' => __( 'Cancelled on', 'lucidlab-mpc-cleanup' ),
						'failed'    => __( 'Failed on', 'lucidlab-mpc-cleanup' ),
						default     => __( 'Completed on', 'lucidlab-mpc-cleanup' ),
					};
					$conversion_note = '<small class="lucidlab-mpc-finished-conversion">' . esc_html( __( 'Generate copies', 'lucidlab-mpc-cleanup' ) . ' — ' . $conversion_status . ' ' . LucidLab_MPC_Helpers::job_datetime( $conversion_time ) ) . '</small>';
				}
			}
			echo '<div class="lucidlab-mpc-finished-job lucidlab-mpc-finished-job--' . esc_attr( $tone ) . '" data-finished-source="' . esc_attr( $source ) . '" data-finished-status="' . esc_attr( $tone ) . '" data-finished-updated="' . esc_attr( $entry['updated'] ?? 0 ) . '" data-finished-replay="' . esc_attr( wp_json_encode( $replay ) ) . '"><dt><a href="' . esc_url( $this->tab_url( $source ) ) . '">' . esc_html( $label ) . '</a></dt><dd><span class="lucidlab-mpc-finished-status">' . esc_html( $text ) . '</span>' . wp_kses_post( $conversion_note . $actions ) . '</dd></div>';
		}
		echo '</dl></aside>';
	}

	/**
	 * Full current MySQL database size and table count via information_schema.
	 *
	 * @return array{db_bytes:int,table_count:int}
	 */
	protected function database_schema_stats(): array {
		global $wpdb;
		$schema = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		if ( '' === $schema ) {
			return array(
				'db_bytes'    => 0,
				'table_count' => 0,
			);
		}
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			$wpdb->prepare(
				'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS bytes, COUNT(*) AS tables FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s',
				$schema
			),
			ARRAY_A
		);
		return array(
			'db_bytes'    => (int) ( $row['bytes'] ?? 0 ),
			'table_count' => (int) ( $row['tables'] ?? 0 ),
		);
	}

	/**
	 * A reused reference index used to store only the rows patched on that pass. The published index is the set this scan actually checked.
	 *
	 * @param array<string,mixed> $counts Counts.
	 *
	 * @return array<string,mixed>
	 */
	private function heal_missing_checked( array $counts ): array {
		if ( ! array_key_exists( 'checked', $counts ) || ! LucidLab_MPC_Work::exists( LucidLab_MPC_Reference_Index::SHARED ) ) {
			return $counts;
		}
		$rows = $this->missing_index_records();
		if ( $rows <= (int) $counts['checked'] ) {
			return $counts;
		}
		$counts['checked'] = $rows;
		$summary           = get_option( 'lucidlab_mpc_summary_missing', array() );
		if ( is_array( $summary ) && is_array( $summary['counts'] ?? null ) && (int) ( $summary['counts']['checked'] ?? 0 ) < $rows ) {
			$summary['counts']['checked'] = $rows;
			update_option( 'lucidlab_mpc_summary_missing', $summary, false );
		}
		return $counts;
	}

	/**
	 * Missing index records.
	 *
	 * @return int
	 */
	private function missing_index_records(): int {
		$path = LucidLab_MPC_Work::path( LucidLab_MPC_Reference_Index::SHARED );
		clearstatcache( true, $path );
		$key    = (string) ( is_file( $path ) ? filesize( $path ) : false ) . ':' . (string) ( is_file( $path ) ? filemtime( $path ) : false );
		$cached = get_transient( 'lucidlab_mpc_ref_rowcount' );
		if ( is_array( $cached ) && (string) ( $cached['key'] ?? '' ) === $key ) {
			return (int) ( $cached['rows'] ?? 0 );
		}
		$rows = LucidLab_MPC_Work::count_lines( LucidLab_MPC_Reference_Index::SHARED );
		set_transient(
			'lucidlab_mpc_ref_rowcount',
			array(
				'key'  => $key,
				'rows' => $rows,
			),
			DAY_IN_SECONDS
		);
		return $rows;
	}

	/**
	 * Drop duplicate array-count keys and non-display fields from a saved scan summary.
	 *
	 * @param array  $counts Counts.
	 * @param string $tab    Tab.
	 *
	 * @return array
	 */
	private function summary_counts_for_display( array $counts, string $tab = '' ): array {
		$aliases = array(
			'groups'  => 'group_count',
			'orphans' => 'orphan_count',
			'images'  => 'image_count',
		);
		foreach ( $aliases as $from => $to ) {
			if ( ! array_key_exists( $from, $counts ) ) {
				continue;
			}
			if ( ! array_key_exists( $to, $counts ) ) {
				$counts[ $to ] = $counts[ $from ];
			}
			unset( $counts[ $from ] );
		}
		foreach ( array( 'type', 'mode', 'done', 'progress', 'counts', 'acf_active', 'orphan_units_file', 'images_file', 'batch_count', 'last_id', 'scan', 'outcome' ) as $skip ) {
			unset( $counts[ $skip ] );
		}
		if ( 'dbcleaner' === $tab ) {
			$schema                = $this->database_schema_stats();
			$counts['db_bytes']    = $schema['db_bytes'];
			$counts['table_count'] = $schema['table_count'];
			// Candidate tables / orphan record groups are not shown as summary squares.
			unset( $counts['total'], $counts['orphan_records'] );
			$order   = array( 'db_bytes', 'table_count', 'bytes', 'record_bytes', 'record_rows', 'optimize_bytes', 'inactive_plugins', 'missing_plugins', 'rows' );
			$ordered = array();
			foreach ( $order as $key ) {
				if ( ! array_key_exists( $key, $counts ) ) {
					continue;
				}
				$ordered[ $key ] = $counts[ $key ];
				unset( $counts[ $key ] );
			}
			foreach ( $counts as $key => $value ) {
				$ordered[ $key ] = $value;
			}
			return $ordered;
		}
		// The saved scan count belongs to that scan. A newer shared reference index
		// can contain additional rows and must not rewrite an older result card.
		return $counts;
	}

	/**
	 * Summary count label.
	 *
	 * @param string $key Key.
	 *
	 * @return string
	 */
	private function summary_count_label( string $key ): string {
		$labels = array(
			'orphan_count'      => __( 'Orphans', 'lucidlab-mpc-cleanup' ),
			'protected'         => __( 'Still in use', 'lucidlab-mpc-cleanup' ),
			'scanned'           => __( 'Attachments checked', 'lucidlab-mpc-cleanup' ),
			'reclaim_bytes'     => __( 'Reclaimable', 'lucidlab-mpc-cleanup' ),
			'orphan_bytes'      => __( 'Reclaimable', 'lucidlab-mpc-cleanup' ),
			'orphan_files'      => __( 'Orphan files', 'lucidlab-mpc-cleanup' ),
			'orphan_groups'     => __( 'Orphan groups', 'lucidlab-mpc-cleanup' ),
			'group_count'       => __( 'Duplicate groups', 'lucidlab-mpc-cleanup' ),
			'db_bytes'          => __( 'Database size', 'lucidlab-mpc-cleanup' ),
			'table_count'       => __( 'Total tables', 'lucidlab-mpc-cleanup' ),
			'total'             => __( 'Candidate tables', 'lucidlab-mpc-cleanup' ),
			'inactive_plugins'  => __( 'Inactive plugin tables', 'lucidlab-mpc-cleanup' ),
			'missing_plugins'   => __( 'Missing plugin tables', 'lucidlab-mpc-cleanup' ),
			'bytes'             => __( 'Reclaimable size', 'lucidlab-mpc-cleanup' ),
			'rows'              => __( 'Table rows', 'lucidlab-mpc-cleanup' ),
			'orphan_records'    => __( 'Orphan record groups', 'lucidlab-mpc-cleanup' ),
			'record_bytes'      => __( 'Orphan record size', 'lucidlab-mpc-cleanup' ),
			'record_rows'       => __( 'Orphan rows', 'lucidlab-mpc-cleanup' ),
			'optimize_bytes'    => __( 'OPTIMIZE reclaimable', 'lucidlab-mpc-cleanup' ),
			'dupe_count'        => __( 'Removable copies', 'lucidlab-mpc-cleanup' ),
			'file_copies'       => __( 'Extra files on disk', 'lucidlab-mpc-cleanup' ),
			'same_file_records' => __( 'Records sharing one file', 'lucidlab-mpc-cleanup' ),
			'variation_sum'     => __( 'Variations cascading', 'lucidlab-mpc-cleanup' ),
			'image_count'       => __( 'Shared images', 'lucidlab-mpc-cleanup' ),
			'product_links'     => __( 'Item references', 'lucidlab-mpc-cleanup' ),
			'total_files'       => __( 'Files', 'lucidlab-mpc-cleanup' ),
			'total_bytes'       => __( 'Total size', 'lucidlab-mpc-cleanup' ),
			'registered_count'  => __( 'Registered media paths (including sizes)', 'lucidlab-mpc-cleanup' ),
			'registered_bytes'  => __( 'Protected size', 'lucidlab-mpc-cleanup' ),
			'checked'           => __( 'Content records checked this run', 'lucidlab-mpc-cleanup' ),
			'missing'           => __( 'Missing attachments', 'lucidlab-mpc-cleanup' ),
			'products_missing'  => __( 'Products missing images', 'lucidlab-mpc-cleanup' ),
			'broken_urls'       => __( 'Broken content URLs', 'lucidlab-mpc-cleanup' ),
			'images_checked'    => __( 'Checked', 'lucidlab-mpc-cleanup' ),
			'candidate_groups'  => __( 'Candidate groups', 'lucidlab-mpc-cleanup' ),
			'orig_count'        => __( 'Original files', 'lucidlab-mpc-cleanup' ),
			'webp_count'        => __( 'WebP files', 'lucidlab-mpc-cleanup' ),
			'avif_count'        => __( 'AVIF files', 'lucidlab-mpc-cleanup' ),
			'skipped'           => __( 'Skipped', 'lucidlab-mpc-cleanup' ),
		);
		return $labels[ $key ] ?? ucwords( str_replace( '_', ' ', $key ) );
	}

	/**
	 * Counters whose result list is gone (expired, older format, or deleted work file) describe a list nobody
	 * can open. Forget them so the tab reads Never run instead of showing old numbers with a warning.
	 *
	 * @param string $tab     Tab.
	 * @param mixed  $summary Saved summary option.
	 *
	 * @return mixed Summary, or false when it was dropped.
	 */
	private function drop_orphaned_summary( string $tab, $summary ) {
		$list_tabs = array( 'orphans', 'uploads', 'missing', 'shared', 'duplicates', 'dupcontent', 'visual', 'product_copy' );
		if ( ! in_array( $tab, $list_tabs, true ) || ! is_array( $summary ) || empty( $summary['counts'] ) || ! empty( $_GET['lucidlab_mpc_scanned'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
			return $summary;
		}
		if ( $this->task_result_exists( $tab ) || $this->summary_is_clean_scan( $tab, (array) $summary['counts'] ) ) {
			return $summary;
		}
		delete_option( 'lucidlab_mpc_summary_' . $tab );
		return false;
	}

	/**
	 * Red notice when counters are from a summary snapshot but the detailed list is not loaded.
	 */
	private function render_results_snapshot_notice(): void {
		printf(
			'<div class="notice notice-error inline lucidlab-mpc-job-summary lucidlab-mpc-missing-results-notice" role="status">%s</div>',
			esc_html__( 'No saved result list is available. Run a new scan to refresh the findings; the counters may show an older snapshot.', 'lucidlab-mpc-cleanup' )
		);
	}

	/**
	 * Render scan complete notice.
	 *
	 * @param string $tab Tab.
	 */
	protected function render_scan_complete_notice( string $tab ): void {
		$summary = get_option( 'lucidlab_mpc_summary_' . $tab, array() );
		$history = (array) get_user_meta( get_current_user_id(), 'lucidlab_mpc_finished_jobs', true );
		$entry   = is_array( $history[ $tab ] ?? null ) ? $history[ $tab ] : array();
		$updated = $this->aligned_scan_stamp( (int) ( $entry['updated'] ?? 0 ), (int) ( $summary['updated'] ?? 0 ) );
		$state   = (string) ( $entry['status'] ?? '' );
		if ( 'webp' === $tab && $updated < 1 ) {
			$alt = get_option( 'lucidlab_mpc_summary_webpstats', array() );
			if ( (int) ( $alt['updated'] ?? 0 ) > 0 ) {
				$summary = $alt;
				$updated = (int) $alt['updated'];
				$state   = ( $state ? $state : 'done' );
			}
		}
		// Check live pages keeps its own status line next to its button. A cancelled crawl must not mark the Missing Images scan as cancelled.
		if ( $updated < 1 ) {
			return;
		}
		if ( ! in_array( $state, array( 'done', 'cancelled', 'failed' ), true ) ) {
			// A finished uploads scan can reach the results page before Finished Jobs
			// has stored "done". The saved result is enough to show Completed on.
			if ( ! $this->task_result_exists( $tab ) ) {
				return;
			}
			$state = 'done';
		}
		$prefix       = 'cancelled' === $state ? __( 'Cancelled on:', 'lucidlab-mpc-cleanup' ) : ( 'failed' === $state ? __( 'Failed on:', 'lucidlab-mpc-cleanup' ) : __( 'Completed on:', 'lucidlab-mpc-cleanup' ) );
		$source_label = 'fs-sizes' === $tab ? __( 'Other Sizes on Disk', 'lucidlab-mpc-cleanup' ) . ' — ' : ( 'sizes' === $tab ? __( 'Analyze sizes', 'lucidlab-mpc-cleanup' ) . ' — ' : '' );
		echo '<div class="notice notice-info inline lucidlab-mpc-job-summary" data-mpc-job-summary="' . esc_attr( $tab ) . '" data-mpc-completed="' . (int) $updated . '">' . esc_html( $source_label . $prefix . ' ' . LucidLab_MPC_Helpers::job_datetime( $updated ) ) . '</div>';
	}

	/**
	 * Render shell.
	 *
	 * @param string $tab     Tab.
	 * @param mixed  $result  Result.
	 * @param array  $notices Notices.
	 */
	private function render_shell( string $tab, $result, array $notices ): void {
		$is_run_all        = is_array( $result ) && ! empty( $result['run_all'] );
		$initial_task_jobs = array();
		if ( class_exists( 'LucidLab_MPC_Task_Job' ) ) {
			try {
				$initial_task_jobs = ( new LucidLab_MPC_Task_Job( $this ) )->command( 'list', array() )['jobs'] ?? array();
				$initial_task_jobs = array_merge( $initial_task_jobs, $this->special_job_states() );
			} catch ( Throwable $e ) {
				$initial_task_jobs = array();
			}
		}
		// The completion notice is printed before Finished Jobs, so refresh that history first.
		$this->sync_finished_jobs( $initial_task_jobs );
		// Single-tab loads fetch that tab's payload only. Accept it even when older saves omit `type`.
		$pick                  = static function ( $key ) use ( $result, $is_run_all, $tab ) {
			if ( ! is_array( $result ) ) {
				return null;
			}
			if ( $is_run_all ) {
				return $result[ $key ] ?? null;
			}
			$type = (string) ( $result['type'] ?? '' );
			if ( $type === $key || ( $key === $tab && ( '' === $type || $type === $key ) ) ) {
				if ( '' === $type ) {
					$result['type'] = $key;
				}
				return $result;
			}
			return null;
		};
		$lucidlab_mpc_logo_url = $this->brand_logo_url();
		$lucidlab_mpc_title    = __( 'Media & Products Cleanup', 'lucidlab-mpc-cleanup' );
		?>
		<div class="wrap lucidlab-mpc-wrap lucidlab-mpc-with-finished-jobs">

			<header class="lucidlab-mpc-admin-head">
				<div class="lucidlab-mpc-admin-title">
					<img class="lucidlab-mpc-admin-logo" src="<?php echo esc_url( $lucidlab_mpc_logo_url ); ?>" alt="" width="52" height="52" />
					<div class="lucidlab-mpc-admin-title-text">
						<h1 class="lucidlab-mpc-title">
							<?php
							if ( preg_match( '/^(.*)\s+(\S+)$/u', $lucidlab_mpc_title, $lucidlab_mpc_title_parts ) ) {
								echo '<span class="lucidlab-mpc-title-brand">' . esc_html( $lucidlab_mpc_title_parts[1] ) . '</span> ';
								echo '<span class="lucidlab-mpc-title-name">' . esc_html( $lucidlab_mpc_title_parts[2] ) . '</span>';
							} else {
								echo esc_html( $lucidlab_mpc_title );
							}
							?>
						</h1>
						<p class="lucidlab-mpc-ver">v<?php echo esc_html( LUCIDLAB_MPC_VERSION ); ?> <?php esc_html_e( 'Free', 'lucidlab-mpc-cleanup' ); ?>
						<?php
						if ( defined( 'LUCIDLAB_MPC_PRO_VERSION' ) ) :
							?>
							&amp; v<?php echo esc_html( LUCIDLAB_MPC_PRO_VERSION ); ?> <?php esc_html_e( 'Pro', 'lucidlab-mpc-cleanup' ); ?><?php endif; ?> <span aria-hidden="true">·</span> <a class="lucidlab-mpc-head-link" href="<?php echo esc_url( lucidlab_mpc_docs_url() ); ?>" target="_blank" rel="noopener noreferrer" data-testid="docs-link"><?php esc_html_e( 'Documentation', 'lucidlab-mpc-cleanup' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'lucidlab-mpc-cleanup' ); ?></span></a></p>
					</div>
				</div>
			</header>
			<hr class="wp-header-end">

			<div class="lucidlab-mpc-jobs-layout"><div class="lucidlab-mpc-main-column">
			<div class="lucidlab-mpc-admin-body">
			<aside class="lucidlab-mpc-nav-card lucidlab-mpc-card">
			<nav class="lucidlab-mpc-tabs lucidlab-mpc-tabs--sidebar" aria-label="<?php esc_attr_e( 'Media & Products Cleanup sections', 'lucidlab-mpc-cleanup' ); ?>">
					<?php
					$navs = $this->menu_tabs();
					foreach ( $navs as $key => $label ) {
						// Pro sections keep their Pro badge, also when Pro is active; locked ones are muted.
						$is_pro_tab = in_array( $key, LucidLab_MPC_Edition::pro_badge_tabs(), true );
						$badge      = $is_pro_tab ? ' ' . self::pro_badge() : '';
						printf(
							'<a href="%s" class="nav-tab %s"%s data-testid="tab-%s">%s%s</a>',
							esc_url( $this->tab_url( $key ) ),
							( $key === $tab ? 'nav-tab-active' : '' ) . ( $is_pro_tab && ! LucidLab_MPC_Edition::pro() ? ' lucidlab-mpc-tab-locked' : '' ),
							$key === $tab ? ' aria-current="page"' : '',
							esc_attr( $key ),
							esc_html( $label ),
							wp_kses_post( $badge )
						);
					}
					$panel_tabs = array_intersect_key( $navs, array( $tab => true ) );
					if ( ! $panel_tabs ) {
						$panel_tabs = array( $tab => $tab );
					}
					?>
			</nav>
			</aside>
			<div class="lucidlab-mpc-admin-content">
			<div id="lucidlab-mpc-top-notices" class="lucidlab-mpc-top-notices" data-testid="top-notices"><?php $this->render_pro_inactive_notice(); ?></div>
<aside id="lucidlab-mpc-running-jobs" class="lucidlab-mpc-running-jobs lucidlab-mpc-card" hidden><h2 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Current Running Jobs', 'lucidlab-mpc-cleanup' ); ?></h2><p class="lucidlab-mpc-hint lucidlab-mpc-running-jobs-note lucidlab-mpc-card__lede"><?php echo LucidLab_MPC_Edition::pro() ? esc_html__( 'Pro can run several jobs at once. Stop pauses after the current batch; completed changes remain. Background processing uses WP-Cron. Run All still needs other jobs to finish first.', 'lucidlab-mpc-cleanup' ) : esc_html__( 'Free runs one job at a time — including at most one background job. Stop pauses after the current batch; completed changes remain. Free jobs can continue in the background with WP-Cron. Parallel jobs require Pro.', 'lucidlab-mpc-cleanup' ); ?></p><div data-mpc-running-jobs-list></div></aside><div id="lucidlab-mpc-run-all-progress" class="lucidlab-mpc-run-all-progress" hidden></div><?php wp_add_inline_script( 'lucidlab-mpc-admin', 'window.lucidlabMpcInitialJobs = ' . wp_json_encode( $initial_task_jobs ) . ';', 'before' ); ?>

		<?php foreach ( $panel_tabs as $panel_tab => $panel_label ) : ?>
				<?php
				$this->lucidlab_mpc_card_overview_open = false;
				$this->lucidlab_mpc_card_results_open  = false;
				$panel_classes                         = 'lucidlab-mpc-panel lucidlab-mpc-panel--cards';
				?>
			<div class="<?php echo esc_attr( $panel_classes ); ?>" data-mpc-panel="<?php echo esc_attr( $panel_tab ); ?>" <?php echo $panel_tab === $tab ? '' : 'hidden'; ?>>
				<?php
				$this->render_page_hero( $panel_tab, (string) $panel_label );
				if ( 'options' !== $panel_tab ) {
					$this->render_ping_notice();
				}
				if ( in_array( $panel_tab, LucidLab_MPC_Edition::pro_tabs(), true ) && ! LucidLab_MPC_Edition::pro() ) {
					// Keep the locked Pro card directly under the compact header.
					$this->render_locked_tab( $panel_tab );
					echo '</div>';
					continue;
				}
				// Guide/Backup open their own card wrappers; an empty overview shell would show as a blank white box.
				if ( ! in_array( $panel_tab, array( 'welcome', 'guide', 'backup' ), true ) ) {
					$this->begin_overview_card( 'dbcleaner' === $panel_tab );
				}
				$exposure = LucidLab_MPC_Quarantine::public_access_notice();
				if ( '' !== $exposure ) {
					array_unshift( $notices, array( 'warning', $exposure ) );
				}
				// Options / Backup / Logs: blue confirmation messages in a white card under the hero.
				if ( in_array( $panel_tab, array( 'options', 'backup', 'logs' ), true ) ) {
					$this->end_overview_card();
					$this->render_hero_notice_card( $notices, true );
					if ( 'logs' === $panel_tab ) {
						$this->begin_overview_card();
					}
				} elseif ( 'options' !== $panel_tab ) {
					$this->render_notices( $notices );
				}
				if ( 'missing' === $panel_tab ) {
					$this->drop_converted_broken_urls();
				}
				$summary = get_option( 'lucidlab_mpc_summary_' . $panel_tab );
				$summary = $this->drop_orphaned_summary( $panel_tab, $summary );
				if ( 'webp' === $panel_tab && class_exists( 'LucidLab_MPC_Webp', false ) ) {
					$live_stats = LucidLab_MPC_Webp::get_stats();
					if ( ! is_array( $summary ) ) {
						$summary = array();
					}
					$summary['counts'] = array(
						'orig_count' => (int) ( $live_stats['orig_count'] ?? 0 ),
						'webp_count' => (int) ( $live_stats['webp_count'] ?? 0 ),
						'avif_count' => (int) ( $live_stats['avif_count'] ?? 0 ),
					);
				}
				if ( 'uploads' === $panel_tab && is_array( $result ) && isset( $result['orphan_units'] ) && is_array( $summary ) ) {
					foreach ( array( 'orphan_bytes', 'orphan_files', 'orphan_groups' ) as $count_key ) {
						$summary['counts'][ $count_key ] = $result[ $count_key ];
					}
				}
				$panel_result = $pick( $panel_tab );
				// Status notices first so last-run time is visible without scrolling.
				$scan_status_tabs = array( 'orphans', 'uploads', 'sizes', 'missing', 'shared', 'duplicates', 'dupcontent', 'visual', 'webp', 'dbcleaner' );
				if ( in_array( $panel_tab, $scan_status_tabs, true ) ) {
					// WebP statistics and conversion are independent jobs with separate notices.
					$this->render_scan_complete_notice( 'webp' === $panel_tab ? 'webpstats' : $panel_tab );
					if ( 'sizes' === $panel_tab && LucidLab_MPC_Edition::pro() ) {
						$this->render_scan_complete_notice( 'fs-sizes' );
					}
				}
				$summary_counts = ( is_array( $summary ) && is_array( $summary['counts'] ?? null ) ) ? $summary['counts'] : array();
				$saved_list     = is_array( $panel_result ) || $this->task_result_exists( $panel_tab );
				// WebP stats are aggregate counts, not a saved list of individual
				// results. A fresh stats scan must not show a missing-list warning.
				$stale_snapshot = 'webp' !== $panel_tab && empty( $_GET['lucidlab_mpc_scanned'] ) && $summary_counts && ! $saved_list && ! $this->summary_is_clean_scan( $panel_tab, $summary_counts ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
				$counts         = array();
				if ( 'sizes' !== $panel_tab && 'product_copy' !== $panel_tab && $summary_counts && ! $stale_snapshot ) {
					$counts = $this->summary_counts_for_display( $summary_counts, $panel_tab );
				} elseif ( 'dbcleaner' === $panel_tab && ! $stale_snapshot ) {
					// No saved scan yet: still show the live database size and table count.
					$counts = $this->summary_counts_for_display( array(), 'dbcleaner' );
				}
				$live_counts = array();
				if ( 'dbcleaner' === $panel_tab && $stale_snapshot ) {
					$live = $this->summary_counts_for_display( array(), 'dbcleaner' );
					foreach ( array( 'db_bytes', 'table_count' ) as $live_key ) {
						if ( array_key_exists( $live_key, $live ) ) {
							$live_counts[ $live_key ] = $live[ $live_key ];
						}
					}
				}
				// Duplicate Products reprints counters with its result list. Uploads keeps the overview
				// snapshot squares after View current results (results card is the file list only).
				$detailed_stats = is_array( $panel_result ) && 'duplicates' === $panel_tab;
				if ( $live_counts ) {
					echo '<div class="lucidlab-mpc-stats lucidlab-mpc-stats-live" data-mpc-live-stats="1">';
					foreach ( $live_counts as $key => $count ) {
						$label = $this->summary_count_label( (string) $key );
						echo '<div class="lucidlab-mpc-stat lucidlab-mpc-stat-hero" data-mpc-stat-key="' . esc_attr( (string) $key ) . '"><span class="lucidlab-mpc-num">' . esc_html( strpos( (string) $key, 'bytes' ) !== false ? LucidLab_MPC_Helpers::bytes( $count ) : number_format_i18n( (int) $count ) ) . '</span><span class="lucidlab-mpc-lbl">' . esc_html( $label ) . '</span></div>';
					}
					echo '</div>';
				}
				if ( $stale_snapshot ) {
					$this->render_results_snapshot_notice();
				}
				if ( $counts && ! $detailed_stats ) {
					echo '<div class="lucidlab-mpc-stats lucidlab-mpc-stats-snapshot">';
					foreach ( $counts as $key => $count ) {
						$hero  = in_array( $key, array( 'orphan_count', 'missing', 'broken_urls', 'products_missing', 'image_count', 'reclaim_bytes', 'orphan_bytes', 'group_count', 'dupe_count', 'db_bytes', 'bytes' ), true );
						$label = 'visual' === $panel_tab && 'checked' === $key ? __( 'Images checked', 'lucidlab-mpc-cleanup' ) : $this->summary_count_label( (string) $key );
						echo '<div class="lucidlab-mpc-stat' . ( $hero ? ' lucidlab-mpc-stat-hero' : '' ) . '" data-mpc-stat-key="' . esc_attr( (string) $key ) . '"><span class="lucidlab-mpc-num">' . esc_html( strpos( (string) $key, 'bytes' ) !== false ? LucidLab_MPC_Helpers::bytes( $count ) : number_format_i18n( (int) $count ) ) . '</span><span class="lucidlab-mpc-lbl">' . esc_html( $label ) . '</span></div>';
					}
					echo '</div>';
				}
				switch ( $panel_tab ) {
					case 'welcome':
							$this->render_welcome();
						break;
					case 'guide':
							$this->render_scan_guide();
						break;
					case 'backup':
							$this->render_backup_panel();
						break;
					case 'orphans':
							$this->render_orphans( $pick( 'orphans' ) );
						break;
					case 'uploads':
						if ( ! LucidLab_MPC_Edition::pro() || ! method_exists( $this, 'render_uploads' ) ) {
							$this->render_locked_tab( 'uploads' );
							break;
						}
						$uploads_data  = $pick( 'uploads' );
						$uploads_total = count( $uploads_data['orphan_units'] ?? array() );
						$uploads_pages = max( 1, (int) ceil( $uploads_total / 50 ) );
						$uploads_page  = min( $uploads_pages, max( 1, absint( $_GET['lucidlab_mpc_uploads_page'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
						if ( is_array( $uploads_data ) ) {
							$uploads_data['orphan_units'] = array_slice( $uploads_data['orphan_units'] ?? array(), ( $uploads_page - 1 ) * 50, 50, true );
						}
						$this->render_uploads( $uploads_data );
						if ( $uploads_pages > 1 ) {
							echo '<p>' . esc_html__( 'Showing 50 groups per page. The checkbox in the heading selects every reclaimable file. Uncheck a row to limit the move to the checked rows on this page.', 'lucidlab-mpc-cleanup' ) . '</p><div class="tablenav-pages">';
							echo wp_kses_post(
								paginate_links(
									array(
										'base'    => $this->tab_url( 'uploads' ) . '&lucidlab_mpc_scanned=1&lucidlab_mpc_uploads_page=%#%',
										'format'  => '',
										'current' => $uploads_page,
										'total'   => $uploads_pages,
									)
								)
							);
							echo '</div>';
						}
						break;
					case 'shared':
						$this->render_shared( $pick( 'shared' ) );
						break;
					case 'sizes':
						$this->render_sizes( $pick( 'sizes' ) );
						break;
					case 'regenerate':
						$this->render_regenerate();
						break;
					case 'quarantine':
						$this->render_quarantine_tab();
						break;
					case 'missing':
						$this->render_missing();
						break;
					case 'product_copy':
						if ( LucidLab_MPC_Edition::pro() ) {
							$this->render_product_copy();
						} else {
							$this->render_locked_tab( 'product_copy' );
						}
						break;
					case 'options':
						$this->render_options( $notices );
						break;
					case 'logs':
						$this->render_logs_diagnostics();
						break;
					case 'visual':
						if ( LucidLab_MPC_Edition::pro() && method_exists( $this, 'render_visual' ) ) {
							$this->render_visual();
						} else {
							$this->render_locked_tab( 'visual' ); }
						break;
					case 'review':
						$this->render_review_page();
						break;
					case 'dupcontent':
						$this->render_dupcontent( $pick( 'dupcontent' ) );
						break;
					case 'duplicates':
						if ( LucidLab_MPC_Edition::pro() && method_exists( $this, 'render_duplicates' ) ) {
							$this->render_duplicates( $pick( 'duplicates' ) );
						} else {
							$this->render_locked_tab( 'duplicates' ); }
						break;
					case 'rename':
						$this->render_rename();
						break;
					case 'rename_ai':
						if ( LucidLab_MPC_Edition::pro() && method_exists( $this, 'render_ai_rename_panel' ) ) {
							$this->render_ai_rename_panel();
						} else {
							$this->render_locked_tab( 'rename_ai' );
						}
						break;
					case 'dbcleaner':
						if ( LucidLab_MPC_Edition::pro() && method_exists( $this, 'render_dbcleaner' ) ) {
							$this->render_dbcleaner( $pick( 'dbcleaner' ) );
						} else {
							$this->render_locked_tab( 'dbcleaner' );
						}
						break;
					case 'webp':
						$this->render_webp();
						break;
					default:
						$this->render_pro_required_notice( __( 'This tool requires the Pro add-on.', 'lucidlab-mpc-cleanup' ) );
				}
				$this->end_panel_cards();
				?>
			</div>
			<?php endforeach; ?>
			</div><!-- .lucidlab-mpc-admin-content -->
			</div><!-- .lucidlab-mpc-admin-body -->
			</div><?php $this->render_finished_jobs( $initial_task_jobs ); ?></div>
		</div>
			<?php
	}

	/**
	 * Plugin-bundled brand mark (works without the Lucid Lab theme).
	 *
	 * @param string $file File.
	 *
	 * @return string
	 */
	private function brand_logo_url( string $file = 'logo-icon.png' ): string {
		$file = sanitize_file_name( $file );
		$path = LUCIDLAB_MPC_DIR . 'assets/img/' . $file;
		$url  = LUCIDLAB_MPC_URL . 'assets/img/' . $file;
		if ( is_readable( $path ) ) {
			$url = add_query_arg( 'ver', (string) filemtime( $path ), $url );
		}
		return $url;
	}

	/**
	 * Small "Pro" badge.
	 *
	 * @return string HTML.
	 */
	private static function pro_badge(): string {
		return '<span class="lucidlab-mpc-pro-badge">' . esc_html__( 'Pro', 'lucidlab-mpc-cleanup' ) . '</span>';
	}

	/**
	 * "Get Pro" link.
	 *
	 * @param string $context Where the link is shown (added to the URL for statistics).
	 *
	 * @return string HTML.
	 */
	private static function pro_link( string $context ): string {
		$url = LucidLab_MPC_Edition::pro_url();
		if ( '#' !== $url ) {
			$url = add_query_arg( 'utm_content', rawurlencode( $context ), $url );
		}
		return '<a class="button lucidlab-mpc-pro-link" href="' . esc_url( $url ) . '"' . ( '#' === $url ? '' : ' target="_blank" rel="noopener noreferrer"' ) . ' data-testid="pro-get-link"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span> ' . esc_html__( 'Get Pro', 'lucidlab-mpc-cleanup' ) . '</a>';
	}

	/**
	 * Pro tools. While Pro is not active their tabs are listed with a Pro badge and only show a
	 * locked card that describes the tool.
	 *
	 * @return array<string,array{text:string,points:array<int,string>}>
	 */
	private static function pro_features(): array {
		return array(
			'uploads'      => array(
				'text'   => __( 'Finds files in the uploads folder that are not in the Media Library and are not named in content, and moves the ones you select to restorable quarantine.', 'lucidlab-mpc-cleanup' ),
				'points' => array(
					__( 'Files on disk that no Media Library item uses', 'lucidlab-mpc-cleanup' ),
					__( 'Files still named in posts, products or settings are kept', 'lucidlab-mpc-cleanup' ),
					__( 'Selected files move to restorable quarantine', 'lucidlab-mpc-cleanup' ),
				),
			),
			'visual'       => array(
				'text'   => __( 'Finds look-alike images, such as resized or recompressed copies, for you to review. Nothing merges automatically.', 'lucidlab-mpc-cleanup' ),
				'points' => array(
					__( 'Groups sorted by the space a merge really frees', 'lucidlab-mpc-cleanup' ),
					__( 'You choose which image to keep in every group', 'lucidlab-mpc-cleanup' ),
					__( 'Merges can be undone from the Quarantine tab', 'lucidlab-mpc-cleanup' ),
				),
			),
			'duplicates'   => array(
				'text'   => __( 'Finds WooCommerce products that share a title, a slug, or a title and SKU, so you can review the duplicates before you change anything.', 'lucidlab-mpc-cleanup' ),
				'points' => array(
					__( 'Matches on title, slug, or title and SKU', 'lucidlab-mpc-cleanup' ),
					__( 'Review every group before anything changes', 'lucidlab-mpc-cleanup' ),
				),
			),
			'rename_ai'    => array(
				'text'   => __( 'Suggests file names and alt texts based on what each image shows. You review every suggestion before it is applied.', 'lucidlab-mpc-cleanup' ),
				'points' => array(
					__( 'File name and alt-text suggestions from the image itself', 'lucidlab-mpc-cleanup' ),
					__( 'Nothing is applied until you approve it', 'lucidlab-mpc-cleanup' ),
					__( 'Applied renames can be undone', 'lucidlab-mpc-cleanup' ),
				),
			),
			'product_copy' => array(
				'text'   => __( 'Creates AI product titles, short descriptions and long descriptions from the featured image, for you to review before they are saved.', 'lucidlab-mpc-cleanup' ),
				'points' => array(
					__( 'Titles, short descriptions and long descriptions', 'lucidlab-mpc-cleanup' ),
					__( 'Review and edit every suggestion before it is saved', 'lucidlab-mpc-cleanup' ),
				),
			),
			'review'       => array(
				'text'   => __( 'One list of scanner and AI findings to triage before you quarantine or rename anything.', 'lucidlab-mpc-cleanup' ),
				'points' => array(
					__( 'Findings of the scanners and AI tools in one place', 'lucidlab-mpc-cleanup' ),
					__( 'Filter by scanner source and minimum finding count', 'lucidlab-mpc-cleanup' ),
				),
			),
			'dbcleaner'    => array(
				'text'   => __( 'Finds unused database tables and leftover records from inactive or missing plugins and moves them to restorable quarantine.', 'lucidlab-mpc-cleanup' ),
				'points' => array(
					__( 'Leftover options, meta, plugin post types and expired transients', 'lucidlab-mpc-cleanup' ),
					__( 'Action Scheduler logs, WooCommerce sessions, spam and trashed comments', 'lucidlab-mpc-cleanup' ),
					__( 'Everything moves to restorable quarantine first', 'lucidlab-mpc-cleanup' ),
				),
			),
		);
	}

	/**
	 * A locked Pro tab: a disabled card that describes the Pro tool (Pro missing, or installed
	 * without a valid license).
	 *
	 * @param string $tab Tab.
	 */
	private function render_locked_tab( string $tab ): void {
		$features = self::pro_features();
		$feature  = $features[ $tab ] ?? array(
			'text'   => '',
			'points' => array(),
		);
		$title    = $this->menu_tabs()[ $tab ] ?? __( 'Pro tool', 'lucidlab-mpc-cleanup' );
		?>
		<div class="lucidlab-mpc-card lucidlab-mpc-card--locked" aria-disabled="true" data-testid="pro-locked-card">
			<div class="lucidlab-mpc-card__head">
				<h3 class="lucidlab-mpc-card__title"><?php echo esc_html( $title ); ?> <?php echo wp_kses_post( self::pro_badge() ); ?></h3>
				<?php if ( '' !== $feature['text'] ) : ?>
					<p class="lucidlab-mpc-card__lede"><?php echo esc_html( $feature['text'] ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $feature['points'] ) : ?>
				<ul class="lucidlab-mpc-pro-points">
					<?php foreach ( $feature['points'] as $point ) : ?>
						<li><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php echo esc_html( $point ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<div class="lucidlab-mpc-btn-row">
				<?php echo self::pro_link( $tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in pro_link(). ?>
				<span class="lucidlab-mpc-hint"><?php echo esc_html( $this->pro_card_hint() ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Hint next to the "Get Pro" button of a locked Pro card.
	 *
	 * @return string
	 */
	private function pro_card_hint(): string {
		if ( ! class_exists( 'LucidLab_MPC_Pro', false ) ) {
			return __( 'Available in the Lucid Lab Media & Products Cleanup Pro add-on. The free tools on the other tabs keep working without it.', 'lucidlab-mpc-cleanup' );
		}
		$reason = class_exists( 'LucidLab_MPC_License', false ) ? LucidLab_MPC_License::block_reason() : 'inactive';
		if ( 'revoked' === $reason ) {
			return __( 'The Pro add-on is installed, but Lucid Lab revoked this license: save a valid license under Media & Products Cleanup → License.', 'lucidlab-mpc-cleanup' );
		}
		if ( 'expired' === $reason ) {
			return __( 'The Pro add-on is installed, but its license has expired and the 7-day grace period is over: renew it or save a valid license under Media & Products Cleanup → License.', 'lucidlab-mpc-cleanup' );
		}
		if ( 'outage' === $reason ) {
			return __( 'The Pro add-on is installed, but Lucid Lab has been unreachable for 7 days. Pro stays off until Lucid Lab can be reached again; the status is under Media & Products Cleanup → License.', 'lucidlab-mpc-cleanup' );
		}
		return __( 'The Pro add-on is installed: activate its license under Media & Products Cleanup → License.', 'lucidlab-mpc-cleanup' );
	}

	/**
	 * Pro-required notice inside a section (quarantine sets of a Pro source). Locked Pro tabs use render_locked_tab().
	 *
	 * @param string $message Message.
	 */
	private function render_pro_required_notice( string $message ): void {
		echo '<div class="lucidlab-mpc-license-gate lucidlab-mpc-license-gate--buy" role="status" data-testid="pro-buy-gate">';
		echo '<h2>' . esc_html__( 'This tool requires the Pro add-on', 'lucidlab-mpc-cleanup' ) . '</h2>';
		echo '<p>' . esc_html( $message ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( 'https://lucidlab.eu/#pricing' ) . '" target="_blank" rel="noopener noreferrer">';
		esc_html_e( 'Buy now!', 'lucidlab-mpc-cleanup' );
		echo '</a></p></div>';
	}

	/**
	 * Render scan guide.
	 */
	private function render_scan_guide(): void {
		$sections = array(
			array(
				'id'     => 'inventory',
				'tab'    => 'welcome',
				'title'  => __( 'Scan uploads folder', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Counts every file under the uploads folder and groups them by kind, such as originals, WebP, AVIF, and thumbnails. Thumbnail sizes are combined into one row. The size column is the combined total for that group.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'Files on disk, including WebP and AVIF made by this plugin or by another program. Folders you excluded in Options are skipped, and the quarantine and plugin work folders are always skipped.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'It walks the uploads folder in batches and classifies each file by its extension and whether the filename looks like a thumbnail. It does not decide whether a file is still used. Use Orphan Images or the Uploads Scanner for that.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'refs',
				'tab'    => 'welcome',
				'title'  => __( 'Scan library references', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Counts how images in the Media Library are referenced, so you can see featured images, galleries, content, and other uses before cleaning anything up.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'Media Library image records and the same reference sources used by the cleanup scans: post and product content, terms, custom fields, and settings.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'It reads the library and the reference index in batches and shows counts. It does not move or delete files.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'orphans',
				'tab'    => 'orphans',
				'title'  => __( 'Orphan Images', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Lists Media Library images that nothing on the site still points to. Those images can be moved to quarantine, which is restorable.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'Only image attachments in the Media Library. A file is kept when its attachment ID or filename appears as a featured image, in a product gallery, in page or product content, in a category or other term, in builder fields, or in plugin settings. When a reference is uncertain, the image is kept.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'The scan first builds a reference index. If only a few posts or terms changed, it re-reads those instead of the whole site. It then checks image attachments in batches. Loose files in uploads/lucidlab-mpc-cleanup/webp or uploads/lucidlab-mpc-cleanup/avif are not part of this scan. Deleting a product does not remove its Media Library image, so that image stays protected until the attachment itself is unused.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'sizes',
				'tab'    => 'sizes',
				'title'  => __( 'Image Sizes', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Shows each thumbnail size WordPress currently has registered, how many images have that size, and how much disk those files use.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'Attachment metadata for image files, and whether each registered size file exists next to the original.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'It reads image records in batches and totals each size name stored in that metadata. Sizes from an old theme that WordPress no longer has registered do not appear here.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'fs-sizes',
				'tab'    => 'sizes',
				'pro'    => true,
				'title'  => __( 'Other Sizes on Disk', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Finds resized image files left on disk after a theme or plugin removed a size. Those leftovers do not appear in the Image Sizes table.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'Image files under uploads whose names look like a resized copy, such as photo-150x150.jpg, including JPEG, PNG, GIF, WebP, and AVIF. It groups them by pixel size.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'It walks the uploads folder and groups matching filenames. Dimensions matching a currently registered WordPress size are protected. Other selected groups can be quarantined and restored.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'uploads',
				'tab'    => 'uploads',
				'pro'    => true,
				'title'  => __( 'Uploads Scanner', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Finds files sitting in the uploads folder that are not a Media Library file and are not still named in content. Those files can be quarantined.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'Files on disk. A file is kept when it belongs to a Media Library attachment, when its filename is still used in content, or when it is a generated WebP or AVIF copy of a file that is still kept. Plugin logs, caches, fonts, and scripts are skipped.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'It walks uploads, then checks candidates against the Media Library and the reference index. A copy named photo.jpg.webp, or the same name inside uploads/lucidlab-mpc-cleanup/webp or uploads/lucidlab-mpc-cleanup/avif, stays while photo.jpg is still a library file or is still named in content. A file named photo.webp next to photo.jpg is not treated as that JPEG. Deleting a product does not remove the original file, so its copies stay protected until the original is gone. On the next scan, a folder whose file list has not changed is not opened file by file.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'shared',
				'tab'    => 'shared',
				'title'  => __( 'Shared Images', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Lists images that are used in two or more places, so you can see what would break if you removed one.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'Featured images, product galleries, category thumbnails, and image IDs inside posts, pages, products, and builder content.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'It reads content in batches and keeps an image when at least two different places point at it. The scan does not change those references.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'dupcontent',
				'tab'    => 'dupcontent',
				'title'  => __( 'Byte Identical Images', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Groups Media Library images whose original files are byte for byte the same, then shows where each copy is used. You can merge a group onto one image and quarantine the extras.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'The file bytes of each image attachment, using a SHA-256 hash. An unchanged file is not hashed again. It also looks up featured images, galleries, term thumbnails, and image IDs in content.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'Images with the same hash are one group. The scan does not treat a resized thumbnail as a separate original, and it does not match images that only look alike.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'visual',
				'tab'    => 'visual',
				'pro'    => true,
				'title'  => __( 'Visually Identical Images', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Suggests Media Library images that look the same even when the files are not byte for byte identical. You review the pairs. Nothing is merged automatically.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'A small fingerprint of each image. Images smaller than 256 pixels on the short side are skipped because the fingerprint would be unreliable.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'A later scan only decodes an image whose file changed, and it reuses a saved comparison while both files stay the same. Matching fingerprints become candidates for you to open and compare.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'duplicates',
				'tab'    => 'duplicates',
				'pro'    => true,
				'title'  => __( 'Duplicate Products', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Groups WooCommerce products that share the same title, the same slug, or the same title and SKU, depending on the mode you choose.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'Published, pending, draft, and private products. It also reads the SKU when you match on title and SKU, plus sales and review counts so the suggested product to keep is the one with more orders, then more reviews.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'Products with an empty title or slug are skipped. Trashing a duplicate can also trash its variations. The scan itself does not change products until you confirm that action.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'missing',
				'tab'    => 'missing',
				'title'  => __( 'Missing Images', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Finds Media Library images whose main file is gone, and content links under uploads that no longer point at a file.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'Image attachments referenced from content, and upload URLs written into posts, pages, and products, including hardcoded links and missing thumbnail sizes.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'It reads content in batches and checks whether the file exists under uploads. Purging a row removes the Media Library record only. A broken URL inside a post or page has to be edited there. Check live pages opens each public page and reports an image 404 together with the page it appeared on.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'crawl',
				'tab'    => 'missing',
				'title'  => __( 'Live-page check: broken images, files and links', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Requests the home page, published pages, posts, products and public taxonomy archives, and checks everything their HTML loads or links to. Reports 4xx and 5xx answers, timeouts and redirect loops, with the page where each one was found.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'Images (src, every srcset size, lazy-loading attributes, og:image, icons), background images in style attributes, style blocks and stylesheets, stylesheets, scripts, fonts, video and audio, documents such as PDF files, and links to other pages of this site. Pro can also check images, files and links on other sites.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'Choose Check live pages on Missing Images. This is an HTTP crawler, not a JavaScript browser: private pages and addresses inserted only by scripts are outside its scope. It never renames, quarantines or deletes files. Pro can schedule it in Options and export the findings as CSV. Every address is checked once per scan, however many pages use it. Static files that exist on disk are not requested at all. Pages are requested 4 at a time and images, files and links 8 at a time with HEAD requests, through the WordPress HTTP filters. A redirect is followed hop by hop, so a link that redirects to a missing page is reported. Healthy answers can be reused for up to five minutes. Broken and inconclusive answers are checked again on the next scan.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'webp',
				'tab'    => 'webp',
				'title'  => __( 'WebP / AVIF', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Creates WebP copies in Free and AVIF copies with Pro. It counts generated copies and can list copies whose original file is gone. Free can compare ten JPEG or PNG images to preview the available formats.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'Media Library images for conversion, and files anywhere under uploads whose names end like photo.jpg.webp or photo.png.avif. A file named photo.webp, with no original extension in the name, is not treated as a generated copy of photo.jpg.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'Conversion runs as its own job and writes copies beside the original or under uploads/lucidlab-mpc-cleanup/webp and uploads/lucidlab-mpc-cleanup/avif. The copy search only collects those double-extension names. Statistics count the copies already on disk. Quarantining an attachment moves the original and its WordPress sizes. It does not remove copies stored in the webp or avif folders.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'product-copy',
				'tab'    => 'product_copy',
				'pro'    => true,
				'title'  => __( 'Product AI', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Suggests a product title, short description and long description from the featured image and a short note about the website.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'Published products with a usable featured image. Already reviewed suggestions are kept in Recent suggestions and can be found again by search.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'AI suggestions use the featured image and website description on this page. Nothing changes until you apply a field. Page-builder descriptions are not replaced from here.', 'lucidlab-mpc-cleanup' ),
			),
			array(
				'id'     => 'core-check',
				'tab'    => 'options',
				'title'  => __( 'Check WordPress files', 'lucidlab-mpc-cleanup' ),
				'does'   => __( 'Compares WordPress core files with official checksums and reports unexpected files or changed contents. Use WordPress Updates or your hosting provider for repairs.', 'lucidlab-mpc-cleanup' ),
				'checks' => __( 'Files under wp-admin and wp-includes, plus unexpected PHP or JS files in the WordPress root. This check is read-only; wp-content is not scanned.', 'lucidlab-mpc-cleanup' ),
				'works'  => __( 'It downloads the official checksum list from wordpress.org and, when possible, the matching release zip for size checks, replace and compare. A clean result shows an OK message. Findings stay under Options until you run the check again. Pro automation can schedule this check weekly or monthly.', 'lucidlab-mpc-cleanup' ),
			),
		);
		$this->end_overview_card();
		?>
		<div class="lucidlab-mpc-guide">
			<?php $this->begin_results_card( ' data-mpc-guide-section="intro"' ); ?>
			<h2 class="lucidlab-mpc-card__title"><?php esc_html_e( 'How scans work', 'lucidlab-mpc-cleanup' ); ?></h2>
			<p class="lucidlab-mpc-hint lucidlab-mpc-card__lede"><?php esc_html_e( 'A scan reads the library, the uploads folder, or both, in small batches. It saves a result you can review. It does not delete files. Quarantine moves files aside so they can be restored. Permanent deletion is a separate action.', 'lucidlab-mpc-cleanup' ); ?></p>
			<p class="lucidlab-mpc-hint"><?php esc_html_e( 'Orphan Images, the Uploads Scanner, Shared Images and Missing share one reference index. The first scan to finish builds it. Later scans reuse it until content changes. A small set of changed posts or terms is re-read on its own. A theme, widget, or settings change rebuilds the index. The Uploads Scanner reads a fresh directory listing for each scan and checks file metadata live. Invalid UTF-8 names are left untouched and require manual review.', 'lucidlab-mpc-cleanup' ); ?></p>
			<ul class="lucidlab-mpc-guide-toc">
				<?php foreach ( $sections as $section ) : ?>
					<li><a href="#lucidlab-mpc-guide-<?php echo esc_attr( $section['id'] ); ?>"><?php echo esc_html( $section['title'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
			<?php foreach ( $sections as $section ) : ?>
				<?php $this->begin_results_card( ' data-mpc-guide-section="' . esc_attr( $section['id'] ) . '" id="lucidlab-mpc-guide-' . esc_attr( $section['id'] ) . '"' ); ?>
				<article class="lucidlab-mpc-guide-scan">
					<h3 class="lucidlab-mpc-card__title">
						<a href="<?php echo esc_url( $this->tab_url( $section['tab'] ) ); ?>"><?php echo esc_html( $section['title'] ); ?></a>
						<?php
						if ( ! empty( $section['pro'] ) ) :
							?>
							<span class="lucidlab-mpc-guide-pro"><?php esc_html_e( 'Pro', 'lucidlab-mpc-cleanup' ); ?></span><?php endif; ?>
					</h3>
					<p><strong><?php esc_html_e( 'What it does.', 'lucidlab-mpc-cleanup' ); ?></strong> <?php echo esc_html( $section['does'] ); ?></p>
					<p><strong><?php esc_html_e( 'What it checks.', 'lucidlab-mpc-cleanup' ); ?></strong> <?php echo esc_html( $section['checks'] ); ?></p>
					<p><strong><?php esc_html_e( 'How it works.', 'lucidlab-mpc-cleanup' ); ?></strong> <?php echo esc_html( $section['works'] ); ?></p>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render welcome.
	 */
	private function render_welcome(): void {
		$pro           = LucidLab_MPC_Edition::pro();
		$free_features = array(
			__( 'Find orphaned and shared media safely', 'lucidlab-mpc-cleanup' ),
			__( 'Detect byte-identical images', 'lucidlab-mpc-cleanup' ),
			__( 'Count uploads files and library references', 'lucidlab-mpc-cleanup' ),
			__( 'Review references before changing files', 'lucidlab-mpc-cleanup' ),
			__( 'Restore quarantined files and undo changes', 'lucidlab-mpc-cleanup' ),
			__( 'Rename images and regenerate thumbnails', 'lucidlab-mpc-cleanup' ),
			__( 'Convert to WebP and compare ten images as AVIF', 'lucidlab-mpc-cleanup' ),
			__( 'Find broken images and links on live pages', 'lucidlab-mpc-cleanup' ),
			__( 'Disable unneeded thumbnail sizes', 'lucidlab-mpc-cleanup' ),
			__( 'Back up the database, uploads or full site', 'lucidlab-mpc-cleanup' ),
			__( 'Email weekly or monthly cleanup reports', 'lucidlab-mpc-cleanup' ),
		);
		$pro_features  = array(
			__( 'Everything in Free, plus advanced scanners', 'lucidlab-mpc-cleanup' ),
			__( 'Uploads Scanner for files outside the Media Library', 'lucidlab-mpc-cleanup' ),
			__( 'Visual duplicate candidates and reference merging', 'lucidlab-mpc-cleanup' ),
			__( 'Duplicate WooCommerce products by title, slug or SKU', 'lucidlab-mpc-cleanup' ),
			__( 'Quarantine leftover image sizes on disk', 'lucidlab-mpc-cleanup' ),
			__( 'AVIF conversion and replacing originals', 'lucidlab-mpc-cleanup' ),
			__( 'AI file names, alt text and product copy', 'lucidlab-mpc-cleanup' ),
			__( 'Review Queue for scanner and AI findings', 'lucidlab-mpc-cleanup' ),
			__( 'Database Cleaner for plugin leftovers', 'lucidlab-mpc-cleanup' ),
			__( 'Run All and parallel background jobs', 'lucidlab-mpc-cleanup' ),
			__( 'Scheduled scans and WP-CLI commands', 'lucidlab-mpc-cleanup' ),
		);
		$tools         = array(
			array( 'orphans', 'dashicons-search', __( 'Find unused media', 'lucidlab-mpc-cleanup' ), __( 'Check attachment references across products, pages, galleries and content before you quarantine anything.', 'lucidlab-mpc-cleanup' ), 'blue' ),
			array( 'dupcontent', 'dashicons-admin-links', __( 'Find duplicate images', 'lucidlab-mpc-cleanup' ), __( 'Compare byte-identical files and review where each copy is used.', 'lucidlab-mpc-cleanup' ), 'teal' ),
			array( 'shared', 'dashicons-images-alt2', __( 'Understand image usage', 'lucidlab-mpc-cleanup' ), __( 'See shared featured images, galleries, category thumbnails and content references.', 'lucidlab-mpc-cleanup' ), 'amber' ),
			array( 'quarantine', 'dashicons-shield', __( 'Clean up safely', 'lucidlab-mpc-cleanup' ), __( 'Move files to a restorable quarantine first. Restore them when you need to.', 'lucidlab-mpc-cleanup' ), 'green' ),
			array( 'rename', 'dashicons-star-filled', __( 'Improve your media library', 'lucidlab-mpc-cleanup' ), __( 'Rename files with useful patterns, update titles and use AI suggestions in Pro.', 'lucidlab-mpc-cleanup' ), 'violet' ),
			array( 'product_copy', 'dashicons-edit-page', __( 'Product AI', 'lucidlab-mpc-cleanup' ), __( 'Find published products with no usable image, and review AI title and description suggestions before anything is saved.', 'lucidlab-mpc-cleanup' ), 'indigo' ),
			array( 'options', 'dashicons-admin-generic', __( 'Tune the workflow', 'lucidlab-mpc-cleanup' ), __( 'Set batch sizes, scan exclusions, email reports and quarantine retention. Logging is under Logs / Diagnostics.', 'lucidlab-mpc-cleanup' ), 'slate' ),
			array( 'missing', 'dashicons-visibility', __( 'Check live pages', 'lucidlab-mpc-cleanup' ), __( 'Crawl public pages, products and category archives to find broken images, files, stylesheets, scripts and links, with links to the affected pages.', 'lucidlab-mpc-cleanup' ), 'cyan' ),
			array( 'sizes', 'dashicons-format-gallery', __( 'Manage image sizes', 'lucidlab-mpc-cleanup' ), __( 'Review thumbnail sizes, disable unneeded sizes and inspect leftover files before quarantine. Regenerate missing thumbnails from their own tab.', 'lucidlab-mpc-cleanup' ), 'pink' ),
		);
		?>
		<div class="lucidlab-mpc-welcome">
			<section class="lucidlab-mpc-welcome-hero">
				<div><span class="lucidlab-mpc-welcome-kicker"><?php esc_html_e( 'MEDIA LIBRARY TOOLKIT', 'lucidlab-mpc-cleanup' ); ?></span>
				<h2><?php esc_html_e( 'Make space. Keep control.', 'lucidlab-mpc-cleanup' ); ?></h2>
				<p><?php esc_html_e( 'Media & Products Cleanup helps you see what is used, what is duplicated and what can be safely recovered.', 'lucidlab-mpc-cleanup' ); ?></p>
				<p><a class="lucidlab-mpc-welcome-guide-link" href="<?php echo esc_url( $this->tab_url( 'guide' ) ); ?>"><span><?php esc_html_e( 'Learn how each scan decides', 'lucidlab-mpc-cleanup' ); ?></span></a></p>
				<div class="lucidlab-mpc-welcome-actions"><?php if ( $pro ) : ?>
					<button type="button" class="button button-primary button-hero" data-mpc-run-all data-testid="run-all"><span class="dashicons dashicons-controls-play" aria-hidden="true"></span><?php esc_html_e( 'Run all scans', 'lucidlab-mpc-cleanup' ); ?></button>
					<?php
				else :
					?>
					<a class="button button-primary button-hero" href="<?php echo esc_url( $this->tab_url( 'orphans' ) ); ?>"><span class="dashicons dashicons-controls-play" aria-hidden="true"></span><?php esc_html_e( 'Start with a scan', 'lucidlab-mpc-cleanup' ); ?></a><?php endif; ?><a class="button button-hero" href="<?php echo esc_url( $this->tab_url( 'options' ) ); ?>"><?php esc_html_e( 'Open options', 'lucidlab-mpc-cleanup' ); ?></a><?php if ( $pro ) : ?>
					<div class="lucidlab-mpc-run-all-picker" id="lucidlab-mpc-run-all-picker" hidden>
						<label class="lucidlab-mpc-run-all-select-all"><input type="checkbox" data-mpc-run-all-select-all /> <?php esc_html_e( 'Select all', 'lucidlab-mpc-cleanup' ); ?></label>
						<div class="lucidlab-mpc-run-all-stages">
							<?php foreach ( LucidLab_MPC_Task_Job::run_all_choices() as $stage_key => $stage_label ) : ?>
								<label><input type="checkbox" name="lucidlab_mpc_run_all_stage[]" value="<?php echo esc_attr( $stage_key ); ?>" data-mpc-run-all-stage <?php checked( in_array( $stage_key, array( 'index', 'orphanscan', 'sizescan', 'fs-sizes', 'uploadsscan', 'shared', 'dupcontent', 'visual', 'duplicates', 'missing', 'copies', 'webpstats', 'dbcleaner' ), true ) ); ?> /> <?php echo esc_html( $stage_label ); ?></label>
							<?php endforeach; ?>
						</div>
						<label class="lucidlab-mpc-run-all-email"><input type="checkbox" data-mpc-run-all-email /> <?php esc_html_e( 'Email report with the outcome of the selected jobs', 'lucidlab-mpc-cleanup' ); ?></label>
						<p class="lucidlab-mpc-hint lucidlab-mpc-run-all-email-hint"><?php esc_html_e( 'Uses the recipients saved under Options → Email report. Add an address there first if the list is empty.', 'lucidlab-mpc-cleanup' ); ?></p>
						<div class="lucidlab-mpc-run-all-picker-actions">
							<button type="button" class="button button-primary" data-mpc-run-all-start><?php esc_html_e( 'Start', 'lucidlab-mpc-cleanup' ); ?></button>
							<button type="button" class="button" data-mpc-run-all-cancel><?php esc_html_e( 'Cancel', 'lucidlab-mpc-cleanup' ); ?></button>
						</div>
					</div>
				<?php endif; ?></div>
				</div><div class="lucidlab-mpc-welcome-hero-icon" aria-hidden="true"><img class="lucidlab-mpc-welcome-hero-logo" src="<?php echo esc_url( $this->brand_logo_url() ); ?>" alt="" width="120" height="120" /></div>
			</section>
			<?php
			$welcome_uploads            = LucidLab_MPC_Helpers::uploads_basedir();
			$welcome_quarantine         = ( new LucidLab_MPC_Quarantine() )->root();
			$lucidlab_mpc_cron          = LucidLab_MPC_Helpers::cron_health();
			$lucidlab_mpc_cron_standard = __( 'Standard', 'lucidlab-mpc-cleanup' );
			$lucidlab_mpc_cron_server   = __( 'Server cron', 'lucidlab-mpc-cleanup' );
			$lucidlab_mpc_cron_off      = __( 'Disabled', 'lucidlab-mpc-cleanup' );
			$lucidlab_mpc_cron_ready    = empty( $lucidlab_mpc_cron['disabled'] ) || 'ok' === ( $lucidlab_mpc_cron['status'] ?? '' );
			$lucidlab_mpc_cron_state    = empty( $lucidlab_mpc_cron['disabled'] ) ? $lucidlab_mpc_cron_standard : ( 'ok' === ( $lucidlab_mpc_cron['status'] ?? '' ) ? $lucidlab_mpc_cron_server : $lucidlab_mpc_cron_off );
			$welcome_labels             = array(
				'Uploads'     => __( 'Uploads', 'lucidlab-mpc-cleanup' ),
				'Quarantine'  => __( 'Quarantine', 'lucidlab-mpc-cleanup' ),
				'WP-Cron'     => 'WP-Cron',
				'Media index' => __( 'Media index', 'lucidlab-mpc-cleanup' ),
				'Backup'      => __( 'Backup', 'lucidlab-mpc-cleanup' ),
			);
			$welcome_checks             = array(
				'Uploads'     => is_dir( $welcome_uploads ) && is_writable( $welcome_uploads ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				'Quarantine'  => ( is_dir( $welcome_quarantine ) && is_writable( $welcome_quarantine ) ) || ( ! is_dir( $welcome_quarantine ) && is_writable( dirname( $welcome_quarantine ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				'WP-Cron'     => $lucidlab_mpc_cron_ready,
				'Media index' => LucidLab_MPC_Index::is_ready(),
				'Backup'      => class_exists( 'LucidLab_MPC_Backup', false ) && LucidLab_MPC_Backup::has_backups(),
			);
			?>
			<section class="lucidlab-mpc-welcome-section lucidlab-mpc-welcome-readiness"><div class="lucidlab-mpc-welcome-section-heading"><span class="lucidlab-mpc-welcome-eyebrow"><?php esc_html_e( 'READY CHECK', 'lucidlab-mpc-cleanup' ); ?></span><h3><?php esc_html_e( 'Check the workspace before scanning', 'lucidlab-mpc-cleanup' ); ?></h3></div><div class="lucidlab-mpc-welcome-readiness-grid">
			<?php
			foreach ( $welcome_checks as $welcome_label => $welcome_ready ) :
				?>
				<div class="lucidlab-mpc-readiness-card <?php echo $welcome_ready ? 'is-ready' : 'is-check'; ?>"><span class="lucidlab-mpc-readiness-mark" aria-hidden="true"><span class="dashicons <?php echo $welcome_ready ? 'dashicons-yes' : 'dashicons-warning'; ?>"></span></span><span class="lucidlab-mpc-readiness-copy">
				<?php
				if ( 'Media index' === $welcome_label || 'WP-Cron' === $welcome_label ) :
					?>
				<a class="lucidlab-mpc-media-index-link" href="<?php echo esc_url( $this->tab_url( 'options' ) ); ?>"><strong><?php echo esc_html( $welcome_labels[ $welcome_label ] ?? $welcome_label ); ?></strong></a>
					<?php
elseif ( 'Backup' === $welcome_label ) :
	?>
<a class="lucidlab-mpc-media-index-link" href="<?php echo esc_url( $this->tab_url( 'backup' ) ); ?>"><strong><?php echo esc_html( $welcome_labels[ $welcome_label ] ?? $welcome_label ); ?></strong></a>
	<?php
else :
	?>
	<strong><?php echo esc_html( $welcome_labels[ $welcome_label ] ?? $welcome_label ); ?></strong><?php endif; ?><span class="lucidlab-mpc-readiness-state">
				<?php
				if ( 'WP-Cron' === $welcome_label ) {
					echo esc_html( $lucidlab_mpc_cron_state );
				} else {
					echo $welcome_ready ? esc_html__( 'Ready', 'lucidlab-mpc-cleanup' ) : esc_html__( 'Check', 'lucidlab-mpc-cleanup' ); }
				?>
</span></span></div><?php endforeach; ?></div></section>
			<?php if ( ! get_option( 'lucidlab_mpc_setup_done' ) && ( get_option( 'lucidlab_mpc_setup_pending' ) || ! LucidLab_MPC_Index::is_ready() ) ) : ?>
			<section class="lucidlab-mpc-welcome-section lucidlab-mpc-setup-wizard">
				<div class="lucidlab-mpc-welcome-section-heading"><span class="lucidlab-mpc-welcome-eyebrow"><?php esc_html_e( 'SETUP WIZARD', 'lucidlab-mpc-cleanup' ); ?></span><h3><?php esc_html_e( 'Finish first-run setup', 'lucidlab-mpc-cleanup' ); ?></h3><p><?php esc_html_e( 'Four checks before the first scan. Nothing is deleted from this page.', 'lucidlab-mpc-cleanup' ); ?></p></div>
				<ol class="lucidlab-mpc-setup-steps">
					<li><strong><?php esc_html_e( 'Backup', 'lucidlab-mpc-cleanup' ); ?></strong> — <?php esc_html_e( 'Use Backup Database, Backup uploads or Backup full site on the Backup tab, then download the copies. Database and uploads restore overwrite the live copies. Full-site restore requires your hosting provider or WordPress recovery tools.', 'lucidlab-mpc-cleanup' ); ?> <a href="<?php echo esc_url( $this->tab_url( 'backup' ) ); ?>"><?php esc_html_e( 'Open Backup', 'lucidlab-mpc-cleanup' ); ?></a></li>
					<li><strong><?php esc_html_e( 'Media Index', 'lucidlab-mpc-cleanup' ); ?></strong> — <?php echo LucidLab_MPC_Index::is_ready() ? esc_html__( 'Ready.', 'lucidlab-mpc-cleanup' ) : esc_html__( 'Rebuild it from Options so scanners can reuse one image list.', 'lucidlab-mpc-cleanup' ); ?> <a href="<?php echo esc_url( $this->tab_url( 'options' ) ); ?>"><?php esc_html_e( 'Open Options', 'lucidlab-mpc-cleanup' ); ?></a></li>
					<li><strong><?php esc_html_e( 'Folders and WP-Cron', 'lucidlab-mpc-cleanup' ); ?></strong> — <?php esc_html_e( 'Confirm Uploads/Quarantine are writable and that WP-Cron or a server cron runner is available.', 'lucidlab-mpc-cleanup' ); ?></li>
					<li><strong><?php esc_html_e( 'First scan', 'lucidlab-mpc-cleanup' ); ?></strong> — <a href="<?php echo esc_url( $this->tab_url( 'orphans' ) ); ?>"><?php esc_html_e( 'Run Orphan Images', 'lucidlab-mpc-cleanup' ); ?></a> <?php esc_html_e( 'and review findings before quarantining.', 'lucidlab-mpc-cleanup' ); ?></li>
				</ol>
				<form method="post" action="<?php echo esc_url( $this->tab_url( 'welcome' ) ); ?>">
					<?php wp_nonce_field( 'lucidlab_mpc_op_dismiss_setup' ); ?>
					<input type="hidden" name="lucidlab_mpc_op" value="dismiss_setup" />
					<button class="button"><?php esc_html_e( 'Dismiss setup checklist', 'lucidlab-mpc-cleanup' ); ?></button>
				</form>
			</section>
			<?php endif; ?>
			<?php
			$inventory  = class_exists( 'LucidLab_MPC_Inventory' ) ? LucidLab_MPC_Inventory::result() : array();
			$inv_disk   = is_array( $inventory['disk'] ?? null ) ? $inventory['disk'] : null;
			$inv_refs   = is_array( $inventory['refs'] ?? null ) ? $inventory['refs'] : null;
			$inv_status = array();
			$inv_size   = '';
			if ( $inv_disk ) {
				$inv_size     = array_key_exists( 'total_bytes', $inv_disk ) ? LucidLab_MPC_Helpers::bytes( (int) $inv_disk['total_bytes'] ) : (string) ( $inv_disk['total_size'] ?? '' );
				$inv_status[] = sprintf(
					/* translators: 1: file count, 2: size */
					__( 'Uploads inventory ready — %1$s files (%2$s).', 'lucidlab-mpc-cleanup' ),
					number_format_i18n( (int) ( $inv_disk['total_files'] ?? 0 ) ),
					$inv_size
				);
			}
			if ( $inv_refs ) {
				$inv_status[] = __( 'Library references ready.', 'lucidlab-mpc-cleanup' );
			}
			if ( ! empty( $inventory['updated'] ) ) {
				$inv_status[] = sprintf(
					/* translators: %s: date/time */
					__( 'Saved %s — kept until the next scan.', 'lucidlab-mpc-cleanup' ),
					wp_date( 'j F Y H:i', (int) $inventory['updated'] )
				);
			}
			?>
			<section class="lucidlab-mpc-welcome-section lucidlab-mpc-inventory" id="lucidlab-mpc-inventory" data-mpc-inventory-pro="<?php echo $pro ? '1' : '0'; ?>">
				<div class="lucidlab-mpc-welcome-section-heading">
					<span class="lucidlab-mpc-welcome-eyebrow"><?php esc_html_e( 'STORAGE OVERVIEW', 'lucidlab-mpc-cleanup' ); ?></span>
					<h3><?php esc_html_e( 'Scan uploads folder', 'lucidlab-mpc-cleanup' ); ?></h3>
					<p><?php esc_html_e( 'Walk the entire uploads directory (honouring excluded folders from Options) and list how many files you have by type, originals vs thumbnails, WebP/AVIF copies, PDFs, scripts and more. Optionally count media library items and content references to images and PDFs. Free and Pro can both run these scans; opening View / Edit from the library references list needs Pro.', 'lucidlab-mpc-cleanup' ); ?></p>
				</div>
				<p class="lucidlab-mpc-btn-row lucidlab-mpc-inventory-actions">
					<button type="button" class="button button-primary" id="lucidlab-mpc-inventory-disk"><?php esc_html_e( 'Scan uploads folder', 'lucidlab-mpc-cleanup' ); ?></button>
					<button type="button" class="button" id="lucidlab-mpc-inventory-refs"><?php esc_html_e( 'Scan library references', 'lucidlab-mpc-cleanup' ); ?></button>
					<a class="button" href="<?php echo esc_url( $this->tab_url( 'options' ) ); ?>"><?php esc_html_e( 'Excluded folders', 'lucidlab-mpc-cleanup' ); ?></a>
				</p>
				<p class="lucidlab-mpc-hint lucidlab-mpc-inventory-status-line" id="lucidlab-mpc-inventory-status" role="status"><?php echo esc_html( implode( ' ', $inv_status ) ); ?></p>
				<p class="lucidlab-mpc-hint"><?php esc_html_e( 'This inventory counts every file under uploads except excluded folders and quarantine. The Uploads Scanner leaves out plugin support files, so its file total can be lower.', 'lucidlab-mpc-cleanup' ); ?></p>
				<div class="lucidlab-mpc-inventory-track" id="lucidlab-mpc-inventory-progress" hidden><span class="lucidlab-mpc-inventory-bar" id="lucidlab-mpc-inventory-bar" style="width:0%"></span></div>
				<details class="lucidlab-mpc-inventory-panel" id="lucidlab-mpc-inventory-disk-result" <?php echo $inv_disk ? '' : 'hidden'; ?>>
					<summary>
						<span class="lucidlab-mpc-inventory-summary-line">
							<?php if ( $inv_disk ) : ?>
								<strong><?php echo esc_html( sprintf( /* translators: 1: file count, 2: size */ __( 'Total %1$s files · %2$s', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) ( $inv_disk['total_files'] ?? 0 ) ), $inv_size ) ); ?></strong>
								<?php if ( ! empty( $inv_disk['skipped_dirs'] ) ) : ?>
									<span class="lucidlab-mpc-hint"><?php echo esc_html( sprintf( /* translators: %s: number of directory instances excluded during the uploads walk; this may exceed the number of configured folder patterns */ __( '%s excluded directory instances skipped', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $inv_disk['skipped_dirs'] ) ) ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<strong><?php esc_html_e( 'Uploads inventory', 'lucidlab-mpc-cleanup' ); ?></strong>
							<?php endif; ?>
						</span>
						<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
					</summary>
					<div class="lucidlab-mpc-inventory-panel-body" id="lucidlab-mpc-inventory-disk-body">
					<?php if ( $inv_disk ) : ?>
						<table class="widefat lucidlab-mpc-table lucidlab-mpc-inventory-table">
							<thead><tr><th><?php esc_html_e( 'Type', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Category', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Files', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Size', 'lucidlab-mpc-cleanup' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( (array) ( $inv_disk['rows'] ?? array() ) as $row ) : ?>
								<tr>
									<td><code><?php echo esc_html( (string) ( $row['ext'] ?? '' ) ); ?></code></td>
									<td><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) ( $row['count'] ?? 0 ) ) ); ?></td>
									<td><?php echo esc_html( array_key_exists( 'bytes', $row ) ? LucidLab_MPC_Helpers::bytes( (int) $row['bytes'] ) : (string) ( $row['size'] ?? '' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<?php if ( ! empty( $inv_disk['folders'] ) ) : ?>
						<h4><?php esc_html_e( 'Uploads folder', 'lucidlab-mpc-cleanup' ); ?></h4>
						<table class="widefat lucidlab-mpc-table lucidlab-mpc-inventory-table">
							<thead><tr><th><?php esc_html_e( 'Uploads folder', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Files', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Size', 'lucidlab-mpc-cleanup' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $inv_disk['folders'] as $folder ) : ?>
								<tr><td><code><?php echo esc_html( (string) ( $folder['name'] ?? '' ) ); ?></code></td><td><?php echo esc_html( number_format_i18n( (int) ( $folder['count'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( LucidLab_MPC_Helpers::bytes( (int) ( $folder['bytes'] ?? 0 ) ) ); ?></td></tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<?php endif; ?>
					<?php endif; ?>
					</div>
				</details>
				<details class="lucidlab-mpc-inventory-panel" id="lucidlab-mpc-inventory-refs-result" <?php echo $inv_refs ? '' : 'hidden'; ?>>
					<summary>
						<span class="lucidlab-mpc-inventory-summary-line">
							<strong><?php esc_html_e( 'Media library &amp; references', 'lucidlab-mpc-cleanup' ); ?></strong>
						</span>
						<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
					</summary>
					<div class="lucidlab-mpc-inventory-panel-body" id="lucidlab-mpc-inventory-refs-body">
					<?php if ( $inv_refs ) : ?>
						<p class="lucidlab-mpc-hint">
						<?php
						echo $pro
							? esc_html__( 'Click a count to see which products, pages or posts are included (sample of up to 80). Use View / Edit to open each item in a new tab.', 'lucidlab-mpc-cleanup' )
							: esc_html__( 'Click a count to see which products, pages or posts are included (sample of up to 80). View / Edit links require the Pro add-on.', 'lucidlab-mpc-cleanup' );
						?>
							</p>
						<table class="widefat lucidlab-mpc-table lucidlab-mpc-inventory-table" id="lucidlab-mpc-inventory-refs-table">
							<thead><tr><th><?php esc_html_e( 'Reference', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Count', 'lucidlab-mpc-cleanup' ); ?></th></tr></thead>
							<tbody>
							<?php
							$inv_samples = is_array( $inv_refs['samples'] ?? null ) ? $inv_refs['samples'] : array();
							foreach ( (array) ( $inv_refs['rows'] ?? array() ) as $row ) :
								$key   = (string) ( $row['key'] ?? '' );
								$count = (int) ( $row['count'] ?? 0 );
								$items = ( $key && isset( $inv_samples[ $key ] ) && is_array( $inv_samples[ $key ] ) ) ? array_values( $inv_samples[ $key ] ) : array();
								if ( ! $pro && $items ) {
									$items = array_map(
										static function ( $item ) {
											if ( ! is_array( $item ) ) {
												return $item;
											}
											$item['view'] = '';
											$item['edit'] = '';
											return $item;
										},
										$items
									);
								}
								?>
								<tr>
									<td><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></td>
									<td>
										<?php if ( $count > 0 && $items ) : ?>
											<button type="button" class="button-link lucidlab-mpc-inventory-count" data-mpc-inv-key="<?php echo esc_attr( $key ); ?>" data-mpc-inv-label="<?php echo esc_attr( (string) ( $row['label'] ?? '' ) ); ?>" data-mpc-inv-items="<?php echo esc_attr( wp_json_encode( $items ) ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></button>
										<?php else : ?>
											<?php echo esc_html( number_format_i18n( $count ) ); ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					</div>
				</details>
				<dialog class="lucidlab-mpc-inventory-dialog" id="lucidlab-mpc-inventory-dialog">
					<form method="dialog" class="lucidlab-mpc-inventory-dialog-head">
						<strong id="lucidlab-mpc-inventory-dialog-title"></strong>
						<button type="submit" class="button-link" aria-label="<?php esc_attr_e( 'Close', 'lucidlab-mpc-cleanup' ); ?>">&times;</button>
					</form>
					<div class="lucidlab-mpc-inventory-dialog-body" id="lucidlab-mpc-inventory-dialog-body"></div>
				</dialog>
			</section>
			<?php if ( is_multisite() ) : ?>
			<div class="notice notice-info inline lucidlab-mpc-welcome-setup-link"><p><?php esc_html_e( 'This site uses its own Media Index, quarantine and jobs. Network-wide Agency management is not included in this build.', 'lucidlab-mpc-cleanup' ); ?></p></div>
			<?php endif; ?>
			<section class="lucidlab-mpc-welcome-section"><div class="lucidlab-mpc-welcome-section-heading"><span class="lucidlab-mpc-welcome-eyebrow"><?php esc_html_e( 'EDITIONS', 'lucidlab-mpc-cleanup' ); ?></span><h3><?php esc_html_e( 'Free core and Pro add-on', 'lucidlab-mpc-cleanup' ); ?></h3><p><?php esc_html_e( 'The same recovery data stays available if you activate or deactivate Pro. Restore and undo never depend on the add-on.', 'lucidlab-mpc-cleanup' ); ?></p></div><div class="lucidlab-mpc-welcome-plans"><div class="lucidlab-mpc-welcome-plan"><div class="lucidlab-mpc-plan-top"><h4><?php esc_html_e( 'Free', 'lucidlab-mpc-cleanup' ); ?></h4>
			<?php
			if ( ! $pro ) :
				?>
				<span class="lucidlab-mpc-plan-badge"><?php esc_html_e( 'Current', 'lucidlab-mpc-cleanup' ); ?></span><?php endif; ?></div><p><?php esc_html_e( 'Manual scans, reports, individual renaming, thumbnail regeneration and restoration.', 'lucidlab-mpc-cleanup' ); ?></p><ul>
				<?php
				foreach ( $free_features as $item ) :
					?>
				<li><span class="dashicons dashicons-yes"></span><span><?php echo esc_html( $item ); ?></span></li><?php endforeach; ?></ul>
				<?php
				if ( $pro ) :
					?>
	<p class="lucidlab-mpc-plan-active"><?php esc_html_e( 'Installed', 'lucidlab-mpc-cleanup' ); ?></p><?php endif; ?></div><div class="lucidlab-mpc-welcome-plan lucidlab-mpc-plan-pro"><div class="lucidlab-mpc-plan-top"><h4><?php esc_html_e( 'Pro', 'lucidlab-mpc-cleanup' ); ?></h4>
		<?php
		if ( $pro ) :
			?>
	<span class="lucidlab-mpc-plan-badge"><?php esc_html_e( 'Current', 'lucidlab-mpc-cleanup' ); ?></span><?php endif; ?></div><p><?php esc_html_e( 'Advanced scanners, bulk workflows and scheduled cleanup on top of the Free core.', 'lucidlab-mpc-cleanup' ); ?></p><ul>
		<?php
		foreach ( $pro_features as $item ) :
			?>
	<li><span class="dashicons dashicons-yes"></span><span><?php echo esc_html( $item ); ?></span></li><?php endforeach; ?></ul></div></div></section>
			<section class="lucidlab-mpc-welcome-section"><div class="lucidlab-mpc-welcome-section-heading"><span class="lucidlab-mpc-welcome-eyebrow"><?php esc_html_e( 'WHAT YOU CAN DO', 'lucidlab-mpc-cleanup' ); ?></span><h3><?php esc_html_e( 'A clearer way to manage media', 'lucidlab-mpc-cleanup' ); ?></h3><p><?php esc_html_e( 'Start with a report, inspect the references, then choose the action. Nothing is removed just because a scan found it.', 'lucidlab-mpc-cleanup' ); ?></p></div><div class="lucidlab-mpc-welcome-tools">
			<?php
			foreach ( $tools as $tool ) :
				?>
				<a class="lucidlab-mpc-welcome-tool" href="<?php echo esc_url( $this->tab_url( $tool[0] ) ); ?>"><span class="lucidlab-mpc-welcome-tool-icon is-<?php echo esc_attr( $tool[4] ); ?>"><span class="dashicons <?php echo esc_attr( $tool[1] ); ?>"></span></span><span><strong><?php echo esc_html( $tool[2] ); ?></strong><small><?php echo esc_html( $tool[3] ); ?></small><span class="lucidlab-mpc-welcome-link"><?php esc_html_e( 'Open tool', 'lucidlab-mpc-cleanup' ); ?></span></span></a><?php endforeach; ?></div></section>
			<section class="lucidlab-mpc-welcome-section lucidlab-mpc-welcome-explanations"><details><summary><span><span class="lucidlab-mpc-welcome-eyebrow"><?php esc_html_e( 'LEARN MORE', 'lucidlab-mpc-cleanup' ); ?></span><strong><?php esc_html_e( 'Explanations and Features', 'lucidlab-mpc-cleanup' ); ?></strong><small><?php esc_html_e( 'How the plugin works, from first scan to safe cleanup', 'lucidlab-mpc-cleanup' ); ?></small></span><span class="dashicons dashicons-arrow-down-alt2"></span></summary><div class="lucidlab-mpc-explanation-grid">
				<div class="lucidlab-mpc-explanation-plan"><span class="lucidlab-mpc-welcome-eyebrow"><?php esc_html_e( 'RECOMMENDED CLEANUP ORDER', 'lucidlab-mpc-cleanup' ); ?></span><h4><?php esc_html_e( 'Start small, verify each result', 'lucidlab-mpc-cleanup' ); ?></h4><p><?php esc_html_e( 'Run a backup first. Work through these steps in order and rescan after each quarantine or restore. Keep the site and uploads folder available while you verify the results.', 'lucidlab-mpc-cleanup' ); ?></p><ol><li><strong><?php esc_html_e( 'Media Index', 'lucidlab-mpc-cleanup' ); ?></strong> — <?php esc_html_e( 'Rebuild it first. It stores image IDs, titles and filenames for rename and thumbnail estimates. Orphan Images, the Uploads Scanner, Shared Images and Missing build one shared reference index when they run.', 'lucidlab-mpc-cleanup' ); ?></li><li><strong><?php esc_html_e( 'Orphan Images', 'lucidlab-mpc-cleanup' ); ?></strong> — <?php esc_html_e( 'Lists Media Library images that nothing still points to, including content, galleries, categories, builder fields and settings. Quarantine only the ones you have checked. Purge is a later manual step.', 'lucidlab-mpc-cleanup' ); ?></li><li><strong><?php esc_html_e( 'Image Sizes', 'lucidlab-mpc-cleanup' ); ?></strong> — <?php esc_html_e( 'Counts thumbnail sizes WordPress has registered now. Other Sizes on Disk, in Pro, finds resized files left by an old theme. Disable a size only when it is no longer needed, then quarantine those files.', 'lucidlab-mpc-cleanup' ); ?></li><li><strong><?php esc_html_e( 'Uploads Scanner', 'lucidlab-mpc-cleanup' ); ?></strong> — <?php esc_html_e( 'Finds files in uploads that are not a Media Library file and are not still named in content. A WebP or AVIF copy of a file that is still kept stays protected. Logs and caches are skipped.', 'lucidlab-mpc-cleanup' ); ?></li><li><strong><?php esc_html_e( 'Shared and Duplicate Images', 'lucidlab-mpc-cleanup' ); ?></strong> — <?php esc_html_e( 'Shared Images lists pictures used in two or more places. Byte-identical groups are the same file bytes; merge only after checking every reference. Visually identical groups, in Pro, are suggestions and are not merged automatically.', 'lucidlab-mpc-cleanup' ); ?></li><li><strong><?php esc_html_e( 'Missing Images', 'lucidlab-mpc-cleanup' ); ?></strong> — <?php esc_html_e( 'Finds library images whose file is gone, and upload links in content that no longer point at a file. Purging removes the library record only. A broken link in a post or page has to be edited there.', 'lucidlab-mpc-cleanup' ); ?></li><li><strong><?php esc_html_e( 'Optimize and maintain', 'lucidlab-mpc-cleanup' ); ?></strong> — <?php esc_html_e( 'Regenerate missing thumbnails. In Pro, create WebP or AVIF copies. Those copies do not free space. Reclaim storage with quarantine and purge, then review the saved results.', 'lucidlab-mpc-cleanup' ); ?></li></ol><p class="lucidlab-mpc-plan-note"><strong><?php esc_html_e( 'Free:', 'lucidlab-mpc-cleanup' ); ?></strong> <?php esc_html_e( 'Follow the same order manually, one scanner at a time. Keep this page open until the job finishes.', 'lucidlab-mpc-cleanup' ); ?> <strong><?php esc_html_e( 'Pro:', 'lucidlab-mpc-cleanup' ); ?></strong> <?php esc_html_e( 'Use saved scans, Run All and background jobs for the repeatable parts, while merges, renames and permanent purge remain manual approvals.', 'lucidlab-mpc-cleanup' ); ?></p></div>
				<div><span class="dashicons dashicons-search"></span><h4><?php esc_html_e( 'Scan and understand', 'lucidlab-mpc-cleanup' ); ?></h4><p><?php esc_html_e( 'Scanners inspect the Media Library, the uploads folder, or both. A result is a report. Nothing is removed just because a scan found it.', 'lucidlab-mpc-cleanup' ); ?></p></div>
				<div><span class="dashicons dashicons-admin-links"></span><h4><?php esc_html_e( 'Duplicates and shared images', 'lucidlab-mpc-cleanup' ); ?></h4><p><?php esc_html_e( 'Byte-identical images share the same file bytes. Shared Images shows featured images, galleries, category thumbnails and content used in more than one place. Visual matches, in Pro, are suggestions to compare, not an automatic merge.', 'lucidlab-mpc-cleanup' ); ?></p></div>
				<div><span class="dashicons dashicons-shield"></span><h4><?php esc_html_e( 'Quarantine before purge', 'lucidlab-mpc-cleanup' ); ?></h4><p><?php esc_html_e( 'Files can be moved to a restorable quarantine. Restore is available later; permanent Purge is always a separate manual step.', 'lucidlab-mpc-cleanup' ); ?></p></div>
				<div><span class="dashicons dashicons-edit"></span><h4><?php esc_html_e( 'Rename and improve', 'lucidlab-mpc-cleanup' ); ?></h4><p><?php esc_html_e( 'Rename files with title, domain, date and filename patterns. Pro adds reviewable AI filename, title and alt-text suggestions; changes are never applied automatically.', 'lucidlab-mpc-cleanup' ); ?></p></div>
				<div><span class="dashicons dashicons-performance"></span><h4><?php esc_html_e( 'Optimize images', 'lucidlab-mpc-cleanup' ); ?></h4><p><?php esc_html_e( 'Regenerate missing thumbnails. With Pro, create WebP or AVIF copies using separate quality settings. Those copies do not replace a quarantine when you want the space back.', 'lucidlab-mpc-cleanup' ); ?></p></div>
				<div><span class="dashicons dashicons-clock"></span><h4><?php esc_html_e( 'Work at your pace', 'lucidlab-mpc-cleanup' ); ?></h4><p><?php esc_html_e( 'Jobs show progress and can be stopped, resumed or cancelled. Free runs one job at a time and can continue Free jobs in the background with WP-Cron. Pro can run several jobs at once.', 'lucidlab-mpc-cleanup' ); ?></p></div>
			</div></details></section>
			<div class="lucidlab-mpc-welcome-safety"><span class="dashicons dashicons-shield"></span><div><strong><?php esc_html_e( 'Built around safe decisions', 'lucidlab-mpc-cleanup' ); ?></strong><p><?php esc_html_e( 'Scans report first. Quarantine is reversible, references are shown before merging, and permanent purge always requires a separate manual action. Back up your site before large changes.', 'lucidlab-mpc-cleanup' ); ?></p></div></div>
		</div>
		<?php
	}

	/**
	 * Render backup files.
	 *
	 * @param array  $backups Backups.
	 * @param string $kind    Kind.
	 */
	private function render_backup_files( array $backups, string $kind ): void {
		echo '<ul class="lucidlab-mpc-backup-files" data-mpc-backup-files="' . esc_attr( $kind ) . '">';
		$any = false;
		foreach ( $backups as $item ) {
			if ( ( $item['kind'] ?? '' ) !== $kind ) {
				continue;
			}
			$any = true;
			echo '<li data-file="' . esc_attr( (string) ( $item['file'] ?? '' ) ) . '">';
			echo '<code>' . esc_html( (string) ( $item['file'] ?? '' ) ) . '</code> ';
			echo '<span class="lucidlab-mpc-hint">' . esc_html( (string) ( $item['detail'] ?? $item['size'] ?? '' ) . ' · ' . (string) ( $item['when'] ?? '' ) ) . '</span> ';
			$unsigned = 'unsigned' === (string) ( $item['signature'] ?? '' );
			if ( $unsigned ) {
				echo '<span class="lucidlab-mpc-badge lucidlab-mpc-badge-danger" data-mpc-backup-unsigned title="' . esc_attr__( 'This file was not created by Media & Products Cleanup on this site, or it was changed afterwards. Download it and restore it with your hosting provider\'s database tools.', 'lucidlab-mpc-cleanup' ) . '">' . esc_html__( 'Not signed by this site', 'lucidlab-mpc-cleanup' ) . '</span> ';
			}
			echo '<a class="button button-small" href="' . esc_url( (string) ( $item['download'] ?? '' ) ) . '">' . esc_html__( 'Download', 'lucidlab-mpc-cleanup' ) . '</a> ';
			if ( ! array_key_exists( 'restorable', $item ) || ! empty( $item['restorable'] ) ) {
				echo '<button type="button" class="button button-small" data-mpc-backup="restore" data-kind="' . esc_attr( $kind ) . '" data-file="' . esc_attr( (string) ( $item['file'] ?? '' ) ) . '">' . esc_html__( 'Restore', 'lucidlab-mpc-cleanup' ) . '</button> ';
			} else {
				echo '<button type="button" class="button button-small" disabled title="' . esc_attr__( 'Restore is not available for backups that are not signed by this site.', 'lucidlab-mpc-cleanup' ) . '">' . esc_html__( 'Restore', 'lucidlab-mpc-cleanup' ) . '</button> ';
			}
			echo '<button type="button" class="button button-small" data-mpc-backup="delete" data-file="' . esc_attr( (string) ( $item['file'] ?? '' ) ) . '">' . esc_html__( 'Delete', 'lucidlab-mpc-cleanup' ) . '</button>';
			echo '</li>';
		}
		if ( ! $any ) {
			echo '<li class="lucidlab-mpc-hint">' . esc_html__( 'No backups stored yet.', 'lucidlab-mpc-cleanup' ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Render backup panel.
	 */
	private function render_backup_panel(): void {
		$backups     = class_exists( 'LucidLab_MPC_Backup', false ) ? LucidLab_MPC_Backup::list_backups() : array();
		$engine      = array();
		$keep        = 5;
		$restore_url = '';
		$restore_err = '';
		if ( class_exists( 'LucidLab_MPC_Backup', false ) ) {
			$keep = LucidLab_MPC_Backup::keep();
			try {
				$engine = LucidLab_MPC_Backup::engine();
			} catch ( Throwable $e ) {
				$engine = array(
					'gzip'         => function_exists( 'gzopen' ),
					'zip'          => class_exists( 'ZipArchive' ),
					'db_note'      => __( 'SQL dump', 'lucidlab-mpc-cleanup' ),
					'uploads_note' => class_exists( 'ZipArchive' ) ? __( 'ZIP store (no extra compression)', 'lucidlab-mpc-cleanup' ) : '',
					'site_note'    => class_exists( 'ZipArchive' ) ? __( 'WordPress files + SQL, ZIP', 'lucidlab-mpc-cleanup' ) : '',
				);
			}
		}
		$up_ok = ! empty( $engine['zip'] );
		$this->end_overview_card();
		?>
		<div id="lucidlab-mpc-backup" data-mpc-backup-panel data-mpc-restore-url="<?php echo esc_url( $restore_url ); ?>">
			<?php $this->begin_results_card( ' data-mpc-backup-section="intro"' ); ?>
			<div class="lucidlab-mpc-welcome-section-heading">
				<span class="lucidlab-mpc-welcome-eyebrow"><?php esc_html_e( 'BACKUP FIRST', 'lucidlab-mpc-cleanup' ); ?></span>
				<h2 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Backup', 'lucidlab-mpc-cleanup' ); ?></h2>
				<p class="lucidlab-mpc-card__lede"><?php esc_html_e( 'Quarantine, rename, restore and other cleanup actions change the WordPress database and/or the uploads folder. Take a copy of the database, uploads, or the full site before large jobs. Backups are stored in a protected folder with a random name under uploads (lucidlab-mpc-cleanup/backups-…). Free and Pro can create, download, import and restore these copies.', 'lucidlab-mpc-cleanup' ); ?></p>
			</div>
			<p class="lucidlab-mpc-hint"><?php echo esc_html( sprintf( /* translators: %d: number of copies kept */ __( 'Keeps the newest %d copies of each type (database, uploads, full site) in the protected backup folder under uploads. Change that number on the Options tab.', 'lucidlab-mpc-cleanup' ), $keep ) ); ?></p>

			<?php $this->begin_results_card( ' data-mpc-backup-section="kinds"' ); ?>
			<div class="lucidlab-mpc-backup-grid">
				<div class="lucidlab-mpc-backup-card">
					<h3><?php esc_html_e( 'Database', 'lucidlab-mpc-cleanup' ); ?></h3>
					<p><?php esc_html_e( 'Create a SQL dump, download it, import a stored copy, or restore over the live database. Restore can sign you out. The dump is compressed with gzip or 7-Zip when that tool is available; otherwise it stays plain SQL.', 'lucidlab-mpc-cleanup' ); ?></p>
					<?php
					if ( ! empty( $engine['db_note'] ) ) :
						?>
						<p class="lucidlab-mpc-hint"><?php echo esc_html( sprintf( /* translators: %s: dump format such as SQL dump + gzip */ __( 'Format: %s', 'lucidlab-mpc-cleanup' ), $engine['db_note'] ) ); ?></p><?php endif; ?>
					<p class="lucidlab-mpc-btn-row"><button type="button" class="button button-primary" data-mpc-backup="start" data-kind="db"><?php esc_html_e( 'Backup Database', 'lucidlab-mpc-cleanup' ); ?></button></p>
					<form class="lucidlab-mpc-backup-import" data-kind="db">
						<label><?php esc_html_e( 'Import database backup', 'lucidlab-mpc-cleanup' ); ?> <input type="file" accept=".sql,.sql.gz,.gz,text/plain"></label>
						<button type="submit" class="button"><?php esc_html_e( 'Import database backup', 'lucidlab-mpc-cleanup' ); ?></button>
					</form>
					<?php $this->render_backup_files( $backups, 'db' ); ?>
				</div>
				<div class="lucidlab-mpc-backup-card">
					<h3><?php esc_html_e( 'Uploads folder', 'lucidlab-mpc-cleanup' ); ?></h3>
					<p><?php echo $up_ok ? esc_html__( 'Archive the uploads folder, download it, import a stored copy, or restore over files in uploads.', 'lucidlab-mpc-cleanup' ) : esc_html__( 'Uploads backups need 7-Zip or the PHP ZipArchive extension. Database backups still work.', 'lucidlab-mpc-cleanup' ); ?></p>
					<?php
					if ( ! empty( $engine['uploads_note'] ) ) :
						?>
						<p class="lucidlab-mpc-hint"><?php echo esc_html( sprintf( /* translators: %s: archive format such as ZIP store */ __( 'Format: %s', 'lucidlab-mpc-cleanup' ), $engine['uploads_note'] ) ); ?></p><?php endif; ?>
					<p class="lucidlab-mpc-btn-row"><button type="button" class="button button-primary" data-mpc-backup="start" data-kind="uploads" <?php disabled( ! $up_ok ); ?>><?php esc_html_e( 'Backup uploads', 'lucidlab-mpc-cleanup' ); ?></button></p>
					<form class="lucidlab-mpc-backup-import" data-kind="uploads">
						<label><?php esc_html_e( 'Import uploads backup', 'lucidlab-mpc-cleanup' ); ?> <input type="file" accept=".zip,application/zip" <?php disabled( ! $up_ok ); ?>></label>
						<button type="submit" class="button" <?php disabled( ! $up_ok ); ?>><?php esc_html_e( 'Import uploads backup', 'lucidlab-mpc-cleanup' ); ?></button>
					</form>
					<?php $this->render_backup_files( $backups, 'uploads' ); ?>
				</div>
				<div class="lucidlab-mpc-backup-card">
					<h3><?php esc_html_e( 'Full site', 'lucidlab-mpc-cleanup' ); ?></h3>
					<p><?php echo $up_ok ? esc_html__( 'Archive WordPress core files, plugins, themes, uploads and a SQL dump. Other wp-content folders and custom root files are excluded. Download the archive and restore with your hosting provider or WordPress recovery tools; excluded files and folders are preserved.', 'lucidlab-mpc-cleanup' ) : esc_html__( 'Full-site backups need 7-Zip or the PHP ZipArchive extension.', 'lucidlab-mpc-cleanup' ); ?></p>
					<?php
					if ( ! empty( $engine['site_note'] ) ) :
						?>
						<p class="lucidlab-mpc-hint"><?php echo esc_html( sprintf( /* translators: %s: archive format such as WordPress files + SQL, ZIP */ __( 'Format: %s', 'lucidlab-mpc-cleanup' ), $engine['site_note'] ) ); ?></p><?php endif; ?>
					<p class="lucidlab-mpc-btn-row"><button type="button" class="button button-primary" data-mpc-backup="start" data-kind="site" <?php disabled( ! $up_ok ); ?>><?php esc_html_e( 'Backup full site', 'lucidlab-mpc-cleanup' ); ?></button></p>
					<form class="lucidlab-mpc-backup-import" data-kind="site">
						<label><?php esc_html_e( 'Import full-site backup', 'lucidlab-mpc-cleanup' ); ?> <input type="file" accept=".zip,application/zip" <?php disabled( ! $up_ok ); ?>></label>
						<button type="submit" class="button" <?php disabled( ! $up_ok ); ?>><?php esc_html_e( 'Import full-site backup', 'lucidlab-mpc-cleanup' ); ?></button>
					</form>
					<?php $this->render_backup_files( $backups, 'site' ); ?>
				</div>
			</div>

			<?php $this->begin_results_card( ' data-mpc-backup-section="recovery"' ); ?>
			<h3 class="lucidlab-mpc-card__title"> <?php esc_html_e( 'Recovering a full-site backup', 'lucidlab-mpc-cleanup' ); ?></h3>
			<p class="lucidlab-mpc-card__lede"> <?php esc_html_e( 'Download your full-site archive and restore it with your hosting provider or WordPress recovery tools. This plugin does not install a web-accessible restore script.', 'lucidlab-mpc-cleanup' ); ?></p>
			<p class="lucidlab-mpc-backup-status notice notice-info inline lucidlab-mpc-job-summary" data-mpc-backup-status role="status" hidden></p>
		</div>
		<?php
	}

	/**
	 * Render review page.
	 */
	private function render_review_page(): void {
		if ( LucidLab_MPC_Edition::pro() && method_exists( $this, 'render_review' ) ) {
			$this->render_review();
			return; }
		$this->render_locked_tab( 'review' );
	}

	/**
	 * Render notices.
	 *
	 * @param array $notices Notices.
	 */
	private function render_notices( array $notices ): void {
		foreach ( $notices as $n ) {
			list( $type, $msg ) = $n;
			$class              = array(
				'success' => 'notice-success',
				'error'   => 'notice-error',
				'warning' => 'notice-warning',
			)[ $type ] ?? 'notice-info';
			printf( '<div class="notice %s inline is-dismissible lucidlab-mpc-notice" role="status"><p>%s</p></div>', esc_attr( $class ), wp_kses_post( $msg ) );
		}
	}

	/**
	 * Blue confirmation messages in a white card directly under the compact hero (Options / Backup; Logs uses the same blue styling without forcing a card when empty).
	 *
	 * @param array<int,array{0?:string,1?:string}> $notices    Notices.
	 * @param bool                                  $force_info Force info.
	 */
	private function render_hero_notice_card( array $notices, bool $force_info = true ): void {
		$has = false;
		foreach ( $notices as $n ) {
			if ( is_array( $n ) && '' !== trim( (string) ( $n[1] ?? '' ) ) ) {
				$has = true;
				break;
			}
		}
		echo '<section class="lucidlab-mpc-card lucidlab-mpc-card--results lucidlab-mpc-panel-notices" data-mpc-panel-notices' . ( $has ? '' : ' hidden' ) . '>';
		foreach ( $notices as $n ) {
			$msg = trim( (string) ( $n[1] ?? '' ) );
			if ( '' === $msg ) {
				continue;
			}
			$type  = $force_info ? 'info' : (string) ( $n[0] ?? 'info' );
			$class = array(
				'success' => 'notice-success',
				'error'   => 'notice-error',
				'warning' => 'notice-warning',
			)[ $type ] ?? 'notice-info';
			printf( '<div class="notice %s inline lucidlab-mpc-notice" role="status"><p>%s</p></div>', esc_attr( $class ), wp_kses_post( $msg ) );
		}
		echo '</section>';
	}

	/**
	 * Render option notices.
	 *
	 * @param array $notices Notices.
	 */
	private function render_option_notices( array $notices ): void {
		foreach ( $notices as $n ) {
			printf( '<p class="notice notice-info inline lucidlab-mpc-job-summary" role="status">%s</p>', wp_kses_post( $n[1] ?? '' ) );
		}
	}

	/**
	 * Progress bar.
	 *
	 * @param string $id Id.
	 */
	private function progress_bar( string $id ): void {
		?>
		<div class="lucidlab-mpc-progress" id="lucidlab-mpc-progress-<?php echo esc_attr( $id ); ?>" hidden>
			<div class="lucidlab-mpc-progress-label"><?php esc_html_e( 'Starting…', 'lucidlab-mpc-cleanup' ); ?></div>
			<div class="lucidlab-mpc-progress-track"><div class="lucidlab-mpc-progress-bar"></div></div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: list folders.
	 */
	private function ajax_list_folders(): void {
		$this->task_success( array( 'folders' => class_exists( 'LucidLab_MPC_Exclusions', false ) ? LucidLab_MPC_Exclusions::upload_root_folders() : array() ) );
	}

	/**
	 * AJAX handler: core check.
	 *
	 * @throws RuntimeException When the operation fails.
	 */
	private function ajax_core_check(): void {
		$sub = sanitize_key( wp_unslash( $this->req['sub'] ?? 'scan' ) );
		try {
			if ( 'scan' === $sub ) {
				$this->task_success( LucidLab_MPC_Core_Check::scan() );
			}
			if ( 'result' === $sub ) {
				$this->task_success(
					LucidLab_MPC_Helpers::either(
						LucidLab_MPC_Core_Check::saved_result(),
						array(
							'ok'      => true,
							'items'   => array(),
							'counts'  => array( 'total' => 0 ),
							'message' => '',
						)
					)
				);
			}
			$path = sanitize_text_field( wp_unslash( $this->req['path'] ?? '' ) );
			if ( 'replace' === $sub ) {
				$this->task_success( LucidLab_MPC_Core_Check::replace( $path ) );
			}
			if ( 'delete' === $sub ) {
				if ( 'CONFIRM' !== sanitize_text_field( wp_unslash( $this->req['confirm'] ?? '' ) ) ) {
					throw new RuntimeException( __( 'Type CONFIRM before permanently deleting this file.', 'lucidlab-mpc-cleanup' ) );
				}
				$paths = LucidLab_MPC_Helpers::json_input( $this->req['paths'] ?? '[]', array( 'list' => 'path' ) );
				if ( is_array( $paths ) && count( $paths ) > 1 ) {
					$this->task_success( LucidLab_MPC_Core_Check::delete_extras( $paths ) );
				}
				if ( is_array( $paths ) && 1 === count( $paths ) && '' === $path ) {
					$path = (string) $paths[0];
				}
				$this->task_success( LucidLab_MPC_Core_Check::delete_extra( $path ) );
			}
			if ( 'quarantine' === $sub ) {
				$paths = LucidLab_MPC_Helpers::json_input( $this->req['paths'] ?? '[]', array( 'list' => 'path' ) );
				if ( ! is_array( $paths ) ) {
					$paths = $path ? array( $path ) : array();
				}
				$this->task_success( LucidLab_MPC_Core_Check::quarantine( $paths ) );
			}
			if ( 'view' === $sub || 'compare' === $sub ) {
				$this->task_success( LucidLab_MPC_Core_Check::view( $path, 'compare' === $sub || ! empty( $this->req['compare'] ) ) );
			}
			wp_send_json_error( array( 'message' => __( 'Unknown core check action.', 'lucidlab-mpc-cleanup' ) ), 400 );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}
	}

	/**
	 * Delete toggle.
	 */
	private function delete_toggle(): void {
		?>
		<label class="lucidlab-mpc-toggle"><input type="checkbox" class="lucidlab-mpc-enable" data-testid="enable-delete" /> <span><?php esc_html_e( 'I understand — enable deletion for this action', 'lucidlab-mpc-cleanup' ); ?></span></label>
		<?php
	}

	/* ---------- Tab 1 ---------- */

	/* ---------- Tab 2 ---------- */

	/**
	 * Render orphans.
	 *
	 * @param mixed $data Data.
	 */
	private function render_orphans( $data ): void {
		?>
		<?php if ( $this->scan_is_stale( 'orphans' ) ) : ?>
			<p class="notice notice-warning inline"><?php esc_html_e( 'These Orphan results are out of date. Files were restored from quarantine after this scan. Run the scan again before relying on the count.', 'lucidlab-mpc-cleanup' ); ?></p>
		<?php endif; ?>
		<p class="lucidlab-mpc-scan-description"><?php esc_html_e( 'Scan builds one authoritative "used" set from featured images, product galleries, Flatsome UX Builder shortcodes, Gutenberg blocks, theme options, widgets, term meta, Elementor and serialized meta — then the remainder is orphaned. When in doubt, it keeps. Selected orphans are moved to the restorable quarantine (no MEDIA_TRASH needed).', 'lucidlab-mpc-cleanup' ); ?></p>
		<form method="post" class="lucidlab-mpc-controls" id="lucidlab-mpc-scan-orphans-form">
			<?php wp_nonce_field( 'lucidlab_mpc_op_scan_orphans' ); ?>
			<input type="hidden" name="lucidlab_mpc_op" value="scan_orphans" />
			<button type="submit" class="button button-primary" data-mpc-scan="orphans" data-testid="scan-orphans"><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Scan orphan images', 'lucidlab-mpc-cleanup' ); ?></button>
			<?php $this->render_view_saved_results( 'orphans', __( 'Loading orphan results…', 'lucidlab-mpc-cleanup' ) ); ?>
			<?php if ( is_array( $data ) ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lucidlab_mpc_export_csv&tab=orphans' ), 'lucidlab_mpc_export_orphans' ) ); ?>"><span class="dashicons dashicons-media-spreadsheet"></span> <?php esc_html_e( 'Export CSV', 'lucidlab-mpc-cleanup' ); ?></a>
			<?php endif; ?>
		</form>

		<?php if ( ! is_array( $data ) ) : ?>
			
			<?php
			return;
endif;
		?>

		<?php $this->begin_results_card( ' data-mpc-results-section="orphans"' ); ?>
		<?php /* translators: %s says whether the ACF scanner is active. */ ?>
		<p class="lucidlab-mpc-hint"><?php echo esc_html( sprintf( __( 'ACF scan: %s. Deleting moves files to quarantine — no wp-config change required.', 'lucidlab-mpc-cleanup' ), ! empty( $data['acf_active'] ) ? __( 'active', 'lucidlab-mpc-cleanup' ) : __( 'inactive (skipped)', 'lucidlab-mpc-cleanup' ) ) ); ?></p>
		<div class="lucidlab-mpc-stats" data-mpc-results-loaded="1" hidden></div>

		<?php if ( empty( $data['orphans'] ) ) : ?>
			<?php if ( ! $this->render_if_quarantined( $data ) ) : ?>
			<p class="lucidlab-mpc-empty lucidlab-mpc-good"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'No orphan images detected.', 'lucidlab-mpc-cleanup' ); ?></p>
			<?php endif; ?>
			<?php
			return;
endif;
		?>

		<?php
		$orphans_page    = $this->results_page( (array) $data['orphans'], 'lucidlab_mpc_orphans_page' );
		$data['orphans'] = $orphans_page['items'];
		?>

		<?php $this->progress_bar( 'orphans-quar' ); ?>
		<div class="lucidlab-mpc-quar-zone" data-tab="orphans">
			<table class="widefat lucidlab-mpc-table">
				<thead><tr>
					<th><input type="checkbox" class="lucidlab-mpc-selectall" aria-label="<?php esc_attr_e( 'Select all rows', 'lucidlab-mpc-cleanup' ); ?>" data-testid="orphan-select-all" /></th><th><?php esc_html_e( 'Preview', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'ID', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Title / URL', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Size', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Status', 'lucidlab-mpc-cleanup' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $data['orphans'] as $o ) : ?>
					<tr>
						<td><input type="checkbox" class="lucidlab-mpc-row-check" value="<?php echo (int) $o['id']; ?>" data-bytes="<?php echo (int) $o['size']; ?>" data-label="<?php echo esc_attr( ( $o['title'] ? $o['title'] : '#' . (int) $o['id'] ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: image title or ID */ __( 'Select %s', 'lucidlab-mpc-cleanup' ), ( $o['title'] ? $o['title'] : '#' . (int) $o['id'] ) ) ); ?>" data-testid="orphan-check-<?php echo (int) $o['id']; ?>" /></td>
						<td><?php echo $o['thumb'] ? '<img class="lucidlab-mpc-thumb lucidlab-mpc-lb" src="' . esc_url( $o['thumb'] ) . '" data-full="' . esc_url( (string) $o['url'] ) . '" data-caption="' . esc_attr( ( $o['title'] ? $o['title'] : '' ) ) . '" alt="" />' : '<span class="lucidlab-mpc-thumb lucidlab-mpc-thumb-none dashicons dashicons-format-image"></span>'; ?></td>
						<td><?php echo (int) $o['id']; ?></td>
						<td><strong><?php echo esc_html( ( $o['title'] ? $o['title'] : __( '(no title)', 'lucidlab-mpc-cleanup' ) ) ); ?></strong><br /><span class="lucidlab-mpc-url"><?php echo esc_html( (string) $o['url'] ); ?></span></td>
						<td><?php echo esc_html( LucidLab_MPC_Helpers::bytes( $o['size'] ) ); ?></td>
						<td><span class="lucidlab-mpc-badge lucidlab-mpc-badge-risk"><?php esc_html_e( 'orphan', 'lucidlab-mpc-cleanup' ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="lucidlab-mpc-actionbar">
				<?php $this->delete_toggle(); ?>
				<button type="button" class="button button-primary lucidlab-mpc-danger" data-mpc-quarantine="orphans" disabled data-testid="quarantine-orphans"><span class="dashicons dashicons-archive"></span> <?php esc_html_e( 'Move selected to quarantine', 'lucidlab-mpc-cleanup' ); ?></button>
			</div>
		</div>
		<?php
		$this->render_results_pager( $orphans_page, 'orphans', 'lucidlab_mpc_orphans_page', __( 'orphans', 'lucidlab-mpc-cleanup' ) );
	}

	/* ---------- Tab 3 ---------- */

	/* ---------- Tab 9 (Quarantine, central) ---------- */

	/**
	 * Render quarantine tab.
	 */
	private function render_quarantine_tab(): void {
		if ( ! $this->render_list_requested( 'quarantine', __( 'Load quarantined items', 'lucidlab-mpc-cleanup' ) ) ) {
			return;
		}
		$this->begin_results_card( ' data-mpc-results-section="quarantine"' );
		/* translators: %d is the quarantine retention period in days. */
		echo '<p class="lucidlab-mpc-hint">' . esc_html( LucidLab_MPC_Edition::pro() ? sprintf( __( 'All quarantined items, grouped by source. Eligible sets are auto-purged after %d days; original-image backups are kept until you purge.', 'lucidlab-mpc-cleanup' ), LucidLab_MPC_Quarantine::retention_days() ) : __( 'All quarantined items, grouped by source. Kept until you purge; automatic purge requires Pro.', 'lucidlab-mpc-cleanup' ) ) . '</p>';
		$sets    = $this->quar->list_sets();
		$sources = array(
			'orphans'    => __( 'Orphan Images', 'lucidlab-mpc-cleanup' ),
			'uploads'    => __( 'Uploads Scanner', 'lucidlab-mpc-cleanup' ),
			'fs-sizes'   => __( 'Other Sizes on Disk', 'lucidlab-mpc-cleanup' ),
			'webp'       => __( 'Generated WebP (including legacy mixed sets)', 'lucidlab-mpc-cleanup' ),
			'avif'       => __( 'Generated AVIF', 'lucidlab-mpc-cleanup' ),
			'originals'  => __( 'Original images', 'lucidlab-mpc-cleanup' ),
			'dupcontent' => __( 'Byte Identical Images', 'lucidlab-mpc-cleanup' ),
			'visual'     => __( 'Visually Identical Images', 'lucidlab-mpc-cleanup' ),
			'missing'    => __( 'Missing Images', 'lucidlab-mpc-cleanup' ),
			'dbcleaner'  => __( 'Database Cleaner', 'lucidlab-mpc-cleanup' ),
		);
		// Every restorable set is listed, so this tab shows the same number as Diagnostics. Sources without a
		// fixed card (Image Sizes, Shared Images, WordPress files and any older source) get a card when they have sets.
		foreach ( $sets as $s ) {
			$src = (string) ( $s['source'] ?? 'uploads' );
			if ( ! isset( $sources[ $src ] ) ) {
				$label           = LucidLab_MPC_Quarantine::source_label( $src );
				$sources[ $src ] = '' !== $label ? $label : sprintf( /* translators: %s: internal source name of a quarantine set. */ __( 'Other source: %s', 'lucidlab-mpc-cleanup' ), $src );
			}
		}
		echo '<p class="lucidlab-mpc-hint" data-mpc-quarantine-total="' . esc_attr( (string) count( $sets ) ) . '">' . esc_html( $this->quarantine_count_summary( $sets ) ) . '</p>';
		$this->render_quarantine_health();
		foreach ( $sources as $src => $label ) {
			$block = array_values( array_filter( $sets, static fn( $s ) => (string) ( $s['source'] ?? 'uploads' ) === (string) $src ) );
			$this->begin_results_card( ' data-mpc-source="' . esc_attr( $src ) . '"' );
			echo '<h3 class="lucidlab-mpc-card__title">' . esc_html( $label ) . '</h3>';
			if ( ! LucidLab_MPC_Edition::pro() && LucidLab_MPC_Edition::pro_quarantine_source( $src ) ) {
				$this->render_pro_required_notice( __( 'This tool requires the Pro add-on. Existing items can still be restored.', 'lucidlab-mpc-cleanup' ) );
			}
			if ( ! $block && 'visual' === $src ) {
				echo ( '<p class="lucidlab-mpc-empty">' . esc_html__( 'No quarantined items. Merged look-alike duplicates from Visually Identical Images appear here so you can restore or undo them.', 'lucidlab-mpc-cleanup' ) . '</p>' );
			} elseif ( ! $block && 'missing' === $src ) {
				echo ( '<p class="lucidlab-mpc-empty">' . esc_html__( 'No quarantined items. The Missing Images Purge action permanently removes missing media records; those records do not enter quarantine.', 'lucidlab-mpc-cleanup' ) . '</p>' );
			} elseif ( ! $block && 'dbcleaner' === $src ) {
				echo '<p class="lucidlab-mpc-empty">' . esc_html__( 'No quarantined database items. Use Database Cleaner to move unused plugin tables or leftover records here.', 'lucidlab-mpc-cleanup' ) . '</p>';
			} else {
				$this->render_set_table( $block, $src );
			}
			if ( 'originals' === $src ) {
				echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'JPEG and PNG files moved aside while converting to WebP or AVIF. These are not deleted automatically, so you can still create the other format later. Purge them only when you no longer need that conversion.', 'lucidlab-mpc-cleanup' ) . '</p>';
			}
		}
		// Keep individual items from every source available for search and restore.
		$this->begin_results_card( ' data-mpc-quarantine-files="1"' );
		$this->render_quarantine_files();
	}

	/**
	 * One sentence used by Diagnostics and the Quarantine tab, so both count the same sets the same way.
	 *
	 * @param array $sets Rows from LucidLab_MPC_Quarantine::list_sets().
	 *
	 * @return string
	 */
	private function quarantine_count_summary( array $sets ): string {
		$protected = 0;
		foreach ( $sets as $s ) {
			if ( LucidLab_MPC_Quarantine::is_health_protected( (string) ( $s['stamp'] ?? '' ) ) ) {
				++$protected;
			}
		}
		$total = count( $sets );
		/* translators: %s: number of quarantine sets that still contain restorable items. */
		$text = sprintf( _n( '%s restorable set (all sources)', '%s restorable sets (all sources)', $total, 'lucidlab-mpc-cleanup' ), number_format_i18n( $total ) );
		if ( $protected > 0 ) {
			/* translators: %s: number of protected sets that are never purged automatically. */
			$text .= ' · ' . sprintf( _n( 'includes %s protected set that is never purged automatically', 'includes %s protected sets that are never purged automatically', $protected, 'lucidlab-mpc-cleanup' ), number_format_i18n( $protected ) );
		}
		return $text;
	}

	/**
	 * Broken quarantine manifests / disk mismatches — never auto-deletes.
	 */
	private function render_quarantine_health(): void {
		$health        = $this->quar->health_scan();
		$counts        = (array) ( $health['counts'] ?? array() );
		$issues        = (array) ( $health['issues'] ?? array() );
		$problem_total = (int) ( $counts['invalid_manifest'] ?? 0 )
			+ (int) ( $counts['missing_manifest'] ?? 0 )
			+ (int) ( $counts['empty_manifest_files'] ?? 0 )
			+ (int) ( $counts['missing_files'] ?? 0 )
			+ (int) ( $counts['orphan_disk_files'] ?? 0 );
		?>
		<?php $this->begin_results_card( ' data-testid="quarantine-health"' ); ?>
		<div class="lucidlab-mpc-quarantine-health">
			<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Quarantine Health', 'lucidlab-mpc-cleanup' ); ?></h3>
			<p class="lucidlab-mpc-hint"><?php esc_html_e( 'Checks quarantine folders for broken or missing manifests, manifest rows that point at missing files, and files on disk that are not listed. Nothing is deleted automatically. Permanent purge of broken artifacts is not restoreable. Protected test sets are never purged here.', 'lucidlab-mpc-cleanup' ); ?></p>
			<ul class="lucidlab-mpc-hint" style="margin-left:1.2em;">
				<?php /* translators: %s is the localized number of quarantine folders scanned. */ ?>
				<li><?php echo esc_html( sprintf( __( 'Folders scanned: %s', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) ( $counts['folders'] ?? 0 ) ) ) ); ?></li>
				<?php /* translators: %s is the localized number of broken manifests. */ ?>
				<li><?php echo esc_html( sprintf( __( 'Broken manifests: %s', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) ( $counts['invalid_manifest'] ?? 0 ) ) ) ); ?></li>
				<?php /* translators: %s is the localized number of folders without a manifest. */ ?>
				<li><?php echo esc_html( sprintf( __( 'Folders with no manifest: %s', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) ( $counts['missing_manifest'] ?? 0 ) ) ) ); ?></li>
				<?php /* translators: %s is the localized number of empty manifests with files still on disk. */ ?>
				<li><?php echo esc_html( sprintf( __( 'Empty manifest with files on disk: %s', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) ( $counts['empty_manifest_files'] ?? 0 ) ) ) ); ?></li>
				<?php /* translators: %s is the localized number of manifest entries whose files are missing. */ ?>
				<li><?php echo esc_html( sprintf( __( 'Manifest rows with missing files: %s', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) ( $counts['missing_files'] ?? 0 ) ) ) ); ?></li>
				<?php /* translators: %s is the localized number of disk files absent from manifests. */ ?>
				<li><?php echo esc_html( sprintf( __( 'Disk files not in manifest: %s', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) ( $counts['orphan_disk_files'] ?? 0 ) ) ) ); ?></li>
			</ul>
			<?php if ( ! $problem_total ) : ?>
				<p class="lucidlab-mpc-empty lucidlab-mpc-good"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'No quarantine health issues found.', 'lucidlab-mpc-cleanup' ); ?></p>
			<?php else : ?>
				<table class="widefat lucidlab-mpc-table">
					<thead><tr>
						<th><?php esc_html_e( 'Set', 'lucidlab-mpc-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Issue', 'lucidlab-mpc-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Count', 'lucidlab-mpc-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Size', 'lucidlab-mpc-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Samples', 'lucidlab-mpc-cleanup' ); ?></th>
					</tr></thead>
					<tbody>
					<?php
					foreach ( $issues as $issue ) :
						if ( ! is_array( $issue ) ) {
							continue;
						}
						$prot = ! empty( $issue['protected'] );
						?>
						<tr>
							<td><code><?php echo esc_html( (string) ( $issue['stamp'] ?? '' ) ); ?></code>
							<?php
							if ( $prot ) :
								?>
								<span class="lucidlab-mpc-badge lucidlab-mpc-badge-safe"><?php esc_html_e( 'Protected', 'lucidlab-mpc-cleanup' ); ?></span><?php endif; ?></td>
							<td><?php echo esc_html( (string) ( $issue['label'] ?? $issue['kind'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $issue['count'] ?? 0 ) ) ); ?></td>
							<td><?php echo ! empty( $issue['bytes'] ) ? esc_html( LucidLab_MPC_Helpers::bytes( (int) $issue['bytes'] ) ) : '—'; ?></td>
							<td class="lucidlab-mpc-hint">
							<?php
								$samples = array_slice( (array) ( $issue['samples'] ?? array() ), 0, 4 );
								echo $samples ? esc_html( implode( ', ', array_map( 'strval', $samples ) ) ) : '—';
							?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php
				$purgeable = array_values(
					array_filter(
						$issues,
						static fn( $i ) => is_array( $i ) && empty( $i['protected'] )
					)
				);
				if ( $purgeable ) :
					$kind_labels   = array(
						'invalid_manifest'     => __( 'Broken manifests', 'lucidlab-mpc-cleanup' ),
						'missing_manifest'     => __( 'Folders with no manifest', 'lucidlab-mpc-cleanup' ),
						'empty_manifest_files' => __( 'Empty manifest with files on disk', 'lucidlab-mpc-cleanup' ),
						'missing_files'        => __( 'Stale missing-file manifest rows', 'lucidlab-mpc-cleanup' ),
						'orphan_disk_files'    => __( 'Disk files not in manifest', 'lucidlab-mpc-cleanup' ),
					);
					$kinds_present = array();
					foreach ( $purgeable as $issue ) {
						$k = sanitize_key( (string) ( $issue['kind'] ?? '' ) );
						if ( $k ) {
							$kinds_present[ $k ] = ( $kinds_present[ $k ] ?? 0 ) + 1;
						}
					}
					?>
					<form method="post" class="lucidlab-mpc-quarantine-health-purge" data-mpc-confirm-message="<?php echo esc_attr__( 'PERMANENTLY delete the selected broken quarantine artifacts? They cannot be restored.', 'lucidlab-mpc-cleanup' ); ?>">
						<?php wp_nonce_field( 'lucidlab_mpc_op_purge_quarantine_health' ); ?>
						<input type="hidden" name="lucidlab_mpc_op" value="purge_quarantine_health" />
						<p><strong><?php esc_html_e( 'Permanently purge broken artifacts', 'lucidlab-mpc-cleanup' ); ?></strong></p>
						<?php foreach ( $kinds_present as $kind => $n ) : ?>
							<label style="display:block;margin:4px 0;">
								<input type="checkbox" name="lucidlab_mpc_health_kinds[]" value="<?php echo esc_attr( $kind ); ?>" data-testid="health-kind-<?php echo esc_attr( $kind ); ?>" />
								<?php echo esc_html( ( $kind_labels[ $kind ] ?? $kind ) . ' (' . number_format_i18n( $n ) . ')' ); ?>
							</label>
						<?php endforeach; ?>
						<p class="lucidlab-mpc-btn-row">
							<button type="submit" class="button lucidlab-mpc-danger-text" data-testid="purge-quarantine-health"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Purge selected broken artifacts', 'lucidlab-mpc-cleanup' ); ?></button>
						</p>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render set table.
	 *
	 * @param array  $sets   Sets.
	 * @param string $source Source.
	 */
	private function render_set_table( array $sets, string $source ): void {
		if ( empty( $sets ) ) {
			echo '<p class="lucidlab-mpc-empty">' . esc_html__( 'Nothing here.', 'lucidlab-mpc-cleanup' ) . '</p>';
			return;
		}
		?>
		<table class="widefat lucidlab-mpc-table">
			<thead><tr><th><?php esc_html_e( 'Set', 'lucidlab-mpc-cleanup' ); ?></th><th><?php echo esc_html( 'dbcleaner' === $source ? __( 'Items', 'lucidlab-mpc-cleanup' ) : __( 'Files on disk', 'lucidlab-mpc-cleanup' ) ); ?></th><th><?php echo esc_html( 'dbcleaner' === $source ? '—' : __( 'Media', 'lucidlab-mpc-cleanup' ) ); ?></th><th><?php esc_html_e( 'Size', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Expires', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Actions', 'lucidlab-mpc-cleanup' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $sets as $s ) : ?>
				<tr>
					<td><code><?php echo esc_html( $s['stamp'] ); ?></code></td>
					<td><?php echo (int) $s['count']; ?></td>
					<td><?php echo (int) ( $s['attachments'] ?? $s['media'] ?? 0 ); ?></td>
					<td><?php echo esc_html( LucidLab_MPC_Helpers::bytes( $s['bytes'] ) ); ?></td>
					<?php /* translators: %d is the number of days until this quarantine set expires. */ ?>
					<td>
					<?php
					if ( ! LucidLab_MPC_Edition::pro() || 'originals' === $source ) :
						?>
						<?php esc_html_e( 'Kept until you purge', 'lucidlab-mpc-cleanup' ); ?>
						<?php
else :
	?>
						<?php echo esc_html( sprintf( /* translators: %d: number of days. */ _n( '%d day', '%d days', $s['expires'], 'lucidlab-mpc-cleanup' ), $s['expires'] ) ); ?>
						<?php
						if ( $s['expires'] <= 3 ) :
							?>
	<span class="lucidlab-mpc-badge lucidlab-mpc-badge-risk" title="<?php echo esc_attr__( 'This quarantine set is nearing automatic purge.', 'lucidlab-mpc-cleanup' ); ?>"><?php esc_html_e( 'Expires soon', 'lucidlab-mpc-cleanup' ); ?></span><?php endif; ?><?php endif; ?></td>
					<td class="lucidlab-mpc-qactions">
								<a class="button button-small" href="<?php echo esc_url( $this->tab_url( 'quarantine' ) . '&lucidlab_mpc_load=1&lucidlab_mpc_view_set=' . rawurlencode( $s['stamp'] ) . '#lucidlab-mpc-quarantine-items' ); ?>"><?php esc_html_e( 'View', 'lucidlab-mpc-cleanup' ); ?></a>
								<a class="button button-small" href="
								<?php
								echo esc_url(
									wp_nonce_url(
										add_query_arg(
											array(
												'action' => 'lucidlab_mpc_export_manifest',
												'set'    => $s['stamp'],
											),
											admin_url( 'admin-post.php' )
										),
										'lucidlab_mpc_export_manifest_' . $s['stamp']
									)
								);
								?>
"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export', 'lucidlab-mpc-cleanup' ); ?></a>
							<?php if ( in_array( $source, array( 'dupcontent', 'visual' ), true ) ) : ?>
								<form method="post" data-mpc-undo-merge><?php wp_nonce_field( 'lucidlab_mpc_op_undo_merge' ); ?><input type="hidden" name="lucidlab_mpc_op" value="undo_merge" /><input type="hidden" name="lucidlab_mpc_set" value="<?php echo esc_attr( $s['stamp'] ); ?>" /><button class="button button-small" data-testid="undo-merge-<?php echo esc_attr( $s['stamp'] ); ?>"><span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e( 'Undo merge', 'lucidlab-mpc-cleanup' ); ?></button></form>
							<?php else : ?>
								<form method="post" data-mpc-restore="set"><?php wp_nonce_field( 'lucidlab_mpc_op_restore_quarantine' ); ?><input type="hidden" name="lucidlab_mpc_op" value="restore_quarantine" /><input type="hidden" name="lucidlab_mpc_set" value="<?php echo esc_attr( $s['stamp'] ); ?>" /><input type="hidden" name="lucidlab_mpc_source" value="<?php echo esc_attr( $source ); ?>" /><button class="button button-small"><span class="dashicons dashicons-undo"></span> <?php esc_html_e( 'Restore', 'lucidlab-mpc-cleanup' ); ?></button></form>
							<?php endif; ?>
							<?php
							if ( LucidLab_MPC_Edition::pro() || ! LucidLab_MPC_Edition::pro_quarantine_source( $source ) ) :
								?>
								<form method="post" data-mpc-confirm-message="PERMANENTLY delete this set?"><?php wp_nonce_field( 'lucidlab_mpc_op_purge_quarantine' ); ?><input type="hidden" name="lucidlab_mpc_op" value="purge_quarantine" /><input type="hidden" name="lucidlab_mpc_set" value="<?php echo esc_attr( $s['stamp'] ); ?>" /><input type="hidden" name="lucidlab_mpc_source" value="<?php echo esc_attr( $source ); ?>" /><button class="button button-small lucidlab-mpc-danger-text"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Purge', 'lucidlab-mpc-cleanup' ); ?></button></form><?php endif; ?>
						</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( LucidLab_MPC_Edition::pro() || ! LucidLab_MPC_Edition::pro_quarantine_source( $source ) ) :
			?>
			<form method="post" class="lucidlab-mpc-purge-all" data-mpc-confirm-message="PERMANENTLY delete ALL sets in this section?">
			<?php wp_nonce_field( 'lucidlab_mpc_op_purge_source' ); ?>
			<input type="hidden" name="lucidlab_mpc_op" value="purge_source" /><input type="hidden" name="lucidlab_mpc_source" value="<?php echo esc_attr( $source ); ?>" />
			<button class="button lucidlab-mpc-danger-text button-small"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Purge all in this section', 'lucidlab-mpc-cleanup' ); ?></button>
		</form><?php endif; ?>
		<?php
	}

	/* ---------- Tab 4 (Image Sizes) ---------- */

	/* ---------- Tab 7 (Regenerate Thumbnails) ---------- */

	/**
	 * Render regenerate.
	 */
	private function render_regenerate(): void {
		$job      = $this->regen->job();
		$sizes    = $this->sizes->registered();
		$page     = LucidLab_MPC_Settings::page_regen();
		$quality  = (int) ( $page['quality'] ?? 85 );
		$mode     = (string) ( $page['mode'] ?? 'skip' );
		$picked   = (array) ( $page['sizes'] ?? array() );
		$disabled = array_keys( array_filter( $sizes, static fn( $s ) => $s['disabled'] ) );
		$terminal = ! empty( $job['updated'] ) && in_array( (string) ( $job['status'] ?? 'idle' ), array( 'done', 'failed', 'cancelled' ), true );
		?>
		<?php if ( $terminal ) : ?>
			<p class="lucidlab-mpc-regen-stat notice notice-info inline lucidlab-mpc-job-summary" id="lucidlab-mpc-regen-stat" role="status">
			<?php
				$prefix = 'cancelled' === ( $job['status'] ?? '' ) ? __( 'Cancelled on:', 'lucidlab-mpc-cleanup' ) : ( 'failed' === ( $job['status'] ?? '' ) ? __( 'Failed on:', 'lucidlab-mpc-cleanup' ) : __( 'Completed on:', 'lucidlab-mpc-cleanup' ) );
				echo esc_html(
					$prefix . ' ' . LucidLab_MPC_Helpers::job_datetime( (int) $job['updated'] ) . ' — '
					. sprintf(
						/* translators: 1: images processed, 2: images regenerated, 3: images skipped, 4: images failed. */
						__( '%1$s processed, %2$s regenerated, %3$s skipped, %4$s failed.', 'lucidlab-mpc-cleanup' ),
						number_format_i18n( (int) $job['done'] ),
						number_format_i18n( (int) $job['regenerated'] ),
						number_format_i18n( (int) $job['skipped'] ),
						number_format_i18n( (int) $job['failed'] )
					)
				);
			?>
																													</p>
			<?php
			$fail_notes = array();
			foreach ( (array) ( $job['reasons'] ?? array() ) as $reason ) {
				if ( ( $reason['type'] ?? '' ) !== 'failed' ) {
					continue;
				}
				$why = (string) ( $reason['why'] ?? '' );
				if ( '' === $why ) {
					continue;
				}
				$fail_notes[ $why ] = ( $fail_notes[ $why ] ?? 0 ) + 1;
			}
			if ( $fail_notes ) :
				?>
				<ul class="lucidlab-mpc-hint" id="lucidlab-mpc-regen-fail-reasons" style="margin:0 0 12px 1.2em;">
					<?php foreach ( $fail_notes as $why => $count ) : ?>
						<li><?php echo esc_html( sprintf( /* translators: 1: count, 2: reason */ _n( '%1$d failed: %2$s', '%1$d failed: %2$s', $count, 'lucidlab-mpc-cleanup' ), number_format_i18n( $count ), $why ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php else : ?>
			<p class="lucidlab-mpc-regen-stat notice notice-info inline" id="lucidlab-mpc-regen-stat" role="status" hidden></p>
		<?php endif; ?>
		<div id="lucidlab-mpc-regen" data-status="<?php echo esc_attr( $job['status'] ); ?>" data-driver="<?php echo esc_attr( $job['driver'] ); ?>">
			<?php if ( ! empty( $disabled ) ) : ?>
				<div class="notice notice-warning inline"><p>
				<?php
					$disabled_badges = '<span class="lucidlab-mpc-regen-disabled-list">';
				foreach ( $disabled as $disabled_name ) {
					$disabled_badges .= '<span>' . esc_html( $disabled_name ) . '</span>';
				}
					$disabled_badges .= '</span>';
					/* translators: %s is HTML markup listing disabled image-size badges. */
					echo wp_kses_post( sprintf( __( 'These sizes are disabled and will be skipped: %s', 'lucidlab-mpc-cleanup' ), $disabled_badges ) );
				?>
				</p></div>
			<?php endif; ?>
			<p class="lucidlab-mpc-hint"><?php esc_html_e( 'With the default “Skip correctly-sized thumbnails” option, images are regenerated only when a selected thumbnail is missing or its dimensions no longer match the current image size settings. Images whose selected thumbnails are already correct are skipped. “Regenerate ALL attachments” and “Featured images only” force regeneration for their selected images.', 'lucidlab-mpc-cleanup' ); ?></p>
			<p class="lucidlab-mpc-hint"><?php esc_html_e( 'Run All Scans uses these same mode, size and quality choices when it regenerates thumbnails. Failed images are usually missing on disk (for example after quarantine) or unsupported/corrupt files.', 'lucidlab-mpc-cleanup' ); ?></p>
			<div class="lucidlab-mpc-regen-opts">
				<fieldset class="lucidlab-mpc-match">
					<legend><?php esc_html_e( 'Sizes to (re)generate', 'lucidlab-mpc-cleanup' ); ?></legend>
					<div class="lucidlab-mpc-regen-size-grid">
					<?php foreach ( $sizes as $name => $s ) : ?>
						<?php if ( ! empty( $s['disabled'] ) ) : ?>
							<span class="lucidlab-mpc-regen-disabled-size lucidlab-mpc-hint"><?php echo esc_html( $name . ' (' . __( 'disabled in Image Sizes', 'lucidlab-mpc-cleanup' ) . ')' ); ?></span>
						<?php else : ?>
							<label><input type="checkbox" class="lucidlab-mpc-regen-size" value="<?php echo esc_attr( $name ); ?>" <?php checked( ! $picked || in_array( $name, $picked, true ) ); ?> /> <?php echo esc_html( $name ); ?></label>
						<?php endif; ?>
					<?php endforeach; ?>
					</div>
				</fieldset>
				<p>
					<label><input type="radio" name="lucidlab-mpc-regen-mode" value="skip" <?php checked( $mode, 'skip' ); ?> /> <?php esc_html_e( 'Skip correctly-sized thumbnails (faster)', 'lucidlab-mpc-cleanup' ); ?></label><br />
					<label><input type="radio" name="lucidlab-mpc-regen-mode" value="all" <?php checked( $mode, 'all' ); ?> /> <?php esc_html_e( 'Regenerate ALL attachments', 'lucidlab-mpc-cleanup' ); ?></label><br />
					<label><input type="radio" name="lucidlab-mpc-regen-mode" value="featured" <?php checked( $mode, 'featured' ); ?> /> <?php esc_html_e( 'Featured images only', 'lucidlab-mpc-cleanup' ); ?></label>
				</p>
				<p><label><?php esc_html_e( 'Quality', 'lucidlab-mpc-cleanup' ); ?> <input type="number" id="lucidlab-mpc-regen-quality" value="<?php echo esc_attr( (string) max( 1, min( 100, ( $quality ? $quality : 85 ) ) ) ); ?>" min="1" max="100" step="1" /></label></p>
			</div>
			<?php $this->progress_bar( 'regen' ); ?>
			<div class="lucidlab-mpc-btn-row lucidlab-mpc-scan-controls">
				<button type="button" class="button button-primary" id="lucidlab-mpc-regen-start" data-testid="regen-start"><?php esc_html_e( 'Generate thumbnails', 'lucidlab-mpc-cleanup' ); ?></button>
			</div>
		</div>
		<?php
	}

	/* ---------- Tab 10 (Options) ---------- */

	/**
	 * Render media index section.
	 *
	 * @param array $notices Notices.
	 */
	private function render_media_index_section( array $notices = array() ): void {
		unset( $notices );
		?>
		<?php $this->begin_results_card( ' data-mpc-options-section="media-index"' ); ?>
		<form method="post" action="<?php echo esc_url( $this->tab_url( 'options' ) ); ?>" class="lucidlab-mpc-index-form">
			<?php wp_nonce_field( 'lucidlab_mpc_op_save_options' ); ?>
			<section class="lucidlab-mpc-index-tools">
				<h2 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Media Index', 'lucidlab-mpc-cleanup' ); ?></h2>
				<p class="lucidlab-mpc-card__lede"><?php esc_html_e( 'The Media Index caches image IDs, titles, MIME types and filenames. It speeds up Rename Images searches and helps estimate regeneration work. Rebuild it after large media-library changes or when the readiness check says it is missing.', 'lucidlab-mpc-cleanup' ); ?></p>
				<button type="submit" name="lucidlab_mpc_op" value="rebuild_index" formnovalidate class="button button-primary"><?php esc_html_e( 'Rebuild media index', 'lucidlab-mpc-cleanup' ); ?></button>
			</section>
		</form>
		<?php
		$this->end_results_card();
		$this->render_core_check_section();
	}

	/**
	 * Options card: compare installed WordPress core files to the official package.
	 */
	private function render_core_check_section(): void {
		$saved   = class_exists( 'LucidLab_MPC_Core_Check', false ) ? LucidLab_MPC_Core_Check::saved_result() : array();
		$version = (string) get_bloginfo( 'version' );
		$this->begin_results_card( ' data-mpc-options-section="core-check"' );
		?>
		<div class="lucidlab-mpc-core-check" data-mpc-core-check data-mpc-initial="<?php echo esc_attr( wp_json_encode( ( $saved ? $saved : new stdClass() ) ) ); ?>">
			<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Check WordPress files', 'lucidlab-mpc-cleanup' ); ?></h3>
			<p class="lucidlab-mpc-hint lucidlab-mpc-card__lede"><?php echo esc_html( sprintf( /* translators: %s: WordPress version */ __( 'Compares this site’s WordPress %s core files with official checksums from wordpress.org. Reports extra files and changed contents under wp-admin, wp-includes and the WordPress root. This check is read-only; wp-content is not scanned.', 'lucidlab-mpc-cleanup' ), $version ) ); ?></p>
			<p class="lucidlab-mpc-btn-row">
				<button type="button" class="button button-primary" data-mpc-core-check-run><?php esc_html_e( 'Check WordPress files', 'lucidlab-mpc-cleanup' ); ?></button>
			</p>
			<div class="lucidlab-mpc-core-check-status" data-mpc-core-check-status role="status" aria-live="polite"></div>
			<div class="lucidlab-mpc-core-check-results" data-mpc-core-check-results hidden></div>
		</div>
		<dialog class="lucidlab-mpc-core-check-dialog" data-mpc-core-check-dialog>
			<div class="lucidlab-mpc-core-check-dialog-head">
				<strong data-mpc-core-check-dialog-title><?php esc_html_e( 'File compare', 'lucidlab-mpc-cleanup' ); ?></strong>
				<button type="button" class="button-link" data-mpc-core-check-dialog-close aria-label="<?php esc_attr_e( 'Close', 'lucidlab-mpc-cleanup' ); ?>">&times;</button>
			</div>
			<div class="lucidlab-mpc-core-check-dialog-body" data-mpc-core-check-dialog-body></div>
		</dialog>
		<?php
		$this->end_results_card();
	}

	/**
	 * Job tab label.
	 *
	 * @param string $tab Tab.
	 *
	 * @return string
	 */
	private function job_tab_label( string $tab ): string {
		$labels = array(
			'duplicates'   => __( 'Duplicate Products', 'lucidlab-mpc-cleanup' ),
			'orphans'      => __( 'Orphan Images', 'lucidlab-mpc-cleanup' ),
			'uploads'      => __( 'Uploads Scanner', 'lucidlab-mpc-cleanup' ),
			'sizes'        => __( 'Image Sizes', 'lucidlab-mpc-cleanup' ),
			'fs-sizes'     => __( 'Other Sizes on Disk', 'lucidlab-mpc-cleanup' ),
			'missing'      => __( 'Missing Images', 'lucidlab-mpc-cleanup' ),
			'product_copy' => __( 'Product AI', 'lucidlab-mpc-cleanup' ),
			'shared'       => __( 'Shared Images', 'lucidlab-mpc-cleanup' ),
			'dupcontent'   => __( 'Byte Identical Images', 'lucidlab-mpc-cleanup' ),
			'visual'       => __( 'Visually Identical Images', 'lucidlab-mpc-cleanup' ),
			'regenerate'   => __( 'Regenerate Thumbnails', 'lucidlab-mpc-cleanup' ),
			'webp'         => __( 'WebP', 'lucidlab-mpc-cleanup' ),
			'webpstats'    => __( 'WebP', 'lucidlab-mpc-cleanup' ),
			'webpscan'     => __( 'WebP', 'lucidlab-mpc-cleanup' ),
			'webpclean'    => __( 'WebP', 'lucidlab-mpc-cleanup' ),
			'rename'       => __( 'Rename Images', 'lucidlab-mpc-cleanup' ),
			'rename_ai'    => __( 'Rename Images AI', 'lucidlab-mpc-cleanup' ),
			'quarantine'   => __( 'Quarantine', 'lucidlab-mpc-cleanup' ),
			'run_all'      => __( 'Run All', 'lucidlab-mpc-cleanup' ),
			'crawl'        => __( 'Missing Images', 'lucidlab-mpc-cleanup' ),
			'dbcleaner'    => __( 'Database Cleaner', 'lucidlab-mpc-cleanup' ),
			'backup'       => __( 'Backup', 'lucidlab-mpc-cleanup' ),
			'form'         => __( 'Quarantine', 'lucidlab-mpc-cleanup' ),
			'convert'      => __( 'WebP / AVIF', 'lucidlab-mpc-cleanup' ),
		);
		$tab    = sanitize_key( $tab );
		if ( isset( $labels[ $tab ] ) ) {
			return $labels[ $tab ];
		}
		return $tab ? ucwords( str_replace( array( '-', '_' ), ' ', $tab ) ) : __( 'General', 'lucidlab-mpc-cleanup' );
	}

	/**
	 * Keep Completed on, the sidebar and the log on the same minute. A summary rewritten within 90 seconds of the job must not move the clock forward.
	 *
	 * @param int $job_updated     Job updated.
	 * @param int $summary_updated Summary updated.
	 *
	 * @return int
	 */
	private function aligned_scan_stamp( int $job_updated, int $summary_updated ): int {
		if ( $job_updated < 1 ) {
			return max( 0, $summary_updated );
		}
		if ( $summary_updated < 1 ) {
			return $job_updated;
		}
		if ( abs( $summary_updated - $job_updated ) <= 90 ) {
			return min( $job_updated, $summary_updated );
		}
		return max( $job_updated, $summary_updated );
	}

	/**
	 * Saved scan jobs can keep an older timestamp after a later result was stored.
	 *
	 * @param array $job Job.
	 *
	 * @return int
	 */
	private function job_display_updated( array $job ): int {
		$updated = (int) ( $job['updated'] ?? 0 );
		$tab     = sanitize_key( (string) ( $job['scan_tab'] ?? '' ) );
		$key     = (string) ( $job['key'] ?? '' );
		if ( '' === $tab || ! str_starts_with( $key, 'scan_' ) ) {
			return $updated;
		}
		$summary = get_option( 'lucidlab_mpc_summary_' . $tab, array() );
		if ( ! is_array( $summary ) ) {
			return $updated;
		}
		return $this->aligned_scan_stamp( $updated, (int) ( $summary['updated'] ?? 0 ) );
	}

	/**
	 * Job source key.
	 *
	 * @param array $job Job.
	 *
	 * @return string
	 */
	private function job_source_key( array $job ): string {
		if ( ! empty( $job['stages'] ) || str_starts_with( (string) ( $job['key'] ?? '' ), 'run_all' ) ) {
			return 'run_all';
		}
		$tab = sanitize_key( (string) ( $job['scan_tab'] ?? '' ) );
		if ( '' === $tab ) {
			$tab = sanitize_key( (string) ( $job['panel'] ?? '' ) );
		}
		if ( '' === $tab && ( 'form' === (string) ( $job['op'] ?? '' ) || in_array( (string) ( $job['sub_action'] ?? '' ), array( 'purge_quarantine', 'purge_source', 'restore_quarantine', 'restore_one' ), true ) ) ) {
			return 'quarantine';
		}
		if ( '' === $tab && preg_match( '/^(Deleting|Restoring) /', (string) ( $job['label'] ?? '' ) ) ) {
			return 'quarantine';
		}
		return $tab;
	}

	/**
	 * Job row title.
	 *
	 * @param array $job         Job.
	 * @param bool  $with_source With source.
	 *
	 * @return string
	 */
	private function job_row_title( array $job, bool $with_source = false ): string {
		$source = $this->job_tab_label( $this->job_source_key( $job ) );
		$label  = trim( (string) ( $job['label'] ?? '' ) );
		$plain  = strtolower( rtrim( $label, '.' ) );
		if ( '' === $label || in_array( $plain, array( 'completed', 'starting…', 'starting...', 'cancelled', 'processing…', 'processing...' ), true ) ) {
			return $source;
		}
		if ( $with_source && 'General' !== $source && false === stripos( $label, $source ) ) {
			return $source . ' — ' . $label;
		}
		return $label;
	}

	/**
	 * Recover a failure count from older history rows that stored it only in the label.
	 *
	 * @param array $job Job.
	 *
	 * @return int
	 */
	private function job_failure_count( array $job ): int {
		$failed = max( (int) ( $job['failed'] ?? 0 ), count( (array) ( $job['failed_ids'] ?? array() ) ) );
		if ( $failed < 1 && preg_match( '/(?:^|[—·])\s*([\d,.]+)\s+failed\b/i', (string) ( $job['label'] ?? '' ), $match ) ) {
			$failed = (int) preg_replace( '/\D/', '', $match[1] );
		}
		return $failed;
	}

	/**
	 * Render job retry button.
	 *
	 * @param array $job Job.
	 */
	private function render_job_retry_button( array $job ): void {
		$failed = (int) ( $job['failed'] ?? 0 );
		if ( ! $failed ) {
			$failed = count( (array) ( $job['failed_ids'] ?? array() ) );
		}
		$can = ! empty( $job['can_retry'] ) || ( $failed > 0 && in_array( (string) ( $job['status'] ?? '' ), array( 'done', 'failed' ), true ) && ! empty( $job['key'] ) );
		if ( ! $can ) {
			echo '—';
			return;
		}
		echo '<button type="button" class="button button-small" data-mpc-retry-job data-key="' . esc_attr( (string) ( $job['key'] ?? '' ) ) . '" data-special="' . esc_attr( (string) ( $job['special'] ?? '' ) ) . '">' . esc_html__( 'Retry failed', 'lucidlab-mpc-cleanup' ) . '</button>';
	}

	/**
	 * Render scan exclusions.
	 */
	private function render_scan_exclusions(): void {
		$s      = LucidLab_MPC_Settings::all();
		$chosen = class_exists( 'LucidLab_MPC_Exclusions', false ) ? LucidLab_MPC_Exclusions::parse_folders( (string) ( $s['exclude_folders'] ?? '' ) ) : array();
		$mimes  = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/*', 'application/pdf', 'video/*' );
		$this->begin_results_card( ' data-mpc-options-section="exclusions"' );
		?>
		<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Scan exclusions', 'lucidlab-mpc-cleanup' ); ?></h3>
		<p class="lucidlab-mpc-hint lucidlab-mpc-card__lede"><?php esc_html_e( 'These rules skip attachments and upload paths during orphan, shared, duplicate, missing, visual and uploads scans. They do not change quarantine, merge or regeneration of items you already selected.', 'lucidlab-mpc-cleanup' ); ?></p>
		<div class="lucidlab-mpc-exclude-folders" data-mpc-exclude-folders>
			<label for="lucidlab-mpc-exclude-folders" class="lucidlab-mpc-exclude-heading"><?php esc_html_e( 'Skip folders', 'lucidlab-mpc-cleanup' ); ?></label>
			<textarea id="lucidlab-mpc-exclude-folders" name="lucidlab_mpc_exclude_folders" rows="3" class="large-text code" hidden><?php echo esc_textarea( implode( "\n", $chosen ) ); ?></textarea>
			<div class="lucidlab-mpc-exclude-chips" data-mpc-exclude-chips></div>
			<p class="lucidlab-mpc-exclude-enter"><input type="text" class="regular-text" data-mpc-exclude-add placeholder="<?php esc_attr_e( 'Type a folder name and press Enter', 'lucidlab-mpc-cleanup' ); ?>"></p>
			<p class="lucidlab-mpc-btn-row"><button type="button" class="button" id="lucidlab-mpc-exclude-show-folders" data-mpc-exclude-show-folders><?php esc_html_e( 'Show folders', 'lucidlab-mpc-cleanup' ); ?></button> <span class="lucidlab-mpc-hint" data-mpc-exclude-folders-status></span></p>
			<p class="lucidlab-mpc-hint lucidlab-mpc-exclude-help"><?php esc_html_e( 'Click Show folders to load top-level folders from uploads, then click a folder to skip it, or type a name such as 2024/01. Year and month folders are not expanded here. Matching folder names anywhere under uploads are skipped. The quarantine and index folders are always skipped.', 'lucidlab-mpc-cleanup' ); ?></p>
			<p class="lucidlab-mpc-exclude-picker-label" data-mpc-exclude-picker-label hidden><?php esc_html_e( 'Folders in uploads', 'lucidlab-mpc-cleanup' ); ?></p>
			<div class="lucidlab-mpc-exclude-picker" data-mpc-exclude-picker hidden></div>
		</div>
		<div class="lucidlab-mpc-exclude-mimes-field">
			<label for="lucidlab-mpc-exclude-mimes" class="lucidlab-mpc-exclude-heading"><?php esc_html_e( 'Skip MIME types', 'lucidlab-mpc-cleanup' ); ?></label>
			<p class="lucidlab-mpc-hint lucidlab-mpc-exclude-help"><?php esc_html_e( 'A MIME type is the file format WordPress stores for each media item — for example image/jpeg for photos, image/png for PNG files, or application/pdf for PDFs. Skipping a type leaves those files out of scans. Use image/* to skip every image, or image/svg+xml to skip only SVGs.', 'lucidlab-mpc-cleanup' ); ?></p>
			<p><input id="lucidlab-mpc-exclude-mimes" type="text" name="lucidlab_mpc_exclude_mimes" class="regular-text" value="<?php echo esc_attr( (string) ( $s['exclude_mimes'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'image/svg+xml, image/gif', 'lucidlab-mpc-cleanup' ); ?>"></p>
			<span class="lucidlab-mpc-exclude-mimes">
				<?php foreach ( $mimes as $mime ) : ?>
					<button type="button" class="button button-small" data-mpc-exclude-mime="<?php echo esc_attr( $mime ); ?>"><?php echo esc_html( $mime ); ?></button>
				<?php endforeach; ?>
			</span>
		</div>
		<p class="lucidlab-mpc-exclude-newer">
			<label class="lucidlab-mpc-exclude-heading"><?php esc_html_e( 'Skip attachments newer than (days)', 'lucidlab-mpc-cleanup' ); ?></label>
			<span class="lucidlab-mpc-exclude-newer-row">
				<input type="number" name="lucidlab_mpc_exclude_newer_days" value="<?php echo (int) ( $s['exclude_newer_days'] ?? 0 ); ?>" min="0" max="3650">
				<span class="lucidlab-mpc-hint"><?php esc_html_e( '0 keeps every age. Use this to ignore recent uploads that are still being used.', 'lucidlab-mpc-cleanup' ); ?></span>
			</span>
		</p>
		<p class="lucidlab-mpc-exclude-featured">
			<label class="lucidlab-mpc-exclude-heading">
				<input type="checkbox" name="lucidlab_mpc_exclude_featured" value="1" <?php checked( ! empty( $s['exclude_featured'] ) ); ?>>
				<?php esc_html_e( 'Skip WooCommerce product featured images', 'lucidlab-mpc-cleanup' ); ?>
			</label>
			<span class="lucidlab-mpc-hint lucidlab-mpc-exclude-help"><?php esc_html_e( 'Keeps product and variation featured images out of scan results, including identical copies used as featured images.', 'lucidlab-mpc-cleanup' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Render free options.
	 *
	 * @param array $notices Notices.
	 */
	private function render_free_options( array $notices = array() ): void {
		unset( $notices );
		$s = LucidLab_MPC_Settings::all();
		$this->render_media_index_section();
		$this->render_cron_note();
		$this->begin_results_card( ' data-mpc-options-section="edition"' );
		?>
		<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Free edition', 'lucidlab-mpc-cleanup' ); ?></h3>
		<p class="lucidlab-mpc-card__lede"><?php esc_html_e( 'Manual orphan cleanup, exact duplicate detection, usage reports, individual renaming, thumbnail regeneration and restoration.', 'lucidlab-mpc-cleanup' ); ?></p>
		<p class="lucidlab-mpc-card__lede"><?php esc_html_e( 'The separate Pro add-on adds visual comparison, merging, uploads and size cleanup, bulk renaming, WebP / AVIF and scheduled workflows. Existing recovery data remains available without Pro.', 'lucidlab-mpc-cleanup' ); ?></p>
		<?php if ( class_exists( 'LucidLab_MPC_Pro', false ) && ! LucidLab_MPC_Edition::pro() ) : ?>
			<p class="notice notice-warning"><?php esc_html_e( 'Pro is installed, but the license is not active. Pro options stay off until Lucid Lab confirms the license.', 'lucidlab-mpc-cleanup' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=lucidlab-mpc-pro-license' ) ); ?>"><?php esc_html_e( 'Open License', 'lucidlab-mpc-cleanup' ); ?></a></p>
		<?php endif; ?>
		<?php
		$this->end_results_card();
		?>
		<form method="post" class="lucidlab-mpc-options-form">
			<?php wp_nonce_field( 'lucidlab_mpc_op_save_options' ); ?>
			<input type="hidden" name="lucidlab_mpc_op" value="save_options">
			<input type="hidden" name="lucidlab_mpc_options_scope" value="all">
			<?php $this->begin_results_card( ' data-mpc-options-section="performance"' ); ?>
			<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Performance', 'lucidlab-mpc-cleanup' ); ?></h3>
			<p class="lucidlab-mpc-hint lucidlab-mpc-card__lede"><?php esc_html_e( 'These numbers control how much work each batch does. Lower values use less memory; higher values finish faster. WebP and AVIF conversion keeps going for about two seconds per request. Visual comparison has no batch setting here.', 'lucidlab-mpc-cleanup' ); ?></p>
			<p class="lucidlab-mpc-performance-fields">
			<?php
			$performance_fields = array(
				'batch_db'        => array( __( 'Duplicate hash batch size', 'lucidlab-mpc-cleanup' ), __( 'Byte Identical Images.', 'lucidlab-mpc-cleanup' ), '50', '5000', false ),
				'batch_files'     => array( __( 'File batch size', 'lucidlab-mpc-cleanup' ), __( 'Maximum Uploads Scanner files per batch. Uses this saved value; a batch may finish earlier when the time or memory limit is reached.', 'lucidlab-mpc-cleanup' ), '1', '5000', false ),
				'scan_batch_db'   => array( __( 'Scan DB batch size', 'lucidlab-mpc-cleanup' ), __( 'Orphan Images and Uploads Scanner indexing.', 'lucidlab-mpc-cleanup' ), '1', '5000', false ),
				'missing_batch'   => array( __( 'Missing-image batch size', 'lucidlab-mpc-cleanup' ), __( 'Missing Images.', 'lucidlab-mpc-cleanup' ), '1', '100', false ),
				'regen_batch'     => array( __( 'Thumbnail regeneration batch size', 'lucidlab-mpc-cleanup' ), __( 'Regenerate Thumbnails.', 'lucidlab-mpc-cleanup' ), '1', '100', false ),
				'jpeg_quality'    => array( __( 'Default JPEG quality', 'lucidlab-mpc-cleanup' ), __( 'Regenerate Thumbnails.', 'lucidlab-mpc-cleanup' ), '1', '100', false ),
				'backup_keep'     => array( __( 'Backups to keep', 'lucidlab-mpc-cleanup' ), __( 'Newest copies kept for each type: database, uploads and full site.', 'lucidlab-mpc-cleanup' ), '1', '50', false ),
				'quarantine_days' => array( __( 'Quarantine retention (days)', 'lucidlab-mpc-cleanup' ), __( 'Eligible sets older than this are removed when automatic purge is enabled (Pro).', 'lucidlab-mpc-cleanup' ), '1', '365', true ),
			);
			foreach ( $performance_fields as $key => $field ) {
				$disabled = $field[4];
				$hint     = $disabled ? ( __( 'Pro only.', 'lucidlab-mpc-cleanup' ) . ' ' . $field[1] ) : $field[1];
				?>
				<label><?php echo esc_html( $field[0] ); ?>
					<input type="number" name="lucidlab_mpc_<?php echo esc_attr( $key ); ?>" value="<?php echo (int) ( $s[ $key ] ?? 0 ); ?>" min="<?php echo (int) $field[2]; ?>" max="<?php echo (int) $field[3]; ?>"<?php echo $disabled ? ' disabled="disabled"' : ''; ?>>
					<span class="lucidlab-mpc-hint"><?php echo esc_html( $hint ); ?></span>
				</label>
				<?php
			}
			?>
			</p>
			<?php $this->render_scan_exclusions(); ?>
			<?php $this->render_email_report_fields( $s ); ?>
			<?php $this->render_install_ping_field( $s ); ?>
			<?php $this->render_uninstall_field( $s ); ?>
			<?php $this->begin_results_card( ' data-mpc-options-section="save"' ); ?>
			<p><button type="submit" name="lucidlab_mpc_op" value="save_options" class="button button-primary"><?php esc_html_e( 'Save options', 'lucidlab-mpc-cleanup' ); ?></button></p>
		</form>
		<?php
		$this->render_reset_section();
	}

	/**
	 * What the install ping sends, in one sentence (notice and Options tab).
	 *
	 * @return string
	 */
	private static function ping_text(): string {
		return sprintf(
			/* translators: %s: address the install ping is sent to. */
			__( 'When it is on, this site sends its address (home URL), the plugin version, the plugin name and a random install ID to %s: right away, after each plugin update and once a week. Lucid Lab uses it only to count active installs. Nothing else is sent: no users, e-mail addresses, content, database data or settings. It is off by default and you can turn it off at any time on the Options tab.', 'lucidlab-mpc-cleanup' ),
			LucidLab_MPC_Site_Ping::ENDPOINT
		);
	}

	/**
	 * Dismissible opt-in notice for the install ping (this plugin's screen only, once per user, from 7
	 * days after install).
	 */
	private function render_ping_notice(): void {
		static $shown = false;
		if ( $shown || ! LucidLab_MPC_Site_Ping::show_notice() ) {
			return;
		}
		$shown = true;
		?>
		<div class="notice inline notice-info lucidlab-mpc-ping-notice" data-mpc-ping-notice data-testid="ping-notice">
			<div class="lucidlab-mpc-ping-notice-text">
				<p><strong><?php esc_html_e( 'Help Lucid Lab count active installs?', 'lucidlab-mpc-cleanup' ); ?></strong></p>
				<p><?php echo esc_html( self::ping_text() ); ?> <a href="<?php echo esc_url( 'https://lucidlab.eu/privacy/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Lucid Lab privacy policy', 'lucidlab-mpc-cleanup' ); ?></a></p>
				<p class="lucidlab-mpc-hint" data-mpc-ping-notice-message role="status" aria-live="polite"></p>
			</div>
			<div class="lucidlab-mpc-btn-row">
				<button type="button" class="button button-primary" data-mpc-ping-yes data-testid="ping-notice-yes"><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'Yes, count this site', 'lucidlab-mpc-cleanup' ); ?></button>
				<button type="button" class="button" data-mpc-ping-no data-testid="ping-notice-no"><?php esc_html_e( 'No thanks', 'lucidlab-mpc-cleanup' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Options card for the install ping: an opt-in checkbox (off by default, also with Pro).
	 *
	 * @param array $s Settings.
	 */
	private function render_install_ping_field( array $s ): void {
		$this->begin_results_card( ' data-mpc-options-section="install-ping"' );
		?>
		<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Install count (optional)', 'lucidlab-mpc-cleanup' ); ?></h3>
		<p class="lucidlab-mpc-performance-fields">
			<label class="lucidlab-mpc-performance-check"><input type="checkbox" name="lucidlab_mpc_install_ping" value="1" <?php checked( ! empty( $s['install_ping'] ) ); ?> data-testid="options-install-ping" /> <strong><?php esc_html_e( 'Tell Lucid Lab that this site uses the plugin', 'lucidlab-mpc-cleanup' ); ?></strong></label>
		</p>
		<p class="lucidlab-mpc-hint"><?php echo esc_html( self::ping_text() ); ?></p>
		<p class="lucidlab-mpc-hint"><a href="<?php echo esc_url( 'https://lucidlab.eu/privacy/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Lucid Lab privacy policy', 'lucidlab-mpc-cleanup' ); ?></a></p>
		<?php
		$last = LucidLab_MPC_Site_Ping::last();
		if ( $last && ! empty( $s['install_ping'] ) ) :
			$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last['time'] );
			?>
			<p class="lucidlab-mpc-hint" role="status">
				<?php
				echo esc_html(
					$last['ok']
						/* translators: %s: date and time of the last install ping. */
						? sprintf( __( 'Last sent: %s.', 'lucidlab-mpc-cleanup' ), $when )
						/* translators: 1: date and time of the last attempt, 2: HTTP status code (0 means no connection). */
						: sprintf( __( 'Last attempt failed: %1$s (HTTP %2$d). It is tried again later.', 'lucidlab-mpc-cleanup' ), $when, $last['code'] )
				);
				?>
			</p>
		<?php endif; ?>
		<?php
		$this->end_results_card();
	}

	/**
	 * Options card: opt-in removal of all plugin data when the plugin is deleted (off by default).
	 *
	 * @param array $s Settings.
	 */
	private function render_uninstall_field( array $s ): void {
		$this->begin_results_card( ' data-mpc-options-section="uninstall"' );
		?>
		<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'When the plugin is deleted', 'lucidlab-mpc-cleanup' ); ?></h3>
		<p class="lucidlab-mpc-performance-fields">
			<label class="lucidlab-mpc-performance-check"><input type="checkbox" name="lucidlab_mpc_delete_data_on_uninstall" value="1" <?php checked( ! empty( $s['delete_data_on_uninstall'] ) ); ?> data-testid="options-delete-data-on-uninstall" /> <strong><?php esc_html_e( 'Delete all plugin data when the plugin is deleted', 'lucidlab-mpc-cleanup' ); ?></strong></label>
		</p>
		<p class="lucidlab-mpc-hint"><?php esc_html_e( 'Off by default: deleting the plugin keeps settings, rename and merge undo history, quarantine and backups, so you can reinstall and still restore. When this is on, deleting the plugin from the Plugins screen permanently removes all of it, including every quarantined file and every backup made by this plugin. Files in quarantine can then no longer be restored.', 'lucidlab-mpc-cleanup' ); ?></p>
		<?php
		$this->end_results_card();
	}

	/**
	 * Email report enable, recipients (chip list), frequency and schedule.
	 *
	 * @param array $s S.
	 */
	private function render_email_report_fields( array $s ): void {
		$emails = LucidLab_MPC_Helpers::parse_emails( $s['report_recipient'] ?? '' );
		if ( ! $emails ) {
			$admin = sanitize_email( (string) get_option( 'admin_email' ) );
			if ( is_email( $admin ) ) {
				$emails = array( $admin );
			}
		}
		$this->begin_results_card( ' data-mpc-options-section="email-report"' );
		?>
		<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Email report', 'lucidlab-mpc-cleanup' ); ?></h3>
		<p>
			<label><input type="checkbox" name="lucidlab_mpc_report_enabled" value="1" <?php checked( ! empty( $s['report_enabled'] ) ); ?> data-testid="report-enable" /> <?php esc_html_e( 'Email a scheduled cleanup report', 'lucidlab-mpc-cleanup' ); ?></label>
			<select name="lucidlab_mpc_report_frequency">
				<option value="weekly" <?php selected( $s['report_frequency'] ?? 'weekly', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'lucidlab-mpc-cleanup' ); ?></option>
				<option value="monthly" <?php selected( $s['report_frequency'] ?? 'weekly', 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'lucidlab-mpc-cleanup' ); ?></option>
			</select>
		</p>
		<div class="lucidlab-mpc-report-emails" data-mpc-report-emails>
			<label for="lucidlab-mpc-report-email-add"><?php esc_html_e( 'Recipients', 'lucidlab-mpc-cleanup' ); ?></label>
			<input type="hidden" name="lucidlab_mpc_report_recipient" id="lucidlab-mpc-report-recipient" value="<?php echo esc_attr( implode( ', ', $emails ) ); ?>" data-testid="report-email" />
			<div class="lucidlab-mpc-exclude-chips" data-mpc-report-email-chips></div>
			<p><input type="email" id="lucidlab-mpc-report-email-add" class="regular-text" data-mpc-report-email-add placeholder="<?php esc_attr_e( 'Type an email and press Enter', 'lucidlab-mpc-cleanup' ); ?>" autocomplete="email" /></p>
			<p class="lucidlab-mpc-hint"><?php esc_html_e( 'Add one or more addresses. Press Enter or click outside the field to confirm each address. Use × on a chip to remove it.', 'lucidlab-mpc-cleanup' ); ?></p>
		</div>
		<?php
		$this->render_schedule_fields( $s );
		echo '<div class="lucidlab-mpc-report-schedule-status"><p class="lucidlab-mpc-hint">' . esc_html__( 'Scheduled reports contain the latest cached scan totals. WordPress cron must run for delivery.', 'lucidlab-mpc-cleanup' ) . '</p>';
		$report_hook = 'monthly' === ( $s['report_frequency'] ?? 'weekly' ) ? self::CRON_REPORT_MONTHLY : self::CRON_REPORT;
		$next_report = wp_next_scheduled( $report_hook );
		$delivery    = (array) get_option( 'lucidlab_mpc_report_delivery', array() );
		$format      = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'Next scheduled report:', 'lucidlab-mpc-cleanup' ) . ' ' . esc_html( $next_report ? wp_date( $format, (int) $next_report ) : __( 'Not scheduled', 'lucidlab-mpc-cleanup' ) ) . '</p>';
		echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'Last scheduled report sent:', 'lucidlab-mpc-cleanup' ) . ' ' . esc_html( ! empty( $delivery['last_scheduled_success'] ) ? wp_date( $format, (int) $delivery['last_scheduled_success'] ) : __( 'Never', 'lucidlab-mpc-cleanup' ) ) . '</p>';
		if ( ! empty( $delivery['last_scheduled_attempt'] ) && (int) ( $delivery['last_scheduled_attempt'] ?? 0 ) > (int) ( $delivery['last_scheduled_success'] ?? 0 ) ) {
			/* translators: %s is the date and time of the last failed scheduled report attempt. */
			echo '<p class="lucidlab-mpc-hint">' . esc_html( sprintf( __( 'Last scheduled attempt failed on %s.', 'lucidlab-mpc-cleanup' ), wp_date( $format, (int) $delivery['last_scheduled_attempt'] ) ) ) . '</p>';
		}
		echo '</div>';
		echo '<details class="lucidlab-mpc-report-preview"><summary>' . esc_html__( 'Preview report totals', 'lucidlab-mpc-cleanup' ) . '</summary>';
		echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'The email uses the latest cached scan totals. Open the scan tabs to refresh them before sending.', 'lucidlab-mpc-cleanup' ) . '</p>';
		echo '<table class="widefat lucidlab-mpc-table"><thead><tr><th>' . esc_html__( 'Scanner', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Items', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Reclaimable space', 'lucidlab-mpc-cleanup' ) . '</th></tr></thead><tbody>';
		foreach ( LucidLab_MPC_Stats::all() as $slice => $data ) {
			echo '<tr><td>' . esc_html( LucidLab_MPC_Stats::label( (string) $slice ) ) . '</td><td>' . esc_html( number_format_i18n( (int) ( $data['count'] ?? 0 ) ) ) . '</td><td>' . esc_html( LucidLab_MPC_Helpers::bytes( (int) ( $data['bytes'] ?? 0 ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></details>';
	}

	/**
	 * Day-of-week and hour for email reports and Pro automation.
	 *
	 * @param array $s S.
	 */
	private function render_schedule_fields( array $s ): void {
		$weekday = max( 1, min( 7, (int) ( $s['schedule_weekday'] ?? 1 ) ) );
		$hour    = max( 0, min( 23, (int) ( $s['schedule_hour'] ?? 3 ) ) );
		echo '<p class="lucidlab-mpc-schedule-fields">';
		echo '<label>' . esc_html__( 'Day', 'lucidlab-mpc-cleanup' ) . ' <select name="lucidlab_mpc_schedule_weekday">';
		foreach ( LucidLab_MPC_Helpers::weekday_choices() as $value => $label ) {
			echo '<option value="' . (int) $value . '" ' . selected( $weekday, (int) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Time', 'lucidlab-mpc-cleanup' ) . ' <select name="lucidlab_mpc_schedule_hour">';
		for ( $h = 0; $h < 24; $h++ ) {
			$label = sprintf( '%02d:00', $h );
			echo '<option value="' . esc_attr( $h ) . '" ' . selected( $hour, $h, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo ' <span class="lucidlab-mpc-hint lucidlab-mpc-schedule-tz-hint">' . esc_html__( 'Site timezone. Used for email reports and Pro automation jobs.', 'lucidlab-mpc-cleanup' ) . '</span>';
		echo '</p>';
	}

	/**
	 * Render diagnostics.
	 */
	private function render_diagnostics(): void {
		$uploads    = LucidLab_MPC_Helpers::uploads_basedir();
		$quarantine = ( new LucidLab_MPC_Quarantine() )->root();
		$checks     = array(
			__( 'Edition', 'lucidlab-mpc-cleanup' )        => LucidLab_MPC_Edition::pro() ? __( 'Pro', 'lucidlab-mpc-cleanup' ) : __( 'Free', 'lucidlab-mpc-cleanup' ),
			'PHP'                                          => PHP_VERSION,
			__( 'PHP GD image support', 'lucidlab-mpc-cleanup' ) => function_exists( 'imagecreatefromstring' ) ? __( 'Available', 'lucidlab-mpc-cleanup' ) : __( 'Missing', 'lucidlab-mpc-cleanup' ),
			__( 'Uploads folder', 'lucidlab-mpc-cleanup' ) => is_dir( $uploads ) ? ( is_writable( $uploads ) ? __( 'Writable', 'lucidlab-mpc-cleanup' ) : __( 'Not writable', 'lucidlab-mpc-cleanup' ) ) : __( 'Missing', 'lucidlab-mpc-cleanup' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			__( 'Quarantine folder', 'lucidlab-mpc-cleanup' ) => is_dir( $quarantine ) ? ( is_writable( $quarantine ) ? __( 'Writable', 'lucidlab-mpc-cleanup' ) : __( 'Not writable', 'lucidlab-mpc-cleanup' ) ) : ( is_writable( dirname( $quarantine ) ) ? __( 'Will be created when needed', 'lucidlab-mpc-cleanup' ) : __( 'Cannot create', 'lucidlab-mpc-cleanup' ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			'WP-Cron'                                      => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'Disabled in wp-config.php', 'lucidlab-mpc-cleanup' ) : __( 'Enabled', 'lucidlab-mpc-cleanup' ),
			__( 'PHP memory limit', 'lucidlab-mpc-cleanup' ) => LucidLab_MPC_Helpers::either( ini_get( 'memory_limit' ), __( 'Unknown', 'lucidlab-mpc-cleanup' ) ),
			__( 'PHP max execution time', 'lucidlab-mpc-cleanup' ) => (string) ( LucidLab_MPC_Helpers::either( ini_get( 'max_execution_time' ), '0' ) ) . ' ' . __( 'seconds', 'lucidlab-mpc-cleanup' ),
		);
		if ( LucidLab_MPC_Edition::pro() ) {
			$ai       = LucidLab_MPC_Settings::all();
			$provider = (string) ( $ai['ai_provider'] ?? 'none' );
			$checks[ __( 'AI provider', 'lucidlab-mpc-cleanup' ) ] = 'none' === $provider
				? __( 'Not configured', 'lucidlab-mpc-cleanup' )
				: ( LucidLab_MPC_AI::configured() ? ucfirst( $provider ) . ' ' . __( 'configured', 'lucidlab-mpc-cleanup' ) : ucfirst( $provider ) . ( 'local' === $provider ? ' ' . __( 'endpoint missing', 'lucidlab-mpc-cleanup' ) : ' ' . __( 'key missing', 'lucidlab-mpc-cleanup' ) ) );
		}
		$idx      = LucidLab_MPC_Index::get();
		$building = $idx ? null : LucidLab_MPC_Index::build_progress();
		$checks[ __( 'Media index', 'lucidlab-mpc-cleanup' ) ] = $idx
			/* translators: 1: media-index build date and time, 2: indexed image count. */
			? sprintf( __( 'Built %1$s (%2$d images)', 'lucidlab-mpc-cleanup' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $idx['built'] ?? 0 ) ), (int) ( $idx['count'] ?? 0 ) )
			: ( $building
				/* translators: 1: images indexed so far, 2: images in the library. */
				? sprintf( __( 'Building: %1$s of %2$s images', 'lucidlab-mpc-cleanup' ), number_format_i18n( $building['indexed'] ), number_format_i18n( $building['total'] ) )
				: __( 'Not built', 'lucidlab-mpc-cleanup' ) );
		// Same list and definition as the Quarantine tab: sets that still contain restorable items, from every
		// source, protected sets included. Folders without a readable manifest are reported by Quarantine Health.
		$checks[ __( 'Restorable quarantine sets', 'lucidlab-mpc-cleanup' ) ] = $this->quarantine_count_summary( ( new LucidLab_MPC_Quarantine() )->list_sets() );
		$cron_hooks = array(
			self::CRON_PURGE => __( 'Automatic quarantine purge', 'lucidlab-mpc-cleanup' ),
		);
		$settings   = LucidLab_MPC_Settings::all();
		if ( ! empty( $settings['report_enabled'] ) ) {
			$monthly = 'monthly' === ( $settings['report_frequency'] ?? 'weekly' );
			$cron_hooks[ $monthly ? self::CRON_REPORT_MONTHLY : self::CRON_REPORT ] = $monthly
				? __( 'Monthly report', 'lucidlab-mpc-cleanup' )
				: __( 'Weekly report', 'lucidlab-mpc-cleanup' );
		} else {
			$checks[ __( 'Email report', 'lucidlab-mpc-cleanup' ) ] = __( 'Not scheduled', 'lucidlab-mpc-cleanup' );
		}
		$automation_enabled = false;
		foreach ( array( 'weekly', 'monthly' ) as $frequency ) {
			if ( empty( $settings[ 'autorun_' . $frequency ] ) ) {
				continue;
			}
			$automation_enabled                                    = true;
			$cron_hooks[ 'lucidlab_mpc_automation_' . $frequency ] = 'weekly' === $frequency
				? __( 'Weekly automation', 'lucidlab-mpc-cleanup' )
				: __( 'Monthly automation', 'lucidlab-mpc-cleanup' );
		}
		if ( ! $automation_enabled ) {
			$checks[ __( 'Automation', 'lucidlab-mpc-cleanup' ) ] = __( 'Not scheduled', 'lucidlab-mpc-cleanup' );
		}
		foreach ( $cron_hooks as $hook => $label ) {
			$event            = wp_next_scheduled( $hook );
			$checks[ $label ] = $event ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $event ) : __( 'Not scheduled', 'lucidlab-mpc-cleanup' );
			if ( self::CRON_PURGE === $hook && ! LucidLab_MPC_Edition::pro() && ! $event ) {
				$checks[ $label ] = __( 'Paused while Pro is inactive', 'lucidlab-mpc-cleanup' );
			}
		}
		$risk_values = array( __( 'Missing', 'lucidlab-mpc-cleanup' ), __( 'Not writable', 'lucidlab-mpc-cleanup' ), __( 'Cannot create', 'lucidlab-mpc-cleanup' ), __( 'Not scheduled', 'lucidlab-mpc-cleanup' ), __( 'Not configured', 'lucidlab-mpc-cleanup' ), __( 'key missing', 'lucidlab-mpc-cleanup' ) );
		echo '<h3 class="lucidlab-mpc-card__title">' . esc_html__( 'Diagnostics', 'lucidlab-mpc-cleanup' ) . '</h3><table class="widefat lucidlab-mpc-table"><tbody>';
		foreach ( $checks as $label => $value ) {
			$ok = ! in_array( $value, $risk_values, true ) && false === strpos( strtolower( (string) $value ), 'key missing' );
			echo '<tr><th>' . esc_html( $label ) . '</th><td><span class="lucidlab-mpc-badge ' . ( $ok ? 'lucidlab-mpc-badge-safe' : 'lucidlab-mpc-badge-risk' ) . '">' . esc_html( $value ) . '</span></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render cron health card.
	 */
	private function render_cron_health_card(): void {
		$cron       = LucidLab_MPC_Helpers::cron_health();
		$cron_class = 'ok' === $cron['status'] ? 'lucidlab-mpc-badge-safe' : ( 'disabled' === $cron['status'] ? 'lucidlab-mpc-badge-risk' : 'lucidlab-mpc-badge-warn' );
		echo '<h3 class="lucidlab-mpc-card__title">' . esc_html__( 'WP-Cron', 'lucidlab-mpc-cleanup' ) . '</h3>';
		echo '<p><span class="lucidlab-mpc-badge ' . esc_attr( $cron_class ) . '">' . esc_html( ucfirst( $cron['status'] ) ) . '</span> ' . esc_html( $cron['label'] ) . '</p>';
		/* translators: %s is the date and time of the last wp-cron.php run. */
		echo '<p class="lucidlab-mpc-hint">' . esc_html( sprintf( __( 'Last wp-cron.php run: %s', 'lucidlab-mpc-cleanup' ), $cron['last_label'] ) ) . '</p>';
		echo ( '<p class="lucidlab-mpc-hint">' . esc_html__( 'A server cron job that calls wp-cron.php every few minutes is the right setup when DISABLE_WP_CRON is on, or when the site has little traffic. Fix folder permissions before starting large background jobs.', 'lucidlab-mpc-cleanup' ) . '</p>' );
	}

	/**
	 * Render setup checklist.
	 */
	private function render_setup_checklist(): void {
		$uploads    = LucidLab_MPC_Helpers::uploads_basedir();
		$quarantine = ( new LucidLab_MPC_Quarantine() )->root();
		$checks     = array(
			__( 'Uploads writable', 'lucidlab-mpc-cleanup' ) => is_dir( $uploads ) && is_writable( $uploads ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			__( 'Quarantine ready', 'lucidlab-mpc-cleanup' ) => ( is_dir( $quarantine ) && is_writable( $quarantine ) ) || ( ! is_dir( $quarantine ) && is_writable( dirname( $quarantine ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			__( 'WP-Cron enabled', 'lucidlab-mpc-cleanup' ) => ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON,
		);
		echo ( '<div class="notice notice-info inline"><h3>' . esc_html__( 'Pro setup checklist', 'lucidlab-mpc-cleanup' ) . '</h3><p><strong>' . esc_html__( 'Readiness:', 'lucidlab-mpc-cleanup' ) . '</strong> ' ) . esc_html( implode( ' · ', array_map( static fn( $label, $ready ) => ( $ready ? sprintf( /* translators: %s: checklist item. */ __( 'OK: %s', 'lucidlab-mpc-cleanup' ), $label ) : sprintf( /* translators: %s: checklist item. */ __( 'Check: %s', 'lucidlab-mpc-cleanup' ), $label ) ), array_keys( $checks ), array_values( $checks ) ) ) ) . ( '</p><ol><li>' . esc_html__( 'Make a current backup before bulk changes.', 'lucidlab-mpc-cleanup' ) . '</li><li>' . esc_html__( 'Confirm Uploads and Quarantine folders are writable in Diagnostics.', 'lucidlab-mpc-cleanup' ) . '</li><li>' . esc_html__( 'Regenerate missing or changed thumbnails before WebP/AVIF conversion.', 'lucidlab-mpc-cleanup' ) . '</li><li>' . esc_html__( 'Run one scan manually and review its findings.', 'lucidlab-mpc-cleanup' ) . '</li><li>' . esc_html__( 'Enable a real cron runner or confirm site visits trigger WP-Cron before scheduling background jobs.', 'lucidlab-mpc-cleanup' ) . '</li></ol><p>' . esc_html__( 'All cleanup actions remain reviewable, and quarantined files can be restored until permanent purge.', 'lucidlab-mpc-cleanup' ) . '</p></div>' );
	}

	/**
	 * Render woocommerce summary.
	 */
	private function render_woocommerce_summary(): void {
		global $wpdb;
		$product                = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status NOT IN ('trash','auto-draft')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$featured               = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_thumbnail_id' AND CAST(m.meta_value AS UNSIGNED)>0 WHERE p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$gallery                = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_product_image_gallery' AND m.meta_value<>'' WHERE p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$variations             = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product_variation' AND post_status NOT IN ('trash','auto-draft')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$variation_gallery      = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_product_image_gallery' AND m.meta_value<>'' WHERE p.post_type='product_variation' AND p.post_status NOT IN ('trash','auto-draft')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$categories             = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key='thumbnail_id' AND CAST(meta_value AS UNSIGNED)>0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			$missing_products   = $wpdb->get_results( "SELECT p.ID,p.post_title FROM {$wpdb->posts} p WHERE p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft') AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_thumbnail_id' AND CAST(m.meta_value AS UNSIGNED)>0) ORDER BY p.post_title ASC LIMIT 50" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			$gallery_variations = $wpdb->get_results( "SELECT v.ID,v.post_parent,v.post_title FROM {$wpdb->posts} v INNER JOIN {$wpdb->postmeta} m ON m.post_id=v.ID AND m.meta_key='_product_image_gallery' AND m.meta_value<>'' WHERE v.post_type='product_variation' AND v.post_status NOT IN ('trash','auto-draft') ORDER BY v.ID DESC LIMIT 50" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		echo '<h3>' . esc_html__( 'WooCommerce image usage', 'lucidlab-mpc-cleanup' ) . '</h3><p class="lucidlab-mpc-hint">' . esc_html__( 'Read-only summary of how product media is referenced. It does not identify unused images by itself.', 'lucidlab-mpc-cleanup' ) . '</p><table class="widefat lucidlab-mpc-table"><tbody>';
		foreach ( array(
			__( 'Products', 'lucidlab-mpc-cleanup' ) => $product,
			__( 'Products with featured image', 'lucidlab-mpc-cleanup' ) => $featured,
			__( 'Products missing featured image', 'lucidlab-mpc-cleanup' ) => max( 0, $product - $featured ),
			__( 'Products with gallery', 'lucidlab-mpc-cleanup' ) => $gallery,
			__( 'Product variations', 'lucidlab-mpc-cleanup' ) => $variations,
			__( 'Variations with gallery', 'lucidlab-mpc-cleanup' ) => $variation_gallery,
			__( 'Categories with thumbnail', 'lucidlab-mpc-cleanup' ) => $categories,
		) as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( number_format_i18n( $value ) ) . '</td></tr>';
		}
			echo '</tbody></table>';
		if ( $missing_products ) {
			echo '<details class="lucidlab-mpc-review-preview"><summary>' . esc_html__( 'Preview products missing featured images', 'lucidlab-mpc-cleanup' ) . '</summary><ul class="lucidlab-mpc-reference-list">';
			foreach ( $missing_products as $missing_product ) {
				$edit = get_edit_post_link( (int) $missing_product->ID, 'raw' );
				echo '<li>#' . (int) $missing_product->ID . ' ';
				if ( $edit ) {
					echo '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener">' . esc_html( ( $missing_product->post_title ? $missing_product->post_title : __( '(untitled product)', 'lucidlab-mpc-cleanup' ) ) ) . '</a>';
				} else {
					echo esc_html( ( $missing_product->post_title ? $missing_product->post_title : __( '(untitled product)', 'lucidlab-mpc-cleanup' ) ) );
				}
				echo '</li>';
			}
			echo '</ul>';
			if ( $product - $featured > count( $missing_products ) ) {
				echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'Showing the first 50 products. Use the Products screen for the complete list.', 'lucidlab-mpc-cleanup' ) . '</p>';
			}
			echo '</details>';
		}
		if ( $gallery_variations ) {
			echo '<details class="lucidlab-mpc-review-preview"><summary>' . esc_html__( 'Preview variations with gallery references', 'lucidlab-mpc-cleanup' ) . '</summary><ul class="lucidlab-mpc-reference-list">';
			foreach ( $gallery_variations as $variation ) {
				$parent_id    = (int) $variation->post_parent;
				$edit         = $parent_id ? get_edit_post_link( $parent_id, 'raw' ) : '';
				$parent_title = $parent_id ? get_the_title( $parent_id ) : '';
				echo '<li>' . esc_html__( 'Variation #', 'lucidlab-mpc-cleanup' ) . (int) $variation->ID . ' — ' . esc_html( ( $parent_title ? $parent_title : ( ( $variation->post_title ? $variation->post_title : __( '(untitled product)', 'lucidlab-mpc-cleanup' ) ) ) ) );
				if ( $edit ) {
					echo ' <a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener">' . esc_html__( 'Edit product', 'lucidlab-mpc-cleanup' ) . '</a>';
				}
				echo '</li>';
			}
			echo '</ul>';
			if ( $variation_gallery > count( $gallery_variations ) ) {
				echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'Showing the latest 50 variations. Use the Products screen for the complete list.', 'lucidlab-mpc-cleanup' ) . '</p>';
			}
			echo '</details>';
		}
	}

	/**
	 * Render logs diagnostics.
	 */
	private function render_logs_diagnostics(): void {
		LucidLab_MPC_Stats::sync_from_summaries();
		$s = LucidLab_MPC_Settings::all();
		$this->begin_results_card( ' data-mpc-logs-section="diagnostics"' );
		echo '<div class="lucidlab-mpc-log-diagnostics">';
		$this->render_diagnostics();
		echo '</div>';
		$this->begin_results_card( ' data-mpc-logs-section="scan-performance"' );
		LucidLab_MPC_Scan_Profile::render();
		$this->begin_results_card( ' data-mpc-logs-section="wp-cron"' );
		$this->render_cron_health_card();
		try {
			$task_jobs = ( new LucidLab_MPC_Task_Job( $this ) )->command( 'list', array() );
		} catch ( Throwable $e ) {
			$task_jobs = array( 'jobs' => array() ); }
		$task_jobs = (array) ( $task_jobs['jobs'] ?? array() );
		foreach ( $task_jobs as $task_index => $task_job ) {
			$task_jobs[ $task_index ]['updated'] = $this->job_display_updated( $task_job );
		}
		usort( $task_jobs, static fn( $a, $b ) => (int) ( $b['updated'] ?? 0 ) <=> (int) ( $a['updated'] ?? 0 ) );
		$saved_task_jobs = $task_jobs;
		$task_jobs       = array_slice( $task_jobs, 0, 10 );
		if ( $task_jobs ) {
			$this->begin_results_card( ' data-mpc-logs-section="recent-jobs"' );
			echo '<h3 class="lucidlab-mpc-card__title">' . esc_html__( 'Recent background jobs', 'lucidlab-mpc-cleanup' ) . '</h3>';
			echo '<table class="widefat lucidlab-mpc-table"><thead><tr><th>' . esc_html__( 'Source tab', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Job', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Status', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Updated', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Progress', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Error', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Actions', 'lucidlab-mpc-cleanup' ) . '</th></tr></thead><tbody>';
			foreach ( $task_jobs as $task_job ) {
				$task_tab         = $this->job_source_key( $task_job );
				$status_label     = ucfirst( strtolower( (string) ( $task_job['status'] ?? 'unknown' ) ) );
				$partial_failures = 'done' === ( $task_job['status'] ?? '' ) ? $this->job_failure_count( $task_job ) : 0;
				if ( $partial_failures > 0 ) {
					/* translators: %s is the localized count of failed job items. */
					$status_label .= ' · ' . sprintf( __( '%s failed', 'lucidlab-mpc-cleanup' ), number_format_i18n( $partial_failures ) );
				}
				echo '<tr><td>' . esc_html( $this->job_tab_label( $task_tab ) ) . '</td><td>' . esc_html( $this->job_row_title( $task_job ) ) . '</td><td><span class="lucidlab-mpc-badge ' . ( 'failed' === ( $task_job['status'] ?? '' ) ? 'lucidlab-mpc-badge-risk' : ( 'done' === ( $task_job['status'] ?? '' ) && ! $partial_failures ? 'lucidlab-mpc-badge-safe' : 'lucidlab-mpc-badge-warn' ) ) . '">' . esc_html( $status_label ) . '</span>';
				if ( ! empty( $task_job['retry_at'] ) ) {
					/* translators: %s is the scheduled retry time for this job. */
					echo ' <small>' . esc_html( sprintf( __( 'Retry: %s', 'lucidlab-mpc-cleanup' ), wp_date( get_option( 'time_format' ), (int) $task_job['retry_at'] ) ) ) . '</small>';
				}
				/* translators: %s is the localized count of failed job items. */
				echo '</td><td>' . ( ! empty( $task_job['updated'] ) ? esc_html( LucidLab_MPC_Helpers::job_datetime( (int) $task_job['updated'] ) ) : '—' ) . '</td><td>' . esc_html( number_format_i18n( (int) ( $task_job['progress'] ?? 0 ) ) . '%' ) . '</td><td>' . ( ! empty( $task_job['last_error'] ) ? esc_html( (string) $task_job['last_error'] ) : ( $partial_failures ? esc_html( sprintf( __( '%s failed', 'lucidlab-mpc-cleanup' ), number_format_i18n( $partial_failures ) ) ) : '—' ) ) . '</td><td>';
				$this->render_job_retry_button( $task_job );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$all_job_history = (array) get_option( 'lucidlab_mpc_job_history', array() );
		$history_keys    = array_fill_keys( array_map( static fn( $entry ) => is_array( $entry ) ? (string) ( $entry['key'] ?? '' ) : '', $all_job_history ), true );
		// Recover stage rows from older saved Run All jobs that predate stage history.
		// This only affects the view; neither the queue nor stored history is changed.
		foreach ( $saved_task_jobs as $saved_job ) {
			if ( ! str_starts_with( (string) ( $saved_job['key'] ?? '' ), 'run_all' ) ) {
				continue;
			}
			foreach ( (array) ( $saved_job['stages'] ?? array() ) as $stage_index => $stage ) {
				$stage_key = (string) $saved_job['key'] . ':' . $stage_index;
				if ( 'done' !== ( $stage['status'] ?? '' ) || empty( $stage['completed_at'] ) || isset( $history_keys[ $stage_key ] ) ) {
					continue;
				}
				$source                     = sanitize_key( (string) ( $stage['panel'] ?? '' ) );
				$all_job_history[]          = array(
					'key'      => $stage_key,
					'label'    => (string) ( $stage['label'] ?? $this->job_tab_label( $source ) ),
					'status'   => 'done',
					'updated'  => (int) $stage['completed_at'],
					'panel'    => $source,
					'scan_tab' => $source,
					'special'  => 'run_all_stage',
				);
				$history_keys[ $stage_key ] = true;
			}
		}
		usort( $all_job_history, static fn( $a, $b ) => (int) ( $b['updated'] ?? 0 ) <=> (int) ( $a['updated'] ?? 0 ) );
		$history_page = max( 1, min( (int) ceil( count( $all_job_history ) / 10 ), absint( $_GET['lucidlab_mpc_history_page'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$job_history  = array_slice( $all_job_history, ( $history_page - 1 ) * 10, 10 );
		if ( $job_history ) {
			$this->begin_results_card( ' id="lucidlab-mpc-job-history" data-mpc-logs-section="job-history"' );
			echo '<h3 class="lucidlab-mpc-card__title">' . esc_html__( 'Job history', 'lucidlab-mpc-cleanup' ) . '</h3>';
			echo '<table class="widefat lucidlab-mpc-table"><thead><tr><th>' . esc_html__( 'Job', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Status', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'When', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Error', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Actions', 'lucidlab-mpc-cleanup' ) . '</th></tr></thead><tbody>';
			foreach ( $job_history as $entry ) {
				$hist_status      = ucfirst( strtolower( (string) ( $entry['status'] ?? '' ) ) );
				$partial_failures = 'done' === ( $entry['status'] ?? '' ) ? $this->job_failure_count( is_array( $entry ) ? $entry : array() ) : 0;
				if ( $partial_failures > 0 ) {
					/* translators: %s is the localized count of failed historical job items. */
					$hist_status .= ' · ' . sprintf( __( '%s failed', 'lucidlab-mpc-cleanup' ), number_format_i18n( $partial_failures ) );
				}
				/* translators: %s is the localized count of failed historical job items. */
				echo '<tr><td>' . esc_html( $this->job_row_title( is_array( $entry ) ? $entry : array(), true ) ) . '</td><td><span class="lucidlab-mpc-badge ' . ( 'failed' === ( $entry['status'] ?? '' ) ? 'lucidlab-mpc-badge-risk' : ( 'done' === ( $entry['status'] ?? '' ) && ! $partial_failures ? 'lucidlab-mpc-badge-safe' : 'lucidlab-mpc-badge-warn' ) ) . '">' . esc_html( $hist_status ) . '</span></td><td>' . ( ! empty( $entry['updated'] ) ? esc_html( LucidLab_MPC_Helpers::job_datetime( (int) $entry['updated'] ) ) : '—' ) . '</td><td>' . ( ! empty( $entry['error'] ) ? esc_html( (string) $entry['error'] ) : ( $partial_failures ? esc_html( sprintf( __( '%s failed', 'lucidlab-mpc-cleanup' ), number_format_i18n( $partial_failures ) ) ) : '—' ) ) . '</td><td>';
				$this->render_job_retry_button( is_array( $entry ) ? $entry : array() );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			if ( $history_page > 1 || count( $all_job_history ) > $history_page * 10 ) {
				echo '<nav class="lucidlab-mpc-pagination" aria-label="' . esc_attr__( 'Job history', 'lucidlab-mpc-cleanup' ) . '">';
				/* translators: 1: current Job history page, 2: total Job history pages. */
				echo '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'lucidlab-mpc-cleanup' ), $history_page, (int) ceil( count( $all_job_history ) / 10 ) ) ) . '</span> ';
				if ( $history_page > 1 ) {
					echo '<a class="button" href="' . esc_url( add_query_arg( 'lucidlab_mpc_history_page', $history_page - 1, $this->tab_url( 'logs' ) ) . '#lucidlab-mpc-job-history' ) . '">' . esc_html__( 'Previous', 'lucidlab-mpc-cleanup' ) . '</a> ';
				}
				if ( count( $all_job_history ) > $history_page * 10 ) {
					echo '<a class="button" href="' . esc_url( add_query_arg( 'lucidlab_mpc_history_page', $history_page + 1, $this->tab_url( 'logs' ) ) . '#lucidlab-mpc-job-history' ) . '">' . esc_html__( 'Next', 'lucidlab-mpc-cleanup' ) . '</a>';
				}
				echo '</nav>';
			}
		}
		if ( LucidLab_MPC_Edition::pro() ) {
			$history = get_option( 'lucidlab_mpc_stats_history', array() );
			$slices  = LucidLab_MPC_Stats::all();
			$this->begin_results_card( ' data-mpc-logs-section="woocommerce"' );
			$this->render_woocommerce_summary();
			$this->begin_results_card( ' data-mpc-logs-section="reclaimable"' );
			echo '<h3 class="lucidlab-mpc-card__title">' . esc_html__( 'Current reclaimable space by scanner', 'lucidlab-mpc-cleanup' ) . '</h3>';
			echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'Values are cached from the last completed scan for each scanner; they are not recalculated when this page opens.', 'lucidlab-mpc-cleanup' ) . '</p>';
			if ( empty( $slices ) ) {
				echo '<p class="lucidlab-mpc-empty">' . esc_html__( 'Run a supported scan to populate per-scanner reclaimable totals.', 'lucidlab-mpc-cleanup' ) . '</p>';
			} else {
				echo '<table class="widefat lucidlab-mpc-table"><thead><tr><th>' . esc_html__( 'Scanner', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Reclaimable space', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Items', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Updated', 'lucidlab-mpc-cleanup' ) . '</th></tr></thead><tbody>';
				foreach ( $slices as $slice => $data ) {
					echo '<tr><th>' . esc_html( LucidLab_MPC_Stats::label( (string) $slice ) ) . '</th><td>' . esc_html( LucidLab_MPC_Helpers::bytes( (int) ( $data['bytes'] ?? 0 ) ) ) . '</td><td>' . esc_html( number_format_i18n( (int) ( $data['count'] ?? 0 ) ) ) . '</td><td>' . ( ! empty( $data['updated'] ) ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $data['updated'] ) ) : '—' ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			$this->begin_results_card( ' data-mpc-logs-section="storage-history"' );
			echo '<h3 class="lucidlab-mpc-card__title">' . esc_html__( 'Storage history', 'lucidlab-mpc-cleanup' ) . '</h3>';
			if ( ! empty( $history ) || ! empty( $slices ) ) {
				echo '<p><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'lucidlab_mpc_export_stats' ), admin_url( 'admin-post.php' ) ), 'lucidlab_mpc_export_stats' ) ) . '">' . esc_html__( 'Export storage report CSV', 'lucidlab-mpc-cleanup' ) . '</a> <a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'lucidlab_mpc_export_pdf' ), admin_url( 'admin-post.php' ) ), 'lucidlab_mpc_export_pdf' ) ) . '">' . esc_html__( 'Print / save PDF report', 'lucidlab-mpc-cleanup' ) . '</a></p>';
			}
			if ( ! empty( $history ) ) {
				$chart_history = array_reverse( array_slice( $history, -30, 30, true ), true );
				$chart_max     = 0;
				foreach ( $chart_history as $chart_snapshot ) {
					$chart_max = max( $chart_max, (int) ( $chart_snapshot['bytes'] ?? 0 ) );
				}
				echo '<div class="lucidlab-mpc-history-chart" role="img" aria-label="' . esc_attr__( 'Reclaimable storage by day', 'lucidlab-mpc-cleanup' ) . '">';
				foreach ( $chart_history as $chart_day => $chart_snapshot ) {
					$chart_bytes  = (int) ( $chart_snapshot['bytes'] ?? 0 );
					$chart_height = $chart_max > 0 ? max( 4, (int) round( $chart_bytes / $chart_max * 100 ) ) : 4;
					echo '<div class="lucidlab-mpc-history-bar-wrap" title="' . esc_attr( wp_date( get_option( 'date_format' ), strtotime( $chart_day . ' 00:00:00' ) ) . ': ' . LucidLab_MPC_Helpers::bytes( $chart_bytes ) ) . '"><span class="lucidlab-mpc-history-bar" style="height:' . (int) $chart_height . '%"></span><small>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $chart_day . ' 00:00:00' ) ) ) . '</small></div>';
				}
				echo '</div>';
				echo '<table class="widefat lucidlab-mpc-table"><thead><tr><th>' . esc_html__( 'Date', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Reclaimable space', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Items', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Scanner breakdown', 'lucidlab-mpc-cleanup' ) . '</th></tr></thead><tbody>';
				foreach ( array_reverse( array_slice( $history, -30, 30, true ), true ) as $day => $snapshot ) {
					$breakdown = array();
					foreach ( (array) ( $snapshot['slices'] ?? array() ) as $slice => $slice_data ) {
						if ( ! empty( $slice_data['bytes'] ) ) {
							$breakdown[] = LucidLab_MPC_Stats::label( (string) $slice ) . ': ' . LucidLab_MPC_Helpers::bytes( (int) $slice_data['bytes'] );
						}
					}
					echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $day . ' 00:00:00' ) ) ) . '</td><td>' . esc_html( LucidLab_MPC_Helpers::bytes( (int) ( $snapshot['bytes'] ?? 0 ) ) ) . '</td><td>' . esc_html( number_format_i18n( (int) ( $snapshot['count'] ?? 0 ) ) ) . '</td><td>' . esc_html( $breakdown ? implode( ' · ', $breakdown ) : '—' ) . '</td></tr>';
				}
				echo '</tbody></table><p class="lucidlab-mpc-hint">' . esc_html__( 'Daily snapshots are read-only and retained for up to 90 days. Older snapshots may not include scanner breakdowns.', 'lucidlab-mpc-cleanup' ) . '</p>';
			} else {
				echo '<p class="lucidlab-mpc-empty">' . esc_html__( 'Run a scan to start the history.', 'lucidlab-mpc-cleanup' ) . '</p>';
			}
		}
		$this->begin_results_card( ' data-mpc-logs-section="logging"' );
		echo '<h3 class="lucidlab-mpc-card__title">' . esc_html__( 'Logging', 'lucidlab-mpc-cleanup' ) . '</h3>';
		echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'Use logging and maintenance tools here when diagnosing a problem. Review scan results before making changes; quarantine is reversible, while permanent purge requires a separate manual action. Back up your site and test on staging before large changes.', 'lucidlab-mpc-cleanup' ) . '</p>';
		echo '<form method="post" class="lucidlab-mpc-logging-form"><input type="hidden" name="lucidlab_mpc_op" value="save_logging" />';
		wp_nonce_field( 'lucidlab_mpc_op_save_logging' );
		echo '<p><label><input type="checkbox" name="lucidlab_mpc_logging" value="1" ' . checked( ! empty( $s['logging'] ), true, false ) . ' data-testid="logging-enable" /> ' . esc_html__( 'Enable log / debugging', 'lucidlab-mpc-cleanup' ) . '</label></p>';
		echo '<p><button type="submit" class="button button-primary" data-testid="logging-save">' . esc_html__( 'Save logging settings', 'lucidlab-mpc-cleanup' ) . '</button></p></form>';
		echo '<div class="lucidlab-mpc-btn-row"><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'lucidlab_mpc_export_log' ), admin_url( 'admin-post.php' ) ), 'lucidlab_mpc_export_log' ) ) . '">' . esc_html__( 'Export log CSV', 'lucidlab-mpc-cleanup' ) . '</a>';
		echo '<form method="post"><input type="hidden" name="lucidlab_mpc_op" value="send_test_report" />';
		wp_nonce_field( 'lucidlab_mpc_op_send_test_report' );
		echo '<button type="submit" class="button">' . esc_html__( 'Send test report now', 'lucidlab-mpc-cleanup' ) . '</button></form>';
		if ( empty( $s['report_enabled'] ) ) {
			echo '<span class="lucidlab-mpc-hint">' . esc_html__( 'Scheduled reports are off. This test still sends an email to the configured recipient, or the site administrator email if none is configured.', 'lucidlab-mpc-cleanup' ) . '</span>';
		}
		echo '<form method="post"><input type="hidden" name="lucidlab_mpc_op" value="log_clear" />';
		wp_nonce_field( 'lucidlab_mpc_op_log_clear', 'lucidlab_mpc_log_nonce' );
		echo '<button type="submit" class="button">' . esc_html__( 'Clear log', 'lucidlab-mpc-cleanup' ) . '</button></form></div>';
		$log = LucidLab_MPC_Logger::get();
		if ( ! empty( $log ) ) {
			$this->begin_results_card( ' data-mpc-logs-section="log"' );
			echo '<h3 class="lucidlab-mpc-card__title">' . esc_html__( 'Log', 'lucidlab-mpc-cleanup' ) . '</h3><pre class="lucidlab-mpc-log">';
			foreach ( array_reverse( $log ) as $line ) {
				echo esc_html( '[' . $line['t'] . '] ' . strtoupper( $line['lvl'] ) . ': ' . $line['m'] ) . "\n";
			}
			echo '</pre>';
		}
	}

	/**
	 * Render review image preview.
	 *
	 * @param int $id Id.
	 */
	private function render_review_image_preview( int $id ): void {
		echo wp_get_attachment_image(
			$id,
			array( 64, 64 ),
			false,
			array(
				'loading' => 'lazy',
				'class'   => 'lucidlab-mpc-thumb',
			)
		);
		$url = get_edit_post_link( $id, 'raw' );
		if ( $url ) {
			echo ' <a href="' . esc_url( $url ) . ( '" target="_blank" rel="noopener">' . esc_html__( 'View media', 'lucidlab-mpc-cleanup' ) . '</a>' );
		}
	}

	/**
	 * Render options.
	 *
	 * @param array $notices Notices.
	 */
	private function render_options( array $notices = array() ): void {
		if ( ! LucidLab_MPC_Edition::pro() ) {
			$this->render_free_options( $notices );
			return;}
		$s = LucidLab_MPC_Settings::all();
		$this->render_media_index_section();
		$this->render_cron_note();
		?>
		<form method="post" action="<?php echo esc_url( $this->tab_url( 'options' ) ); ?>" class="lucidlab-mpc-options-form">
			<?php wp_nonce_field( 'lucidlab_mpc_op_save_options' ); ?>
			<input type="hidden" name="lucidlab_mpc_op" value="save_options" />
			<input type="hidden" name="lucidlab_mpc_options_scope" value="all" />
			<?php $this->begin_results_card( ' data-mpc-options-section="performance"' ); ?>
			<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Performance', 'lucidlab-mpc-cleanup' ); ?></h3>
			<p class="lucidlab-mpc-hint lucidlab-mpc-card__lede"><?php esc_html_e( 'These numbers control how much work each batch does. Lower values use less memory; higher values finish faster. WebP and AVIF conversion keeps going for about two seconds per request. Visual comparison has no batch setting here. Memory and timeout pauses apply to background jobs.', 'lucidlab-mpc-cleanup' ); ?></p>
			<p class="lucidlab-mpc-performance-fields">
				<label><?php esc_html_e( 'Duplicate hash batch size', 'lucidlab-mpc-cleanup' ); ?> <input type="number" name="lucidlab_mpc_batch_db" value="<?php echo (int) $s['batch_db']; ?>" min="50" max="5000" /> <span class="lucidlab-mpc-hint"><?php esc_html_e( 'Byte Identical Images.', 'lucidlab-mpc-cleanup' ); ?></span></label>
				<label><?php esc_html_e( 'File batch size', 'lucidlab-mpc-cleanup' ); ?> <input type="number" name="lucidlab_mpc_batch_files" value="<?php echo (int) $s['batch_files']; ?>" min="50" max="5000" /> <span class="lucidlab-mpc-hint"><?php esc_html_e( 'Maximum Uploads Scanner files per batch. Uses this saved value; a batch may finish earlier when the time or memory limit is reached.', 'lucidlab-mpc-cleanup' ); ?></span></label>
				<label><?php esc_html_e( 'Scan DB batch size', 'lucidlab-mpc-cleanup' ); ?> <input type="number" name="lucidlab_mpc_scan_batch_db" value="<?php echo (int) $s['scan_batch_db']; ?>" min="50" max="5000" /> <span class="lucidlab-mpc-hint"><?php esc_html_e( 'Orphan Images and Uploads Scanner.', 'lucidlab-mpc-cleanup' ); ?></span></label>
				<label><?php esc_html_e( 'Missing-image batch size', 'lucidlab-mpc-cleanup' ); ?> <input type="number" name="lucidlab_mpc_missing_batch" value="<?php echo (int) $s['missing_batch']; ?>" min="1" max="100" /> <span class="lucidlab-mpc-hint"><?php esc_html_e( 'Missing Images.', 'lucidlab-mpc-cleanup' ); ?></span></label>
				<label><?php esc_html_e( 'Thumbnail regeneration batch size', 'lucidlab-mpc-cleanup' ); ?> <input type="number" name="lucidlab_mpc_regen_batch" value="<?php echo (int) $s['regen_batch']; ?>" min="1" max="100" /> <span class="lucidlab-mpc-hint"><?php esc_html_e( 'Regenerate Thumbnails.', 'lucidlab-mpc-cleanup' ); ?></span></label>
				<label><?php esc_html_e( 'Default JPEG quality', 'lucidlab-mpc-cleanup' ); ?> <input type="number" name="lucidlab_mpc_jpeg_quality" value="<?php echo (int) $s['jpeg_quality']; ?>" min="1" max="100" /> <span class="lucidlab-mpc-hint"><?php esc_html_e( 'Regenerate Thumbnails.', 'lucidlab-mpc-cleanup' ); ?></span></label>
				<label><?php esc_html_e( 'Backups to keep', 'lucidlab-mpc-cleanup' ); ?> <input type="number" name="lucidlab_mpc_backup_keep" value="<?php echo (int) ( $s['backup_keep'] ?? 5 ); ?>" min="1" max="50" /> <span class="lucidlab-mpc-hint"><?php esc_html_e( 'Newest copies kept for each type: database, uploads and full site.', 'lucidlab-mpc-cleanup' ); ?></span></label>
				<label class="lucidlab-mpc-performance-check"><input type="checkbox" name="lucidlab_mpc_quarantine_auto_purge" value="1" <?php checked( ! empty( $s['quarantine_auto_purge'] ) ); ?> /> <?php esc_html_e( 'Automatically purge expired quarantine sets daily (Pro)', 'lucidlab-mpc-cleanup' ); ?></label>
				<label><?php esc_html_e( 'Quarantine retention (days)', 'lucidlab-mpc-cleanup' ); ?> <input type="number" name="lucidlab_mpc_quarantine_days" value="<?php echo (int) ( $s['quarantine_days'] ?? 30 ); ?>" min="1" max="365" /> <span class="lucidlab-mpc-hint"><?php esc_html_e( 'Eligible sets older than this are removed when automatic purge is enabled. Original-image backups are never auto-purged.', 'lucidlab-mpc-cleanup' ); ?></span></label>
				<label><?php esc_html_e( 'Pause jobs at memory use (MB)', 'lucidlab-mpc-cleanup' ); ?> <input type="number" name="lucidlab_mpc_memory_threshold" value="<?php echo (int) ( $s['memory_threshold'] ?? 256 ); ?>" min="64" max="4096" /> <span class="lucidlab-mpc-hint"><?php esc_html_e( 'Background jobs pause when PHP memory use reaches this cap.', 'lucidlab-mpc-cleanup' ); ?></span></label>
				<label><?php esc_html_e( 'Pause jobs this many seconds before timeout', 'lucidlab-mpc-cleanup' ); ?> <input type="number" name="lucidlab_mpc_timeout_threshold" value="<?php echo (int) ( $s['timeout_threshold'] ?? 8 ); ?>" min="3" max="25" /> <span class="lucidlab-mpc-hint"><?php esc_html_e( 'Jobs pause near max_execution_time so the current batch can finish cleanly.', 'lucidlab-mpc-cleanup' ); ?></span></label>
			</p>
			<?php $this->render_scan_exclusions(); ?>
			<?php $this->begin_results_card( ' data-mpc-options-section="ai-provider"' ); ?>
			<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'AI provider (optional)', 'lucidlab-mpc-cleanup' ); ?></h3>
			<p class="lucidlab-mpc-hint lucidlab-mpc-card__lede"><?php esc_html_e( 'Leave this disabled until you choose a provider. The plugin never sends images or metadata without an explicit provider and key. Model and endpoint defaults are suggestions; you can edit them manually. Analyze sends the picture; Test connection only sends text. For local Ollama use a vision model (qwen2.5vl, llama3.2-vision or llava) with the exact name from ollama list, endpoint http://127.0.0.1:11434/api/chat, and expect 1–3 minutes per image on CPU.', 'lucidlab-mpc-cleanup' ); ?></p>
			<div class="lucidlab-mpc-ai-fields">
				<label><?php esc_html_e( 'AI Provider', 'lucidlab-mpc-cleanup' ); ?> <select name="lucidlab_mpc_ai_provider" data-mpc-ai-provider><option value="none" <?php selected( $s['ai_provider'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'Not configured', 'lucidlab-mpc-cleanup' ); ?></option><option value="openai" <?php selected( $s['ai_provider'] ?? 'none', 'openai' ); ?>><?php esc_html_e( 'OpenAI-compatible', 'lucidlab-mpc-cleanup' ); ?></option><option value="anthropic" <?php selected( $s['ai_provider'] ?? 'none', 'anthropic' ); ?>><?php esc_html_e( 'Anthropic-compatible', 'lucidlab-mpc-cleanup' ); ?></option><option value="local" <?php selected( $s['ai_provider'] ?? 'none', 'local' ); ?>><?php esc_html_e( 'Local Ollama-compatible', 'lucidlab-mpc-cleanup' ); ?></option></select></label>
				<label><?php esc_html_e( 'Model', 'lucidlab-mpc-cleanup' ); ?> <input type="text" name="lucidlab_mpc_ai_model" value="<?php echo esc_attr( $s['ai_model'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Provider model name', 'lucidlab-mpc-cleanup' ); ?>" data-mpc-ai-model /></label>
				<?php if ( LucidLab_MPC_AI::key_from_config() ) : ?>
					<label><?php esc_html_e( 'API key', 'lucidlab-mpc-cleanup' ); ?> <input type="password" value="" placeholder="<?php esc_attr_e( 'Defined in wp-config.php', 'lucidlab-mpc-cleanup' ); ?>" class="regular-text" disabled="disabled" autocomplete="off" /></label>
					<p class="lucidlab-mpc-hint lucidlab-mpc-ai-field-hint"><?php esc_html_e( 'The API key comes from LUCIDLAB_MPC_AI_KEY in wp-config.php and overrides any saved key.', 'lucidlab-mpc-cleanup' ); ?></p>
				<?php else : ?>
					<label><?php esc_html_e( 'API key', 'lucidlab-mpc-cleanup' ); ?> <input type="password" name="lucidlab_mpc_ai_api_key" value="" placeholder="<?php echo esc_attr( LucidLab_MPC_AI::masked_key( (string) ( $s['ai_api_key'] ?? '' ) ) ); ?>" class="regular-text" autocomplete="new-password" data-mpc-ai-api-key /></label>
					<p class="lucidlab-mpc-hint lucidlab-mpc-ai-field-hint"><?php esc_html_e( 'For more security, define LUCIDLAB_MPC_AI_KEY in wp-config.php instead.', 'lucidlab-mpc-cleanup' ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== (string) ( $s['ai_api_key'] ?? '' ) ) : ?>
					<label class="lucidlab-mpc-ai-check"><span><?php esc_html_e( 'Remove the saved API key', 'lucidlab-mpc-cleanup' ); ?></span><span class="lucidlab-mpc-ai-check__control"><input type="checkbox" name="lucidlab_mpc_ai_api_key_clear" value="1" /> <span class="lucidlab-mpc-hint"><?php esc_html_e( 'The saved key is not shown. Leave the field empty to keep it.', 'lucidlab-mpc-cleanup' ); ?></span></span></label>
				<?php endif; ?>
				<label><?php esc_html_e( 'Endpoint', 'lucidlab-mpc-cleanup' ); ?> <input type="url" name="lucidlab_mpc_ai_endpoint" value="<?php echo esc_attr( $s['ai_endpoint'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr__( 'https://… or http://localhost:11434', 'lucidlab-mpc-cleanup' ); ?>" data-mpc-ai-endpoint /></label>
				<?php
				$ai_locale_choices = LucidLab_MPC_AI::output_locale_choices();
				$ai_locale_saved   = str_replace( '-', '_', (string) ( $s['ai_output_locale'] ?? '' ) );
				$ai_locale_other   = '' !== $ai_locale_saved && ! isset( $ai_locale_choices[ $ai_locale_saved ] );
				?>
				<label><?php esc_html_e( 'Suggestion language', 'lucidlab-mpc-cleanup' ); ?> <span class="lucidlab-mpc-ai-lang"><select name="lucidlab_mpc_ai_output_locale" data-mpc-ai-locale>
					<option value="" <?php selected( $ai_locale_saved, '' ); ?>><?php esc_html_e( 'Site language (default)', 'lucidlab-mpc-cleanup' ); ?></option>
					<?php foreach ( $ai_locale_choices as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $ai_locale_saved, $code ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
					<option value="__other" <?php selected( $ai_locale_other ); ?>><?php esc_html_e( 'Other…', 'lucidlab-mpc-cleanup' ); ?></option>
				</select> <input type="text" name="lucidlab_mpc_ai_output_locale_other" value="<?php echo esc_attr( $ai_locale_other ? $ai_locale_saved : '' ); ?>" maxlength="60" placeholder="<?php esc_attr_e( 'Language name', 'lucidlab-mpc-cleanup' ); ?>" aria-label="<?php esc_attr_e( 'Language name', 'lucidlab-mpc-cleanup' ); ?>" data-mpc-ai-locale-other <?php echo $ai_locale_other ? '' : 'hidden disabled'; ?> /></span></label>
				<p class="lucidlab-mpc-hint"><?php esc_html_e( 'Filename, title and alt text are written in this language. Choose Other… to type a language that is not listed.', 'lucidlab-mpc-cleanup' ); ?></p>
				<div class="lucidlab-mpc-ai-test">
					<button type="button" class="button" data-mpc-ai-test><?php esc_html_e( 'Test connection', 'lucidlab-mpc-cleanup' ); ?></button>
					<span class="lucidlab-mpc-ai-test-status" role="status"></span>
				</div>
			</div>
			<?php $this->begin_results_card( ' data-mpc-options-section="ai-limits"' ); ?>
			<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'AI usage limits', 'lucidlab-mpc-cleanup' ); ?></h3>
			<?php $ai_usage = LucidLab_MPC_AI::usage(); ?>
			<?php /* translators: 1: requests made today, 2: daily request limit, 3: estimated provider cost. */ ?>
			<p class="lucidlab-mpc-hint lucidlab-mpc-card__lede" role="status"><?php echo esc_html( sprintf( __( 'Today (UTC): %1$s / %2$s requests. Estimated provider cost: %3$s. Estimates are not provider invoices.', 'lucidlab-mpc-cleanup' ), $ai_usage['requests'], $ai_usage['limit'], number_format_i18n( (float) $ai_usage['estimate'], 4 ) ) ); ?></p>
			<div class="lucidlab-mpc-ai-limits">
				<label><?php esc_html_e( 'Requests per day', 'lucidlab-mpc-cleanup' ); ?> <input type="number" min="1" max="100000" name="lucidlab_mpc_ai_daily_requests" value="<?php echo (int) $s['ai_daily_requests']; ?>"></label>
				<label><?php esc_html_e( 'Estimated cost per hosted request', 'lucidlab-mpc-cleanup' ); ?> <input type="number" min="0" step="0.0001" name="lucidlab_mpc_ai_request_estimate" value="<?php echo esc_attr( $s['ai_request_estimate'] ); ?>"></label>
				<label><?php esc_html_e( 'Daily estimated budget (0 disables budget check)', 'lucidlab-mpc-cleanup' ); ?> <input type="number" min="0" step="0.01" name="lucidlab_mpc_ai_daily_budget" value="<?php echo esc_attr( $s['ai_daily_budget'] ); ?>"></label>
				<label><?php esc_html_e( 'Minimum confidence threshold', 'lucidlab-mpc-cleanup' ); ?> <input type="number" min="0" max="1" step="0.05" name="lucidlab_mpc_ai_min_confidence" value="<?php echo esc_attr( number_format( (float) ( $s['ai_min_confidence'] ?? 0.65 ), 2, '.', '' ) ); ?>"></label>
				<p class="lucidlab-mpc-hint"><?php esc_html_e( 'Limits reset at midnight UTC. Every outgoing request, including tests and failed attempts, counts. Use the same currency for both cost fields. Cost estimates are user supplied, not provider billing; use a provider-side spending cap for a hard monetary limit. Local Ollama has zero estimated provider cost. Saved-result review uses no AI requests. Suggestions below the confidence threshold are hidden until the user lowers the threshold or reviews the image manually.', 'lucidlab-mpc-cleanup' ); ?></p>
			</div>
			<?php $this->render_email_report_fields( $s ); ?>
			<?php $this->begin_results_card( ' data-mpc-options-section="automation"' ); ?>
			<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Automation', 'lucidlab-mpc-cleanup' ); ?></h3>
			<?php
			$auto = LucidLab_MPC_Automation::choices();
			echo '<p><strong>' . esc_html__( 'Pro automation:', 'lucidlab-mpc-cleanup' ) . '</strong> ' . esc_html__( 'scheduled scans, confirmed automatic quarantine, automatic deletion after the 30-day retention period, email reports and WP-CLI scheduled jobs. Only selected jobs run automatically after saving. WP-Cron requires site visits or a cron runner.', 'lucidlab-mpc-cleanup' ) . '</p><div class="lucidlab-mpc-automation-columns">';
			foreach ( array(
				'weekly'  => __( 'Weekly', 'lucidlab-mpc-cleanup' ),
				'monthly' => __( 'Monthly', 'lucidlab-mpc-cleanup' ),
			) as $freq => $flabel ) :
				$sel = (array) $s[ 'autorun_' . $freq ];
				?>
				<fieldset data-mpc-automation-freq="<?php echo esc_attr( $freq ); ?>"><legend><strong><?php echo esc_html( $flabel ); ?></strong></legend>
				<label class="lucidlab-mpc-automation-select-all"><input type="checkbox" data-mpc-automation-select-all <?php checked( count( $sel ) === count( $auto ) && count( $auto ) > 0 ); ?> /> <?php esc_html_e( 'Select all', 'lucidlab-mpc-cleanup' ); ?></label>
				<?php foreach ( $auto as $k => $lbl ) : ?>
					<label><input type="checkbox" name="lucidlab_mpc_autorun_<?php echo esc_attr( $freq ); ?>[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $sel, true ) ); ?> /> <?php echo esc_html( $lbl ); ?></label>
				<?php endforeach; ?></fieldset>
			<?php endforeach; ?></div>
			<?php $this->render_install_ping_field( $s ); ?>
			<?php $this->render_uninstall_field( $s ); ?>
			<?php $this->begin_results_card( ' data-mpc-options-section="save"' ); ?>
			<p><button type="submit" name="lucidlab_mpc_op" value="save_options" class="button button-primary" data-testid="options-save"><?php esc_html_e( 'Save options', 'lucidlab-mpc-cleanup' ); ?></button></p>
		</form>
		<?php
		$this->render_reset_section();
	}

	/**
	 * Options card: restore defaults and remove scan-generated data.
	 *
	 * @param array $notices Notices.
	 */
	private function render_reset_section( array $notices = array() ): void {
		unset( $notices );
		$confirm_msg = __( 'Reset Media & Products Cleanup data? This restores Options to defaults, including turning logging off. It permanently deletes scan results, Finished Jobs history, job state, media/visual indexes and the plugin work folders (lucidlab-mpc-cleanup/work, lucidlab-mpc-cleanup/format-preview). The next visual duplicate scan must rebuild its fingerprints and may take longer. Scan folder exclusions are kept. Automatic quarantine purge returns to its default (off). License keys, rename undo history and user backups are kept. Quarantine is kept unless you checked that box (protected sets are never deleted). Type CONFIRM to continue.', 'lucidlab-mpc-cleanup' );
		$this->begin_results_card( ' data-mpc-options-section="reset"' );
		?>
		<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Reset plugin data', 'lucidlab-mpc-cleanup' ); ?></h3>
		<p class="lucidlab-mpc-hint lucidlab-mpc-card__lede"><?php esc_html_e( 'Use this after testing or when you want a clean slate for scans. It does not uninstall the plugin.', 'lucidlab-mpc-cleanup' ); ?></p>
		<ul class="lucidlab-mpc-hint">
			<li><strong><?php esc_html_e( 'Will reset / delete', 'lucidlab-mpc-cleanup' ); ?></strong> — <?php esc_html_e( 'all Options settings (back to defaults); scan summaries and result caches; running/finished scan job state; Finished Jobs history; other plugin database options and user meta; media index and visual fingerprint tables; hash/reference/result caches; crawl and uploads work files under uploads/lucidlab-mpc-cleanup/work; WebP/AVIF format-preview test folder; reclaimable stats and job history; plugin log.', 'lucidlab-mpc-cleanup' ); ?> <?php esc_html_e( 'Logging returns to its default off state. The next visual duplicate scan rebuilds fingerprints.', 'lucidlab-mpc-cleanup' ); ?></li>
			<li><strong><?php esc_html_e( 'Will keep', 'lucidlab-mpc-cleanup' ); ?></strong> — <?php esc_html_e( 'Scan folder exclusions; Pro license registration; rename undo history; user-created backups in uploads/lucidlab-mpc-cleanup; quarantine sets (unless you opt in below). If present, protected quarantine sets 2026-09-29_101714 and 2026-09-29_122928 are never purged. Automatic quarantine purge returns to its default (off).', 'lucidlab-mpc-cleanup' ); ?></li>
		</ul>
		<form method="post" action="<?php echo esc_url( $this->tab_url( 'options' ) ); ?>" class="lucidlab-mpc-reset-form" data-mpc-confirm-message="<?php echo esc_attr( $confirm_msg ); ?>" data-mpc-confirm-text="CONFIRM">
			<?php wp_nonce_field( 'lucidlab_mpc_op_reset_plugin_data' ); ?>
			<input type="hidden" name="lucidlab_mpc_op" value="reset_plugin_data" />
			<input type="hidden" name="lucidlab_mpc_reset_confirm" value="" />
			<p class="lucidlab-mpc-reset-quarantine">
				<label>
					<input type="checkbox" name="lucidlab_mpc_reset_quarantine" value="1" />
					<?php esc_html_e( 'Also delete quarantine sets (except protected sets)', 'lucidlab-mpc-cleanup' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" id="lucidlab-mpc-options-reset" class="button lucidlab-mpc-danger-text" data-testid="options-reset"><?php esc_html_e( 'Reset plugin data', 'lucidlab-mpc-cleanup' ); ?></button>
			</p>
		</form>
		<?php
		$this->end_results_card();
	}

	/* ---------- Tab 5 (Byte Identical Images) ---------- */

	/**
	 * Render dupcontent.
	 *
	 * @param mixed $data Data.
	 */
	private function render_dupcontent( $data ): void {
		ob_start();try {
			if ( ! is_array( $data ) && $this->results_list_requested( 'dupcontent' ) ) {
				$data = $this->get_scan_result( 'dupcontent' );
			}
			?>
		<p class="lucidlab-mpc-scan-description"><?php esc_html_e( 'Hashes every image with SHA-256 to find truly byte-identical copies (re-encoded look-alikes are ignored). For each group it shows where every copy is attached — featured image, product gallery, category thumbnail and post/builder content. Merging repoints all references onto one "Keep" image and moves the extra copies to the restorable quarantine.', 'lucidlab-mpc-cleanup' ); ?></p>
		<form method="post" class="lucidlab-mpc-controls">
			<button type="submit" class="button button-primary" data-mpc-scan="dupcontent" data-testid="scan-dupcontent" formnovalidate><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Find byte-identical images', 'lucidlab-mpc-cleanup' ); ?></button>
			<?php $this->render_view_saved_results( 'dupcontent', __( 'Loading byte-identical images…', 'lucidlab-mpc-cleanup' ) ); ?>
			<?php if ( is_array( $data ) ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lucidlab_mpc_export_csv&tab=dupcontent' ), 'lucidlab_mpc_export_dupcontent' ) ); ?>"><span class="dashicons dashicons-media-spreadsheet"></span> <?php esc_html_e( 'Export CSV', 'lucidlab-mpc-cleanup' ); ?></a>
			<?php endif; ?>
		</form>

			<?php
			if ( ! is_array( $data ) ) {
				if ( $this->results_list_requested( 'dupcontent' ) ) {
					$summary = get_option( 'lucidlab_mpc_summary_dupcontent', array() );
					$counts  = is_array( $summary['counts'] ?? null ) ? $summary['counts'] : array();
					if ( array_key_exists( 'group_count', $counts ) && 0 === (int) $counts['group_count'] ) {
						echo '<p class="lucidlab-mpc-empty lucidlab-mpc-good"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'No byte-identical duplicate images found.', 'lucidlab-mpc-cleanup' ) . '</p>';
					}
				}
				return;
			}
			?>

			<?php $this->begin_results_card( ' data-mpc-results-section="dupcontent"' ); ?>
		<div class="lucidlab-mpc-stats" data-mpc-results-loaded="1" hidden></div>

			<?php if ( empty( $data['groups'] ) ) : ?>
				<?php if ( ! $this->render_if_quarantined( $data ) ) : ?>
			<p class="lucidlab-mpc-empty lucidlab-mpc-good"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'No byte-identical duplicate images found.', 'lucidlab-mpc-cleanup' ); ?></p>
			<?php endif; ?>
				<?php
				return;
endif;
			?>

			<?php $this->progress_bar( 'dupcontent-merge' ); ?>
			<?php
			$dupc_page      = $this->results_page( (array) $data['groups'], 'lucidlab_mpc_dupc_page' );
			$data['groups'] = $dupc_page['items'];
			?>
		<div class="lucidlab-mpc-dupc-form">
			<?php foreach ( $data['groups'] as $gi => $group ) : ?>
				<div class="lucidlab-mpc-group">
					<div class="lucidlab-mpc-group-head">
						<?php if ( ! empty( $group['same_file'] ) ) : ?>
							<?php /* translators: 1: duplicate group number, 2: file size, 3: number of media records, 4: number of files on disk. */ ?>
							<?php echo esc_html( sprintf( __( 'Group %1$d — %2$s, %3$d media records using %4$d file(s) on disk', 'lucidlab-mpc-cleanup' ), $gi + 1, LucidLab_MPC_Helpers::bytes( $group['bytes'] ), $group['count'], (int) ( $group['files'] ?? 1 ) ) ); ?>
						<?php else : ?>
							<?php /* translators: 1: duplicate group number, 2: file size per copy, 3: number of copies. */ ?>
							<?php echo esc_html( sprintf( __( 'Group %1$d — %2$s each, %3$d copies', 'lucidlab-mpc-cleanup' ), $gi + 1, LucidLab_MPC_Helpers::bytes( $group['bytes'] ), $group['count'] ) ); ?>
						<?php endif; ?>
						<code class="lucidlab-mpc-hash"><?php echo esc_html__( 'sha256:', 'lucidlab-mpc-cleanup' ); ?><?php echo esc_html( substr( $group['hash'], 0, 12 ) ); ?>…</code>
						<?php if ( ! empty( $group['warning'] ) ) : ?>
							<p class="lucidlab-mpc-hint lucidlab-mpc-warning"><strong><?php esc_html_e( 'Warning:', 'lucidlab-mpc-cleanup' ); ?></strong> <?php echo esc_html( $group['warning'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $group['same_file'] ) ) : ?>
							<?php /* translators: %d: number of media records that use the same file as another record of the group. */ ?>
							<p class="lucidlab-mpc-hint"><?php echo esc_html( sprintf( _n( '%d media record uses the same file on disk as another record here; it is not a copy. Merging it moves its references to the Keep image and removes the extra record, but the file stays in place for the records that use it, so it frees no space.', '%d media records use the same file on disk as another record here; they are not copies. Merging them moves their references to the Keep image and removes the extra records, but the file stays in place for the records that use it, so it frees no space.', (int) $group['same_file'], 'lucidlab-mpc-cleanup' ), (int) $group['same_file'] ) ); ?></p>
						<?php endif; ?>
					</div>
					<div class="lucidlab-mpc-shared-list">
						<?php foreach ( $group['members'] as $m ) : ?>
							<div class="lucidlab-mpc-shared-row lucidlab-mpc-dupc-row <?php echo $m['is_survivor'] ? 'lucidlab-mpc-keep' : 'lucidlab-mpc-dupe'; ?>" data-group="<?php echo (int) $gi; ?>" data-id="<?php echo (int) $m['id']; ?>" data-refs="<?php echo (int) $m['ref_count']; ?>" data-bytes="<?php echo (int) ( empty( $m['same_file'] ) ? ( $group['bytes'] ?? 0 ) : 0 ); ?>" data-title="<?php echo esc_attr( $m['title'] ); ?>">
								<div class="lucidlab-mpc-shared-image">
									<?php if ( $m['thumb'] ) : ?>
										<img class="lucidlab-mpc-thumb lucidlab-mpc-thumb-lg lucidlab-mpc-lb" src="<?php echo esc_url( $m['thumb'] ); ?>" data-full="<?php echo esc_url( (string) $m['full'] ); ?>" data-caption="<?php echo esc_attr( $m['title'] ); ?>" alt="" />
									<?php else : ?>
										<span class="lucidlab-mpc-thumb lucidlab-mpc-thumb-lg lucidlab-mpc-thumb-none dashicons dashicons-format-image"></span>
									<?php endif; ?>
									<div class="lucidlab-mpc-shared-meta">
										<label class="lucidlab-mpc-dupc-keeplbl"><input type="radio" name="lucidlab_mpc_keep[<?php echo (int) $gi; ?>]" value="<?php echo (int) $m['id']; ?>" class="lucidlab-mpc-dupc-keep" data-group="<?php echo (int) $gi; ?>" data-id="<?php echo (int) $m['id']; ?>" <?php checked( $m['is_survivor'] ); ?> data-testid="dupc-keep-<?php echo (int) $m['id']; ?>" /> <?php esc_html_e( 'Keep this one', 'lucidlab-mpc-cleanup' ); ?></label>
										<strong><?php echo esc_html( $m['title'] ); ?></strong>
										<?php /* translators: %d is the number of references to this image. */ ?>
										<span class="lucidlab-mpc-hint">#<?php echo (int) $m['id']; ?> · <?php echo esc_html( sprintf( _n( '%d reference', '%d references', $m['ref_count'], 'lucidlab-mpc-cleanup' ), $m['ref_count'] ) ); ?></span>
										<?php if ( ! empty( $m['same_file'] ) ) : ?>
											<?php /* translators: %d: ID of the media record that uses the same file. */ ?>
											<span class="lucidlab-mpc-badge" title="<?php echo esc_attr__( 'Same file on disk, not a copy. The file stays in place when this record is merged.', 'lucidlab-mpc-cleanup' ); ?>"><?php echo esc_html( sprintf( __( 'Same file as #%d', 'lucidlab-mpc-cleanup' ), (int) $m['same_file'] ) ); ?></span>
										<?php endif; ?>
										<?php
										if ( (int) $m['ref_count'] >= 10 ) :
											?>
											<span class="lucidlab-mpc-badge lucidlab-mpc-badge-risk" title="<?php echo esc_attr__( 'This image is referenced broadly; review every reference before merging.', 'lucidlab-mpc-cleanup' ); ?>"><?php esc_html_e( 'Many references', 'lucidlab-mpc-cleanup' ); ?></span><?php endif; ?>
										<div class="lucidlab-mpc-btn-row">
											<?php
											if ( $m['edit'] ) :
												?>
												<a class="button button-small" href="<?php echo esc_url( $m['edit'] ); ?>" target="_blank"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Edit image', 'lucidlab-mpc-cleanup' ); ?></a><?php endif; ?>
										</div>
										<span class="lucidlab-mpc-badge lucidlab-mpc-badge-safe lucidlab-mpc-role-keep" <?php echo $m['is_survivor'] ? '' : 'hidden'; ?>><?php esc_html_e( 'KEEP', 'lucidlab-mpc-cleanup' ); ?></span>
										<span class="lucidlab-mpc-badge lucidlab-mpc-badge-risk lucidlab-mpc-role-dupe" <?php echo $m['is_survivor'] ? 'hidden' : ''; ?>><?php esc_html_e( 'will merge → quarantine', 'lucidlab-mpc-cleanup' ); ?></span>
									</div>
								</div>
								<details class="lucidlab-mpc-shared-products" data-mpc-lazy-refs="<?php echo (int) $m['id']; ?>"><summary><?php echo esc_html__( 'Show referenced products / posts / content', 'lucidlab-mpc-cleanup' ); ?></summary>
									<div class="lucidlab-mpc-lazy-refs-body"><p class="lucidlab-mpc-hint"><?php esc_html_e( 'Open to load references.', 'lucidlab-mpc-cleanup' ); ?></p></div>
								</details>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="lucidlab-mpc-dupc-preview" data-group="<?php echo (int) $gi; ?>"></p>
<p><button type="button" class="button button-primary" data-mpc-merge-group><?php esc_html_e( 'Merge this group into its Keep image', 'lucidlab-mpc-cleanup' ); ?></button></p>
				</div>
			<?php endforeach; ?>
			<div class="lucidlab-mpc-actionbar">
				<button type="button" class="button" data-mpc-reset-keeps data-testid="reset-keeps"><span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e( 'Reset Keeps to default rule', 'lucidlab-mpc-cleanup' ); ?></button>
				<?php $this->delete_toggle(); ?>
				<button type="button" class="button button-primary lucidlab-mpc-danger" data-mpc-merge disabled data-testid="merge-dupcontent"><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Merge all groups into their Keep image', 'lucidlab-mpc-cleanup' ); ?></button>
			</div>
			<p class="lucidlab-mpc-group-note"><?php esc_html_e( 'Default Keep rule: most references, then oldest ID. Change any "Keep" to override, or reset all to the rule. Merge processes every group in one pass — it repoints _thumbnail_id, product galleries, term thumbnails and content/builder IDs, then quarantines the duplicates (restorable for 30 days; use "Undo merge" on the Quarantine tab to reverse a whole batch).', 'lucidlab-mpc-cleanup' ); ?></p>
		</div>
			<?php
			$this->render_results_pager( $dupc_page, 'dupcontent', 'lucidlab_mpc_dupc_page', __( 'groups', 'lucidlab-mpc-cleanup' ) );
		} finally {
			$html = ob_get_clean();
			if ( ! LucidLab_MPC_Edition::pro() ) {
				$html = preg_replace( '~<button\b[^>]*data-mpc-merge[^>]*>.*?</button>~s', '', $html );
				$html = preg_replace( '~<label class="lucidlab-mpc-dupc-keeplbl">.*?</label>~s', '', $html );
				$html = preg_replace( '~<span class="lucidlab-mpc-badge[^"]*lucidlab-mpc-role-(?:keep|dupe)"[^>]*>.*?</span>~s', '', $html );
				$html = str_replace( __( 'Merging repoints all references onto one &quot;Keep&quot; image and moves the extra copies to the restorable quarantine.', 'lucidlab-mpc-cleanup' ), __( 'Reference merging is available in the Pro add-on.', 'lucidlab-mpc-cleanup' ), $html );
			}echo $html; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buffered admin markup assembled from escaped fragments. */ }
	}

	/**
	 * Ref label.
	 *
	 * @param string $type Type.
	 *
	 * @return string
	 */
	private function ref_label( string $type ): string {
		$map = array(
			'featured' => __( 'featured', 'lucidlab-mpc-cleanup' ),
			'gallery'  => __( 'gallery', 'lucidlab-mpc-cleanup' ),
			'term'     => __( 'category', 'lucidlab-mpc-cleanup' ),
			'content'  => __( 'content', 'lucidlab-mpc-cleanup' ),
		);
		return $map[ $type ] ?? $type;
	}

	/* ---------- Tab 6 (Rename Images) ---------- */

	/**
	 * Render list requested.
	 *
	 * @param string $tab   Tab.
	 * @param string $label Label.
	 *
	 * @return bool
	 */
	private function render_list_requested( string $tab, string $label ): bool {
		if ( $this->current_tab() === $tab && ( ! empty( $_GET['lucidlab_mpc_load'] ) || ! empty( $_GET['paged'] ) || ! empty( $_GET['lucidlab_mpc_quarantined'] ) || ! empty( $_GET['lucidlab_mpc_restored'] ) || ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $this->req['lucidlab_mpc_op'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
			return true;
		}
		echo '<p class="lucidlab-mpc-hint lucidlab-mpc-load-list-intro">' . esc_html__( 'Load the list when you need it. Switching tabs does not scan files or load images.', 'lucidlab-mpc-cleanup' ) . '</p>';
		$loading_label = 'quarantine' === $tab ? __( 'Loading Quarantined Items', 'lucidlab-mpc-cleanup' ) : __( 'Loading images to rename', 'lucidlab-mpc-cleanup' );
		echo '<p class="lucidlab-mpc-load-list-action"><a class="button button-primary" data-mpc-load-list="1" data-mpc-loading-text="' . esc_attr( $loading_label ) . '" href="' . esc_url( $this->tab_url( $tab ) . '&lucidlab_mpc_load=1' ) . '">' . esc_html( $label ) . '</a></p>';
		return false;
	}

	/**
	 * Render rename.
	 */
	private function render_rename(): void {
		ob_start();try {
			if ( ! $this->render_list_requested( 'rename', __( 'Load images to rename', 'lucidlab-mpc-cleanup' ) ) ) {
				return;
			}
			/* Stay in the open overview card so results replace the Load button in the same white card. */
			$per        = 50;
			$paged      = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
			$search     = isset( $_GET['lucidlab_mpc_rename_search'] ) ? sanitize_text_field( wp_unslash( $_GET['lucidlab_mpc_rename_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
			$total      = $this->rename->total_images( $search );
			$pages      = max( 1, (int) ceil( $total / $per ) );
			$paged      = min( $paged, $pages );
			$images     = $this->rename->list_images( ( $paged - 1 ) * $per, $per, $search );
			$log_search = sanitize_text_field( wp_unslash( $_GET['lucidlab_mpc_rename_log_search'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
			$log_page   = max( 1, ( isset( $_GET['lucidlab_mpc_rename_log_page'] ) ? (int) $_GET['lucidlab_mpc_rename_log_page'] : 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
			$log_result = $this->rename->log_page( $log_search, $log_page, 50 );
			$log_total  = $log_result['total'];
			$log_pages  = max( 1, (int) ceil( $log_total / 50 ) );
			if ( $log_page > $log_pages ) {
				$log_page   = $log_pages;
				$log_result = $this->rename->log_page( $log_search, $log_page, 50 ); }
			$log     = $log_result['rows'];
			$log_url = add_query_arg(
				array(
					'lucidlab_mpc_load'              => 1,
					'paged'                          => $paged,
					'lucidlab_mpc_rename_search'     => $search,
					'lucidlab_mpc_rename_log_search' => $log_search,
				),
				$this->tab_url( 'rename' )
			);
			?>

		<p class="lucidlab-mpc-hint"><?php esc_html_e( 'Renames the image file (original + all generated sizes) on disk, updates the media record, and rewrites the old upload path to the new one inside post/builder content. The attachment ID never changes, so featured/gallery/category links keep working. Every rename can be undone below. Run on staging first.', 'lucidlab-mpc-cleanup' ); ?></p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="lucidlab-mpc-cleanup" /><input type="hidden" name="tab" value="rename" /><input type="hidden" name="lucidlab_mpc_load" value="1" />
			<label><?php esc_html_e( 'Search images', 'lucidlab-mpc-cleanup' ); ?> <input type="search" name="lucidlab_mpc_rename_search" id="lucidlab-mpc-rename-search" value="<?php echo esc_attr( $search ); ?>" /></label>
			<button class="button"><?php echo esc_html__( 'Search', 'lucidlab-mpc-cleanup' ); ?></button>
		</form>
		<p><label><?php esc_html_e( 'Naming pattern', 'lucidlab-mpc-cleanup' ); ?> <input type="text" id="lucidlab-mpc-rename-pattern" class="regular-text" value="{title}" /></label></p>
		<div class="lucidlab-mpc-rename-pattern" data-mpc-rename-pattern>
			<span class="lucidlab-mpc-exclude-chips" data-mpc-pattern-chips id="lucidlab-mpc-pattern-selected"></span>
			<span class="lucidlab-mpc-pattern-tags"><button type="button" class="button button-small" data-pattern-tag="{title}">{title}</button> <button type="button" class="button button-small" data-pattern-tag="{domain}">{domain}</button> <button type="button" class="button button-small" data-pattern-tag="{date}">{date}</button> <button type="button" class="button button-small" data-pattern-tag="{id}">{id}</button> <button type="button" class="button button-small" data-pattern-tag="{filename}">{filename}</button> <button type="button" class="button button-small" data-pattern-tag="{sku}">{sku}</button> <button type="button" class="button button-small" data-pattern-tag="{brand}">{brand}</button> <button type="button" class="button button-small" data-pattern-tag="{brands}">{brands}</button> <button type="button" class="button button-small" data-pattern-tag="{category}">{category}</button> <button type="button" class="button button-small" data-pattern-tag="{categories}">{categories}</button></span>
		</div>
		<fieldset class="lucidlab-mpc-ai-apply-fields">
			<legend><?php esc_html_e( 'Apply with Rename all', 'lucidlab-mpc-cleanup' ); ?></legend>
			<label><input type="checkbox" id="lucidlab-mpc-rename-filename" checked> <?php esc_html_e( 'Filename', 'lucidlab-mpc-cleanup' ); ?></label>
			<label><input type="checkbox" id="lucidlab-mpc-rename-title"> <?php esc_html_e( 'Title', 'lucidlab-mpc-cleanup' ); ?></label>
			<label><input type="checkbox" id="lucidlab-mpc-rename-alt"> <?php esc_html_e( 'Alt text', 'lucidlab-mpc-cleanup' ); ?></label>
		</fieldset>
		<p><button type="button" class="button" id="lucidlab-mpc-rename-preview"><?php echo esc_html__( 'Apply to names below', 'lucidlab-mpc-cleanup' ); ?></button>
		<button type="button" class="button button-primary" id="lucidlab-mpc-rename-pattern-all"><?php echo esc_html__( 'Rename All Images', 'lucidlab-mpc-cleanup' ); ?></button></p>
		<p class="lucidlab-mpc-hint">Tags: {title} = attached post/product/page title (image title if unattached); {domain} = site domain; {date} = image upload date; {id} = image ID; {filename} = current filename without extension. Dots in the domain become hyphens. Existing filenames are never overwritten; matching names receive -1, -2, and so on.</p>
			<?php $this->progress_bar( 'rename' ); ?>
		<p id="lucidlab-mpc-rename-pattern-status" role="status"></p>
			<?php if ( empty( $images ) ) : ?>
			<p class="lucidlab-mpc-empty"><?php esc_html_e( 'No images in the media library.', 'lucidlab-mpc-cleanup' ); ?></p>
		<?php else : ?>
			<div class="lucidlab-mpc-pager">
				<button type="button" class="button button-primary" data-mpc-rename-all data-testid="rename-all"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Rename Images on this page', 'lucidlab-mpc-cleanup' ); ?></button>
			</div>
			<table class="widefat lucidlab-mpc-table lucidlab-mpc-rename-table" style="table-layout: auto;">
				<thead><tr>
					<th style="width: 80px;"><?php esc_html_e( 'Preview', 'lucidlab-mpc-cleanup' ); ?></th><th style="width: 150px;"><?php esc_html_e( 'Current file', 'lucidlab-mpc-cleanup' ); ?></th><th style="width: 120px;"><?php esc_html_e( 'Attached', 'lucidlab-mpc-cleanup' ); ?> <span class="lucidlab-mpc-attachment-help" tabindex="0" role="img" aria-label="<?php esc_attr_e( 'About images marked Not attached', 'lucidlab-mpc-cleanup' ); ?>" aria-describedby="lucidlab-mpc-attachment-help-text">!<span class="lucidlab-mpc-attachment-tooltip" id="lucidlab-mpc-attachment-help-text" role="tooltip"><strong><?php esc_html_e( '“Not attached” means the image has no parent product, page, or post recorded in WordPress.', 'lucidlab-mpc-cleanup' ); ?></strong> <?php esc_html_e( 'It doesn\'t necessarily mean the image is unused.', 'lucidlab-mpc-cleanup' ); ?><br /><br /><?php esc_html_e( 'An image can still be used as a featured image, in a product gallery, or inside page content without having a parent recorded. The Orphan Images scanner checks those references, so it correctly excludes those images from its results.', 'lucidlab-mpc-cleanup' ); ?></span></span></th><th style="width: 180px;"><?php esc_html_e( 'New name', 'lucidlab-mpc-cleanup' ); ?></th><th style="width: 100px;"><?php esc_html_e( 'Action', 'lucidlab-mpc-cleanup' ); ?></th><th style="width: 80px;"><?php esc_html_e( 'Status', 'lucidlab-mpc-cleanup' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $images as $im ) : ?>
					<tr class="lucidlab-mpc-rename-row" data-tags="<?php echo esc_attr( wp_json_encode( $im['tags'] ) ); ?>" data-id="<?php echo (int) $im['id']; ?>">
						<td style="text-align: center;"><?php echo $im['thumb'] ? '<img class="lucidlab-mpc-thumb lucidlab-mpc-lb" src="' . esc_url( $im['thumb'] ) . '" data-full="' . esc_url( (string) $im['full'] ) . '" data-caption="' . esc_attr( $im['title'] ) . '" alt="" style="max-width: 60px; max-height: 60px;" />' : '<span class="lucidlab-mpc-thumb lucidlab-mpc-thumb-none dashicons dashicons-format-image"></span>'; ?></td>
						<td><span class="lucidlab-mpc-rename-cur" style="display: block; word-break: break-all;"><?php echo esc_html( $im['current'] ); ?></span><span class="lucidlab-mpc-hint" style="font-size: 11px;">#<?php echo (int) $im['id']; ?></span></td>
						<td><?php echo esc_html( ( $im['parent_title'] ? $im['parent_title'] : __( 'Not attached', 'lucidlab-mpc-cleanup' ) ) ); ?></td>
						<td><input type="text" class="lucidlab-mpc-rename-name" value="<?php echo esc_attr( $im['suggest'] ); ?>" data-testid="rename-input-<?php echo (int) $im['id']; ?>" style="width: 100%;" /></td>
						<td><div style="display: flex; flex-direction: column; gap: 4px;"><button type="button" class="button button-small button-primary" data-mpc-rename data-id="<?php echo (int) $im['id']; ?>" data-testid="rename-btn-<?php echo (int) $im['id']; ?>" style="width: 100%; text-align: center;"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Rename', 'lucidlab-mpc-cleanup' ); ?></button>
							<?php
							if ( $im['edit'] ) :
								?>
								<a class="button button-small" href="<?php echo esc_url( $im['edit'] ); ?>" target="_blank" rel="noopener noreferrer" style="width: 100%; text-align: center;"><?php esc_html_e( 'Edit Image', 'lucidlab-mpc-cleanup' ); ?></a><?php endif; ?>
							<?php
							if ( $im['parent_url'] ) :
								$parent_label = 'product' === $im['parent_type'] ? __( 'View Product', 'lucidlab-mpc-cleanup' ) : ( 'page' === $im['parent_type'] ? __( 'View Page', 'lucidlab-mpc-cleanup' ) : ( 'post' === $im['parent_type'] ? __( 'View Post', 'lucidlab-mpc-cleanup' ) : __( 'View', 'lucidlab-mpc-cleanup' ) ) );
								?>
<a class="button button-small" href="<?php echo esc_url( $im['parent_url'] ); ?>" target="_blank" rel="noopener noreferrer" style="width: 100%; text-align: center;"><?php echo esc_html( $parent_label ); ?></a><?php endif; ?>
						</div></td>
						<td class="lucidlab-mpc-rename-status" style="font-size: 12px;"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="lucidlab-mpc-pager">
				<?php
				if ( $paged > 1 ) :
					?>
					<a class="button" href="<?php echo esc_url( $this->tab_url( 'rename' ) . '&lucidlab_mpc_rename_search=' . rawurlencode( $search ) . '&paged=' . ( $paged - 1 ) ); ?>">&laquo; <?php esc_html_e( 'Prev', 'lucidlab-mpc-cleanup' ); ?></a><?php endif; ?>
				<?php /* translators: 1: current result page, 2: total pages, 3: total images. */ ?>
				<span><?php echo esc_html( sprintf( __( 'Page %1$d of %2$d (%3$d images)', 'lucidlab-mpc-cleanup' ), $paged, $pages, $total ) ); ?></span>
				<?php
				if ( $paged < $pages ) :
					?>
					<a class="button" href="<?php echo esc_url( $this->tab_url( 'rename' ) . '&lucidlab_mpc_rename_search=' . rawurlencode( $search ) . '&paged=' . ( $paged + 1 ) ); ?>"><?php esc_html_e( 'Next', 'lucidlab-mpc-cleanup' ); ?> &raquo;</a><?php endif; ?>
			</div>
		<?php endif; ?>

			<?php $this->begin_results_card( ' data-mpc-results-section="rename-history"' ); ?>
		<h3 class="lucidlab-mpc-card__title" id="lucidlab-mpc-recent-renames"><?php esc_html_e( 'Recent renames', 'lucidlab-mpc-cleanup' ); ?></h3>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>#lucidlab-mpc-recent-renames">
<input type="hidden" name="page" value="lucidlab-mpc-cleanup" /><input type="hidden" name="tab" value="rename" /><input type="hidden" name="lucidlab_mpc_load" value="1" />
<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>" /><input type="hidden" name="lucidlab_mpc_rename_search" value="<?php echo esc_attr( $search ); ?>" />
<p><label><?php esc_html_e( 'Search recent renames', 'lucidlab-mpc-cleanup' ); ?> <input type="search" name="lucidlab_mpc_rename_log_search" value="<?php echo esc_attr( $log_search ); ?>" /></label> <button class="button"><?php echo esc_html__( 'Search recent renames', 'lucidlab-mpc-cleanup' ); ?></button></p></form>
<table class="widefat lucidlab-mpc-table lucidlab-mpc-rename-history">
				<thead><tr><th><?php echo esc_html__( 'Preview', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'When', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Image', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'From → To', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Posts updated', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Undo', 'lucidlab-mpc-cleanup' ); ?></th></tr></thead>
				<tbody>
				<?php
				foreach ( $log as $e ) :
					$i = (int) $e['row'];
					?>
					<tr>
<td>
					<?php
					$thumb = wp_get_attachment_image_url( (int) ( $e['id'] ?? 0 ), 'thumbnail' );
					echo $thumb ? '<img class="lucidlab-mpc-thumb" src="' . esc_url( $thumb ) . '" alt="" loading="lazy" />' : '<span class="lucidlab-mpc-thumb lucidlab-mpc-thumb-none dashicons dashicons-format-image"></span>';
					?>
</td>
<td><?php echo esc_html( $e['ts'] ?? '' ); ?></td>
						<td>#<?php echo (int) ( $e['id'] ?? 0 ); ?> <?php echo esc_html( $e['title'] ?? '' ); ?></td>
						<td><code><?php echo esc_html( $e['from'] ?? '' ); ?></code> → <code class="lucidlab-mpc-rename-new"><?php echo esc_html( $e['to'] ?? '' ); ?></code></td>
						<td><?php echo (int) $e['posts']; ?></td>
						<td>
							<form method="post" data-mpc-confirm-message="Undo this rename?">
								<?php wp_nonce_field( 'lucidlab_mpc_op_undo_rename' ); ?>
								<input type="hidden" name="lucidlab_mpc_op" value="undo_rename" />
								<input type="hidden" name="lucidlab_mpc_index" value="<?php echo (int) $i; ?>" />
								<button class="button button-small" data-testid="undo-rename-<?php echo (int) $i; ?>"><span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e( 'Undo', 'lucidlab-mpc-cleanup' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php
				if ( ! $log ) :
					?>
					<tr><td colspan="6"><?php echo esc_html__( 'No recent renames match your search.', 'lucidlab-mpc-cleanup' ); ?></td></tr><?php endif; ?></tbody>
			</table>
<div class="lucidlab-mpc-pager">
			<?php
			if ( $log_page > 1 ) :
				?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'lucidlab_mpc_rename_log_page', $log_page - 1, $log_url ) . '#lucidlab-mpc-recent-renames' ); ?>"><?php echo esc_html__( 'Previous', 'lucidlab-mpc-cleanup' ); ?></a><?php endif; ?>
<span>
			<?php
			echo esc_html(
				sprintf(
				/* translators: 1: page number, 2: total pages, 3: rename count */
					_n( 'Page %1$d of %2$d (%3$d rename)', 'Page %1$d of %2$d (%3$d renames)', $log_total, 'lucidlab-mpc-cleanup' ),
					$log_page,
					$log_pages,
					$log_total
				)
			);
			?>
</span>
			<?php
			if ( $log_page < $log_pages ) :
				?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'lucidlab_mpc_rename_log_page', $log_page + 1, $log_url ) . '#lucidlab-mpc-recent-renames' ); ?>"><?php echo esc_html__( 'Next', 'lucidlab-mpc-cleanup' ); ?></a><?php endif; ?></div>
			<?php
		} finally {
			$html = ob_get_clean();
			if ( ! LucidLab_MPC_Edition::pro() ) {
				$html = preg_replace( '~<div id="lucidlab-mpc-rename-job-controls">.*?</div>~s', '', $html );
				$html = preg_replace( ( '~<p><label>' . esc_html__( 'Naming pattern.*?', 'lucidlab-mpc-cleanup' ) . '</p>~s' ), '', $html );
				$html = preg_replace( '~<div class="lucidlab-mpc-rename-pattern".*?</div>~s', '', $html );
				$html = preg_replace( ( '~<p class="lucidlab-mpc-hint">' . esc_html__( 'Tags:.*?', 'lucidlab-mpc-cleanup' ) . '</p>~s' ), '', $html );
				$html = preg_replace( '~<button\b[^>]*(?:data-mpc-rename-all|data-rename-job|id="lucidlab-mpc-rename-pattern-all")[^>]*>.*?</button>~s', '', $html );
			}echo $html; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buffered admin markup assembled from escaped fragments. */ }
	}



	/* ---------- Tab 8 (WebP / AVIF) ---------- */

	/* ---------- Tab 4b (Shared images) ---------- */

	/**
	 * AJAX handler: scan product copy.
	 */
	private function ajax_scan_product_copy(): void {
		if ( ! post_type_exists( 'product' ) ) {
			$this->task_error( array( 'message' => __( 'WooCommerce products are not available.', 'lucidlab-mpc-cleanup' ) ), 400 );
		}
		$key   = $this->k_state( 'product_copy' );
		$state = get_transient( $key );
		if ( ! empty( $this->req['reset'] ) ) {
			$state = array(
				'offset'                => 0,
				'items'                 => array(),
				'include_empty_gallery' => ! empty( $this->req['include_empty_gallery'] ),
			);
		}
		if ( ! is_array( $state ) ) {
			$this->task_error( array( 'message' => __( 'Scan session expired. Start a new scan.', 'lucidlab-mpc-cleanup' ) ), 400 );
		}
		global $wpdb;
		$types = array( 'product' );
		if ( post_type_exists( 'product_variation' ) ) {
			$types[] = 'product_variation';
		}
		$type_placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		if ( ! isset( $state['total'] ) ) {
			$state['total'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($type_placeholders) AND post_status='publish'", $types ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		}
		$batch    = 25;
		$deadline = microtime( true ) + 2.0;
		$done     = false;
		do {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($type_placeholders) AND post_status='publish' ORDER BY ID ASC LIMIT %d OFFSET %d", array_merge( $types, array( $batch, (int) $state['offset'] ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- IN() placeholder list built with array_fill(); values are passed to $wpdb->prepare(); Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			foreach ( (array) $ids as $id ) {
				$row = $this->product_copy_gap( (int) $id, ! empty( $state['include_empty_gallery'] ) );
				if ( $row ) {
					$state['items'][ (int) $id ] = $row;
				}
			}
			$state['offset'] += count( (array) $ids );
			$done             = count( (array) $ids ) < $batch;
		} while ( ! $done && microtime( true ) < $deadline );
		set_transient( $key, $state, $this->task_capture ? MONTH_IN_SECONDS : DAY_IN_SECONDS );
		if ( $done ) {
			$items = array_values( (array) $state['items'] );
			usort( $items, static fn( $a, $b ) => strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) ) );
			$this->set_scan_result(
				'product_copy',
				array(
					'type'                  => 'product_copy',
					'items'                 => $items,
					'checked'               => (int) $state['offset'],
					'products_missing'      => count( $items ),
					'include_empty_gallery' => ! empty( $state['include_empty_gallery'] ),
				)
			);
			delete_transient( $key );
		}
		$this->task_success(
			array(
				'done'     => $done,
				'progress' => $done ? 100 : min( 99, (int) floor( 100 * (int) $state['offset'] / max( 1, (int) $state['total'] ) ) ),
				'label'    => sprintf(
					/* translators: 1: products checked, 2: products in the catalog, 3: products missing an image */
					__( '%1$d of %2$d published products checked; %3$d missing an image', 'lucidlab-mpc-cleanup' ),
					(int) $state['offset'],
					max( (int) $state['offset'], (int) $state['total'] ),
					count( (array) $state['items'] )
				),
			)
		);
	}

	/**
	 * Product copy gap.
	 *
	 * @param int  $id                    Id.
	 * @param bool $include_empty_gallery Include empty gallery.
	 *
	 * @return array<string,mixed>
	 */
	private function product_copy_gap( int $id, bool $include_empty_gallery ): array {
		$post = get_post( $id );
		if ( ! $post ) {
			return array();
		}
		$thumb        = (int) get_post_meta( $id, '_thumbnail_id', true );
		$file_state   = $this->product_copy_file_state( $thumb );
		$is_variation = 'product_variation' === $post->post_type;
		$reasons      = array();
		if ( $is_variation ) {
			if ( $thumb < 1 || 'ok' === $file_state ) {
				return array();
			}
			$reasons[] = 'file' === $file_state ? 'featured_file' : 'featured_gone';
		} else {
			if ( 'none' === $file_state ) {
				$reasons[] = 'no_featured';
			} elseif ( 'gone' === $file_state ) {
				$reasons[] = 'featured_gone';
			} elseif ( 'file' === $file_state ) {
				$reasons[] = 'featured_file';
			}
			$gallery = array_slice( array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $id, '_product_image_gallery', true ) ) ) ), 0, 30 );
			foreach ( $gallery as $gallery_id ) {
				if ( 'ok' !== $this->product_copy_file_state( $gallery_id ) ) {
					$reasons[] = 'gallery_file';
					break;
				}
			}
			if ( $include_empty_gallery && ! $gallery && 'ok' === $file_state ) {
				$reasons[] = 'empty_gallery';
			}
		}
		if ( ! $reasons ) {
			return array();
		}
		$title = (string) $post->post_title;
		if ( $is_variation ) {
			$parent = $post->post_parent ? get_the_title( (int) $post->post_parent ) : '';
			/* translators: %s is the title of the parent product. */
			$title = '' !== $parent ? sprintf( __( 'Variation of %s', 'lucidlab-mpc-cleanup' ), $parent ) : __( 'Variation', 'lucidlab-mpc-cleanup' );
			if ( '' !== (string) $post->post_title ) {
				$title .= ' — ' . $post->post_title;
			}
		}
		return array(
			'id'      => $id,
			'title'   => $title,
			'type'    => (string) $post->post_type,
			'reasons' => $reasons,
			'edit'    => (string) get_edit_post_link( $id, 'raw' ),
			'view'    => (string) get_permalink( $id ),
		);
	}

	/**
	 * Product copy file state.
	 *
	 * @param int $id Id.
	 *
	 * @return string
	 */
	private function product_copy_file_state( int $id ): string {
		if ( $id < 1 ) {
			return 'none';
		}
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $id ) ) {
			return 'gone';
		}
		$file = LucidLab_MPC_Helpers::attached_file_path( $id );
		if ( ! $file || ! is_file( $file ) ) {
			return 'file';
		}
		return 'ok';
	}

	/**
	 * Product copy reason label.
	 *
	 * @param string $code Code.
	 *
	 * @return string
	 */
	private function product_copy_reason_label( string $code ): string {
		$labels = array(
			'no_featured'   => __( 'No featured image', 'lucidlab-mpc-cleanup' ),
			'featured_gone' => __( 'Featured image record is gone', 'lucidlab-mpc-cleanup' ),
			'featured_file' => __( 'Featured image file is missing', 'lucidlab-mpc-cleanup' ),
			'gallery_file'  => __( 'A gallery image is missing', 'lucidlab-mpc-cleanup' ),
			'empty_gallery' => __( 'Featured image only, gallery is empty', 'lucidlab-mpc-cleanup' ),
		);
		return $labels[ $code ] ?? $code;
	}

	/**
	 * Product copy builder locked.
	 *
	 * @param WP_Post $post Post.
	 *
	 * @return bool
	 */
	private function product_copy_builder_locked( WP_Post $post ): bool {
		if ( get_post_meta( $post->ID, '_elementor_data', true ) ) {
			return true;
		}
		return (bool) preg_match( '/\[(ux_|uxbuilder|flatsome_)/', (string) $post->post_content );
	}

	/**
	 * AJAX handler: product copy.
	 *
	 * @param string $op Op.
	 */
	private function ajax_product_copy( string $op ): void {
		if ( ! LucidLab_MPC_Edition::pro() ) {
			wp_send_json_error( array( 'message' => __( 'Product AI requires the Pro add-on.', 'lucidlab-mpc-cleanup' ) ), 403 );
		}
		if ( 'product_copy_about' === $op || ( 'product_copy_suggest' === $op && isset( $this->req['site_about'] ) ) ) {
			LucidLab_MPC_AI::save_site_about( sanitize_textarea_field( wp_unslash( $this->req['site_about'] ?? '' ) ) );
			if ( 'product_copy_about' === $op ) {
				wp_send_json_success( array( 'site_about' => LucidLab_MPC_AI::site_about() ) );
			}
		}
		$id   = absint( $this->req['product_id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post || 'product' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'That product cannot be edited.', 'lucidlab-mpc-cleanup' ) ), 403 );
		}
		if ( 'product_copy_undo' === $op ) {
			$this->ajax_product_copy_undo( $post );
		}
		if ( 'product_copy_apply' === $op ) {
			$this->ajax_product_copy_apply( $post );
		}
		$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
		if ( 'ok' !== $this->product_copy_file_state( $thumb ) ) {
			wp_send_json_error( array( 'message' => __( 'This product has no usable featured image to read.', 'lucidlab-mpc-cleanup' ) ), 400 );
		}
		$result = LucidLab_MPC_AI::suggest_product_copy( $thumb );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		$result['fingerprint']   = LucidLab_MPC_AI::product_copy_fingerprint( $thumb );
		$result['attachment_id'] = $thumb;
		$result['suggested_at']  = time();
		update_post_meta( $id, '_lucidlab_mpc_product_copy', $result );
		$this->product_copy_remember( $id );
		wp_send_json_success(
			array(
				'title'             => (string) $result['title'],
				'short_description' => (string) $result['short_description'],
				'description'       => (string) $result['description'],
				'reasons'           => (array) $result['reasons'],
				'message'           => sprintf(
					/* translators: %s: confidence from 0 to 1 */
					__( 'Suggestion ready. Confidence %s. Nothing is saved until you apply a field.', 'lucidlab-mpc-cleanup' ),
					number_format_i18n( (float) $result['confidence'], 2 )
				),
			)
		);
	}

	/**
	 * AJAX handler: product copy apply.
	 *
	 * @param WP_Post $post Post.
	 */
	private function ajax_product_copy_apply( WP_Post $post ): void {
		$fields = array(
			'title' => ! empty( $this->req['apply_title'] ),
			'short' => ! empty( $this->req['apply_short'] ),
			'long'  => ! empty( $this->req['apply_long'] ),
		);
		if ( ! in_array( true, $fields, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose at least one field to apply.', 'lucidlab-mpc-cleanup' ) ), 400 );
		}
		$locked = $this->product_copy_builder_locked( $post );
		if ( $fields['long'] && $locked ) {
			wp_send_json_error( array( 'message' => __( 'This long description is stored by a page builder. Apply the title and short description here, and edit the long description in the product editor.', 'lucidlab-mpc-cleanup' ) ), 400 );
		}
		$incoming = array(
			'title' => sanitize_text_field( wp_unslash( $this->req['title'] ?? '' ) ),
			'short' => sanitize_textarea_field( wp_unslash( $this->req['short_description'] ?? '' ) ),
			'long'  => wp_kses_post( wp_unslash( $this->req['description'] ?? '' ) ),
		);
		$update   = array( 'ID' => $post->ID );
		$undo     = array(
			'at'     => time(),
			'fields' => array(),
		);
		$current  = array(
			'title' => (string) $post->post_title,
			'short' => (string) $post->post_excerpt,
			'long'  => (string) $post->post_content,
		);
		$map      = array(
			'title' => 'post_title',
			'short' => 'post_excerpt',
			'long'  => 'post_content',
		);
		foreach ( $map as $key => $column ) {
			if ( empty( $fields[ $key ] ) ) {
				continue;
			}
			$text = trim( $incoming[ $key ] );
			if ( '' === $text ) {
				wp_send_json_error( array( 'message' => __( 'Enter text before applying a field.', 'lucidlab-mpc-cleanup' ) ), 400 );
			}
			if ( $text === $current[ $key ] ) {
				continue;
			}
			$update[ $column ]      = $text;
			$undo['fields'][ $key ] = array(
				'previous' => $current[ $key ],
				'new'      => $text,
			);
		}
		if ( count( $update ) < 2 ) {
			wp_send_json_success( array_merge( $this->product_copy_current_payload( $post ), array( 'message' => __( 'Skipped: those fields already match.', 'lucidlab-mpc-cleanup' ) ) ) );
		}
		$updated = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $updated ) ) {
			wp_send_json_error( array( 'message' => $updated->get_error_message() ), 400 );
		}
		update_post_meta( $post->ID, '_lucidlab_mpc_product_copy_undo', $undo );
		clean_post_cache( $post->ID );
		$fresh = get_post( $post->ID );
		wp_send_json_success( array_merge( $this->product_copy_current_payload( $fresh instanceof WP_Post ? $fresh : $post ), array( 'message' => __( 'Saved the fields you checked. The other fields were left as they were.', 'lucidlab-mpc-cleanup' ) ) ) );
	}

	/**
	 * AJAX handler: product copy undo.
	 *
	 * @param WP_Post $post Post.
	 */
	private function ajax_product_copy_undo( WP_Post $post ): void {
		$undo = get_post_meta( $post->ID, '_lucidlab_mpc_product_copy_undo', true );
		if ( ! is_array( $undo ) || empty( $undo['fields'] ) || ! is_array( $undo['fields'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No saved product copy change is available to undo.', 'lucidlab-mpc-cleanup' ) ), 400 );
		}
		$current = array(
			'title' => (string) $post->post_title,
			'short' => (string) $post->post_excerpt,
			'long'  => (string) $post->post_content,
		);
		$map     = array(
			'title' => 'post_title',
			'short' => 'post_excerpt',
			'long'  => 'post_content',
		);
		$update  = array( 'ID' => $post->ID );
		foreach ( $undo['fields'] as $key => $entry ) {
			if ( ! isset( $map[ $key ] ) || ! is_array( $entry ) ) {
				continue;
			}
			if ( (string) ( $entry['new'] ?? '' ) !== $current[ $key ] ) {
				wp_send_json_error( array( 'message' => __( 'The product text changed after this suggestion was applied. Review it in the product editor before undoing.', 'lucidlab-mpc-cleanup' ) ), 409 );
			}
			$update[ $map[ $key ] ] = (string) ( $entry['previous'] ?? '' );
		}
		if ( count( $update ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'No saved product copy change is available to undo.', 'lucidlab-mpc-cleanup' ) ), 400 );
		}
		$updated = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $updated ) ) {
			wp_send_json_error( array( 'message' => $updated->get_error_message() ), 400 );
		}
		delete_post_meta( $post->ID, '_lucidlab_mpc_product_copy_undo' );
		clean_post_cache( $post->ID );
		$fresh = get_post( $post->ID );
		wp_send_json_success( array_merge( $this->product_copy_current_payload( $fresh instanceof WP_Post ? $fresh : $post ), array( 'message' => __( 'Restored the previous text.', 'lucidlab-mpc-cleanup' ) ) ) );
	}

	/**
	 * Product copy current payload.
	 *
	 * @param WP_Post $post Post.
	 *
	 * @return array<string,string>
	 */
	private function product_copy_current_payload( WP_Post $post ): array {
		return array(
			'current_title' => (string) $post->post_title,
			'current_short' => (string) $post->post_excerpt,
			'current_long'  => wp_strip_all_tags( (string) $post->post_content ),
		);
	}

	/**
	 * Render product copy.
	 */
	private function render_product_copy(): void {
		if ( ! post_type_exists( 'product' ) ) {
			echo '<p class="notice notice-info inline lucidlab-mpc-job-summary">' . esc_html__( 'Product AI needs WooCommerce.', 'lucidlab-mpc-cleanup' ) . '</p>';
			return;
		}
		echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'Use a product featured image to suggest a title, short description and long description. Review each field before applying it; existing product text stays unchanged until you approve it.', 'lucidlab-mpc-cleanup' ) . '</p>';
		$this->render_product_copy_ai();
		$this->render_product_copy_recent();
	}

	/**
	 * Render product copy gaps.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	private function render_product_copy_gaps( array $data ): void {
		$items = is_array( $data['items'] ?? null ) ? $data['items'] : array();
		if ( ! $items ) {
			echo '<p class="lucidlab-mpc-empty lucidlab-mpc-good"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'No published products are missing an image.', 'lucidlab-mpc-cleanup' ) . '</p>';
			return;
		}
		$page  = $this->results_page( $items, 'lucidlab_mpc_copy_page' );
		$items = $page['items'];
		echo '<table class="widefat lucidlab-mpc-table"><thead><tr><th>' . esc_html__( 'Product', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Why it is listed', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Open', 'lucidlab-mpc-cleanup' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			echo '<tr><td>' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</td><td>';
			foreach ( (array) ( $item['reasons'] ?? array() ) as $reason ) {
				echo '<div>' . esc_html( $this->product_copy_reason_label( (string) $reason ) ) . '</div>';
			}
			echo '</td><td class="lucidlab-mpc-missing-usage-actions">';
			if ( ! empty( $item['view'] ) ) {
				echo '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( (string) $item['view'] ) . '">' . esc_html__( 'View', 'lucidlab-mpc-cleanup' ) . '</a> ';
			}
			if ( ! empty( $item['edit'] ) ) {
				echo '<a class="button button-small" target="_blank" rel="noopener noreferrer" href="' . esc_url( (string) $item['edit'] ) . '">' . esc_html__( 'Edit', 'lucidlab-mpc-cleanup' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$this->render_results_pager( $page, 'product_copy', 'lucidlab_mpc_copy_page', __( 'products', 'lucidlab-mpc-cleanup' ) );
	}

	/**
	 * Render product copy ai.
	 */
	private function render_product_copy_ai(): void {
		global $wpdb;
		$search = sanitize_text_field( wp_unslash( $_GET['lucidlab_mpc_copy_search'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
		$view   = 'suggested' === sanitize_text_field( wp_unslash( $_GET['lucidlab_mpc_copy_view'] ?? '' ) ) ? 'suggested' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
		$paged  = max( 1, absint( $_GET['lucidlab_mpc_copy_ai_page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
		$where  = "p.post_type='product' AND p.post_status='publish'";
		$args   = array();
		if ( '' !== $search ) {
			$where .= ' AND p.post_title LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$join = "FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} t ON t.post_id=p.ID AND t.meta_key='_thumbnail_id' AND CAST(t.meta_value AS UNSIGNED) > 0";
		if ( 'suggested' === $view ) {
			$where .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id=p.ID AND s.meta_key IN ('_lucidlab_mpc_product_copy'))";
		} elseif ( '' === $search ) {
			$where .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id=p.ID AND s.meta_key IN ('_lucidlab_mpc_product_copy'))";
		}
		$join .= " WHERE $where";
		$count = "SELECT COUNT(*) $join";
		$list  = "SELECT p.ID $join ORDER BY p.ID DESC LIMIT 10 OFFSET %d";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count, $args ) : $count ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where placeholders are passed to prepare() when a search is present; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$pages = max( 1, (int) ceil( $total / 10 ) );
		$paged = min( $pages, $paged );
		$ids   = $wpdb->get_col( $wpdb->prepare( $list, array_merge( $args, array( ( $paged - 1 ) * 10 ) ) ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $list uses the same prepared fragments; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		$ready = LucidLab_MPC_AI::configured();
		$this->begin_results_card( ' data-mpc-results-section="product_copy_ai"' );
		echo '<h2 class="lucidlab-mpc-card__title" id="lucidlab-mpc-product-copy">' . esc_html__( 'Title and description from the image', 'lucidlab-mpc-cleanup' ) . '</h2>';
		/* translators: %s is the language used for AI-generated suggestions. */
		echo '<p class="lucidlab-mpc-hint">' . esc_html( sprintf( __( 'Suggestions are written in %s. They stay on this page until you apply a field. The current title and descriptions are not changed until then. The picture and the website description below are sent to the AI provider you set in Options.', 'lucidlab-mpc-cleanup' ), LucidLab_MPC_AI::ui_language_name( LucidLab_MPC_AI::output_locale() ) ) ) . '</p>';
		echo '<label class="lucidlab-mpc-copy-about" for="lucidlab-mpc-copy-about"><span class="lucidlab-mpc-copy-label">' . esc_html__( 'What is this website about?', 'lucidlab-mpc-cleanup' ) . '</span>';
		echo '<textarea id="lucidlab-mpc-copy-about" rows="3" maxlength="800">' . esc_textarea( LucidLab_MPC_AI::site_about() ) . '</textarea>';
		echo '<span class="lucidlab-mpc-hint">' . esc_html__( 'A short note so the AI can focus the title and descriptions on what this shop sells. It is saved here and sent with the photo. It is not written onto the product.', 'lucidlab-mpc-cleanup' ) . '</span></label>';
		if ( ! $ready ) {
			echo '<p class="notice notice-info inline lucidlab-mpc-job-summary">' . esc_html__( 'Choose an AI provider in Options before suggesting copy.', 'lucidlab-mpc-cleanup' ) . ' <a href="' . esc_url( $this->tab_url( 'options' ) ) . '">' . esc_html__( 'Open Options', 'lucidlab-mpc-cleanup' ) . '</a></p>';
		}
		echo '<form method="get" class="lucidlab-mpc-copy-toolbar"><input type="hidden" name="page" value="lucidlab-mpc-cleanup"><input type="hidden" name="tab" value="product_copy"><span class="lucidlab-mpc-copy-toolbar-row"><input type="search" name="lucidlab_mpc_copy_search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search product title', 'lucidlab-mpc-cleanup' ) . '">';
		echo '<label class="screen-reader-text" for="lucidlab-mpc-copy-view">' . esc_html__( 'Which products to list', 'lucidlab-mpc-cleanup' ) . '</label>';
		echo '<select id="lucidlab-mpc-copy-view" name="lucidlab_mpc_copy_view" onchange="this.form.submit()"><option value="">' . esc_html__( 'All products', 'lucidlab-mpc-cleanup' ) . '</option><option value="suggested"' . selected( $view, 'suggested', false ) . '>' . esc_html__( 'Last suggestions', 'lucidlab-mpc-cleanup' ) . '</option></select>';
		echo '<button class="button">' . esc_html__( 'Search', 'lucidlab-mpc-cleanup' ) . '</button> <button type="button" class="button" data-mpc-copy-suggest-selected' . ( $ready ? '' : ' disabled' ) . '>' . esc_html__( 'Suggest for selected', 'lucidlab-mpc-cleanup' ) . '</button> <button type="button" class="button" data-mpc-copy-suggest-page' . ( $ready && $ids ? '' : ' disabled' ) . '>' . esc_html__( 'Suggest all', 'lucidlab-mpc-cleanup' ) . '</button></span><span class="lucidlab-mpc-hint">' . esc_html__( 'Last suggestions lists products that already have a suggestion, so you can ask the AI again. A product that already has a suggestion leaves this list. It stays in Recent suggestions below, and the full product comes back here when you search or choose Last suggestions. Suggest all covers only the products on this page. Apply checked fields on each product saves the title and descriptions.', 'lucidlab-mpc-cleanup' ) . '</span></form>';
		if ( ! $ids ) {
			echo '<p class="lucidlab-mpc-empty">' . esc_html__( 'No published products with a usable featured image match this search.', 'lucidlab-mpc-cleanup' ) . '</p>';
			return;
		}
		foreach ( (array) $ids as $id ) {
			$this->render_product_copy_card( (int) $id, $ready );
		}
		$page_url = add_query_arg(
			array(
				'lucidlab_mpc_copy_search' => $search,
				'lucidlab_mpc_copy_view'   => $view,
			),
			$this->tab_url( 'product_copy' )
		);
		echo '<nav class="lucidlab-mpc-pager" aria-label="' . esc_attr__( 'Product AI pages', 'lucidlab-mpc-cleanup' ) . '">';
		if ( $paged > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'lucidlab_mpc_copy_ai_page', $paged - 1, $page_url ) ) . '">' . esc_html__( 'Previous', 'lucidlab-mpc-cleanup' ) . '</a> ';
		}
		/* translators: 1: current result page, 2: total pages, 3: total products. */
		echo esc_html( sprintf( __( 'Page %1$d of %2$d — %3$d products', 'lucidlab-mpc-cleanup' ), $paged, $pages, $total ) );
		if ( $paged < $pages ) {
			echo ' <a class="button" href="' . esc_url( add_query_arg( 'lucidlab_mpc_copy_ai_page', $paged + 1, $page_url ) ) . '">' . esc_html__( 'Next', 'lucidlab-mpc-cleanup' ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Render product copy card.
	 *
	 * @param int  $id    Id.
	 * @param bool $ready Ready.
	 */
	private function render_product_copy_card( int $id, bool $ready ): void {
		$post = get_post( $id );
		if ( ! $post ) {
			return;
		}
		$thumb     = (int) get_post_meta( $id, '_thumbnail_id', true );
		$usable    = 'ok' === $this->product_copy_file_state( $thumb );
		$saved     = get_post_meta( $id, '_lucidlab_mpc_product_copy', true );
		$fresh     = $usable && is_array( $saved ) && ( (string) ( $saved['fingerprint'] ?? '' ) === LucidLab_MPC_AI::product_copy_fingerprint( $thumb ) );
		$locked    = $this->product_copy_builder_locked( $post );
		$suggest   = $fresh ? $saved : array();
		$thumb_url = $this->product_copy_thumb_url( $thumb, $usable );
		$this->begin_results_card( ' data-product-id="' . (int) $id . '"' );
		echo '<article class="lucidlab-mpc-copy-card" data-product-id="' . (int) $id . '" data-copy-locked="' . ( $locked ? '1' : '0' ) . '">';
		echo '<div class="lucidlab-mpc-copy-media">';
		echo '<label class="lucidlab-mpc-copy-pick-label"><input type="checkbox" class="lucidlab-mpc-copy-pick"' . ( $ready && $usable ? '' : ' disabled' ) . '> ' . esc_html__( 'Select', 'lucidlab-mpc-cleanup' ) . '</label>';
		if ( $thumb_url ) {
			echo '<img src="' . esc_url( $thumb_url ) . '" alt="">';
		} else {
			echo '<span class="lucidlab-mpc-thumb lucidlab-mpc-thumb-none dashicons dashicons-format-image"></span>';
		}
		echo '</div><div class="lucidlab-mpc-copy-body">';
		echo '<h3>' . esc_html( get_the_title( $id ) ) . '</h3>';
		if ( ! $usable ) {
			echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'The featured image file is missing. This product belongs in the list above.', 'lucidlab-mpc-cleanup' ) . '</p></div></article>';
			return;
		}
		if ( is_array( $saved ) && ! $fresh ) {
			echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'The image changed since this suggestion. Suggest again before applying.', 'lucidlab-mpc-cleanup' ) . '</p>';
		}
		$fields = array(
			'title' => array( __( 'Current title', 'lucidlab-mpc-cleanup' ), __( 'Suggested title', 'lucidlab-mpc-cleanup' ), (string) $post->post_title, (string) ( $suggest['title'] ?? '' ) ),
			'short' => array( __( 'Current short description', 'lucidlab-mpc-cleanup' ), __( 'Suggested short description', 'lucidlab-mpc-cleanup' ), (string) $post->post_excerpt, (string) ( $suggest['short_description'] ?? '' ) ),
			'long'  => array( __( 'Current long description', 'lucidlab-mpc-cleanup' ), __( 'Suggested long description', 'lucidlab-mpc-cleanup' ), wp_strip_all_tags( (string) $post->post_content ), (string) ( $suggest['description'] ?? '' ) ),
		);
		foreach ( $fields as $key => $field ) {
			$disabled = ( 'long' === $key && $locked ) || ! $fresh;
			echo '<div class="lucidlab-mpc-copy-field">';
			echo '<div><span class="lucidlab-mpc-copy-label">' . esc_html( $field[0] ) . '</span><p class="lucidlab-mpc-copy-current" data-mpc-copy-current="' . esc_attr( $key ) . '">' . esc_html( $field[2] ) . '</p>';
			if ( '' === trim( (string) $field[2] ) ) {
				echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'Nothing is stored in this WooCommerce field.', 'lucidlab-mpc-cleanup' ) . '</p>';
			}
			echo '</div>';
			echo '<div><label class="lucidlab-mpc-copy-suggested"><span class="lucidlab-mpc-copy-label">' . esc_html( $field[1] ) . '</span>';
			echo '<textarea data-mpc-copy-suggest="' . esc_attr( $key ) . '"' . ( $disabled ? ' readonly' : '' ) . '>' . esc_textarea( $field[3] ) . '</textarea></label>';
			if ( 'long' === $key && $locked ) {
				echo '<span class="lucidlab-mpc-hint">' . esc_html__( 'This description is stored by a page builder, so it is not replaced from here.', 'lucidlab-mpc-cleanup' ) . '</span>';
			} else {
				echo '<label class="lucidlab-mpc-copy-apply"><input type="checkbox" data-mpc-copy-apply="' . esc_attr( $key ) . '"' . ( $disabled ? ' disabled' : '' ) . '> ' . esc_html__( 'Apply this field', 'lucidlab-mpc-cleanup' ) . '</label>';
			}
			echo '</div></div>';
		}
		echo '<p class="lucidlab-mpc-btn-row"><button type="button" class="button" data-mpc-copy-suggest' . ( $ready ? '' : ' disabled' ) . '>' . esc_html__( 'Suggest from image', 'lucidlab-mpc-cleanup' ) . '</button> ';
		echo '<button type="button" class="button button-primary" data-mpc-copy-apply-fields' . ( $fresh ? '' : ' disabled' ) . '>' . esc_html__( 'Apply checked fields', 'lucidlab-mpc-cleanup' ) . '</button> ';
		echo '<button type="button" class="button" data-mpc-copy-undo>' . esc_html__( 'Undo last apply', 'lucidlab-mpc-cleanup' ) . '</button></p>';
		echo '<p class="lucidlab-mpc-copy-note lucidlab-mpc-hint" role="status"></p>';
		echo '</div></article>';
	}

	/**
	 * Product copy thumb url.
	 *
	 * @param int  $thumb  Thumb.
	 * @param bool $usable Usable.
	 *
	 * @return string
	 */
	private function product_copy_thumb_url( int $thumb, bool $usable ): string {
		if ( ! $usable || $thumb < 1 ) {
			return '';
		}
		$url = (string) wp_get_attachment_image_url( $thumb, 'thumbnail' );
		if ( '' !== $url ) {
			return $url;
		}
		$path = LucidLab_MPC_Helpers::attached_file_path( $thumb );
		$base = trailingslashit( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ) );
		if ( '' === $path || ! str_starts_with( strtolower( $path ), strtolower( $base ) ) ) {
			return '';
		}
		$uploads = wp_upload_dir();
		return trailingslashit( (string) $uploads['baseurl'] ) . substr( $path, strlen( $base ) );
	}

	/**
	 * Product copy suggestions saved.
	 *
	 * @return bool
	 */
	private function product_copy_suggestions_saved(): bool {
		$ids = get_option( 'lucidlab_mpc_product_copy_recent', array() );
		return is_array( $ids ) && (bool) array_filter( array_map( 'absint', $ids ) );
	}

	/**
	 * Product copy remember.
	 *
	 * @param int $id Id.
	 */
	private function product_copy_remember( int $id ): void {
		$ids = get_option( 'lucidlab_mpc_product_copy_recent', array() );
		if ( ! is_array( $ids ) ) {
			$ids = array();
		}
		$ids = array_values( array_unique( array_merge( array( $id ), array_map( 'absint', $ids ) ) ) );
		update_option( 'lucidlab_mpc_product_copy_recent', array_slice( $ids, 0, 50 ), false );
	}

	/**
	 * Render product copy recent.
	 */
	private function render_product_copy_recent(): void {
		global $wpdb;
		$ids = get_option( 'lucidlab_mpc_product_copy_recent', array() );
		$ids = is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
		if ( ! $ids ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type='product' AND p.post_status='publish' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key IN ('_lucidlab_mpc_product_copy')) ORDER BY p.ID DESC LIMIT 20" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		}
		$ids = array_slice( $ids, 0, 20 );
		$this->begin_results_card( ' data-mpc-results-section="product_copy_recent"' );
		echo '<section class="lucidlab-mpc-copy-recent" id="lucidlab-mpc-copy-recent">';
		echo '<h2 class="lucidlab-mpc-card__title">' . esc_html__( 'Recent suggestions', 'lucidlab-mpc-cleanup' ) . '</h2>';
		echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'Products that already received a suggestion. The photo stays in this list. Undo last apply is at the end of each row.', 'lucidlab-mpc-cleanup' ) . '</p>';
		$shown = 0;
		foreach ( $ids as $id ) {
			$post  = get_post( $id );
			$saved = get_post_meta( $id, '_lucidlab_mpc_product_copy', true );
			if ( ! $post || 'product' !== $post->post_type || ! is_array( $saved ) ) {
				continue;
			}
			$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
			$url   = $this->product_copy_thumb_url( $thumb, 'ok' === $this->product_copy_file_state( $thumb ) );
			$title = (string) ( $saved['title'] ?? '' );
			echo '<article class="lucidlab-mpc-copy-recent-row" data-product-id="' . (int) $id . '">';
			if ( $url ) {
				echo '<img src="' . esc_url( $url ) . '" alt="">';
			} else {
				echo '<span class="lucidlab-mpc-thumb lucidlab-mpc-thumb-none dashicons dashicons-format-image"></span>';
			}
			echo '<div class="lucidlab-mpc-copy-recent-copy"><strong>' . esc_html( get_the_title( $id ) ) . '</strong>';
			if ( '' !== $title ) {
				echo '<span class="lucidlab-mpc-hint">' . esc_html( $title ) . '</span>';
			}
			echo '<p class="lucidlab-mpc-copy-note lucidlab-mpc-hint" role="status"></p></div>';
			echo '<button type="button" class="button" data-mpc-copy-undo>' . esc_html__( 'Undo last apply', 'lucidlab-mpc-cleanup' ) . '</button>';
			echo '</article>';
			++$shown;
		}
		if ( ! $shown ) {
			echo '<p class="lucidlab-mpc-empty">' . esc_html__( 'No suggestions yet. Use Suggest from image on a product above.', 'lucidlab-mpc-cleanup' ) . '</p>';
		}
		echo '</section>';
	}

	/**
	 * AJAX handler: scan missing.
	 */
	private function ajax_scan_missing(): void {
		$key  = $this->k_state( 'missing' );
		$file = $this->missing_ref_file();
		if ( ! empty( $this->req['reset'] ) ) {
			delete_transient( $key );
			$this->delete_scan_result( 'missing' );
			$refs  = LucidLab_MPC_Reference_Index::begin( $file );
			$state = array(
				'phase' => 'done' === ( $refs['phase'] ?? '' ) ? 'assemble' : 'refs',
				'refs'  => $refs,
			);
			if ( 'assemble' === $state['phase'] ) {
				$state['records_checked'] = LucidLab_MPC_Reference_Index::covered_records( $refs, $file );
				unset( $state['refs'] );
			}
			set_transient( $key, $state, $this->state_ttl() );
			$this->task_success(
				array_merge(
					$this->reference_index_start( $refs ),
					array(
						'done'  => false,
						'label' => 'assemble' === $state['phase'] ? __( 'Reusing recent reference index…', 'lucidlab-mpc-cleanup' ) : __( 'Indexing references…', 'lucidlab-mpc-cleanup' ),
					)
				)
			);
		}
		$state = get_transient( $key );
		if ( ! is_array( $state ) || empty( $state['phase'] ) ) {
			$this->task_error( array( 'message' => __( 'Scan session expired. Start a new scan.', 'lucidlab-mpc-cleanup' ) ), 400 );}
		if ( 'refs' === $state['phase'] ) {
			$ran = LucidLab_MPC_Reference_Index::label_phase( (array) ( $state['refs'] ?? array() ) );
			LucidLab_MPC_Reference_Index::pump( $state['refs'] );
			$assemble = 'done' === ( $state['refs']['phase'] ?? '' );
			if ( $assemble ) {
				$state['records_checked'] = LucidLab_MPC_Reference_Index::covered_records( (array) ( $state['refs'] ?? array() ), $file );
				$state['phase']           = 'assemble';
				unset( $state['refs'] );
			}
			set_transient( $key, $state, $this->state_ttl() );
			$this->task_success( array_merge( $this->reference_index_tick( (array) ( $state['refs'] ?? array() ), $ran, $assemble ), array( 'done' => false ) ) );
		}
		$found           = $this->shared->from_reference( $file, false, true );
		$urls            = array_values( (array) ( $found['broken_urls'] ?? array() ) );
		$records_checked = (int) ( $state['records_checked'] ?? 0 );
		if ( $records_checked < 1 ) {
			$records_checked = LucidLab_MPC_Work::count_lines( $file );}
		if ( $records_checked < 1 ) {
			$records_checked = (int) ( $found['image_count'] ?? count( (array) ( $found['images'] ?? array() ) ) );}
		$missing_result = array(
			'type'        => 'missing',
			'images'      => array_values( (array) ( $found['images'] ?? array() ) ),
			'urls'        => $urls,
			'checked'     => $records_checked,
			'missing'     => count( (array) ( $found['images'] ?? array() ) ),
			'broken_urls' => count( $urls ),
		);
		$this->set_scan_result( 'missing', $missing_result );
		update_option(
			'lucidlab_mpc_summary_missing',
			array(
				'updated' => time(),
				'counts'  => array(
					'checked'     => $missing_result['checked'],
					'missing'     => $missing_result['missing'],
					'broken_urls' => $missing_result['broken_urls'],
				),
			),
			false
		);
		delete_transient( $key );
		LucidLab_MPC_Work::delete( $file );
		$this->task_success(
			array(
				'done'     => true,
				'progress' => 100,
				'label'    => sprintf( /* translators: 1: content records checked, 2: missing attachments, 3: broken content URLs. */ __( '%1$s content records checked; %2$s missing attachments, %3$s broken content URLs', 'lucidlab-mpc-cleanup' ), number_format_i18n( $missing_result['checked'] ), number_format_i18n( $missing_result['missing'] ), number_format_i18n( $missing_result['broken_urls'] ) ),
			)
		);
	}

	/**
	 * One Usage block (title + View/Edit) for Missing Images Used-by cells.
	 *
	 * @param array $item Item.
	 */
	private function render_missing_usage_item( array $item ): void {
		echo '<div class="lucidlab-mpc-missing-usage">';
		echo '<div class="lucidlab-mpc-missing-usage-title">' . esc_html( (string) ( $item['title'] ?? '' ) . ' — ' . (string) ( $item['usage'] ?? '' ) ) . '</div>';
		echo '<div class="lucidlab-mpc-missing-usage-actions">';
		if ( ! empty( $item['view'] ) ) {
			echo '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( (string) $item['view'] ) . '">' . esc_html__( 'View', 'lucidlab-mpc-cleanup' ) . '</a>';
		}
		if ( ! empty( $item['edit'] ) ) {
			echo '<a class="button button-small" target="_blank" rel="noopener noreferrer" href="' . esc_url( (string) $item['edit'] ) . '">' . esc_html__( 'Edit', 'lucidlab-mpc-cleanup' ) . '</a>';
		}
		echo '</div></div>';
	}

	/**
	 * Used-by cell for Missing Images: single ref inline; multiple refs use Shared Images Show N / Hide toggle.
	 *
	 * @param array<int,array<string,mixed>> $products Products.
	 */
	private function render_missing_used_by( array $products ): void {
		$products = array_values( array_filter( $products, 'is_array' ) );
		$count    = count( $products );
		if ( $count < 1 ) {
			return;
		}
		if ( 1 === $count ) {
			$this->render_missing_usage_item( $products[0] );
			return;
		}
		/* translators: %d is the number of hidden shared-image references. */
		echo '<button type="button" class="button button-small lucidlab-mpc-shared-toggle" data-mpc-toggle aria-expanded="false"><span class="dashicons dashicons-arrow-down-alt2"></span> ' . esc_html( sprintf( __( 'Show %d items', 'lucidlab-mpc-cleanup' ), $count ) ) . '</button>';
		echo '<div class="lucidlab-mpc-shared-products lucidlab-mpc-missing-products" hidden data-count="' . (int) $count . '" data-loaded="1">';
		foreach ( $products as $item ) {
			$this->render_missing_usage_item( $item );
		}
		echo '</div>';
	}

	/**
	 * Render missing.
	 */
	private function render_missing(): void {
		$converted_present = __( 'A WebP or AVIF copy of that image counts as present, so deleting the original does not add it to this list.', 'lucidlab-mpc-cleanup' );
		$converted_omitted = __( 'Paths with a WebP or AVIF copy are left off this list.', 'lucidlab-mpc-cleanup' );
		echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'Finds Media Library image records whose main file is gone from uploads, and content URLs under uploads that no longer resolve to a file on disk (including hardcoded links and missing thumbnail sizes). Each row lists the Product, Page or Post that still points at it. Purge only deletes Media Library records — broken content URLs must be edited in the post/page.', 'lucidlab-mpc-cleanup' ) . ' ' . esc_html( $converted_present ) . '</p>';
		echo '<p class="lucidlab-mpc-btn-row"><button type="button" class="button button-primary" data-mpc-scan="missing"><span class="dashicons dashicons-search"></span> ' . esc_html__( 'Scan missing images', 'lucidlab-mpc-cleanup' ) . '</button>';
		$this->render_view_saved_results( 'missing', __( 'Loading missing images…', 'lucidlab-mpc-cleanup' ) );
		echo '</p>';
		// Missing results sit directly under the overview card — before Check live pages.
		if ( $this->results_list_requested( 'missing' ) ) {
			$data            = $this->get_scan_result( 'missing' );
			$missing         = is_array( $data ) && is_array( $data['images'] ?? null ) ? $data['images'] : array();
			$broken          = is_array( $data ) && is_array( $data['urls'] ?? null ) ? $data['urls'] : array();
			$summary         = get_option( 'lucidlab_mpc_summary_missing', array() );
			$summary_counts  = is_array( $summary['counts'] ?? null ) ? $summary['counts'] : array();
			$summary_missing = array_key_exists( 'missing', $summary_counts ) ? (int) $summary_counts['missing'] : -1;
			$summary_broken  = array_key_exists( 'broken_urls', $summary_counts ) ? (int) $summary_counts['broken_urls'] : -1;
			$is_clean        = ( is_array( $data ) && ! $missing && ! $broken ) || ( 0 === $summary_missing && $summary_broken <= 0 );
			$this->begin_results_card( ' data-mpc-results-section="missing"' );
			if ( is_array( $data ) ) {
				echo '<div class="lucidlab-mpc-stats" data-mpc-results-loaded="1" hidden></div>';
				if ( ! $missing && ! $broken ) {
					echo '<p class="lucidlab-mpc-empty lucidlab-mpc-good"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'No missing images detected.', 'lucidlab-mpc-cleanup' ) . '</p>';
				}
			} elseif ( $is_clean ) {
				echo '<p class="lucidlab-mpc-empty lucidlab-mpc-good"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'No missing images detected.', 'lucidlab-mpc-cleanup' ) . '</p>';
			}
			if ( $missing ) {
				$missing_page  = $this->results_page( $missing, 'lucidlab_mpc_missing_page' );
				$missing_total = (int) ( $missing_page['total'] ?? count( $missing ) );
				$missing       = $missing_page['items'];
				echo '<p class="lucidlab-mpc-missing-bulk"><form method="post" data-mpc-confirm-message="' . esc_attr__( 'Permanently delete ALL missing image media records listed below? This cannot be undone. Products, pages, posts and remaining files will be kept.', 'lucidlab-mpc-cleanup' ) . '">';
				wp_nonce_field( 'lucidlab_mpc_op_purge_missing' );
				echo '<input type="hidden" name="lucidlab_mpc_op" value="purge_missing"><input type="hidden" name="lucidlab_mpc_image_id" value="ALL">';
				echo '<button type="submit" class="button lucidlab-mpc-danger-text">' . esc_html__( 'Purge All Media Records', 'lucidlab-mpc-cleanup' ) . '</button></form></p>';
				echo '<div class="lucidlab-mpc-missing-attachments-section">';
				echo '<div class="lucidlab-mpc-missing-attachments-heading">';
				echo '<h3>' . esc_html__( 'Missing Media Library attachments', 'lucidlab-mpc-cleanup' ) . '</h3>';
				/* translators: %d is the number of hidden missing attachments. */
				echo '<button type="button" class="button button-small lucidlab-mpc-shared-toggle" data-mpc-toggle aria-expanded="false" data-testid="missing-attachments-toggle"><span class="dashicons dashicons-arrow-down-alt2"></span> ' . esc_html( sprintf( __( 'Show %d items', 'lucidlab-mpc-cleanup' ), $missing_total ) ) . '</button>';
				echo '</div>';
				echo '<div class="lucidlab-mpc-shared-products lucidlab-mpc-missing-attachments-panel" hidden data-count="' . (int) $missing_total . '" data-loaded="1">';
				echo '<div class="lucidlab-mpc-table-scroll"><table class="widefat lucidlab-mpc-table lucidlab-mpc-missing-table"><thead><tr><th>' . esc_html__( 'Image record', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Missing upload', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Used by', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Action', 'lucidlab-mpc-cleanup' ) . '</th></tr></thead><tbody>';
				foreach ( $missing as $image ) {
					echo '<tr class="lucidlab-mpc-missing-row"><td>#' . (int) $image['id'] . ' ' . esc_html( (string) ( $image['title'] ?? '' ) ) . '</td><td><code>' . esc_html( (string) ( $image['file'] ?? '' ) ) . '</code></td><td class="lucidlab-mpc-missing-usedby">';
					$this->render_missing_used_by( (array) ( $image['products'] ?? array() ) );
					echo '</td><td><form method="post" data-mpc-confirm-message="' . esc_attr__( 'Permanently delete this missing image media record? This cannot be undone. The product/post and remaining files will be kept.', 'lucidlab-mpc-cleanup' ) . '">';
					wp_nonce_field( 'lucidlab_mpc_op_purge_missing' );
					echo '<input type="hidden" name="lucidlab_mpc_op" value="purge_missing"><input type="hidden" name="lucidlab_mpc_image_id" value="' . (int) $image['id'] . '"><button type="submit" class="button lucidlab-mpc-danger-text">' . esc_html__( 'Purge media record', 'lucidlab-mpc-cleanup' ) . '</button></form></td></tr>';
				}
				echo '</tbody></table></div>';
				$this->render_results_pager( $missing_page, 'missing', 'lucidlab_mpc_missing_page', __( 'attachments', 'lucidlab-mpc-cleanup' ) );
				echo '</div></div>';
			}
			if ( $broken ) {
				$broken_page  = $this->results_page( $broken, 'lucidlab_mpc_missing_urls_page' );
				$broken_total = (int) ( $broken_page['total'] ?? count( $broken ) );
				$broken       = $broken_page['items'];
				echo '<div class="lucidlab-mpc-missing-urls-section">';
				echo '<div class="lucidlab-mpc-missing-urls-heading">';
				echo '<h3>' . esc_html__( 'Broken content URLs', 'lucidlab-mpc-cleanup' ) . '</h3>';
				/* translators: %d is the number of hidden broken content URLs. */
				echo '<button type="button" class="button button-small lucidlab-mpc-shared-toggle" data-mpc-toggle aria-expanded="false" data-testid="missing-urls-toggle"><span class="dashicons dashicons-arrow-down-alt2"></span> ' . esc_html( sprintf( __( 'Show %d items', 'lucidlab-mpc-cleanup' ), $broken_total ) ) . '</button>';
				echo '</div>';
				echo '<div class="lucidlab-mpc-shared-products lucidlab-mpc-missing-urls-panel" hidden data-count="' . (int) $broken_total . '" data-loaded="1">';
				echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'These uploads paths appear in post/page/product content (or builder meta) but the file is not on disk. Edit the content to remove or replace the link — there may be no Media Library record to purge.', 'lucidlab-mpc-cleanup' ) . ' ' . esc_html( $converted_omitted ) . '</p>';
				echo '<div class="lucidlab-mpc-table-scroll"><table class="widefat lucidlab-mpc-table lucidlab-mpc-missing-table lucidlab-mpc-missing-urls-table"><thead><tr><th>' . esc_html__( 'Broken upload', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Library ID', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Used by', 'lucidlab-mpc-cleanup' ) . '</th></tr></thead><tbody>';
				foreach ( $broken as $item ) {
					$aid = (int) ( $item['attachment_id'] ?? 0 );
					echo '<tr class="lucidlab-mpc-missing-row"><td><code>' . esc_html( (string) ( $item['file'] ?? '' ) ) . '</code>';
					if ( ! empty( $item['url'] ) ) {
						echo '<div class="lucidlab-mpc-hint">' . esc_html( (string) $item['url'] ) . '</div>';
					}
					echo '</td><td>';
					if ( $aid > 0 ) {
						$edit = get_edit_post_link( $aid, 'raw' );
						echo '#' . (int) $aid;
						if ( $edit ) {
							echo ' <a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit media', 'lucidlab-mpc-cleanup' ) . '</a>';
						}
					} else {
						echo '<span class="lucidlab-mpc-hint">' . esc_html__( 'No attachment', 'lucidlab-mpc-cleanup' ) . '</span>';
					}
					echo '</td><td class="lucidlab-mpc-missing-usedby">';
					$this->render_missing_used_by( (array) ( $item['products'] ?? array() ) );
					echo '</td></tr>';
				}
				echo '</tbody></table></div>';
				$this->render_results_pager( $broken_page, 'missing', 'lucidlab_mpc_missing_urls_page', __( 'URLs', 'lucidlab-mpc-cleanup' ) );
				echo '</div></div>';
			}
		}
		$this->begin_results_card( ' data-mpc-results-section="crawl"' );
		$this->render_live_page_check();
	}

	/**
	 * Opens public pages and lists broken images, files and links with the page they appeared on.
	 */
	private function render_live_page_check(): void {
		$pro     = LucidLab_MPC_Edition::pro();
		$options = LucidLab_MPC_Crawler::saved_options();
		echo '<h3 class="lucidlab-mpc-card__title">' . esc_html__( 'Check live pages', 'lucidlab-mpc-cleanup' ) . '</h3>';
		echo '<p class="lucidlab-mpc-hint lucidlab-mpc-card__lede">' . esc_html__( 'Requests public pages, posts, products and category archives and checks everything they load or link to: images (including every srcset size and background images), stylesheets, scripts, fonts, video, documents such as PDF files, and links to other pages of this site. It reports 4xx and 5xx answers, timeouts and redirects that end on an error, with the page where each one was found. Images inserted only by JavaScript are not seen. The disk scan above checks stored references under the configured uploads folder; this check sees the HTML visitors get, so the two reports can differ. No files are changed. The check sends at most 4 requests per second to this site as LucidLabLiveCheck; when the server answers 429 Too Many Requests or 503 it pauses, then continues more slowly by itself.', 'lucidlab-mpc-cleanup' ) . '</p>';
		echo '<p class="lucidlab-mpc-performance-fields lucidlab-mpc-crawl-options">';
		echo '<label class="lucidlab-mpc-performance-check"><input type="checkbox" id="lucidlab-mpc-crawl-links" value="1" ' . checked( $options['links'], true, false ) . ' data-testid="crawl-links" /> ' . esc_html__( 'Also check links to other pages of this site', 'lucidlab-mpc-cleanup' ) . '</label> ';
		echo '<label class="lucidlab-mpc-performance-check"><input type="checkbox" id="lucidlab-mpc-crawl-external" value="1" ' . checked( $pro && $options['external'], true, false ) . disabled( $pro, false, false ) . ' data-testid="crawl-external" /> ' . esc_html__( 'Also check images, files and links on other sites', 'lucidlab-mpc-cleanup' );
		if ( ! $pro ) {
			echo ' ' . wp_kses_post( self::pro_badge() );
		}
		echo '</label></p>';
		echo '<p class="lucidlab-mpc-btn-row"><button type="button" class="button" id="lucidlab-mpc-crawl-scan" data-mpc-scan="crawl">' . esc_html__( 'Check live pages', 'lucidlab-mpc-cleanup' ) . '</button>';
		$saved = $this->get_scan_result( 'crawl' );
		if ( is_array( $saved ) ) {
			printf(
				'<a class="button lucidlab-mpc-crawl-results-link" href="%1$s">%2$s</a>',
				esc_url( $this->tab_url( 'missing' ) . '&lucidlab_mpc_load=1&lucidlab_mpc_scanned=1' ),
				esc_html__( 'View page check', 'lucidlab-mpc-cleanup' )
			);
			if ( $pro && (int) ( $saved['broken'] ?? 0 ) > 0 ) {
				printf(
					' <a class="button" href="%1$s"><span class="dashicons dashicons-media-spreadsheet"></span> %2$s</a>',
					esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lucidlab_mpc_export_csv&tab=crawl' ), 'lucidlab_mpc_export_crawl' ) ),
					esc_html__( 'Export CSV', 'lucidlab-mpc-cleanup' )
				);
			}
		}
		echo '</p>';
		if ( is_array( $saved ) ) {
			$this->render_crawl_summary( $saved );
		}
		if ( is_array( $saved ) && ( ! empty( $saved['page_errors'] ) || ! empty( $saved['image_errors'] ) ) ) {
			/* translators: 1: live pages that could not be read, 2: inconclusive checks. */
			echo '<p class="notice notice-warning inline">' . esc_html( sprintf( __( 'Incomplete checks: %1$d pages could not be read (private, redirected to another site or no answer); %2$d checks were inconclusive (for example 429 Too Many Requests, or another site refusing automated requests). Retry later or after checking server access and timeouts.', 'lucidlab-mpc-cleanup' ), (int) ( $saved['page_errors'] ?? 0 ), (int) ( $saved['image_errors'] ?? 0 ) ) ) . '</p>';
		}
		echo '<div id="lucidlab-mpc-crawl-live" hidden>';
		$this->render_crawl_table_head();
		echo '<tbody></tbody></table></div>';
		if ( ! $this->results_list_requested( 'missing' ) || ! is_array( $saved ) ) {
			return;
		}
		$broken = (int) ( $saved['broken'] ?? 0 );
		echo '<div class="lucidlab-mpc-crawl-saved">';
		if ( $broken < 1 ) {
			echo '<p class="lucidlab-mpc-empty lucidlab-mpc-good"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'No broken images, files or links on the live pages.', 'lucidlab-mpc-cleanup' ) . '</p></div>';
			return;
		}
		$kind  = sanitize_key( wp_unslash( $_GET['lucidlab_mpc_crawl_kind'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter of a saved report.
		$kind  = in_array( $kind, LucidLab_MPC_Crawler::KINDS, true ) ? $kind : '';
		$kinds = array_filter( array_map( 'intval', (array) ( $saved['kinds'] ?? array() ) ) );
		if ( count( $kinds ) > 1 ) {
			$base = $this->tab_url( 'missing' ) . '&lucidlab_mpc_scanned=1';
			echo '<p class="lucidlab-mpc-crawl-filter">';
			echo '' === $kind ? '<strong>' . esc_html__( 'All', 'lucidlab-mpc-cleanup' ) . '</strong>' : '<a href="' . esc_url( $base ) . '">' . esc_html__( 'All', 'lucidlab-mpc-cleanup' ) . '</a>';
			foreach ( $kinds as $name => $count ) {
				$text = LucidLab_MPC_Crawler::kind_label( (string) $name ) . ' (' . number_format_i18n( $count ) . ')';
				echo ' | ' . ( $kind === $name ? '<strong>' . esc_html( $text ) . '</strong>' : '<a href="' . esc_url( $base . '&lucidlab_mpc_crawl_kind=' . rawurlencode( (string) $name ) ) . '">' . esc_html( $text ) . '</a>' );
			}
			echo '</p>';
		}
		$page = LucidLab_MPC_Crawler::findings_slice( $this->uid(), max( 1, absint( $_GET['lucidlab_mpc_crawl_page'] ?? 1 ) ), 50, $kind ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging of a saved report.
		$this->render_crawl_table_head();
		echo '<tbody>';
		foreach ( $page['items'] as $row ) {
			echo '<tr class="lucidlab-mpc-missing-row">';
			$this->render_crawl_finding_cells( is_array( $row ) ? $row : array() );
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( (int) $page['pages'] > 1 ) {
			/* translators: %d is the number of problems shown per page. */
			echo '<p>' . esc_html( sprintf( __( 'Showing %1$d problems per page.', 'lucidlab-mpc-cleanup' ), (int) $page['per'] ) ) . '</p><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => $this->tab_url( 'missing' ) . '&lucidlab_mpc_scanned=1' . ( '' !== $kind ? '&lucidlab_mpc_crawl_kind=' . rawurlencode( $kind ) : '' ) . '&lucidlab_mpc_crawl_page=%#%',
						'format'  => '',
						'current' => (int) $page['page'],
						'total'   => (int) $page['pages'],
					)
				)
			);
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Counts of the last live-page check, by kind.
	 *
	 * @param array $saved Saved summary.
	 */
	private function render_crawl_summary( array $saved ): void {
		$broken = (int) ( $saved['broken'] ?? 0 ) + (int) ( $saved['suppressed'] ?? 0 );
		$line   = sprintf(
			/* translators: 1: pages checked, 2: images, files and links checked, 3: problems */
			__( 'Last check: %1$s pages, %2$s images, files and links, %3$s problems.', 'lucidlab-mpc-cleanup' ),
			number_format_i18n( (int) ( $saved['pages'] ?? 0 ) ),
			number_format_i18n( (int) ( $saved['urls'] ?? $saved['images'] ?? 0 ) ),
			number_format_i18n( $broken )
		);
		if ( ! empty( $saved['seconds'] ) ) {
			/* translators: %s: duration such as "3 mins" */
			$line .= ' ' . sprintf( __( 'Took %s.', 'lucidlab-mpc-cleanup' ), human_time_diff( 0, (int) $saved['seconds'] ) );
		}
		echo '<p class="lucidlab-mpc-hint">' . esc_html( $line ) . '</p>';
		$kinds = array_filter( array_map( 'intval', (array) ( $saved['kinds'] ?? array() ) ) );
		if ( $kinds ) {
			$parts = array();
			foreach ( $kinds as $name => $count ) {
				$parts[] = LucidLab_MPC_Crawler::kind_label( (string) $name ) . ': ' . number_format_i18n( $count );
			}
			/* translators: %s: list such as "Image: 3, Link: 2" */
			echo '<p class="lucidlab-mpc-hint">' . esc_html( sprintf( __( 'Broken addresses by type: %s', 'lucidlab-mpc-cleanup' ), implode( ', ', $parts ) ) ) . '</p>';
		}
		if ( ! empty( $saved['suppressed'] ) ) {
			/* translators: %d: rows not listed */
			echo '<p class="lucidlab-mpc-hint">' . esc_html( sprintf( __( 'An address that is broken on many pages (for example in the menu or footer) is listed for its first 25 pages; %d more pages with the same address are counted but not listed.', 'lucidlab-mpc-cleanup' ), (int) $saved['suppressed'] ) ) . '</p>';
		}
	}

	/**
	 * Opening tag and header row of the live-page check table.
	 */
	private function render_crawl_table_head(): void {
		echo '<table class="widefat lucidlab-mpc-table lucidlab-mpc-missing-table lucidlab-mpc-crawl-table"><thead><tr><th>' . esc_html__( 'Page', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Broken address', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Type', 'lucidlab-mpc-cleanup' ) . '</th><th>' . esc_html__( 'Problem', 'lucidlab-mpc-cleanup' ) . '</th></tr></thead>';
	}

	/**
	 * Page, address, type and problem cells for one live-page finding.
	 *
	 * @param array $row Row.
	 */
	private function render_crawl_finding_cells( array $row ): void {
		$row   = LucidLab_MPC_Crawler::present( $row );
		$page  = (string) $row['page'];
		$title = (string) $row['title'];
		$url   = (string) $row['url'];
		echo '<td>';
		if ( '' !== $page ) {
			echo '<a href="' . esc_url( $page ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $title ? $title : $page ) . '</a>';
		} else {
			echo esc_html( $title );
		}
		echo '<div class="lucidlab-mpc-missing-usage-actions">';
		if ( '' !== $page ) {
			echo '<a class="button button-small" target="_blank" rel="noopener noreferrer" href="' . esc_url( $page ) . '">' . esc_html__( 'Open', 'lucidlab-mpc-cleanup' ) . '</a>';
		}
		if ( ! empty( $row['edit'] ) ) {
			echo '<a class="button button-small" target="_blank" rel="noopener noreferrer" href="' . esc_url( (string) $row['edit'] ) . '">' . esc_html__( 'Edit', 'lucidlab-mpc-cleanup' ) . '</a>';
		}
		echo '</div></td><td><code>' . esc_html( $url ) . '</code>';
		if ( '' !== (string) $row['via'] ) {
			echo '<div class="lucidlab-mpc-hint">' . esc_html__( 'Found in stylesheet:', 'lucidlab-mpc-cleanup' ) . ' <code>' . esc_html( (string) $row['via'] ) . '</code></div>';
		}
		echo '<div class="lucidlab-mpc-missing-usage-actions">';
		if ( '' !== $url ) {
			echo '<a class="button button-small" target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'lucidlab-mpc-cleanup' ) . '</a>';
		}
		if ( ! empty( $row['image_edit'] ) ) {
			echo '<a class="button button-small" target="_blank" rel="noopener noreferrer" href="' . esc_url( (string) $row['image_edit'] ) . '">' . esc_html__( 'Edit media', 'lucidlab-mpc-cleanup' ) . '</a>';
		}
		echo '</div></td><td>' . esc_html( (string) $row['kind_label'] );
		if ( ! empty( $row['external'] ) ) {
			echo '<div class="lucidlab-mpc-hint">' . esc_html__( 'Other site', 'lucidlab-mpc-cleanup' ) . '</div>';
		}
		echo '</td><td>' . esc_html( (string) $row['problem_label'] ) . '</td>';
	}

	/**
	 * AJAX handler: scan crawl.
	 */
	private function ajax_scan_crawl(): void {
		$key = $this->k_state( 'crawl' );
		if ( ! empty( $this->req['reset'] ) ) {
			LucidLab_MPC_Crawler::reset( $this->uid() );
			$options = LucidLab_MPC_Crawler::saved_options();
			if ( ! empty( $this->req['crawl_opts'] ) ) {
				$options = LucidLab_MPC_Crawler::save_options(
					array(
						'links'    => ! empty( $this->req['crawl_links'] ),
						'external' => ! empty( $this->req['crawl_external'] ),
					)
				);
			}
			set_transient( $key, LucidLab_MPC_Crawler::initial_state( $options ), $this->state_ttl() );
			$this->task_success(
				array(
					'done'     => false,
					'progress' => 2,
					'label'    => __( 'Listing public pages…', 'lucidlab-mpc-cleanup' ),
				)
			);
		}
		$state = get_transient( $key );
		if ( ! is_array( $state ) ) {
			$this->task_error( array( 'message' => __( 'Scan session expired. Start a new scan.', 'lucidlab-mpc-cleanup' ) ), 400 );
		}
		$result = LucidLab_MPC_Crawler::step( $state, $this->uid() );
		if ( ! empty( $result['done'] ) ) {
			$this->set_scan_result( 'crawl', LucidLab_MPC_Crawler::summary( $state ) );
			delete_transient( $key );
			LucidLab_MPC_Work::delete( LucidLab_MPC_Crawler::urls_file( $this->uid() ), LucidLab_MPC_Crawler::status_file( $this->uid() ), LucidLab_MPC_Crawler::listed_file( $this->uid() ) );
		} else {
			set_transient( $key, $state, $this->state_ttl() );
		}
		$data = array(
			'done'     => ! empty( $result['done'] ),
			'progress' => (int) $result['progress'],
			'label'    => (string) $result['label'],
		);
		if ( ! empty( $result['wait_until'] ) ) {
			// The site asked to slow down: the job runner waits until then before the next batch.
			$data['wait_until'] = (int) $result['wait_until'];
		}
		$this->task_success( $data );
	}

	/**
	 * Render shared.
	 *
	 * @param mixed $data Data.
	 */
	private function render_shared( $data ): void {
		?>
		<p class="lucidlab-mpc-scan-description"><?php esc_html_e( 'Finds images used by two or more distinct products, posts, pages or categories through featured images, product galleries, category thumbnails, and stored post/builder content. Each item lists its usage types. Click a thumbnail to view it full-size.', 'lucidlab-mpc-cleanup' ); ?></p>
		<form method="post" class="lucidlab-mpc-controls">
			<?php wp_nonce_field( 'lucidlab_mpc_op_scan_shared' ); ?>
			<input type="hidden" name="lucidlab_mpc_op" value="scan_shared" />
			<button type="submit" class="button button-primary" data-mpc-scan="shared" data-testid="scan-shared"><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Find shared images', 'lucidlab-mpc-cleanup' ); ?></button>
			<?php $this->render_view_saved_results( 'shared', __( 'Loading shared images…', 'lucidlab-mpc-cleanup' ) ); ?>
			<?php if ( is_array( $data ) ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lucidlab_mpc_export_csv&tab=shared' ), 'lucidlab_mpc_export_shared' ) ); ?>"><span class="dashicons dashicons-media-spreadsheet"></span> <?php esc_html_e( 'Export CSV', 'lucidlab-mpc-cleanup' ); ?></a>
			<?php endif; ?>
		</form>
		<?php $this->progress_bar( 'shared-quar' ); ?>

			<?php if ( is_array( $data ) && ( ! empty( $data['images'] ) || ( ! empty( $data['images_file'] ) && (int) ( $data['image_count'] ?? 0 ) > 0 ) ) ) : ?>
			<div class="lucidlab-mpc-shared-toolbar">
				<button type="button" class="button button-small" data-mpc-expand-all data-testid="shared-expand-all"><span class="dashicons dashicons-editor-expand"></span> <?php esc_html_e( 'Expand all', 'lucidlab-mpc-cleanup' ); ?></button>
				<button type="button" class="button button-small" data-mpc-collapse-all data-testid="shared-collapse-all"><span class="dashicons dashicons-editor-contract"></span> <?php esc_html_e( 'Collapse all', 'lucidlab-mpc-cleanup' ); ?></button>
			</div>
		<?php endif; ?>

		<?php if ( ! is_array( $data ) ) : ?>
			
			<?php
			return;
endif;
		?>

		<?php $this->begin_results_card( ' data-mpc-results-section="shared"' ); ?>
		<div class="lucidlab-mpc-stats" data-mpc-results-loaded="1" hidden></div>

		<?php
		$shared_from_file = ! empty( $data['images_file'] ) && ! isset( $data['images'] );
		if ( $shared_from_file ) {
			$shared_slice  = $this->shared_read_page( max( 1, absint( $_GET['lucidlab_mpc_shared_page'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
			$shared_images = $shared_slice['items'];
			$shared_pages  = (int) $shared_slice['pages'];
			$shared_page   = (int) $shared_slice['page'];
		} else {
			$shared_pages  = max( 1, (int) ceil( count( (array) ( $data['images'] ?? array() ) ) / 50 ) );
			$shared_page   = min( $shared_pages, max( 1, absint( $_GET['lucidlab_mpc_shared_page'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
			$shared_images = array_slice( (array) ( $data['images'] ?? array() ), ( $shared_page - 1 ) * 50, 50 );
		}
		?>
		<?php if ( ! $shared_images ) : ?>
			<?php if ( ! $this->render_if_quarantined( $data ) ) : ?>
			<p class="lucidlab-mpc-empty lucidlab-mpc-good"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'No image is shared by multiple items.', 'lucidlab-mpc-cleanup' ); ?></p>
			<?php endif; ?>
			<?php
			return;
endif;
		?>

		<?php
		$shared_navigation = function () use ( $shared_page, $shared_pages ) {
			echo ( '<nav class="lucidlab-mpc-pagination" aria-label="' . esc_attr__( 'Shared images pages', 'lucidlab-mpc-cleanup' ) . '"><p>' );
			if ( $shared_page > 1 ) {
				echo '<a class="button" href="' . esc_url(
					add_query_arg(
						array(
							'lucidlab_mpc_load'        => 1,
							'lucidlab_mpc_shared_page' => $shared_page - 1,
						),
						$this->tab_url( 'shared' )
					)
				) . ( '">' . esc_html__( 'Previous', 'lucidlab-mpc-cleanup' ) . '</a> ' );
			}
			echo '<span>' . esc_html( sprintf( /* translators: 1: current page number, 2: total pages. */ __( 'Page %1$s of %2$s', 'lucidlab-mpc-cleanup' ), number_format_i18n( (int) $shared_page ), number_format_i18n( (int) $shared_pages ) ) ) . '</span> ';
			if ( $shared_page < $shared_pages ) {
				echo '<a class="button" href="' . esc_url(
					add_query_arg(
						array(
							'lucidlab_mpc_load'        => 1,
							'lucidlab_mpc_shared_page' => $shared_page + 1,
						),
						$this->tab_url( 'shared' )
					)
				) . ( '">' . esc_html__( 'Next', 'lucidlab-mpc-cleanup' ) . '</a>' );
			}
			echo '</p></nav>';
		};
		$shared_navigation();
		?>
		<div class="lucidlab-mpc-shared-list">
			<?php foreach ( $shared_images as $img ) : ?>
				<?php $img = $this->shared_row_links( $img ); ?>
				<div class="lucidlab-mpc-shared-row">
					<div class="lucidlab-mpc-shared-image">
						<?php if ( $img['thumb'] ) : ?>
							<img class="lucidlab-mpc-thumb lucidlab-mpc-thumb-lg lucidlab-mpc-lb" src="<?php echo esc_url( $img['thumb'] ); ?>" data-full="<?php echo esc_url( (string) $img['full'] ); ?>" data-caption="<?php echo esc_attr( $img['title'] ); ?>" alt="" />
						<?php else : ?>
							<span class="lucidlab-mpc-thumb lucidlab-mpc-thumb-lg lucidlab-mpc-thumb-none dashicons dashicons-format-image"></span>
						<?php endif; ?>
						<div class="lucidlab-mpc-shared-meta">
							<strong><?php echo esc_html( $img['title'] ); ?></strong>
							<?php /* translators: %d is the number of posts or terms using this image. */ ?>
							<span class="lucidlab-mpc-hint">#<?php echo (int) $img['id']; ?> · <?php echo esc_html( sprintf( _n( '%d item', '%d items', $img['count'], 'lucidlab-mpc-cleanup' ), $img['count'] ) ); ?></span>
							<div class="lucidlab-mpc-btn-row">
								<?php /* translators: %d is the number of hidden references to this shared image. */ ?>
								<button type="button" class="button button-small lucidlab-mpc-shared-toggle" data-mpc-toggle aria-expanded="false" data-testid="shared-toggle-<?php echo (int) $img['id']; ?>"><span class="dashicons dashicons-arrow-down-alt2"></span> <?php echo esc_html( sprintf( __( 'Show %d items', 'lucidlab-mpc-cleanup' ), (int) $img['count'] ) ); ?></button>
								<?php
								if ( $img['edit'] ) :
									?>
									<a class="button button-small" href="<?php echo esc_url( $img['edit'] ); ?>" target="_blank"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Edit image', 'lucidlab-mpc-cleanup' ); ?></a><?php endif; ?>
								<button type="button" class="button button-small lucidlab-mpc-danger-text" data-mpc-quar-image data-id="<?php echo (int) $img['id']; ?>" data-testid="quar-image-<?php echo (int) $img['id']; ?>"><span class="dashicons dashicons-archive"></span> <?php esc_html_e( 'Quarantine image', 'lucidlab-mpc-cleanup' ); ?></button>
							</div>
						</div>
					</div>
					<div class="lucidlab-mpc-shared-products" hidden data-mpc-lazy-shared="<?php echo (int) $img['id']; ?>" data-count="<?php echo (int) $img['count']; ?>"></div>
				</div>
			<?php endforeach; ?>

		</div>
		<?php
		$shared_navigation();
	}

	/**
	 * Render quarantine manager.
	 *
	 * @param array $sets Sets.
	 */
	private function render_quarantine_manager( array $sets ): void {
		?>
		<div class="lucidlab-mpc-quarantine">
			<?php /* translators: %d is the quarantine retention period in days. */ ?>
			<h3><span class="dashicons dashicons-archive"></span> <?php esc_html_e( 'Quarantine', 'lucidlab-mpc-cleanup' ); ?> <span class="lucidlab-mpc-hint">(<?php echo esc_html( LucidLab_MPC_Edition::pro() ? sprintf( __( 'eligible sets auto-purged after %d days', 'lucidlab-mpc-cleanup' ), LucidLab_MPC_Quarantine::retention_days() ) : __( 'Kept until you purge', 'lucidlab-mpc-cleanup' ) ); ?>)</span></h3>
			<?php if ( empty( $sets ) ) : ?>
				<p class="lucidlab-mpc-empty"><?php esc_html_e( 'No quarantined items.', 'lucidlab-mpc-cleanup' ); ?></p>
			<?php else : ?>
				<table class="widefat lucidlab-mpc-table">
					<thead><tr><th><?php esc_html_e( 'Set', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Files', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Media', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Size', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Age', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Expires', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Actions', 'lucidlab-mpc-cleanup' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $sets as $s ) : ?>
						<tr>
							<td><code><?php echo esc_html( $s['stamp'] ); ?></code></td>
							<td><?php echo (int) $s['count']; ?></td>
							<td><?php echo (int) $s['attachments']; ?></td>
							<td><?php echo esc_html( LucidLab_MPC_Helpers::bytes( $s['bytes'] ) ); ?></td>
							<?php /* translators: %d is the age of a quarantine set in days. */ ?>
							<td><?php echo esc_html( sprintf( _n( '%d day', '%d days', $s['age'], 'lucidlab-mpc-cleanup' ), $s['age'] ) ); ?></td>
							<?php /* translators: %d is the number of days until this quarantine set expires. */ ?>
							<td>
							<?php
							if ( ! LucidLab_MPC_Edition::pro() || 'originals' === (string) ( $s['source'] ?? '' ) ) :
								?>
								<?php esc_html_e( 'Kept until you purge', 'lucidlab-mpc-cleanup' ); ?>
								<?php
else :
	?>
								<?php echo esc_html( sprintf( /* translators: %d: number of days. */ _n( '%d day', '%d days', $s['expires'], 'lucidlab-mpc-cleanup' ), $s['expires'] ) ); ?>
								<?php
								if ( $s['expires'] <= 3 ) :
									?>
	<span class="lucidlab-mpc-badge lucidlab-mpc-badge-risk" title="<?php echo esc_attr__( 'This quarantine set is nearing automatic purge.', 'lucidlab-mpc-cleanup' ); ?>"><?php esc_html_e( 'Expires soon', 'lucidlab-mpc-cleanup' ); ?></span><?php endif; ?><?php endif; ?></td>
							<td class="lucidlab-mpc-qactions">
								<a class="button button-small" href="<?php echo esc_url( $this->tab_url( 'quarantine' ) . '&lucidlab_mpc_load=1&lucidlab_mpc_view_set=' . rawurlencode( $s['stamp'] ) . '#lucidlab-mpc-quarantine-items' ); ?>"><?php esc_html_e( 'View', 'lucidlab-mpc-cleanup' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lucidlab_mpc_export_manifest&set=' . rawurlencode( $s['stamp'] ) ), 'lucidlab_mpc_export_manifest_' . $s['stamp'] ) ); ?>"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export', 'lucidlab-mpc-cleanup' ); ?></a>
								<form method="post" data-mpc-restore="set">
									<?php wp_nonce_field( 'lucidlab_mpc_op_restore_quarantine' ); ?>
									<input type="hidden" name="lucidlab_mpc_op" value="restore_quarantine" /><input type="hidden" name="lucidlab_mpc_set" value="<?php echo esc_attr( $s['stamp'] ); ?>" /><input type="hidden" name="lucidlab_mpc_source" value="<?php echo esc_attr( (string) ( $s['source'] ?? '' ) ); ?>" />
									<button class="button button-small" data-testid="restore-<?php echo esc_attr( $s['stamp'] ); ?>"><span class="dashicons dashicons-undo"></span> <?php esc_html_e( 'Restore', 'lucidlab-mpc-cleanup' ); ?></button>
								</form>
								<form method="post" data-mpc-confirm-message="PERMANENTLY delete this set? Cannot be undone.">
									<?php wp_nonce_field( 'lucidlab_mpc_op_purge_quarantine' ); ?>
									<input type="hidden" name="lucidlab_mpc_op" value="purge_quarantine" /><input type="hidden" name="lucidlab_mpc_set" value="<?php echo esc_attr( $s['stamp'] ); ?>" /><input type="hidden" name="lucidlab_mpc_source" value="<?php echo esc_attr( (string) ( $s['source'] ?? '' ) ); ?>" />
									<button type="submit" class="button button-small lucidlab-mpc-danger-text"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Purge', 'lucidlab-mpc-cleanup' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" class="lucidlab-mpc-purge-all" data-mpc-confirm-message="PERMANENTLY delete ALL quarantine sets?">
					<?php wp_nonce_field( 'lucidlab_mpc_op_purge_quarantine' ); ?>
					<input type="hidden" name="lucidlab_mpc_op" value="purge_quarantine" /><input type="hidden" name="lucidlab_mpc_set" value="ALL" />
					<button type="submit" class="button lucidlab-mpc-danger-text"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Purge ALL', 'lucidlab-mpc-cleanup' ); ?></button>
				</form>
				
				<?php
				$last_action = LucidLab_MPC_Quarantine::get_last_action();
				if ( ! empty( $last_action ) && ! empty( $last_action['stamp'] ) ) :
					$time_ago     = human_time_diff( $last_action['timestamp'], time() );
					$action_label = '';
					switch ( $last_action['action_type'] ) {
						case 'quarantine_files':
							/* translators: 1: files quarantined, 2: files that failed. */
							$action_label = sprintf( __( 'Quarantined %1$d files (%2$d failed)', 'lucidlab-mpc-cleanup' ), $last_action['details']['items_quarantined'] ?? 0, $last_action['details']['items_failed'] ?? 0 );
							break;
						case 'quarantine_attachments':
							/* translators: 1: attachments quarantined, 2: attachments that failed. */
							$action_label = sprintf( __( 'Quarantined %1$d attachments (%2$d failed)', 'lucidlab-mpc-cleanup' ), $last_action['details']['attachments_quarantined'] ?? 0, $last_action['details']['attachments_failed'] ?? 0 );
							break;
						default:
							$action_label = ucfirst( str_replace( '_', ' ', $last_action['action_type'] ) );
					}
					?>
				<div class="lucidlab-mpc-undo-last-action">
					<?php /* translators: %s is the elapsed time since the last cleanup action. */ ?>
					<p class="lucidlab-mpc-hint"><strong><?php esc_html_e( 'Last action:', 'lucidlab-mpc-cleanup' ); ?></strong> <?php echo esc_html( $action_label ); ?> — <?php echo esc_html( sprintf( __( '%s ago', 'lucidlab-mpc-cleanup' ), $time_ago ) ); ?></p>
					<form method="post" data-mpc-confirm-message="<?php esc_attr_e( 'Undo the last quarantine action? This will restore all items from the most recent quarantine set.', 'lucidlab-mpc-cleanup' ); ?>">
						<?php wp_nonce_field( 'lucidlab_mpc_op_restore_quarantine' ); ?>
						<input type="hidden" name="lucidlab_mpc_op" value="restore_quarantine" /><input type="hidden" name="lucidlab_mpc_set" value="<?php echo esc_attr( $last_action['stamp'] ); ?>" /><input type="hidden" name="lucidlab_mpc_source" value="<?php echo esc_attr( sanitize_key( (string) ( $last_action['details']['source'] ?? '' ) ) ); ?>" />
						<button class="button"><span class="dashicons dashicons-undo"></span> <?php esc_html_e( 'Undo Last Action', 'lucidlab-mpc-cleanup' ); ?></button>
					</form>
				</div>
				<?php endif; ?>

				<?php $this->render_quarantine_files(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Searchable, per-item list across all quarantine sets with single-item restore.
	 */
	private function render_quarantine_files(): void {
				$selected_set = isset( $_GET['lucidlab_mpc_view_set'] ) ? sanitize_file_name( wp_unslash( $_GET['lucidlab_mpc_view_set'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
		$query                = isset( $_GET['lucidlab_mpc_q'] ) ? sanitize_text_field( wp_unslash( $_GET['lucidlab_mpc_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified by the AJAX/admin-post router before this handler runs.
		$requested            = '' !== $selected_set || '' !== $query;
		$items                = $requested ? $this->quar->list_files( $selected_set, $query ) : array();
		?>
		<div class="lucidlab-mpc-qfiles" id="lucidlab-mpc-quarantine-items">
			<h4><?php esc_html_e( 'Find & restore a specific item', 'lucidlab-mpc-cleanup' ); ?></h4>
			<form method="get" class="lucidlab-mpc-qfiles-search" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>#lucidlab-mpc-quarantine-items">
				<input type="hidden" name="page" value="lucidlab-mpc-cleanup" />
				<input type="hidden" name="tab" value="quarantine" />
				<input type="hidden" name="lucidlab_mpc_load" value="1" />
				<input type="search" name="lucidlab_mpc_q" class="regular-text" value="<?php echo esc_attr( $query ); ?>" aria-label="<?php esc_attr_e( 'Search quarantined items', 'lucidlab-mpc-cleanup' ); ?>" placeholder="<?php esc_attr_e( 'Search by filename, path or set…', 'lucidlab-mpc-cleanup' ); ?>" data-testid="quarantine-search" />
				<button type="submit" class="button" id="lucidlab-mpc-qfiles-search-btn" data-testid="quarantine-search-submit"><?php esc_html_e( 'Search all sets', 'lucidlab-mpc-cleanup' ); ?></button>
			</form>
			<?php if ( ! $requested ) : ?>
				<p class="lucidlab-mpc-hint"><?php esc_html_e( 'Click View on a set or search to load its images.', 'lucidlab-mpc-cleanup' ); ?></p>
			<?php elseif ( empty( $items ) ) : ?>
				<p class="lucidlab-mpc-empty"><?php esc_html_e( 'No items found.', 'lucidlab-mpc-cleanup' ); ?></p>
			<?php endif; ?>
			<?php
			if ( $selected_set ) :
				$viewing_set = $selected_set;
				$viewing_job = LucidLab_MPC_Quarantine::source_label( $this->quar->set_source( $selected_set ) );
				if ( '' !== $viewing_job ) {
					$viewing_set .= ' — ' . $viewing_job;
				}
				?>
				<?php /* translators: %s is the name of the quarantine set being viewed. */ ?>
				<p><?php echo esc_html( sprintf( __( 'Viewing set: %s', 'lucidlab-mpc-cleanup' ), $viewing_set ) ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $items ) ) : ?>
			<table class="widefat lucidlab-mpc-table lucidlab-mpc-qfiles-table">
				<thead><tr>
					<th><?php esc_html_e( 'Preview', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Item', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Type', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Set', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Size', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Restore', 'lucidlab-mpc-cleanup' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $items as $it ) :
					$set_job   = LucidLab_MPC_Quarantine::source_label( (string) ( $it['source'] ?? '' ) );
					$set_label = $it['stamp'] . ( '' !== $set_job ? ' — ' . $set_job : '' );
					$search    = strtolower( $it['label'] . ' ' . $set_label . ' ' . $it['type'] );
					?>
					<tr class="lucidlab-mpc-qfile-row" data-search="<?php echo esc_attr( $search ); ?>">
						<td><?php echo ! empty( $it['preview'] ) ? '<img class="lucidlab-mpc-thumb lucidlab-mpc-lb" loading="lazy" src="' . esc_url( $it['preview'] ) . '" data-full="' . esc_url( $it['preview'] ) . '" data-caption="' . esc_attr( $it['label'] ) . '" alt="" />' : '<span class="lucidlab-mpc-thumb lucidlab-mpc-thumb-none dashicons dashicons-media-default"></span>'; ?></td>
						<td><code><?php echo esc_html( $it['label'] ); ?></code></td>
						<td><span class="lucidlab-mpc-badge <?php echo 'attachment' === $it['type'] ? 'lucidlab-mpc-badge-warn' : 'lucidlab-mpc-badge-safe'; ?>"><?php echo 'attachment' === $it['type'] ? esc_html__( 'media', 'lucidlab-mpc-cleanup' ) : esc_html__( 'file', 'lucidlab-mpc-cleanup' ); ?><?php echo $it['count'] > 1 ? ' &times;' . (int) $it['count'] : ''; ?></span></td>
						<td><code><?php echo esc_html( $set_label ); ?></code></td>
						<td><?php echo $it['bytes'] ? esc_html( LucidLab_MPC_Helpers::bytes( $it['bytes'] ) ) : '—'; ?></td>
						<td>
							<form method="post" data-mpc-restore="item">
								<?php wp_nonce_field( 'lucidlab_mpc_op_restore_one' ); ?>
								<input type="hidden" name="lucidlab_mpc_op" value="restore_one" />
								<input type="hidden" name="lucidlab_mpc_set" value="<?php echo esc_attr( $it['stamp'] ); ?>" />
								<input type="hidden" name="lucidlab_mpc_type" value="<?php echo esc_attr( $it['type'] ); ?>" />
								<input type="hidden" name="lucidlab_mpc_key" value="<?php echo esc_attr( $it['key'] ); ?>" />
								<input type="hidden" name="lucidlab_mpc_source" value="<?php echo esc_attr( (string) ( $it['source'] ?? '' ) ); ?>" />
								<button class="button button-small" data-testid="restore-one"><span class="dashicons dashicons-undo"></span> <?php esc_html_e( 'Restore', 'lucidlab-mpc-cleanup' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table><?php endif; ?>

		</div>
		<?php
	}
}
