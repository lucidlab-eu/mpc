<?php
/**
 * Lucid Lab Media & Products Cleanup: ai dashboard class.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LucidLab_MPC_AI_Dashboard.
 */
final class LucidLab_MPC_AI_Dashboard {
	/**
	 * Render.
	 */
	public static function render(): void {
		echo '<div id="lucidlab-mpc-ai-job" class="lucidlab-mpc-ai-job-status-card notice notice-info inline">';
		echo ( '<p><strong>' . esc_html__( 'AI library review', 'lucidlab-mpc-cleanup' ) . '</strong></p>' );
		echo ( '<p data-ai-job-status role="status" style="margin: 12px 0; font-family: inherit;">' . esc_html__( 'Ready · No library job started.', 'lucidlab-mpc-cleanup' ) . '</p>' );
		echo ( '<p data-ai-job-usage role="status" style="margin: 12px 0; font-family: inherit;">' . esc_html__( 'Today (UTC): 0 / 0 requests · estimated hosted cost: 0.0000', 'lucidlab-mpc-cleanup' ) . '</p>' );
		echo '<progress value="0" max="100"></progress>';
		echo ( '<p class="lucidlab-mpc-hint">' . esc_html__( 'Stop and Cancel take effect after the current image finishes. Background processing uses WP-Cron and requires visits or a server cron runner. Refresh saved results below to see completed items.', 'lucidlab-mpc-cleanup' ) . '</p>' );
		echo '</div>';
	}
}
