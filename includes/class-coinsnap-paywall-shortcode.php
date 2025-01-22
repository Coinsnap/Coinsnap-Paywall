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
      <div class="paywall <?php echo esc_attr($theme); ?>"
           data-price="<?php echo esc_attr($price); ?>"
           data-currency="<?php echo esc_attr($currency); ?>"
           data-duration="<?php echo esc_attr($duration); ?>"
           data-post-id="<?php echo esc_html(get_the_ID()); ?>">
        <h2><?php echo esc_html($shortcode_post->post_title); ?></h2>
        <p><?php echo esc_html($description); ?></p>
        <div class="price-display">
			<?php echo esc_html($price) . ' ' . esc_html($currency); ?>
        </div>
        <button class="paywall-payment-button"><?php echo esc_html($button_text); ?></button>
      </div>
    <p class="restricted <?php echo esc_attr($theme); ?>">
        <?php echo esc_html_e('This content is restricted. Please complete payment to access.','coinsnap-paywall')?>
    </p>

		<?php
		return ob_get_clean();
	}
}

new Coinsnap_Paywall_Shortcode();
