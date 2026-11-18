<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side payment and access security for Coinsnap Bitcoin Paywall.
 *
 * Follows the Coinsnap Plugin Security Guide:
 * - the price, currency and duration of an invoice come from the paywall saved on the server;
 * - access is granted only after the Coinsnap or BTCPay API confirms the invoice the plugin
 *   created and stored itself, never because the browser says so;
 * - the browser holds only a random, unguessable access token (stored hashed);
 * - each invoice grants access once.
 */
class Coinsnap_Paywall_Security {

	const OPTIONS       = 'coinsnap_paywall_options';
	const DB_VERSION    = '2';
	const DB_OPTION     = 'coinsnap_paywall_db_version';
	const UPGRADED_AT   = 'coinsnap_paywall_security_upgraded_at';
	const STATE_KEY     = 'coinsnap_paywall_btcpay_state';
	const NOTICE_KEY    = 'coinsnap_paywall_btcpay_notice';
	const TOKEN_COOKIE  = 'coinsnap_paywall_token';
	const LEGACY_COOKIE = 'coinsnap_initiated_';
	const LEGACY_WINDOW = DAY_IN_SECONDS;
	const INVOICE_TYPE  = 'Coinsnap Bitcoin Paywall';

	/** Permissions the plugin asks BTCPay for: create and read invoices, read store settings for the connection check. */
	const BTCPAY_PERMISSIONS = [
		'btcpay.store.cancreateinvoice',
		'btcpay.store.canviewinvoices',
		'btcpay.store.canviewstoresettings',
	];

	/* ---------------------------------------------------------------------
	 * Database
	 * ------------------------------------------------------------------- */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'coinsnap_paywall_invoices';
	}

	/** One row per invoice the plugin created. invoice_id is the primary key, so an invoice is acted on once. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
  invoice_id varchar(64) NOT NULL,
  paywall_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  provider varchar(16) NOT NULL,
  amount varchar(32) NOT NULL,
  currency varchar(8) NOT NULL,
  duration_hours int(10) unsigned NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  paid_at datetime DEFAULT NULL,
  access_expires datetime DEFAULT NULL,
  PRIMARY KEY  (invoice_id),
  KEY token_post (token_hash,post_id)
) {$charset};" );
		update_option( self::DB_OPTION, self::DB_VERSION );
		if ( ! get_option( self::UPGRADED_AT ) ) {
			update_option( self::UPGRADED_AT, time(), false );
		}
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/* ---------------------------------------------------------------------
	 * Configuration
	 * ------------------------------------------------------------------- */

	public static function config(): array {
		$o        = (array) get_option( self::OPTIONS, [] );
		$provider = ( ( $o['provider'] ?? '' ) === 'btcpay' ) ? 'btcpay' : 'coinsnap';
		if ( $provider === 'btcpay' ) {
			return [
				'provider' => 'btcpay',
				'url'      => rtrim( (string) ( $o['btcpay_url'] ?? '' ), '/' ),
				'store_id' => (string) ( $o['btcpay_store_id'] ?? '' ),
				'api_key'  => (string) ( $o['btcpay_api_key'] ?? '' ),
			];
		}
		return [
			'provider' => 'coinsnap',
			'url'      => rtrim( COINSNAP_SERVER_URL, '/' ),
			'store_id' => (string) ( $o['coinsnap_store_id'] ?? '' ),
			'api_key'  => (string) ( $o['coinsnap_api_key'] ?? '' ),
		];
	}

	public static function is_configured( array $cfg ): bool {
		return $cfg['url'] !== '' && $cfg['store_id'] !== '' && $cfg['api_key'] !== '';
	}

	/* ---------------------------------------------------------------------
	 * API calls (keys never leave the server)
	 * ------------------------------------------------------------------- */

	private static function headers( array $cfg ): array {
		$h = [ 'Accept' => 'application/json', 'Content-Type' => 'application/json' ];
		if ( $cfg['provider'] === 'btcpay' ) {
			$h['Authorization'] = 'token ' . $cfg['api_key'];
		} else {
			$h['x-api-key'] = $cfg['api_key'];
		}
		return $h;
	}

	/** @return array{code:int, body:mixed} code 0 = network error */
	private static function api( array $cfg, string $method, string $path, ?array $body = null ): array {
		$args = [ 'method' => $method, 'timeout' => 15, 'headers' => self::headers( $cfg ) ];
		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $cfg['url'] . '/api/v1/stores/' . rawurlencode( $cfg['store_id'] ) . $path, $args );
		if ( is_wp_error( $response ) ) {
			return [ 'code' => 0, 'body' => null ];
		}
		return [
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => json_decode( (string) wp_remote_retrieve_body( $response ), true ),
		];
	}

	/**
	 * Returns the invoice, null if it doesn't exist, or throws when the API can't be reached.
	 * The three outcomes are kept apart: an API error is never "not found".
	 */
	public static function fetch_invoice( array $cfg, string $invoiceId ): ?array {
		$r = self::api( $cfg, 'GET', '/invoices/' . rawurlencode( $invoiceId ) );
		if ( $r['code'] === 0 || $r['code'] >= 500 ) {
			throw new RuntimeException( 'API unavailable' );
		}
		if ( $r['code'] !== 200 ) {
			return null; // Coinsnap 400 / BTCPay 404
		}
		return is_array( $r['body'] ) ? $r['body'] : null;
	}

	/* ---------------------------------------------------------------------
	 * Paywalls: price, currency and duration come from the server only
	 * ------------------------------------------------------------------- */

	/**
	 * The paywall that protects a post: the first [paywall_payment id="..."] in its content,
	 * the same one restrict_page_content() cuts the content at.
	 * Returns null if the post isn't public or has no valid paywall.
	 */
	public static function paywall_for_post( int $postId ): ?array {
		$post = $postId > 0 ? get_post( $postId ) : null;
		if ( ! $post || ! is_post_publicly_viewable( $post ) || post_password_required( $post ) ) {
			return null;
		}
		if ( ! preg_match( '/' . get_shortcode_regex( [ 'paywall_payment' ] ) . '/', (string) $post->post_content, $m ) ) {
			return null;
		}
		$atts      = shortcode_parse_atts( $m[3] ?? '' );
		$paywallId = is_array( $atts ) ? absint( $atts['id'] ?? 0 ) : 0;
		$paywall   = $paywallId > 0 ? get_post( $paywallId ) : null;
		if ( ! $paywall || $paywall->post_type !== 'paywall-shortcode' || $paywall->post_status !== 'publish' ) {
			return null;
		}
		$currency = (string) get_post_meta( $paywallId, '_coinsnap_paywall_currency', true );
		$currency = $currency !== '' ? $currency : 'SATS';
		$price    = self::normalize_amount( get_post_meta( $paywallId, '_coinsnap_paywall_price', true ), $currency );
		$duration = (int) get_post_meta( $paywallId, '_coinsnap_paywall_duration', true );
		if ( $price === null || ! in_array( $currency, COINSNAP_CURRENCIES, true ) ) {
			return null;
		}
		return [
			'paywall_id' => $paywallId,
			'post_id'    => (int) $post->ID,
			'price'      => $price,
			'currency'   => $currency,
			'duration'   => $duration > 0 ? $duration : 24,
		];
	}

	/** Number of decimal places compared for a currency. */
	public static function scale( string $currency ): int {
		$c = strtoupper( $currency );
		if ( $c === 'BTC' ) {
			return 8;
		}
		return in_array( $c, [ 'SATS', 'JPY' ], true ) ? 0 : 2;
	}

	/**
	 * A positive amount as a fixed-point string for the currency, or null.
	 * A price of 0, a negative or a non-numeric amount is never payable.
	 */
	public static function normalize_amount( $value, string $currency ): ?string {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
			return null;
		}
		$s = trim( (string) $value );
		// Plain decimals, or the exponent form PHP uses when a small float is saved as post meta (1.0E-5).
		if ( ! preg_match( '/^\d+(?:\.\d+)?(?:[eE]-?\d+)?$/', $s ) ) {
			return null;
		}
		$f = (float) $s;
		if ( ! is_finite( $f ) || $f <= 0 || $f > 1e12 ) {
			return null;
		}
		$n = number_format( $f, self::scale( $currency ), '.', '' );
		return ( (float) $n > 0 ) ? $n : null;
	}

	/** The amount in the currency's smallest compared unit, as an integer. */
	public static function to_units( $value, string $currency ): ?int {
		$n = self::normalize_amount( $value, $currency );
		if ( $n === null ) {
			return null;
		}
		return (int) str_replace( '.', '', $n );
	}

	/**
	 * Paid only when the API says Settled, the currency is the one the plugin asked for,
	 * and the amount is the one the plugin recorded (1 unit tolerance for rounding).
	 * BTCPay invoices marked settled by hand (Marked) are refused.
	 */
	public static function invoice_pays( array $invoice, string $expectedAmount, string $currency, bool $isBtcpay ): bool {
		$expected = self::to_units( $expectedAmount, $currency );
		if ( $expected === null || $expected <= 0 || ( $invoice['status'] ?? '' ) !== 'Settled' ) {
			return false;
		}
		if ( $isBtcpay && ( $invoice['additionalStatus'] ?? '' ) === 'Marked' ) {
			return false;
		}
		if ( strtoupper( (string) ( $invoice['currency'] ?? '' ) ) !== strtoupper( $currency ) ) {
			return false;
		}
		// Coinsnap: amount is in sats, fiatAmount in the invoice currency. BTCPay: amount is in the invoice currency.
		$field = ( $isBtcpay || strtoupper( $currency ) === 'SATS' ) ? 'amount' : 'fiatAmount';
		$paid  = self::to_units( $invoice[ $field ] ?? '', $currency );
		return $paid !== null && abs( $paid - $expected ) <= 1;
	}

	/* ---------------------------------------------------------------------
	 * Visitor access token: random, unguessable, stored only as a hash
	 * ------------------------------------------------------------------- */

	public static function read_token(): string {
		$t = isset( $_COOKIE[ self::TOKEN_COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::TOKEN_COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return preg_match( '/^[a-f0-9]{64}$/', $t ) ? $t : '';
	}

	public static function token_hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/** Reuse the visitor's token or create one, and (re)send the cookie for at least $seconds. */
	public static function ensure_token( int $seconds ): string {
		$token = self::read_token();
		if ( $token === '' ) {
			$token = bin2hex( random_bytes( 32 ) );
		}
		if ( ! headers_sent() ) {
			setcookie( self::TOKEN_COOKIE, $token, [
				'expires'  => time() + max( 30 * DAY_IN_SECONDS, $seconds ),
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			] );
		}
		$_COOKIE[ self::TOKEN_COOKIE ] = $token;
		return $token;
	}

	/** Access comes only from a paid row the server recorded for this visitor's token and this post. */
	public static function has_access( int $postId ): bool {
		$token = self::read_token();
		if ( $token === '' || $postId <= 0 ) {
			return false;
		}
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM %i WHERE token_hash = %s AND post_id = %d AND status = %s AND access_expires > %s LIMIT 1',
			self::table(), self::token_hash( $token ), $postId, 'paid', current_time( 'mysql', true )
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $found !== null;
	}

	/* ---------------------------------------------------------------------
	 * Starting a payment
	 * ------------------------------------------------------------------- */

	/**
	 * Create an invoice for the paywall of $postId from server-side data only and record it.
	 * Returns the checkout link, or null.
	 */
	public static function start_payment( int $postId ): ?string {
		$paywall = self::paywall_for_post( $postId );
		$cfg     = self::config();
		if ( $paywall === null || ! self::is_configured( $cfg ) ) {
			return null;
		}
		$orderId = 'paywall-' . $paywall['paywall_id'] . '-' . $paywall['post_id'] . '-' . bin2hex( random_bytes( 6 ) );
		$amount  = $paywall['price'];
		$fields  = [
			'amount'   => $amount,
			'currency' => $paywall['currency'],
			'orderId'  => $orderId,
			'metadata' => [
				'type'      => self::INVOICE_TYPE,
				'orderId'   => $orderId,
				'paywallId' => (string) $paywall['paywall_id'],
				'postId'    => (string) $paywall['post_id'],
			],
		];
		$redirect = (string) get_permalink( $paywall['post_id'] );
		if ( $cfg['provider'] === 'btcpay' ) {
			$fields['checkout'] = [ 'redirectURL' => $redirect, 'redirectAutomatically' => true ];
		} else {
			$fields['redirectUrl']           = $redirect;
			$fields['redirectAutomatically'] = true;
			$fields['referralCode']          = COINSNAP_PAYWALL_REFERRAL_CODE;
		}

		$r   = self::api( $cfg, 'POST', '/invoices', $fields );
		$inv = $r['body'];
		if ( $r['code'] !== 200 || ! is_array( $inv ) || ! is_string( $inv['id'] ?? null ) || ! is_string( $inv['checkoutLink'] ?? null ) ) {
			self::log( 'info', 'invoice create failed' );
			return null;
		}

		$token = self::ensure_token( $paywall['duration'] * HOUR_IN_SECONDS + DAY_IN_SECONDS );
		global $wpdb;
		$ok = $wpdb->insert( self::table(), [
			'invoice_id'     => substr( $inv['id'], 0, 64 ),
			'paywall_id'     => $paywall['paywall_id'],
			'post_id'        => $paywall['post_id'],
			'token_hash'     => self::token_hash( $token ),
			'provider'       => $cfg['provider'],
			'amount'         => $amount,
			'currency'       => $paywall['currency'],
			'duration_hours' => $paywall['duration'],
			'status'         => 'pending',
			'created_at'     => current_time( 'mysql', true ),
		] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $ok === false ) {
			self::log( 'info', 'invoice record failed', $inv['id'] );
			return null;
		}
		return $inv['checkoutLink'];
	}

	/* ---------------------------------------------------------------------
	 * Confirming a payment: ask the API about invoices the server stored
	 * ------------------------------------------------------------------- */

	/**
	 * Check the visitor's own pending invoices for this post with the API.
	 * Returns ['paid' => bool, 'pending' => bool]. The browser only learns the result.
	 */
	public static function refresh_access( int $postId ): array {
		if ( self::has_access( $postId ) ) {
			return [ 'paid' => true, 'pending' => false ];
		}
		self::claim_legacy_invoice( $postId );
		if ( self::has_access( $postId ) ) {
			return [ 'paid' => true, 'pending' => false ];
		}
		$token = self::read_token();
		if ( $token === '' ) {
			return [ 'paid' => false, 'pending' => false ];
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE token_hash = %s AND post_id = %d AND status = %s AND created_at > %s ORDER BY created_at DESC LIMIT 3',
			self::table(), self::token_hash( $token ), $postId, 'pending', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS )
		), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$pending = false;
		foreach ( (array) $rows as $row ) {
			$result = self::confirm_invoice( $row );
			if ( $result === 'paid' ) {
				return [ 'paid' => true, 'pending' => false ];
			}
			$pending = $pending || $result === 'pending';
		}
		return [ 'paid' => false, 'pending' => $pending ];
	}

	/** @return string paid|pending|closed */
	private static function confirm_invoice( array $row ): string {
		$cfg = self::config();
		if ( $cfg['provider'] !== $row['provider'] || ! self::is_configured( $cfg ) ) {
			return 'pending';
		}
		try {
			$invoice = self::fetch_invoice( $cfg, (string) $row['invoice_id'] );
		} catch ( RuntimeException $e ) {
			self::log( 'info', 'retry: API unavailable', (string) $row['invoice_id'] );
			return 'pending';
		}
		if ( $invoice === null ) {
			self::log( 'info', 'unknown invoice', (string) $row['invoice_id'] );
			self::set_status( (string) $row['invoice_id'], 'invalid' );
			return 'closed';
		}
		$meta = is_array( $invoice['metadata'] ?? null ) ? $invoice['metadata'] : [];
		if ( (string) ( $meta['paywallId'] ?? '' ) !== (string) $row['paywall_id'] || (string) ( $meta['postId'] ?? '' ) !== (string) $row['post_id'] ) {
			self::log( 'security', 'not ours', (string) $row['invoice_id'] );
			self::set_status( (string) $row['invoice_id'], 'invalid' );
			return 'closed';
		}
		if ( self::invoice_pays( $invoice, (string) $row['amount'], (string) $row['currency'], $cfg['provider'] === 'btcpay' ) ) {
			self::mark_paid_once( (string) $row['invoice_id'], (int) $row['duration_hours'] );
			return 'paid';
		}
		$status = (string) ( $invoice['status'] ?? '' );
		if ( $status === 'Settled' ) {
			self::log( 'security', 'amount mismatch', (string) $row['invoice_id'] );
			self::set_status( (string) $row['invoice_id'], 'invalid' );
			return 'closed';
		}
		if ( in_array( $status, [ 'Expired', 'Invalid' ], true ) ) {
			self::log( 'info', 'status ' . strtolower( $status ), (string) $row['invoice_id'] );
			self::set_status( (string) $row['invoice_id'], 'expired' );
			return 'closed';
		}
		return 'pending'; // New, Processing or unknown: not paid yet
	}

	/** Only a pending row becomes paid; the WHERE clause makes it happen once. */
	private static function mark_paid_once( string $invoiceId, int $durationHours ): bool {
		global $wpdb;
		$now     = time();
		$updated = $wpdb->query( $wpdb->prepare(
			'UPDATE %i SET status = %s, paid_at = %s, access_expires = %s WHERE invoice_id = %s AND status = %s',
			self::table(), 'paid', gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now + max( 1, $durationHours ) * HOUR_IN_SECONDS ), $invoiceId, 'pending'
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $updated === 1 ) {
			self::log( 'info', 'access granted', $invoiceId );
			return true;
		}
		return false;
	}

	/** Never moves a paid row. */
	private static function set_status( string $invoiceId, string $status ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'UPDATE %i SET status = %s WHERE invoice_id = %s AND status = %s',
			self::table(), $status, $invoiceId, 'pending'
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ---------------------------------------------------------------------
	 * Invoices started by the old version (guide 6.7)
	 * ------------------------------------------------------------------- */

	/**
	 * The old version kept the invoice ID only in a browser cookie. For one day after the
	 * upgrade, such an invoice is accepted once if the API says it is Settled for the
	 * paywall's current price and currency. The cookie is read as JSON, never unserialized.
	 */
	public static function claim_legacy_invoice( int $postId ): void {
		$name = self::LEGACY_COOKIE . $postId;
		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return;
		}
		$raw = (string) wp_unslash( $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! headers_sent() ) {
			setcookie( $name, '', time() - 3600, '/' );
		}
		$upgradedAt = (int) get_option( self::UPGRADED_AT, 0 );
		if ( $upgradedAt <= 0 || time() > $upgradedAt + self::LEGACY_WINDOW ) {
			return;
		}
		$data      = json_decode( $raw, true );
		$invoiceId = is_array( $data ) && is_string( $data['invoice_id'] ?? null ) ? $data['invoice_id'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $invoiceId ) ) {
			return;
		}
		$paywall = self::paywall_for_post( $postId );
		$cfg     = self::config();
		if ( $paywall === null || ! self::is_configured( $cfg ) ) {
			return;
		}
		try {
			$invoice = self::fetch_invoice( $cfg, $invoiceId );
		} catch ( RuntimeException $e ) {
			self::log( 'info', 'retry: API unavailable', $invoiceId );
			return;
		}
		if ( $invoice === null ) {
			return;
		}
		$isBtcpay = $cfg['provider'] === 'btcpay';
		$meta     = is_array( $invoice['metadata'] ?? null ) ? $invoice['metadata'] : [];
		// Old Coinsnap invoices carry metadata.type; old BTCPay invoices had no metadata.
		if ( ! $isBtcpay && ( $meta['type'] ?? '' ) !== self::INVOICE_TYPE ) {
			return;
		}
		if ( isset( $meta['paywallId'] ) || ! self::invoice_pays( $invoice, $paywall['price'], $paywall['currency'], $isBtcpay ) ) {
			return; // new-style invoices are handled from their own record
		}
		$token = self::ensure_token( $paywall['duration'] * HOUR_IN_SECONDS + DAY_IN_SECONDS );
		global $wpdb;
		$now = time();
		// The primary key on invoice_id lets one invoice be claimed once, by one visitor.
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO %i (invoice_id, paywall_id, post_id, token_hash, provider, amount, currency, duration_hours, status, created_at, paid_at, access_expires) VALUES (%s, %d, %d, %s, %s, %s, %s, %d, %s, %s, %s, %s)',
			self::table(), $invoiceId, $paywall['paywall_id'], $paywall['post_id'], self::token_hash( $token ), $cfg['provider'],
			$paywall['price'], $paywall['currency'], $paywall['duration'], 'paid',
			gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now + $paywall['duration'] * HOUR_IN_SECONDS )
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( (int) $wpdb->rows_affected === 1 ) {
			self::log( 'info', 'legacy invoice accepted', $invoiceId );
		}
	}

	/* ---------------------------------------------------------------------
	 * BTCPay connect callback and key permissions
	 * ------------------------------------------------------------------- */

	/** Called from the admin AJAX handler, after capability and nonce checks. */
	public static function btcpay_start_state(): string {
		$state = bin2hex( random_bytes( 32 ) );
		set_transient( self::STATE_KEY, hash( 'sha256', $state ), 10 * MINUTE_IN_SECONDS );
		return $state;
	}

	/** One use, even if the check fails. */
	public static function btcpay_consume_state( string $state ): bool {
		$expected = get_transient( self::STATE_KEY );
		delete_transient( self::STATE_KEY );
		return is_string( $expected ) && $state !== '' && hash_equals( $expected, hash( 'sha256', $state ) );
	}

	/** Ask BTCPay what the key can do; never trust permissions from the POST. */
	public static function btcpay_key_permissions( string $btcpayUrl, string $apiKey ): ?array {
		if ( $btcpayUrl === '' || $apiKey === '' ) {
			return null;
		}
		$response = wp_remote_get( rtrim( $btcpayUrl, '/' ) . '/api/v1/api-keys/current', [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'token ' . $apiKey, 'Accept' => 'application/json' ],
		] );
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}
		$key = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return ( is_array( $key ) && is_array( $key['permissions'] ?? null ) ) ? array_values( $key['permissions'] ) : null;
	}

	public static function btcpay_key_store( array $permissions ): string {
		$first = explode( ':', (string) ( $permissions[0] ?? '' ), 2 );
		return $first[1] ?? '';
	}

	/** Every permission is a store permission for this one store, and the needed ones are there. */
	public static function btcpay_key_is_acceptable( array $permissions, string $storeId ): bool {
		if ( $permissions === [] || $storeId === '' ) {
			return false;
		}
		foreach ( $permissions as $permission ) {
			[ $policy, $scope ] = array_pad( explode( ':', (string) $permission, 2 ), 2, '' );
			if ( strpos( $policy, 'btcpay.store.' ) !== 0 || $scope !== $storeId ) {
				return false;
			}
		}
		foreach ( [ 'btcpay.store.cancreateinvoice', 'btcpay.store.canviewinvoices' ] as $needed ) {
			if ( ! in_array( $needed . ':' . $storeId, $permissions, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The BTCPay connect callback. Saves the key only with a valid one-time state and only
	 * if BTCPay says the key is limited to one store. The server URL comes from the saved settings.
	 */
	public static function btcpay_callback( string $state, string $apiKey ): bool {
		if ( ! self::btcpay_consume_state( $state ) ) {
			self::log( 'security', 'btcpay callback bad state' );
			return false;
		}
		$o           = (array) get_option( self::OPTIONS, [] );
		$btcpayUrl   = rtrim( (string) ( $o['btcpay_url'] ?? '' ), '/' );
		$permissions = self::btcpay_key_permissions( $btcpayUrl, $apiKey );
		if ( $permissions === null ) {
			self::log( 'info', 'btcpay callback key check failed' );
			return false;
		}
		$storeId = self::btcpay_key_store( $permissions );
		if ( ! self::btcpay_key_is_acceptable( $permissions, $storeId ) ) {
			self::log( 'security', 'btcpay callback key refused' );
			return false;
		}
		$o['btcpay_api_key']  = $apiKey;
		$o['btcpay_store_id'] = $storeId;
		$o['provider']        = 'btcpay';
		self::save_options_raw( $o );
		return true;
	}

	/** Write the options without the settings-form sanitizer (which keeps stored keys on empty fields). */
	public static function save_options_raw( array $o ): void {
		$s = $GLOBALS['coinsnap_paywall_settings'] ?? null;
		if ( $s ) {
			remove_filter( 'sanitize_option_' . self::OPTIONS, [ $s, 'sanitize_options' ] );
		}
		update_option( self::OPTIONS, $o );
		if ( $s ) {
			add_filter( 'sanitize_option_' . self::OPTIONS, [ $s, 'sanitize_options' ] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Settings display and saving
	 * ------------------------------------------------------------------- */

	public static function secret_hint( string $value ): string {
		return $value === '' ? '' : '••••••••' . substr( $value, -4 );
	}

	/**
	 * Merge a settings-form submission into the stored options.
	 * An empty key field keeps the stored key. A new BTCPay URL drops the stored BTCPay key
	 * and store ID unless a new key is submitted with it (6.14).
	 */
	public static function merge_settings( array $stored, array $submitted ): array {
		$out = $stored;
		foreach ( [ 'provider', 'coinsnap_store_id', 'btcpay_store_id' ] as $f ) {
			if ( isset( $submitted[ $f ] ) ) {
				$out[ $f ] = sanitize_text_field( (string) $submitted[ $f ] );
			}
		}
		if ( isset( $out['provider'] ) && ! in_array( $out['provider'], [ 'coinsnap', 'btcpay' ], true ) ) {
			$out['provider'] = 'coinsnap';
		}
		foreach ( [ 'coinsnap_api_key', 'btcpay_api_key' ] as $f ) {
			$v = trim( sanitize_text_field( (string) ( $submitted[ $f ] ?? '' ) ) );
			if ( $v !== '' ) {
				$out[ $f ] = $v;
			}
		}
		if ( isset( $submitted['btcpay_url'] ) ) {
			$newUrl = rtrim( esc_url_raw( trim( (string) $submitted['btcpay_url'] ) ), '/' );
			$oldUrl = rtrim( (string) ( $stored['btcpay_url'] ?? '' ), '/' );
			$newKey = trim( (string) ( $submitted['btcpay_api_key'] ?? '' ) );
			if ( $newUrl !== $oldUrl && $newKey === '' ) {
				$out['btcpay_api_key']  = '';
				$out['btcpay_store_id'] = '';
			}
			$out['btcpay_url'] = $newUrl;
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Logs: a fixed reason and the invoice ID, nothing else (5, 6.13)
	 * ------------------------------------------------------------------- */

	public static function log( string $level, string $reason, string $invoiceId = '' ): void {
		if ( $level === 'security' && ! self::security_log_allowed() ) {
			return;
		}
		$id = substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $invoiceId ), 0, 64 );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[coinsnap-paywall] %s reason=%s invoice=%s', strtoupper( $level ), $reason, $id !== '' ? $id : '-' ) );
	}

	private static function security_log_allowed(): bool {
		$key   = 'coinsnap_paywall_seclog_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 2 * MINUTE_IN_SECONDS );
		if ( $count === 11 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[coinsnap-paywall] SECURITY more rejections this minute, not logged' );
		}
		return $count <= 10;
	}
}
