<?php
/**
 * Lucid Lab Media & Products Cleanup: settings class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central settings (single option, merged). Owns all tunables + toggles.
 */
class LucidLab_MPC_Settings {

	const OPTION = 'lucidlab_mpc_settings';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'report_enabled'           => 0,
			'report_recipient'         => '',
			'report_frequency'         => 'weekly',
			'schedule_weekday'         => 1,
			'schedule_hour'            => 3,
			'batch_db'                 => 500,
			'batch_files'              => 400,
			'scan_batch_db'            => 500,
			'missing_batch'            => 50,
			'regen_batch'              => 15,
			'list_size'                => 200,
			'jpeg_quality'             => 85,
			'disabled_sizes'           => array(),
			'logging'                  => 0,
			'autorun_weekly'           => array(),
			'autorun_monthly'          => array(),
			'quarantine_days'          => 30,
			'quarantine_auto_purge'    => 0, // Permanent deletion: off until an administrator ticks it in Options.
			'install_ping'             => 0, // Free opt-in: send site URL and plugin version to lucidlab.eu.
			'delete_data_on_uninstall' => 0, // Opt-in: uninstall.php removes all plugin data and files.
			'ai_provider'              => 'none',
			'ai_api_key'               => '',
			'ai_endpoint'              => '',
			'ai_model'                 => '',
			'ai_output_locale'         => '',
			'ai_site_about'            => '',
			'ai_daily_requests'        => 100,
			'ai_request_estimate'      => 0,
			'ai_daily_budget'          => 0,
			'ai_min_confidence'        => 0.65,
			'memory_threshold'         => 256, // MB - pause if memory usage exceeds this.
			'timeout_threshold'        => 25, // seconds - pause if approaching timeout.
			'exclude_folders'          => '',
			'exclude_mimes'            => '',
			'exclude_newer_days'       => 0,
			'exclude_featured'         => 0,
			'backup_keep'              => 5,
			'page_webp'                => array(
				'webp_enabled'      => 1,
				'avif_enabled'      => 1,
				'webp_mode'         => 'all',
				'avif_mode'         => 'all',
				'webp_images'       => 0,
				'webp_thumbnails'   => 0,
				'avif_images'       => 0,
				'avif_thumbnails'   => 0,
				'skip_existing'     => 1,
				'replace_originals' => 0,
			),
			'page_regen'               => array(
				'mode'    => 'skip',
				'quality' => 85,
				'sizes'   => array(),
			),
		);
	}

	/**
	 * Saved WebP / AVIF page options (merged with defaults).
	 *
	 * @return array
	 */
	public static function page_webp(): array {
		$saved = self::get( 'page_webp' );
		$base  = self::defaults()['page_webp'];
		return is_array( $saved ) ? array_merge( $base, $saved ) : $base;
	}

	/**
	 * Saved Regenerate Thumbnails page options (merged with defaults).
	 *
	 * @return array
	 */
	public static function page_regen(): array {
		$saved          = self::get( 'page_regen' );
		$base           = self::defaults()['page_regen'];
		$out            = is_array( $saved ) ? array_merge( $base, $saved ) : $base;
		$out['sizes']   = array_values( array_filter( array_map( 'sanitize_key', (array) ( $out['sizes'] ?? array() ) ) ) );
		$out['mode']    = in_array( (string) ( $out['mode'] ?? '' ), array( 'skip', 'all', 'featured' ), true ) ? (string) $out['mode'] : 'skip';
		$out['quality'] = max( 1, min( 100, (int) ( $out['quality'] ?? 85 ) ) );
		return $out;
	}

	/**
	 * Save page webp.
	 *
	 * @param array $input Input.
	 *
	 * @return array
	 */
	public static function save_page_webp( array $input ): array {
		$prev = self::page_webp();
		$caps = array(
			'webp' => true,
			'avif' => true,
		);
		if ( class_exists( 'LucidLab_MPC_Format_Preview', false ) ) {
			$caps = LucidLab_MPC_Format_Preview::caps();
		}
		$out = $prev;
		foreach ( array( 'webp', 'avif' ) as $fmt ) {
			if ( 'avif' === $fmt && ! LucidLab_MPC_Edition::pro() ) {
				continue;
			}
			$row = is_array( $input[ $fmt ] ?? null ) ? $input[ $fmt ] : null;
			if ( null !== $row ) {
				$out[ $fmt . '_enabled' ]    = ! empty( $row['enabled'] ) && ! empty( $caps[ $fmt ] ) ? 1 : 0;
				$out[ $fmt . '_mode' ]       = ( ( $row['mode'] ?? 'all' ) === 'featured' ) ? 'featured' : 'all';
				$out[ $fmt . '_images' ]     = max( 0, min( 100, (int) ( $row['images'] ?? 0 ) ) );
				$out[ $fmt . '_thumbnails' ] = max( 0, min( 100, (int) ( $row['thumbnails'] ?? 0 ) ) );
			}
		}
		if ( array_key_exists( 'skip_existing', $input ) ) {
			$out['skip_existing'] = empty( $input['skip_existing'] ) ? 0 : 1;
		}
		if ( LucidLab_MPC_Edition::pro() && array_key_exists( 'replace_originals', $input ) ) {
			$out['replace_originals'] = empty( $input['replace_originals'] ) ? 0 : 1;
		}
		self::update( array( 'page_webp' => $out ) );
		return $out;
	}

	/**
	 * Save page regen.
	 *
	 * @param array $input Input.
	 *
	 * @return array
	 */
	public static function save_page_regen( array $input ): array {
		$enabled = array_values( array_diff( array_keys( wp_get_registered_image_subsizes() ), (array) self::get( 'disabled_sizes' ) ) );
		$sizes   = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['sizes'] ?? array() ) ), $enabled ) );
		$out     = array(
			'mode'    => in_array( (string) ( $input['mode'] ?? '' ), array( 'skip', 'all', 'featured' ), true ) ? (string) $input['mode'] : 'skip',
			'quality' => max( 1, min( 100, (int) ( $input['quality'] ?? 85 ) ) ),
			'sizes'   => $sizes,
		);
		self::update( array( 'page_regen' => $out ) );
		return $out;
	}

	/**
	 * All.
	 *
	 * @return array
	 */
	public static function all(): array {
		$o = get_option( self::OPTION );
		return is_array( $o ) ? array_merge( self::defaults(), $o ) : self::defaults();
	}

	/**
	 * Get.
	 *
	 * @param string $key Key.
	 */
	public static function get( string $key ) {
		$a = self::all();
		return $a[ $key ] ?? null;
	}

	/**
	 * One-time changes to saved settings; each revision runs once per site.
	 */
	public static function upgrade(): void {
		$revision = (int) get_option( 'lucidlab_mpc_settings_revision', 0 );
		if ( $revision >= 1 ) {
			return;
		}
		$saved = get_option( self::OPTION );
		// Saving Options stores every field, so the old default of 5 is kept even when nobody chose it.
		if ( is_array( $saved ) && 5 === (int) ( $saved['missing_batch'] ?? 0 ) ) {
			self::update( array( 'missing_batch' => 50 ) );
		}
		update_option( 'lucidlab_mpc_settings_revision', 1, true );
	}

	/**
	 * Update.
	 *
	 * @param array $patch Patch.
	 */
	public static function update( array $patch ): void {
		update_option( self::OPTION, array_merge( self::all(), $patch ) );
	}
}
