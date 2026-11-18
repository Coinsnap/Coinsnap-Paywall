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
 * Tested up to:        6.9
 * License:             GPL2
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:             true
 */

defined( 'ABSPATH' ) || exit;
if(!defined('COINSNAP_SERVER_URL')){define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' );}
if(!defined('COINSNAP_API_PATH')){define( 'COINSNAP_API_PATH', '/api/v1/');}
if(!defined('COINSNAP_SERVER_PATH')){define( 'COINSNAP_SERVER_PATH', 'stores' );}
if ( ! defined( 'COINSNAP_PAYWALL_REFERRAL_CODE' ) ) {
	define( 'COINSNAP_PAYWALL_REFERRAL_CODE', 'D72896' );
}
if ( ! defined( 'COINSNAP_PAYWALL_VERSION' ) ) {
	define( 'COINSNAP_PAYWALL_VERSION', '1.3.1' );
}
if ( ! defined( 'COINSNAP_PAYWALL_PHP_VERSION' ) ) {
	define( 'COINSNAP_PAYWALL_PHP_VERSION', '8.0' );
}
if(!defined('COINSNAP_CURRENCIES')){
    define( 'COINSNAP_CURRENCIES', array("EUR","USD","SATS","BTC","CAD","JPY","GBP","CHF","RUB") );
}

register_activation_hook( __FILE__, "coinsnap_paywall_activate" );
register_uninstall_hook( __FILE__, 'coinsnap_paywall_uninstall' );
add_action( 'admin_init', 'coinsnap_paywall_php_version' );
add_action( 'plugins_loaded', 'coinsnap_paywall_maybe_upgrade' );

//  Elementor support in next version
/*
add_action( 'elementor/widgets/widgets_registered', function( $widgets_manager ) {
    require_once __DIR__ . '/includes/class-coinsnap-paywall-elementor-widget.php';
    $widgets_manager->register( new \Coinsnap_Paywall_Elementor_Widget() );
});
*/

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
	Coinsnap_Paywall_Security::install();
}

/** Plugin updates don't run the activation hook: create the invoice table on the first request after an update. */
function coinsnap_paywall_maybe_upgrade() {
	Coinsnap_Paywall_Security::maybe_install();
}

/**
 * Uninstall callback: removes only this plugin's own tables and options.
 */
function coinsnap_paywall_uninstall() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $wpdb->prefix . 'coinsnap_paywall_access' ) );
	$wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $wpdb->prefix . 'coinsnap_paywall_invoices' ) );
	foreach ( [ 'coinsnap_paywall_options', 'coinsnap_paywall_connection_result', 'coinsnap_paywall_db_version', 'coinsnap_paywall_security_upgraded_at' ] as $option ) {
		delete_option( $option );
	}
}

// Include the handler classes
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-security.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-btcpay-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-coinsnap-handler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-scripts.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-shortcode.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-post-type.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-client.php';

class CoinsnapPaywall {

    public function __construct() {

        // Public: start a payment. The browser sends only the post ID; the server takes the price from the paywall.
        add_action( 'wp_ajax_coinsnap_paywall_create_invoice', [ $this, 'create_invoice' ] );
        add_action( 'wp_ajax_nopriv_coinsnap_paywall_create_invoice', [ $this, 'create_invoice' ] );
        // Old action name, for pages still serving the previous paywall.js from a cache.
        add_action( 'wp_ajax_coinsnap_create_invoice', [ $this, 'create_invoice' ] );
        add_action( 'wp_ajax_nopriv_coinsnap_create_invoice', [ $this, 'create_invoice' ] );

        // Public: the server asks the API about this visitor's own invoices and answers paid yes/no.
        add_action( 'wp_ajax_coinsnap_paywall_check_access', [ $this, 'check_access' ] );
        add_action( 'wp_ajax_nopriv_coinsnap_paywall_check_access', [ $this, 'check_access' ] );

	// Restrict content
	add_filter( 'the_content', [ $this, 'restrict_page_content' ] );
	// Automatic excerpts (REST API, RSS feeds, embeds, archives) are built from the raw content with the
	// shortcode stripped, so the_content above never sees it. Build them from the teaser only.
	add_filter( 'get_the_excerpt', [ $this, 'restrict_excerpt' ], 9, 2 );

        // Admin only (capability and nonce checked in the handlers).
        add_action('wp_ajax_coinsnap_paywall_btcpay_apiurl_handler', [$this, 'btcpayApiUrlHandler']);
        add_action('wp_ajax_coinsnap_paywall_connection_handler', [$this, 'coinsnapConnectionHandler']);
    }
        
    public function coinsnapConnectionHandler(){
        
        $_nonce = filter_input(INPUT_POST,'apiNonce',FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if ( !wp_verify_nonce( $_nonce, 'coinsnap-ajax-nonce' ) || !current_user_can( 'manage_options' ) ) {
            wp_die('Unauthorized!', '', ['response' => 401]);
        }
        
        $_provider = $this->getPaymentProvider();
        $apiPost = absint(filter_input(INPUT_POST,'apiPost',FILTER_SANITIZE_NUMBER_INT));
        $currency = ($apiPost > 0)? (string) get_post_meta($apiPost, '_coinsnap_paywall_currency', true) : '';
        if(!in_array($currency, COINSNAP_CURRENCIES, true)){
            $currency = 'EUR';
        }
        $client = new Coinsnap_Paywall_Client();
        
        if($_provider === 'btcpay'){
            try {
                
                $storePaymentMethods = $client->getStorePaymentMethods($this->getApiUrl(), $this->getApiKey(), $this->getStoreId());

                if (($storePaymentMethods['code'] ?? 0) === 200) {
                    if($storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData(0,$currency,'bitcoin','calculation');
                    }
                    elseif($storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData(0,$currency,'lightning','calculation');
                    }
                }
            }
            catch (\Exception $e) {
                $response = [
                        'result' => false,
                        'message' => __('Coinsnap Bitcoin Paywall: API connection is not established', 'coinsnap-paywall')
                ];
                $this->sendJsonResponse($response);
            }
        }
        else {
            $checkInvoice = $client->checkPaymentData(0,$currency,'coinsnap','calculation');
        }
        
        if(isset($checkInvoice) && $checkInvoice['result']){
            $connectionData = __('Min order amount is', 'coinsnap-paywall') .' '. $checkInvoice['min_value'].' '.$currency;
        }
        else {
            $connectionData = __('No payment method is configured', 'coinsnap-paywall');
        }
        
        $_message_disconnected = ($_provider !== 'btcpay')? 
            __('Coinsnap Bitcoin Paywall: Coinsnap server is disconnected', 'coinsnap-paywall') :
            __('Coinsnap Bitcoin Paywall: BTCPay server is disconnected', 'coinsnap-paywall');
        $_message_connected = ($_provider !== 'btcpay')?
            __('Coinsnap Bitcoin Paywall: Coinsnap server is connected', 'coinsnap-paywall') : 
            __('Coinsnap Bitcoin Paywall: BTCPay server is connected', 'coinsnap-paywall');
        
        $response = ['result' => false,'message' => $_message_disconnected];

        try {
            $this_store = $client->getStore($this->getApiUrl(), $this->getApiKey(), $this->getStoreId());
            if (($this_store['code'] ?? 0) === 200) {
                $response = ['result' => true,'message' => $_message_connected.' ('.$connectionData.')'];
            }
        }
        catch (\Exception $e) {
            $response['message'] =  __('Coinsnap Bitcoin Paywall: API connection is not established', 'coinsnap-paywall');
        }

        $this->sendJsonResponse($response);
    }
    
    public function sendJsonResponse(array $response): void {
        echo wp_json_encode($response);
        exit();
    }
        
    private function getPaymentProvider() {
        $coinsnap_paywall_data = (array) get_option('coinsnap_paywall_options', []);
        return (($coinsnap_paywall_data['provider'] ?? '') === 'btcpay')? 'btcpay' : 'coinsnap';
    }

    private function getApiKey() {
        return Coinsnap_Paywall_Security::config()['api_key'];
    }
    
    private function getStoreId() {
        return Coinsnap_Paywall_Security::config()['store_id'];
    }
    
    public function getApiUrl() {
        return Coinsnap_Paywall_Security::config()['url'];
    }
    
    function btcpayApiUrlHandler(){
            $_nonce = filter_input(INPUT_POST,'apiNonce',FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            if ( !wp_verify_nonce( $_nonce, 'coinsnap-ajax-nonce' ) || !current_user_can( 'manage_options' ) ) {
                wp_die('Unauthorized!', '', ['response' => 401]);
            }

            $host = filter_var(filter_input(INPUT_POST,'host',FILTER_SANITIZE_FULL_SPECIAL_CHARS), FILTER_VALIDATE_URL);

            if ($host === false || (substr( $host, 0, 7 ) !== "http://" && substr( $host, 0, 8 ) !== "https://")) {
                wp_send_json_error("Error validating BTCPayServer URL.");
            }

            try {
                // One-time state, checked by the callback.
                $state = Coinsnap_Paywall_Security::btcpay_start_state();

                // Create the redirect url to BTCPay instance.
                $url = $this->getAuthorizeUrl(
                    $host,
                    Coinsnap_Paywall_Security::BTCPAY_PERMISSIONS,
                    'CoinsnapPaywall',
                    true,
                    true,
                    add_query_arg(['paywall-btcpay-settings-callback' => '1', 'state' => $state], home_url('/')),
                    null
                );

                // Store the host before we leave the site. A different server drops the old key and store ID (6.14).
                $options = (array) get_option('coinsnap_paywall_options', []);
                if (rtrim((string) ($options['btcpay_url'] ?? ''), '/') !== rtrim($host, '/')) {
                    $options['btcpay_api_key'] = '';
                    $options['btcpay_store_id'] = '';
                }
                $options['btcpay_url'] = rtrim(esc_url_raw($host), '/');
                Coinsnap_Paywall_Security::save_options_raw($options);

                // Return the redirect url.
                wp_send_json_success(['url' => $url]);
            }
            catch (\Throwable $e) {
                Coinsnap_Paywall_Security::log('info', 'btcpay authorize url failed');
            }
            wp_send_json_error("Error processing Ajax request.");
    }
    
    public function getAuthorizeUrl(string $baseUrl, array $permissions, ?string $applicationName, ?bool $strict, ?bool $selectiveStores, ?string $redirectToUrlAfterCreation, ?string $applicationIdentifier): string
    {
        $url = rtrim($baseUrl, '/') . '/api-keys/authorize';

        $params = [];
        $params['permissions'] = $permissions;
        $params['applicationName'] = $applicationName;
        $params['strict'] = $strict;
        $params['selectiveStores'] = $selectiveStores;
        $params['redirect'] = $redirectToUrlAfterCreation;
        $params['applicationIdentifier'] = $applicationIdentifier;

        // Take out NULL values
        $params = array_filter($params, function ($value) {
            return $value !== null;
        });

        $queryParams = [];

        foreach ($params as $param => $value) {
            if ($value === true) {
                $value = 'true';
            }
            if ($value === false) {
                $value = 'false';
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item === true) {
                        $item = 'true';
                    }
                    if ($item === false) {
                        $item = 'false';
                    }
                    $queryParams[] = $param . '=' . urlencode((string)$item);
                }
            } else {
                $queryParams[] = $param . '=' . urlencode((string)$value);
            }
        }

        $queryParams = implode("&", $queryParams);
        $url .= '?' . $queryParams;
        return $url;
    }

	/**
	 * Start a payment. Only the post ID is read from the request; price, currency, duration
	 * and the return URL come from the server. Amount or currency fields sent by the browser are ignored.
	 */
	public function create_invoice() {
		$post_id = absint( filter_input( INPUT_POST, 'postId', FILTER_SANITIZE_NUMBER_INT ) );
		$link    = Coinsnap_Paywall_Security::start_payment( $post_id );
		if ( $link === null ) {
			wp_send_json_error( [ 'message' => __( 'Payment could not be started. Please try again later.', 'coinsnap-paywall' ) ] );
		}
		wp_send_json_success( [ 'invoice_url' => $link ] );
	}

	/**
	 * Public status check: returns only paid yes/no for the visitor's own invoices.
	 * Access is granted on the server from the API's answer; the browser just reloads.
	 */
	public function check_access() {
		$post_id = absint( filter_input( INPUT_POST, 'postId', FILTER_SANITIZE_NUMBER_INT ) );
		wp_send_json_success( Coinsnap_Paywall_Security::refresh_access( $post_id ) );
	}

	public function restrict_page_content( $content ) {
		if ( strpos( (string) $content, '[paywall_payment' ) === false ) {
			return $content;
		}
		$has_access = Coinsnap_Paywall_Security::has_access( (int) get_the_ID() );
		return $this->process_native_content( $content, $has_access );
	}

	/**
	 * An automatic excerpt of a paywalled post uses only the text before the paywall,
	 * unless the visitor has access. A hand-written excerpt is left as the author wrote it.
	 */
	public function restrict_excerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		if ( ! $post || trim( (string) $excerpt ) !== '' || post_password_required( $post ) ) {
			return $excerpt;
		}
		$content = (string) $post->post_content;
		$pos     = strpos( $content, '[paywall_payment' );
		if ( $pos === false || Coinsnap_Paywall_Security::has_access( (int) $post->ID ) ) {
			return $excerpt;
		}
		$teaser = excerpt_remove_blocks( strip_shortcodes( substr( $content, 0, $pos ) ) );
		$teaser = wp_strip_all_tags( str_replace( ']]>', ']]&gt;', $teaser ) );
		$more   = (string) apply_filters( 'excerpt_more', ' [&hellip;]' );
		$teaser = wp_trim_words( $teaser, (int) apply_filters( 'excerpt_length', 55 ), $more );
		// Never return an empty excerpt: wp_trim_excerpt() would then build it from the full content.
		if ( trim( $teaser ) === '' ) {
			$teaser = trim( wp_strip_all_tags( $more ) ) !== '' ? trim( $more ) : '&hellip;';
		}
		return $teaser;
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

// BTCPay connect callback. Public URL: the proof is the one-time state created when the admin clicked "Generate API key".
// Permissions and store ID are read from BTCPay (api-keys/current), never from the POST. Every outcome ends the request.
add_action( 'template_redirect', function(){
    
    global $wp_query;
            
    // Only continue on a paywall-btcpay-settings-callback request.    
    if (!isset( $wp_query->query_vars['paywall-btcpay-settings-callback'])) {
        return;
    }

    // phpcs:disable WordPress.Security.NonceVerification -- the one-time state is the CSRF protection here
    $state  = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
    $apiKey = isset($_POST['apiKey']) && is_string($_POST['apiKey']) ? sanitize_text_field(wp_unslash($_POST['apiKey'])) : '';
    // phpcs:enable

    $ok = Coinsnap_Paywall_Security::btcpay_callback($state, $apiKey);
    set_transient(Coinsnap_Paywall_Security::NOTICE_KEY, $ok ? 'ok' : 'failed', 10 * MINUTE_IN_SECONDS);

    wp_safe_redirect(admin_url('admin.php?page=coinsnap_paywall'));
    exit();
});
