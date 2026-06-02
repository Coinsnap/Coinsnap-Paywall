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

                // Override vendor's .csc-btn-generate so it opens a popup on HTTP
                // and relays the key back via postMessage instead of navigating away.
                $popup_inline_js = <<<'JSCODE'
(function($) {
    $(document).ready(function() {
        $('.csc-btn-generate').off('click').on('click', function(e) {
            e.preventDefault();
            var $wrapper = $(this).closest('.csc-generate-key-wrapper, .csc-field-row, .bif-generate-key-wrapper');
            var host = $wrapper.find('input[type="url"]').val() || $(this).siblings('input[type="url"]').val();
            if (!host || host.indexOf('http') === -1) {
                alert('Please enter a valid URL including https:// for your BTCPay Server.');
                return;
            }
            try { new URL(host); } catch(err) {
                alert('Please enter a valid URL including https:// for your BTCPay Server.');
                return;
            }
            if (typeof CoinsnapCoreAdmin === 'undefined' || !CoinsnapCoreAdmin.ajax_url) return;
            $.post(CoinsnapCoreAdmin.ajax_url, {
                action: CoinsnapCoreAdmin.btcpay_action || 'coinsnap_paywall_btcpay_apiurl_handler',
                host: host,
                apiNonce: CoinsnapCoreAdmin.nonce || ''
            }, function(response) {
                if (response.data && response.data.url) {
                    if (response.data.popup) {
                        var popup = window.open(response.data.url, 'btcpay_paywall_auth', 'width=760,height=640,scrollbars=yes,resizable=yes');
                        var handler = function(event) {
                            if (event.origin !== window.location.origin) return;
                            if (!event.data || event.data.type !== 'coinsnap_paywall_btcpay_auth') return;
                            window.removeEventListener('message', handler);
                            var $apiKey = $('input[name$="[btcpay_api_key]"]');
                            var $storeId = $('input[name$="[btcpay_store_id]"]');
                            if (event.data.apiKey && $apiKey.length) {
                                $apiKey.val(event.data.apiKey).css({'border-color': '#00a32a', 'box-shadow': '0 0 0 1px #00a32a'});
                            }
                            if (event.data.storeId && $storeId.length) {
                                $storeId.val(event.data.storeId).css({'border-color': '#00a32a', 'box-shadow': '0 0 0 1px #00a32a'});
                            }
                            if (popup && !popup.closed) { popup.close(); }
                        };
                        window.addEventListener('message', handler);
                    } else {
                        window.location = response.data.url;
                    }
                }
            }).fail(function() {
                alert('Error processing your request. Please verify your BTCPay Server URL.');
            });
        });
    });
})(jQuery);
JSCODE;
                wp_add_inline_script( 'coinsnap-core-admin', $popup_inline_js, 'after' );
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
