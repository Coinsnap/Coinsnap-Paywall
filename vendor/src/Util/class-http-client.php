<?php
/**
 * HTTP client wrapper.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generic HTTP request wrapper around wp_remote_request.
 */
class HttpClient {

	/**
	 * Make an HTTP request.
	 *
	 * @param string $method  HTTP method (GET, POST, DELETE, etc.).
	 * @param string $url     Request URL.
	 * @param array  $headers Request headers.
	 * @param string $body    Request body.
	 * @param int    $timeout Request timeout in seconds.
	 * @return array { status: int, body: mixed, headers: array } or { error: array }
	 */
	public static function request( string $method, string $url, array $headers = array(), string $body = '', int $timeout = 15 ): array {
		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'body'    => $body,
			'timeout' => $timeout,
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'error' => array(
					'code'    => (int) $response->get_error_code(),
					'message' => $response->get_error_message(),
				),
			);
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => json_decode( wp_remote_retrieve_body( $response ), true ),
			'headers' => wp_remote_retrieve_headers( $response )->getAll(),
		);
	}

	/**
	 * Build standard API headers for Coinsnap/BTCPay.
	 *
	 * @param string $api_key API key.
	 * @return array Headers array.
	 */
	public static function api_headers( string $api_key ): array {
		return array(
			'X-Api-Key'     => $api_key,
			'Authorization' => 'token ' . $api_key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);
	}
}
