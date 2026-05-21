<?php
/**
 * Plugin Name: Coinsnap Core
 * Description: Shared payment infrastructure for Coinsnap WordPress plugins.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Author: Coinsnap
 * Author URI: https://coinsnap.io
 * License: GPL-2.0-or-later
 * Text Domain: coinsnap-core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent double-loading.
if ( defined( 'COINSNAP_CORE_VERSION' ) ) {
	return;
}

define( 'COINSNAP_CORE_VERSION', '1.0.0' );
define( 'COINSNAP_CORE_PHP_VERSION', '7.4' );
define( 'COINSNAP_CORE_PLUGIN_FILE', __FILE__ );
define( 'COINSNAP_CORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'COINSNAP_CORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Shared constants for backward compatibility with existing plugins.
if ( ! defined( 'COINSNAP_SERVER_URL' ) ) {
	define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' );
}
if ( ! defined( 'COINSNAP_API_PATH' ) ) {
	define( 'COINSNAP_API_PATH', '/api/v1/' );
}
if ( ! defined( 'COINSNAP_SERVER_PATH' ) ) {
	define( 'COINSNAP_SERVER_PATH', 'stores' );
}
if ( ! defined( 'COINSNAP_CURRENCIES' ) ) {
	define( 'COINSNAP_CURRENCIES', array( 'EUR', 'USD', 'SATS', 'BTC', 'CAD', 'JPY', 'GBP', 'CHF', 'RUB' ) );
}

// PHP version check.
if ( version_compare( PHP_VERSION, COINSNAP_CORE_PHP_VERSION, '<' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>';
		printf(
			/* translators: 1: required PHP version 2: current PHP version */
			esc_html__( 'Coinsnap Core requires PHP %1$s or higher. You are running %2$s.', 'coinsnap-core' ),
			esc_html( COINSNAP_CORE_PHP_VERSION ),
			esc_html( PHP_VERSION )
		);
		echo '</p></div>';
	} );
	return;
}

// Autoloader.
spl_autoload_register( function ( $class ) {
	$prefix = 'CoinsnapCore\\';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );

	// Explicit class map for WordPress-style filenames.
	$map = array(
		'PluginInstance'                       => 'class-plugin-instance',
		'CoinsnapConstants'                    => 'class-coinsnap-constants',
		'Interfaces\\PaymentProviderInterface' => 'Interfaces/class-payment-provider-interface',
		'Providers\\CoinsnapProvider'           => 'Providers/class-coinsnap-provider',
		'Providers\\BTCPayProvider'             => 'Providers/class-btcpay-provider',
		'Admin\\AjaxHandlers'                   => 'Admin/class-ajax-handlers',
		'Admin\\SettingsPage'                   => 'Admin/class-settings-page',
		'Admin\\TransactionsPage'               => 'Admin/class-transactions-page',
		'Admin\\LogsPage'                       => 'Admin/class-logs-page',
		'Auth\\BTCPayAuthorizer'                => 'Auth/class-btcpay-authorizer',
		'Util\\HttpClient'                      => 'Util/class-http-client',
		'Util\\ExchangeRates'                   => 'Util/class-exchange-rates',
		'Util\\Logger'                          => 'Util/class-logger',
		'Util\\LogLevels'                       => 'Util/class-log-levels',
		'Util\\ProviderFactory'                 => 'Util/class-provider-factory',
		'Database\\PaymentTable'                => 'Database/class-payment-table',
		'Rest\\WebhookHelper'                   => 'Rest/class-webhook-helper',
	);

	if ( isset( $map[ $relative ] ) ) {
		$file = COINSNAP_CORE_PLUGIN_DIR . 'src/' . $map[ $relative ] . '.php';
	} else {
		// Fallback: convert namespace separators to directory separators.
		$file = COINSNAP_CORE_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
	}

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );
