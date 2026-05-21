<?php
/**
 * Webhook verification and parsing helpers.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Rest;

use CoinsnapCore\PluginInstance;
use CoinsnapCore\Providers\CoinsnapProvider;
use CoinsnapCore\Providers\BTCPayProvider;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static utilities for webhook signature verification and data parsing.
 * Consuming plugins call these in their own REST route handlers.
 */
class WebhookHelper {

    /**
     * Verify Coinsnap webhook signature.
     *
     * @param PluginInstance $instance Plugin config (for reading webhook secret).
     * @return bool True if signature is valid.
     */
    public static function verify_coinsnap_signature( PluginInstance $instance ): bool {
        $provider = new CoinsnapProvider( $instance );
        return $provider->verify_signature();
    }

    /**
     * Verify BTCPay webhook signature.
     *
     * @param PluginInstance $instance Plugin config (for reading webhook secret).
     * @return bool True if signature is valid.
     */
    public static function verify_btcpay_signature( PluginInstance $instance ): bool {
        $provider = new BTCPayProvider( $instance );
        return $provider->verify_signature();
    }

    /**
     * Parse webhook data into a standard format.
     *
     * @param string $provider Provider name ('coinsnap' or 'btcpay').
     * @param array  $data     Raw webhook JSON data.
     * @return array { invoice_id: string, paid: bool, type: string, metadata: array }
     */
    public static function parse_webhook( string $provider, array $data ): array {
        $invoice_id = isset( $data['invoiceId'] ) ? (string) $data['invoiceId'] : '';
        $type       = isset( $data['type'] ) ? (string) $data['type'] : '';
        $paid       = in_array( $type, array( 'InvoiceSettled', 'PaymentReceived', 'InvoicePaid', 'Settled' ), true );

        return array(
            'invoice_id' => $invoice_id,
            'paid'       => $paid,
            'type'       => $type,
            'metadata'   => $data,
        );
    }
}
