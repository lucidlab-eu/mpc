<?php
/**
 * Lucid Lab Media & Products Cleanup: hooks class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Hooks every request needs, including front-end page views that skip the admin plugin. */
final class LucidLab_MPC_Hooks {
	/**
	 * Done.
	 *
	 * @var bool
	 */
	private static bool $done = false;

	/**
	 * Register.
	 */
	public static function register(): void {
		if ( self::$done ) {
			return;
		}
		self::$done = true;
		add_action( LucidLab_MPC_Index::AUTOBUILD_HOOK, array( 'LucidLab_MPC_Index', 'autobuild_step' ) );
		add_action( 'admin_init', array( 'LucidLab_MPC_Index', 'maybe_schedule_build' ) );
		LucidLab_MPC_Site_Ping::register();
		add_filter(
			'get_attached_file',
			static function ( $file, $attachment_id ) {
				$resolved = LucidLab_MPC_Helpers::attached_file_path( (int) $attachment_id );
				return '' !== $resolved ? $resolved : $file;
			},
			10,
			2
		);
		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				if ( ! isset( $schedules['monthly'] ) ) {
					$schedules['monthly'] = array(
						'interval' => 30 * DAY_IN_SECONDS,
						'display'  => __( 'Monthly', 'lucidlab-mpc-cleanup' ),
					);
				} return $schedules;
			}
		);
		add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'disabled_sizes' ), 100 );
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $option_hook ) {
			add_action(
				$option_hook,
				static function ( $option ) {
					if ( strpos( $option, 'widget_' ) === 0 || strpos( $option, 'theme_mods_' ) === 0 || in_array( $option, array( 'site_icon', 'site_logo', 'woocommerce_placeholder_image', 'woocommerce_email_header_image', 'lucidlab_mpc_settings' ), true ) ) {
						LucidLab_MPC_Reference_Index::touch( 'full' );
						update_option( 'lucidlab_mpc_index_generation', microtime( true ), false );
					}},
				10,
				1
			); }
		// Keep the media index current without rebuilding the whole library on each change.
		foreach ( array( 'add_attachment', 'delete_attachment' ) as $attachment_hook ) {
			add_action(
				$attachment_hook,
				static function () {
					if ( class_exists( 'LucidLab_MPC_Shared', false ) ) {
						LucidLab_MPC_Shared::forget_images();
					} }
			);
		}
		add_action(
			'add_attachment',
			static function ( $id ) {
				LucidLab_MPC_Index::upsert( (int) $id );
			}
		);
		add_action(
			'edit_attachment',
			static function ( $id ) {
				LucidLab_MPC_Index::upsert( (int) $id );
			}
		);
		add_action(
			'save_post_attachment',
			static function ( $id ) {
				LucidLab_MPC_Index::upsert( (int) $id );
			}
		);
		add_action(
			'delete_attachment',
			static function ( $id ) {
				LucidLab_MPC_Index::remove( (int) $id );
				if ( class_exists( 'LucidLab_MPC_Hash_Cache', false ) ) {
					LucidLab_MPC_Hash_Cache::forget( (int) $id );
				} }
		);
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $media_hook ) {
			add_action(
				$media_hook,
				static function ( $meta_id, $post_id, $key ) {
					if ( '_wp_attached_file' === $key && 'attachment' === get_post_type( $post_id ) ) {
						LucidLab_MPC_Index::upsert( (int) $post_id );
					}
				},
				10,
				3
			);
		}
		$bump_scan_generation = static function ( $first = 0, $second = null, $meta_key = '' ) {
			static $queued = false;
			static $posts  = array();
			static $terms  = array();
			// Orders, stock, prices and edit locks never add or remove media references.
			$key = is_string( $meta_key ) ? $meta_key : '';
			if ( '' !== $key && ( in_array( $key, array( '_edit_lock', '_edit_last', 'total_sales', '_stock', '_stock_status', '_price', '_regular_price', '_sale_price', '_wc_average_rating', '_wc_review_count', '_wc_rating_count', 'product_count_product_cat', 'product_count_product_tag', 'order' ), true ) || str_starts_with( $key, '_transient' ) ) ) {
				return;
			}
			$hook    = (string) current_filter();
			$post_id = 0;
			$term_id = 0;
			if ( in_array( $hook, array( 'save_post', 'deleted_post' ), true ) ) {
				$post_id = (int) $first;
			} elseif ( str_contains( $hook, '_post_meta' ) ) {
				$post_id = (int) $second;
			} elseif ( in_array( $hook, array( 'created_term', 'edited_term', 'delete_term' ), true ) ) {
				$term_id = (int) $first;
			} elseif ( str_contains( $hook, '_term_meta' ) ) {
				$term_id = (int) $second;
			}
			if ( $post_id > 0 && in_array( get_post_type( $post_id ), array( 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription', 'revision', 'customize_changeset', 'oembed_cache' ), true ) ) {
				return;
			}
			if ( $post_id > 0 ) {
				$posts[ $post_id ] = 1;
			}
			if ( $term_id > 0 ) {
				$terms[ $term_id ] = 1;
			}
			if ( $queued ) {
				return;
			}
			$queued = true;
			add_action(
				'shutdown',
				static function () use ( &$posts, &$terms ) {
					if ( $posts ) {
						LucidLab_MPC_Reference_Index::touch_ids( 'posts', array_keys( $posts ) );
					}
					if ( $terms ) {
						LucidLab_MPC_Reference_Index::touch_ids( 'terms', array_keys( $terms ) );
					}
					update_option( 'lucidlab_mpc_index_generation', microtime( true ), false );
				},
				20
			);
		};
		foreach ( array( 'save_post', 'deleted_post', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta', 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, $bump_scan_generation, 10, str_contains( $hook, '_meta' ) ? 3 : 1 );
		}
	}

	/**
	 * Disabled sizes.
	 *
	 * @param array $sizes Sizes.
	 *
	 * @return array
	 */
	public static function disabled_sizes( array $sizes ): array {
		foreach ( (array) LucidLab_MPC_Settings::get( 'disabled_sizes' ) as $name ) {
			unset( $sizes[ $name ] );
		}
		return $sizes;
	}
}
