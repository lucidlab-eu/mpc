<?php
/**
 * Lucid Lab Media & Products Cleanup: i18n class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Load Free and Pro gettext files using the signed-in user’s profile language in wp-admin. */
class LucidLab_MPC_I18n {
	/**
	 * Loaded domains.
	 *
	 * @var array
	 */
	private static array $loaded_domains = array();

	/**
	 * Load.
	 */
	public static function load(): void {
		add_filter( 'plugin_locale', array( __CLASS__, 'filter_plugin_locale' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'reload' ), 1 );
		self::reload();
	}

	/**
	 * Reload Free and Pro translations after the signed-in user is known.
	 */
	public static function reload(): void {
		self::load_domains( self::user_locale() );
	}

	/**
	 * Cron workers have no signed-in profile, so labels they save would otherwise use the site language. Pair every true result with restore().
	 *
	 * @param int $user_id User id.
	 *
	 * @return bool
	 */
	public static function switch_to_user( int $user_id ): bool {
		if ( $user_id < 1 || ! function_exists( 'switch_to_user_locale' ) ) {
			return false;
		}
		$locale = get_user_locale( $user_id );
		if ( ! $locale || ! switch_to_user_locale( $user_id ) ) {
			return false;
		}
		self::load_domains( $locale );
		return true;
	}

	/**
	 * Restore.
	 *
	 * @param bool $switched Switched.
	 */
	public static function restore( bool $switched ): void {
		if ( ! $switched ) {
			return;
		}
		restore_previous_locale();
		self::load_domains( self::user_locale() );
	}

	/**
	 * Load domains.
	 *
	 * @param string $locale Locale.
	 */
	private static function load_domains( string $locale ): void {
		$pack   = self::pack_locale( $locale );
		$active = ( '' === $pack || self::is_english( $locale ) ) ? '' : $locale;
		self::load_domain( 'lucidlab-mpc-cleanup', $active, $pack, defined( 'LUCIDLAB_MPC_DIR' ) ? LUCIDLAB_MPC_DIR . 'languages/' : '' );
		if ( defined( 'LUCIDLAB_MPC_PRO_FILE' ) && defined( 'LUCIDLAB_MPC_PRO_DIR' ) ) {
			self::load_domain( 'lucidlab-mpc-cleanup-pro', $active, $pack, LUCIDLAB_MPC_PRO_DIR . 'languages/' );
		}
	}

	/**
	 * Always prefer the profile language for this plugin in admin screens.
	 *
	 * @param string $locale Locale.
	 * @param string $domain Domain.
	 *
	 * @return string
	 */
	public static function filter_plugin_locale( string $locale, string $domain ): string {
		if ( ! in_array( $domain, array( 'lucidlab-mpc-cleanup', 'lucidlab-mpc-cleanup-pro' ), true ) ) {
			return $locale;
		}
		return LucidLab_MPC_Helpers::either( self::user_locale(), $locale );
	}

	/**
	 * Translation file to use for a profile locale. English has no pack. es_CL uses es_ES when that is the Spanish file we ship.
	 *
	 * @param string $locale Locale.
	 *
	 * @return string
	 */
	public static function pack_locale( string $locale ): string {
		$locale = str_replace( '-', '_', $locale );
		if ( self::is_english( $locale ) ) {
			return '';
		}
		$packs = self::available_packs();
		if ( isset( $packs[ $locale ] ) ) {
			return $locale;
		}
		$lang = strtolower( (string) strtok( $locale, '_' ) );
		if ( '' === $lang ) {
			return '';
		}
		$preferred = array(
			'es' => 'es_ES',
			'pt' => 'pt_PT',
			'zh' => 'zh_CN',
			'nb' => 'nb_NO',
			'nn' => 'nb_NO',
			'no' => 'nb_NO',
		);
		$same      = $lang . '_' . strtoupper( $lang );
		foreach ( array( $preferred[ $lang ] ?? '', $same, $lang ) as $candidate ) {
			if ( '' !== $candidate && isset( $packs[ $candidate ] ) ) {
				return $candidate;
			}
		}
		foreach ( array_keys( $packs ) as $pack ) {
			if ( str_starts_with( $pack, $lang . '_' ) ) {
				return $pack;
			}
		}
		return '';
	}

	/**
	 * Whether english.
	 *
	 * @param string $locale Locale.
	 *
	 * @return bool
	 */
	private static function is_english( string $locale ): bool {
		return '' === $locale || 'en' === $locale || str_starts_with( $locale, 'en_' );
	}

	/**
	 * Available packs.
	 *
	 * @return array<string,bool>
	 */
	private static function available_packs(): array {
		static $packs = null;
		if ( null !== $packs ) {
			return $packs;
		}
		$packs = array();
		$dirs  = array( defined( 'LUCIDLAB_MPC_DIR' ) ? LUCIDLAB_MPC_DIR . 'languages/' : '' );
		if ( defined( 'LUCIDLAB_MPC_PRO_DIR' ) ) {
			$dirs[] = LUCIDLAB_MPC_PRO_DIR . 'languages/';
		}
		if ( defined( 'WP_LANG_DIR' ) ) {
			$dirs[] = trailingslashit( WP_LANG_DIR ) . 'plugins/';
		}
		foreach ( $dirs as $dir ) {
			if ( '' === $dir || ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( array( 'lucidlab-mpc-cleanup*.mo' ) as $pattern ) {
				foreach ( (array) glob( $dir . $pattern ) as $file ) {
					if ( preg_match( '/^lucidlab-mpc-cleanup(?:-pro)?-([a-z]{2,3}(?:_[A-Z]{2})?)\.mo$/', basename( $file ), $match ) ) {
						$packs[ $match[1] ] = true;
					}
				}
			}
		}
		return $packs;
	}

	/**
	 * User locale.
	 *
	 * @return string
	 */
	public static function user_locale(): string {
		if ( is_admin() && function_exists( 'get_user_locale' ) ) {
			$locale = get_user_locale();
			if ( is_string( $locale ) && $locale ) {
				return $locale;
			}
		}
		if ( function_exists( 'determine_locale' ) ) {
			return (string) determine_locale();
		}
		return (string) get_locale();
	}

	/**
	 * Language packs are loaded from the plugin or WordPress.org; never write into WP_LANG_DIR.
	 */
	public static function install_language_packs(): void {}

	/**
	 * Load domain.
	 *
	 * @param string $domain          Domain.
	 * @param string $active          Active.
	 * @param string $pack            Pack.
	 * @param string $plugin_lang_dir Plugin lang dir.
	 */
	private static function load_domain( string $domain, string $active, string $pack, string $plugin_lang_dir ): void {
		$signature = $active . '|' . $pack . '|' . $plugin_lang_dir;
		if ( ( self::$loaded_domains[ $domain ] ?? null ) === $signature && function_exists( 'is_textdomain_loaded' ) && is_textdomain_loaded( $domain ) ) {
			return;
		}

		if ( function_exists( 'unload_textdomain' ) ) {
			unload_textdomain( $domain );
		}
		if ( '' === $active || '' === $pack ) {
			return;
		}
		$bases        = array();
		$wp_base      = defined( 'WP_LANG_DIR' ) ? trailingslashit( WP_LANG_DIR ) . 'plugins/' . $domain . '-' . $pack : '';
		$bundled_base = $plugin_lang_dir ? $plugin_lang_dir . $domain . '-' . $pack : '';
		// WordPress.org community packs should supersede the bundled Spanish fallback.
		if ( 'lucidlab-mpc-cleanup' === $domain && $wp_base ) {
			$bases[] = $wp_base;
		}
		if ( $bundled_base ) {
			$bases[] = $bundled_base;
		}
		if ( 'lucidlab-mpc-cleanup' !== $domain && $wp_base ) {
			$bases[] = $wp_base;
		}
		foreach ( $bases as $base ) {
			$mo = $base . '.mo';
			if ( is_readable( $mo ) && load_textdomain( $domain, $mo, $active ) ) {
				self::$loaded_domains[ $domain ] = $signature;
				return;
			}
		}
	}
}
