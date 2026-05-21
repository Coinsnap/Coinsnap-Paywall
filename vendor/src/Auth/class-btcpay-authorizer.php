<?php
/**
 * BTCPay Server authorization flow.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Auth;

use CoinsnapCore\PluginInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the BTCPay Server OAuth-style authorization flow.
 *
 * Provides:
 * - Building the authorization URL for BTCPay API key creation.
 * - Registering and handling the callback endpoint that receives the API key.
 * - Merging received credentials into the plugin's WordPress options.
 */
class BTCPayAuthorizer {

	/** Default BTCPay permissions required for invoice management. */
	public const REQUIRED_PERMISSIONS = array(
		'btcpay.store.canviewinvoices',
		'btcpay.store.cancreateinvoice',
		'btcpay.store.canviewstoresettings',
		'btcpay.store.canmodifyinvoices',
	);

	/** Optional BTCPay permissions for extended functionality. */
	public const OPTIONAL_PERMISSIONS = array(
		'btcpay.store.cancreatenonapprovedpullpayments',
		'btcpay.store.webhooks.canmodifywebhooks',
	);

	/**
	 * Build BTCPay authorization URL. Pure function, no side effects.
	 *
	 * @param string      $base_url          BTCPay server base URL.
	 * @param array       $permissions       Array of permission strings.
	 * @param string|null $app_name          Application name shown in BTCPay.
	 * @param bool|null   $strict            Whether to require strict permissions.
	 * @param bool|null   $selective_stores  Whether to allow store selection.
	 * @param string|null $redirect_url      URL to redirect back to after authorization.
	 * @param string|null $app_identifier    Application identifier string.
	 * @return string Fully constructed authorization URL.
	 */
	public static function get_authorize_url(
		string $base_url,
		array $permissions,
		?string $app_name = null,
		?bool $strict = null,
		?bool $selective_stores = null,
		?string $redirect_url = null,
		?string $app_identifier = null
	): string {
		$url = rtrim( $base_url, '/' ) . '/api-keys/authorize';

		$params = array(
			'permissions'           => $permissions,
			'applicationName'       => $app_name,
			'strict'                => $strict,
			'selectiveStores'       => $selective_stores,
			'redirect'              => $redirect_url,
			'applicationIdentifier' => $app_identifier,
		);

		// Remove NULL values.
		$params = array_filter(
			$params,
			function ( $value ) {
				return null !== $value;
			}
		);

		$query_params = array();
		foreach ( $params as $param => $value ) {
			if ( true === $value ) {
				$value = 'true';
			}
			if ( false === $value ) {
				$value = 'false';
			}

			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( true === $item ) {
						$item = 'true';
					}
					if ( false === $item ) {
						$item = 'false';
					}
					$query_params[] = $param . '=' . urlencode( (string) $item );
				}
			} else {
				$query_params[] = $param . '=' . urlencode( (string) $value );
			}
		}

		return $url . '?' . implode( '&', $query_params );
	}

	/**
	 * Register the BTCPay callback endpoint (rewrite + request filter + template_redirect).
	 * Call this from the consuming plugin's boot().
	 *
	 * @param PluginInstance $instance The plugin instance providing configuration.
	 */
	public static function register_callback( PluginInstance $instance ): void {
		$endpoint = $instance->get( 'btcpay_callback_endpoint' );

		add_action(
			'init',
			function () use ( $endpoint ) {
				add_rewrite_endpoint( $endpoint, EP_ROOT );
			}
		);

		add_filter(
			'request',
			function ( $vars ) use ( $endpoint ) {
				if ( isset( $vars[ $endpoint ] ) ) {
					$vars[ $endpoint ]              = true;
					$vars[ $endpoint . '-nonce' ] = wp_create_nonce( 'coinsnap-core-btcpay-nonce' );
				}
				return $vars;
			}
		);

		add_action(
			'template_redirect',
			function () use ( $instance, $endpoint ) {
				self::handle_callback( $instance, $endpoint );
			}
		);
	}

	/**
	 * Handle the BTCPay callback redirect.
	 *
	 * Validates the incoming request, verifies the API key against the BTCPay
	 * server, checks permissions, and saves credentials to the plugin options.
	 *
	 * @param PluginInstance $instance The plugin instance providing configuration.
	 * @param string         $endpoint The rewrite endpoint name.
	 */
	private static function handle_callback( PluginInstance $instance, string $endpoint ): void {
		global $wp_query;

		if ( ! isset( $wp_query->query_vars[ $endpoint ] ) ) {
			return;
		}

		if ( ! isset( $wp_query->query_vars[ $endpoint . '-nonce' ] )
			|| ! wp_verify_nonce( $wp_query->query_vars[ $endpoint . '-nonce' ], 'coinsnap-core-btcpay-nonce' ) ) {
			return;
		}

		$settings_url = admin_url( '/admin.php?page=' . $instance->get( 'menu_slug' ) . '-settings' );
		$option_key   = $instance->option_key();
		$form_data    = get_option( $option_key, array() );
		if ( ! is_array( $form_data ) ) {
			$form_data = array();
		}

		$btcpay_host    = $form_data['btcpay_host'] ?? '';
		$btcpay_api_key = filter_input( INPUT_POST, 'apiKey', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// Verify API key works by fetching stores.
		$request_url = rtrim( $btcpay_host, '/' ) . '/api/v1/stores';
		$args        = array(
			'method'  => 'GET',
			'headers' => array(
				'Authorization' => 'token ' . $btcpay_api_key,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 20,
		);

		$response   = wp_remote_request( $request_url, $args );
		$code       = wp_remote_retrieve_response_code( $response );
		$get_stores = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && is_array( $get_stores ) ) {
			if ( count( $get_stores ) < 1 ) {
				wp_redirect( $settings_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit();
			}
		}

		// Parse POST data for permissions.
		$data = array();
		if ( ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via query_vars.
			$data['apiKey'] = $btcpay_api_key;
			if ( isset( $_POST['permissions'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$permissions = array_map( 'sanitize_text_field', wp_unslash( $_POST['permissions'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( is_array( $permissions ) ) {
					foreach ( $permissions as $key => $value ) {
						$data['permissions'][ $key ] = sanitize_text_field( $permissions[ $key ] ?? '' );
					}
				}
			}
		}

		if ( isset( $data['apiKey'] ) && isset( $data['permissions'] ) ) {
			$btcpay_perms = $data['permissions'];

			$permissions = array_reduce(
				$btcpay_perms,
				function ( array $carry, string $permission ) {
					return array_merge( $carry, array( explode( ':', $permission )[0] ) );
				},
				array()
			);

			// Remove optional permissions to check only required ones.
			$permissions  = array_diff( $permissions, self::OPTIONAL_PERMISSIONS );
			$has_required = empty(
				array_merge(
					array_diff( self::REQUIRED_PERMISSIONS, $permissions ),
					array_diff( $permissions, self::REQUIRED_PERMISSIONS )
				)
			);

			$has_single_store = true;
			$store_id         = null;
			foreach ( $btcpay_perms as $perms ) {
				$exploded = explode( ':', $perms );
				if ( 2 !== count( $exploded ) ) {
					wp_redirect( $settings_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
					exit();
				}
				$received_store_id = $exploded[1] ?? null;
				if ( null === $received_store_id ) {
					$has_single_store = false;
				}
				if ( $store_id === $received_store_id ) {
					continue;
				}
				if ( null === $store_id ) {
					$store_id = $received_store_id;
					continue;
				}
				$has_single_store = false;
			}

			if ( $has_single_store && $has_required ) {
				self::update_settings(
					$option_key,
					array(
						'btcpay_api_key'   => $data['apiKey'],
						'btcpay_store_id'  => explode( ':', $btcpay_perms[0] )[1],
						'payment_provider' => 'btcpay',
					)
				);
			}

			wp_redirect( $settings_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit();
		}

		wp_redirect( $settings_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit();
	}

	/**
	 * Merge settings into an existing WordPress option.
	 *
	 * @param string $option_key The WordPress option name.
	 * @param array  $data       Key-value pairs to merge into the option.
	 */
	public static function update_settings( string $option_key, array $data ): void {
		$existing = get_option( $option_key, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		foreach ( $data as $key => $value ) {
			$existing[ $key ] = $value;
		}
		update_option( $option_key, $existing );
	}
}
