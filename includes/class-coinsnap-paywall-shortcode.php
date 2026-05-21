<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Coinsnap_Paywall_Shortcode {
	public function __construct() {
		add_shortcode( 'paywall_payment', [ $this, 'render_paywall_shortcode' ] );
	}

	public function render_paywall_shortcode($atts) {
		// If no ID is provided, return empty
		if (!isset($atts['id'])) {
			return '';
		}

		// Get the shortcode post
		$shortcode_post = get_post($atts['id']);

		// Verify it's a paywall-shortcode post type
		if (!$shortcode_post || $shortcode_post->post_type !== 'paywall-shortcode') {
			return '';
		}

		// Retrieve meta values
		$description = get_post_meta($shortcode_post->ID, '_coinsnap_paywall_description', true);
		$button_text = get_post_meta($shortcode_post->ID, '_coinsnap_paywall_button_text', true) ?: 'Pay Now';
		$price = get_post_meta($shortcode_post->ID, '_coinsnap_paywall_price', true) ?: '0';
		$currency = get_post_meta($shortcode_post->ID, '_coinsnap_paywall_currency', true) ?: 'SATS';
		$duration = get_post_meta($shortcode_post->ID, '_coinsnap_paywall_duration', true) ?: '24';
		$theme = get_post_meta($shortcode_post->ID, '_coinsnap_paywall_theme', true) ?: 'light';

		// Start output buffering
		ob_start();
		?>
		<div class="cscp-wrap cscp-theme-<?php echo esc_attr( $theme ); ?>"
		     data-price="<?php echo esc_attr( $price ); ?>"
		     data-currency="<?php echo esc_attr( $currency ); ?>"
		     data-duration="<?php echo esc_attr( $duration ); ?>"
		     data-post-id="<?php echo esc_attr( get_the_ID() ); ?>">

			<div class="cscp-icon" aria-hidden="true">&#8383;</div>

			<h2 class="cscp-title"><?php echo esc_html( $shortcode_post->post_title ); ?></h2>

			<?php if ( $description ) : ?>
				<p class="cscp-description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>

			<div class="cscp-price-bar">
				<span class="cscp-price-label"><?php esc_html_e( 'Price', 'coinsnap-paywall' ); ?></span>
				<span class="cscp-price-value"><?php echo esc_html( $price . ' ' . $currency ); ?></span>
			</div>

			<p class="cscp-access-info">
				<?php
				printf(
					/* translators: %s: number of hours */
					esc_html__( 'Access granted for %s hours after payment.', 'coinsnap-paywall' ),
					esc_html( $duration )
				);
				?>
			</p>

			<button class="cscp-pay-btn"><?php echo esc_html( $button_text ); ?></button>
		</div>

		<div class="cscp-locked-notice">
			<span class="cscp-locked-icon" aria-hidden="true">&#128274;</span>
			<p><?php esc_html_e( 'This content is restricted. Please complete payment to access.', 'coinsnap-paywall' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}
}

new Coinsnap_Paywall_Shortcode();
