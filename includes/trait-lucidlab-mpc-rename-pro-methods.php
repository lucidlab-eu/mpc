<?php
/**
 * Lucid Lab Media & Products Cleanup: rename pro methods trait.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! trait_exists( 'LucidLab_MPC_Rename_Pro_Methods' ) ) {
	/**
	 * Trait LucidLab_MPC_Rename_Pro_Methods.
	 */
	trait LucidLab_MPC_Rename_Pro_Methods {
		/**
		 * Job action.
		 *
		 * @param string $action Action.
		 * @param array  $input  Input.
		 *
		 * @throws RuntimeException When the operation fails.
		 * @return array
		 */
		public function job_action( string $action, array $input = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Same signature as the Pro implementation that reads $input.
			$j = $this->job();
			if ( ! in_array( $action, array( 'status', 'stop', 'cancel' ), true ) ) {
				throw new RuntimeException( __( 'Bulk rename requires Pro.', 'lucidlab-mpc-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is escaped where printed (esc_html) or sent as JSON.
			}
			if ( 'status' !== $action ) {
				$j['status'] = 'cancel' === $action ? 'cancelled' : 'paused';
				update_option( self::JOB_OPTION, $j, false );
				wp_clear_scheduled_hook( self::CRON_HOOK );}
			return $j;
		}
	}
}
