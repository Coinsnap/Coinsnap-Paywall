<?php
/*
 * Plugin Name:        Coinsnap Paywall
 * Plugin URI:         https://coinsnap.io
 * Description:        A plugin for Paywall using Coinsnap and BTCPay.
 * Version:            1.0.0
 * Author:             Coinsnap
 * Author URI:         https://coinsnap.io/
 * Text Domain:        coinsnap-paywall
 * Domain Path:         /languages
 * Tested up to:        6.7
 * License:             GPL2
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:             true
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'COINSNAP_PAYWALL_REFERRAL_CODE' ) ) {
	define( 'COINSNAP_PAYWALL_REFERRAL_CODE', 'D72896' );
}
if ( ! defined( 'COINSNAP_PAYWALL_VERSION' ) ) {
	define( 'COINSNAP_PAYWALL_VERSION', '1.0.0' );
}
if ( ! defined( 'COINSNAP_PAYWALL_PHP_VERSION' ) ) {
	define( 'COINSNAP_PAYWALL_PHP_VERSION', '8.0' );
}

register_activation_hook( __FILE__, "coinsnap_paywall_activate" );
register_uninstall_hook( __FILE__, 'coinsnap_paywall_uninstall' );
add_action( 'admin_init', 'coinsnap_paywall_php_version' );
add_action( 'init', 'start_custom_session', 1 );

function start_custom_session() {
	if ( session_status() === PHP_SESSION_NONE ) {
		session_start();
	}
}

function coinsnap_paywall_php_notice() {
	$versionMessage = sprintf(
	/* translators: 1: PHP version, 2: Required PHP version */
		__( 'Cannot activate coinsnap_paywall: Your PHP version is %1$s but Coinsnap Payment plugin requires version %2$s.', 'coinsnap-paywall' ),
		PHP_VERSION,
		'8.0'
	); ?>
  <div class="notice notice-error">
  <p><?php echo esc_html( $versionMessage ); ?></p>
  </div><?php
}

function coinsnap_paywall_php_version() {
	// Ensure the PHP version matches the plugin's minimum requirement (8.0)
	if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
		add_action( 'admin_notices', 'coinsnap_paywall_php_notice' );
		deactivate_plugins( plugin_basename( __FILE__ ) );
	}
}

function coinsnap_paywall_activate() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'coinsnap_paywall_access';

	$wpdb->query( $wpdb->prepare( "CREATE TABLE IF NOT EXISTS %i (
        id INT AUTO_INCREMENT PRIMARY KEY,
        post_id INT NOT NULL,
        session_id INT NOT NULL,
        access_expires DATETIME NOT NULL)", $table_name ) );
}

/**
 * Uninstall callback to clean up the database.
 */
function coinsnap_paywall_uninstall() {
	global $wpdb;

	// Get the table name
	$table_name = $wpdb->prefix . 'coinsnap_paywall_access';

	// Drop the table
	$wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $table_name ) );
}

// Include the handler classes
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-btcpay-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-coinsnap-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-scripts.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-shortcode.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-post-type.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-test.php';

class CoinsnapPaywall {

	public function __construct() {

		// Register AJAX handlers for payment initiation
		add_action( 'wp_ajax_coinsnap_create_invoice', [ $this, 'create_invoice' ] );
		add_action( 'wp_ajax_nopriv_coinsnap_create_invoice', [ $this, 'create_invoice' ] );

		// Restrict content
		//add_action( 'init', [$this, 'start_custom_session'], 1 );
		add_filter( 'the_content', [ $this, 'restrict_page_content' ] );

		add_action( 'wp_ajax_check_invoice_status', [ $this, 'check_invoice_status' ] );
		add_action( 'wp_ajax_nopriv_check_invoice_status', [ $this, 'check_invoice_status' ] );
		add_action( 'wp_ajax_coinsnap_paywall_grant_access', [ $this, 'coinsnap_paywall_grant_access' ] );
		add_action( 'wp_ajax_nopriv_coinsnap_paywall_grant_access', [ $this, 'coinsnap_paywall_grant_access' ] );
	}

	public function check_invoice_status() {
		if ( null === filter_input( INPUT_POST, 'invoice_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ) {
			wp_send_json_error( 'Invoice ID is required' );
		}

		$invoice_id = sanitize_text_field( filter_input( INPUT_POST, 'invoice_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		$provider   = get_option( 'coinsnap_paywall_options' )['provider'];

		$handler = $this->get_provider_handler( $provider );

		if ( ! $handler ) {
			wp_send_json_error( 'Invalid provider' );
		}

		$invoice = $handler->getInvoiceStatus( $invoice_id );

		if ( isset( $invoice['status'] ) ) {
			wp_send_json_success( [
				'status'      => $invoice['status'],
				'checkoutUrl' => $invoice['checkoutLink'] ?? null,
			] );
		} else {
			wp_send_json_error( [ 'status' => 'Pending', 'message' => 'Invoice is not settled' ] );
		}
	}

	public function create_invoice() {
		if ( empty( filter_input( INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT ) ) || empty( filter_input( INPUT_POST, 'currency', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ) ) {
			wp_send_json_error( [ 'message' => 'Invalid request parameters.' ] );
		}

		$provider    = get_option( 'coinsnap_paywall_options' )['provider'];
		$price       = sanitize_text_field( filter_input( INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT ) );
		$currency    = sanitize_text_field( filter_input( INPUT_POST, 'currency', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		$redirectUrl = sanitize_text_field( filter_input( INPUT_POST, 'currentPage', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );

		$handler = $this->get_provider_handler( $provider );

		if ( ! $handler ) {
			wp_send_json_error( [ 'message' => 'Invalid provider' ] );
		}

		$invoice = $handler->createInvoice( $price, $currency, $redirectUrl );

		if ( $invoice && isset( $invoice['data']['checkoutLink'] ) ) {


			$ids = [
				'invoice_id' => $invoice['data']['id'] ?? null,
				'post_id'    => filter_input( INPUT_POST, 'postId', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ?? null,
			];

			setcookie( 'coinsnap_initiated_' . ( filter_input( INPUT_POST, 'postId', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ?? '' ), wp_json_encode( $ids ), time() + 900, '/' );

			wp_send_json_success( [ 'invoice_url' => $invoice['data']['checkoutLink'] ] );
		} else {
			// Debug Invoice creation
			wp_send_json_error( [ 'message' => 'Failed to create invoice' . $invoice["body"] ] );
		}
	}

	/**
	 * Get the appropriate handler based on the provider.
	 *
	 * @param string $provider
	 *
	 * @return object|null
	 */
	private function get_provider_handler( $provider ) {
		switch ( $provider ) {
			case 'btcpay':
				return new Coinsnap_Paywall_BTCPayHandler(
					get_option( 'coinsnap_paywall_options' )['btcpay_store_id'],
					get_option( 'coinsnap_paywall_options' )['btcpay_api_key'],
					get_option( 'coinsnap_paywall_options' )['btcpay_url']
				);

			case 'coinsnap':
				return new Coinsnap_Paywall_CoinsnapHandler(
					get_option( 'coinsnap_paywall_options' )['coinsnap_store_id'],
					get_option( 'coinsnap_paywall_options' )['coinsnap_api_key']
				);

			default:
				return null;
		}
	}

	public function coinsnap_paywall_has_access( $post_id, $session_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'coinsnap_paywall_access';

		$access = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM %i WHERE post_id = %d AND session_id = %s AND access_expires > NOW()",
			$table_name, $post_id, $session_id )
		);

		return $access !== null;
	}

	public function coinsnap_paywall_grant_access() {
		// Get and use the session ID
		$session_id = session_id();

		// Debug incoming data
		//error_log( print_r( $_POST, true ) );

		if ( empty( filter_input( INPUT_POST, 'post_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ) || empty( filter_input( INPUT_POST, 'duration', FILTER_VALIDATE_INT ) ) ) {
			wp_send_json_error( 'Missing required parameters' );
		}

		$post_id  = sanitize_text_field( filter_input( INPUT_POST, 'post_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		$duration = intval( filter_input( INPUT_POST, 'duration', FILTER_VALIDATE_INT ) );

		// Debug session_id
		//error_log( 'Session ID: ' . $session_id );

		if ( ! $session_id ) {
			wp_send_json_error( 'Session not initialized' );
		}

		$access_expires = gmdate( 'Y-m-d H:i:s', time() + ( $duration * 3600 ) );

		global $wpdb;
		$table_name = $wpdb->prefix . 'coinsnap_paywall_access';

		$result = $wpdb->insert( $table_name, [
			'post_id'        => $post_id,
			'session_id'     => $session_id,
			'access_expires' => $access_expires,
		] );

		// Debug query execution
		if ( $result === false ) {
			//  Debug Database Error
			//error_log( 'Database Error: ' . $wpdb->last_error );
			wp_send_json_error( 'Database insertion failed' );
		}

		wp_send_json_success();
	}

	public function restrict_page_content( $content ) {
		// Start the session if it hasn't been started already

		// Ensure the session ID is set
		if ( empty( session_id() ) ) {
			// Optionally, generate a unique session ID or trigger an error if needed
			session_regenerate_id();
		}

		$session_id = session_id();
		$post_id    = get_the_ID();

		// Check if the condition is met (user has access)
		$has_access = $this->coinsnap_paywall_has_access( $post_id, $session_id );

		return $this->process_native_content( $content, $has_access );
	}

	private function process_native_content( $content, $has_access ) {
		if ( strpos( $content, '[paywall_payment' ) !== false ) {
			if ( $has_access ) {
				$content = preg_replace( '/\[paywall_payment[^\]]*\]/', '', $content );

				return $content;
			} else {
				// Restrict content and show paywall up to the shortcode
				$parts           = explode( '[paywall_payment', $content );
				$shortcode_parts = explode( ']', $parts[1], 2 );
				$shortcode       = '[paywall_payment' . $shortcode_parts[0] . ']';

				return $parts[0] . $shortcode;
			}
		}

		return $content; // Return as-is if no shortcode
	}
}

new CoinsnapPaywall();
