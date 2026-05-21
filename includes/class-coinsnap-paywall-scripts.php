<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Coinsnap_Paywall_Scripts {
    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_scripts' ] );
    }

    public function enqueue_admin_scripts( $hook ) {
        $post_id = (filter_input(INPUT_GET,'post',FILTER_SANITIZE_FULL_SPECIAL_CHARS ))? filter_input(INPUT_GET,'post',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';

        $post_type = '';
        if(filter_input(INPUT_GET,'post_type',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) === 'paywall-shortcode'){
            $post_type = filter_input(INPUT_GET,'post_type',FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        }
        elseif($post_id > 0){
            $post_data = get_post($post_id);
            $post_type = $post_data->post_type;
        }

        $page             = filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ?: '';
        $is_settings_page = ( $page === 'coinsnap_paywall' );

        if ( $is_settings_page || $post_type === 'paywall-shortcode' ) {

            // Always load the plugin's own admin CSS (for CPT and legacy styles).
            wp_enqueue_style('coinsnap-paywall-admin-style', COINSNAP_PAYWALL_PLUGIN_URL . 'assets/css/admin.css', [], COINSNAP_PAYWALL_VERSION );

            if ( $is_settings_page && defined( 'COINSNAP_CORE_PLUGIN_URL' ) ) {
                // Settings pages: load the vendor's csc-* design system.
                $core_version = defined( 'COINSNAP_CORE_VERSION' ) ? COINSNAP_CORE_VERSION : '1.0.0';

                wp_register_style( 'coinsnap-core-admin', COINSNAP_CORE_PLUGIN_URL . 'assets/css/admin.css', [], $core_version );
                wp_register_script( 'coinsnap-core-admin', COINSNAP_CORE_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], $core_version, true );

                wp_localize_script( 'coinsnap-core-admin', 'CoinsnapCoreAdmin', [
                    'option_key'        => 'coinsnap_paywall_options',
                    'ajax_url'          => admin_url( 'admin-ajax.php' ),
                    'nonce'             => wp_create_nonce( 'coinsnap-ajax-nonce' ),
                    'connection_action' => 'coinsnap_paywall_connection_handler',
                    'btcpay_action'     => 'coinsnap_paywall_btcpay_apiurl_handler',
                    'webhook_action'    => '',
                    'post'              => $post_id,
                ] );

                wp_enqueue_style( 'coinsnap-core-admin' );
                wp_enqueue_script( 'coinsnap-core-admin' );
            } else {
                // CPT (paywall-shortcode) pages: load core CSS for csc-* cards + plugin JS.
                if ( defined( 'COINSNAP_CORE_PLUGIN_URL' ) ) {
                    $core_version = defined( 'COINSNAP_CORE_VERSION' ) ? COINSNAP_CORE_VERSION : '1.0.0';
                    if ( ! wp_style_is( 'coinsnap-core-admin', 'registered' ) ) {
                        wp_register_style( 'coinsnap-core-admin', COINSNAP_CORE_PLUGIN_URL . 'assets/css/admin.css', [], $core_version );
                    }
                    wp_enqueue_style( 'coinsnap-core-admin' );
                }
                wp_enqueue_script('coinsnap-paywall-admin-script', COINSNAP_PAYWALL_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], COINSNAP_PAYWALL_VERSION, true);
                wp_localize_script(
                    'coinsnap-paywall-admin-script',
                    'coinsnap_paywall_ajax',
                    ['ajax_url' => admin_url('admin-ajax.php'),'nonce' => wp_create_nonce( 'coinsnap-ajax-nonce'),'post' => $post_id]
                );
            }
        }
    }

    public function enqueue_frontend_scripts() {
    //  Only load on pages with the 'paywall_payment' shortcode
        if ( get_post() ) {
            if ( has_shortcode( get_post()->post_content, 'paywall_payment' ) ) {
                // Retrieve the shortcode attributes from the post content
		$pattern = get_shortcode_regex( [ 'paywall_payment' ] );
		preg_match( "/$pattern/", get_post()->post_content, $matches );

		wp_enqueue_style( 'coinsnap-paywall-paywall', COINSNAP_PAYWALL_PLUGIN_URL . 'assets/css/paywall.css', [], COINSNAP_PAYWALL_VERSION );
		wp_enqueue_script( 'coinsnap-paywall-paywall', COINSNAP_PAYWALL_PLUGIN_URL . 'assets/js/paywall.js', [ 'jquery' ], COINSNAP_PAYWALL_VERSION, true );

		// Localize AJAX URL for the frontend script
		wp_localize_script( 'coinsnap-paywall-paywall', 'coinsnap_paywall_ajax', [ 'ajax_url' => admin_url( 'admin-ajax.php' ) ] );
            }
        }
    }
}

new Coinsnap_Paywall_Scripts();
