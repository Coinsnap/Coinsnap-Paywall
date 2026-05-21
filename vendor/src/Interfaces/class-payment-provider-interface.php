<?php
/**
 * Payment provider interface.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Interfaces;

/**
 * Payment providers must implement invoice creation and webhook handling.
 */
interface PaymentProviderInterface {
	/**
	 * Create a payment invoice.
	 *
	 * @param int    $form_id        Form ID.
	 * @param int    $amount         Amount.
	 * @param string $currency       Currency code.
	 * @param array  $invoice_data   Invoice data.
	 * @return array { invoice_id: string, payment_url: string, expires_at?: int }
	 */
	public function create_invoice( int $form_id, int $amount, string $currency, array $invoice_data ): array;

	/**
	 * Validate webhook or callback.
	 *
	 * @param array $request Parsed request body.
	 * @return array { invoice_id: string, paid: bool, metadata: array }
	 */
	public function handle_webhook( array $request ): array;

	/**
	 * Check invoice payment status.
	 *
	 * @param string $invoice_id Invoice ID to check.
	 * @return array { invoice_id: string, paid: bool, status: string, metadata: array }
	 */
	public function check_invoice_status( string $invoice_id ): array;

	/**
	 * Check webhook.
	 *
	 * @return bool
	 */
	public function check_webhook(): bool;

	/**
	 * Webhook registration.
	 *
	 * @return array
	 */
	public function register_webhook(): array;

	/**
	 * Getting Store data.
	 *
	 * @return array
	 */
	public function get_store(): array;
}
