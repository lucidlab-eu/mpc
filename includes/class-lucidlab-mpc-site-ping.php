<?php
/**
 * Lucid Lab Media & Products Cleanup: optional install ping.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells lucidlab.eu that this site runs the plugin, so Lucid Lab can count active installs.
 *
 * Off by default. Free sends nothing until an administrator ticks the opt-in box in Options. The licensed
 * Pro add-on turns it on through the lucidlab_mpc_site_ping_allowed filter, because it already talks to
 * lucidlab.eu for its license checks. Only the site address, the plugin version and a random install ID
 * are sent: when it is switched on, after each plugin update and once a week. Switching it off removes
 * the weekly event at once.
 */
final class LucidLab_MPC_Site_Ping {

	/** Endpoint on lucidlab.eu (theme: mpc-landing/free-installs.php, POST, install_id + site_url + version). */
	const ENDPOINT = 'https://lucidlab.eu/wp-json/mpc/v1/free/ping';

	/** Weekly WP-Cron hook. */
	const HOOK = 'lucidlab_mpc_site_ping';

	/** Settings key of the Free opt-in (0 by default). */
	const SETTING = 'install_ping';

	/** Random 32-hex install ID; it identifies the install, not a person. */
	const INSTALL_ID = 'lucidlab_mpc_install_id';

	/** Last attempt: signature, time, HTTP code. */
	const LAST = 'lucidlab_mpc_site_ping_last';

	/** Seconds before a failed ping for the same versions is tried again from an admin page. */
	const RETRY_AFTER = 3600;

	/**
	 * Hook into WordPress. Called from LucidLab_MPC_Hooks::register() on every request.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'cron' ) );
		add_action( 'admin_init', array( __CLASS__, 'admin_init' ) );
		add_action( 'update_option_' . LucidLab_MPC_Settings::OPTION, array( __CLASS__, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . LucidLab_MPC_Settings::OPTION, array( __CLASS__, 'settings_added' ), 10, 2 );
		add_action( 'deleted_option', array( __CLASS__, 'option_deleted' ) );
		add_action( 'lucidlab_mpc_pro_license_sync', array( __CLASS__, 'refresh' ), 20 );
	}

	/**
	 * Whether the administrator opted in (Free) or a licensed Pro add-on allows it.
	 *
	 * @return bool
	 */
	public static function allowed(): bool {
		$opted_in = ! empty( LucidLab_MPC_Settings::get( self::SETTING ) );
		/**
		 * Filters whether this site may send the install ping to lucidlab.eu.
		 *
		 * @param bool $allowed True when the administrator opted in under Options.
		 */
		return (bool) apply_filters( 'lucidlab_mpc_site_ping_allowed', $opted_in );
	}

	/**
	 * Versions the last ping reported; a new value triggers a ping after an update.
	 *
	 * @return string
	 */
	public static function signature(): string {
		/**
		 * Filters the version signature. Pro appends its own version, so a Pro update also pings.
		 *
		 * @param string $signature Free plugin version.
		 */
		return (string) apply_filters( 'lucidlab_mpc_site_ping_signature', LUCIDLAB_MPC_VERSION );
	}

	/**
	 * Exactly what is sent.
	 *
	 * @return array{install_id:string,site_url:string,version:string}
	 */
	public static function payload(): array {
		return array(
			'install_id' => self::install_id(),
			'site_url'   => home_url(),
			'version'    => LUCIDLAB_MPC_VERSION,
		);
	}

	/**
	 * Random install ID, created on the first ping.
	 *
	 * @return string
	 */
	public static function install_id(): string {
		$saved = get_option( self::INSTALL_ID, '' );
		$saved = is_string( $saved ) ? strtolower( $saved ) : '';
		if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $saved ) ) {
			return $saved;
		}
		$id = strtolower( substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 32 ) );
		update_option( self::INSTALL_ID, $id, false );
		return $id;
	}

	/**
	 * Admin requests by an administrator: keep the schedule in line and ping after an update.
	 */
	public static function admin_init(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::refresh();
	}

	/**
	 * Schedule or unschedule the weekly event, and ping when the versions changed since the last ping.
	 */
	public static function refresh(): void {
		if ( ! self::sync_schedule() ) {
			return;
		}
		$last = get_option( self::LAST, array() );
		$last = is_array( $last ) ? $last : array();
		if ( ( $last['signature'] ?? '' ) === self::signature() ) {
			if ( ! empty( $last['ok'] ) || time() - (int) ( $last['time'] ?? 0 ) < self::RETRY_AFTER ) {
				return;
			}
		}
		self::send();
	}

	/**
	 * Weekly event when allowed, none otherwise.
	 *
	 * @return bool Whether pinging is allowed.
	 */
	public static function sync_schedule(): bool {
		if ( ! self::allowed() ) {
			self::stop();
			return false;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', self::HOOK );
		}
		return true;
	}

	/**
	 * Opted out, Pro unlicensed or plugin deactivated: no event and no remembered ping, so a later
	 * opt-in pings again at once.
	 */
	public static function stop(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
		delete_option( self::LAST );
	}

	/**
	 * Weekly event. Checks the opt-in again, because the event may predate an opt-out.
	 */
	public static function cron(): void {
		if ( ! self::sync_schedule() ) {
			return;
		}
		self::send();
	}

	/**
	 * Settings saved: react only when the opt-in itself changed.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 */
	public static function settings_changed( $old_value, $value ): void {
		$was = is_array( $old_value ) && ! empty( $old_value[ self::SETTING ] );
		$now = is_array( $value ) && ! empty( $value[ self::SETTING ] );
		if ( $was === $now ) {
			return;
		}
		if ( $now ) {
			self::refresh(); // Opted in: ping now and schedule the weekly event.
		} else {
			self::sync_schedule(); // Opted out: unschedules at once unless licensed Pro still allows it.
		}
	}

	/**
	 * Settings created for the first time.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Settings.
	 */
	public static function settings_added( $option, $value ): void {
		unset( $option );
		self::settings_changed( array(), $value );
	}

	/**
	 * Settings deleted (back to defaults, so the opt-in is off).
	 *
	 * @param string $option Option name.
	 */
	public static function option_deleted( $option ): void {
		if ( LucidLab_MPC_Settings::OPTION === $option ) {
			self::sync_schedule();
		}
	}

	/**
	 * POST the payload to lucidlab.eu. Never blocks a page for more than 5 seconds.
	 *
	 * @return bool Whether lucidlab.eu accepted it.
	 */
	public static function send(): bool {
		if ( ! self::allowed() ) {
			return false;
		}
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode( self::payload() ),
				'data_format' => 'body',
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$ok       = 200 === $code;
		update_option(
			self::LAST,
			array(
				'signature' => self::signature(),
				'time'      => time(),
				'code'      => $code,
				'ok'        => $ok,
			),
			false
		);
		if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
			LucidLab_MPC_Logger::diag(
				'site-ping',
				$ok ? 'Install ping sent to lucidlab.eu.' : 'Install ping to lucidlab.eu failed.',
				array(
					'http'    => $code,
					'version' => LUCIDLAB_MPC_VERSION,
					'error'   => is_wp_error( $response ) ? $response->get_error_message() : '',
				),
				$ok ? 'info' : 'warning'
			);
		}
		return $ok;
	}

	/**
	 * Last attempt for the Options screen, or null when none is remembered.
	 *
	 * @return array{time:int,ok:bool,code:int}|null
	 */
	public static function last(): ?array {
		$last = get_option( self::LAST, array() );
		if ( ! is_array( $last ) || empty( $last['time'] ) ) {
			return null;
		}
		return array(
			'time' => (int) $last['time'],
			'ok'   => ! empty( $last['ok'] ),
			'code' => (int) ( $last['code'] ?? 0 ),
		);
	}
}
