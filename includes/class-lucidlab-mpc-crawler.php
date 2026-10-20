<?php
/**
 * Lucid Lab Media & Products Cleanup: crawler class.
 *
 * @package LucidLab_MPC
 */

/**
 * Opens each public page and checks what its HTML loads or links to: images (src, srcset,
 * backgrounds), stylesheets and what they load, scripts, fonts, video and audio, documents
 * and links to other pages. Findings are 4xx/5xx responses, timeouts and redirect loops,
 * with the page they were found on. Pro can also check addresses on other sites.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LucidLab_MPC_Crawler.
 */
final class LucidLab_MPC_Crawler {

	const TIME_BUDGET = 6.0;

	/** State format. 2 = items per page (images, files, links), parallel checks. */
	const FORMAT = 2;

	/** New addresses checked per parallel batch, taken from the current and the queued pages. */
	const ITEM_BATCH = 64;

	/** Items taken from one page at most. */
	const MAX_ITEMS_PER_PAGE = 2000;

	/** Pages listed for one broken address; more pages are counted, not listed. */
	const MAX_ROWS_PER_URL = 25;

	/** Redirect hops followed per address. */
	const MAX_HOPS = 5;

	/** Requests per second to this site, by default (lucidlab_mpc_crawler_rate filter). */
	const DEFAULT_RATE = 4.0;

	/** First pause after the site answers 429 or 503, in seconds; it doubles for each further pause. */
	const PAUSE_FIRST = 60;

	/** Longest pause chosen by the check itself, in seconds. A Retry-After header may ask for up to PAUSE_HONOUR. */
	const PAUSE_MAX = 1800;

	/** Longest Retry-After that is honoured, in seconds. */
	const PAUSE_HONOUR = 3600;

	/** Answers without 429/503 needed before the check speeds up one step again. */
	const RAMP_AFTER = 30;

	/** Times one address is asked again after 429/503 before it is reported as inconclusive. */
	const MAX_RETRIES = 8;

	/** Item kinds, as stored in findings. */
	const KINDS = array( 'image', 'srcset', 'background', 'css', 'js', 'font', 'media', 'file', 'link', 'iframe', 'page' );

	/** Extensions of documents and downloads. */
	const FILE_EXT = 'pdf|docx?|xlsx?|pptx?|odt|ods|odp|rtf|txt|csv|zip|rar|7z|gz|tar|epub|mobi|psd|ai|eps|dwg|stl|apk|dmg|exe|msi|ics|vcf|xml|json|kml|gpx';

	/** Extensions of images. */
	const IMAGE_EXT = 'jpe?g|png|gif|webp|avif|svg|bmp|ico|tiff?|heic|jxl';

	/** Extensions of video and audio. */
	const MEDIA_EXT = 'mp4|m4v|webm|ogv|mov|avi|mkv|mp3|m4a|ogg|oga|wav|flac|aac|vtt|srt';

	/** Extensions of fonts. */
	const FONT_EXT = 'woff2?|ttf|otf|eot';

	/**
	 * Urls file.
	 *
	 * @param int $uid Uid.
	 *
	 * @return string
	 */
	public static function urls_file( int $uid ): string {
		return 'crawl-urls-' . max( 0, $uid ) . '.jsonl';
	}

	/**
	 * Findings file.
	 *
	 * @param int $uid Uid.
	 *
	 * @return string
	 */
	public static function findings_file( int $uid ): string {
		return 'crawl-findings-' . max( 0, $uid ) . '.jsonl';
	}

	/**
	 * Status file.
	 *
	 * @param int $uid Uid.
	 *
	 * @return string
	 */
	public static function status_file( int $uid ): string {
		return 'crawl-status-' . max( 0, $uid ) . '.json';
	}

	/**
	 * Reset.
	 *
	 * @param int $uid Uid.
	 */
	public static function reset( int $uid ): void {
		if ( class_exists( 'LucidLab_MPC_Work', false ) ) {
			LucidLab_MPC_Work::delete( self::urls_file( $uid ), self::findings_file( $uid ), self::status_file( $uid ), self::listed_file( $uid ) );
		}
	}

	/**
	 * Saved choices for the live-page check: links (internal links) and external (Pro).
	 *
	 * @return array{links:bool,external:bool}
	 */
	public static function saved_options(): array {
		$saved = get_option( 'lucidlab_mpc_crawl_options', array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'links'    => ! array_key_exists( 'links', $saved ) || ! empty( $saved['links'] ),
			'external' => ! empty( $saved['external'] ),
		);
	}

	/**
	 * Remember the choices, so a scheduled check (Pro) uses them too.
	 *
	 * @param array $options Options with links and external.
	 *
	 * @return array{links:bool,external:bool}
	 */
	public static function save_options( array $options ): array {
		$clean = array(
			'links'    => ! empty( $options['links'] ),
			'external' => ! empty( $options['external'] ),
		);
		update_option( 'lucidlab_mpc_crawl_options', $clean, false );
		return $clean;
	}

	/**
	 * Initial state.
	 *
	 * @param array $options Options: links (check links to other pages of this site), external (Pro: check other sites).
	 *
	 * @return array<string,mixed>
	 */
	public static function initial_state( array $options = array() ): array {
		$options = array_merge( self::saved_options(), $options );
		$pro     = class_exists( 'LucidLab_MPC_Edition', false ) && LucidLab_MPC_Edition::pro();
		return array(
			'format'        => self::FORMAT,
			'phase'         => 'list',
			'stage'         => 'home',
			'listing_after' => 0,
			'offset'        => 0,
			'pages'         => 0,
			'checked'       => 0,
			'images'        => 0,
			'urls'          => 0,
			'broken'        => 0,
			'page_errors'   => 0,
			'image_errors'  => 0,
			'requests'      => 0,
			'local'         => 0,
			'cache_hits'    => 0,
			'suppressed'    => 0,
			'kinds'         => array(),
			'hits'          => array(),
			'links'         => ! empty( $options['links'] ),
			'external'      => $pro && ! empty( $options['external'] ),
			'started'       => time(),
			'cursor'        => array( 'offset' => 0 ),
			'page'          => array(),
			'page_queue'    => array(),
			'retry_rows'    => array(),
			'retries'       => array(),
			'throttled'     => 0,
			'pauses'        => 0,
			'throttle'      => self::fresh_throttle(),
		);
	}

	/**
	 * Throttle state of a new check: full speed, no pause.
	 *
	 * @return array{par:int,rate:float,cap_par:int,cap_rate:float,until:int,streak:int,level:int,reason:string}
	 */
	public static function fresh_throttle(): array {
		return array(
			'par'      => self::parallel_limit(),
			'rate'     => self::rate_limit(),
			'cap_par'  => self::parallel_limit(),
			'cap_rate' => self::rate_limit(),
			'until'    => 0,
			'streak'   => 0,
			'level'    => 0,
			'reason'   => '',
		);
	}

	/**
	 * Unix time until which the check waits because the site asked it to slow down, or 0.
	 *
	 * @param array<string,mixed> $state State.
	 *
	 * @return int
	 */
	public static function paused_until( array $state ): int {
		$until = (int) ( $state['throttle']['until'] ?? 0 );
		return $until > time() ? $until : 0;
	}

	/**
	 * Slow down after this site answered 429 Too Many Requests or 503: pause (at least as long as
	 * Retry-After asks, otherwise 60 s doubling per pause up to 30 min) and continue with one
	 * request at a time at one request per second. Returns the pause end.
	 *
	 * @param array<string,mixed> &$state      State.
	 * @param int                 $code        Status code (429 or 503).
	 * @param int                 $retry_after Seconds asked by Retry-After (0 = none).
	 * @param int|null            $now         Current time (tests).
	 *
	 * @return int
	 */
	public static function throttle_back( array &$state, int $code, int $retry_after, ?int $now = null ): int {
		$now      = $now ?? time();
		$throttle = array_merge( self::fresh_throttle(), (array) ( $state['throttle'] ?? array() ) );
		$level    = (int) $throttle['level'];
		$wait     = min( self::PAUSE_MAX, self::PAUSE_FIRST * ( 2 ** min( 10, $level ) ) );
		if ( $retry_after > 0 ) {
			$wait = max( $wait, min( self::PAUSE_HONOUR, $retry_after ) );
		}
		// Speed up again later to at most three quarters of the speed the server refused. A refusal
		// right after a pause (one request a second) means the server still blocks, not that it was
		// too fast, so the ceiling never drops below one request a second.
		if ( (int) $throttle['par'] > 1 || (float) $throttle['rate'] > 1.0 ) {
			$throttle['cap_par']  = max( 1, min( (int) $throttle['cap_par'], (int) $throttle['par'] - 1 ) );
			$throttle['cap_rate'] = max( 1.0, min( (float) $throttle['cap_rate'], 0.75 * (float) $throttle['rate'] ) );
		}
		$throttle['until']  = max( (int) $throttle['until'], $now + $wait );
		$throttle['level']  = $level + 1;
		$throttle['par']    = 1;
		$throttle['rate']   = min( 1.0, $throttle['cap_rate'] );
		$throttle['streak'] = 0;
		$throttle['reason'] = (string) $code;
		$state['throttle']  = $throttle;
		$state['pauses']    = (int) ( $state['pauses'] ?? 0 ) + 1;
		return (int) $throttle['until'];
	}

	/**
	 * Speed up one step after RAMP_AFTER answers without 429/503: one more request at a time and
	 * half a request per second more, up to the configured limits and to three quarters of the speed
	 * that was refused. After 4 × RAMP_AFTER quiet answers
	 * the next pause starts short again.
	 *
	 * @param array<string,mixed> &$state State.
	 * @param int                 $ok     Answers without 429/503 in this round.
	 */
	public static function throttle_ramp( array &$state, int $ok ): void {
		if ( $ok < 1 ) {
			return;
		}
		$throttle            = array_merge( self::fresh_throttle(), (array) ( $state['throttle'] ?? array() ) );
		$throttle['streak'] += $ok;
		$max_par             = min( self::parallel_limit(), (int) $throttle['cap_par'] );
		$max_rate            = min( self::rate_limit(), (float) $throttle['cap_rate'] );
		while ( $throttle['streak'] >= self::RAMP_AFTER && ( $throttle['par'] < $max_par || $throttle['rate'] < $max_rate ) ) {
			$throttle['streak'] -= self::RAMP_AFTER;
			$throttle['par']     = min( $max_par, (int) $throttle['par'] + 1 );
			$throttle['rate']    = min( $max_rate, (float) $throttle['rate'] + 0.5 );
		}
		if ( $throttle['streak'] >= 4 * self::RAMP_AFTER ) {
			$throttle['level']  = 0;
			$throttle['streak'] = self::RAMP_AFTER;
		}
		$state['throttle'] = $throttle;
	}

	/**
	 * Seconds asked by a Retry-After header (delay in seconds or an HTTP date), 0 when absent or invalid.
	 *
	 * @param string   $value Header value.
	 * @param int|null $now   Current time (tests).
	 *
	 * @return int
	 */
	public static function retry_after_seconds( string $value, ?int $now = null ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			return min( self::PAUSE_HONOUR, (int) $value );
		}
		$time = strtotime( $value );
		if ( false === $time ) {
			return 0;
		}
		return max( 0, min( self::PAUSE_HONOUR, $time - ( $now ?? time() ) ) );
	}

	/**
	 * Step.
	 *
	 * @param array &$state State.
	 * @param int   $uid   Uid.
	 *
	 * @return array{done:bool,progress:int,label:string}
	 */
	public static function step( array &$state, int $uid ): array {
		$deadline = microtime( true ) + self::TIME_BUDGET;
		if ( (int) ( $state['format'] ?? 1 ) < self::FORMAT ) {
			// A check started by an older version keeps its listing and counters; its page in progress is read again.
			$state['format']     = self::FORMAT;
			$state['page']       = array();
			$state['page_queue'] = array();
			$state['links']      = $state['links'] ?? true;
			$state['external']   = false;
		}
		if ( ! isset( $state['throttle'] ) || ! is_array( $state['throttle'] ) ) {
			$state['throttle'] = self::fresh_throttle();
		}
		if ( 'list' === ( $state['phase'] ?? '' ) ) {
			self::list_until( $state, $uid, $deadline );
		}
		if ( 'pages' === ( $state['phase'] ?? '' ) && ! self::paused_until( $state ) ) {
			self::check_until( $state, $uid, $deadline );
		}
		$done = 'done' === ( $state['phase'] ?? '' );
		if ( $done && empty( $state['seconds'] ) ) {
			$state['seconds'] = max( 1, time() - (int) ( $state['started'] ?? time() ) );
		}
		$out   = array(
			'done'     => $done,
			'progress' => self::progress( $state ),
			'label'    => self::label( $state ),
		);
		$until = $done ? 0 : self::paused_until( $state );
		if ( $until ) {
			// The job runner waits until then; the browser polls the saved status meanwhile.
			$out['wait_until'] = $until;
		}
		return $out;
	}

	/**
	 * Summary saved with the scan result.
	 *
	 * @param array<string,mixed> $state State.
	 *
	 * @return array<string,mixed>
	 */
	public static function summary( array $state ): array {
		return array(
			'type'         => 'crawl',
			'format'       => self::FORMAT,
			'pages'        => (int) ( $state['checked'] ?? 0 ),
			'images'       => (int) ( $state['images'] ?? 0 ),
			'urls'         => (int) ( $state['urls'] ?? 0 ),
			'page_errors'  => (int) ( $state['page_errors'] ?? 0 ),
			'image_errors' => (int) ( $state['image_errors'] ?? 0 ),
			'broken'       => (int) ( $state['broken'] ?? 0 ),
			'suppressed'   => (int) ( $state['suppressed'] ?? 0 ),
			'kinds'        => array_map( 'intval', (array) ( $state['kinds'] ?? array() ) ),
			'requests'     => (int) ( $state['requests'] ?? 0 ),
			'local'        => (int) ( $state['local'] ?? 0 ),
			'throttled'    => (int) ( $state['throttled'] ?? 0 ),
			'pauses'       => (int) ( $state['pauses'] ?? 0 ),
			'links'        => ! empty( $state['links'] ),
			'external'     => ! empty( $state['external'] ),
			'seconds'      => (int) ( $state['seconds'] ?? 0 ),
		);
	}

	/**
	 * Translated name of an item kind.
	 *
	 * @param string $kind Kind.
	 *
	 * @return string
	 */
	public static function kind_label( string $kind ): string {
		$labels = array(
			'image'      => __( 'Image', 'lucidlab-mpc-cleanup' ),
			'srcset'     => __( 'Image (srcset)', 'lucidlab-mpc-cleanup' ),
			'background' => __( 'Background image', 'lucidlab-mpc-cleanup' ),
			'css'        => __( 'Stylesheet', 'lucidlab-mpc-cleanup' ),
			'js'         => __( 'Script', 'lucidlab-mpc-cleanup' ),
			'font'       => __( 'Font', 'lucidlab-mpc-cleanup' ),
			'media'      => __( 'Video or audio', 'lucidlab-mpc-cleanup' ),
			'file'       => __( 'File', 'lucidlab-mpc-cleanup' ),
			'link'       => __( 'Link', 'lucidlab-mpc-cleanup' ),
			'iframe'     => __( 'Embedded page', 'lucidlab-mpc-cleanup' ),
			'page'       => __( 'Page itself', 'lucidlab-mpc-cleanup' ),
		);
		return $labels[ $kind ] ?? $labels['image'];
	}

	/**
	 * Translated problem of a finding, for example "404 Not Found" or "Timeout".
	 *
	 * @param array<string,mixed> $row Finding.
	 *
	 * @return string
	 */
	public static function problem_label( array $row ): string {
		$code  = (int) ( $row['status'] ?? 0 );
		$error = (string) ( $row['error'] ?? '' );
		if ( 'timeout' === $error ) {
			return __( 'Timeout: no answer in time', 'lucidlab-mpc-cleanup' );
		}
		if ( 'loop' === $error ) {
			return __( 'Too many redirects', 'lucidlab-mpc-cleanup' );
		}
		if ( 0 === $code ) {
			return __( 'No response (host not found or connection failed)', 'lucidlab-mpc-cleanup' );
		}
		$text = function_exists( 'get_status_header_desc' ) ? (string) get_status_header_desc( $code ) : '';
		$text = trim( $code . ' ' . $text );
		if ( ! empty( $row['final'] ) ) {
			/* translators: 1: HTTP status such as "404 Not Found", 2: address the request was redirected to. */
			$text = sprintf( __( '%1$s after a redirect to %2$s', 'lucidlab-mpc-cleanup' ), $text, (string) $row['final'] );
		}
		return $text;
	}

	/**
	 * Findings written after $offset, for the live table.
	 *
	 * @param int $uid    Uid.
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 *
	 * @return array{rows:array<int,array<string,mixed>>,next:int}
	 */
	public static function findings_after( int $uid, int $offset, int $limit = 40 ): array {
		$offset = max( 0, $offset );
		$limit  = max( 1, min( 100, $limit ) );
		$rows   = array();
		$index  = 0;
		$path   = class_exists( 'LucidLab_MPC_Work', false ) ? LucidLab_MPC_Work::path( self::findings_file( $uid ) ) : '';
		$handle = '' !== $path && is_file( $path ) ? ( is_readable( $path ) ? fopen( $path, 'rb' ) : false ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( $handle ) {
			$wanted = max( 0, $limit );
			$taken  = 0;
			while ( $taken < $wanted ) {
				$line = fgets( $handle );
				if ( false === $line ) {
					break;
				}
				$row = json_decode( $line, true );
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( $index >= $offset ) {
					$rows[] = self::present( $row );
					++$taken;
				}
				++$index;
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		return array(
			'rows' => $rows,
			'next' => $offset + count( $rows ),
		);
	}

	/**
	 * Findings slice.
	 *
	 * @param int    $uid  Uid.
	 * @param int    $page Page.
	 * @param int    $per  Per.
	 * @param string $kind Only this item kind ('' for all).
	 *
	 * @return array{items:array<int,array<string,mixed>>,page:int,pages:int,total:int,per:int}
	 */
	public static function findings_slice( int $uid, int $page, int $per = 50, string $kind = '' ): array {
		$per    = max( 1, $per );
		$page   = max( 1, $page );
		$items  = array();
		$total  = 0;
		$path   = class_exists( 'LucidLab_MPC_Work', false ) ? LucidLab_MPC_Work::path( self::findings_file( $uid ) ) : '';
		$handle = '' !== $path ? ( is_readable( $path ) ? fopen( $path, 'rb' ) : false ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( $handle ) {
			$start = ( $page - 1 ) * $per;
			while ( true ) {
				$line = fgets( $handle );
				if ( false === $line ) {
					break;
				}
				$row = json_decode( $line, true );
				if ( ! is_array( $row ) ) {
					continue;
				}
				$row = self::present( $row );
				if ( '' !== $kind && $kind !== $row['kind'] ) {
					continue;
				}
				if ( $total >= $start && count( $items ) < $per ) {
					$items[] = $row;
				}
				++$total;
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		$pages = max( 1, (int) ceil( $total / $per ) );
		return array(
			'items' => $items,
			'page'  => min( $page, $pages ),
			'pages' => $pages,
			'total' => $total,
			'per'   => $per,
		);
	}

	/**
	 * Finding with every field set and its labels added. Rows from older versions are image findings.
	 *
	 * @param array<string,mixed> $row Row.
	 *
	 * @return array<string,mixed>
	 */
	public static function present( array $row ): array {
		$url  = (string) ( $row['url'] ?? $row['image'] ?? '' );
		$kind = in_array( $row['kind'] ?? '', self::KINDS, true ) ? (string) $row['kind'] : 'image';
		$row  = array_merge(
			array(
				'page'       => '',
				'title'      => '',
				'edit'       => '',
				'image_edit' => '',
				'status'     => 0,
				'error'      => '',
				'final'      => '',
				'via'        => '',
				'external'   => 0,
			),
			$row
		);

		$row['url']           = $url;
		$row['image']         = $url;
		$row['kind']          = $kind;
		$row['kind_label']    = self::kind_label( $kind );
		$row['problem_label'] = self::problem_label( $row );
		return $row;
	}

	/**
	 * Image addresses in rendered HTML that belong to this site.
	 *
	 * @param string $html     Html.
	 * @param string $page_url Page url.
	 * @param string $home     Home.
	 *
	 * @return string[]
	 */
	public static function image_urls_from_html( string $html, string $page_url, string $home ): array {
		$found = array();
		foreach ( self::items_from_html( $html, $page_url, $home ) as $item ) {
			if ( in_array( $item['k'], array( 'image', 'srcset', 'background' ), true ) ) {
				$found[ $item['u'] ] = $item['u'];
			}
		}
		return array_values( $found );
	}

	/**
	 * Everything a page loads or links to: list of items with u (address), k (kind) and x (1 = other site).
	 *
	 * @param string $html     Html.
	 * @param string $page_url Page url (base for relative addresses).
	 * @param string $home     Home.
	 * @param array  $options  links (bool, default true), external (bool, default false).
	 *
	 * @return array<int,array{u:string,k:string,x:int}>
	 */
	public static function items_from_html( string $html, string $page_url, string $home, array $options = array() ): array {
		$links    = ! array_key_exists( 'links', $options ) || ! empty( $options['links'] );
		$external = ! empty( $options['external'] );
		$base     = $page_url;
		if ( preg_match( '/<base\b[^>]*\bhref\s*=\s*(["\'])([^"\']+)\1/i', $html, $base_match ) ) {
			$resolved = self::absolute_url( $base_match[2], $page_url );
			$base     = '' !== $resolved ? $resolved : $page_url;
		}
		$found = array();
		$add   = static function ( string $raw, string $kind ) use ( &$found, $base, $home, $external, $links, $page_url ): void {
			$url = self::absolute_url( $raw, $base );
			if ( '' === $url || ! self::url_is_sane( $url ) ) {
				return;
			}
			$other = ! self::same_site( $url, $home );
			if ( $other && ! $external ) {
				return;
			}
			$kind = self::refine_kind( $url, $kind );
			if ( '' === $kind || ( 'link' === $kind && ! $links ) || ( 'iframe' === $kind && $other ) ) {
				return;
			}
			if ( in_array( $kind, array( 'link', 'iframe' ), true ) && ( self::skipped_link( $url ) || untrailingslashit( $url ) === untrailingslashit( $page_url ) ) ) {
				return;
			}
			if ( ! isset( $found[ $url ] ) ) {
				$found[ $url ] = array(
					'u' => $url,
					'k' => $kind,
					'x' => $other ? 1 : 0,
				);
			}
		};
		// Comments and inline script bodies hold no loaded addresses; script tags keep their src.
		$markup = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$markup = (string) preg_replace( '#(<script\b[^>]*>).*?</script>#is', '$1</script>', $markup );
		if ( preg_match_all( '/<(img|source|link|script|a|area|video|audio|track|embed|object|iframe|meta|input)\b([^>]*)>/i', $markup, $tags, PREG_SET_ORDER ) ) {
			foreach ( $tags as $tag ) {
				$name  = strtolower( $tag[1] );
				$attrs = self::attributes( $tag[2] );
				foreach ( self::tag_urls( $name, $attrs ) as $pair ) {
					if ( 'srcset' === $pair[1] ) {
						foreach ( self::srcset_urls( $pair[0] ) as $part ) {
							$add( $part, 'srcset' );
						}
						continue;
					}
					$add( $pair[0], $pair[1] );
				}
				if ( isset( $attrs['style'] ) && str_contains( strtolower( $attrs['style'] ), 'url(' ) ) {
					foreach ( self::css_urls( $attrs['style'] ) as $raw ) {
						$add( $raw, 'background' );
					}
				}
			}
		}
		// Backgrounds in style blocks, style attributes of other tags and lazy-load attributes.
		if ( preg_match_all( '/url\(\s*(["\']?)([^)\'"]+)\1\s*\)/i', $html, $styles ) ) {
			foreach ( $styles[2] as $raw ) {
				$raw = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
				$url = self::absolute_url( $raw, $base );
				if ( '' !== $url && ( self::looks_like_image( $url ) || self::has_ext( $url, self::FONT_EXT ) ) ) {
					$add( $raw, self::has_ext( $url, self::FONT_EXT ) ? 'font' : 'background' );
				}
			}
		}
		if ( preg_match_all( '/\sdata-(?:bg|background|background-image|bg-src|bg-image)\s*=\s*(["\'])([^"\']+)\1/i', $markup, $lazy ) ) {
			foreach ( $lazy[2] as $raw ) {
				$raw = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
				if ( preg_match( '/url\(\s*(["\']?)([^)\'"]+)\1\s*\)/i', $raw, $inner ) ) {
					$raw = $inner[2];
				}
				$add( $raw, 'background' );
			}
		}
		return array_slice( array_values( $found ), 0, self::MAX_ITEMS_PER_PAGE );
	}

	/**
	 * Addresses in a stylesheet (url() and @import), resolved against the stylesheet address.
	 *
	 * @param string $css     Css.
	 * @param string $css_url Stylesheet address.
	 * @param string $home    Home.
	 * @param bool   $external Include other sites.
	 *
	 * @return array<int,array{u:string,k:string,x:int}>
	 */
	public static function items_from_css( string $css, string $css_url, string $home, bool $external = false ): array {
		$found = array();
		$css   = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$raws  = self::css_urls( $css );
		if ( preg_match_all( '/@import\s+(["\'])([^"\']+)\1/i', $css, $imports ) ) {
			$raws = array_merge( $raws, $imports[2] );
		}
		foreach ( $raws as $raw ) {
			$url = self::absolute_url( $raw, $css_url );
			if ( '' === $url || ! self::url_is_sane( $url ) || isset( $found[ $url ] ) ) {
				continue;
			}
			$other = ! self::same_site( $url, $home );
			if ( $other && ! $external ) {
				continue;
			}
			if ( self::has_ext( $url, self::FONT_EXT ) ) {
				$kind = 'font';
			} elseif ( self::has_ext( $url, 'css' ) || str_contains( $url, 'fonts.googleapis.com/css' ) ) {
				$kind = 'css';
			} else {
				$kind = 'background';
			}
			$found[ $url ] = array(
				'u' => $url,
				'k' => $kind,
				'x' => $other ? 1 : 0,
			);
		}
		return array_values( $found );
	}

	/**
	 * Whether a check result is a problem: '' (fine), 'broken' or 'inconclusive'.
	 *
	 * Other sites often refuse automated requests (401, 403, 405, 429, 999), so for them only
	 * 404, 410, server errors, timeouts and unreachable hosts count as broken. 429 and 503 (busy or
	 * rate limited, often caused by the check itself) are inconclusive everywhere.
	 *
	 * @param array $rec      Result: c (status code), e (error).
	 * @param bool  $external Whether the address is on another site.
	 *
	 * @return string
	 */
	public static function verdict( array $rec, bool $external ): string {
		$code  = (int) ( $rec['c'] ?? 0 );
		$error = (string) ( $rec['e'] ?? '' );
		if ( 'offsite' === $error ) {
			return '';
		}
		if ( 'loop' === $error || 'timeout' === $error ) {
			return 'broken';
		}
		if ( in_array( $error, array( 'unsafe', 'private' ), true ) ) {
			return 'inconclusive';
		}
		if ( 0 === $code ) {
			return $external ? 'broken' : 'inconclusive';
		}
		if ( $code >= 200 && $code < 400 ) {
			return '';
		}
		if ( 429 === $code || 503 === $code ) {
			return 'inconclusive';
		}
		if ( $external ) {
			return ( in_array( $code, array( 404, 410 ), true ) || ( $code >= 500 && $code < 600 && 503 !== $code ) ) ? 'broken' : 'inconclusive';
		}
		return $code >= 400 ? 'broken' : 'inconclusive';
	}

	/**
	 * Absolute url.
	 *
	 * @param string $url  Url.
	 * @param string $base Base.
	 *
	 * @return string
	 */
	public static function absolute_url( string $url, string $base ): string {
		$url = self::normalize_candidate_url( $url );
		if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url ) && ! preg_match( '#^https?://#i', $url ) ) {
			return ''; }
		if ( str_starts_with( $url, '?' ) ) {
			$url = preg_split( '/[?#]/', $base, 2 )[0] . $url; }
		if ( '' === $url || str_starts_with( $url, 'data:' ) || str_starts_with( $url, 'blob:' ) || str_starts_with( $url, '#' ) || str_starts_with( strtolower( $url ), 'javascript:' ) ) {
			return '';
		}
		if ( str_starts_with( $url, '//' ) ) {
			$scheme = (string) ( LucidLab_MPC_Helpers::either( self::url_part( $base, PHP_URL_SCHEME ), 'https' ) );
			$url    = $scheme . ':' . $url;
		} elseif ( str_starts_with( $url, '/' ) ) {
			$host = (string) self::url_part( $base, PHP_URL_HOST );
			if ( '' === $host ) {
				return '';
			}
			$port = self::url_part( $base, PHP_URL_PORT );
			$url  = (string) ( LucidLab_MPC_Helpers::either( self::url_part( $base, PHP_URL_SCHEME ), 'https' ) ) . '://' . $host . ( $port ? ':' . $port : '' ) . $url;
		} elseif ( ! preg_match( '#^https?://#i', $url ) ) {
			$host = (string) self::url_part( $base, PHP_URL_HOST );
			if ( '' === $host ) {
				return '';
			}
			$path = (string) ( LucidLab_MPC_Helpers::either( self::url_part( $base, PHP_URL_PATH ), '/' ) );
			$dir  = preg_replace( '#/[^/]*$#', '/', $path );
			$port = self::url_part( $base, PHP_URL_PORT );
			$url  = (string) ( LucidLab_MPC_Helpers::either( self::url_part( $base, PHP_URL_SCHEME ), 'https' ) ) . '://' . $host . ( $port ? ':' . $port : '' ) . $dir . $url;
		}
		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return ''; }
		$segments = array();
		foreach ( explode( '/', $parts['path'] ?? '/' ) as $segment ) {
			if ( '.' === $segment ) {
				continue; }
			if ( '..' === $segment ) {
				if ( count( $segments ) > 1 ) {
					array_pop( $segments );
				} continue; }
			$segments[] = $segment;
		}
		$url = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . implode( '/', $segments ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		return self::url_is_sane( $url ) ? $url : '';
	}

	/**
	 * Clean src/href/srcset/JSON-LD scraps before resolve-relative. JSON-escaped absolutes like "https:\/\/…" must not be joined onto the page path.
	 *
	 * @param string $url Url.
	 *
	 * @return string
	 */
	public static function normalize_candidate_url( string $url ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $url ) {
			return '';
		}
		// JSON solidus escapes: https:\/\/host\/path → https://host/path.
		$url = str_replace( '\\/', '/', $url );
		// Unicode-escaped quotes sometimes left from JSON fragments.
		$url = str_ireplace( array( '\\u0022', '\\u0027' ), '', $url );
		for ( $i = 0; $i < 5; $i++ ) {
			$before = $url;
			$url    = trim( $url );
			$url    = trim( $url, "\"'\\" );
			$url    = preg_replace( '/^(?:%22|%27)+/i', '', $url ) ?? $url;
			$url    = preg_replace( '/(?:%22|%27)+$/i', '', $url ) ?? $url;
			if ( $url === $before ) {
				break;
			}
		}
		$url = str_replace( array( '\\"', "\\'" ), '', $url );
		return trim( $url );
	}

	/**
	 * Reject mangled joins (quotes/backslashes, or a second scheme stuck in the path).
	 *
	 * @param string $url Url.
	 *
	 * @return bool
	 */
	public static function url_is_sane( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		if ( str_contains( $url, '"' ) || str_contains( $url, "'" ) || str_contains( $url, '\\' ) ) {
			return false;
		}
		$path = (string) ( LucidLab_MPC_Helpers::either( self::url_part( $url, PHP_URL_PATH ), '' ) );
		if ( preg_match( '#https?:#i', $path ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Same site.
	 *
	 * @param string $url  Url.
	 * @param string $home Home.
	 *
	 * @return bool
	 */
	public static function same_site( string $url, string $home ): bool {
		$host = self::bare_host( (string) self::url_part( $url, PHP_URL_HOST ) );
		$site = self::bare_host( (string) self::url_part( $home, PHP_URL_HOST ) );
		return '' !== $host && $host === $site;
	}

	/**
	 * List until.
	 *
	 * @param array &$state    State.
	 * @param int   $uid      Uid.
	 * @param float $deadline Deadline.
	 */
	private static function list_until( array &$state, int $uid, float $deadline ): void {
		while ( microtime( true ) < $deadline && 'list' === ( $state['phase'] ?? '' ) ) {
			$stage = (string) ( $state['stage'] ?? 'home' );
			if ( 'home' === $stage ) {
				$home = home_url( '/' );
				self::append_urls(
					$uid,
					array(
						array(
							'url'   => $home,
							'title' => __( 'Home', 'lucidlab-mpc-cleanup' ),
							'edit'  => '',
						),
					)
				);
				$state['pages']  = (int) ( $state['pages'] ?? 0 ) + 1;
				$state['stage']  = 'posts';
				$state['offset'] = 0;
				continue;
			}
			if ( 'posts' === $stage ) {
				$batch = self::list_posts( (int) ( $state['offset'] ?? 0 ), 100, isset( $state['listing_after'] ) ? (int) $state['listing_after'] : null );
				if ( $batch['scanned'] < 1 ) {
					$state['stage'] = 'terms';
					if ( isset( $state['listing_after'] ) ) {
						$state['listing_after'] = 0; }
					$state['offset'] = 0;
					continue;
				}
				$state['pages']  = (int) ( $state['pages'] ?? 0 ) + self::append_urls( $uid, $batch['rows'] );
				$state['offset'] = (int) ( $state['offset'] ?? 0 ) + $batch['scanned'];
				if ( isset( $state['listing_after'] ) ) {
					$state['listing_after'] = $batch['last_id']; }
				continue;
			}
			if ( 'terms' === $stage ) {
				$batch = self::list_terms( (int) ( $state['offset'] ?? 0 ), 100, isset( $state['listing_after'] ) ? (int) $state['listing_after'] : null );
				if ( $batch['scanned'] < 1 ) {
					$state['phase']  = 'pages';
					$state['cursor'] = array( 'offset' => 0 );
					$state['page']   = array();
					self::save_listed( $uid );
					continue;
				}
				$state['pages']  = (int) ( $state['pages'] ?? 0 ) + self::append_urls( $uid, $batch['rows'] );
				$state['offset'] = (int) ( $state['offset'] ?? 0 ) + $batch['scanned'];
				if ( isset( $state['listing_after'] ) ) {
					$state['listing_after'] = $batch['last_id']; }
				continue;
			}
			$state['phase'] = 'pages';
		}
	}

	/**
	 * Check until.
	 *
	 * Each round sends one parallel batch that holds both the next pages (GET, a few at a time)
	 * and the addresses of the current and queued pages that have no result yet (HEAD, more at a
	 * time). Page rendering and file checks therefore overlap instead of waiting for each other,
	 * and a batch is not limited to the few new addresses of a single page.
	 *
	 * @param array &$state    State.
	 * @param int   $uid      Uid.
	 * @param float $deadline Deadline.
	 */
	private static function check_until( array &$state, int $uid, float $deadline ): void {
		$status = self::load_status( $uid );
		$listed = self::load_listed( $uid );
		$dirty  = false;
		$home   = home_url( '/' );
		if ( ! empty( $state['external'] ) && ! ( class_exists( 'LucidLab_MPC_Edition', false ) && LucidLab_MPC_Edition::pro() ) ) {
			$state['external'] = false;
		}
		while ( microtime( true ) < $deadline - 0.5 && 'pages' === ( $state['phase'] ?? '' ) && ! self::paused_until( $state ) ) {
			$page  = is_array( $state['page'] ?? null ) ? $state['page'] : array();
			$queue = (array) ( $state['page_queue'] ?? array() );
			if ( empty( $page['url'] ) && $queue ) {
				$state['page']       = (array) array_shift( $queue );
				$state['page_queue'] = $queue;
				continue;
			}
			$rows  = array();
			$limit = self::current_par( $state, true );
			if ( ( empty( $state['list_done'] ) || ! empty( $state['retry_rows'] ) ) && count( $queue ) < $limit ) {
				$rows = self::next_rows( $uid, $state, empty( $page['url'] ) && empty( $state['fetched'] ) ? 1 : $limit );
				if ( ! $rows && empty( $state['retry_rows'] ) ) {
					$state['list_done'] = 1;
				}
			}
			if ( empty( $page['url'] ) && ! $rows && ! empty( $state['list_done'] ) && empty( $state['retry_rows'] ) ) {
				$state['phase'] = 'done';
				break;
			}
			$pending = self::pre_resolve( empty( $page['url'] ) ? array() : self::unknown_items( $page, $queue, $status, self::current_par( $state, false ) ), $status, $state, $listed );
			$jobs    = array();
			foreach ( $rows as $i => $row ) {
				$jobs[ 'p' . $i ] = array(
					'url'     => (string) $row['url'],
					'method'  => 'GET',
					'limit'   => 2 * MB_IN_BYTES,
					'timeout' => 10,
					'page'    => true,
				);
			}
			foreach ( $pending as $url ) {
				$jobs[ 'u' . $url ] = array(
					'url'     => $url,
					'method'  => 'HEAD',
					'limit'   => 4096,
					'timeout' => 6,
					'page'    => false,
				);
			}
			if ( $jobs ) {
				$dirty   = true;
				$results = self::check_urls( $jobs, $state );
				if ( $rows ) {
					self::queue_pages( $uid, $rows, $results, $state, $status, $home );
				}
				self::store_results( $pending, $results, $status, $state );
				continue;
			}
			if ( empty( $page['url'] ) ) {
				continue;
			}
			$dirty = self::process_page( $uid, $state, $status, $home ) || $dirty;
		}
		if ( $dirty ) {
			self::save_status( $uid, $status );
		}
	}

	/**
	 * Record the findings of the current page as far as its addresses have results. Returns whether
	 * results changed (a stylesheet was read).
	 *
	 * @param int    $uid    Uid.
	 * @param array  &$state  State.
	 * @param array  &$status Check results by address.
	 * @param string $home   Home.
	 *
	 * @return bool
	 */
	private static function process_page( int $uid, array &$state, array &$status, string $home ): bool {
		$page    = (array) $state['page'];
		$items   = array_values( (array) ( $page['items'] ?? array() ) );
		$index   = (int) ( $page['index'] ?? 0 );
		$extra   = array();
		$rows    = array();
		$count   = count( $items );
		$changed = false;
		while ( $index < $count ) {
			$item = $items[ $index ];
			$url  = (string) ( $item['u'] ?? '' );
			if ( '' !== $url && ! isset( $status[ $url ] ) ) {
				break;
			}
			++$index;
			if ( '' === $url ) {
				continue;
			}
			$rec     = $status[ $url ];
			$verdict = self::verdict( $rec, ! empty( $item['x'] ) );
			if ( '' === $verdict && 'css' === ( $item['k'] ?? '' ) && empty( $rec['p'] ) ) {
				// Each stylesheet is read once per check; what it loads is checked on the first page that uses it.
				$found = self::stylesheet_items( $url, $home, ! empty( $state['external'] ), $state );
				if ( null === $found ) {
					// The server asked to slow down while the stylesheet was read: read it after the pause.
					--$index;
					break;
				}
				$status[ $url ]['p'] = 1;
				$changed             = true;
				$extra               = array_merge( $extra, $found );
			}
			if ( 'inconclusive' === $verdict ) {
				$state['image_errors'] = (int) ( $state['image_errors'] ?? 0 ) + 1;
			}
			if ( 'broken' === $verdict ) {
				$row = self::finding_row( $page, $item, $rec, $state );
				if ( $row ) {
					$rows[] = $row;
				}
			}
		}
		if ( $rows ) {
			LucidLab_MPC_Work::append( self::findings_file( $uid ), $rows );
		}
		$page['index'] = $index;
		if ( $extra ) {
			$known = array_flip( array_column( $items, 'u' ) );
			$extra = array_values( array_filter( $extra, static fn( $child ) => ! isset( $known[ $child['u'] ] ) ) );
			array_splice( $items, $index, 0, array_slice( $extra, 0, self::MAX_ITEMS_PER_PAGE ) );
			$page['items'] = $items;
		}
		if ( $index >= count( $items ) ) {
			$state['checked'] = (int) ( $state['checked'] ?? 0 ) + 1;
			$page             = array();
		}
		$state['page'] = $page;
		return $changed;
	}

	/**
	 * Items without a result yet: those of the current page first, then those of the queued pages.
	 *
	 * @param array $page   Current page.
	 * @param array $queue  Queued pages.
	 * @param array $status Check results by address.
	 * @param int   $limit  Addresses wanted at most.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function unknown_items( array $page, array $queue, array $status, int $limit = self::ITEM_BATCH ): array {
		$out   = array();
		$pages = array_merge( array( $page ), $queue );
		foreach ( $pages as $i => $one ) {
			$items = array_values( (array) ( $one['items'] ?? array() ) );
			$from  = 0 === $i ? (int) ( $one['index'] ?? 0 ) : 0;
			$count = count( $items );
			for ( $n = $from; $n < $count; $n++ ) {
				$url = (string) ( $items[ $n ]['u'] ?? '' );
				if ( '' === $url || isset( $status[ $url ] ) || isset( $out[ $url ] ) ) {
					continue;
				}
				$out[ $url ] = $items[ $n ];
				if ( count( $out ) >= min( self::ITEM_BATCH, max( 1, $limit ) ) ) {
					return array_values( $out );
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * A finding row, or null when this address was already listed for enough pages.
	 *
	 * @param array $page  Page.
	 * @param array $item  Item.
	 * @param array $rec   Check result.
	 * @param array &$state State.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function finding_row( array $page, array $item, array $rec, array &$state ): ?array {
		$url  = (string) $item['u'];
		$kind = (string) ( $item['k'] ?? 'image' );
		$hits = (int) ( $state['hits'][ $url ] ?? 0 ) + 1;
		if ( ! isset( $state['hits'] ) || ! is_array( $state['hits'] ) ) {
			$state['hits'] = array();
		}
		$state['hits'][ $url ] = $hits;
		if ( 1 === $hits ) {
			$state['kinds'][ $kind ] = (int) ( $state['kinds'][ $kind ] ?? 0 ) + 1;
		}
		if ( $hits > self::MAX_ROWS_PER_URL ) {
			$state['suppressed'] = (int) ( $state['suppressed'] ?? 0 ) + 1;
			return null;
		}
		$state['broken'] = (int) ( $state['broken'] ?? 0 ) + 1;
		$image_edit      = '';
		if ( empty( $item['x'] ) && in_array( $kind, array( 'image', 'srcset', 'background', 'file', 'media' ), true ) ) {
			if ( ! isset( $state['image_edits'] ) || ! is_array( $state['image_edits'] ) ) {
				$state['image_edits'] = array();
			}
			if ( ! array_key_exists( $url, $state['image_edits'] ) ) {
				$state['image_edits'][ $url ] = self::media_edit_link( $url );
			}
			$image_edit = (string) $state['image_edits'][ $url ];
		}
		return array(
			'page'       => (string) ( $page['url'] ?? '' ),
			'title'      => (string) ( $page['title'] ?? '' ),
			'edit'       => (string) ( $page['edit'] ?? '' ),
			'url'        => $url,
			'image'      => $url,
			'kind'       => $kind,
			'via'        => (string) ( $item['v'] ?? '' ),
			'external'   => empty( $item['x'] ) ? 0 : 1,
			'status'     => (int) ( $rec['c'] ?? 0 ),
			'error'      => (string) ( $rec['e'] ?? '' ),
			'final'      => (string) ( $rec['f'] ?? '' ),
			'image_edit' => $image_edit,
		);
	}

	/**
	 * Items loaded by a stylesheet, marked with the stylesheet they came from.
	 *
	 * @param string $css_url  Stylesheet address.
	 * @param string $home     Home.
	 * @param bool   $external Include other sites.
	 * @param array  &$state    State.
	 *
	 * @return array<int,array<string,mixed>>|null Null when this site answered 429/503 (read it again after the pause).
	 */
	private static function stylesheet_items( string $css_url, string $home, bool $external, array &$state ): ?array {
		$css  = '';
		$path = self::local_path( $css_url );
		if ( '' !== $path && is_file( $path ) && is_readable( $path ) ) {
			$css = (string) file_get_contents( $path, false, null, 0, MB_IN_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		} elseif ( self::same_site( $css_url, $home ) || $external ) {
			$own = self::same_origin( $css_url );
			self::pace( $state, $own ? 1 : 0 );
			$response          = LucidLab_MPC_Crawl_Http::send(
				array(
					array(
						'url'     => $css_url,
						'method'  => 'GET',
						'timeout' => 8,
						'limit'   => MB_IN_BYTES,
						'safe'    => ! $own,
						'agent'   => self::user_agent(),
					),
				)
			);
			$state['requests'] = (int) ( $state['requests'] ?? 0 ) + 1;
			$response          = $response[0] ?? null;
			$code              = is_array( $response ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
			if ( $own && in_array( $code, array( 429, 503 ), true ) ) {
				$after              = wp_remote_retrieve_header( $response, 'retry-after' );
				$state['throttled'] = (int) ( $state['throttled'] ?? 0 ) + 1;
				self::throttle_back( $state, $code, self::retry_after_seconds( is_array( $after ) ? (string) end( $after ) : (string) $after ) );
				return null;
			}
			if ( 200 === $code ) {
				$css = (string) wp_remote_retrieve_body( $response );
			}
		}
		if ( '' === $css ) {
			return array();
		}
		$items = self::items_from_css( $css, $css_url, $home, $external );
		foreach ( $items as &$item ) {
			$item['v'] = $css_url;
		}
		unset( $item );
		return $items;
	}

	/**
	 * Next rows of the page list, without repeats.
	 *
	 * @param int   $uid   Uid.
	 * @param array &$state State.
	 * @param int   $limit Rows wanted.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function next_rows( int $uid, array &$state, int $limit ): array {
		$rows  = array();
		$seen  = array();
		$taken = 0;
		while ( $taken < $limit ) {
			// Pages the server refused with 429/503 come first, before new ones from the list.
			$next = ! empty( $state['retry_rows'] ) ? (array) array_shift( $state['retry_rows'] ) : self::read_url( $uid, $state );
			if ( null === $next ) {
				break;
			}
			$key = self::page_key( (string) $next['url'] );
			if ( isset( $seen[ $key ] ) ) {
				$state['checked'] = (int) ( $state['checked'] ?? 0 ) + 1;
				continue;
			}
			$seen[ $key ] = 1;
			$rows[]       = $next;
			++$taken;
		}
		$state['fetched'] = (int) ( $state['fetched'] ?? 0 ) + count( $rows );
		return $rows;
	}

	/**
	 * Queue fetched pages with what they load; a page that fails is a finding or an unreadable page.
	 *
	 * @param int    $uid     Uid.
	 * @param array  $rows    Page rows.
	 * @param array  $results Check results, page i under key "p" . i.
	 * @param array  &$state   State.
	 * @param array  &$status  Check results by address.
	 * @param string $home    Home.
	 *
	 * @throws RuntimeException When the very first page cannot be requested at all.
	 */
	private static function queue_pages( int $uid, array $rows, array $results, array &$state, array &$status, string $home ): void {
		$queue = (array) ( $state['page_queue'] ?? array() );
		foreach ( $rows as $i => $row ) {
			$url = (string) $row['url'];
			$rec = $results[ 'p' . $i ] ?? array(
				'c' => 0,
				'e' => 'failed',
			);
			if ( self::ask_again( $state, $url, $rec ) ) {
				// Not checked: asked again after the pause, so it is never counted as read or broken.
				$state['retry_rows'][] = $row;
				continue;
			}
			if ( 'throttled' === ( $rec['e'] ?? '' ) ) {
				$rec['c'] = (int) ( $rec['c'] ?? 429 );
			}
			unset( $state['retries'][ $url ] );
			if ( 1 === (int) ( $state['fetched'] ?? 0 ) && 0 === (int) ( $rec['c'] ?? 0 ) && 'failed' === ( $rec['e'] ?? '' ) ) {
				throw new RuntimeException( __( 'The site could not open its own pages. The server has to be able to request its own address.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			$body = (string) ( $rec['b'] ?? '' );
			unset( $rec['b'] );
			$status[ $url ] = $rec;
			$code           = (int) ( $rec['c'] ?? 0 );
			$page           = array(
				'url'   => $url,
				'title' => (string) ( $row['title'] ?? '' ),
				'edit'  => (string) ( $row['edit'] ?? '' ),
			);
			if ( 200 <= $code && 300 > $code && empty( $rec['e'] ) ) {
				// Relative addresses resolve against the address the page ended up at after redirects.
				$base          = (string) ( $rec['f'] ?? '' );
				$page['items'] = self::items_from_html(
					$body,
					'' !== $base ? $base : $url,
					$home,
					array(
						'links'    => ! empty( $state['links'] ),
						'external' => ! empty( $state['external'] ),
					)
				);
				$page['index'] = 0;
				$queue[]       = $page;
				continue;
			}
			$state['checked'] = (int) ( $state['checked'] ?? 0 ) + 1;
			if ( 'broken' === self::verdict( $rec, false ) ) {
				// A published page that answers 4xx/5xx or times out is a finding of its own.
				$hit = self::finding_row(
					$page,
					array(
						'u' => $url,
						'k' => 'page',
						'x' => 0,
					),
					$rec,
					$state
				);
				if ( $hit ) {
					LucidLab_MPC_Work::append( self::findings_file( $uid ), array( $hit ) );
				}
				continue;
			}
			// Login redirects (private pages), redirects to other sites and failed requests.
			$state['page_errors'] = (int) ( $state['page_errors'] ?? 0 ) + 1;
		}
		$state['page_queue'] = $queue;
	}

	/**
	 * Give results to the items that need no request: static files on disk, links to listed pages
	 * and healthy answers cached in the last five minutes. Returns the addresses still to request.
	 *
	 * @param array $items  Items.
	 * @param array &$status Check results by address.
	 * @param array &$state  State.
	 * @param array $listed Pages of this check (page key => 1).
	 *
	 * @return array<string,string>
	 */
	private static function pre_resolve( array $items, array &$status, array &$state, array $listed ): array {
		$pending = array();
		foreach ( $items as $item ) {
			$url = (string) ( $item['u'] ?? '' );
			if ( '' === $url || isset( $status[ $url ] ) || isset( $pending[ $url ] ) ) {
				continue;
			}
			$kind = (string) ( $item['k'] ?? '' );
			if ( empty( $item['x'] ) && 'link' === $kind && ( isset( $listed[ self::page_key( $url ) ] ) || isset( $listed[ self::view_key( $url ) ] ) ) ) {
				// A link to a page on this check's list needs no request of its own: that page is fetched
				// anyway, and reported as a "Page itself" problem when it fails.
				$status[ $url ] = array(
					'c' => 200,
					'l' => 1,
				);
				$state['urls']  = (int) ( $state['urls'] ?? 0 ) + 1;
				continue;
			}
			if ( empty( $item['x'] ) && ! in_array( $kind, array( 'link', 'iframe', 'page' ), true ) ) {
				$path = self::local_path( $url );
				if ( '' !== $path && is_file( $path ) && is_readable( $path ) ) {
					// A static file that exists on disk is served by the web server; no request needed.
					$status[ $url ]  = array( 'c' => 200 );
					$state['local']  = (int) ( $state['local'] ?? 0 ) + 1;
					$state['urls']   = (int) ( $state['urls'] ?? 0 ) + 1;
					$state['images'] = (int) ( $state['images'] ?? 0 ) + 1;
					continue;
				}
			}
			$pending[ $url ] = $url;
		}
		if ( ! $pending ) {
			return array();
		}
		$keys   = self::cache_keys( array_values( $pending ) );
		$cached = $keys ? LucidLab_MPC_Result_Cache::read( 'crawl-healthy', $keys ) : array();
		foreach ( (array) $cached as $url => $code ) {
			if ( is_int( $code ) && $code >= 200 && $code < 300 && isset( $pending[ $url ] ) ) {
				$status[ $url ] = array( 'c' => $code );
				unset( $pending[ $url ] );
				$state['cache_hits'] = (int) ( $state['cache_hits'] ?? 0 ) + 1;
				$state['urls']       = (int) ( $state['urls'] ?? 0 ) + 1;
			}
		}
		return $pending;
	}

	/**
	 * Store the results of requested addresses and cache the healthy ones for five minutes.
	 *
	 * @param array $pending Requested addresses.
	 * @param array $results Check results, address under key "u" . address.
	 * @param array &$status  Check results by address.
	 * @param array &$state   State.
	 */
	private static function store_results( array $pending, array $results, array &$status, array &$state ): void {
		$healthy = array();
		foreach ( $pending as $url ) {
			$rec = $results[ 'u' . $url ] ?? array(
				'c' => 0,
				'e' => 'failed',
			);
			unset( $rec['b'] );
			if ( self::ask_again( $state, $url, $rec ) ) {
				// No result yet: the address is asked again after the pause.
				continue;
			}
			unset( $state['retries'][ $url ] );
			$status[ $url ]  = $rec;
			$state['urls']   = (int) ( $state['urls'] ?? 0 ) + 1;
			$state['images'] = (int) ( $state['images'] ?? 0 ) + 1;
			$code            = (int) ( $rec['c'] ?? 0 );
			if ( $code >= 200 && $code < 300 && empty( $rec['f'] ) ) {
				$healthy[ $url ] = $code;
			}
		}
		if ( $healthy && class_exists( 'LucidLab_MPC_Result_Cache' ) ) {
			LucidLab_MPC_Result_Cache::write( 'crawl-healthy', self::cache_keys( array_keys( $healthy ) ), $healthy, 300 );
		}
	}

	/**
	 * Request addresses in parallel rounds and follow redirects one validated hop at a time.
	 * Each round holds up to parallel_limit() pages and asset_parallel_limit() other addresses.
	 * A HEAD that does not answer 2xx is asked again with GET, because some servers refuse HEAD.
	 *
	 * Result per key: c (final status, 0 = no response), e (timeout, failed, loop, offsite, unsafe, private),
	 * f (address after redirects, when it differs) and b (body of a 2xx GET).
	 *
	 * @param array<string,array<string,mixed>> $jobs  Jobs by key: url, method, limit, timeout, page.
	 * @param array                             &$state State.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function check_urls( array $jobs, array &$state ): array {
		$results = array();
		$active  = array();
		foreach ( $jobs as $key => $job ) {
			$active[ $key ] = array_merge(
				$job,
				array(
					'start'   => (string) $job['url'],
					'current' => (string) $job['url'],
					'asked'   => (string) $job['method'],
					'hops'    => 0,
					'retried' => false,
				)
			);
		}
		$guard      = 0;
		$page_limit = self::current_par( $state, true );
		$item_limit = self::current_par( $state, false );
		while ( $active && $guard < 4 * ( self::MAX_HOPS + 2 ) ) {
			++$guard;
			$batch  = array();
			$pages  = 0;
			$assets = 0;
			foreach ( $active as $key => $job ) {
				if ( ! empty( $job['page'] ) ? $pages < $page_limit : $assets < $item_limit ) {
					$batch[ $key ] = $job;
					if ( ! empty( $job['page'] ) ) {
						++$pages;
					} else {
						++$assets;
					}
				}
			}
			$requests = array();
			$own      = 0;
			foreach ( $batch as $key => $job ) {
				$safe             = ! self::same_origin( $job['current'] );
				$own             += $safe ? 0 : 1;
				$requests[ $key ] = array(
					'url'     => $job['current'],
					'method'  => $job['method'],
					'timeout' => $job['retried'] ? (int) $job['timeout'] + 2 : (int) $job['timeout'],
					'limit'   => 'HEAD' === $job['method'] ? 4096 : (int) $job['limit'],
					'safe'    => $safe,
					'agent'   => self::user_agent(),
				);
			}
			self::pace( $state, $own );
			$responses         = LucidLab_MPC_Crawl_Http::send( $requests );
			$state['requests'] = (int) ( $state['requests'] ?? 0 ) + count( $requests );
			$slow_down         = array();
			$fine              = 0;
			foreach ( $batch as $key => $job ) {
				$response = $responses[ $key ] ?? new WP_Error( 'http_request_failed', '' );
				$outcome  = self::next_hop( $job, $response, (string) $job['asked'] );
				if ( isset( $outcome['result'] ) ) {
					$results[ $key ] = $outcome['result'];
					unset( $active[ $key ] );
					if ( 'throttled' === ( $outcome['result']['e'] ?? '' ) ) {
						$slow_down[] = $outcome['result'];
						continue;
					}
				} else {
					$active[ $key ] = $outcome['job'];
				}
				$fine += self::same_origin( (string) $job['current'] ) ? 1 : 0;
			}
			if ( $slow_down ) {
				$asked = 0;
				foreach ( $slow_down as $rec ) {
					$asked = max( $asked, (int) ( $rec['r'] ?? 0 ) );
				}
				$state['throttled'] = (int) ( $state['throttled'] ?? 0 ) + count( $slow_down );
				self::throttle_back( $state, (int) ( $slow_down[0]['c'] ?? 429 ), $asked );
				// Nothing more is sent before the pause ends; what is still open is asked again later
				// without counting as a try, because it was not refused itself.
				foreach ( $active as $key => $job ) {
					$results[ $key ] = array(
						'c' => 0,
						'e' => 'deferred',
					);
				}
				return $results;
			}
			self::throttle_ramp( $state, $fine );
		}
		foreach ( $active as $key => $job ) {
			$results[ $key ] = array(
				'c' => 0,
				'e' => 'loop',
				'f' => $job['current'],
			);
		}
		return $results;
	}

	/**
	 * What one response means for an address: a final result, or the next request to send.
	 *
	 * @param array          $job      Job: start, current, method, hops, retried.
	 * @param array|WP_Error $response Response.
	 * @param string         $method   Method the caller asked for.
	 *
	 * @return array{result?:array<string,mixed>,job?:array<string,mixed>}
	 */
	private static function next_hop( array $job, $response, string $method ): array {
		$moved = $job['current'] !== $job['start'] ? $job['current'] : '';
		if ( is_wp_error( $response ) ) {
			if ( 'HEAD' === $job['method'] && ! $job['retried'] ) {
				$job['method']  = 'GET';
				$job['retried'] = true;
				return array( 'job' => $job );
			}
			$result = array(
				'c' => 0,
				'e' => LucidLab_MPC_Crawl_Http::is_timeout( $response ) ? 'timeout' : 'failed',
			);
			if ( '' !== $moved ) {
				$result['f'] = $moved;
			}
			return array( 'result' => $result );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 429, 503 ), true ) && self::same_origin( $job['current'] ) ) {
			// This site asks to slow down. Not a GET retry and not a result: the check pauses.
			$after = wp_remote_retrieve_header( $response, 'retry-after' );
			return array(
				'result' => array(
					'c' => $code,
					'e' => 'throttled',
					'r' => self::retry_after_seconds( is_array( $after ) ? (string) end( $after ) : (string) $after ),
				),
			);
		}
		$location = wp_remote_retrieve_header( $response, 'location' );
		$location = is_array( $location ) ? (string) end( $location ) : (string) $location;
		if ( $code >= 300 && $code < 400 && '' !== $location ) {
			$next = self::absolute_url( $location, $job['current'] );
			if ( '' === $next ) {
				return array(
					'result' => array(
						'c' => $code,
						'e' => 'unsafe',
					),
				);
			}
			if ( str_contains( $next, 'wp-login.php' ) ) {
				return array(
					'result' => array(
						'c' => $code,
						'e' => 'private',
						'f' => $next,
					),
				);
			}
			$was_here = self::same_origin( $job['start'] );
			if ( $was_here && ! self::same_origin( $next ) ) {
				// A link of this site that sends visitors elsewhere is not checked further.
				return array(
					'result' => array(
						'c' => $code,
						'e' => 'offsite',
						'f' => $next,
					),
				);
			}
			if ( ! self::same_origin( $next ) && ! wp_http_validate_url( $next ) ) {
				return array(
					'result' => array(
						'c' => $code,
						'e' => 'unsafe',
						'f' => $next,
					),
				);
			}
			if ( $job['hops'] >= self::MAX_HOPS ) {
				return array(
					'result' => array(
						'c' => $code,
						'e' => 'loop',
						'f' => $next,
					),
				);
			}
			$job['current'] = $next;
			$job['hops']    = (int) $job['hops'] + 1;
			$job['method']  = $method;
			$job['retried'] = false;
			return array( 'job' => $job );
		}
		$definite = in_array( $code, array( 404, 410 ), true ) && self::same_origin( $job['current'] );
		if ( ( $code < 200 || $code >= 300 ) && ! $definite && 'HEAD' === $job['method'] && ! $job['retried'] ) {
			// Some servers refuse HEAD (405, 403, 501). A 404 or 410 from this site is final.
			$job['method']  = 'GET';
			$job['retried'] = true;
			return array( 'job' => $job );
		}
		$result = array( 'c' => $code );
		if ( '' !== $moved ) {
			$result['f'] = $moved;
		}
		if ( 'GET' === $method && $code >= 200 && $code < 300 ) {
			$result['b'] = (string) wp_remote_retrieve_body( $response );
		}
		return array( 'result' => $result );
	}

	/**
	 * Page key of a link without view-only query arguments (sorting, filters, paging of shop lists):
	 * /shop/?orderby=price and /shop/?filter_color=red show the listed /shop/ page, so they need no
	 * request of their own. Addresses whose query selects the content (p, page_id, product, cat …) keep it.
	 *
	 * @param string $url Url.
	 *
	 * @return string
	 */
	public static function view_key( string $url ): string {
		$query = (string) self::url_part( $url, PHP_URL_QUERY );
		if ( '' === $query ) {
			return self::page_key( $url );
		}
		parse_str( $query, $args );
		$keep = array( 'p', 'page_id', 'post_type', 'attachment_id', 'cat', 'tag', 'product', 'product_cat', 'product_tag', 'author', 'm', 'year', 'monthnum', 'name', 'pagename', 'lang' );
		foreach ( array_keys( $args ) as $name ) {
			if ( in_array( strtolower( (string) $name ), $keep, true ) ) {
				return self::page_key( $url );
			}
		}
		return self::page_key( (string) strtok( $url, '?' ) );
	}

	/**
	 * Address key used to match links with listed pages: lower case, no fragment, no trailing slash.
	 *
	 * @param string $url Url.
	 *
	 * @return string
	 */
	private static function page_key( string $url ): string {
		$url = (string) strtok( $url, '#' );
		$url = (string) preg_replace( '#^https?://(?:www\.)?#i', '', $url );
		return untrailingslashit( strtolower( $url ) );
	}

	/**
	 * Listed file.
	 *
	 * @param int $uid Uid.
	 *
	 * @return string
	 */
	public static function listed_file( int $uid ): string {
		return 'crawl-listed-' . max( 0, $uid ) . '.json';
	}

	/**
	 * Write the keys of all listed pages, once the list is complete.
	 *
	 * @param int $uid Uid.
	 */
	private static function save_listed( int $uid ): void {
		$keys   = array();
		$path   = LucidLab_MPC_Work::path( self::urls_file( $uid ) );
		$handle = is_readable( $path ) ? fopen( $path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( $handle ) {
			while ( true ) {
				$line = fgets( $handle );
				if ( false === $line ) {
					break;
				}
				$row = json_decode( $line, true );
				if ( is_array( $row ) && ! empty( $row['url'] ) ) {
					$keys[ self::page_key( (string) $row['url'] ) ] = 1;
				}
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
		$json = wp_json_encode( $keys );
		if ( is_string( $json ) ) {
			file_put_contents( LucidLab_MPC_Work::path( self::listed_file( $uid ) ), $json, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
	}

	/**
	 * Keys of all listed pages.
	 *
	 * @param int $uid Uid.
	 *
	 * @return array<string,int>
	 */
	private static function load_listed( int $uid ): array {
		$path = LucidLab_MPC_Work::path( self::listed_file( $uid ) );
		$data = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Read url.
	 *
	 * @param int   $uid   Uid.
	 * @param array &$state State.
	 *
	 * @throws RuntimeException When the operation fails.
	 * @return array|null
	 */
	private static function read_url( int $uid, array &$state ): ?array {
		$path   = LucidLab_MPC_Work::path( self::urls_file( $uid ) );
		$handle = ( is_readable( $path ) ? fopen( $path, 'rb' ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		if ( ! $handle ) {
			return null;
		}
		$offset = max( 0, (int) ( $state['cursor']['offset'] ?? 0 ) );
		if ( 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
			return null;
		}
		try {
			for ( $attempt = 0; $attempt < 1000; $attempt++ ) {
				$line            = fgets( $handle );
				$state['cursor'] = array( 'offset' => (int) ftell( $handle ) );
				if ( ! is_string( $line ) ) {
					return null; }
				$row = json_decode( $line, true );
				if ( is_array( $row ) && ! empty( $row['url'] ) ) {
					return $row; }
			}
			throw new RuntimeException( __( 'Crawler work file contains too many invalid rows. Start a new scan.', 'lucidlab-mpc-cleanup' ) );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
	}

	/**
	 * Media edit link.
	 *
	 * @param string $url Url.
	 *
	 * @return string
	 */
	private static function media_edit_link( string $url ): string {
		if ( ! function_exists( 'attachment_url_to_postid' ) ) {
			return '';
		}
		$clean = (string) strtok( $url, '?' );
		$id    = (int) attachment_url_to_postid( $clean );
		if ( ! $id ) {
			$stripped = (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $clean );
			if ( '' !== $stripped && $stripped !== $clean ) {
				$id = (int) attachment_url_to_postid( $stripped );
			}
		}
		if ( ! $id ) {
			return '';
		}
		$edit = get_edit_post_link( $id, 'raw' );
		return is_string( $edit ) ? $edit : '';
	}

	/**
	 * Append urls.
	 *
	 * @param int                             $uid  Uid.
	 * @param array<int,array<string,string>> $rows Rows.
	 *
	 * @return int
	 */
	private static function append_urls( int $uid, array $rows ): int {
		static $listed = array();
		$clean         = array();
		foreach ( $rows as $row ) {
			$url = (string) ( $row['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			// The same page can be listed twice (for example a front page that is also a post). Check it once.
			$key = $uid . '|' . untrailingslashit( strtolower( $url ) );
			if ( isset( $listed[ $key ] ) ) {
				continue;
			}
			$listed[ $key ] = 1;
			$clean[]        = array(
				'url'   => $url,
				'title' => (string) ( $row['title'] ?? $url ),
				'edit'  => (string) ( $row['edit'] ?? '' ),
			);
		}
		if ( $clean ) {
			LucidLab_MPC_Work::append( self::urls_file( $uid ), $clean );
		}
		return count( $clean );
	}

	/**
	 * List posts.
	 *
	 * @param int      $offset Offset.
	 * @param int      $limit  Limit.
	 * @param int|null $after  After.
	 *
	 * @return array{rows:array<int,array<string,string>>,scanned:int}
	 * @throws RuntimeException When the operation fails.
	 */
	private static function list_posts( int $offset, int $limit, ?int $after = null ): array {
		global $wpdb;
		$types = self::post_types();
		if ( ! $types ) {
			return array(
				'rows'    => array(),
				'scanned' => 0,
			);
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args         = $types;
		$cursor_where = null === $after ? '' : ' AND ID > %d';
		if ( null !== $after ) {
			$args[] = $after; }
		$args[] = $limit;
		$paging = null === $after ? ' LIMIT %d OFFSET %d' : ' LIMIT %d';
		if ( null === $after ) {
			$args[] = $offset; }
		$sql  = $wpdb->prepare(
			"SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders) $cursor_where ORDER BY ID ASC$paging", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are internal table names; values are prepared.
			...$args
		);
		$rows = $wpdb->get_results( $sql ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the return value of prepare() above; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not list crawler pages; scan is incomplete.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		$home    = untrailingslashit( home_url( '/' ) );
		$out     = array();
		$scanned = count( (array) $rows );
		// Permalinks and edit links read full posts. Fetch cache misses together
		// instead of issuing a separate post lookup for every URL in this batch.
		if ( $scanned && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_map( static fn( $row ) => (int) $row->ID, $rows ), false, false );
		}
		foreach ( (array) $rows as $row ) {
			$id  = (int) $row->ID;
			$url = get_permalink( $id );
			if ( ! is_string( $url ) || '' === $url || untrailingslashit( $url ) === $home ) {
				continue;
			}
			$edit  = get_edit_post_link( $id, 'raw' );
			$out[] = array(
				'url'   => $url,
				'title' => ( ( $row->post_title ? $row->post_title : ( '#' . $id ) ) ) . ' (' . $row->post_type . ')',
				'edit'  => is_string( $edit ) ? $edit : '',
			);
		}
		return array(
			'rows'    => $out,
			'scanned' => $scanned,
			'last_id' => $scanned ? (int) $rows[ $scanned - 1 ]->ID : (int) $after,
		);
	}

	/**
	 * List terms.
	 *
	 * @param int      $offset Offset.
	 * @param int      $limit  Limit.
	 * @param int|null $after  After.
	 *
	 * @return array{rows:array<int,array<string,string>>,scanned:int}
	 * @throws RuntimeException When the operation fails.
	 */
	private static function list_terms( int $offset, int $limit, ?int $after = null ): array {
		global $wpdb;
		$taxes = array_values( get_taxonomies( array( 'public' => true ), 'names' ) );
		if ( ! $taxes ) {
			return array(
				'rows'    => array(),
				'scanned' => 0,
			);
		}
		$placeholders = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
		$args         = $taxes;
		$cursor_where = null === $after ? '' : ' AND tt.term_taxonomy_id > %d';
		if ( null !== $after ) {
			$args[] = $after; }
		$args[] = $limit;
		$paging = null === $after ? ' LIMIT %d OFFSET %d' : ' LIMIT %d';
		if ( null === $after ) {
			$args[] = $offset; }
		$sql  = $wpdb->prepare(
			"SELECT tt.term_taxonomy_id, t.term_id, t.name, tt.taxonomy FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN ($placeholders) $cursor_where ORDER BY tt.term_taxonomy_id ASC$paging", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers are internal table names; values are prepared.
			...$args
		);
		$rows = $wpdb->get_results( $sql ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the return value of prepare() above; Batch scan/backup/maintenance query; no core API, results are not reused across requests.
		if ( $wpdb->last_error ) {
			throw new RuntimeException( __( 'Could not list crawler pages; scan is incomplete.', 'lucidlab-mpc-cleanup' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		$out     = array();
		$scanned = count( (array) $rows );
		// Archive links and edit links share these term objects.
		if ( $scanned && function_exists( '_prime_term_caches' ) ) {
			_prime_term_caches( array_map( static fn( $row ) => (int) $row->term_id, $rows ), false );
		}
		foreach ( (array) $rows as $row ) {
			$link = get_term_link( (int) $row->term_id, (string) $row->taxonomy );
			if ( is_wp_error( $link ) || ! is_string( $link ) || '' === $link ) {
				continue;
			}
			$edit  = get_edit_term_link( (int) $row->term_id, (string) $row->taxonomy );
			$out[] = array(
				'url'   => $link,
				'title' => $row->name . ' (' . $row->taxonomy . ')',
				'edit'  => is_string( $edit ) ? $edit : '',
			);
		}
		return array(
			'rows'    => $out,
			'scanned' => $scanned,
			'last_id' => $scanned ? (int) $rows[ $scanned - 1 ]->term_taxonomy_id : (int) $after,
		);
	}

	/**
	 * Post types.
	 *
	 * @return string[]
	 */
	private static function post_types(): array {
		$types = get_post_types( array( 'publicly_queryable' => true ), 'names' );
		unset( $types['attachment'] );
		if ( ! isset( $types['page'] ) && post_type_exists( 'page' ) ) {
			$types['page'] = 'page';
		}
		return array_values( $types );
	}

	/**
	 * Srcset urls.
	 *
	 * @param string $srcset Srcset.
	 *
	 * @return string[]
	 */
	private static function srcset_urls( string $srcset ): array {
		$urls = array();
		foreach ( explode( ',', $srcset ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$bits = preg_split( '/\s+/', $part );
			if ( is_array( $bits ) && '' !== ( $bits[0] ?? '' ) ) {
				$urls[] = $bits[0];
			}
		}
		return $urls;
	}

	/**
	 * Bare host.
	 *
	 * @param string $host Host.
	 *
	 * @return string
	 */
	private static function bare_host( string $host ): string {
		$host = strtolower( $host );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Url part.
	 *
	 * @param string $url       Url.
	 * @param int    $component Component.
	 *
	 * @return string|int|null
	 */
	private static function url_part( string $url, int $component ): string|int|null {
		$part = wp_parse_url( $url, $component );
		if ( false === $part || null === $part ) {
			return null;
		}
		return $part;
	}

	/**
	 * Whether an address has exactly the scheme host and port of this site (requests to it need no public-address check).
	 *
	 * @param string $url Url.
	 *
	 * @return bool
	 */
	private static function same_origin( string $url ): bool {
		$home  = wp_parse_url( home_url( '/' ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! is_array( $home ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}
		$home_scheme = strtolower( (string) ( $home['scheme'] ?? 'https' ) );
		$port        = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
		$home_port   = (int) ( $home['port'] ?? ( 'https' === $home_scheme ? 443 : 80 ) );
		// http and https of the same host count as this site; the default ports follow the scheme.
		if ( $port !== $home_port && ! ( in_array( $port, array( 80, 443 ), true ) && in_array( $home_port, array( 80, 443 ), true ) ) ) {
			return false;
		}
		return strtolower( (string) ( $parts['host'] ?? '' ) ) === strtolower( (string) ( $home['host'] ?? '' ) );
	}

	/**
	 * Cache keys for healthy answers: local files are keyed by their file signature, so a changed file is checked again.
	 *
	 * @param array $urls Urls.
	 *
	 * @return array
	 */
	private static function cache_keys( array $urls ): array {
		$keys = array();
		if ( ! class_exists( 'LucidLab_MPC_Result_Cache' ) ) {
			return $keys; }
		foreach ( $urls as $url ) {
			$path      = class_exists( 'LucidLab_MPC_Helpers' ) ? LucidLab_MPC_Helpers::uploads_url_to_path( $url ) : '';
			$signature = '' !== $path ? LucidLab_MPC_Result_Cache::file_signature( $path ) : 'http';
			if ( null !== $signature ) {
				$keys[ $url ] = home_url( '/' ) . ':' . (string) get_option( 'lucidlab_mpc_index_generation', 0 ) . ':' . $signature;
			}
		}
		return $keys;
	}

	/**
	 * File on disk behind a static address of this site (uploads, wp-content, wp-includes, wp-admin), or ''.
	 * PHP files and addresses outside those folders return ''.
	 *
	 * @param string $url Url.
	 *
	 * @return string
	 */
	private static function local_path( string $url ): string {
		if ( ! self::same_origin( $url ) ) {
			return '';
		}
		$path = (string) self::url_part( $url, PHP_URL_PATH );
		if ( '' === $path || ! preg_match( '/\.(?:' . self::IMAGE_EXT . '|' . self::FILE_EXT . '|' . self::MEDIA_EXT . '|' . self::FONT_EXT . '|css|js|map|html?)$/i', $path ) ) {
			return '';
		}
		$path = '/' . ltrim( wp_normalize_path( rawurldecode( $path ) ), '/' );
		if ( str_contains( $path, '/../' ) || str_contains( $path, "\0" ) ) {
			return '';
		}
		$roots = array();
		if ( class_exists( 'LucidLab_MPC_Helpers' ) ) {
			$uploads = LucidLab_MPC_Helpers::uploads_url_to_path( $url );
			if ( '' !== $uploads ) {
				return $uploads;
			}
		}
		if ( defined( 'WP_CONTENT_DIR' ) && function_exists( 'content_url' ) ) {
			$roots[] = array( (string) self::url_part( content_url( '/' ), PHP_URL_PATH ), WP_CONTENT_DIR );
		}
		if ( function_exists( 'includes_url' ) ) {
			$roots[] = array( (string) self::url_part( includes_url( '/' ), PHP_URL_PATH ), ABSPATH . WPINC );
		}
		if ( function_exists( 'admin_url' ) ) {
			$roots[] = array( (string) self::url_part( admin_url( '/' ), PHP_URL_PATH ), ABSPATH . 'wp-admin' );
		}
		foreach ( $roots as $root ) {
			$prefix = '/' . trim( wp_normalize_path( $root[0] ), '/' ) . '/';
			if ( '//' === $prefix || ! str_starts_with( strtolower( $path ), strtolower( $prefix ) ) ) {
				continue;
			}
			return wp_normalize_path( untrailingslashit( (string) $root[1] ) . '/' . substr( $path, strlen( $prefix ) ) );
		}
		return '';
	}

	/**
	 * Attributes of one tag, names lower-cased and values entity-decoded.
	 *
	 * @param string $source Everything between the tag name and >.
	 *
	 * @return array<string,string>
	 */
	private static function attributes( string $source ): array {
		$attrs = array();
		if ( preg_match_all( '/([^\s=\/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+)))?/', $source, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name = strtolower( $match[1] );
				if ( isset( $attrs[ $name ] ) ) {
					continue;
				}
				$value          = (string) ( $match[2] ?? '' ) . (string) ( $match[3] ?? '' ) . (string) ( $match[4] ?? '' );
				$attrs[ $name ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $attrs;
	}

	/**
	 * Addresses of one tag, with their kind ('srcset' values are split later).
	 *
	 * @param string               $name  Tag name.
	 * @param array<string,string> $attrs Attributes.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function tag_urls( string $name, array $attrs ): array {
		$out = array();
		$get = static function ( string $attr ) use ( $attrs ): string {
			return isset( $attrs[ $attr ] ) ? trim( $attrs[ $attr ] ) : '';
		};
		switch ( $name ) {
			case 'img':
			case 'source':
				// A source of video or audio is media; one of a picture element is an image (by its file type).
				$kind = 'img' === $name ? 'image' : 'media';
				foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-original', 'data-large_image', 'data-full-url' ) as $attr ) {
					if ( '' !== $get( $attr ) ) {
						$out[] = array( $get( $attr ), $kind );
					}
				}
				foreach ( array( 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $attr ) {
					if ( '' !== $get( $attr ) ) {
						$out[] = array( $get( $attr ), 'srcset' );
					}
				}
				break;
			case 'link':
				$rel = strtolower( $get( 'rel' ) );
				$as  = strtolower( $get( 'as' ) );
				if ( preg_match( '/\bstylesheet\b/', $rel ) || ( str_contains( $rel, 'preload' ) && 'style' === $as ) ) {
					$out[] = array( $get( 'href' ), 'css' );
				} elseif ( preg_match( '/\b(?:icon|apple-touch-icon|apple-touch-icon-precomposed|mask-icon)\b/', $rel ) || ( str_contains( $rel, 'preload' ) && 'image' === $as ) ) {
					$out[] = array( $get( 'href' ), 'image' );
				} elseif ( str_contains( $rel, 'modulepreload' ) || ( str_contains( $rel, 'preload' ) && 'script' === $as ) ) {
					$out[] = array( $get( 'href' ), 'js' );
				} elseif ( str_contains( $rel, 'preload' ) && 'font' === $as ) {
					$out[] = array( $get( 'href' ), 'font' );
				}
				break;
			case 'script':
				$out[] = array( $get( 'src' ), 'js' );
				break;
			case 'a':
			case 'area':
				if ( ! isset( $attrs['download'] ) || '' === $get( 'href' ) ) {
					$out[] = array( $get( 'href' ), 'link' );
				} else {
					$out[] = array( $get( 'href' ), 'file' );
				}
				break;
			case 'video':
			case 'audio':
			case 'track':
			case 'embed':
				$out[] = array( $get( 'src' ), 'media' );
				if ( 'video' === $name && '' !== $get( 'poster' ) ) {
					$out[] = array( $get( 'poster' ), 'image' );
				}
				break;
			case 'object':
				$out[] = array( $get( 'data' ), 'media' );
				break;
			case 'iframe':
				$out[] = array( $get( 'src' ), 'iframe' );
				break;
			case 'meta':
				$property = strtolower( $get( 'property' ) . $get( 'name' ) );
				if ( in_array( $property, array( 'og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src', 'msapplication-tileimage' ), true ) ) {
					$out[] = array( $get( 'content' ), 'image' );
				}
				break;
			case 'input':
				if ( 'image' === strtolower( $get( 'type' ) ) ) {
					$out[] = array( $get( 'src' ), 'image' );
				}
				break;
		}
		return array_values( array_filter( $out, static fn( $pair ) => '' !== $pair[0] ) );
	}

	/**
	 * Kind from the file type: a link to a PDF is a file, a link to a JPEG is an image, a media tag
	 * that points to an image is an image. Returns '' for addresses that are skipped.
	 *
	 * @param string $url  Url.
	 * @param string $kind Kind from the tag.
	 *
	 * @return string
	 */
	private static function refine_kind( string $url, string $kind ): string {
		if ( in_array( $kind, array( 'link', 'file', 'media' ), true ) ) {
			if ( self::has_ext( $url, self::IMAGE_EXT ) ) {
				return 'image';
			}
			if ( self::has_ext( $url, self::MEDIA_EXT ) ) {
				return 'media';
			}
			if ( self::has_ext( $url, self::FILE_EXT ) ) {
				return 'file';
			}
			if ( 'link' === $kind && self::has_ext( $url, 'css' ) ) {
				return 'css';
			}
			if ( 'link' === $kind && self::has_ext( $url, 'js' ) ) {
				return 'js';
			}
			return 'media' === $kind ? 'file' : $kind;
		}
		return $kind;
	}

	/**
	 * Links that are not checked: admin, login, feeds, REST, cart and account actions, nonces and replies.
	 *
	 * @param string $url Url.
	 *
	 * @return bool
	 */
	private static function skipped_link( string $url ): bool {
		$path  = strtolower( (string) self::url_part( $url, PHP_URL_PATH ) );
		$query = strtolower( (string) self::url_part( $url, PHP_URL_QUERY ) );
		$skip  = (bool) preg_match( '#/(?:wp-admin|wp-login\.php|xmlrpc\.php|wp-cron\.php|wp-json|wp-signup\.php|wp-activate\.php|wp-trackback\.php)(?:/|$)|/feed/?$|/trackback/?$|customer-logout#', $path )
			|| (bool) preg_match( '/(?:^|&)(?:add-to-cart|add_to_wishlist|remove_item|undo_item|removed_item|wc-ajax|_wpnonce|nonce|action|replytocom|s|logout|share|preview|doing_wp_cron)=/', $query );
		/**
		 * Filters whether the live-page check skips a link.
		 *
		 * @param bool   $skip Whether the link is skipped.
		 * @param string $url  Link address.
		 */
		return (bool) apply_filters( 'lucidlab_mpc_crawler_skip_url', $skip, $url );
	}

	/**
	 * Raw url() values in CSS.
	 *
	 * @param string $css Css.
	 *
	 * @return string[]
	 */
	private static function css_urls( string $css ): array {
		$out = array();
		if ( preg_match_all( '/url\(\s*(["\']?)([^)\'"]+)\1\s*\)/i', $css, $matches ) ) {
			foreach ( $matches[2] as $raw ) {
				$raw = trim( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ) );
				if ( '' !== $raw && '#' !== $raw[0] && ! str_starts_with( $raw, '%23' ) && ! str_starts_with( strtolower( $raw ), 'data:' ) ) {
					$out[] = $raw;
				}
			}
		}
		return $out;
	}

	/**
	 * Whether the address path ends in one of the extensions (a regex alternation).
	 *
	 * @param string $url        Url.
	 * @param string $extensions Extensions.
	 *
	 * @return bool
	 */
	private static function has_ext( string $url, string $extensions ): bool {
		$path = (string) self::url_part( $url, PHP_URL_PATH );
		return (bool) preg_match( '/\.(?:' . $extensions . ')$/i', $path );
	}

	/**
	 * Looks like image.
	 *
	 * @param string $url Url.
	 *
	 * @return bool
	 */
	private static function looks_like_image( string $url ): bool {
		$path = strtolower( (string) self::url_part( $url, PHP_URL_PATH ) );
		return self::has_ext( $url, self::IMAGE_EXT ) || str_contains( $path, '/uploads/' );
	}

	/**
	 * Whether a result means "ask again after the pause": refused with 429/503 (counts as a try) or
	 * not sent because another address was refused first (does not count).
	 *
	 * @param array  &$state State.
	 * @param string $url   Address.
	 * @param array  $rec   Result.
	 *
	 * @return bool
	 */
	private static function ask_again( array &$state, string $url, array $rec ): bool {
		$error = (string) ( $rec['e'] ?? '' );
		if ( 'deferred' === $error ) {
			return true;
		}
		return 'throttled' === $error && self::count_try( $state, $url );
	}

	/**
	 * Count a 429/503 refusal of an address; false once it has had MAX_RETRIES tries.
	 *
	 * @param array  &$state State.
	 * @param string $url   Address.
	 *
	 * @return bool
	 */
	private static function count_try( array &$state, string $url ): bool {
		if ( ! isset( $state['retries'] ) || ! is_array( $state['retries'] ) ) {
			$state['retries'] = array();
		}
		$tries                    = (int) ( $state['retries'][ $url ] ?? 0 ) + 1;
		$state['retries'][ $url ] = $tries;
		if ( $tries > self::MAX_RETRIES ) {
			unset( $state['retries'][ $url ] );
			return false;
		}
		return true;
	}

	/**
	 * Requests at the same time right now: the configured limit, lowered after 429/503.
	 *
	 * @param array $state State.
	 * @param bool  $pages Pages (true) or other addresses (false).
	 *
	 * @return int
	 */
	private static function current_par( array $state, bool $pages ): int {
		$limit = $pages ? self::parallel_limit() : self::asset_parallel_limit();
		$now   = (int) ( $state['throttle']['par'] ?? $limit );
		return max( 1, min( $limit, $now ) );
	}

	/**
	 * Wait so that requests to this site stay under the current rate (requests per second).
	 *
	 * @param array &$state State.
	 * @param int   $count Requests to this site about to be sent.
	 */
	private static function pace( array &$state, int $count ): void {
		if ( $count < 1 ) {
			return;
		}
		$rate = max( 0.2, (float) ( $state['throttle']['rate'] ?? self::rate_limit() ) );
		$now  = microtime( true );
		$next = (float) ( $state['throttle']['next'] ?? 0 );
		if ( $next > $now ) {
			usleep( (int) min( 5000000, ( $next - $now ) * 1000000 ) );
			$now = microtime( true );
		}
		$state['throttle']['next'] = max( $now, $next ) + $count / $rate;
	}

	/**
	 * Most requests per second to this site (lucidlab_mpc_crawler_rate, 0.2 to 50, default 4).
	 *
	 * @return float
	 */
	private static function rate_limit(): float {
		/**
		 * Filters how many requests per second the live-page check sends to this site at most.
		 *
		 * @param float $rate Requests per second (0.2 to 50).
		 */
		return max( 0.2, min( 50.0, (float) apply_filters( 'lucidlab_mpc_crawler_rate', self::DEFAULT_RATE ) ) );
	}

	/**
	 * User agent of the check, so hosts can recognise it in their logs and rate limiters.
	 *
	 * @return string
	 */
	private static function user_agent(): string {
		$version = defined( 'LUCIDLAB_MPC_VERSION' ) ? (string) LUCIDLAB_MPC_VERSION : '';
		$agent   = 'Mozilla/5.0 (compatible; LucidLabLiveCheck/' . $version . '; +https://lucidlab.eu/privacy/)';
		/**
		 * Filters the User-Agent of the live-page check.
		 *
		 * @param string $agent User agent.
		 */
		return (string) apply_filters( 'lucidlab_mpc_crawler_user_agent', $agent );
	}

	/**
	 * How many pages the crawler requests at the same time. Kept small so the site is not overloaded.
	 *
	 * @return int
	 */
	private static function parallel_limit(): int {
		/**
		 * Filters how many pages the live-page check requests at the same time (1 to 8).
		 *
		 * @param int $limit Parallel page requests.
		 */
		return max( 1, min( 8, (int) apply_filters( 'lucidlab_mpc_crawler_parallel', 4 ) ) );
	}

	/**
	 * How many images, files and links the crawler checks at the same time. These are HEAD
	 * requests, mostly for static files, so they cost the server far less than pages.
	 *
	 * @return int
	 */
	private static function asset_parallel_limit(): int {
		/**
		 * Filters how many images, files and links the live-page check requests at the same time (1 to 16).
		 *
		 * @param int $limit Parallel requests for images, files and links.
		 */
		return max( 1, min( 16, (int) apply_filters( 'lucidlab_mpc_crawler_parallel_assets', self::parallel_limit() ) ) );
	}

	/**
	 * Load status. Results of older versions (a bare status code) are read as array( 'c' => code ).
	 *
	 * @param int $uid Uid.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function load_status( int $uid ): array {
		$path = LucidLab_MPC_Work::path( self::status_file( $uid ) );
		$data = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		$out  = array();
		foreach ( is_array( $data ) ? $data : array() as $url => $rec ) {
			$out[ (string) $url ] = is_array( $rec ) ? $rec : array( 'c' => (int) $rec );
		}
		return $out;
	}

	/**
	 * Save status.
	 *
	 * @param int   $uid    Uid.
	 * @param array $status Status.
	 */
	private static function save_status( int $uid, array $status ): void {
		$json = wp_json_encode( $status );
		if ( is_string( $json ) ) {
			file_put_contents( LucidLab_MPC_Work::path( self::status_file( $uid ) ), $json, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local streaming/atomic file I/O under uploads or wp-content; WP_Filesystem has no streaming or atomic-rename API.
		}
	}

	/**
	 * Progress.
	 *
	 * @param array<string,mixed> $state State.
	 *
	 * @return int
	 */
	private static function progress( array $state ): int {
		if ( 'done' === ( $state['phase'] ?? '' ) ) {
			return 100;
		}
		if ( 'list' === ( $state['phase'] ?? '' ) ) {
			return 0;
		}
		// Pages checked of pages listed, the same numbers as the label.
		$total = max( 1, (int) ( $state['pages'] ?? 0 ) );
		return (int) min( 99, floor( 100 * (int) ( $state['checked'] ?? 0 ) / $total ) );
	}

	/**
	 * Label.
	 *
	 * @param array<string,mixed> $state State.
	 *
	 * @return string
	 */
	private static function label( array $state ): string {
		if ( 'done' === ( $state['phase'] ?? '' ) ) {
			return sprintf(
				/* translators: 1: pages checked, 2: addresses checked, 3: problems found, 4: pages that could not be read, 5: inconclusive checks */
				__( 'Checked %1$d pages and %2$d images, files and links. %3$d problems found. %4$d pages could not be read; %5$d checks were inconclusive.', 'lucidlab-mpc-cleanup' ),
				(int) ( $state['checked'] ?? 0 ),
				(int) ( $state['urls'] ?? 0 ),
				(int) ( $state['broken'] ?? 0 ) + (int) ( $state['suppressed'] ?? 0 ),
				(int) ( $state['page_errors'] ?? 0 ),
				(int) ( $state['image_errors'] ?? 0 )
			);
		}
		if ( 'list' === ( $state['phase'] ?? '' ) ) {
			return sprintf(
				/* translators: %d: public pages found so far */
				__( 'Listing public pages (%d found)…', 'lucidlab-mpc-cleanup' ),
				(int) ( $state['pages'] ?? 0 )
			);
		}
		$until = self::paused_until( $state );
		if ( $until ) {
			return sprintf(
				/* translators: 1: HTTP status (429 or 503), 2: time the check resumes, 3: pages checked, 4: pages listed */
				__( 'Paused: the server asked us to slow down (HTTP %1$s). Resuming automatically at %2$s, one request at a time. Pages checked so far: %3$d / %4$d.', 'lucidlab-mpc-cleanup' ),
				(string) ( $state['throttle']['reason'] ?? '429' ),
				function_exists( 'wp_date' ) ? (string) wp_date( (string) get_option( 'time_format', 'H:i' ), $until ) : gmdate( 'H:i', $until ),
				(int) ( $state['checked'] ?? 0 ),
				(int) ( $state['pages'] ?? 0 )
			);
		}
		return sprintf(
			/* translators: 1: pages checked, 2: pages listed, 3: addresses checked, 4: problems found */
			__( 'Checking pages (%1$d / %2$d), %3$d images, files and links… %4$d problems', 'lucidlab-mpc-cleanup' ),
			(int) ( $state['checked'] ?? 0 ),
			(int) ( $state['pages'] ?? 0 ),
			(int) ( $state['urls'] ?? 0 ),
			(int) ( $state['broken'] ?? 0 ) + (int) ( $state['suppressed'] ?? 0 )
		);
	}
}
