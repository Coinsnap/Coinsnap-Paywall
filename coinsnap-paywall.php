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
add_action( 'init', 'coinsnap_paywall_custom_session', 1 );

//  Elementor support in next version
/*
add_action( 'elementor/widgets/widgets_registered', function( $widgets_manager ) {
    require_once __DIR__ . '/includes/class-coinsnap-paywall-elementor-widget.php';
    $widgets_manager->register( new \Coinsnap_Paywall_Elementor_Widget() );
});
*/

function coinsnap_paywall_custom_session() {
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
require_once plugin_dir_path( __FILE__ ) . 'includes/class-coinsnap-paywall-client.php';

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
            'result' => false,
            'message' => __('Empty gateway URL or API Key', 'coinsnap-paywall')
        ];
        
        
        $_provider = $this->getPaymentProvider();
        $currency = ('' !== filter_input(INPUT_POST,'apiPost',FILTER_SANITIZE_FULL_SPECIAL_CHARS))? get_post_meta(filter_input(INPUT_POST,'apiPost',FILTER_SANITIZE_FULL_SPECIAL_CHARS), '_coinsnap_paywall_currency', true) : 'EUR';
        $client = new Coinsnap_Paywall_Client();
        
        if($_provider === 'btcpay'){
            try {
                
                $storePaymentMethods = $client->getStorePaymentMethods($this->getApiUrl(), $this->getApiKey(), $this->getStoreId());

                if ($storePaymentMethods['code'] === 200) {
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
                        'message' => __('Coinsnap Bitcoin Voting: API connection is not established', 'coinsnap-paywall')
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
            __('Coinsnap Bitcoin Voting: Coinsnap server is disconnected', 'coinsnap-paywall') :
            __('Coinsnap Bitcoin Voting: BTCPay server is disconnected', 'coinsnap-paywall');
        $_message_connected = ($_provider !== 'btcpay')?
            __('Coinsnap Bitcoin Voting: Coinsnap server is connected', 'coinsnap-paywall') : 
            __('Coinsnap Bitcoin Voting: BTCPay server is connected', 'coinsnap-paywall');
        
        if( wp_verify_nonce($_nonce,'coinsnap-ajax-nonce') ){
            $response = ['result' => false,'message' => $_message_disconnected];

            try {
                $this_store = $client->getStore($this->getApiUrl(), $this->getApiKey(), $this->getStoreId());
                
                if ($this_store['code'] !== 200) {
                    $this->sendJsonResponse($response);
                }
                
                else {
                    $response = ['result' => true,'message' => $_message_connected.' ('.$connectionData.')'];
                    $this->sendJsonResponse($response);
                }
            }
            catch (\Exception $e) {
                $response['message'] =  __('Coinsnap Bitcoin Voting: API connection is not established', 'coinsnap-paywall');
            }

            $this->sendJsonResponse($response);
        }            
    }
    
    public function sendJsonResponse(array $response): void {
        echo wp_json_encode($response);
        exit();
    }
        
    private function getPaymentProvider() {
        $coinsnap_paywall_data = get_option('coinsnap_paywall_options', []);
        return ($coinsnap_paywall_data['provider'] === 'btcpay')? 'btcpay' : 'coinsnap';
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
        return ($this->getPaymentProvider() === 'btcpay')? $coinsnap_paywall_data['btcpay_url'] : COINSNAP_SERVER_URL;
    }
    
    function btcpayApiUrlHandler(){
            $_nonce = filter_input(INPUT_POST,'apiNonce',FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            if ( !wp_verify_nonce( $_nonce, 'coinsnap-ajax-nonce' ) ) {
                wp_die('Unauthorized!', '', ['response' => 401]);
            }

            if ( current_user_can( 'manage_options' ) ) {
                $host = filter_var(filter_input(INPUT_POST,'host',FILTER_SANITIZE_FULL_SPECIAL_CHARS), FILTER_VALIDATE_URL);

                if ($host === false || (substr( $host, 0, 7 ) !== "http://" && substr( $host, 0, 8 ) !== "https://")) {
                    wp_send_json_error("Error validating BTCPayServer URL.");
                }

                $permissions = array_merge([
                    'btcpay.store.canviewinvoices',
                    'btcpay.store.cancreateinvoice',
                    'btcpay.store.canviewstoresettings',
                    'btcpay.store.canmodifyinvoices'
                ],
                [
                    'btcpay.store.cancreatenonapprovedpullpayments',
                    'btcpay.store.webhooks.canmodifywebhooks',
                ]);

                try {
                    // Create the redirect url to BTCPay instance.
                    $url = $this->getAuthorizeUrl(
                        $host,
                        $permissions,
                        'CoinsnapPaywall',
                        true,
                        true,
                        home_url('?paywall-btcpay-settings-callback'),
                        null
                    );

                    // Store the host to options before we leave the site.
                    coinsnap_settings_update('coinsnap_paywall_options',['btcpay_url' => $host]);

                    // Return the redirect url.
                    wp_send_json_success(['url' => $url]);
                }

                catch (\Throwable $e) {

                }
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

// Adding template redirect handling for paywall-btcpay-settings-callback.
add_action( 'template_redirect', function(){
    
    global $wp_query;
            
    // Only continue on a paywall-btcpay-settings-callback request.    
    if (!isset( $wp_query->query_vars['paywall-btcpay-settings-callback'])) {
        return;
    }

    $CoinsnapBTCPaySettingsUrl = admin_url('/admin.php?page=coinsnap_paywall');

            $rawData = file_get_contents('php://input');
            $form_data = get_option( 'coinsnap_paywall_options', [] );
            
            $btcpay_server_url = $form_data['btcpay_url'];
            $btcpay_api_key  = filter_input(INPUT_POST,'apiKey',FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $request_url = $btcpay_server_url.'/api/v1/stores';
            $request_headers = ['Accept' => 'application/json','Content-Type' => 'application/json','Authorization' => 'token '.$btcpay_api_key];
            $getstores = remoteRequest('GET',$request_url,$request_headers);
            
            if(!isset($getstores['error'])){
                if (count($getstores['body']) < 1) {
                    $messageAbort = __('Error on verifiying redirected API Key with stored BTCPay Server url. Aborting API wizard. Please try again or continue with manual setup.', 'coinsnap-paywall');
                    //$notice->addNotice('error', $messageAbort);
                    wp_redirect($CoinsnapBTCPaySettingsUrl);
                }
            }
                        
            // Data does get submitted with url-encoded payload, so parse $_POST here.
            if (!empty($_POST) || wp_verify_nonce(filter_input(INPUT_POST,'wp_nonce',FILTER_SANITIZE_FULL_SPECIAL_CHARS),'-1')) {
                $data['apiKey'] = filter_input(INPUT_POST,'apiKey',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? null;
                if(isset($_POST['permissions'])){
                    $permissions = array_map('sanitize_text_field', wp_unslash($_POST['permissions']));
                    if(is_array($permissions)){
                        foreach ($permissions as $key => $value) {
                            $data['permissions'][$key] = sanitize_text_field($permissions[$key] ?? null);
                        }
                    }
                }
            }
    
            if (isset($data['apiKey']) && isset($data['permissions'])) {

                $REQUIRED_PERMISSIONS = [
                    'btcpay.store.canviewinvoices',
                    'btcpay.store.cancreateinvoice',
                    'btcpay.store.canviewstoresettings',
                    'btcpay.store.canmodifyinvoices'
                ];
                $OPTIONAL_PERMISSIONS = [
                    'btcpay.store.cancreatenonapprovedpullpayments',
                    'btcpay.store.webhooks.canmodifywebhooks',
                ];
                
                $btcpay_server_permissions = $data['permissions'];
                
                $permissions = array_reduce($btcpay_server_permissions, static function (array $carry, string $permission) {
			return array_merge($carry, [explode(':', $permission)[0]]);
		}, []);

		// Remove optional permissions so that only required ones are left.
		$permissions = array_diff($permissions, $OPTIONAL_PERMISSIONS);

		$hasRequiredPermissions = (empty(array_merge(array_diff($REQUIRED_PERMISSIONS, $permissions), array_diff($permissions, $REQUIRED_PERMISSIONS))))? true : false;
                
                $hasSingleStore = true;
                $storeId = null;
		foreach ($btcpay_server_permissions as $perms) {
                    if (2 !== count($exploded = explode(':', $perms))) { return false; }
                    if (null === ($receivedStoreId = $exploded[1])) { $hasSingleStore = false; }
                    if ($storeId === $receivedStoreId) { continue; }
                    if (null === $storeId) { $storeId = $receivedStoreId; continue; }
                    $hasSingleStore = false;
		}
                
                if ($hasSingleStore && $hasRequiredPermissions) {

                    coinsnap_settings_update(
                        'coinsnap_paywall_options',[
                        'btcpay_api_key' => $data['apiKey'],
                        'btcpay_store_id' => explode(':', $btcpay_server_permissions[0])[1],
                        'provider' => 'btcpay'
                        ]);
                    
                    wp_redirect($CoinsnapBTCPaySettingsUrl);
                    exit();
                }
                else {
                    //$notice->addNotice('error', __('Please make sure you only select one store on the BTCPay API authorization page.', 'coinsnap-paywall'));
                    wp_redirect($CoinsnapBTCPaySettingsUrl);
                    exit();
                }
            }

    //$notice->addNotice('error', __('Error processing the data from Coinsnap. Please try again.', 'coinsnap-paywall'));
    wp_redirect($CoinsnapBTCPaySettingsUrl);
    exit();
});
