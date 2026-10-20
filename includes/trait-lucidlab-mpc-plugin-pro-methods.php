<?php
/**
 * Lucid Lab Media & Products Cleanup: plugin pro methods trait.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! trait_exists( 'LucidLab_MPC_Plugin_Pro_Methods' ) ) {
	/**
	 * Trait LucidLab_MPC_Plugin_Pro_Methods.
	 */
	trait LucidLab_MPC_Plugin_Pro_Methods {
		/**
		 * Render review.
		 */
		private function render_review(): void {
			$this->render_pro_required_notice( __( 'The Review Queue requires the Pro add-on.', 'lucidlab-mpc-cleanup' ) ); }
		/**
		 * Render visual.
		 */
		private function render_visual(): void {
			$this->render_pro_required_notice( __( 'Visual comparison requires the Pro add-on.', 'lucidlab-mpc-cleanup' ) ); }
		/**
		 * Render dbcleaner.
		 *
		 * @param mixed $data Data.
		 */
		private function render_dbcleaner( $data = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Same signature as the Pro implementation that renders $data.
			$this->render_pro_required_notice( __( 'Database Cleaner requires the Pro add-on. It finds unused database tables and leftover records from inactive or missing plugins and moves them to restorable quarantine.', 'lucidlab-mpc-cleanup' ) ); }
		/**
		 * AJAX handler: scan dbcleaner.
		 */
		private function ajax_scan_dbcleaner(): void {
			$this->task_error( array( 'message' => __( 'Database Cleaner requires Pro.', 'lucidlab-mpc-cleanup' ) ), 403 ); }
		/**
		 * AJAX handler: quarantine dbcleaner.
		 */
		private function ajax_quarantine_dbcleaner(): void {
			$this->task_error( array( 'message' => __( 'Database Cleaner requires Pro.', 'lucidlab-mpc-cleanup' ) ), 403 ); }
		/**
		 * AJAX handler: db shrink preview.
		 */
		private function ajax_db_shrink_preview(): void {
			$this->task_error( array( 'message' => __( 'Database Cleaner shrink tools require Pro.', 'lucidlab-mpc-cleanup' ) ), 403 ); }
		/**
		 * AJAX handler: db shrink run.
		 */
		private function ajax_db_shrink_run(): void {
			$this->task_error( array( 'message' => __( 'Database Cleaner shrink tools require Pro.', 'lucidlab-mpc-cleanup' ) ), 403 ); }
		/**
		 * AJAX handler: convert webp.
		 */
		private function ajax_convert_webp(): void {
			$action = sanitize_key( wp_unslash( $this->req['sub'] ?? 'status' ) );
			if ( ! in_array( $action, array( 'status', 'start', 'step', 'stop', 'resume', 'background', 'cancel', 'retry' ), true ) ) {
				$this->task_error( array( 'message' => __( 'Invalid action.', 'lucidlab-mpc-cleanup' ) ), 400 );
			}
			$input = LucidLab_MPC_Helpers::json_input( $this->req['settings'] ?? '{}', 'auto' );
			try {
				$this->task_success( $this->webp->job_action( $action, is_array( $input ) ? $input : array() ) ); } catch ( Throwable $error ) {
							$this->task_error( array( 'message' => $error->getMessage() ), 400 ); }
		}
		/**
		 * Render webp.
		 */
		private function render_webp(): void {
			$this->begin_results_card( ' data-mpc-results-section="webp-generate"' );
			echo '<h3 class="lucidlab-mpc-card__title">' . esc_html__( 'Generate WebP copies', 'lucidlab-mpc-cleanup' ) . '</h3>';
			echo '<p class="lucidlab-mpc-hint">' . esc_html__( 'Free can create WebP copies for the image library. Choose quality and generate copies below.', 'lucidlab-mpc-cleanup' ) . '</p>';
			echo '<p id="lucidlab-mpc-webp-stat" class="notice notice-info inline lucidlab-mpc-job-summary" role="status" hidden></p>';
			echo '<div id="lucidlab-mpc-webp">';
			$this->render_webp_format_cards( true );
			$saved = LucidLab_MPC_Settings::page_webp();
			echo '<p><label class="lucidlab-mpc-format-check"><input type="checkbox" id="lucidlab-mpc-webp-skip-existing" ' . checked( ! empty( $saved['skip_existing'] ), true, false ) . '> ' . esc_html__( 'Skip existing WebP copies', 'lucidlab-mpc-cleanup' ) . '</label></p>';
			$this->render_webp_preview_section();
			echo '<p class="lucidlab-mpc-btn-row"><button type="button" class="button button-primary" id="lucidlab-mpc-webp-start">' . esc_html__( 'Generate WebP copies', 'lucidlab-mpc-cleanup' ) . '</button></p>';
			echo '</div>';
			$this->end_results_card();
			$this->render_pro_required_notice( __( 'AVIF conversion, original-image quarantine and generated-copy cleanup require the Pro add-on.', 'lucidlab-mpc-cleanup' ) );
		}
		/**
		 * Sizes scan full.
		 *
		 * @return array
		 */
		private function sizes_scan_full(): array {
			$counts = array();
			$o      = 0;
			do {
				$n  = $this->sizes->analyze_step( $o, $counts );
				$o += (int) LucidLab_MPC_Settings::get( 'batch_db' );
			} while ( (int) LucidLab_MPC_Settings::get( 'batch_db' ) === $n );
			return array(
				'type'   => 'sizes',
				'counts' => $counts,
			);
		}
		/**
		 * AJAX handler: scan sizes.
		 */
		private function ajax_scan_sizes(): void {
			$skey = $this->k_state( 'sizes' );
			$rkey = 'lucidlab_mpc_sizecounts_' . $this->uid();
			if ( ! empty( $this->req['reset'] ) ) {
				delete_transient( $skey );
				$this->delete_scan_result( 'sizes' );
				set_transient( $rkey, array(), $this->state_ttl() );
				set_transient(
					$skey,
					array(
						'offset' => 0,
						'total'  => $this->sizes->total_images(),
					),
					$this->state_ttl()
				);
				$this->task_success(
					array(
						'progress' => 3,
						'label'    => __( 'Analyzing…', 'lucidlab-mpc-cleanup' ),
						'done'     => false,
					)
				);
			}
			$state            = get_transient( $skey );
			$counts           = get_transient( $rkey );
			$state            = is_array( $state ) ? $state : array(
				'offset' => 0,
				'total'  => 0,
			);
			$counts           = is_array( $counts ) ? $counts : array();
			$n                = $this->sizes->analyze_step( (int) $state['offset'], $counts );
			$state['offset'] += (int) LucidLab_MPC_Settings::get( 'batch_db' );
			set_transient( $rkey, $counts, $this->state_ttl() );
			if ( $n < (int) LucidLab_MPC_Settings::get( 'batch_db' ) ) {
				$this->set_scan_result(
					'sizes',
					array(
						'type'   => 'sizes',
						'counts' => $counts,
					)
				);
				delete_transient( $skey );
				delete_transient( $rkey );
				$this->task_success(
					array(
						'progress' => 100,
						'done'     => true,
					)
				);
			}
			set_transient( $skey, $state, $this->state_ttl() );
			$pct = $state['total'] > 0 ? min( 99, (int) ( $state['offset'] / $state['total'] * 100 ) ) : 50;
			/* translators: %d is the number of images analyzed so far. */
			$this->task_success(
				array(
					'progress' => $pct,
					'label'    => sprintf( /* translators: %d: number of images analyzed so far. */ __( 'Analyzing (%d)…', 'lucidlab-mpc-cleanup' ), (int) $state['offset'] ),
					'done'     => false,
				)
			);
		}
		/**
		 * Render sizes.
		 *
		 * @param mixed $data Data.
		 */
		private function render_sizes( $data ): void {
			$sizes = $this->sizes->registered();
			if ( ! is_array( $data ) ) {
				$data = $this->get_scan_result( 'sizes' );
			}
			$counts = is_array( $data ) ? ( $data['counts'] ?? array() ) : array();
			?>
	<form method="post" class="lucidlab-mpc-controls">
			<?php wp_nonce_field( 'lucidlab_mpc_op_scan_sizes' ); ?>
	<input type="hidden" name="lucidlab_mpc_op" value="scan_sizes" />
	<button type="submit" class="button button-primary" data-mpc-scan="sizes" data-testid="scan-sizes"><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Analyze sizes', 'lucidlab-mpc-cleanup' ); ?></button>
	</form>
			<?php $this->progress_bar( 'sizes' ); ?>

			<?php $this->begin_results_card( ' data-mpc-results-section="sizes"' ); ?>
	<form method="post" class="lucidlab-mpc-sizes-form">
			<?php wp_nonce_field( 'lucidlab_mpc_op_save_options' ); ?>
	<input type="hidden" name="lucidlab_mpc_op" value="save_options" />
	<input type="hidden" name="lucidlab_mpc_options_scope" value="disabled_sizes" />
	<table class="widefat lucidlab-mpc-table">
	<thead><tr>
	<th><input type="checkbox" class="lucidlab-mpc-selectall" data-testid="sizes-select-all" /> <?php esc_html_e( 'Disable', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Size', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Dimensions', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Crop', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'In use', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Disk', 'lucidlab-mpc-cleanup' ); ?></th>
	</tr></thead>
	<tbody>
			<?php
			$row_names = array_values( array_unique( array_merge( array_keys( $sizes ), array_keys( $counts ) ) ) );
			foreach ( $row_names as $name ) :
				$s      = $sizes[ $name ] ?? null;
				$c      = $counts[ $name ] ?? array(
					'n'     => 0,
					'bytes' => 0,
				);
				$legacy = ! is_array( $s );
				?>
	<tr>
		<td>
				<?php
				if ( $legacy ) :
					?>
		<span class="lucidlab-mpc-hint"><?php esc_html_e( 'legacy', 'lucidlab-mpc-cleanup' ); ?></span>
					<?php
else :
	?>
			<label><input type="checkbox" class="lucidlab-mpc-row-check" name="lucidlab_mpc_disabled_sizes[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( ! empty( $s['disabled'] ) ); ?> data-testid="size-disable-<?php echo esc_attr( $name ); ?>" /> <?php echo ! empty( $s['disabled'] ) ? esc_html__( 'off', 'lucidlab-mpc-cleanup' ) : esc_html__( 'on', 'lucidlab-mpc-cleanup' ); ?></label><?php endif; ?></td>
		<td><code><?php echo esc_html( $name ); ?></code></td>
		<td><?php echo $legacy ? '—' : ( (int) $s['width'] . ' × ' . (int) $s['height'] ); ?></td>
		<td><?php echo $legacy ? '—' : ( $s['crop'] ? esc_html__( 'yes', 'lucidlab-mpc-cleanup' ) : '—' ); ?></td>
		<td><?php echo esc_html( number_format_i18n( (int) $c['n'] ) ); ?></td>
		<td><?php echo esc_html( LucidLab_MPC_Helpers::bytes( (int) $c['bytes'] ) ); ?></td>
	</tr>
	<?php endforeach; ?>
	</tbody>
	</table>
	<div class="lucidlab-mpc-actionbar">
	<button type="submit" class="button" data-testid="save-disabled-sizes"><?php esc_html_e( 'Save disabled sizes', 'lucidlab-mpc-cleanup' ); ?></button>
	</div>
	</form>
	<p class="lucidlab-mpc-hint"><?php esc_html_e( 'Disabling a size only stops WordPress generating it in future — it does not touch files that already exist. Scanning leftover resized files on disk (Other Sizes on Disk) requires Pro.', 'lucidlab-mpc-cleanup' ); ?></p>

			<?php $this->begin_results_card( ' data-mpc-results-section="fs-sizes"' ); ?>
	<h3 class="lucidlab-mpc-card__title"><?php esc_html_e( 'Other Sizes on Disk', 'lucidlab-mpc-cleanup' ); ?></h3>
			<?php $this->render_pro_required_notice( __( 'Scanning leftover resized files on disk and quarantining them by dimensions requires the Pro add-on.', 'lucidlab-mpc-cleanup' ) ); ?>
			<?php
		}
	}
}
