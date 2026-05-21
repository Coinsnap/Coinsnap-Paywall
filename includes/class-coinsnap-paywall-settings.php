<?php
/**
 * Settings management for Coinsnap Bitcoin Paywall.
 *
 * Delegates all settings registration, sanitization, and rendering to
 * CoinsnapCore\Admin\SettingsPage so the settings page matches the
 * card-based UI used by all other Coinsnap plugins.
 *
 * @package coinsnap-paywall
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

class Coinsnap_Paywall_Settings {

public function __construct() {
add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
add_action( 'admin_init', [ $this, 'register_settings' ] );
add_action( 'admin_notices', [ $this, 'maybe_show_setup_notice' ] );
}

/** Delegate settings registration to coinsnap-core. */
public function register_settings(): void {
\CoinsnapCore\Admin\SettingsPage::register_for( coinsnap_paywall_instance() );
}

/** Show a setup notice when credentials are missing. */
public function maybe_show_setup_notice(): void {
\CoinsnapCore\Admin\SettingsPage::maybe_show_setup_notice( coinsnap_paywall_instance() );
}

/** Render the settings page using the coinsnap-core card UI. */
public function settings_page_html(): void {
if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'Insufficient permissions.', 'coinsnap-paywall' ) );
}
\CoinsnapCore\Admin\SettingsPage::render_page_for( coinsnap_paywall_instance() );
}

public function add_menu_page(): void {
add_menu_page(
__( 'Coinsnap Bitcoin Paywall', 'coinsnap-paywall' ),
__( 'Coinsnap Bitcoin Paywall', 'coinsnap-paywall' ),
'manage_options',
'coinsnap_paywall',
[ $this, 'settings_page_html' ],
'dashicons-lock',
100
);

// Rename the auto-generated first submenu entry to "Settings".
add_submenu_page(
'coinsnap_paywall',
__( 'Settings', 'coinsnap-paywall' ),
__( 'Settings', 'coinsnap-paywall' ),
'manage_options',
'coinsnap_paywall',
[ $this, 'settings_page_html' ]
);

// Paywall Shortcodes submenu.
add_submenu_page(
'coinsnap_paywall',
__( 'Paywall Shortcodes', 'coinsnap-paywall' ),
__( 'Paywall Shortcodes', 'coinsnap-paywall' ),
'manage_options',
'edit.php?post_type=paywall-shortcode'
);
}
}

new Coinsnap_Paywall_Settings();