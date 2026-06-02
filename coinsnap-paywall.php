<?php
/*
 * Plugin Name:        Coinsnap Bitcoin Paywall
 * Plugin URI:         https://coinsnap.io
 * Description:        A plugin for Paywall using Bitcoin and Lightning payments via Coinsnap and BTCPay payment gateways.
 * Version:            1.3.1
 * Author:             Coinsnap
 * Author URI:         https://coinsnap.io/
 * Text Domain:        coinsnap-paywall
 * Domain Path:         /languages
 * Tested up to:        7.0
 * License:             GPL2
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:             true
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'COINSNAP_PAYWALL_REFERRAL_CODE' ) )  { define( 'COINSNAP_PAYWALL_REFERRAL_CODE', 'D72896' ); }
if ( ! defined( 'COINSNAP_PAYWALL_VERSION' ) )         { define( 'COINSNAP_PAYWALL_VERSION', '1.3.1' ); }
if ( ! defined( 'COINSNAP_PAYWALL_PHP_VERSION' ) )     { define( 'COINSNAP_PAYWALL_PHP_VERSION', '8.0' ); }
if ( ! defined( 'COINSNAP_PAYWALL_PLUGIN_FILE' ) )     { define( 'COINSNAP_PAYWALL_PLUGIN_FILE', __FILE__ ); }
if ( ! defined( 'COINSNAP_PAYWALL_PLUGIN_DIR' ) )      { define( 'COINSNAP_PAYWALL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) ); }
if ( ! defined( 'COINSNAP_PAYWALL_PLUGIN_URL' ) )      { define( 'COINSNAP_PAYWALL_PLUGIN_URL', plugin_dir_url( __FILE__ ) ); }

// Shared constants (also defined by coinsnap-core, guards prevent double-define).
if ( ! defined( 'COINSNAP_SERVER_URL' ) )  { define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' ); }
if ( ! defined( 'COINSNAP_API_PATH' ) )    { define( 'COINSNAP_API_PATH', '/api/v1/' ); }
if ( ! defined( 'COINSNAP_SERVER_PATH' ) ) { define( 'COINSNAP_SERVER_PATH', 'stores' ); }
if ( ! defined( 'COINSNAP_CURRENCIES' ) )  { define( 'COINSNAP_CURRENCIES', array( 'EUR', 'USD', 'SATS', 'BTC', 'CAD', 'JPY', 'GBP', 'CHF', 'RUB' ) ); }

// Load coinsnap-core shared library (CoinsnapCore\ namespace + autoloader).
require_once COINSNAP_PAYWALL_PLUGIN_DIR . 'vendor/coinsnap-core.php';

// PSR-4 autoloader for CoinsnapPaywall\ namespace.
spl_autoload_register(
	function ( $class ) {
		$prefix = 'CoinsnapPaywall\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = COINSNAP_PAYWALL_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Returns the shared PluginInstance for this plugin.
 *
 * @return \CoinsnapCore\PluginInstance
 */
function coinsnap_paywall_instance(): \CoinsnapCore\PluginInstance {
	static $instance;
	if ( ! $instance ) {
		$instance = new \CoinsnapCore\PluginInstance( array(
			'plugin_name'   => 'Coinsnap Bitcoin Paywall',
			'option_key'    => 'coinsnap_paywall_options',
			'referral_code' => COINSNAP_PAYWALL_REFERRAL_CODE,
			'text_domain'   => 'coinsnap-paywall',
			'plugin_url'    => COINSNAP_PAYWALL_PLUGIN_URL,
			'plugin_dir'    => COINSNAP_PAYWALL_PLUGIN_DIR,
			'menu_slug'     => 'coinsnap_paywall',
		) );
	}
	return $instance;
}

/**
 * One-time migration: rename legacy option keys to match CoinsnapCore expectations.
 *   provider   → payment_provider
 *   btcpay_url → btcpay_host
 */
function coinsnap_paywall_migrate_settings(): void {
	$opts    = get_option( 'coinsnap_paywall_options', array() );
	$changed = false;

	if ( isset( $opts['provider'] ) && ! isset( $opts['payment_provider'] ) ) {
		$opts['payment_provider'] = $opts['provider'];
		unset( $opts['provider'] );
		$changed = true;
	}
	if ( isset( $opts['btcpay_url'] ) && ! isset( $opts['btcpay_host'] ) ) {
		$opts['btcpay_host'] = $opts['btcpay_url'];
		unset( $opts['btcpay_url'] );
		$changed = true;
	}
	if ( $changed ) {
		update_option( 'coinsnap_paywall_options', $opts );
	}
}
add_action( 'init', 'coinsnap_paywall_migrate_settings', 5 );

register_activation_hook( __FILE__, "coinsnap_paywall_activate" );
register_uninstall_hook( __FILE__, 'coinsnap_paywall_uninstall' );
add_action( 'admin_init', 'coinsnap_paywall_php_version' );
add_action( 'admin_init', 'coinsnap_paywall_migrate_db_schema' );

//  Elementor support in next version
/*
add_action( 'elementor/widgets/widgets_registered', function( $widgets_manager ) {
    require_once __DIR__ . '/includes/class-coinsnap-paywall-elementor-widget.php';
    $widgets_manager->register( new \Coinsnap_Paywall_Elementor_Widget() );
});
*/

/**
 * Migrate existing installations: change session_id column from INT to VARCHAR(255).
 * Runs once on admin_init and becomes a no-op after the schema is correct.
 */
function coinsnap_paywall_migrate_db_schema() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'coinsnap_paywall_access';

	// Skip if the table doesn't exist yet.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return;
	}

	// Check the current type of the session_id column.
	$col = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		"SELECT DATA_TYPE FROM information_schema.COLUMNS
		 WHERE TABLE_SCHEMA = DATABASE()
		   AND TABLE_NAME  = %s
		   AND COLUMN_NAME = 'session_id'",
		$table_name
	) );

	if ( $col && strtolower( $col ) !== 'varchar' ) {
		// Clear existing (invalid) INT-stored records and fix the column.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
				'ALTER TABLE %i MODIFY COLUMN session_id VARCHAR(255) NOT NULL', // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
				$table_name
			)
		);
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

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query(
		$wpdb->prepare( "CREATE TABLE IF NOT EXISTS %i (
        id INT AUTO_INCREMENT PRIMARY KEY,
        post_id INT NOT NULL,
        session_id VARCHAR(255) NOT NULL,
        access_expires DATETIME NOT NULL)", $table_name ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
}

/**
 * Uninstall callback to clean up the database.
 */
function coinsnap_paywall_uninstall() {
	global $wpdb;

	// Get the table name
	$table_name = $wpdb->prefix . 'coinsnap_paywall_access';

	// Drop the table
	$wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

// Load includes (legacy global-namespace classes — autoloader handles CoinsnapPaywall\ namespace).
require_once COINSNAP_PAYWALL_PLUGIN_DIR . 'includes/class-coinsnap-paywall-btcpay-handler.php';
require_once COINSNAP_PAYWALL_PLUGIN_DIR . 'includes/class-coinsnap-paywall-coinsnap-handler.php';
require_once COINSNAP_PAYWALL_PLUGIN_DIR . 'includes/class-coinsnap-paywall-scripts.php';
require_once COINSNAP_PAYWALL_PLUGIN_DIR . 'includes/class-coinsnap-paywall-shortcode.php';
require_once COINSNAP_PAYWALL_PLUGIN_DIR . 'includes/class-coinsnap-paywall-settings.php';
require_once COINSNAP_PAYWALL_PLUGIN_DIR . 'includes/class-coinsnap-paywall-post-type.php';
require_once COINSNAP_PAYWALL_PLUGIN_DIR . 'includes/class-coinsnap-paywall-client.php';

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
                
        add_action('wp_ajax_coinsnap_paywall_btcpay_apiurl_handler', [$this, 'btcpayApiUrlHandler']);
        add_action('wp_ajax_coinsnap_paywall_connection_handler', [$this, 'coinsnapConnectionHandler']);
    }
        
    public function coinsnapConnectionHandler(){

        $_nonce = filter_input(INPUT_POST,'apiNonce',FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if ( !wp_verify_nonce( $_nonce, 'coinsnap-ajax-nonce' ) ) {
            wp_die('Unauthorized!', '', ['response' => 401]);
        }

        $response = [
            'result'  => false,
            'message' => __('Empty gateway URL or API Key', 'coinsnap-paywall'),
        ];

        $_provider = $this->getPaymentProvider();
        $currency  = ('' !== filter_input(INPUT_POST,'apiPost',FILTER_SANITIZE_FULL_SPECIAL_CHARS))
            ? get_post_meta(filter_input(INPUT_POST,'apiPost',FILTER_SANITIZE_FULL_SPECIAL_CHARS), '_coinsnap_paywall_currency', true)
            : 'EUR';

        // Use legacy client for payment-method / min-amount checks (not in CoinsnapCore).
        $client = new Coinsnap_Paywall_Client();
        $connectionData = __('No payment method is configured', 'coinsnap-paywall');

        if ( $_provider === 'btcpay' ) {
            try {
                $storePaymentMethods = $client->getStorePaymentMethods($this->getApiUrl(), $this->getApiKey(), $this->getStoreId());
                if ( ($storePaymentMethods['code'] ?? 0) === 200 ) {
                    if ( $storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning'] ) {
                        $checkInvoice = $client->checkPaymentData(0, $currency, 'bitcoin', 'calculation');
                    } elseif ( $storePaymentMethods['result']['lightning'] ) {
                        $checkInvoice = $client->checkPaymentData(0, $currency, 'lightning', 'calculation');
                    }
                }
            } catch (\Exception $e) {
                $this->sendJsonResponse([
                    'result'  => false,
                    'message' => __('Coinsnap Bitcoin Paywall: API connection is not established', 'coinsnap-paywall'),
                ]);
            }
        } else {
            $checkInvoice = $client->checkPaymentData(0, $currency, 'coinsnap', 'calculation');
        }

        if ( isset($checkInvoice) && $checkInvoice['result'] ) {
            $connectionData = __('Min order amount is', 'coinsnap-paywall') . ' ' . $checkInvoice['min_value'] . ' ' . $currency;
        }

        $_message_disconnected = ($_provider !== 'btcpay')
            ? __('Coinsnap Bitcoin Paywall: Coinsnap server is disconnected', 'coinsnap-paywall')
            : __('Coinsnap Bitcoin Paywall: BTCPay server is disconnected', 'coinsnap-paywall');
        $_message_connected = ($_provider !== 'btcpay')
            ? __('Coinsnap Bitcoin Paywall: Coinsnap server is connected', 'coinsnap-paywall')
            : __('Coinsnap Bitcoin Paywall: BTCPay server is connected', 'coinsnap-paywall');

        // Use CoinsnapCore provider for the actual connection/store check.
        $response = ['result' => false, 'message' => $_message_disconnected];
        try {
            $core_provider = $this->get_provider_handler( $_provider );
            $this_store    = $core_provider ? $core_provider->get_store() : array( 'error' => true );

            if ( isset($this_store['error']) || ($this_store['code'] ?? 0) !== 200 ) {
                $this->sendJsonResponse($response);
            } else {
                $response = ['result' => true, 'message' => $_message_connected . ' (' . $connectionData . ')'];
                $this->sendJsonResponse($response);
            }
        } catch (\Exception $e) {
            $response['message'] = __('Coinsnap Bitcoin Paywall: API connection is not established', 'coinsnap-paywall');
        }

        $this->sendJsonResponse($response);
    }
    
    public function sendJsonResponse(array $response): void {
        wp_send_json( $response );
    }
        
    private function getPaymentProvider() {
        $coinsnap_paywall_data = get_option('coinsnap_paywall_options', []);
        return (( $coinsnap_paywall_data['payment_provider'] ?? '' ) === 'btcpay')? 'btcpay' : 'coinsnap';
    }

    private function getApiKey() {
        $coinsnap_paywall_data = get_option('coinsnap_paywall_options', []);
        return ($this->getPaymentProvider() === 'btcpay')? $coinsnap_paywall_data['btcpay_api_key']  : $coinsnap_paywall_data['coinsnap_api_key'];
    }
    
    private function getStoreId() {
	$coinsnap_paywall_data = get_option('coinsnap_paywall_options', []);
        return ($this->getPaymentProvider() === 'btcpay')? $coinsnap_paywall_data['btcpay_store_id'] : $coinsnap_paywall_data['coinsnap_store_id'];
    }
    
    public function getApiUrl() {
        $coinsnap_paywall_data = get_option('coinsnap_paywall_options', []);
        return ($this->getPaymentProvider() === 'btcpay')? ($coinsnap_paywall_data['btcpay_host'] ?? '') : COINSNAP_SERVER_URL;
    }
    
    function btcpayApiUrlHandler() {
        $_nonce = filter_input( INPUT_POST, 'apiNonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        if ( ! wp_verify_nonce( $_nonce, 'coinsnap-ajax-nonce' ) ) {
            wp_die( 'Unauthorized!', '', array( 'response' => 401 ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Error processing Ajax request.' );
        }

        $host = filter_var( filter_input( INPUT_POST, 'host', FILTER_SANITIZE_FULL_SPECIAL_CHARS ), FILTER_VALIDATE_URL );

        if ( false === $host || ( substr( $host, 0, 7 ) !== 'http://' && substr( $host, 0, 8 ) !== 'https://' ) ) {
            wp_send_json_error( 'Error validating BTCPayServer URL.' );
        }

        $permissions = array_merge(
            \CoinsnapCore\Auth\BTCPayAuthorizer::REQUIRED_PERMISSIONS,
            \CoinsnapCore\Auth\BTCPayAuthorizer::OPTIONAL_PERMISSIONS
        );

        try {
            // Determine redirect URL (popup mode when not on HTTPS and no ngrok tunnel).
            $settings   = get_option( 'coinsnap_paywall_options', array() );
            $popup_mode = false;
            if ( ! empty( $settings['ngrok_url'] ) ) {
                $redirect_url = rtrim( $settings['ngrok_url'], '/' ) . '/?paywall-btcpay-settings-callback';
            } elseif ( is_ssl() ) {
                $redirect_url = home_url( '/?paywall-btcpay-settings-callback' );
            } else {
                $redirect_url = home_url( '/?paywall-btcpay-settings-callback&popup=1' );
                $popup_mode   = true;
            }

            $url = \CoinsnapCore\Auth\BTCPayAuthorizer::get_authorize_url(
                $host,
                $permissions,
                'CoinsnapPaywall',
                true,
                true,
                $redirect_url,
                null
            );

            // Store the host before we leave the site.
            coinsnap_settings_update( 'coinsnap_paywall_options', array( 'btcpay_host' => $host ) );

            wp_send_json_success( array( 'url' => $url, 'popup' => $popup_mode ) );
        } catch ( \Throwable $e ) {
            wp_send_json_error( 'Error processing Ajax request.' );
        }
    }

	public function check_invoice_status() {
		if ( null === filter_input( INPUT_POST, 'invoice_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ) {
			wp_send_json_error( 'Invoice ID is required' );
		}

		$invoice_id = sanitize_text_field( filter_input( INPUT_POST, 'invoice_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		$provider   = $this->getPaymentProvider();

		$handler = $this->get_provider_handler( $provider );

		if ( ! $handler ) {
			wp_send_json_error( 'Invalid provider' );
		}

		$result = $handler->check_invoice_status( $invoice_id );

		if ( isset( $result['status'] ) ) {
			wp_send_json_success( array(
				'status'      => $result['status'],
				'checkoutUrl' => $result['metadata']['checkoutLink'] ?? null,
			) );
		} else {
			wp_send_json_error( array( 'status' => 'Pending', 'message' => 'Invoice is not settled' ) );
		}
	}

	public function create_invoice() {
		if ( empty( filter_input( INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT ) ) || empty( filter_input( INPUT_POST, 'currency', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ) ) {
			wp_send_json_error( [ 'message' => 'Invalid request parameters.' ] );
		}

		$provider    = get_option( 'coinsnap_paywall_options' )['payment_provider'] ?? 'coinsnap';
		$price       = sanitize_text_field( filter_input( INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT ) );
		$currency    = sanitize_text_field( filter_input( INPUT_POST, 'currency', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		$redirectUrl = sanitize_text_field( filter_input( INPUT_POST, 'currentPage', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );

		$handler = $this->get_invoice_handler( $provider );

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
	 * Returns a CoinsnapCore payment provider for status checks and store info.
	 *
	 * @param string $provider 'btcpay' or 'coinsnap'.
	 * @return \CoinsnapCore\Interfaces\PaymentProviderInterface|null
	 */
	private function get_provider_handler( $provider ) {
		$instance = coinsnap_paywall_instance();
		switch ( $provider ) {
			case 'btcpay':
				return new \CoinsnapCore\Providers\BTCPayProvider( $instance );
			case 'coinsnap':
				return new \CoinsnapCore\Providers\CoinsnapProvider( $instance );
			default:
				return null;
		}
	}

	/**
	 * Returns the legacy invoice handler for creating invoices.
	 * CoinsnapCore's create_invoice signature is incompatible with the Paywall's
	 * redirect-URL-based flow, so the legacy handlers are kept for creation only.
	 *
	 * @param string $provider 'btcpay' or 'coinsnap'.
	 * @return Coinsnap_Paywall_BTCPayHandler|Coinsnap_Paywall_CoinsnapHandler|null
	 */
	private function get_invoice_handler( $provider ) {
		$opts = get_option( 'coinsnap_paywall_options', array() );
		switch ( $provider ) {
			case 'btcpay':
				return new Coinsnap_Paywall_BTCPayHandler(
					$opts['btcpay_store_id'] ?? '',
					$opts['btcpay_api_key']  ?? '',
					$opts['btcpay_host']     ?? ''
				);
			case 'coinsnap':
				return new Coinsnap_Paywall_CoinsnapHandler(
					$opts['coinsnap_store_id'] ?? '',
					$opts['coinsnap_api_key']  ?? ''
				);
			default:
				return null;
		}
	}

	public function coinsnap_paywall_has_access( $post_id ) {
		$cookie_name  = 'coinsnap_access_' . intval( $post_id );
		$access_token = isset( $_COOKIE[ $cookie_name ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) )
			: '';

		if ( empty( $access_token ) ) {
			return false;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'coinsnap_paywall_access';

		$access = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT id FROM %i WHERE post_id = %d AND session_id = %s AND access_expires > NOW()",
			$table_name, $post_id, $access_token )
		);

		return $access !== null;
	}

	public function coinsnap_paywall_grant_access() {
		if ( empty( filter_input( INPUT_POST, 'post_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ) || empty( filter_input( INPUT_POST, 'duration', FILTER_VALIDATE_INT ) ) ) {
			wp_send_json_error( 'Missing required parameters' );
		}

		$post_id  = intval( filter_input( INPUT_POST, 'post_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
		$duration = intval( filter_input( INPUT_POST, 'duration', FILTER_VALIDATE_INT ) );

		// Generate a unique, secure access token (no PHP sessions needed).
		$access_token   = wp_generate_password( 32, false );
		$access_expires = gmdate( 'Y-m-d H:i:s', time() + ( $duration * 3600 ) );

		global $wpdb;
		$table_name = $wpdb->prefix . 'coinsnap_paywall_access';

		$result = $wpdb->insert( $table_name, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			'post_id'        => $post_id,
			'session_id'     => $access_token,
			'access_expires' => $access_expires,
		] );

		if ( $result === false ) {
			wp_send_json_error( 'Database insertion failed' );
		}

		// Set a persistent cookie with the access token; expires same time as DB record.
		setcookie(
			'coinsnap_access_' . $post_id,
			$access_token,
			[
				'expires'  => time() + ( $duration * 3600 ),
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);

		wp_send_json_success();
	}

	public function restrict_page_content( $content ) {
		$post_id    = get_the_ID();
		$has_access = $this->coinsnap_paywall_has_access( $post_id );

		return $this->process_native_content( $content, $has_access );
	}

	private function process_native_content( $content, $has_access ) {
		if ( strpos( $content, '[paywall_payment' ) !== false ) {
			if ( $has_access ) {
				// Extract the shortcode ID and fetch the protected content stored in the CPT.
				$protected_html = '';
				if ( preg_match( '/\[paywall_payment[^\]]*\bid=[\'"](\d+)[\'"][^\]]*\]/', $content, $m ) ) {
					$stored = get_post_meta( intval( $m[1] ), '_coinsnap_paywall_protected_content', true );
					if ( $stored ) {
						$protected_html = wp_kses_post( $stored );
					}
				}
				// Replace the shortcode tag with the protected content (empty if none set).
				$content = preg_replace_callback(
					'/\[paywall_payment[^\]]*\]/',
					function () use ( $protected_html ) {
						return $protected_html;
					},
					$content
				);
				return $content;
			} else {
				// Restrict content and show paywall up to the shortcode.
				$parts           = explode( '[paywall_payment', $content );
				$shortcode_parts = explode( ']', $parts[1], 2 );
				$shortcode       = '[paywall_payment' . $shortcode_parts[0] . ']';

				return $parts[0] . $shortcode;
			}
		}

		return $content;
	}
}

new CoinsnapPaywall();

add_action('init', function() {
    // Setting up and handling custom endpoint for api key redirect from BTCPay Server.
    add_rewrite_endpoint('paywall-btcpay-settings-callback', EP_ROOT);
});

// To be able to use the endpoint without appended url segments we need to do this.
add_filter('request', function($vars) {
    if (isset($vars['paywall-btcpay-settings-callback'])) {
        $vars['paywall-btcpay-settings-callback'] = true;
    }
    return $vars;
});

if(!function_exists('coinsnap_settings_update')){
    function coinsnap_settings_update($option,$data){
        
        $form_data = get_option($option, []);
        
        foreach($data as $key => $value){
            $form_data[$key] = $value;
        }
        
        update_option($option,$form_data);
    }
}

// Handle BTCPay callback: save credentials and (in popup mode) relay via postMessage.
add_action( 'template_redirect', function () {
    global $wp_query;

    if ( ! isset( $wp_query->query_vars['paywall-btcpay-settings-callback'] ) ) {
        return;
    }

    $settings_url = admin_url( 'admin.php?page=coinsnap_paywall' );
    $form_data    = get_option( 'coinsnap_paywall_options', array() );
    $btcpay_host  = is_array( $form_data ) ? ( $form_data['btcpay_host'] ?? '' ) : '';

    $btcpay_api_key = filter_input( INPUT_POST, 'apiKey', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    if ( empty( $btcpay_api_key ) ) {
        wp_safe_redirect( $settings_url );
        exit();
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- BTCPay posts back without a WP nonce.
    $btcpay_perms = isset( $_POST['permissions'] ) && is_array( $_POST['permissions'] )
        ? array_map( 'sanitize_text_field', wp_unslash( $_POST['permissions'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
        : array();

    if ( empty( $btcpay_perms ) ) {
        wp_safe_redirect( $settings_url );
        exit();
    }

    $required = \CoinsnapCore\Auth\BTCPayAuthorizer::REQUIRED_PERMISSIONS;
    $optional = \CoinsnapCore\Auth\BTCPayAuthorizer::OPTIONAL_PERMISSIONS;

    $base_perms = array_reduce(
        $btcpay_perms,
        static function ( array $carry, string $p ) {
            return array_merge( $carry, array( explode( ':', $p )[0] ) );
        },
        array()
    );
    $base_perms   = array_diff( $base_perms, $optional );
    $has_required = empty(
        array_merge(
            array_diff( $required, $base_perms ),
            array_diff( $base_perms, $required )
        )
    );

    $has_single_store = true;
    $store_id         = null;
    foreach ( $btcpay_perms as $perm ) {
        $parts = explode( ':', $perm );
        if ( 2 !== count( $parts ) ) {
            wp_safe_redirect( $settings_url );
            exit();
        }
        $received = $parts[1] ?? null;
        if ( null === $received ) {
            $has_single_store = false;
        }
        if ( $store_id === $received ) {
            continue;
        }
        if ( null === $store_id ) {
            $store_id = $received;
            continue;
        }
        $has_single_store = false;
    }

    $clean_api_key      = sanitize_text_field( $btcpay_api_key );
    $store_id_from_perm = '';

    if ( $has_single_store && $has_required ) {
        $store_id_from_perm = explode( ':', $btcpay_perms[0] )[1] ?? '';

        if ( empty( $store_id_from_perm ) && ! empty( $btcpay_host ) ) {
            $response = wp_remote_get(
                rtrim( $btcpay_host, '/' ) . '/api/v1/stores',
                array(
                    'headers' => array(
                        'Authorization' => 'token ' . $clean_api_key,
                        'Content-Type'  => 'application/json',
                    ),
                    'timeout' => 20,
                )
            );
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 300 ) {
                $stores = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( is_array( $stores ) && ! empty( $stores[0]['id'] ) ) {
                    $store_id_from_perm = $stores[0]['id'];
                }
            }
        }

        \CoinsnapCore\Auth\BTCPayAuthorizer::update_settings(
            'coinsnap_paywall_options',
            array(
                'btcpay_api_key'   => $clean_api_key,
                'btcpay_store_id'  => $store_id_from_perm,
                'payment_provider' => 'btcpay',
            )
        );
    }

    // Popup mode: relay credentials back to the opener via postMessage then close.
    $popup = filter_input( INPUT_GET, 'popup', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    if ( '1' === $popup ) {
        $js_data     = wp_json_encode( array(
            'type'    => 'coinsnap_paywall_btcpay_auth',
            'apiKey'  => $clean_api_key,
            'storeId' => $store_id_from_perm,
        ) );
        $js_origin   = wp_json_encode( home_url() );
        $js_fallback = wp_json_encode( $settings_url );

        header( 'Content-Type: text/html; charset=utf-8' );
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Authorizing...</title>';
        echo '<script>(function(){';
        echo 'var d=' . $js_data . ';'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo 'if(window.opener&&!window.opener.closed){';
        echo 'window.opener.postMessage(d,' . $js_origin . ');'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo 'window.close();';
        echo '}else{window.location.href=' . $js_fallback . ';}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '})();</script></head><body></body></html>';
        exit();
    }

    wp_safe_redirect( $settings_url );
    exit();
} );
