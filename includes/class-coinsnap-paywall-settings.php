<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Coinsnap_Paywall_Settings {
	private $shortcode_class;

	public function __construct() {
		// Register menus
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'update_option_coinsnap_paywall_options', [ $this, 'test_connection_on_save' ], 10, 2 );
		add_action( 'admin_notices', [ $this, 'display_connection_test_notice' ] );

		// Instantiate the shortcode class
		$this->shortcode_class = new Coinsnap_Paywall_Shortcode();
	}

	public function register_settings() {
		register_setting( 'coinsnap_paywall', 'coinsnap_paywall_options', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_options' ]
		] );

		// Provider Section
		add_settings_section(
			'coinsnap_paywall_provider_section',
			'Provider Settings',
			[ $this, 'provider_section_callback' ],
			'coinsnap_paywall'
		);

		add_settings_field(
			'provider',
			'Payment Provider',
			[ $this, 'render_field' ],
			'coinsnap_paywall',
			'coinsnap_paywall_provider_section',
			[
				'label_for' => 'provider',
				'type'      => 'select',
				'options'   => [
					'coinsnap' => 'Coinsnap',
					'btcpay'   => 'BTCPay'
				]
			]
		);

		// Coinsnap Section
		add_settings_section(
			'coinsnap_paywall_coinsnap_section',
			'Coinsnap Settings',
			[ $this, 'coinsnap_section_callback' ],
			'coinsnap_paywall'
		);

		add_settings_field(
			'coinsnap_store_id',
			'Coinsnap Store ID',
			[ $this, 'render_field' ],
			'coinsnap_paywall',
			'coinsnap_paywall_coinsnap_section',
			[
				'label_for' => 'coinsnap_store_id',
				'type'      => 'text'
			]
		);

		add_settings_field(
			'coinsnap_api_key',
			'Coinsnap API Key',
			[ $this, 'render_field' ],
			'coinsnap_paywall',
			'coinsnap_paywall_coinsnap_section',
			[
				'label_for' => 'coinsnap_api_key',
				'type'      => 'secret'
			]
		);

                add_settings_field(
                    'check_connection_coinsnap',
                    'Check Connection',
                    [$this, 'render_field'],
                    'coinsnap_paywall',
                    'coinsnap_paywall_coinsnap_section',
                    [
                        'label_for' => 'check_connection_coinsnap',
                        'type'      => 'check_connection',
                        'id'        => 'check_connection_coinsnap'

                    ]
                );

		// BTCPay Section
		add_settings_section(
			'coinsnap_paywall_btcpay_section',
			'BTCPay Settings',
			[ $this, 'btcpay_section_callback' ],
			'coinsnap_paywall'
		);

		add_settings_field(
			'btcpay_url',
			'BTCPay URL',
                        [ $this, 'render_field' ],
                        'coinsnap_paywall',
                        'coinsnap_paywall_btcpay_section',
                        [
                            'label_for' => 'btcpay_url',
                            'type'      => 'text',
                            'description' => '<button class="button btcpay-apikey-link" type="button" id="coinsnap_paywall_btcpay_wizard_button" target="_blank">'. esc_html__('Generate API key','coinsnap-paywall') .'</button>'
			]
		);

		add_settings_field(
			'btcpay_store_id',
			'BTCPay Store ID',
			[ $this, 'render_field' ],
			'coinsnap_paywall',
			'coinsnap_paywall_btcpay_section',
			[
				'label_for' => 'btcpay_store_id',
				'type'      => 'text'
			]
		);

		add_settings_field(
			'btcpay_api_key',
			'BTCPay API Key',
			[ $this, 'render_field' ],
			'coinsnap_paywall',
			'coinsnap_paywall_btcpay_section',
			[
				'label_for' => 'btcpay_api_key',
				'type'      => 'secret'
			]
		);

                add_settings_field(
                    'check_connection_btcpay',
                    'Check Connection',
                    [$this, 'render_field'],
                    'coinsnap_paywall',
                    'coinsnap_paywall_btcpay_section',
                    [
                        'label_for' => 'check_connection_btcpay',
                        'type'      => 'check_connection',
                        'id'        => 'check_connection_btcpay'
                    ]
                );
	}

	public function render_field( $args ) {
		$options     = get_option( 'coinsnap_paywall_options', [] );
		$field_id    = $args['label_for'];
		$field_type  = $args['type'];
		$field_value = isset( $options[ $field_id ] ) ? $options[ $field_id ] : '';

		switch ( $field_type ) {
			case 'select':
				echo '<select 
                id="' . esc_attr( $field_id ) . '" 
                name="coinsnap_paywall_options[' . esc_attr( $field_id ) . ']"
                class="regular-text">';
				foreach ( $args['options'] as $value => $label ) {
					echo '<option value="' . esc_attr( $value ) . '"' .
					     selected( $field_value, $value, false ) . '>' .
					     esc_html( $label ) . '</option>';
				}
				echo '</select>';
				break;

			case 'secret':
				// Never print a stored key. Show only its last 4 characters; an empty field keeps the stored key.
				$hint = Coinsnap_Paywall_Security::secret_hint( (string) $field_value );
				echo '<input type="password" 
                id="' . esc_attr( $field_id ) . '" 
                name="coinsnap_paywall_options[' . esc_attr( $field_id ) . ']" 
                value="" autocomplete="new-password" 
                placeholder="' . esc_attr( $hint !== '' ? $hint : __( 'Paste your API key', 'coinsnap-paywall' ) ) . '" 
                class="regular-text">';
				break;

			case 'text':
				echo '<input type="text" 
                id="' . esc_attr( $field_id ) . '" 
                name="coinsnap_paywall_options[' . esc_attr( $field_id ) . ']" 
                value="' . esc_attr( $field_value ) . '" 
                class="regular-text"' .
				     ( isset( $args['readonly'] ) && $args['readonly'] ? ' readonly' : '' ) .
				     ( isset( $args['value'] ) ? ' value="' . esc_attr( $args['value'] ) . '"' : '' ) .
				     '>';
				break;
                            
                            
                        case 'check_connection':
                            $id = isset($args['id']) ? $args['id'] : 'check_connection';
                            echo '<div >' . '<button type="button" class="button" id="' . esc_attr($id) . '_button">' . esc_html__('Check', 'coinsnap-paywall') . '</button>' . '<span style="" id="' . esc_attr($id) .  '">' . '</span>' . '</div>';
                        break;
            
		}

            if (isset($args['description']) && !empty($args['description'])) {
                echo '<p class="description">' . wp_kses($args['description'],[
                    'a' => ['href'  => true,'title' => true,'class' => true], 
                    'b' => [], 
                    'button' => ['class' => true,'type' => true, 'id' => true, 'target' => true]
                    ]) . '</p>';
            }
	}

	public function sanitize_options( $options ) {
		// Merge into the stored options: an empty key field keeps the stored key,
		// and a new BTCPay URL without a new key drops the old key (guide 6.14).
		return Coinsnap_Paywall_Security::merge_settings( (array) get_option( 'coinsnap_paywall_options', [] ), is_array( $options ) ? $options : [] );
	}

	// Optional section callbacks for additional descriptions
	public function provider_section_callback() {
		echo esc_html_e( 'Select your preferred payment provider and configure its settings below.', 'coinsnap-paywall' );
	}

	public function coinsnap_section_callback() {
		echo esc_html_e( 'Enter your Coinsnap credentials here if you selected Coinsnap as your payment provider.', 'coinsnap-paywall' );
	}

	public function btcpay_section_callback() {
		echo esc_html_e( 'Enter your BTCPay credentials here if you selected BTCPay as your payment provider.', 'coinsnap-paywall' );
	}

    public function add_menu_page() {
        // Add a top-level menu page for Coinsnap Bitcoin Paywall
        add_menu_page(
            'Coinsnap Bitcoin Paywall',
            'Coinsnap Bitcoin Paywall',
            'manage_options',
            'coinsnap_paywall',
            [ $this, 'settings_page_html' ],
			'dashicons-lock',
			100
		);
                
        add_submenu_page(
            'coinsnap_paywall',
            'Settings',
            'Settings',
            'manage_options',
            'coinsnap_paywall',
            [$this, 'settings_page_html']
        );

        

		// Add the Paywall Shortcodes submenu
		add_submenu_page(
			'coinsnap_paywall', // Parent slug
			'Paywall Shortcodes', // Page title
			'Paywall Shortcodes', // Menu title
			'manage_options', // Capability
			'edit.php?post_type=paywall-shortcode' // Submenu slug
		);
	}

	/**
	 * Renders a specific settings section manually.
	 *
	 * @param string $section_id The ID of the section to render.
	 */
	private function render_section( $section_id ) {
		global $wp_settings_sections, $wp_settings_fields;

		if ( ! isset( $wp_settings_sections['coinsnap_paywall'][ $section_id ] ) ) {
			return;
		}

		$section = $wp_settings_sections['coinsnap_paywall'][ $section_id ];

		if ( ! empty( $section['title'] ) ) {
			echo '<h3>' . esc_html( $section['title'] ) . '</h3>';
		}

		if ( ! empty( $section['callback'] ) ) {
			call_user_func( $section['callback'], $section );
		}

		if ( ! empty( $wp_settings_fields['coinsnap_paywall'][ $section_id ] ) ) {
			echo '<table class="form-table">';
			do_settings_fields( 'coinsnap_paywall', $section_id );
			echo '</table>';
		}
	}

	public function settings_page_html() {
		?>
      <div class="wrap">
        <h1><?php esc_html_e( 'Coinsnap Bitcoin Paywall Settings', 'coinsnap-paywall' ); ?></h1>
        <form method="post" action="options.php">
			<?php
			// Render the settings fields for the Coinsnap Bitcoin Paywall
			settings_fields( 'coinsnap_paywall' );

			// Render the Provider Settings Section
                        echo '<div id="coinsnap-settings-provider">';
			$this->render_section( 'coinsnap_paywall_provider_section' );
			echo '</div><div class="coinsnapConnectionStatus"></div>';

			// Render Coinsnap Settings inside a wrapper
			echo '<div id="coinsnap-settings-wrapper" class="provider-settings">';
			$this->render_section( 'coinsnap_paywall_coinsnap_section' );
			echo '</div>';

			// Render BTCPay Settings inside a wrapper
			echo '<div id="btcpay-settings-wrapper" class="provider-settings">';
			$this->render_section( 'coinsnap_paywall_btcpay_section' );
			echo '</div>';

			// Render submit button
			submit_button();
			?>
        </form>
      </div>
		<?php
	}

    public function display_connection_test_notice() {

    //  Only show on the plugin settings page
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'toplevel_page_coinsnap_paywall' ) {
            return;
        }

    // Retrieve the connection test result
        $connection_result = get_option( 'coinsnap_paywall_connection_result' );

        $btcpay_notice = get_transient( Coinsnap_Paywall_Security::NOTICE_KEY );
        if ( is_string( $btcpay_notice ) ) {
            delete_transient( Coinsnap_Paywall_Security::NOTICE_KEY );
            $ok = $btcpay_notice === 'ok';
            echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $ok
                ? __( 'BTCPay Server is connected.', 'coinsnap-paywall' )
                : __( 'BTCPay connection failed. The API key must be limited to one store with the requested permissions. Please try again.', 'coinsnap-paywall' ) ) . '</p></div>';
        }

        if ( is_array($connection_result) ) {
            $class = $connection_result['success'] ? 'notice-success' : 'notice-error';
	?>
        <div class="notice <?php echo esc_attr( $class ); ?> is-dismissible"><p><?php echo esc_html( $connection_result['message'] );?></p></div>
        <?php
        //update_option( 'coinsnap_paywall_connection_result', '' );
	}
    }

    public function test_connection_on_save( $old_options, $new_options ) {

        // Ensure we have a provider selected
        if ( ! isset( $new_options['provider'] ) ) {
            return;
        }

	$connection_result = ['success' => false, 'message' => __( 'Connection test failed', 'coinsnap-paywall' )];

        try {
            $handler = null;

            // Select handler based on provider
            if ( $new_options['provider'] === 'coinsnap' ) {
		
                // Ensure required Coinsnap credentials are present
		if ( empty( $new_options['coinsnap_store_id'] ) || empty( $new_options['coinsnap_api_key'] ) ) {
                    $connection_result['message'] = __( 'Coinsnap Store ID or API Key is missing', 'coinsnap-paywall' );
                    update_option( 'coinsnap_paywall_connection_result', $connection_result );
                    return;
                }

                $handler = new Coinsnap_Paywall_CoinsnapHandler($new_options['coinsnap_store_id'],$new_options['coinsnap_api_key']);
            }
            
            elseif ( $new_options['provider'] === 'btcpay' ) {
                
                // Ensure required BTCPay credentials are present
		if (empty( $new_options['btcpay_store_id'] ) || empty( $new_options['btcpay_api_key'] ) || empty( $new_options['btcpay_url'] )){
                    $connection_result['message'] = __( 'BTCPay Store ID, API Key, or URL is missing', 'coinsnap-paywall' );
                    update_option( 'coinsnap_paywall_connection_result', $connection_result );
                    return;
		}

		$handler = new Coinsnap_Paywall_BTCPayHandler($new_options['btcpay_store_id'], $new_options['btcpay_api_key'], $new_options['btcpay_url']);
            }

            // Perform connection test
            if ( $handler ) {
                $connection_result = $handler->testConnection();
            }
	}
        catch ( Exception $e ) {
            $connection_result['message'] = __( 'Connection test failed', 'coinsnap-paywall' );
        }

	// Persist the connection result
        update_option( 'coinsnap_paywall_connection_result', $connection_result );
    }
}

$GLOBALS['coinsnap_paywall_settings'] = new Coinsnap_Paywall_Settings();
