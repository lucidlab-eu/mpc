<?php
/**
 * Lucid Lab Media & Products Cleanup: ai class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Provider-neutral AI gateway; network calls are intentionally disabled until configured. */
class LucidLab_MPC_AI {
	/**
	 * Usage.
	 *
	 * @return array
	 */
	public static function usage(): array {
		$day   = gmdate( 'Y-m-d' );
		$usage = (array) get_option( 'lucidlab_mpc_ai_usage', array() );
		if ( ( $usage['day'] ?? '' ) !== $day ) {
			$usage = array(
				'day'      => $day,
				'requests' => 0,
				'estimate' => 0,
			);
		}
		$usage['limit']  = max( 1, (int) ( LucidLab_MPC_Settings::get( 'ai_daily_requests' ) ?? 100 ) );
		$usage['budget'] = max( 0, (float) LucidLab_MPC_Settings::get( 'ai_daily_budget' ) );
		return $usage;
	}

	/**
	 * Reserve before sending: failed/time-out requests can still be billed.
	 */
	public static function reserve_request() {
		global $wpdb;
		$lock = 'lucidlab_mpc_ai_usage_' . md5( $wpdb->prefix );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
			return new WP_Error( 'lucidlab_mpc_ai_usage_busy', __( 'Usage counter is busy. Retry shortly.', 'lucidlab-mpc-cleanup' ) );
		}
		try {
			wp_cache_delete( 'lucidlab_mpc_ai_usage', 'options' );
			$usage = self::usage();
			$cost  = self::provider() === 'local' ? 0 : max( 0, (float) LucidLab_MPC_Settings::get( 'ai_request_estimate' ) );
			if ( $usage['requests'] >= $usage['limit'] ) {
				return new WP_Error( 'lucidlab_mpc_ai_limit', __( 'Daily AI request limit reached. Limits reset at midnight UTC; resume then or adjust Options.', 'lucidlab-mpc-cleanup' ) );
			}
			if ( $usage['budget'] > 0 && self::provider() !== 'local' && $cost <= 0 ) {
				return new WP_Error( 'lucidlab_mpc_ai_limit', __( 'Set an estimated cost per request in Options before using a daily estimated budget.', 'lucidlab-mpc-cleanup' ) );
			}
			if ( $usage['budget'] > 0 && $usage['estimate'] + $cost > $usage['budget'] + 0.000001 ) {
				return new WP_Error( 'lucidlab_mpc_ai_limit', __( 'Daily estimated AI budget reached. Resume after midnight UTC or adjust Options.', 'lucidlab-mpc-cleanup' ) );
			}
			++$usage['requests'];
			$usage['estimate'] += $cost;
			update_option( 'lucidlab_mpc_ai_usage', $usage, false );
			return true;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch scan/backup/maintenance query; no core API, results are not reused across requests.
	}

	/**
	 * Resource error.
	 */
	public static function resource_error() {
		$error = LucidLab_MPC_Helpers::resource_error();
		if ( ! $error ) {
			return null;
		}
		return new WP_Error( 'lucidlab_mpc_ai_resource', $error->get_error_message() );
	}

	/**
	 * Locale used on the public site, not get_user_locale() for the admin.
	 *
	 * @return string
	 */
	public static function visitor_locale(): string {
		if ( function_exists( 'pll_default_language' ) ) {
			$loc = pll_default_language( 'locale' );
			if ( is_string( $loc ) && '' !== $loc ) {
				return $loc;
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$code = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core filter, called to read its language data.
			if ( is_string( $code ) && '' !== $code ) {
				$langs = apply_filters( 'wpml_active_languages', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core filter, called to read its language data.
				if ( is_array( $langs ) && ! empty( $langs[ $code ]['default_locale'] ) ) {
					return (string) $langs[ $code ]['default_locale'];
				}
				return strlen( $code ) > 2 ? $code : $code . '_' . strtoupper( $code );
			}
		}
		if ( function_exists( 'get_locale' ) ) {
			$locale = get_locale();
			if ( is_string( $locale ) && '' !== $locale ) {
				return $locale;
			}
		}
		return 'en_US';
	}

	/**
	 * Visitor language name.
	 *
	 * @return string
	 */
	public static function visitor_language_name(): string {
		return self::english_language_name( self::visitor_locale() );
	}

	/**
	 * Locales published on this site, in site order. English names are for the model; labels are for the admin.
	 *
	 * @return array
	 */
	public static function output_locale_choices(): array {
		$out = self::common_output_languages();
		foreach ( self::published_locale_codes() as $code ) {
			if ( ! isset( $out[ $code ] ) ) {
				$out[ $code ] = self::ui_language_name( $code );
			}
		}
		return $out;
	}

	/**
	 * Common suggestion languages, locale code => translated label.
	 *
	 * @return array<string,string>
	 */
	public static function common_output_languages(): array {
		return array(
			'en_US' => __( 'English', 'lucidlab-mpc-cleanup' ),
			'nl_NL' => __( 'Dutch', 'lucidlab-mpc-cleanup' ),
			'de_DE' => __( 'German', 'lucidlab-mpc-cleanup' ),
			'fr_FR' => __( 'French', 'lucidlab-mpc-cleanup' ),
			'es_ES' => __( 'Spanish', 'lucidlab-mpc-cleanup' ),
			'it_IT' => __( 'Italian', 'lucidlab-mpc-cleanup' ),
			'pt_PT' => __( 'Portuguese', 'lucidlab-mpc-cleanup' ),
			'pt_BR' => __( 'Portuguese (Brazil)', 'lucidlab-mpc-cleanup' ),
			'pl_PL' => __( 'Polish', 'lucidlab-mpc-cleanup' ),
			'sv_SE' => __( 'Swedish', 'lucidlab-mpc-cleanup' ),
			'da_DK' => __( 'Danish', 'lucidlab-mpc-cleanup' ),
			'nb_NO' => __( 'Norwegian', 'lucidlab-mpc-cleanup' ),
			'fi'    => __( 'Finnish', 'lucidlab-mpc-cleanup' ),
			'cs_CZ' => __( 'Czech', 'lucidlab-mpc-cleanup' ),
			'hu_HU' => __( 'Hungarian', 'lucidlab-mpc-cleanup' ),
			'ro_RO' => __( 'Romanian', 'lucidlab-mpc-cleanup' ),
			'el'    => __( 'Greek', 'lucidlab-mpc-cleanup' ),
			'tr_TR' => __( 'Turkish', 'lucidlab-mpc-cleanup' ),
			'ru_RU' => __( 'Russian', 'lucidlab-mpc-cleanup' ),
			'uk'    => __( 'Ukrainian', 'lucidlab-mpc-cleanup' ),
			'ar'    => __( 'Arabic', 'lucidlab-mpc-cleanup' ),
			'he_IL' => __( 'Hebrew', 'lucidlab-mpc-cleanup' ),
			'hi_IN' => __( 'Hindi', 'lucidlab-mpc-cleanup' ),
			'ja'    => __( 'Japanese', 'lucidlab-mpc-cleanup' ),
			'ko_KR' => __( 'Korean', 'lucidlab-mpc-cleanup' ),
			'zh_CN' => __( 'Chinese (Simplified)', 'lucidlab-mpc-cleanup' ),
			'zh_TW' => __( 'Chinese (Traditional)', 'lucidlab-mpc-cleanup' ),
		);
	}

	/**
	 * Whether a value is a locale code (the alternative is a custom language name).
	 *
	 * @param string $value Value.
	 *
	 * @return bool
	 */
	public static function is_locale_code( string $value ): bool {
		return (bool) preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $value );
	}

	/**
	 * Sanitize a custom language name typed under Other.
	 *
	 * @param string $name Name.
	 *
	 * @return string
	 */
	public static function sanitize_custom_language( string $name ): string {
		$name = preg_replace( '/[^\p{L}\p{M} ()\'\-]/u', '', $name ) ?? '';
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) ?? '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 60 ) : substr( $name, 0, 60 );
	}

	/**
	 * API key: LUCIDLAB_MPC_AI_KEY from wp-config.php wins over the saved option.
	 *
	 * @return string
	 */
	public static function api_key(): string {
		if ( self::key_from_config() ) {
			return trim( (string) constant( 'LUCIDLAB_MPC_AI_KEY' ) );
		}
		return class_exists( 'LucidLab_MPC_Settings', false ) ? (string) LucidLab_MPC_Settings::get( 'ai_api_key' ) : '';
	}

	/**
	 * Whether the API key is defined in wp-config.php.
	 *
	 * @return bool
	 */
	public static function key_from_config(): bool {
		return defined( 'LUCIDLAB_MPC_AI_KEY' ) && is_string( constant( 'LUCIDLAB_MPC_AI_KEY' ) ) && '' !== trim( (string) constant( 'LUCIDLAB_MPC_AI_KEY' ) );
	}

	/**
	 * Output locale.
	 *
	 * @return string
	 */
	public static function output_locale(): string {
		$choices = self::output_locale_choices();
		$saved   = class_exists( 'LucidLab_MPC_Settings', false ) ? str_replace( '-', '_', (string) LucidLab_MPC_Settings::get( 'ai_output_locale' ) ) : '';
		if ( '' !== $saved && isset( $choices[ $saved ] ) ) {
			return $saved;
		}
		// A language typed under Other, or a locale that is no longer offered, keeps working.
		if ( '' !== $saved ) {
			$custom = self::is_locale_code( $saved ) ? $saved : self::sanitize_custom_language( $saved );
			if ( '' !== $custom ) {
				return $custom;
			}
		}
		$choices = array_intersect_key( $choices, array_flip( self::published_locale_codes() ) );
		$visitor = str_replace( '-', '_', self::visitor_locale() );
		if ( isset( $choices[ $visitor ] ) ) {
			return $visitor;
		}
		$short = strtolower( substr( $visitor, 0, 2 ) );
		foreach ( array_keys( $choices ) as $code ) {
			if ( strtolower( substr( $code, 0, 2 ) ) === $short ) {
				return $code;
			}
		}
		$first = array_key_first( $choices );
		return $first ? (string) $first : 'en_US';
	}

	/**
	 * Output language name.
	 *
	 * @return string
	 */
	public static function output_language_name(): string {
		return self::english_language_name( self::output_locale() );
	}

	/**
	 * Ui language name.
	 *
	 * @param string $locale Locale.
	 *
	 * @return string
	 */
	public static function ui_language_name( string $locale ): string {
		if ( ! self::is_locale_code( str_replace( '-', '_', $locale ) ) ) {
			return $locale;
		}
		$common = self::common_output_languages();
		if ( isset( $common[ str_replace( '-', '_', $locale ) ] ) ) {
			return $common[ str_replace( '-', '_', $locale ) ];
		}
		$short = strtolower( substr( str_replace( '-', '_', $locale ), 0, 2 ) );
		$names = array(
			'nl' => __( 'Dutch', 'lucidlab-mpc-cleanup' ),
			'en' => __( 'English', 'lucidlab-mpc-cleanup' ),
			'de' => __( 'German', 'lucidlab-mpc-cleanup' ),
			'fr' => __( 'French', 'lucidlab-mpc-cleanup' ),
			'es' => __( 'Spanish', 'lucidlab-mpc-cleanup' ),
			'it' => __( 'Italian', 'lucidlab-mpc-cleanup' ),
			'pt' => __( 'Portuguese', 'lucidlab-mpc-cleanup' ),
		);
		if ( isset( $names[ $short ] ) ) {
			return $names[ $short ];
		}
		return self::english_language_name( $locale );
	}

	/**
	 * English language name.
	 *
	 * @param string $locale Locale.
	 *
	 * @return string
	 */
	private static function english_language_name( string $locale ): string {
		if ( ! self::is_locale_code( str_replace( '-', '_', $locale ) ) ) {
			return $locale;
		}
		if ( function_exists( 'locale_get_display_language' ) ) {
			$name = locale_get_display_language( str_replace( '-', '_', $locale ), 'en' );
			if ( is_string( $name ) && '' !== $name ) {
				return $name;
			}
		}
		return class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::language_name_from_locale( $locale ) : $locale;
	}

	/**
	 * Published locale codes.
	 *
	 * @return list<string>
	 */
	private static function published_locale_codes(): array {
		$codes = array();
		if ( function_exists( 'get_option' ) ) {
			$trp = get_option( 'trp_settings' );
			if ( is_array( $trp ) && ! empty( $trp['publish-languages'] ) && is_array( $trp['publish-languages'] ) ) {
				$codes = $trp['publish-languages'];
			}
		}
		if ( ! $codes && function_exists( 'pll_languages_list' ) ) {
			$listed = pll_languages_list( array( 'fields' => 'locale' ) );
			if ( is_array( $listed ) ) {
				$codes = $listed;
			}
		}
		if ( ! $codes && function_exists( 'apply_filters' ) ) {
			$active = apply_filters( 'wpml_active_languages', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core filter, called to read its language data.
			if ( is_array( $active ) ) {
				foreach ( $active as $row ) {
					if ( is_array( $row ) && ! empty( $row['default_locale'] ) ) {
						$codes[] = (string) $row['default_locale'];
					}
				}
			}
		}
		if ( ! $codes ) {
			$codes[] = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
			$codes[] = 'en_US';
			if ( function_exists( 'get_available_languages' ) ) {
				$codes = array_merge( $codes, get_available_languages() );
			}
		}
		$clean = array();
		foreach ( $codes as $code ) {
			$code = str_replace( '-', '_', (string) $code );
			if ( preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $code ) ) {
				$clean[ $code ] = $code;
			}
		}
		if ( ! $clean ) {
			$clean['en_US'] = 'en_US';
		}
		return array_values( $clean );
	}

	/**
	 * Analysis prompt.
	 *
	 * @return string
	 */
	public static function analysis_prompt(): string {
		$lang   = self::output_language_name();
		$locale = self::output_locale();
		return 'Describe the actual visible image. Write filename, title, alt_text, caption and description in ' . $lang . ( self::is_locale_code( $locale ) ? ' (locale ' . $locale . ')' : '' ) . '. The filename must name what is visible in the picture. Do not translate or reuse the current WordPress title or file name. Return only JSON with filename (descriptive lowercase ' . $lang . ' words separated by hyphens, without extension), title (one normal ' . $lang . ' sentence: start with a capital letter and capitalize names of people, places and brands), alt_text (same ' . $lang . ' sentence style for accessibility), caption (one concise factual sentence), description (one or two factual sentences, no invented details), labels, confidence, reasons and cleanup_type (screenshot, product or other). Classifications are uncertain review suggestions. Do not identify people. Do not return computer actions or coordinates.';
	}
	/**
	 * Change alt.
	 *
	 * @param int    $id   Id.
	 * @param bool   $undo Undo.
	 * @param string $alt  Alt.
	 */
	public static function change_alt( int $id, bool $undo, string $alt = '' ) {
		$current = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		$history = (array) get_option( 'lucidlab_mpc_ai_alt_history', array() );
		if ( $undo ) {
			foreach ( $history as $key => $entry ) {
				if ( (int) ( $entry['id'] ?? 0 ) !== $id || ! empty( $entry['undone'] ) ) {
					continue;
				}
				if ( $current !== (string) $entry['new'] ) {
					return new WP_Error( 'alt_conflict', __( 'Alt text changed since this action. Review the current text before undoing.', 'lucidlab-mpc-cleanup' ) );
				}
				$alt = (string) $entry['previous'];
				update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
				if ( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) !== $alt ) {
					return new WP_Error( 'alt_write', __( 'Could not restore alt text.', 'lucidlab-mpc-cleanup' ) );
				}
				$history[ $key ]['undone'] = time();
				update_option( 'lucidlab_mpc_ai_alt_history', $history, false );
				return array(
					'message'  => __( 'Previous alt text restored.', 'lucidlab-mpc-cleanup' ),
					'alt_text' => $alt,
				);
			}
			return new WP_Error( 'alt_missing', __( 'No saved alt-text change is available to undo.', 'lucidlab-mpc-cleanup' ) );
		}
		if ( '' === $alt ) {
			return new WP_Error( 'alt_empty', __( 'Enter alt text before applying.', 'lucidlab-mpc-cleanup' ) );
		}
		if ( $alt === $current ) {
			return array(
				'message'  => __( 'Skipped: alt text is unchanged.', 'lucidlab-mpc-cleanup' ),
				'alt_text' => $alt,
				'skipped'  => true,
			);
		}
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
		if ( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) !== $alt ) {
			return new WP_Error( 'alt_write', __( 'Could not save alt text.', 'lucidlab-mpc-cleanup' ) );
		}
		array_unshift(
			$history,
			array(
				'id'       => $id,
				'previous' => $current,
				'new'      => $alt,
				'time'     => time(),
			)
		);
		update_option( 'lucidlab_mpc_ai_alt_history', array_slice( $history, 0, 50 ), false );
		return array(
			'message'  => __( 'Alt text updated.', 'lucidlab-mpc-cleanup' ),
			'alt_text' => $alt,
		);
	}
	/**
	 * Provider.
	 *
	 * @return string
	 */
	public static function provider(): string {
		return (string) ( LucidLab_MPC_Helpers::either( LucidLab_MPC_Settings::get( 'ai_provider' ), 'none' ) ); }
	/**
	 * Masked label for a saved API key (shown as placeholder; the key itself is never printed).
	 *
	 * @param string $key Key.
	 *
	 * @return string
	 */
	public static function masked_key( string $key ): string {
		if ( '' === $key ) {
			return '';
		}
		/* translators: %s: last four characters of the saved API key. */
		return sprintf( __( 'Saved (…%s)', 'lucidlab-mpc-cleanup' ), substr( $key, -4 ) );
	}

	/**
	 * Check an AI endpoint before any request (and before an API key is attached).
	 *
	 * Cloud providers need https and a public host; requests go through wp_safe_remote_post(), which also
	 * refuses hosts that resolve to private or reserved addresses (DNS rebinding included). The local
	 * provider (Ollama / LM Studio) is meant for a local or LAN server and never receives the API key.
	 *
	 * @param string $provider Provider.
	 * @param string $endpoint Endpoint.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_endpoint( string $provider, string $endpoint ) {
		$parts  = wp_parse_url( $endpoint );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );
		if ( '' === $host || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return new WP_Error( 'lucidlab_mpc_ai_endpoint', __( 'The AI endpoint is not a valid URL.', 'lucidlab-mpc-cleanup' ) );
		}
		if ( 'local' === $provider ) {
			return in_array( $scheme, array( 'http', 'https' ), true ) ? true : new WP_Error( 'lucidlab_mpc_ai_endpoint', __( 'The local AI endpoint must start with http:// or https://.', 'lucidlab-mpc-cleanup' ) );
		}
		if ( 'https' !== $scheme ) {
			return new WP_Error( 'lucidlab_mpc_ai_endpoint', __( 'Cloud AI endpoints must use https://. Clear the endpoint field to use the provider default.', 'lucidlab-mpc-cleanup' ) );
		}
		$is_ip = filter_var( $host, FILTER_VALIDATE_IP );
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' )
			|| ( $is_ip && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) ) {
			return new WP_Error( 'lucidlab_mpc_ai_endpoint', __( 'Cloud AI endpoints cannot point to a local, private or reserved address. Use the local provider for a server on your own network.', 'lucidlab-mpc-cleanup' ) );
		}
		return true;
	}

	/**
	 * Endpoint.
	 *
	 * @return string
	 */
	public static function endpoint(): string {
		return self::normalize_endpoint( self::provider(), (string) ( LucidLab_MPC_Helpers::either( LucidLab_MPC_Settings::get( 'ai_endpoint' ), '' ) ) ); }

	/**
	 * Tidy an endpoint URL: remove repeated path segments (/api/api/v1 becomes /api/v1) and add the
	 * chat path when only an API base such as https://openrouter.ai/api/v1 was entered.
	 *
	 * @param string $provider Provider.
	 * @param string $endpoint Endpoint.
	 *
	 * @return string
	 */
	public static function normalize_endpoint( string $provider, string $endpoint ): string {
		$endpoint = trim( $endpoint );
		if ( '' === $endpoint ) {
			return '';
		}
		$parts = wp_parse_url( $endpoint );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $endpoint;
		}
		$path     = (string) ( $parts['path'] ?? '' );
		$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		$clean    = array();
		foreach ( $segments as $segment ) {
			if ( $clean && strtolower( (string) end( $clean ) ) === strtolower( $segment ) ) {
				continue;
			}
			$clean[] = $segment;
		}
		$path = '/' . implode( '/', $clean );
		if ( 'anthropic' === $provider && preg_match( '#/v1$#i', $path ) ) {
			$path .= '/messages';
		} elseif ( 'openai' === $provider && preg_match( '#/v1$#i', $path ) ) {
			$path .= '/chat/completions';
		}
		$url = strtolower( (string) ( $parts['scheme'] ?? 'https' ) ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . ( '/' === $path ? '' : $path );
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$url .= '?' . $parts['query'];
		}
		return $url;
	}
	/**
	 * Configured.
	 *
	 * @return bool
	 */
	public static function configured(): bool {
		return self::provider() === 'local' ? (bool) self::endpoint() : ( self::provider() !== 'none' && '' !== self::api_key() ); }
	/**
	 * Suggest.
	 *
	 * @param int $attachment_id Attachment id.
	 *
	 * @return array
	 */
	public static function suggest( int $attachment_id ): array {
		$title = get_the_title( $attachment_id );
		return array(
			'attachment_id'   => $attachment_id,
			'suggestion'      => $title ? sanitize_title( $title ) : '',
			'provider'        => self::provider(),
			'ready'           => self::configured(),
			'review_required' => true,
		);
	}
	/**
	 * Request.
	 *
	 * @param string $prompt    Prompt.
	 * @param array  $payload   Payload.
	 * @param array  $overrides Overrides.
	 */
	public static function request( string $prompt, array $payload = array(), array $overrides = array() ) {
		$provider   = sanitize_key( (string) ( $overrides['provider'] ?? self::provider() ) );
		$api_key    = (string) ( $overrides['api_key'] ?? self::api_key() );
		$endpoint   = (string) ( $overrides['endpoint'] ?? self::endpoint() );
		$endpoint   = self::normalize_endpoint( $provider, $endpoint );
		$model      = (string) ( $overrides['model'] ?? LucidLab_MPC_Settings::get( 'ai_model' ) );
		$configured = 'local' === $provider ? (bool) $endpoint : ( 'none' !== $provider && '' !== $api_key );
		if ( ! $configured ) {
			return new WP_Error( 'lucidlab_mpc_ai_unconfigured', __( 'AI provider is not configured.', 'lucidlab-mpc-cleanup' ) ); }
		if ( trim( $model ) === '' ) {
			return new WP_Error( 'lucidlab_mpc_ai_model', __( 'Enter an AI model before testing or analyzing.', 'lucidlab-mpc-cleanup' ) );
		}
		if ( ! in_array( $provider, array( 'openai', 'anthropic', 'local' ), true ) ) {
			return new WP_Error( 'lucidlab_mpc_ai_provider', __( 'Choose an AI provider before testing.', 'lucidlab-mpc-cleanup' ) );
		}
		self::prepare_runtime( $provider );
		if ( 'local' !== $provider ) {
			$resource = self::resource_error();
			if ( $resource ) {
				return $resource;
			}
		}
		if ( ! $endpoint ) {
			$endpoint = 'openai' === $provider ? 'https://api.openai.com/v1/chat/completions' : ( 'anthropic' === $provider ? 'https://api.anthropic.com/v1/messages' : 'http://127.0.0.1:11434/api/chat' ); }
		$token_cap      = (int) ( $overrides['max_tokens'] ?? 0 );
		$token_cap      = $token_cap > 0 ? max( 64, min( 1200, $token_cap ) ) : 0;
		$remote_tokens  = $token_cap > 0 ? $token_cap : 500;
		$local_tokens   = $token_cap > 0 ? $token_cap : 320;
		$valid_endpoint = self::validate_endpoint( $provider, $endpoint );
		if ( is_wp_error( $valid_endpoint ) ) {
			return $valid_endpoint;
		}
		$headers = array( 'Content-Type' => 'application/json' );
		if ( 'anthropic' === $provider ) {
			$headers['x-api-key']         = $api_key;
			$headers['anthropic-version'] = '2023-06-01';
		} elseif ( 'local' !== $provider ) {
			$headers['Authorization'] = 'Bearer ' . $api_key; }
		$body   = 'anthropic' === $provider ? array(
			'model'      => $model,
			'max_tokens' => $remote_tokens,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		) : array(
			'model'    => $model,
			'prompt'   => $prompt,
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);
		$images = array_values( array_filter( (array) ( $payload['images'] ?? array() ) ) );
		$mime   = (string) ( $payload['mime_type'] ?? 'image/jpeg' );
		if ( 'local' === $provider ) {
			$endpoint = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::ollama_chat_url( $endpoint ) : $endpoint;
			$is_v1    = (bool) preg_match( '#/v1/#i', $endpoint );
			if ( $is_v1 ) {
				$content = array(
					array(
						'type' => 'text',
						'text' => $prompt,
					),
				);
				foreach ( $images as $image ) {
					$content[] = array(
						'type'      => 'image_url',
						'image_url' => array( 'url' => 'data:' . $mime . ';base64,' . $image ),
					);
				}
				$body = array(
					'model'      => $model,
					'messages'   => array(
						array(
							'role'    => 'user',
							'content' => $content,
						),
					),
					'max_tokens' => $local_tokens,
				);
			} else {
				$message = array(
					'role'    => 'user',
					'content' => $prompt,
				);
				if ( $images ) {
					$message['images'] = $images;
				}
				$body = array(
					'model'    => $model,
					'messages' => array( $message ),
					'stream'   => false,
					'think'    => false,
					'options'  => array(
						'num_predict' => $local_tokens,
						'num_ctx'     => 4096,
						'temperature' => 0.2,
					),
				);
				if ( ! $images ) {
					$body['format'] = 'json';
				}
			}
		}
		if ( 'openai' === $provider ) {
			$content = array(
				array(
					'type' => 'text',
					'text' => $prompt,
				),
			);
			foreach ( $images as $image ) {
				$content[] = array(
					'type'      => 'image_url',
					'image_url' => array( 'url' => 'data:' . $mime . ';base64,' . $image ),
				);
			}
			$body = array(
				'model'    => $model,
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => $content,
					),
				),
			);
		} elseif ( 'anthropic' === $provider && $images ) {
			$content = array();
			foreach ( $images as $image ) {
				$content[] = array(
					'type'   => 'image',
					'source' => array(
						'type'       => 'base64',
						'media_type' => $mime,
						'data'       => $image,
					),
				);
			}
			$content[]                      = array(
				'type' => 'text',
				'text' => $prompt,
			);
			$body['messages'][0]['content'] = $content;
		}
		if ( 'openai' === $provider ) {
			$body['max_tokens'] = $remote_tokens;
		}
		$reservation = self::reserve_request();
		if ( is_wp_error( $reservation ) ) {
			return $reservation;
		}
		$timeout = 'local' === $provider ? 180 : 90;
		if ( 'local' !== $provider ) {
			$max = (int) ini_get( 'max_execution_time' );
			if ( $max > 0 ) {
				$timeout = max( 1, min( $timeout, $max - ( microtime( true ) - ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ) ) ) - 5 ) );
			}
		}
		$args     = array(
			'timeout'             => $timeout,
			'headers'             => $headers,
			'body'                => wp_json_encode( $body ),
			'limit_response_size' => 1024 * 1024,
			// Never follow a redirect with the API key attached.
			'redirection'         => 0,
		);
		$response = 'local' === $provider
			? wp_remote_post( esc_url_raw( $endpoint ), $args )
			: wp_safe_remote_post( esc_url_raw( $endpoint ), $args );
		if ( LucidLab_MPC_Settings::get( 'logging' ) ) {
			LucidLab_MPC_Logger::log( 'AI ' . $provider . ' request: ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response ) ) );
		}
		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			if ( 'local' === $provider && ( false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'timeout' ) ) ) {
				return new WP_Error(
					'lucidlab_mpc_ai_timeout',
					sprintf(
						'Ollama did not finish within %d seconds. Vision models such as qwen2.5vl are slow on CPU and on first load (the status stayed on Analyzing until this timeout). Try qwen2.5vl:3b for a quicker test, confirm the model name matches ollama list, and use endpoint http://127.0.0.1:11434/api/chat.',
						$timeout
					)
				);
			}
			return $response;
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! empty( $data['error'] ) ) {
			$detail = '';
			if ( is_array( $data ) ) {
				$err = $data['error'] ?? $data['message'] ?? '';
				if ( is_array( $err ) ) {
					$err = $err['message'] ?? $err['type'] ?? wp_json_encode( $err );
				}
				$detail = is_string( $err ) ? trim( $err ) : '';
			}
			$message = sprintf( /* translators: %s: HTTP status code. */ __( 'AI provider returned an error (HTTP %s)', 'lucidlab-mpc-cleanup' ), $status );
			if ( $detail ) {
				$message .= ': ' . $detail;
			}
			if ( $images && ( 400 === $status || false !== stripos( $detail, 'image' ) || false !== stripos( $detail, 'vision' ) ) ) {
				$message .= ' Image analysis needs a vision-capable model. Test connection only sends text, so it can succeed while Analyze fails. For local Ollama use a vision model such as qwen2.5vl, llama3.2-vision or llava.';
			} else {
				$message .= ' ' . __( 'Check the endpoint, model and credentials.', 'lucidlab-mpc-cleanup' );
			}
			return new WP_Error( 'lucidlab_mpc_ai_provider', $message );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'lucidlab_mpc_ai_json', __( 'AI provider returned an invalid JSON response.', 'lucidlab-mpc-cleanup' ) );
		}
		return $data;
	}
	/**
	 * Prepare runtime.
	 *
	 * @param string $provider Provider.
	 */
	private static function prepare_runtime( string $provider ): void {
		if ( function_exists( 'set_time_limit' ) ) {
			LucidLab_MPC_Helpers::extend_time_limit( 'local' === $provider ? 300 : 180 );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
	}

	/**
	 * Register privacy policy.
	 */
	public static function register_privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<p>' . esc_html__( 'Media & Products Cleanup stores scan results, quarantine records, rename history and AI suggestions in the WordPress database. Images are sent to an external service only when an administrator configures an AI provider and runs Analyze or Product AI. Analyze sends the picture so the provider can suggest a filename, title and alt text. Product AI sends a product featured image and the website description saved on that page so the provider can suggest a title, short description and long description. Nothing is written to the product until an administrator applies a field. Test connection sends a short text prompt and does not include an image. A local Ollama endpoint stays on the server you configure. Applied filenames, alt text and product text remain in WordPress.', 'lucidlab-mpc-cleanup' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Only when an administrator turns on the install count under Options (or the Pro add-on is licensed), the site sends its address, the plugin version and a random install ID to Lucid Lab once a week. Check live pages requests this site\'s own pages; with Pro and "Check other sites" ticked, it also requests the other sites those pages link to, which then receive the site\'s network address.', 'lucidlab-mpc-cleanup' ) . '</p>';
		$content .= '<p>' . sprintf(
			/* translators: %s: link to the Lucid Lab privacy policy. */
			esc_html__( 'Lucid Lab privacy policy: %s', 'lucidlab-mpc-cleanup' ),
			'<a href="' . esc_url( 'https://lucidlab.eu/privacy/' ) . '">https://lucidlab.eu/privacy/</a>'
		) . '</p>';
		wp_add_privacy_policy_content( __( 'Media & Products Cleanup', 'lucidlab-mpc-cleanup' ), wp_kses_post( $content ) );
	}

	/**
	 * Normalize.
	 *
	 * @param array $response Response.
	 *
	 * @return array
	 */
	public static function normalize( array $response ): array {
		$text = $response['message']['content'] ?? $response['content'][0]['text'] ?? $response['choices'][0]['message']['content'] ?? $response['response'] ?? '';
		if ( ( ! is_string( $text ) || '' === trim( $text ) ) && is_string( $response['message']['thinking'] ?? null ) ) {
			$text = $response['message']['thinking'];
		}
		if ( is_string( $text ) && '' !== $text ) {
			$text    = preg_replace( '/<think>.*?<\/think>/is', '', $text );
			$decoded = json_decode( trim( $text ), true );
			if ( ! is_array( $decoded ) && preg_match( '/\{.*\}/s', $text, $match ) ) {
				$decoded = json_decode( $match[0], true );
			}
			if ( is_array( $decoded ) ) {
				$response = $decoded;
			}
		}
		$filename = is_string( $response['filename'] ?? null ) ? $response['filename'] : '';
		$filename = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::filename_stem( $filename ) : sanitize_title( (string) preg_replace( '/(?:\.|-)(?:jpe?g|png|gif|webp|avif)$/i', '', $filename ) );
		$title    = is_string( $response['title'] ?? null ) ? $response['title'] : '';
		$title    = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::human_phrase( $title ) : sanitize_text_field( $title );
		$alt      = is_string( $response['alt_text'] ?? null ) ? $response['alt_text'] : '';
		$alt      = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::alt_phrase( $alt ) : sanitize_text_field( $alt );
		if ( '' === $title && '' !== $filename && class_exists( 'LucidLab_MPC_Helpers', false ) ) {
			$title = LucidLab_MPC_Helpers::title_from_slug( $filename );
		}
		if ( '' === $alt && '' !== $title ) {
			$alt = $title;
		}
		return array(
			'filename'        => $filename,
			'title'           => $title,
			'alt_text'        => $alt,
			'caption'         => self::copy_line( (string) ( $response['caption'] ?? '' ), 240 ),
			'description'     => self::copy_paragraphs( (string) ( $response['description'] ?? '' ), 500 ),
			'labels'          => array_values( array_filter( (array) ( $response['labels'] ?? array() ), 'is_string' ) ),
			'confidence'      => max( 0, min( 1, (float) ( $response['confidence'] ?? 0 ) ) ),
			'reasons'         => array_values( array_filter( (array) ( $response['reasons'] ?? array() ), 'is_string' ) ),
			'review_required' => true,
			'cleanup_type'    => in_array( $response['cleanup_type'] ?? '', array( 'screenshot', 'product', 'other' ), true ) ? $response['cleanup_type'] : 'other',
		);
	}

	/**
	 * Product copy fingerprint.
	 *
	 * @param int $attachment_id Attachment id.
	 *
	 * @return string
	 */
	public static function product_copy_fingerprint( int $attachment_id ): string {
		$file  = (string) get_attached_file( $attachment_id );
		$mtime = ( '' !== $file && is_file( $file ) ) ? (string) filemtime( $file ) : '0';
		$size  = ( '' !== $file && is_file( $file ) ) ? (string) filesize( $file ) : '0';
		return $attachment_id . ':' . $mtime . ':' . $size;
	}

	/**
	 * Site about.
	 *
	 * @return string
	 */
	public static function site_about(): string {
		$text = class_exists( 'LucidLab_MPC_Settings', false ) ? (string) LucidLab_MPC_Settings::get( 'ai_site_about' ) : '';
		return self::clip_site_about( $text );
	}

	/**
	 * Save site about.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	public static function save_site_about( string $text ): string {
		$text = self::clip_site_about( $text );
		if ( class_exists( 'LucidLab_MPC_Settings', false ) ) {
			LucidLab_MPC_Settings::update( array( 'ai_site_about' => $text ) );
		}
		return $text;
	}

	/**
	 * Clip site about.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	private static function clip_site_about( string $text ): string {
		$text = trim( sanitize_textarea_field( $text ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, 800 );
		}
		return substr( $text, 0, 800 );
	}

	/**
	 * Product copy prompt.
	 *
	 * @return string
	 */
	public static function product_copy_prompt(): string {
		$lang   = self::output_language_name();
		$locale = self::output_locale();
		$about  = trim( preg_replace( '/\s+/u', ' ', self::site_about() ) ?? '' );
		$prompt = 'Look at the product photo and write shop copy in ' . $lang . ( self::is_locale_code( $locale ) ? ' (locale ' . $locale . ')' : '' ) . '. ';
		if ( '' !== $about ) {
			$prompt .= 'The website is about: ' . $about . ( preg_match( '/[.!?]$/u', $about ) ? ' ' : '. ' );
			$prompt .= 'Use that description to focus the title, short description and long description on this kind of shop. The photo still decides what the product is. ';
		}
		$prompt .= 'Return only JSON with keys title, short_description, description, confidence and reasons. title is a short product name for what is visible, at most 80 characters. short_description is one or two sentences, at most 240 characters. description is two short plain-text paragraphs, at most 700 characters, with a blank line between them. confidence is a number from 0 to 1. reasons is an array of up to three short strings naming only what you can see. ';
		if ( '' !== $about ) {
			$prompt .= 'Describe only what is visible, using the website description for the kind of shop and the wording. Do not invent materials, dimensions, brand, awards, prices, stock or rankings that are not visible and not stated in the website description. ';
		} else {
			$prompt .= 'Describe only what is visible. Do not invent materials, dimensions, brand, awards, prices, stock or rankings. ';
		}
		return $prompt . 'Do not use HTML. Do not identify people.';
	}

	/**
	 * Suggest product copy.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public static function suggest_product_copy( int $attachment_id ) {
		self::prepare_runtime( self::provider() );
		if ( 'local' !== self::provider() ) {
			$resource = self::resource_error();
			if ( $resource ) {
				return $resource;
			}
		}
		$file     = class_exists( 'LucidLab_MPC_Helpers', false ) ? LucidLab_MPC_Helpers::attached_file_path( $attachment_id ) : (string) get_attached_file( $attachment_id );
		$max_edge = 'local' === self::provider() ? 640 : 1024;
		$source   = self::analysis_source( $attachment_id, $file, $max_edge );
		if ( '' === $source ) {
			return new WP_Error( 'lucidlab_mpc_ai_missing_file', __( 'Attachment file is missing.', 'lucidlab-mpc-cleanup' ) );
		}
		if ( filesize( $source ) > 20 * 1024 * 1024 ) {
			return new WP_Error( 'lucidlab_mpc_ai_large_file', __( 'This image exceeds the 20 MB analysis limit. Use a smaller image.', 'lucidlab-mpc-cleanup' ) );
		}
		$payload = self::image_payload( $source, (string) wp_check_filetype( $source )['type'], $max_edge );
		if ( empty( $payload['images'] ) ) {
			return new WP_Error( 'lucidlab_mpc_ai_read', __( 'The image could not be read.', 'lucidlab-mpc-cleanup' ) );
		}
		$result = self::request( self::product_copy_prompt(), $payload, array( 'max_tokens' => 900 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::normalize_product_copy( $result );
	}

	/**
	 * Normalize product copy.
	 *
	 * @param array $response Response.
	 *
	 * @return array|WP_Error
	 */
	public static function normalize_product_copy( array $response ): array|WP_Error {
		$text = $response['message']['content'] ?? $response['content'][0]['text'] ?? $response['choices'][0]['message']['content'] ?? $response['response'] ?? '';
		if ( ( ! is_string( $text ) || '' === trim( $text ) ) && is_string( $response['message']['thinking'] ?? null ) ) {
			$text = $response['message']['thinking'];
		}
		if ( is_string( $text ) && '' !== $text ) {
			$text    = preg_replace( '/<think>.*?<\/think>/is', '', $text );
			$decoded = json_decode( trim( (string) $text ), true );
			if ( ! is_array( $decoded ) && preg_match( '/\{.*\}/s', (string) $text, $match ) ) {
				$decoded = json_decode( $match[0], true );
			}
			if ( is_array( $decoded ) ) {
				$response = $decoded;
			}
		}
		$title = self::copy_line( (string) ( $response['title'] ?? '' ), 80 );
		$short = self::copy_line( (string) ( $response['short_description'] ?? '' ), 240 );
		$long  = self::copy_paragraphs( (string) ( $response['description'] ?? '' ), 700 );
		if ( '' === $title && '' === $short && '' === $long ) {
			return new WP_Error( 'lucidlab_mpc_ai_empty', __( 'The model returned no title or description. Check that the selected model supports images and try again.', 'lucidlab-mpc-cleanup' ) );
		}
		$reasons = array();
		foreach ( array_slice( (array) ( $response['reasons'] ?? array() ), 0, 3 ) as $reason ) {
			if ( is_string( $reason ) ) {
				$line = self::copy_line( $reason, 160 );
				if ( '' !== $line ) {
					$reasons[] = $line;
				}
			}
		}
		return array(
			'title'             => $title,
			'short_description' => $short,
			'description'       => $long,
			'confidence'        => max( 0, min( 1, (float) ( $response['confidence'] ?? 0 ) ) ),
			'reasons'           => $reasons,
			'review_required'   => true,
		);
	}

	/**
	 * Copy line.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max.
	 *
	 * @return string
	 */
	private static function copy_line( string $text, int $max ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) ?? '' );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max );
		}
		return substr( $text, 0, $max );
	}

	/**
	 * Copy paragraphs.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max.
	 *
	 * @return string
	 */
	private static function copy_paragraphs( string $text, int $max ): string {
		$text = trim( wp_strip_all_tags( $text ) );
		$text = preg_replace( "/[ \t]+/u", ' ', $text ) ?? $text;
		$text = preg_replace( "/\n{3,}/", "\n\n", $text ) ?? $text;
		if ( function_exists( 'mb_substr' ) ) {
			return trim( mb_substr( $text, 0, $max ) );
		}
		return trim( substr( $text, 0, $max ) );
	}

	/**
	 * Analyze image.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $prompt        Prompt.
	 */
	public static function analyze_image( int $attachment_id, string $prompt = '' ) {
		if ( '' === $prompt ) {
			$prompt = self::analysis_prompt();
		}
		self::prepare_runtime( self::provider() );
		if ( 'local' !== self::provider() ) {
			$resource = self::resource_error();
			if ( $resource ) {
				return $resource;
			}
		}
		$file     = (string) get_attached_file( $attachment_id );
		$max_edge = 'local' === self::provider() ? 640 : 1024;
		$source   = self::analysis_source( $attachment_id, $file, $max_edge );
		if ( '' === $source ) {
			return new WP_Error( 'lucidlab_mpc_ai_missing_file', __( 'Attachment file is missing.', 'lucidlab-mpc-cleanup' ) );
		}
		if ( filesize( $source ) > 20 * 1024 * 1024 ) {
			return new WP_Error( 'lucidlab_mpc_ai_large_file', __( 'This image exceeds the 20 MB analysis limit. Use a smaller image.', 'lucidlab-mpc-cleanup' ) );
		}
		$memory = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $memory > 0 && $memory - memory_get_usage( true ) < filesize( $source ) * 8 + 32 * 1024 * 1024 ) {
			return new WP_Error( 'lucidlab_mpc_ai_resource', __( 'Analysis paused: insufficient PHP memory to encode this image. Increase the memory limit before resuming.', 'lucidlab-mpc-cleanup' ) );
		}
		$payload = self::image_payload( $source, (string) wp_check_filetype( $source )['type'], $max_edge );
		if ( empty( $payload['images'] ) ) {
			return new WP_Error( 'lucidlab_mpc_ai_read', __( 'The image could not be read.', 'lucidlab-mpc-cleanup' ) );
		}
		$result = self::request( $prompt, $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = self::normalize( $result );
		if ( ! $result['filename'] && ! $result['alt_text'] && ! $result['title'] ) {
			return new WP_Error( 'lucidlab_mpc_ai_empty', __( 'The model returned no filename, title or alt text. Check that the selected model supports images and try again.', 'lucidlab-mpc-cleanup' ) );
		}
		if ( sanitize_title( pathinfo( $file, PATHINFO_FILENAME ) ) === $result['filename'] ) {
			$result['filename']  = '';
			$result['reasons'][] = __( 'Filename already matches the suggestion; renaming is unnecessary.', 'lucidlab-mpc-cleanup' );
		}
		return $result;
	}

	/**
	 * Smallest uncropped copy that still covers $max_edge, so large originals are not decoded. An AVIF-only attachment falls back to its WebP copy or the original, in uploads or quarantine.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $file          File.
	 * @param int    $max_edge      Max edge.
	 *
	 * @return string
	 */
	private static function analysis_source( int $attachment_id, string $file, int $max_edge ): string {
		$candidates = array();
		$meta       = wp_get_attachment_metadata( $attachment_id );
		$w          = (int) ( $meta['width'] ?? 0 );
		$h          = (int) ( $meta['height'] ?? 0 );
		if ( '' !== $file && $w > 0 && $h > 0 ) {
			$sizes = array();
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				$sw = (int) ( $size['width'] ?? 0 );
				$sh = (int) ( $size['height'] ?? 0 );
				if ( $sw < 1 || $sh < 1 || empty( $size['file'] ) || max( $sw, $sh ) < $max_edge || abs( $sw / $sh - $w / $h ) > 0.02 ) {
					continue;
				}
				$sizes[ max( $sw, $sh ) ] = dirname( $file ) . '/' . wp_basename( (string) $size['file'] );
			}
			ksort( $sizes );
			$candidates = array_values( $sizes );
		}
		$candidates[] = $file;
		$base         = rtrim( wp_normalize_path( LucidLab_MPC_Helpers::uploads_basedir() ), '/' ) . '/';
		$norm         = wp_normalize_path( $file );
		if ( strpos( $norm, $base ) === 0 && preg_match( '#^(?:(webp|avif)/)?(.+?)(?:\.(webp|avif))?$#i', substr( $norm, strlen( $base ) ), $m ) ) {
			$logical      = '' !== $m[1] && ! empty( $m[3] ) ? $m[2] : substr( $norm, strlen( $base ) );
			$candidates[] = $base . 'webp/' . $logical . '.webp';
			$candidates[] = $base . $logical;
			if ( class_exists( 'LucidLab_MPC_Quarantine' ) ) {
				$candidates[] = ( new LucidLab_MPC_Quarantine() )->find_original( $logical );
			}
		}
		foreach ( $candidates as $path ) {
			if ( '' !== $path && is_file( $path ) && in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'jpg', 'jpeg', 'jpe', 'png', 'webp', 'gif' ), true ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Image payload.
	 *
	 * @param string $file     File.
	 * @param string $mime     Mime.
	 * @param int    $max_edge Max edge.
	 *
	 * @return array
	 */
	private static function image_payload( string $file, string $mime, int $max_edge = 1024 ): array {
		$max_edge = max( 256, min( 1280, $max_edge ) );
		$editor   = function_exists( 'wp_get_image_editor' ) ? wp_get_image_editor( $file ) : null;
		if ( $editor && ! is_wp_error( $editor ) ) {
			$size = method_exists( $editor, 'get_size' ) ? $editor->get_size() : array();
			$w    = (int) ( $size['width'] ?? 0 );
			$h    = (int) ( $size['height'] ?? 0 );
			if ( $w > $max_edge || $h > $max_edge ) {
				$editor->resize( $max_edge, $max_edge, false );
			}
			if ( ! function_exists( 'wp_tempnam' ) ) {
				require_once LucidLab_MPC_Helpers::admin_dir() . 'includes/file.php';
			}
			// Short-lived resized copy in the system temp folder (get_temp_dir()), deleted right after it is read.
			$tmp   = wp_tempnam( 'lucidlab-mpc-ai.jpg' );
			$saved = class_exists( 'LucidLab_MPC_Helpers', false )
				? LucidLab_MPC_Helpers::editor_save_quality( $editor, $tmp, 'image/jpeg', 80 )
				: $editor->save( $tmp, 'image/jpeg' );
			$path  = is_array( $saved ) ? (string) ( $saved['path'] ?? '' ) : '';
			if ( $tmp !== $path && is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			if ( $path && is_file( $path ) ) {
				$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
				wp_delete_file( $path );
				if ( $raw ) {
					return array(
						'images'    => array( base64_encode( $raw ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Image bytes must be base64 for the AI provider API.
						'mime_type' => 'image/jpeg',
					);
				}
			}
		}
		// Vision APIs reject AVIF and many local models only read JPEG and PNG.
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return array();
		}
		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( false === $raw ) {
			return array();
		}
		return array(
			'images'    => array( base64_encode( $raw ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Image bytes must be base64 for the AI provider API.
			'mime_type' => $mime,
		);
	}

	/**
	 * Analyze image with context about visual similarity to improve suggestions. If the image is visually similar to others, the AI can suggest renaming duplicates to indicate their relationship or recommend keeping only the best variant.
	 *
	 * @param int   $attachment_id      Attachment id.
	 * @param array $similarity_context Similarity context.
	 *
	 * @return array|WP_Error
	 */
	public static function analyze_with_similarity( int $attachment_id, array $similarity_context = array() ): array|WP_Error {
		// Start with standard analysis.
		$base_analysis = self::analyze_image( $attachment_id );
		if ( is_wp_error( $base_analysis ) ) {
			return $base_analysis;
		}

		// Enhance with similarity context if available.
		if ( ! empty( $similarity_context['duplicate_group_ids'] ) && count( $similarity_context['duplicate_group_ids'] ) > 1 ) {
			$group_ids = array_filter( array_map( 'absint', $similarity_context['duplicate_group_ids'] ) );
			$group_ids = array_values( $group_ids );

			if ( count( $group_ids ) > 1 && in_array( $attachment_id, $group_ids, true ) ) {
				// This image is part of a visual duplicate group.
				$similarity_score = (float) ( $similarity_context['min_similarity'] ?? 85 ); // 0-100
				$group_size       = count( $group_ids );
				$best_id          = (int) ( $similarity_context['best_id'] ?? $group_ids[0] );
				$is_best          = ( $attachment_id === $best_id );

				if ( ! $is_best && $similarity_score >= 85 ) {
					// High-confidence duplicate - suggest consolidation.
					if ( ! in_array( __( 'Part of a visual duplicate group; consider consolidating.', 'lucidlab-mpc-cleanup' ), $base_analysis['reasons'], true ) ) {
						$base_analysis['reasons'][] = __( 'Part of a visual duplicate group; consider consolidating.', 'lucidlab-mpc-cleanup' );
					}
					if ( 'other' === $base_analysis['cleanup_type'] ) {
						$base_analysis['cleanup_type'] = 'duplicate';
					}
					if ( $base_analysis['confidence'] < 0.8 ) {
						$base_analysis['confidence'] = max( $base_analysis['confidence'], 0.7 );
					}
				} elseif ( ! $is_best && $similarity_score >= 70 ) {
					// Moderate-confidence duplicate - flag for review.
					if ( ! in_array( __( 'Possibly similar to other images; manual review recommended.', 'lucidlab-mpc-cleanup' ), $base_analysis['reasons'], true ) ) {
						$base_analysis['reasons'][] = __( 'Possibly similar to other images; manual review recommended.', 'lucidlab-mpc-cleanup' );
					}
				} elseif ( $is_best && $group_size > 1 ) {
					// This is the recommended image to keep.
					if ( ! in_array( __( 'Recommended to keep (lowest similarity score in group).', 'lucidlab-mpc-cleanup' ), $base_analysis['reasons'], true ) ) {
						$base_analysis['reasons'][] = __( 'Recommended to keep (lowest similarity score in group).', 'lucidlab-mpc-cleanup' );
					}
					$base_analysis['confidence'] = min( 1.0, $base_analysis['confidence'] + 0.1 );
				}
			}
		}

		return $base_analysis;
	}
}
