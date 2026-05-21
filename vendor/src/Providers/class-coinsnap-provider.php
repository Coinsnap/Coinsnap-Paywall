<?php
/**
 * Coinsnap payment provider.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Providers;

use CoinsnapCore\PluginInstance;
use CoinsnapCore\Interfaces\PaymentProviderInterface;
use CoinsnapCore\CoinsnapConstants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CoinSnap payment provider implementation.
 */
class CoinsnapProvider implements PaymentProviderInterface {

	/** @var PluginInstance */
	private $instance;

	/**
	 * @param PluginInstance $instance Per-plugin configuration.
	 */
	public function __construct( PluginInstance $instance ) {
		$this->instance = $instance;
	}

	/**
	 * Get merged settings with defaults.
	 *
	 * @return array
	 */
	private function get_settings(): array {
		$defaults = array(
			'payment_provider'             => 'coinsnap',
			'coinsnap_store_id'            => '',
			'coinsnap_api_key'             => '',
			'coinsnap_api_base'            => CoinsnapConstants::DEFAULT_API_BASE,
			'coinsnap_webhook_secret'      => '',
			'btcpay_host'                  => '',
			'btcpay_api_key'               => '',
			'btcpay_store_id'              => '',
			'btcpay_webhook_secret'        => '',
			'log_level'                    => 'error',
			'disable_webhook_verification' => false,
		);
		$opts = get_option( $this->instance->option_key(), array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		return array_merge( $defaults, $opts );
	}

	/**
	 * Get store data from Coinsnap server.
	 *
	 * @return array
	 */
	public function get_store(): array {
		$settings = $this->get_settings();
		$api_base = rtrim( $settings['coinsnap_api_base'] ? $settings['coinsnap_api_base'] : CoinsnapConstants::DEFAULT_API_BASE, '/' );
		$api_key  = (string) $settings['coinsnap_api_key'];
		$store    = (string) $settings['coinsnap_store_id'];

		if ( ! $api_base || ! $api_key || ! $store ) {
			return array( 'error' => true, 'message' => __( 'Connection credentials error', 'coinsnap-core' ) );
		}

		$url = $api_base . sprintf( CoinsnapConstants::COINSNAP_STORE_ENDPOINT, rawurlencode( $store ) );

		$args = array(
			'method'  => 'GET',
			'headers' => array(
				'X-Api-Key'     => $api_key,
				'Authorization' => 'token ' . $api_key,
				'Content-Type'  => 'application/json',
				'accept'        => 'application/json',
			),
			'timeout' => 20,
		);

		$res = wp_remote_request( $url, $args );

		if ( is_wp_error( $res ) ) {
			return array( 'error' => true, 'message' => __( 'Store data error', 'coinsnap-core' ) );
		}

		$code   = wp_remote_retrieve_response_code( $res );
		$result = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 200 && $code < 300 && is_array( $result ) ) {
			return array( 'code' => $code, 'result' => $result );
		} else {
			return array( 'error' => true, 'message' => __( 'Coinsnap server request error', 'coinsnap-core' ), 'code' => $code, 'result' => $result );
		}
	}

	/**
	 * Check existing webhook on Coinsnap server.
	 *
	 * @return bool
	 */
	public function check_webhook(): bool {

		$webhookExists = false;
		$settings      = $this->get_settings();

		$api_base = rtrim( $settings['coinsnap_api_base'] ? $settings['coinsnap_api_base'] : CoinsnapConstants::DEFAULT_API_BASE, '/' );
		$api_key  = (string) $settings['coinsnap_api_key'];
		$store    = (string) $settings['coinsnap_store_id'];

		if ( ! $api_base || ! $api_key || ! $store ) {
			return false;
		}

		$webhookOption = get_option( $this->instance->webhook_key() );
		if ( ! is_array( $webhookOption ) || empty( $webhookOption['coinsnap'] ) ) {
			return false;
		}
		$storedWebhook = $webhookOption['coinsnap'];

		// Our site's callback URL that Coinsnap should send webhooks to.
		$site_webhook_url = rtrim( (string) get_home_url(), '/' ) . '/wp-json/' . $this->instance->rest_namespace() . '/webhook/coinsnap';
		// Coinsnap API base for webhook management.
		$api_webhooks_url = $api_base . sprintf( CoinsnapConstants::COINSNAP_WEBHOOKS_V1, rawurlencode( $store ) );

		$url  = $api_webhooks_url;
		$args = array(
			'method'  => 'GET',
			'headers' => array(
				'X-Api-Key'     => $api_key,
				'Authorization' => 'token ' . $api_key,
				'Content-Type'  => 'application/json',
				'accept'        => 'application/json',
			),
			'timeout' => 20,
		);

		$res = wp_remote_request( $url, $args );

		if ( is_wp_error( $res ) ) {
			return false;
		}

		$code          = wp_remote_retrieve_response_code( $res );
		$storeWebhooks = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 200 && $code < 300 && is_array( $storeWebhooks ) ) {

			// Registered webhooks analysis.
			foreach ( $storeWebhooks as $webhook ) {

				// If webhook is registered for this site.
				if ( strpos( $webhook['url'], $site_webhook_url ) !== false ) {

					// Return TRUE if stored webhook ID equals registered webhook ID.
					if ( $webhook['id'] === $storedWebhook['id'] ) {
						$webhookExists = true;
					} else {
						// If not - we delete this webhook from server.
						$delete_url  = $api_webhooks_url . '/' . rawurlencode( $webhook['id'] );
						$delete_args = array(
							'method'  => 'DELETE',
							'headers' => array(
								'X-Api-Key'     => $api_key,
								'Authorization' => 'token ' . $api_key,
								'Content-Type'  => 'application/json',
								'accept'        => 'application/json',
							),
							'timeout' => 20,
						);
						wp_remote_request( $delete_url, $delete_args );
					}
				}
			}

			return $webhookExists;
		}
		return false;
	}

	/**
	 * Webhook registration on Coinsnap Server.
	 *
	 * @return array { id: string, secret: string, url: string }
	 */
	public function register_webhook(): array {

		$settings = $this->get_settings();
		$api_base = rtrim( $settings['coinsnap_api_base'] ? $settings['coinsnap_api_base'] : CoinsnapConstants::DEFAULT_API_BASE, '/' );
		$api_key  = (string) $settings['coinsnap_api_key'];
		$store    = (string) $settings['coinsnap_store_id'];

		if ( ! $api_base || ! $api_key || ! $store ) {
			return array( 'error' => true, 'message' => __( 'Connection credentials error', 'coinsnap-core' ) );
		}

		$ngrok_url = ! empty( $settings['ngrok_url'] ) ? rtrim( $settings['ngrok_url'], '/' ) : '';
		$base_url  = $ngrok_url ? $ngrok_url : rtrim( (string) get_home_url(), '/' );
		$webhook_url = $base_url . '/wp-json/' . $this->instance->rest_namespace() . '/webhook/coinsnap';

		$data = array(
			'url'              => $webhook_url,
			'authorizedEvents' => array(
				'everything'     => false,
				'specificEvents' => array( 'New', 'Expired', 'Settled', 'Processing' ),
			),
		);

		$url = $api_base . sprintf( CoinsnapConstants::COINSNAP_WEBHOOKS_V1, rawurlencode( $store ) );

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'X-Api-Key'     => $api_key,
				'Authorization' => 'token ' . $api_key,
				'Content-Type'  => 'application/json',
				'accept'        => 'application/json',
			),
			'timeout' => 20,
			'body'    => wp_json_encode( $data ),
		);

		$res = wp_remote_request( $url, $args );

		if ( is_wp_error( $res ) ) {
			return array( 'error' => true, 'message' => __( 'Webhook creation error', 'coinsnap-core' ) );
		}

		$code   = wp_remote_retrieve_response_code( $res );
		$result = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 200 && $code < 300 && is_array( $result ) ) {
			return array( 'code' => $code, 'result' => $result );
		} else {
			return array( 'error' => true, 'message' => __( 'Coinsnap server request error', 'coinsnap-core' ), 'code' => $code, 'result' => $result );
		}
	}

	/**
	 * Create a payment invoice.
	 *
	 * @param int    $form_id      Form ID.
	 * @param int    $amount       Amount.
	 * @param string $currency     Currency code.
	 * @param array  $invoice_data Invoice data.
	 * @return array { invoice_id: string, payment_url: string, expires_at?: int }
	 */
	public function create_invoice( int $form_id, int $amount, string $currency, array $invoice_data ): array {
		$settings = $this->get_settings();
		$api_key  = $settings['coinsnap_api_key'];
		$store_id = $settings['coinsnap_store_id'];
		$api_base = rtrim( $settings['coinsnap_api_base'] ? $settings['coinsnap_api_base'] : CoinsnapConstants::DEFAULT_API_BASE, '/' );

		if ( ! $api_key || ! $store_id ) {
			return array();
		}

		$endpoints = array(
			$api_base . sprintf( CoinsnapConstants::COINSNAP_INVOICES_V1, rawurlencode( $store_id ) ),
			$api_base . sprintf( CoinsnapConstants::COINSNAP_INVOICES_ALT, rawurlencode( $store_id ) ),
		);

		// Convert amount from cents back to currency units for CoinSnap API.
		$amount_in_currency = $amount / 100;

		// Validate currency code.
		$supported_currencies = COINSNAP_CURRENCIES;
		if ( ! in_array( $currency, $supported_currencies, true ) ) {
			return array();
		}

		$payload = array(
			'amount'       => $amount_in_currency,
			'currency'     => $currency,
			'referralCode' => $this->instance->referral_code(),
			'buyerEmail'   => isset( $invoice_data['email'] ) ? (string) $invoice_data['email'] : '',
			'metadata'     => array_merge(
				array(
					'form_id' => $form_id,
					'email'   => (string) ( $invoice_data['email'] ?? '' ),
				),
				isset( $invoice_data['metadata'] ) && is_array( $invoice_data['metadata'] ) ? $invoice_data['metadata'] : array()
			),
			'checkout'     => array(
				'defaultPaymentMethod' => 'LightningNetwork',
			),
		);

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'X-Api-Key'     => $api_key,
				'Authorization' => 'token ' . $api_key,
				'Content-Type'  => 'application/json',
				'accept'        => 'application/json',
			),
			'timeout' => 20,
			'body'    => wp_json_encode( $payload ),
		);

		foreach ( $endpoints as $url ) {
			$res = wp_remote_request( $url, $args );
			/** Action: on Coinsnap response (raw). */
			do_action( 'wpbn_coinsnap_response', $res, $form_id );
			if ( is_wp_error( $res ) ) {
				continue;
			}
			$code = wp_remote_retrieve_response_code( $res );
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( $code >= 200 && $code < 300 && is_array( $body ) ) {
				$invoice_id  = isset( $body['id'] ) ? (string) $body['id'] : '';
				$payment_url = isset( $body['checkoutLink'] ) ? (string) $body['checkoutLink'] : '';
				if ( $invoice_id && $payment_url ) {
					return array(
						'invoice_id'  => $invoice_id,
						'payment_url' => $payment_url,
					);
				}
			}
		}

		return array();
	}

	/**
	 * Validate webhook or callback.
	 *
	 * @param array $request Parsed request body.
	 * @return array { invoice_id: string, paid: bool, metadata: array }
	 */
	public function handle_webhook( array $request ): array {
		do_action( 'wpbn_coinsnap_webhook_received', $request );
		$invoice_id = isset( $request['invoiceId'] ) ? (string) $request['invoiceId'] : '';
		$type       = isset( $request['type'] ) ? (string) $request['type'] : '';
		$paid       = in_array( $type, array( 'InvoiceSettled', 'PaymentReceived', 'InvoicePaid', 'Settled' ), true );
		return array(
			'invoice_id' => $invoice_id,
			'paid'       => $paid,
			'metadata'   => $request,
		);
	}

	/**
	 * Check invoice payment status with Coinsnap API.
	 *
	 * @param string $invoice_id Invoice ID to check.
	 * @return array { invoice_id: string, paid: bool, status: string, metadata: array }
	 */
	public function check_invoice_status( string $invoice_id ): array {
		$settings = $this->get_settings();
		$api_key  = $settings['coinsnap_api_key'];
		$store_id = $settings['coinsnap_store_id'];
		$api_base = rtrim( $settings['coinsnap_api_base'] ? $settings['coinsnap_api_base'] : CoinsnapConstants::DEFAULT_API_BASE, '/' );

		if ( ! $api_key || ! $store_id ) {
			return array(
				'invoice_id' => $invoice_id,
				'paid'       => false,
				'status'     => 'error',
				'metadata'   => array( 'error' => 'Missing API credentials' ),
			);
		}

		$endpoints = array(
			$api_base . sprintf( '/api/v1/stores/%s/invoices/%s', rawurlencode( $store_id ), rawurlencode( $invoice_id ) ),
			$api_base . sprintf( '/api/stores/%s/invoices/%s', rawurlencode( $store_id ), rawurlencode( $invoice_id ) ),
		);

		$args = array(
			'method'  => 'GET',
			'headers' => array(
				'X-Api-Key'     => $api_key,
				'Authorization' => 'token ' . $api_key,
				'Content-Type'  => 'application/json',
				'accept'        => 'application/json',
			),
			'timeout' => 20,
		);

		foreach ( $endpoints as $url ) {
			$res = wp_remote_request( $url, $args );
			do_action( 'wpbn_coinsnap_response', $res, 0 );

			if ( is_wp_error( $res ) ) {
				continue;
			}

			$code = wp_remote_retrieve_response_code( $res );
			$body = json_decode( wp_remote_retrieve_body( $res ), true );

			if ( $code >= 200 && $code < 300 && is_array( $body ) ) {
				$status = isset( $body['status'] ) ? (string) $body['status'] : 'unknown';
				$paid   = in_array( $status, array( 'Settled', 'Paid', 'Complete' ), true );

				return array(
					'invoice_id' => $invoice_id,
					'paid'       => $paid,
					'status'     => $status,
					'metadata'   => $body,
				);
			}
		}

		return array(
			'invoice_id' => $invoice_id,
			'paid'       => false,
			'status'     => 'error',
			'metadata'   => array( 'error' => 'All API endpoints failed' ),
		);
	}

	/**
	 * Verify webhook HMAC signature.
	 *
	 * @return bool True if signature valid.
	 */
	public function verify_signature(): bool {
		// Get webhook secret from stored webhook registration data.
		$webhook_option = get_option( $this->instance->webhook_key() );
		$secret         = '';
		if ( is_array( $webhook_option ) && ! empty( $webhook_option['coinsnap']['secret'] ) ) {
			$secret = (string) $webhook_option['coinsnap']['secret'];
		}
		if ( ! $secret ) {
			return false;
		}
		$headers = array(
			isset( $_SERVER['HTTP_BTCPAY_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_BTCPAY_SIGNATURE'] ) ) : '',
			isset( $_SERVER['HTTP_X_COINSNAP_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_COINSNAP_SIGNATURE'] ) ) : '',
			isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) ) : '',
		);
		$payload = file_get_contents( 'php://input' );
		if ( ! $payload ) {
			$payload = '';
		}
		if ( ! $payload ) {
			return false;
		}
		$raw         = hash_hmac( 'sha256', $payload, $secret );
		$with_prefix = 'sha256=' . $raw;
		foreach ( $headers as $sig ) {
			if ( ! $sig ) {
				continue;
			}
			if ( hash_equals( $raw, $sig ) || hash_equals( $with_prefix, $sig ) ) {
				return true;
			}
		}
		return false;
	}
}
