<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Coinsnap_Paywall_BTCPayHandler {
	private $store_id;
	private $api_key;
	private $url;

	public function __construct( $store_id, $api_key, $url ) {
		$this->store_id = $store_id;
		$this->api_key  = $api_key;
		$this->url      = rtrim( $url, '/' );
	}

	/**
	 * Test connection to BTCPay API
	 * @return array
	 */
	public function testConnection() {
		try {
			$response = wp_remote_get( "{$this->url}/api/v1/stores/" . rawurlencode( (string) $this->store_id ), [
				'headers' => [
					'Authorization' => 'token ' . $this->api_key,
					'Content-Type'  => 'application/json'
				],
			] );

			// Check for WP errors
			if ( is_wp_error( $response ) ) {
				return [
					'success' => false,
					'message' => __('Connection to BTCPay server failed.','coinsnap-paywall')
				];
			}

			// Check response code
			$response_code = wp_remote_retrieve_response_code( $response );
			if ( $response_code !== 200 ) {
				return [
					'success' => false,
					'message' => __('Connection to BTCPay server failed. HTTP Error: ','coinsnap-paywall')."{$response_code}"
				];
			}

			// If we get here, connection is successful
			return [
				'success' => true,
				'message' => __('Connection to BTCPay server is successful!','coinsnap-paywall')
			];

		} catch ( Exception $e ) {
			return [
				'success' => false,
				'message' => __('Connection error.','coinsnap-paywall')
			];
		}
	}
}
