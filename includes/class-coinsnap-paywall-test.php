<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Coinsnap_Paywall_Admin {
	public function __construct() {
		add_action( 'admin_init', [ $this, 'handle_connection_test' ] );
		add_action( 'admin_notices', [ $this, 'display_connection_notice' ] );
	}

	public function handle_connection_test() {
		// Check if connection test is requested
		if (
			isset( $_GET['coinsnap_test_connection'] ) &&
			isset( $_GET['provider'] ) &&
			current_user_can( 'manage_options' )
		) {
			$provider = sanitize_text_field( $_GET['provider'] );
			$options = get_option( 'coinsnap_paywall_options', [] );

			// Get the appropriate handler based on provider
			$handler = null;
			if ( $provider === 'coinsnap' ) {
				$handler = new Coinsnap_Paywall_CoinsnapHandler(
					$options['coinsnap_store_id'] ?? '',
					$options['coinsnap_api_key'] ?? ''
				);
			} elseif ( $provider === 'btcpay' ) {
				$handler = new Coinsnap_Paywall_BTCPayHandler(
					$options['btcpay_store_id'] ?? '',
					$options['btcpay_api_key'] ?? '',
					$options['btcpay_url'] ?? ''
				);
			}

			// Test connection if handler exists
			if ( $handler ) {
				$result = $handler->testConnection();

				// Store result in transient for displaying notice
				set_transient( 'coinsnap_connection_test_result', $result, 60 );
			}

			// Redirect back to settings page
			wp_redirect( admin_url( 'admin.php?page=coinsnap_paywall' ) );
			exit;
		}
	}

	public function display_connection_notice() {
		// Retrieve the connection test result
		$result = get_transient( 'coinsnap_connection_test_result' );

		if ( $result ) {
			// Delete the transient to prevent repeated notices
			delete_transient( 'coinsnap_connection_test_result' );

			// Determine notice class based on success
			$class = $result['success'] ? 'notice-success' : 'notice-error';

			// Display the notice
			?>
          <div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
            <p><?php echo esc_html( $result['message'] ); ?></p>
          </div>
			<?php
		}
	}
}

// Initialize the admin functionality
new Coinsnap_Paywall_Admin();
