<?php
/**
 * Template: Product Page PWYW Section
 *
 * Variables available:
 *   $config          array        Resolved PWYW config
 *   $last_price      float|null   Raw last-paid price (for data attribute); null if no history
 *   $last_price_note string|null  Human-readable note about the last price
 *   $has_history     bool         True when last price is within current boundaries and used as pre-fill
 *   $out_of_bounds   bool         True when last price exists but is outside current boundaries
 *   $is_variable     bool
 *   $product_id      int
 *   $pre_fill_price  float
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$display_style   = $config['display_style'];
$min_price       = $config['min_price'];
$max_price       = $config['max_price'];
$suggested_price = $config['suggested_price'];
$preset_buttons  = $config['preset_buttons'];
$currency_symbol = $config['currency_symbol'];
$decimals        = $config['decimals'];
$regular_price   = $config['regular_price'];

// Step value based on decimal places (handles JPY with 0 decimal places).
$step = $decimals > 0 ? '0.' . str_repeat( '0', $decimals - 1 ) . '1' : '1';

// Boundary label — use wc_price() HTML directly (not stripped) so HTML entities
// such as currency symbols are rendered by the browser rather than shown literally.
$boundary_html = sprintf(
	/* translators: 1: min price (HTML), 2: max price (HTML) */
	__( 'Pay between %1$s and %2$s', 'wc-pay-what-you-want' ),
	wc_price( $min_price ),
	wc_price( $max_price )
);

// Determine active preset.
$pre_fill_rounded = number_format( (float) $pre_fill_price, $decimals, '.', '' );
$has_active_preset = false;

// Container classes.
$section_classes = 'wcpwyw-section wcpwyw-style-' . strtolower( esc_attr( $display_style ) );
$section_style   = $is_variable ? ' style="display:none;"' : '';
?>
<div class="<?php echo esc_attr( $section_classes ); ?>"
	 id="wcpwyw-section"
	 <?php echo $section_style; // phpcs:ignore WordPress.Security.EscapeOutput ?>
	 aria-live="polite">

	<?php if ( 'D' === $display_style ) : ?>
		<p class="wcpwyw-boundary wcpwyw-price-boundaries" id="wcpwyw-boundary"><?php echo wp_kses_post( $boundary_html ); ?></p>
	<?php endif; ?>

	<?php if ( 'B' === $display_style ) : ?>
		<p class="wcpwyw-regular-price wcpwyw-strikethrough"><del><?php echo wp_kses_post( wc_price( $regular_price ) ); ?></del></p>
	<?php endif; ?>

	<?php if ( 'C' === $display_style ) : ?>
		<p class="wcpwyw-regular-price wcpwyw-reference">
			<?php
			printf(
				/* translators: %s: formatted price */
				esc_html__( 'Regular price: %s', 'wc-pay-what-you-want' ),
				wp_kses_post( wc_price( $regular_price ) )
			);
			?>
		</p>
	<?php endif; ?>

	<h3 class="wcpwyw-heading"><?php echo esc_html( $config['label_input'] ); ?></h3>

	<p class="wcpwyw-suggested">
		<?php
		printf(
			/* translators: %s: formatted suggested price */
			esc_html__( 'Suggested: %s', 'wc-pay-what-you-want' ),
			wp_kses_post( wc_price( $suggested_price ) )
		);
		?>
	</p>

	<?php if ( ! empty( $last_price_note ) ) : ?>
		<p class="wcpwyw-last-price-note"><?php echo wp_kses_post( $last_price_note ); ?></p>
	<?php endif; ?>

	<div class="wcpwyw-presets wcpwyw-preset-buttons" role="group" aria-label="<?php esc_attr_e( 'Quick select price', 'wc-pay-what-you-want' ); ?>">
		<?php
		foreach ( $preset_buttons as $preset_value ) {
			$preset_formatted = number_format( (float) $preset_value, $decimals, '.', '' );
			$is_active        = ( $preset_formatted === $pre_fill_rounded );
			if ( $is_active ) {
				$has_active_preset = true;
			}
			$active_class = $is_active ? ' wcpwyw-preset-btn--active' : '';
			?>
			<button type="button"
					class="wcpwyw-preset-btn<?php echo esc_attr( $active_class ); ?>"
					data-wcpwyw-value="<?php echo esc_attr( $preset_formatted ); ?>">
				<?php echo wp_kses_post( wc_price( (float) $preset_value ) ); ?>
			</button>
			<?php
		}
		$custom_active = $has_active_preset ? '' : ' wcpwyw-preset-btn--active';
		?>
		<button type="button"
				class="wcpwyw-preset-btn wcpwyw-preset-btn--custom<?php echo esc_attr( $custom_active ); ?>">
			<?php esc_html_e( 'Custom', 'wc-pay-what-you-want' ); ?>
		</button>
	</div>

	<div class="wcpwyw-input-wrap wcpwyw-price-wrap">
		<label for="wcpwyw-price-input" class="wcpwyw-currency-symbol">
			<?php echo esc_html( $currency_symbol ); ?>
		</label>
		<input type="number"
			   id="wcpwyw-price-input"
			   name="wcpwyw_price"
			   class="wcpwyw-price-input"
			   min="<?php echo esc_attr( number_format( $min_price, $decimals, '.', '' ) ); ?>"
			   max="<?php echo esc_attr( number_format( $max_price, $decimals, '.', '' ) ); ?>"
			   step="<?php echo esc_attr( $step ); ?>"
			   value="<?php echo esc_attr( number_format( (float) $pre_fill_price, $decimals, '.', '' ) ); ?>"
			   autocomplete="off"
			   aria-describedby="wcpwyw-error wcpwyw-boundary"
			   <?php if ( null !== $last_price ) : ?>
			   data-wcpwyw-last-price="<?php echo esc_attr( number_format( (float) $last_price, $decimals, '.', '' ) ); ?>"
			   <?php endif; ?>
			   <?php if ( ! empty( $has_history ) ) : ?>
			   data-wcpwyw-has-history="1"
			   <?php endif; ?>
			   <?php if ( ! empty( $out_of_bounds ) ) : ?>
			   data-wcpwyw-out-of-bounds="1"
			   <?php endif; ?>
			   />
	</div>

	<p class="wcpwyw-error" id="wcpwyw-error" role="alert" aria-live="assertive" style="display:none;"></p>

	<?php if ( 'D' !== $display_style ) : ?>
		<p class="wcpwyw-boundary wcpwyw-price-boundaries" id="wcpwyw-boundary"><?php echo wp_kses_post( $boundary_html ); ?></p>
	<?php endif; ?>

	<input type="hidden" name="wcpwyw_product_id"   value="<?php echo esc_attr( $product_id ); ?>">
	<input type="hidden" name="wcpwyw_variation_id" value="0">
	<input type="hidden" name="wcpwyw_nonce"        value="<?php echo esc_attr( wp_create_nonce( 'wcpwyw_add_to_cart' ) ); ?>">
</div>
