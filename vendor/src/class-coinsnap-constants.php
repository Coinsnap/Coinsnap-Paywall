<?php
/**
 * Shared constants for Coinsnap payment APIs.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API endpoint constants shared across all Coinsnap plugins.
 */
class CoinsnapConstants {

	/** Default Coinsnap API base URL. */
	public const DEFAULT_API_BASE = 'https://app.coinsnap.io';

	/** Coinsnap API endpoints (relative to API base, use sprintf with store ID). */
	public const COINSNAP_STORE_ENDPOINT   = '/api/v1/stores/%s';
	public const COINSNAP_INVOICES_V1      = '/api/v1/stores/%s/invoices';
	public const COINSNAP_INVOICES_ALT     = '/api/stores/%s/invoices';
	public const COINSNAP_WEBHOOKS_V1      = '/api/v1/stores/%s/webhooks';

	/** BTCPay Server API endpoints (relative to host, use sprintf with store ID). */
	public const BTCPAY_STORES             = '/api/v1/stores';
	public const BTCPAY_STORE              = '/api/v1/stores/%s';
	public const BTCPAY_INVOICES           = '/api/v1/stores/%s/invoices';
	public const BTCPAY_WEBHOOKS           = '/api/v1/stores/%s/webhooks';

	/** HTTP header names. */
	public const HEADER_API_KEY            = 'X-Api-Key';
}
