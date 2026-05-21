<?php
/**
 * Exchange rate utilities.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exchange rate fetching and payment validation utilities.
 * Uses Kraken public API for real-time BTC rates (no auth required).
 */
class ExchangeRates {

	/** Kraken public ticker API. */
	private const KRAKEN_URL = 'https://api.kraken.com/0/public/Ticker';

	/** Map of our currency codes to Kraken pair names. */
	private const KRAKEN_PAIRS = array(
		'EUR' => 'XXBTZEUR',
		'USD' => 'XXBTZUSD',
		'GBP' => 'XXBTZGBP',
		'CAD' => 'XXBTZCAD',
		'JPY' => 'XXBTZJPY',
		'CHF' => 'XBTCHF',
	);

	/**
	 * Get supported currencies list.
	 *
	 * @return array Currency codes.
	 */
	public static function get_currencies(): array {
		if ( defined( 'COINSNAP_CURRENCIES' ) ) {
			return COINSNAP_CURRENCIES;
		}
		return array( 'EUR', 'USD', 'SATS', 'BTC', 'CAD', 'JPY', 'GBP', 'CHF', 'RUB' );
	}

	/**
	 * Load exchange rates from Kraken.
	 *
	 * Returns data in the same format as the old CoinGecko response for backward compatibility:
	 * { result: true, data: { eur: { value: 12345.67 }, usd: { value: 67890.12 }, ... } }
	 * Where 'value' = how many units of that currency per 1 BTC.
	 *
	 * @return array { result: bool, data?: array, error?: string }
	 */
	public static function load_rates(): array {
		$pairs = implode( ',', array_values( self::KRAKEN_PAIRS ) );
		$url   = self::KRAKEN_URL . '?pair=' . $pairs;

		$response = HttpClient::request( 'GET', $url );

		if ( isset( $response['error'] ) || ! isset( $response['status'] ) || 200 !== $response['status'] ) {
			return array( 'result' => false, 'error' => 'ratesLoadingError' );
		}

		$body = $response['body'];
		if ( ! is_array( $body ) || ! empty( $body['error'] ) || empty( $body['result'] ) ) {
			return array( 'result' => false, 'error' => 'ratesListError' );
		}

		$kraken_data = $body['result'];
		$rates       = array();

		// Convert Kraken format to our standard format
		foreach ( self::KRAKEN_PAIRS as $currency => $pair ) {
			if ( isset( $kraken_data[ $pair ] ) && isset( $kraken_data[ $pair ]['c'][0] ) ) {
				$price = (float) $kraken_data[ $pair ]['c'][0];
				if ( $price > 0 ) {
					$rates[ strtolower( $currency ) ] = array( 'value' => $price );
				}
			}
		}

		// Add BTC (1 BTC = 1 BTC)
		$rates['btc'] = array( 'value' => 1.0 );

		// Add SATS (1 BTC = 100,000,000 SATS)
		$rates['sats'] = array( 'value' => 100000000.0 );

		// Add RUB estimate via USD if available (Kraken doesn't have BTC/RUB)
		if ( isset( $rates['usd'] ) ) {
			// Approximate RUB/USD rate — this is a rough estimate
			$rates['rub'] = array( 'value' => $rates['usd']['value'] * 90 );
		}

		if ( empty( $rates ) ) {
			return array( 'result' => false, 'error' => 'ratesListError' );
		}

		return array( 'result' => true, 'data' => $rates );
	}

	/**
	 * Validate payment amount and get exchange rate info.
	 *
	 * @param float  $amount   Payment amount (0 for calculation mode).
	 * @param string $currency Currency code.
	 * @param string $provider Provider type: 'coinsnap', 'bitcoin', or 'lightning'.
	 * @param string $mode     'invoice' to validate amount, 'calculation' to just get min/rate.
	 * @return array { result: bool, error?: string, min_value?: float, rate?: float }
	 */
	public static function check_payment_data( float $amount, string $currency, string $provider = 'coinsnap', string $mode = 'invoice' ): array {
		$rates = self::load_rates();

		if ( ! $rates['result'] ) {
			return array( 'result' => false, 'error' => $rates['error'], 'min_value' => '' );
		}

		$currency_lower = strtolower( $currency );
		if ( ! isset( $rates['data'][ $currency_lower ] ) || $rates['data'][ $currency_lower ]['value'] <= 0 ) {
			return array( 'result' => false, 'error' => 'currencyError', 'min_value' => '' );
		}

		$rate = 1 / $rates['data'][ $currency_lower ]['value'];

		// BTCPay on-chain or lightning.
		if ( 'bitcoin' === $provider || 'lightning' === $provider ) {
			$eur_btc        = isset( $rates['data']['eur']['value'] ) ? 1 / $rates['data']['eur']['value'] * 0.50 : 0.000005;
			$min_value_btc  = ( 'bitcoin' === $provider ) ? $eur_btc : 0.0000001;
			$min_value      = $min_value_btc / $rate;

			if ( 'calculation' === $mode ) {
				return array( 'result' => true, 'min_value' => round( $min_value, 2 ), 'rate' => $rate );
			}

			if ( round( $amount * $rate * 1000000 ) < round( $min_value_btc * 1000000 ) ) {
				return array( 'result' => false, 'error' => 'amountError', 'min_value' => round( $min_value, 2 ) );
			}

			return array( 'result' => true, 'rate' => $rate );
		}

		// Coinsnap provider.
		$supported = self::get_currencies();
		if ( ! is_array( $supported ) || ! in_array( $currency, $supported, true ) ) {
			return array( 'result' => false, 'error' => 'currencyError', 'min_value' => '' );
		}

		$min_values = array( 'SATS' => 1, 'JPY' => 1, 'RUB' => 1, 'BTC' => 0.000001 );
		$min_value  = $min_values[ $currency ] ?? 0.01;

		if ( 'calculation' === $mode ) {
			return array( 'result' => true, 'min_value' => $min_value );
		}

		if ( null === $amount || 0 === $amount ) {
			return array( 'result' => false, 'error' => 'amountError' );
		}

		if ( $amount < $min_value ) {
			return array( 'result' => false, 'error' => 'amountError', 'min_value' => $min_value );
		}

		return array( 'result' => true, 'rate' => $rate );
	}
}
