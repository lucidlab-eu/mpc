<?php
/**
 * Lucid Lab Media & Products Cleanup: plugin formats trait.
 *
 * @package LucidLab_MPC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WebP / AVIF quality cards and the sample-image comparison, shared by Free and Pro. */
trait LucidLab_MPC_Plugin_Formats {
	/**
	 * AJAX handler: webp preview.
	 */
	private function ajax_webp_preview(): void {
		$sub   = sanitize_key( wp_unslash( $this->req['sub'] ?? 'run' ) );
		$input = LucidLab_MPC_Helpers::json_input( $this->req['settings'] ?? '{}', 'auto' );
		$input = is_array( $input ) ? $input : array();
		try {
			if ( 'start' === $sub ) {
				$this->task_success( LucidLab_MPC_Format_Preview::start( $input ) );
			} elseif ( 'step' === $sub ) {
				$this->task_success( LucidLab_MPC_Format_Preview::step() );
			} elseif ( 'status' === $sub ) {
				$this->task_success( LucidLab_MPC_Format_Preview::status() );
			} else {
				$this->task_success( LucidLab_MPC_Format_Preview::compare( $input ) );
			}
		} catch ( Throwable $e ) {
			$this->task_error( array( 'message' => $e->getMessage() ), 400 );
		}
	}

	/**
	 * AJAX handler: webp preview clear.
	 */
	private function ajax_webp_preview_clear(): void {
		$this->task_success( LucidLab_MPC_Format_Preview::clear() );
	}

	/**
	 * Webp format caps.
	 *
	 * @return array
	 */
	private function webp_format_caps(): array {
		if ( LucidLab_MPC_Edition::pro() && isset( $this->webp ) && method_exists( $this->webp, 'caps' ) ) {
			return $this->webp->caps();
		}
		return LucidLab_MPC_Format_Preview::caps();
	}

	/**
	 * Render webp format cards.
	 *
	 * @param bool $library Library.
	 */
	private function render_webp_format_cards( bool $library ): void {
		$caps  = $this->webp_format_caps();
		$saved = class_exists( 'LucidLab_MPC_Settings', false ) ? LucidLab_MPC_Settings::page_webp() : array();
		?>
		<div id="lucidlab-mpc-webp-settings" class="lucidlab-mpc-format-columns">
		<?php foreach ( array( 'webp', 'avif' ) as $fmt ) : ?>
			<?php
			// Keep AVIF settings visible in Free so the edition boundary is clear.
			$defaults = array( LucidLab_MPC_Helpers::default_quality( $fmt ), LucidLab_MPC_Helpers::default_quality( $fmt, true ) );
			$encoder  = LucidLab_MPC_Helpers::nextgen_encoder( $fmt );
			$q_img    = (int) ( $saved[ $fmt . '_images' ] ?? 0 );
			$q_thumb  = (int) ( $saved[ $fmt . '_thumbnails' ] ?? 0 );
			if ( $q_img < 1 || $q_img > 100 ) {
				$q_img = $defaults[0];
			}
			if ( $q_thumb < 1 || $q_thumb > 100 ) {
				$q_thumb = $defaults[1];
			}
			$enabled = ! empty( $caps[ $fmt ] ) && ( ! isset( $saved[ $fmt . '_enabled' ] ) || ! empty( $saved[ $fmt . '_enabled' ] ) );
			$mode    = ( ( $saved[ $fmt . '_mode' ] ?? 'all' ) === 'featured' ) ? 'featured' : 'all';
			?>
			<fieldset class="lucidlab-mpc-format-card" data-format="<?php echo esc_attr( $fmt ); ?>" <?php disabled( empty( $caps[ $fmt ] ) || ( 'avif' === $fmt && ! LucidLab_MPC_Edition::pro() ) ); ?>>
				<legend><?php echo esc_html( strtoupper( $fmt ) ); ?></legend>
				<?php
				if ( 'webp' === $fmt && $library ) :
					?>
					<p class="lucidlab-mpc-hint"><?php esc_html_e( 'A WebP copy that is not smaller than the original is removed, so visitors get the original.', 'lucidlab-mpc-cleanup' ); ?></p><?php endif; ?>
				<?php
				if ( 'avif' === $fmt ) :
					?>
					<p class="lucidlab-mpc-hint"><?php esc_html_e( 'AVIF takes longer to create and usually produces smaller files, depending on the image and quality settings.', 'lucidlab-mpc-cleanup' ); ?>
					<?php
					if ( $library ) :
						?>
										<?php esc_html_e( 'An AVIF copy that is not smaller than the WebP copy (or the original when there is no WebP) is removed, so visitors get the smaller file.', 'lucidlab-mpc-cleanup' ); ?><?php endif; ?></p><?php endif; ?>
				<?php
				if ( 'none' !== $encoder ) :
					?>
					<p class="lucidlab-mpc-hint"><?php echo esc_html( sprintf( /* translators: %s: GD, Imagick or WordPress */ __( 'Encoder on this server: %s.', 'lucidlab-mpc-cleanup' ), $encoder ) ); ?>
					<?php
					if ( 'avif' === $fmt ) :
						?>
										<?php esc_html_e( 'GD and Imagick use different quality scales: Imagick makes larger AVIF files at the same number, so its defaults are 50 and 40 instead of 60 and 50. Imagick copies leave out camera metadata but keep the colour profile.', 'lucidlab-mpc-cleanup' ); ?><?php endif; ?></p><?php endif; ?>
				<p><label class="lucidlab-mpc-format-check"><input type="checkbox" class="lucidlab-mpc-webp-fmt" value="<?php echo esc_attr( $fmt ); ?>" <?php checked( $enabled ); ?> /> <?php echo esc_html( sprintf( /* translators: %s: WEBP or AVIF */ __( 'Generate %s copies', 'lucidlab-mpc-cleanup' ), strtoupper( $fmt ) ) ); ?></label>
				<?php
				if ( empty( $caps[ $fmt ] ) ) :
					?>
					— <?php esc_html_e( 'unavailable on this server', 'lucidlab-mpc-cleanup' ); ?><?php endif; ?></p>
				<?php if ( $library ) : ?>
				<p><label class="lucidlab-mpc-format-field"><?php esc_html_e( 'Images', 'lucidlab-mpc-cleanup' ); ?> <select class="lucidlab-mpc-format-mode"><option value="all" <?php selected( $mode, 'all' ); ?>><?php esc_html_e( 'All images', 'lucidlab-mpc-cleanup' ); ?></option><option value="featured" <?php selected( $mode, 'featured' ); ?>><?php esc_html_e( 'Featured images only', 'lucidlab-mpc-cleanup' ); ?></option></select></label></p>
				<?php else : ?>
				<select class="lucidlab-mpc-format-mode" hidden><option value="all" selected><?php esc_html_e( 'All images', 'lucidlab-mpc-cleanup' ); ?></option></select>
				<?php endif; ?>
				<p><label class="lucidlab-mpc-format-field"><?php esc_html_e( 'Quality Images', 'lucidlab-mpc-cleanup' ); ?> <input type="number" class="lucidlab-mpc-quality-images" min="1" max="100" value="<?php echo (int) $q_img; ?>" /></label></p>
				<?php if ( $library ) : ?>
				<p><label class="lucidlab-mpc-format-field"><?php esc_html_e( 'Quality Thumbnails', 'lucidlab-mpc-cleanup' ); ?> <input type="number" class="lucidlab-mpc-quality-thumbnails" min="1" max="100" value="<?php echo (int) $q_thumb; ?>" /></label></p>
				<?php else : ?>
				<input type="hidden" class="lucidlab-mpc-quality-thumbnails" value="<?php echo (int) $q_thumb; ?>">
				<?php endif; ?>
			</fieldset>
		<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render webp compare cell.
	 *
	 * @param array|null $item     Item.
	 * @param string     $fallback Fallback.
	 */
	private function render_webp_compare_cell( ?array $item, string $fallback ): void {
		$item = is_array( $item ) ? $item : array();
		if ( empty( $item['url'] ) ) {
			echo '<td><span class="lucidlab-mpc-hint">' . esc_html( (string) ( $item['error'] ?? $fallback ) ) . '</span></td>';
			return;
		}
		$saved = isset( $item['percent'] ) ? (int) $item['percent'] : null;
		echo '<td class="lucidlab-mpc-webp-compare-cell">';
		echo '<div class="lucidlab-mpc-webp-zoom">';
		$full  = (string) $item['url'];
		$thumb = (string) ( $item['thumb'] ?? $full );
		printf(
			'<img class="lucidlab-mpc-lb" src="%s" data-full="%s" data-caption="%s" alt="" width="140" height="140">',
			esc_url( '' !== $thumb ? $thumb : $full ),
			esc_url( $full ),
			esc_attr( (string) ( $item['caption'] ?? $fallback ) )
		);
		echo '</div><div class="lucidlab-mpc-webp-compare-meta"><strong>' . esc_html( (string) ( $item['label'] ?? '' ) ) . '</strong>';
		if ( null !== $saved && 0 !== $saved ) {
			$text = $saved > 0
				? sprintf( /* translators: %d: percent smaller */ __( '%d%% smaller', 'lucidlab-mpc-cleanup' ), $saved )
				: sprintf( /* translators: %d: percent larger */ __( '%d%% larger', 'lucidlab-mpc-cleanup' ), abs( $saved ) );
			echo '<span class="lucidlab-mpc-hint">' . esc_html( $text ) . '</span>';
		}
		echo '</div></td>';
	}

	/**
	 * Render webp preview section.
	 */
	private function render_webp_preview_section(): void {
		$saved = LucidLab_MPC_Format_Preview::saved();
		$rows  = is_array( $saved['rows'] ?? null ) ? $saved['rows'] : array();
		?>
		<div id="lucidlab-mpc-webp-preview-box" class="lucidlab-mpc-webp-preview">
			<h3><?php esc_html_e( 'Compare 10 images', 'lucidlab-mpc-cleanup' ); ?></h3>
			<p class="lucidlab-mpc-hint"><?php echo LucidLab_MPC_Edition::pro() ? esc_html__( 'Generate ten JPEG/PNG library images as temporary WebP and AVIF copies using the quality settings above. Thumbnails show a 1:1 center crop so low quality is visible; click a picture for the full image and use the arrows to compare Original, WebP and AVIF. Remove the test copies to try different quality values. These preview files are not used on the site.', 'lucidlab-mpc-cleanup' ) : esc_html__( 'Compare ten JPEG/PNG library images as temporary WebP and AVIF previews. WebP uses the quality setting above; AVIF uses default preview quality. Thumbnails show a 1:1 center crop; click a picture for the full image and use the arrows to compare. Remove the test copies to try different WebP settings. Preview files are not used on the site.', 'lucidlab-mpc-cleanup' ); ?></p>
			<p class="lucidlab-mpc-btn-row"><button type="button" class="button button-primary" id="lucidlab-mpc-webp-preview"><?php esc_html_e( 'Generate 10 images to compare', 'lucidlab-mpc-cleanup' ); ?></button> <button type="button" class="button" id="lucidlab-mpc-webp-preview-clear"><?php esc_html_e( 'Remove test images', 'lucidlab-mpc-cleanup' ); ?></button></p>
			<p id="lucidlab-mpc-webp-preview-progress" class="lucidlab-mpc-webp-preview-progress lucidlab-mpc-hint" role="status" hidden></p>
			<p id="lucidlab-mpc-webp-preview-status" class="lucidlab-mpc-hint" role="status"></p>
			<div class="lucidlab-mpc-webp-compare-wrap" <?php echo $rows ? '' : 'hidden'; ?>>
				<table class="widefat lucidlab-mpc-table lucidlab-mpc-webp-compare">
					<thead><tr><th><?php esc_html_e( 'Image', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'Original', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'WebP', 'lucidlab-mpc-cleanup' ); ?></th><th><?php esc_html_e( 'AVIF', 'lucidlab-mpc-cleanup' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( html_entity_decode( (string) ( $row['title'] ?? ( '#' . (int) ( $row['id'] ?? 0 ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); ?></td>
							<?php $this->render_webp_compare_cell( is_array( $row['original'] ?? null ) ? $row['original'] : null, __( 'Original', 'lucidlab-mpc-cleanup' ) ); ?>
							<?php $this->render_webp_compare_cell( is_array( $row['webp'] ?? null ) ? $row['webp'] : null, __( 'Not generated', 'lucidlab-mpc-cleanup' ) ); ?>
							<?php $this->render_webp_compare_cell( is_array( $row['avif'] ?? null ) ? $row['avif'] : null, __( 'Not generated', 'lucidlab-mpc-cleanup' ) ); ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
