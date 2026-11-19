<?php
/**
 * Invoice orderId/metadata test, run inside a throwaway WordPress with the plugin active:
 *   wp eval-file wp-content/plugins/coinsnap-paywall/tests/invoice-metadata-test.php
 *
 * For Coinsnap and BTCPay Server: starts a paywall payment, captures the create-invoice request
 * (orderId, metadata), then marks the invoice Settled and checks the visitor's status check grants
 * access once. The API is faked with pre_http_request; nothing leaves the site.
 * It overwrites this plugin's settings: never run it on a real site.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['t_fail']     = 0;
$GLOBALS['t_sent']     = null;
$GLOBALS['t_invoices'] = array();
function t_ok( $name, $cond ) {
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	$GLOBALS['t_fail'] += $cond ? 0 : 1;
}
function t_resp( $code, $body ) {
	return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => wp_json_encode( $body ), 'headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ), 'cookies' => array() );
}

// Fake API: rates, BTCPay payment methods, create invoice, get invoice.
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$method = strtoupper( $args['method'] ?? 'GET' );
	if ( false !== strpos( $url, 'api.coingecko.com' ) ) {
		return t_resp( 200, array( 'rates' => array( 'btc' => array( 'value' => 1 ), 'eur' => array( 'value' => 50000 ) ) ) );
	}
	if ( preg_match( '#/payment-methods$#', $url ) && false === strpos( $url, '/invoices/' ) ) {
		return t_resp( 200, array( array( 'enabled' => true, 'paymentMethodId' => 'BTC-LN' ) ) );
	}
	if ( 'POST' === $method && preg_match( '#/stores/[^/]+/invoices$#', $url ) ) {
		$body              = json_decode( $args['body'], true );
		$GLOBALS['t_sent'] = $body;
		$id                = 'inv' . wp_generate_password( 12, false, false );
		$meta              = $body['metadata'] ?? array();
		if ( 0 !== strpos( $url, 'https://btc.test' ) ) {
			$meta['orderId'] = $body['orderId'] ?? 'CS-20261119-0001'; // Coinsnap copies the top-level orderId into metadata.
		}
		$GLOBALS['t_invoices'][ $id ] = array( 'id' => $id, 'status' => 'New', 'additionalStatus' => 'None', 'amount' => (string) $body['amount'], 'fiatAmount' => (string) $body['amount'], 'currency' => $body['currency'], 'metadata' => $meta, 'createdAt' => time(), 'checkoutLink' => 'https://pay.test/i/' . $id );
		return t_resp( 200, $GLOBALS['t_invoices'][ $id ] );
	}
	if ( preg_match( '#/invoices/([A-Za-z0-9]+)/payment-methods$#', $url ) ) {
		return t_resp( 200, array() );
	}
	if ( 'GET' === $method && preg_match( '#/invoices/([A-Za-z0-9]+)$#', $url, $m ) && isset( $GLOBALS['t_invoices'][ $m[1] ] ) ) {
		return t_resp( 200, $GLOBALS['t_invoices'][ $m[1] ] );
	}
	return new WP_Error( 'http_request_failed', 'offline test' );
}, 10, 3 );

global $wpdb;
$table = Coinsnap_Paywall_Security::table();

$paywall_id = wp_insert_post( array( 'post_type' => 'paywall-shortcode', 'post_status' => 'publish', 'post_title' => 'Members\' wall' ) );
update_post_meta( $paywall_id, '_coinsnap_paywall_currency', 'EUR' );
update_post_meta( $paywall_id, '_coinsnap_paywall_price', '2.50' );
update_post_meta( $paywall_id, '_coinsnap_paywall_duration', '48' );
$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Alice\'s guide', 'post_content' => "Teaser [paywall_payment id=$paywall_id] Paid part" ) );

foreach ( array( 'coinsnap', 'btcpay' ) as $provider ) {
	update_option( 'coinsnap_paywall_options', array(
		'provider'          => $provider,
		'coinsnap_api_key'  => 'test-key',
		'coinsnap_store_id' => 'STORE1',
		'btcpay_url'        => 'https://btc.test',
		'btcpay_api_key'    => 'test-key',
		'btcpay_store_id'   => 'STORE2',
	) );
	unset( $_COOKIE[ Coinsnap_Paywall_Security::TOKEN_COOKIE ] ); // a new visitor per provider
	$GLOBALS['t_sent'] = null;

	$link = Coinsnap_Paywall_Security::start_payment( $post_id );
	$sent = is_array( $GLOBALS['t_sent'] ) ? $GLOBALS['t_sent'] : array();
	$m    = $sent['metadata'] ?? array();
	$inv  = (string) array_key_last( $GLOBALS['t_invoices'] );
	$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE invoice_id = %s", $inv ), ARRAY_A );
	t_ok( "$provider: invoice created and recorded", is_string( $link ) && false !== strpos( $link, $inv ) && $row && 'pending' === $row['status'] && $provider === $row['provider'] );
	t_ok( "$provider: orderId top level and in metadata", 1 === preg_match( "/^paywall-$paywall_id-$post_id-[0-9a-f]{12}$/", (string) ( $sent['orderId'] ?? '' ) ) && ( $m['orderId'] ?? '' ) === $sent['orderId'] );
	t_ok( "$provider: matching keys unchanged", 'Coinsnap Bitcoin Paywall' === ( $m['type'] ?? '' ) && (string) $paywall_id === ( $m['paywallId'] ?? null ) && (string) $post_id === ( $m['postId'] ?? null ) );
	t_ok( "$provider: item fields", 'Access to: Alice\'s guide' === ( $m['itemDesc'] ?? '' ) && "paywall-$paywall_id" === ( $m['itemCode'] ?? '' )
		&& 'Alice\'s guide' === ( $m['postTitle'] ?? '' ) && 'Members\' wall' === ( $m['paywallTitle'] ?? '' )
		&& get_permalink( $post_id ) === ( $m['pageUrl'] ?? '' ) && 48 === ( $m['durationHours'] ?? 0 ) );
	$li = $m['lineItems'][0] ?? array();
	t_ok( "$provider: lineItems", 1 === count( $m['lineItems'] ?? array() ) && '2.50' === ( $li['total'] ?? '' ) && 'EUR' === ( $li['currency'] ?? '' ) && 1 === ( $li['quantity'] ?? 0 ) );
	t_ok( "$provider: platform fields", 'wordpress' === ( $m['platform'] ?? '' ) && COINSNAP_PAYWALL_VERSION === ( $m['pluginVersion'] ?? '' ) && '' !== ( $m['language'] ?? '' ) );
	t_ok( "$provider: no personal data, no empty values, no secrets", ! isset( $m['buyerEmail'] ) && ! isset( $m['buyerName'] ) && ! isset( $sent['buyerEmail'] )
		&& array() === array_filter( $m, function ( $v ) { return '' === $v || null === $v; } ) && false === strpos( wp_json_encode( $sent ), 'test-key' ) );
	t_ok( "$provider: amount and currency unchanged", '2.50' === (string) ( $sent['amount'] ?? '' ) && 'EUR' === ( $sent['currency'] ?? '' ) );

	// The visitor's status check matches the stored row by invoice ID, paywallId and postId.
	t_ok( "$provider: no access before payment", false === Coinsnap_Paywall_Security::refresh_access( $post_id )['paid'] );
	$GLOBALS['t_invoices'][ $inv ]['status'] = 'Settled';
	t_ok( "$provider: Settled invoice grants access", true === Coinsnap_Paywall_Security::refresh_access( $post_id )['paid'] && Coinsnap_Paywall_Security::has_access( $post_id ) );
	$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE invoice_id = %s AND status = 'paid'", $inv ) );
	t_ok( "$provider: one paid row", 1 === $rows );
}

echo $GLOBALS['t_fail'] ? $GLOBALS['t_fail'] . " failed\n" : "all passed\n";
if ( $GLOBALS['t_fail'] ) {
	exit( 1 );
}
