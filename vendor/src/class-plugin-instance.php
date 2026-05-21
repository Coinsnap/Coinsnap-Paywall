<?php
/**
 * Per-plugin configuration container.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds plugin-specific configuration.
 * Each consuming plugin creates its own instance.
 */
class PluginInstance {

	/** @var array */
	private $config;

	/**
	 * @param array $config Plugin-specific config overrides.
	 */
	public function __construct( array $config ) {
		$this->config = array_merge( self::defaults(), $config );
	}

	/**
	 * Default configuration values.
	 */
	private static function defaults(): array {
		return array(
			'plugin_name'              => 'Coinsnap',
			'option_key'               => 'coinsnap_settings',
			'webhook_key'              => 'coinsnap_webhook',
			'table_suffix'             => 'coinsnap_payments',
			'rest_namespace'           => 'coinsnap/v1',
			'referral_code'            => '',
			'text_domain'              => 'coinsnap-core',
			'plugin_url'               => COINSNAP_CORE_PLUGIN_URL,
			'plugin_dir'               => COINSNAP_CORE_PLUGIN_DIR,
			'log_dir_name'             => 'coinsnap-logs',
			'log_file_name'            => 'coinsnap.log',
			'btcpay_callback_endpoint' => 'coinsnap-btcpay-callback',
			'btcpay_app_name'          => 'Coinsnap',
			'menu_slug'                => 'coinsnap',
			'plugin_icon_url'          => '',
			'help_links'               => array(),
			'source_column'            => 'source_id',
		);
	}

	/**
	 * Get a config value by key.
	 *
	 * @param string $key     Config key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public function get( string $key, $default = '' ) {
		return $this->config[ $key ] ?? $default;
	}

	/** Get the WordPress option key for this plugin's settings. */
	public function option_key(): string {
		return $this->config['option_key'];
	}

	/** Get the WordPress option key for this plugin's webhook data. */
	public function webhook_key(): string {
		return $this->config['webhook_key'];
	}

	/** Get this plugin's REST API namespace. */
	public function rest_namespace(): string {
		return $this->config['rest_namespace'];
	}

	/** Get the database table suffix (appended to $wpdb->prefix). */
	public function table_suffix(): string {
		return $this->config['table_suffix'];
	}

	/** Get this plugin's Coinsnap referral code. */
	public function referral_code(): string {
		return $this->config['referral_code'];
	}
}
