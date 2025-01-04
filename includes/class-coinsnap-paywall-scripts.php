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
		// TODO: Add hash for caching purposes, and update version variable.

		wp_enqueue_script(
			'coinsnap-paywall-admin',
			plugin_dir_url( __FILE__ ) . '../assets/js/admin.js',
			[ 'jquery' ],
			'1.0.0',
			true
		);

		wp_enqueue_script(
			'coinsnap-paywall-admin',
			plugin_dir_url( __FILE__ ) . '../assets/js/settings.js',
			[ 'jquery' ],
			'1.0.0',
			true
		);

		wp_enqueue_style(
			'coinsnap-paywall-admin',
			plugin_dir_url( __FILE__ ) . '../assets/css/admin.css',
			[],
			'1.0.0'
		);

		wp_localize_script(
			'coinsnap-paywall-admin',
			'coinsnap_paywall_ajax',
			[ 'ajax_url' => admin_url( 'admin-ajax.php' ) ]
		);
	}

	public function enqueue_frontend_scripts() {
		// Only load on pages with the 'paywall_payment' shortcode
		if ( get_post() ) {
			if ( has_shortcode( get_post()->post_content, 'paywall_payment' ) ) {
				// Retrieve the shortcode attributes from the post content
				$pattern = get_shortcode_regex( [ 'paywall_payment' ] );
				preg_match( "/$pattern/", get_post()->post_content, $matches );

				wp_enqueue_style( 'coinsnap-paywall-paywall', plugin_dir_url( __FILE__ ) . "../assets/css/paywall.css", [], '1.0.0' );
				wp_enqueue_script( 'coinsnap-paywall-paywall', plugin_dir_url( __FILE__ ) . '../assets/js/paywall.js', [ 'jquery' ], '1.0.0', true );

				// Localize AJAX URL for the frontend script
				wp_localize_script( 'coinsnap-paywall-paywall', 'coinsnap_paywall_ajax', [ 'ajax_url' => admin_url( 'admin-ajax.php' ) ] );
			}
		}
	}
}

new Coinsnap_Paywall_Scripts();
