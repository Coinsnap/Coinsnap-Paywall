<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Coinsnap_Paywall_CoinsnapHandler {
	private $store_id;
	private $api_key;
	private $url;

	public function __construct( $store_id, $api_key ) {
		$this->store_id = $store_id;
		$this->api_key  = $api_key;
		$this->url      = 'https://app.coinsnap.io';
	}

	/**
	 * Test connection to Coinsnap API
	 * @return array
	 */
	public function testConnection() {
		try {
			$response = wp_remote_get( "{$this->url}/api/v1/stores/" . rawurlencode( (string) $this->store_id ), [
				'headers' => [
					'x-api-key'    => $this->api_key,
					'Content-Type' => 'application/json',
				],
			] );

			// Check for WP errors
			if ( is_wp_error( $response ) ) {
				return [
					'success' => false,
					'message' => __('Connection to the Coinsnap failed.','coinsnap-paywall')
				];
			}

			// Check response code
			$response_code = wp_remote_retrieve_response_code( $response );
			if ( $response_code !== 200 ) {
				return [
					'success' => false,
					'message' => __('Connection to the Coinsnap failed. HTTP Error: ','coinsnap-paywall')."{$response_code}"
				];
			}

			// If we get here, connection is successful
			return [
				'success' => true,
				'message' => __('Connection to the Coinsnap is successful!','coinsnap-paywall')
			];

		} catch ( Exception $e ) {
			return [
				'success' => false,
				'message' => __('Connection error.','coinsnap-paywall')
			];
		}
	}
}
