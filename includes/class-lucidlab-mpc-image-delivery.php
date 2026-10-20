<?php
/**
 * Lucid Lab Media & Products Cleanup: image delivery class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Browser-selected modern copies, with the original img retained as fallback. */
class LucidLab_MPC_Image_Delivery {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'wp_get_attachment_image', array( $this, 'html' ), 30 );
		add_filter( 'the_content', array( $this, 'html' ), 30 );
		add_filter( 'widget_text_content', array( $this, 'html' ), 30 );
		add_filter( 'wp_get_attachment_url', array( $this, 'fallback_url' ), 30 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'fallback_src' ), 30 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'fallback_srcset' ), 30 );
	}

	/**
	 * Uploads.
	 *
	 * @var array{root:string,baseurl:string,host:string,port:mixed,prefix:string}|null
	 */
	private static ?array $uploads = null;
	/**
	 * Usable.
	 *
	 * @var array<string,bool>
	 */
	private static array $usable = array();
	/**
	 * Copies.
	 *
	 * @var array<string,string>
	 */
	private static array $copies = array();

	/**
	 * Uploads.
	 *
	 * @return array
	 */
	private static function uploads(): array {
		if ( null === self::$uploads ) {
			$dir           = wp_get_upload_dir();
			$base          = wp_parse_url( $dir['baseurl'] );
			self::$uploads = array(
				'root'    => trailingslashit( wp_normalize_path( $dir['basedir'] ) ),
				'baseurl' => trailingslashit( $dir['baseurl'] ),
				'host'    => strtolower( (string) ( $base['host'] ?? '' ) ),
				'port'    => $base['port'] ?? null,
				'prefix'  => trailingslashit( (string) ( $base['path'] ?? '' ) ),
			);
		}
		return self::$uploads;
	}

	/**
	 * Uploads-relative path for a local upload URL, or an empty string.
	 *
	 * @param string $url Url.
	 *
	 * @return string
	 */
	private static function relative( string $url ): string {
		$u     = self::uploads();
		$parts = wp_parse_url( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
		if ( ! is_array( $parts ) || ( isset( $parts['host'] ) && strtolower( $parts['host'] ) !== $u['host'] ) || ( isset( $parts['port'] ) && $parts['port'] !== $u['port'] ) ) {
			return ''; }
		$path = $parts['path'] ?? '';
		if ( strpos( $path, $u['prefix'] ) !== 0 ) {
			return ''; }
		$relative = rawurldecode( substr( $path, strlen( $u['prefix'] ) ) );
		return ( $relative && ! preg_match( '~(^|/)\.\.?(/|$)|[\\\\\x00]~', $relative ) ) ? $relative : '';
	}

	/**
	 * One stat per path per request. Empty next-gen files do not count.
	 *
	 * @param string $relative Relative.
	 *
	 * @return bool
	 */
	private static function usable( string $relative ): bool {
		if ( ! isset( self::$usable[ $relative ] ) ) {
			$size                      = ( is_file( self::uploads()['root'] . $relative ) ? filesize( self::uploads()['root'] . $relative ) : false );
			$min                       = preg_match( '/\.(webp|avif)$/i', $relative ) ? 32 : 1;
			self::$usable[ $relative ] = false !== $size && $size >= $min;
		}
		return self::$usable[ $relative ];
	}

	/**
	 * Copy url.
	 *
	 * @param string $url    Url.
	 * @param string $format Format.
	 *
	 * @return string
	 */
	public function copy_url( string $url, string $format ): string {
		if ( ! in_array( $format, array( 'avif', 'webp' ), true ) ) {
			return ''; }
		if ( 'avif' === $format && ! LucidLab_MPC_Edition::pro() ) {
			return ''; }
		$key = $format . '|' . $url;
		if ( isset( self::$copies[ $key ] ) ) {
			return self::$copies[ $key ]; }
		$relative = self::relative( $url );
		$found    = '';
		if ( $relative && preg_match( '/\.(jpe?g|png)$/i', $relative ) ) {
			$candidates = array();
			foreach ( LucidLab_MPC_Helpers::nextgen_prefixes( $format ) as $prefix ) {
				$candidates[] = $prefix . $relative . '.' . $format;
			}
			$candidates[] = $relative . '.' . $format;
			foreach ( $candidates as $candidate ) {
				if ( self::usable( $candidate ) ) {
					$found = self::uploads()['baseurl'] . implode( '/', array_map( 'rawurlencode', explode( '/', $candidate ) ) );
					break;
				}
			}
		}
		self::$copies[ $key ] = $found;
		return $found;
	}

	/**
	 * Stand-in for one srcset size without a copy (removed because it was not smaller): the WebP copy for an AVIF source, else the original, so the other sizes keep their copies.
	 *
	 * @param string $url    Url.
	 * @param string $format Format.
	 *
	 * @return string
	 */
	private function served_without_copy( string $url, string $format ): string {
		$webp = 'avif' === $format ? $this->copy_url( $url, 'webp' ) : '';
		if ( $webp ) {
			return $webp; }
		$relative = self::relative( $url );
		return $relative && preg_match( '/\.(jpe?g|png)$/i', $relative ) && self::usable( $relative ) ? $url : '';
	}

	/**
	 * Html.
	 *
	 * @param string $html Html.
	 *
	 * @return string
	 */
	public function html( string $html ): string {
		if ( is_admin() || is_feed() || ! apply_filters( 'lucidlab_mpc_image_delivery_enabled', true ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html; }
		// Scripts and noscript stay untouched. Existing pictures lose AVIF sources whose files are gone.
		// Pattern syntax must never pass through gettext or HTML escaping.
		$parts = preg_split( '~(<(?:picture|script|style|noscript)\b[^>]*>.*?</(?:picture|script|style|noscript)\s*>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html; }
		foreach ( $parts as $i => $part ) {
			if ( $i % 2 ) {
				if ( stripos( $part, '<picture' ) === 0 ) {
					$parts[ $i ] = $this->drop_missing_avif_sources( $part );
				}
				continue;
			}
			$parts[ $i ] = preg_replace_callback(
				'~<img\b[^>]*>~i',
				function ( $matches ) {
					$tag = new WP_HTML_Tag_Processor( $matches[0] );
					if ( ! $tag->next_tag( 'IMG' ) ) {
						return $matches[0]; }
					// Lazy-load libraries own these URLs and their timing.
					if ( $tag->get_attribute( 'data-src' ) || $tag->get_attribute( 'data-srcset' ) ) {
						return $matches[0]; }
					$src = $tag->get_attribute( 'src' );
					if ( ! is_string( $src ) ) {
						return $matches[0]; }
					$replaced = $this->webp_fallback_url( $src );
					if ( $replaced !== $src ) {
						$tag->set_attribute( 'src', $replaced );
						$src        = $replaced;
						$matches[0] = $tag->get_updated_html();
					}
					$srcset  = $tag->get_attribute( 'srcset' );
					$sources = '';
					foreach ( array( 'avif', 'webp' ) as $format ) {
						$copy = $this->copy_url( $src, $format );
						if ( ! $copy ) {
							continue; }
						$candidates = array();
						$complete   = true;
						if ( is_string( $srcset ) && '' !== $srcset ) {
							foreach ( explode( ',', $srcset ) as $candidate ) {
								$bits   = preg_split( '/\s+/', trim( $candidate ) );
								$mapped = $this->copy_url( $bits[0], $format );
								if ( ! $mapped ) {
									$mapped = $this->served_without_copy( $bits[0], $format );
								}
								if ( ! $mapped ) {
									$complete = false;
									break; }
								$descriptor = $bits[1] ?? '';
								if ( $descriptor && ! preg_match( '/^\d+(?:\.\d+)?[wx]$/', $descriptor ) ) {
									$complete = false;
									break; }
								$candidates[] = $mapped . ( $descriptor ? ' ' . $descriptor : '' );
							}
							// Preserve responsive selection until all its candidates exist.
							if ( ! $complete ) {
								continue; }
						}
						$sizes    = $tag->get_attribute( 'sizes' );
						$sources .= '<source type="image/' . $format . '" srcset="' . esc_attr( $candidates ? implode( ', ', $candidates ) : $copy ) . '"' . ( is_string( $sizes ) ? ' sizes="' . esc_attr( $sizes ) . '"' : '' ) . '>';
					}
					return $sources ? '<picture class="lucidlab-mpc-picture">' . $sources . $matches[0] . '</picture>' : $matches[0];
				},
				$part
			);
		}
		return implode( '', $parts );
	}

	/**
	 * Swap a missing AVIF attachment address for its WebP file.
	 *
	 * @param mixed $url Url.
	 */
	public function fallback_url( $url ) {
		if ( ! is_string( $url ) || is_admin() || is_feed() ) {
			return $url;
		}
		return $this->webp_fallback_url( $url );
	}

	/**
	 * Fallback src.
	 *
	 * @param array{0:string,1:int,2:int}|false $image Image.
	 */
	public function fallback_src( $image ) {
		if ( ! is_array( $image ) || empty( $image[0] ) || ! is_string( $image[0] ) ) {
			return $image;
		}
		$image[0] = $this->fallback_url( $image[0] );
		return $image;
	}

	/**
	 * Fallback srcset.
	 *
	 * @param array<int,array{url?:string}> $sources Sources.
	 */
	public function fallback_srcset( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $width => $source ) {
			if ( ! empty( $source['url'] ) && is_string( $source['url'] ) ) {
				$sources[ $width ]['url'] = $this->fallback_url( $source['url'] );
			}
		}
		return $sources;
	}

	/**
	 * Remove AVIF sources whose files were quarantined so the picture uses WebP.
	 *
	 * @param string $picture Picture.
	 *
	 * @return string
	 */
	private function drop_missing_avif_sources( string $picture ): string {
		return (string) preg_replace_callback(
			'~<source\b[^>]*type=["\']image/avif["\'][^>]*>~i',
			function ( $matches ) {
				if ( ! preg_match( '/\bsrcset=["\']([^"\']+)["\']/i', $matches[0], $srcset ) ) {
					return '';
				}
				foreach ( explode( ',', $srcset[1] ) as $candidate ) {
					$bits = preg_split( '/\s+/', trim( $candidate ) );
					if ( ! LucidLab_MPC_Edition::pro() && ! empty( $bits[0] ) && ! self::plugin_avif_url( $bits[0] ) ) {
						return $matches[0];
					}
					if ( ! LucidLab_MPC_Edition::pro() && ! empty( $bits[0] ) && self::plugin_avif_url( $bits[0] ) ) {
						return '';
					}
					if ( empty( $bits[0] ) || ! $this->upload_file_exists( $bits[0] ) ) {
						return '';
					}
				}
				return $matches[0];
			},
			$picture
		);
	}

	/**
	 * Upload file exists.
	 *
	 * @param string $url Url.
	 *
	 * @return bool
	 */
	private function upload_file_exists( string $url ): bool {
		$relative = self::relative( $url );
		return '' !== $relative && self::usable( $relative );
	}

	/**
	 * Only copies managed in this site's uploads folder are license-gated.
	 *
	 * @param string $url Url.
	 *
	 * @return bool
	 */
	private static function plugin_avif_url( string $url ): bool {
		$relative = self::relative( $url );
		return '' !== $relative && ( '' !== LucidLab_MPC_Helpers::nextgen_prefix_of( $relative, 'avif' ) || 1 === preg_match( '/\.(?:jpe?g|png)\.avif$/i', $relative ) );
	}

	/**
	 * WebP address for an AVIF URL whose file is gone, or the original URL.
	 *
	 * @param string $url Url.
	 *
	 * @return string
	 */
	private function webp_fallback_url( string $url ): string {
		if ( ! preg_match( '/\.avif($|[?#])/i', $url ) || ( ! LucidLab_MPC_Edition::pro() && ! self::plugin_avif_url( $url ) ) || ( LucidLab_MPC_Edition::pro() && $this->upload_file_exists( $url ) ) ) {
			return $url;
		}
		$webp = preg_replace( '#/avif/#i', '/webp/', $url, 1 );
		$webp = preg_replace( '/\.avif($|[?#])/i', '.webp$1', (string) $webp );
		if ( is_string( $webp ) && $this->upload_file_exists( $webp ) ) {
			return $webp;
		}
		$original = preg_replace( '/\.(jpe?g|png)\.avif($|[?#])/i', '.$1$2', (string) preg_replace( '#/(?:' . LucidLab_MPC_Storage::SLUG . '/)?avif/#i', '/', $url, 1 ) );
		return is_string( $original ) && $original !== $url && $this->upload_file_exists( $original ) ? $original : $url;
	}
}
