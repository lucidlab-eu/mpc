<?php
/**
 * Lucid Lab Media & Products Cleanup: reports class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

if ( ! class_exists( 'LucidLab_MPC_Reports', false ) ) {
	/**
	 * Class LucidLab_MPC_Reports.
	 */
	class LucidLab_MPC_Reports {
		/**
		 * Configure.
		 */
		public function configure(): void {
			$settings = LucidLab_MPC_Settings::all();
			foreach ( array( LucidLab_MPC_Plugin::CRON_REPORT, LucidLab_MPC_Plugin::CRON_REPORT_MONTHLY ) as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
			if ( empty( $settings['report_enabled'] ) || ! LucidLab_MPC_Helpers::parse_emails( $settings['report_recipient'] ?? '' ) ) {
				return;
			}
			$monthly = 'monthly' === ( $settings['report_frequency'] ?? 'weekly' );
			$hook    = $monthly ? LucidLab_MPC_Plugin::CRON_REPORT_MONTHLY : LucidLab_MPC_Plugin::CRON_REPORT;
			$when    = LucidLab_MPC_Helpers::next_weekday_hour(
				(int) ( $settings['schedule_weekday'] ?? 1 ),
				(int) ( $settings['schedule_hour'] ?? 3 ),
				$monthly
			);
			wp_schedule_single_event( $when, $hook );
		}

		/**
		 * Send.
		 *
		 * @param mixed $override_to Override to.
		 *
		 * @return bool
		 */
		public function send( $override_to = null ): bool {
			$settings  = LucidLab_MPC_Settings::all();
			$attempted = time();
			$to        = LucidLab_MPC_Helpers::parse_emails(
				null !== $override_to && '' !== $override_to
					? $override_to
					: ( ! empty( $settings['report_recipient'] ) ? $settings['report_recipient'] : get_option( 'admin_email' ) )
			);
			if ( ! $to ) {
				update_option(
					'lucidlab_mpc_report_delivery',
					array_merge(
						(array) get_option( 'lucidlab_mpc_report_delivery', array() ),
						array(
							'last_attempt' => $attempted,
							'last_status'  => 'failed',
						)
					),
					false
				);
				return false;
			}
			$rows = array(
				array( __( 'Total reclaimable', 'lucidlab-mpc-cleanup' ), LucidLab_MPC_Helpers::bytes( LucidLab_MPC_Stats::total_bytes() ) . ' (' . number_format_i18n( LucidLab_MPC_Stats::total_count() ) . ' items)' ),
			);
			foreach ( LucidLab_MPC_Stats::all() as $slice => $data ) {
				$rows[] = array(
					LucidLab_MPC_Stats::label( (string) $slice ),
					LucidLab_MPC_Helpers::bytes( (int) ( $data['bytes'] ?? 0 ) ) . ' (' . number_format_i18n( (int) ( $data['count'] ?? 0 ) ) . ' items)',
				);
			}
			$site                     = wp_parse_url( home_url(), PHP_URL_HOST );
			$sent                     = LucidLab_MPC_Helpers::mail_html(
				$to,
				__( 'Media & Products Cleanup report', 'lucidlab-mpc-cleanup' ),
				__( 'Cleanup report', 'lucidlab-mpc-cleanup' ),
				array(
					array(
						'heading' => __( 'Reclaimable media', 'lucidlab-mpc-cleanup' ),
						'rows'    => $rows,
					),
				),
				__( 'Open Media & Products Cleanup in WordPress to review the latest scan results.', 'lucidlab-mpc-cleanup' ) . ( $site ? ' Site: ' . $site : '' )
			);
			$delivery                 = (array) get_option( 'lucidlab_mpc_report_delivery', array() );
			$delivery['last_attempt'] = $attempted;
			$delivery['last_status']  = $sent ? 'sent' : 'failed';
			if ( $sent ) {
				$delivery['last_success'] = time();
			}
			update_option( 'lucidlab_mpc_report_delivery', $delivery, false );
			if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
				LucidLab_MPC_Logger::log( $sent ? __( 'Email report sent.', 'lucidlab-mpc-cleanup' ) : __( 'Email report failed to send.', 'lucidlab-mpc-cleanup' ), $sent ? 'info' : 'error' );
			}
			return $sent;
		}

		/**
		 * Recipients configured under Options → Email report (no admin_email fallback).
		 *
		 * @return list<string>
		 */
		public function option_recipients(): array {
			$settings = LucidLab_MPC_Settings::all();
			return LucidLab_MPC_Helpers::parse_emails( $settings['report_recipient'] ?? '' );
		}

		/**
		 * Email the emergency restore URL and key to Options recipients.
		 *
		 * @throws RuntimeException When the operation fails.
		 */
		public function send_emergency_restore(): bool {
			throw new RuntimeException( __( 'Web-accessible emergency restore is unavailable. Download the backup and use your hosting provider or WordPress recovery tools.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
		}

		/**
		 * Email stage outcomes after Run All finishes.
		 *
		 * @param array $job Job.
		 *
		 * @return bool
		 */
		public function send_run_all( array $job ): bool {
			$to = $this->option_recipients();
			if ( ! $to ) {
				return false;
			}
			$site       = wp_parse_url( home_url(), PHP_URL_HOST );
			$stage_rows = array();
			foreach ( (array) ( $job['stages'] ?? array() ) as $stage ) {
				$status       = (string) ( $stage['status'] ?? '' );
				$label        = (string) ( $stage['label'] ?? __( 'Stage', 'lucidlab-mpc-cleanup' ) );
				$message      = trim( (string) ( $stage['message'] ?? '' ) );
				$mark         = 'done' === $status ? __( 'Done', 'lucidlab-mpc-cleanup' ) : ( 'skipped' === $status ? __( 'Skipped', 'lucidlab-mpc-cleanup' ) : ucfirst( ( $status ? $status : 'unknown' ) ) );
				$stage_rows[] = array( $label, $mark . ( $message ? ' — ' . $message : '' ) );
			}
			$sections = array(
				array(
					'heading' => __( 'Stages', 'lucidlab-mpc-cleanup' ),
					'rows'    => $stage_rows,
				),
			);
			if ( ! empty( $job['warnings'] ) ) {
				$sections[] = array(
					'heading' => __( 'Warnings', 'lucidlab-mpc-cleanup' ),
					'text'    => implode( ', ', array_unique( (array) $job['warnings'] ) ),
				);
			}
			$stat_rows = array(
				array( __( 'Total reclaimable', 'lucidlab-mpc-cleanup' ), LucidLab_MPC_Helpers::bytes( LucidLab_MPC_Stats::total_bytes() ) . ' (' . number_format_i18n( LucidLab_MPC_Stats::total_count() ) . ' items)' ),
			);
			foreach ( LucidLab_MPC_Stats::all() as $slice => $data ) {
				$stat_rows[] = array(
					LucidLab_MPC_Stats::label( (string) $slice ),
					LucidLab_MPC_Helpers::bytes( (int) ( $data['bytes'] ?? 0 ) ) . ' (' . number_format_i18n( (int) ( $data['count'] ?? 0 ) ) . ' items)',
				);
			}
			$sections[] = array(
				'heading' => __( 'Cached reclaimable totals', 'lucidlab-mpc-cleanup' ),
				'rows'    => $stat_rows,
			);
			return LucidLab_MPC_Helpers::mail_html(
				$to,
				/* translators: %s is the website name in the email subject. */
				sprintf( __( '[%s] Run All completed', 'lucidlab-mpc-cleanup' ), ( $site ? $site : 'WordPress' ) ),
				__( 'Run All completed', 'lucidlab-mpc-cleanup' ),
				$sections,
				__( 'Open Media & Products Cleanup in WordPress to review the latest scan results.', 'lucidlab-mpc-cleanup' )
			);
		}

		/**
		 * Cron send.
		 *
		 * @param string $frequency Frequency.
		 */
		public function cron_send( string $frequency = 'weekly' ): void {
			$settings = LucidLab_MPC_Settings::all();
			if ( empty( $settings['report_enabled'] ) || ! LucidLab_MPC_Helpers::parse_emails( $settings['report_recipient'] ?? '' ) || ( $settings['report_frequency'] ?? 'weekly' ) !== $frequency ) {
				return;
			}
			try {
				$sent                               = $this->send( $settings['report_recipient'] );
				$delivery                           = (array) get_option( 'lucidlab_mpc_report_delivery', array() );
				$delivery['last_scheduled_attempt'] = time();
				if ( $sent ) {
					$delivery['last_scheduled_success'] = $delivery['last_scheduled_attempt'];
				}
				update_option( 'lucidlab_mpc_report_delivery', $delivery, false );
			} catch ( Throwable $error ) {
				$delivery                           = (array) get_option( 'lucidlab_mpc_report_delivery', array() );
				$delivery['last_scheduled_attempt'] = time();
				$delivery['last_status']            = 'failed';
				update_option( 'lucidlab_mpc_report_delivery', $delivery, false );
				if ( class_exists( 'LucidLab_MPC_Logger', false ) ) {
					LucidLab_MPC_Logger::error( __( 'Scheduled email report failed.', 'lucidlab-mpc-cleanup' ) );
				}
			} finally {
				$this->configure();
			}
		}
	}
}
