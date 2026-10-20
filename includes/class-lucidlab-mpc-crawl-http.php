<?php
/**
 * Lucid Lab Media & Products Cleanup: parallel HTTP requests for the live-page check.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends several crawler requests at once, the way WP_Http::request() sends one.
 *
 * Every request passes the same WordPress HTTP filters and actions as wp_remote_request():
 * http_request_args, pre_http_request, http_api_curl, https_ssl_verify, http_api_debug and
 * http_response. Site request policies (blocked hosts, custom headers, cURL options) therefore
 * still apply, and parallel requests no longer have to be switched off when a plugin such as
 * WooCommerce hooks into them. Redirects are never followed by the transport; the crawler
 * checks every hop itself.
 */
final class LucidLab_MPC_Crawl_Http {

	/**
	 * Whether this site can send requests in parallel.
	 *
	 * @return bool
	 */
	public static function parallel_available(): bool {
		if ( ! function_exists( 'curl_multi_init' ) || ! class_exists( '\WpOrg\Requests\Requests' ) || ! class_exists( 'WP_HTTP_Requests_Hooks' ) || ! class_exists( 'WP_HTTP_Requests_Response' ) ) {
			return false;
		}
		// A replaced transport list means the site wants its own transport. Use the WordPress API one by one.
		return ! has_filter( 'http_api_transports' );
	}

	/**
	 * Send requests. Each request is an array with url, method, timeout, limit (bytes) and safe
	 * (true for addresses that are not this site: they get the wp_safe_remote_request() checks).
	 *
	 * @param array<int|string,array<string,mixed>> $requests Requests, keyed by the caller.
	 *
	 * @return array<int|string,array|WP_Error> WordPress response arrays or WP_Error, with the same keys.
	 */
	public static function send( array $requests ): array {
		$out = array();
		if ( count( $requests ) < 2 || ! self::parallel_available() ) {
			foreach ( $requests as $key => $request ) {
				$out[ $key ] = self::send_one( $request );
			}
			return $out;
		}
		$http  = function_exists( '_wp_http_get_object' ) ? _wp_http_get_object() : new WP_Http();
		$multi = array();
		$meta  = array();
		foreach ( $requests as $key => $request ) {
			$url  = (string) $request['url'];
			$args = self::args( $request );
			if ( ! empty( $args['stream'] ) || empty( $args['blocking'] ) || null !== $args['body'] ) {
				// A filter asked for something only the WordPress API can do.
				$out[ $key ] = self::send_one( $request );
				continue;
			}
			$pre = apply_filters( 'pre_http_request', false, $args, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core HTTP filter, applied as WP_Http::request() does.
			if ( false !== $pre ) {
				$out[ $key ] = $pre;
				continue;
			}
			if ( ! empty( $args['reject_unsafe_urls'] ) && ! wp_http_validate_url( $url ) ) {
				$out[ $key ] = new WP_Error( 'http_request_failed', __( 'A valid URL was not provided.', 'lucidlab-mpc-cleanup' ) );
				continue;
			}
			if ( $http->block_request( $url ) ) {
				$out[ $key ] = new WP_Error( 'http_request_not_executed', __( 'Requests to this address are blocked on this site.', 'lucidlab-mpc-cleanup' ) );
				continue;
			}
			$options = self::options( $url, $args );
			if ( null === $options ) {
				$out[ $key ] = self::send_one( $request );
				continue;
			}
			$multi[ $key ] = array(
				'url'     => $url,
				'headers' => (array) $args['headers'],
				'data'    => array(),
				'type'    => (string) $args['method'],
				'options' => $options,
			);
			$meta[ $key ]  = $args;
		}
		if ( ! $multi ) {
			return $out;
		}
		$responses = array();
		mbstring_binary_safe_encoding();
		try {
			$responses = \WpOrg\Requests\Requests::request_multiple( $multi, array( 'follow_redirects' => false ) );
		} catch ( \Throwable $error ) {
			$responses = array();
		} finally {
			reset_mbstring_encoding();
		}
		foreach ( $multi as $key => $request ) {
			$args = $meta[ $key ];
			$url  = (string) $request['url'];
			$raw  = $responses[ $key ] ?? null;
			if ( $raw instanceof \WpOrg\Requests\Response ) {
				$wrapped                   = new WP_HTTP_Requests_Response( $raw );
				$response                  = $wrapped->to_array();
				$response['http_response'] = $wrapped;
			} else {
				$response = new WP_Error( 'http_request_failed', $raw instanceof \Throwable ? $raw->getMessage() : __( 'No response.', 'lucidlab-mpc-cleanup' ) );
			}
			do_action( 'http_api_debug', $response, 'response', 'WpOrg\Requests\Requests', $args, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core HTTP action, fired as WP_Http::request() does.
			if ( ! is_wp_error( $response ) ) {
				$response = apply_filters( 'http_response', $response, $args, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core HTTP filter, applied as WP_Http::request() does.
			}
			$out[ $key ] = $response;
		}
		return $out;
	}

	/**
	 * Whether a failed request ran out of time.
	 *
	 * @param WP_Error $error Error.
	 *
	 * @return bool
	 */
	public static function is_timeout( WP_Error $error ): bool {
		$message = strtolower( $error->get_error_message() );
		return str_contains( $message, 'timed out' ) || str_contains( $message, 'curl error 28' ) || str_contains( $message, 'timeout' );
	}

	/**
	 * One request through the WordPress HTTP API.
	 *
	 * @param array<string,mixed> $request Request.
	 *
	 * @return array|WP_Error
	 */
	private static function send_one( array $request ) {
		return wp_remote_request(
			(string) $request['url'],
			array(
				'method'              => strtoupper( (string) ( $request['method'] ?? 'GET' ) ),
				'timeout'             => (float) ( $request['timeout'] ?? 5 ),
				'redirection'         => 0,
				'limit_response_size' => (int) ( $request['limit'] ?? 4096 ),
				'reject_unsafe_urls'  => ! empty( $request['safe'] ),
				'sslverify'           => true,
				'user-agent'          => self::agent( $request ),
			)
		);
	}

	/**
	 * User agent of a request: the one the caller set, else the WordPress default.
	 *
	 * @param array<string,mixed> $request Request.
	 *
	 * @return string
	 */
	private static function agent( array $request ): string {
		$agent = trim( (string) ( $request['agent'] ?? '' ) );
		if ( '' !== $agent ) {
			return $agent;
		}
		return (string) apply_filters( 'http_headers_useragent', 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ), (string) ( $request['url'] ?? '' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core HTTP filter, applied as WP_Http::request() does.
	}

	/**
	 * Request arguments with the defaults and filters of WP_Http::request().
	 *
	 * @param array<string,mixed> $request Request.
	 *
	 * @return array<string,mixed>
	 */
	private static function args( array $request ): array {
		$url  = (string) $request['url'];
		$args = array(
			'method'              => strtoupper( (string) ( $request['method'] ?? 'GET' ) ),
			'timeout'             => (float) ( $request['timeout'] ?? 5 ),
			'redirection'         => 0,
			'httpversion'         => apply_filters( 'http_request_version', '1.0', $url ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core HTTP filter, applied as WP_Http::request() does.
			'user-agent'          => self::agent( $request ),
			'reject_unsafe_urls'  => ! empty( $request['safe'] ),
			'blocking'            => true,
			'headers'             => array(),
			'cookies'             => array(),
			'body'                => null,
			'compress'            => false,
			'decompress'          => true,
			'sslverify'           => true,
			'sslcertificates'     => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			'stream'              => false,
			'filename'            => null,
			'limit_response_size' => (int) ( $request['limit'] ?? 4096 ),
		);
		$args = apply_filters( 'http_request_args', $args, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core HTTP filter, applied as WP_Http::request() does.
		$args = is_array( $args ) ? $args : array();
		if ( ! isset( $args['_redirection'] ) ) {
			$args['_redirection'] = $args['redirection'] ?? 0;
		}
		if ( ! isset( $args['headers'] ) || null === $args['headers'] ) {
			$args['headers'] = array();
		}
		if ( ! is_array( $args['headers'] ) ) {
			$processed       = WP_Http::processHeaders( (string) $args['headers'] );
			$args['headers'] = $processed['headers'];
		}
		$args['method']   = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$args['blocking'] = ! isset( $args['blocking'] ) || ! empty( $args['blocking'] );
		$args['body']     = $args['body'] ?? null;
		return $args;
	}

	/**
	 * Requests options for one request, or null when it has to go through the WordPress API (proxy).
	 *
	 * @param string              $url  Url.
	 * @param array<string,mixed> $args Arguments.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function options( string $url, array $args ): ?array {
		$proxy = new WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			return null;
		}
		$hooks = new WP_HTTP_Requests_Hooks( $url, $args );
		// WP_Http fires http_api_curl for each cURL handle; a parallel handle is announced as curl.before_multi_add.
		$hooks->register(
			'curl.before_multi_add',
			static function ( &$handle ) use ( $args, $url ) {
				do_action_ref_array( 'http_api_curl', array( &$handle, $args, $url ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core HTTP action, fired as WP_Http::request() does.
			}
		);
		$timeout = max( 1.0, (float) ( $args['timeout'] ?? 5 ) );
		$verify  = empty( $args['sslverify'] ) ? false : (string) ( $args['sslcertificates'] ?? ABSPATH . WPINC . '/certificates/ca-bundle.crt' );
		$verify  = apply_filters( 'https_ssl_verify', $verify, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core HTTP filter, applied as WP_Http::request() does.
		$options = array(
			'timeout'          => $timeout,
			'connect_timeout'  => min( $timeout, 5.0 ),
			'useragent'        => (string) ( $args['user-agent'] ?? '' ),
			'blocking'         => true,
			'hooks'            => $hooks,
			'follow_redirects' => false,
			'verify'           => $verify,
			'verifyname'       => ! empty( $args['sslverify'] ) && false !== $verify,
		);
		if ( ! empty( $args['limit_response_size'] ) ) {
			$options['max_bytes'] = (int) $args['limit_response_size'];
		}
		if ( ! empty( $args['cookies'] ) && is_array( $args['cookies'] ) ) {
			$options['cookies'] = WP_Http::normalize_cookies( $args['cookies'] );
		}
		return $options;
	}
}
