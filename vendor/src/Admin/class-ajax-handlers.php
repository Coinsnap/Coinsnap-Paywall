<?php
/**
 * Shared AJAX handlers for admin connection checks and BTCPay authorization.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Admin;

use CoinsnapCore\PluginInstance;
use CoinsnapCore\Util\HttpClient;
use CoinsnapCore\Util\ExchangeRates;
use CoinsnapCore\Util\ProviderFactory;
use CoinsnapCore\Auth\BTCPayAuthorizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reusable AJAX handlers for connection checks and BTCPay wizard.
 * Each consuming plugin registers its own AJAX actions but delegates to these methods.
 */
class AjaxHandlers {

	/**
	 * Handle connection check AJAX request.
	 * Checks store connection, payment methods, and minimum amounts.
	 *
	 * @param PluginInstance $instance Plugin config.
	 */
	public static function handle_connection_check( PluginInstance $instance ): void {
		$nonce = filter_input( INPUT_POST, 'apiNonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! wp_verify_nonce( $nonce, 'coinsnap-ajax-nonce' ) ) {
			wp_die( 'Unauthorized!', '', array( 'response' => 401 ) );
		}

		$settings = SettingsPage::get_settings_for( $instance );
		$provider_name = $settings['payment_provider'] ?? 'coinsnap';
		$provider_label = ( 'btcpay' === $provider_name ) ? 'BTCPay server' : 'Coinsnap';

		$api_url  = ( 'btcpay' === $provider_name ) ? ( $settings['btcpay_host'] ?? '' ) : ( COINSNAP_SERVER_URL ?? '' );
		$api_key  = ( 'btcpay' === $provider_name ) ? ( $settings['btcpay_api_key'] ?? '' ) : ( $settings['coinsnap_api_key'] ?? '' );
		$store_id = ( 'btcpay' === $provider_name ) ? ( $settings['btcpay_store_id'] ?? '' ) : ( $settings['coinsnap_store_id'] ?? '' );

		if ( empty( $api_url ) || empty( $api_key ) || empty( $store_id ) ) {
			self::send_json( array(
				'result'  => false,
				'message' => $instance->get( 'plugin_name' ) . ': ' .
					__( 'Server URL, API Key, or Store ID is not set', 'coinsnap-core' ),
			) );
		}

		$currency = filter_input( INPUT_POST, 'apiCurrency', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( empty( $currency ) ) {
			$currency = 'EUR';
		}

		// Check payment methods and minimum amounts.
		$connection_data = '';
		if ( 'btcpay' === $provider_name ) {
			try {
				$payment_methods = self::get_store_payment_methods( $api_url, $api_key, $store_id );
				if ( isset( $payment_methods['code'] ) && 200 === $payment_methods['code'] ) {
					if ( $payment_methods['result']['onchain'] && ! $payment_methods['result']['lightning'] ) {
						$check = ExchangeRates::check_payment_data( 0.0, $currency, 'bitcoin', 'calculation' );
					} elseif ( $payment_methods['result']['lightning'] ) {
						$check = ExchangeRates::check_payment_data( 0.0, $currency, 'lightning', 'calculation' );
					}
				}
			} catch ( \Exception $e ) {
				self::send_json( array(
					'result'  => false,
					'message' => $instance->get( 'plugin_name' ) . ': ' .
						__( 'API connection is not established', 'coinsnap-core' ),
				) );
			}
		} else {
			$check = ExchangeRates::check_payment_data( 0.0, $currency, 'coinsnap', 'calculation' );
		}

		if ( isset( $check ) && $check['result'] ) {
			$connection_data = __( 'Min amount is', 'coinsnap-core' ) . ' ' . $check['min_value'] . ' ' . $currency;
		} else {
			$connection_data = __( 'No payment method is configured', 'coinsnap-core' );
		}

		// Check store connection.
		$provider = ProviderFactory::create( $instance );
		$store    = $provider->get_store();

		if ( isset( $store['code'] ) && 200 === $store['code'] ) {
			self::send_json( array(
				'result'  => true,
				'message' => $instance->get( 'plugin_name' ) . ': ' .
					$provider_label . ' ' . __( 'is connected', 'coinsnap-core' ) .
					' (' . $connection_data . ')',
			) );
		}

		self::send_json( array(
			'result'  => false,
			'message' => $instance->get( 'plugin_name' ) . ': ' .
				$provider_label . ' ' . __( 'is disconnected', 'coinsnap-core' ),
		) );
	}

	/**
	 * Handle BTCPay API URL AJAX request.
	 * Validates URL and returns BTCPay authorization redirect URL.
	 *
	 * @param PluginInstance $instance Plugin config.
	 */
	public static function handle_btcpay_url( PluginInstance $instance ): void {
		$nonce = filter_input( INPUT_POST, 'apiNonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! wp_verify_nonce( $nonce, 'coinsnap-ajax-nonce' ) ) {
			wp_die( 'Unauthorized!', '', array( 'response' => 401 ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$host = filter_var(
			filter_input( INPUT_POST, 'host', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
			FILTER_VALIDATE_URL
		);

		if ( false === $host || ( substr( $host, 0, 7 ) !== 'http://' && substr( $host, 0, 8 ) !== 'https://' ) ) {
			wp_send_json_error( 'Error validating BTCPay Server URL.' );
		}

		$permissions = array_merge(
			BTCPayAuthorizer::REQUIRED_PERMISSIONS,
			BTCPayAuthorizer::OPTIONAL_PERMISSIONS
		);

		try {
			$url = BTCPayAuthorizer::get_authorize_url(
				$host,
				$permissions,
				$instance->get( 'btcpay_app_name', 'Coinsnap' ),
				true,
				true,
				home_url( '?' . $instance->get( 'btcpay_callback_endpoint' ) ),
				null
			);

			BTCPayAuthorizer::update_settings( $instance->option_key(), array( 'btcpay_host' => $host ) );

			wp_send_json_success( array( 'url' => $url ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( 'Error processing request.' );
		}
	}

	/**
	 * Get store payment methods (BTCPay only).
	 *
	 * @param string $store_url BTCPay server URL.
	 * @param string $api_key   API key.
	 * @param string $store_id  Store ID.
	 * @return array { code: int, result: { onchain: bool, lightning: bool, usdt: bool } }
	 */
	public static function get_store_payment_methods( string $store_url, string $api_key, string $store_id ): array {
		$url     = rtrim( $store_url, '/' ) . '/api/v1/stores/' . rawurlencode( $store_id ) . '/payment-methods';
		$headers = HttpClient::api_headers( $api_key );

		$response = HttpClient::request( 'GET', $url, $headers );

		if ( isset( $response['error'] ) ) {
			return array( 'error' => $response['error'] );
		}

		if ( isset( $response['status'] ) && 200 === $response['status'] ) {
			$methods = $response['body'];
			$result  = array(
				'response'  => $methods,
				'onchain'   => false,
				'lightning' => false,
				'usdt'      => false,
			);

			if ( is_array( $methods ) ) {
				foreach ( $methods as $method ) {
					if ( ! empty( $method['enabled'] ) && isset( $method['paymentMethodId'] ) ) {
						if ( stripos( $method['paymentMethodId'], 'BTC' ) !== false ) {
							$result['onchain'] = true;
						}
						if ( 'Lightning' === $method['paymentMethodId'] || stripos( $method['paymentMethodId'], '-LN' ) !== false ) {
							$result['lightning'] = true;
						}
						if ( stripos( $method['paymentMethodId'], 'USDT' ) !== false ) {
							$result['usdt'] = true;
						}
					}
				}
			}

			return array( 'code' => $response['status'], 'result' => $result );
		}

		return array( 'code' => $response['status'] ?? 0, 'result' => array() );
	}

	/**
	 * Send JSON response and exit.
	 *
	 * @param array $response Response data.
	 */
	public static function send_json( array $response ): void {
		echo wp_json_encode( $response );
		exit();
	}
}
