<?php
/**
 * Payment provider factory.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Util;

use CoinsnapCore\PluginInstance;
use CoinsnapCore\Interfaces\PaymentProviderInterface;
use CoinsnapCore\Providers\CoinsnapProvider;
use CoinsnapCore\Providers\BTCPayProvider;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Creates the active payment provider for a plugin instance.
 */
class ProviderFactory {

    /**
     * Create a payment provider.
     *
     * @param PluginInstance $instance Plugin config.
     * @param string         $override Optional provider override ('coinsnap' or 'btcpay').
     * @return PaymentProviderInterface
     */
    public static function create( PluginInstance $instance, string $override = '' ): PaymentProviderInterface {
        $provider = $override;
        if ( '' === $provider ) {
            $settings = get_option( $instance->option_key(), array() );
            $provider = ( is_array( $settings ) && isset( $settings['payment_provider'] ) ) ? $settings['payment_provider'] : 'coinsnap';
        }
        if ( 'btcpay' === $provider ) {
            return new BTCPayProvider( $instance );
        }
        return new CoinsnapProvider( $instance );
    }
}
