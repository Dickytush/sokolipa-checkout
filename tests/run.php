<?php
/**
 * Unit tests for the parts of Sokolipa Checkout that do not need WordPress:
 * phone formats, callback parsing, request signing, amount formatting, which
 * networks a visitor is offered, and how orders are confirmed.
 *
 * Run: php tests/run.php   (exit code 0 = all passed)
 *
 * @package Sokolipa
 */

// phpcs:disable -- test scaffolding, not shipped with the plugin.

require __DIR__ . '/bootstrap.php';

use Sokolipa\Orders;
use Sokolipa\Phone;
use Sokolipa\Networks\Airtel;
use Sokolipa\Networks\CallbackHint;
use Sokolipa\Networks\Mpesa;
use Sokolipa\Networks\Mtn;
use Sokolipa\Networks\Network;
use Sokolipa\Networks\Registry;
use Sokolipa\Networks\StatusResult;

$failures = 0;
$passes   = 0;

function check( $name, $condition ) {
	global $failures, $passes;
	if ( $condition ) {
		$passes++;
		echo "  ok   {$name}\n";
		return;
	}
	$failures++;
	echo "  FAIL {$name}\n";
}

function group( $title ) {
	echo "\n{$title}\n";
}

/* ------------------------------------------------------------------ */
group( 'Phone numbers' );

check( 'KE local 0712 345 678', '254712345678' === Phone::normalize( '0712 345 678', 'KE' ) );
check( 'KE +254 with spaces', '254712345678' === Phone::normalize( '+254 712 345 678', 'KE' ) );
check( 'KE 254 prefix', '254712345678' === Phone::normalize( '254712345678', 'KE' ) );
check( 'KE 00254 prefix', '254712345678' === Phone::normalize( '00254712345678', 'KE' ) );
check( 'KE without leading zero', '254712345678' === Phone::normalize( '712345678', 'KE' ) );
check( 'KE new 01xx range', '254110123456' === Phone::normalize( '0110 123 456', 'KE' ) );
check( 'KE too short is rejected', '' === Phone::normalize( '0712345', 'KE' ) );
check( 'KE garbage is rejected', '' === Phone::normalize( '12345', 'KE' ) );
check( 'empty input is rejected', '' === Phone::normalize( '', 'KE' ) );
check( 'unknown country is rejected', '' === Phone::normalize( '0712345678', 'XX' ) );
check( 'UG 0772', '256772123456' === Phone::normalize( '0772123456', 'UG' ) );
check( 'NG 10-digit national', '2348031234567' === Phone::normalize( '08031234567', 'NG' ) );
check( 'CI keeps its leading zero', '2250708123456' === Phone::normalize( '0708123456', 'CI' ) );
check( 'CI +225 with spaces', '2250708123456' === Phone::normalize( '+225 07 08 12 34 56', 'CI' ) );
check( 'BJ old 8-digit', '22997123456' === Phone::normalize( '97123456', 'BJ' ) );
check( 'BJ new 10-digit', '2290197123456' === Phone::normalize( '0197123456', 'BJ' ) );
check( 'national part for Airtel', '772123456' === Phone::national( '256772123456', 'UG' ) );
check( 'mask hides the middle', '2547*****678' === Phone::mask( '254712345678' ) );
check( 'dial code lookup', '255' === Phone::dial_code( 'tz' ) );

/* ------------------------------------------------------------------ */
group( 'M-Pesa (Daraja)' );

$mpesa = new Mpesa();
$hint  = $mpesa->parse_callback(
	new WP_REST_Request(
		array(
			'Body' => array(
				'stkCallback' => array(
					'MerchantRequestID' => 'm1',
					'CheckoutRequestID' => 'ws_CO_1',
					'ResultCode'        => 0,
					'ResultDesc'        => 'The service request is processed successfully.',
					'CallbackMetadata'  => array(
						'Item' => array(
							array( 'Name' => 'Amount', 'Value' => 10 ),
							array( 'Name' => 'MpesaReceiptNumber', 'Value' => 'RKT123ABC' ),
						),
					),
				),
			),
		)
	)
);
check( 'callback reference', 'ws_CO_1' === $hint->provider_ref );
check( 'callback receipt', 'RKT123ABC' === $hint->receipt );
check( 'callback claims paid', StatusResult::PAID === $hint->claimed );

$cancelled = $mpesa->parse_callback( new WP_REST_Request( array( 'Body' => array( 'stkCallback' => array( 'CheckoutRequestID' => 'ws_CO_2', 'ResultCode' => 1032, 'ResultDesc' => 'Request cancelled by user' ) ) ) ) );
check( 'cancelled callback claims failed', StatusResult::FAILED === $cancelled->claimed && '' === $cancelled->receipt );
check( 'unrelated body is ignored', null === $mpesa->parse_callback( new WP_REST_Request( array( 'x' => 1 ) ) ) );
check( 'acknowledgement body', array( 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ) === $mpesa->callback_ack() );
check( 'callback URL avoids the word mpesa', false === stripos( $mpesa->callback_url(), 'mpesa' ) );

$timestamp = sokolipa_test_call( $mpesa, 'timestamp' );
check( 'timestamp is YYYYMMDDHHmmss', (bool) preg_match( '/^\d{14}$/', $timestamp ) );

check( '"still under processing" keeps waiting', true === sokolipa_test_call( $mpesa, 'still_processing', '4999', 'The transaction is still under processing' ) );
check( 'push already in progress keeps waiting', true === sokolipa_test_call( $mpesa, 'still_processing', '1001', 'Unable to lock subscriber' ) );
check( 'timeout 1037 is final', false === sokolipa_test_call( $mpesa, 'still_processing', '1037', 'DS timeout user cannot be reached' ) );
check( 'cancel 1032 is final', false === sokolipa_test_call( $mpesa, 'still_processing', '1032', 'Request cancelled by user' ) );
check( 'wrong PIN 2001 is final', false === sokolipa_test_call( $mpesa, 'still_processing', '2001', 'The initiator information is invalid.' ) );
check( 'timeout reason is buyer-friendly', false !== strpos( sokolipa_test_call( $mpesa, 'result_reason', '1037', 'DS timeout' ), 'did not respond in time' ) );

/* ------------------------------------------------------------------ */
group( 'MTN MoMo' );

$mtn  = new Mtn();
$hint = $mtn->parse_callback( new WP_REST_Request( array( 'externalId' => '55', 'referenceId' => 'abc', 'status' => 'SUCCESSFUL', 'financialTransactionId' => '9876', 'amount' => '10' ) ) );
check( 'callback carries the order id and receipt', 55 === $hint->order_id && StatusResult::PAID === $hint->claimed && '9876' === $hint->receipt );
check( 'markets map to currencies', 'GHS' === Mtn::markets()['mtnghana'][1] );

sokolipa_test_settings( array( 'mtn_env' => 'sandbox', 'mtn_market' => 'mtnghana' ) );
check( 'sandbox charges in EUR', 'EUR' === $mtn->currency() && 'GH' === $mtn->country() );
sokolipa_test_settings( array( 'mtn_env' => 'live', 'mtn_market' => 'mtnghana' ) );
check( 'live charges in the market currency', 'GHS' === $mtn->currency() );
check( 'failure reasons are translated', false !== strpos( sokolipa_test_call( $mtn, 'reason_text', 'NOT_ENOUGH_FUNDS' ), 'balance' ) );

/* ------------------------------------------------------------------ */
group( 'Airtel Money' );

$private_key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
$public_pem  = openssl_pkey_get_details( $private_key )['key'];
$bare_key    = trim( str_replace( array( '-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n" ), '', $public_pem ) );

sokolipa_test_settings( array( 'airtel_country' => 'UG', 'airtel_public_key' => $bare_key ) );
$airtel = new Airtel();
check( 'country and currency', 'UG' === $airtel->country() && 'UGX' === $airtel->currency() );

$body    = array( 'reference' => 'Order 1', 'subscriber' => array( 'country' => 'UG', 'currency' => 'UGX', 'msisdn' => '772123456' ), 'transaction' => array( 'amount' => 1000, 'country' => 'UG', 'currency' => 'UGX', 'id' => 'SK1T1' ) );
$headers = sokolipa_test_call( $airtel, 'signature_headers', $body );
check( 'v2 signature headers present', isset( $headers['x-signature'], $headers['x-key'] ) );
$decrypted = openssl_private_decrypt( base64_decode( $headers['x-key'] ), $key_iv, $private_key, OPENSSL_PKCS1_PADDING );
list( $aes_key, $iv ) = array_map( 'base64_decode', explode( ':', $key_iv ) );
$plain = openssl_decrypt( base64_decode( $headers['x-signature'] ), 'AES-256-CBC', $aes_key, OPENSSL_RAW_DATA, $iv );
check( 'x-key unwraps and x-signature decrypts to the payload', $decrypted && json_encode( $body ) === $plain );

$hint = $airtel->parse_callback( new WP_REST_Request( array( 'transaction' => array( 'id' => 'SK1T1', 'status_code' => 'TS', 'airtel_money_id' => 'AM123', 'message' => 'ok' ) ) ) );
check( 'callback parsing', 'SK1T1' === $hint->provider_ref && StatusResult::PAID === $hint->claimed && 'AM123' === $hint->receipt );

sokolipa_test_settings( array( 'airtel_country' => 'ZZ' ) );
check( 'unknown country falls back to Uganda', 'UG' === $airtel->country() );

/* ------------------------------------------------------------------ */
group( 'Amounts' );

check( 'zero-decimal currencies round up', '1501' === sokolipa_test_call( $mtn, 'amount_for', new WC_Order( 1500.4, 'UGX' ) ) );
check( 'decimal currencies keep two places', '12.50' === sokolipa_test_call( $mtn, 'amount_for', new WC_Order( 12.5, 'GHS' ) ) );

/* ------------------------------------------------------------------ */
group( 'Which networks a visitor sees' );

sokolipa_test_networks( null );
sokolipa_test_settings(
	array(
		'enabled'              => 'yes',
		'airtel_enabled'       => 'yes',
		'airtel_env'           => 'sandbox',
		'airtel_country'       => 'KE',
		'airtel_client_id'     => 'x',
		'airtel_client_secret' => 'y',
		'mpesa_enabled'        => 'yes',
		'mpesa_env'            => 'live',
		'mpesa_consumer_key'   => 'a',
		'mpesa_consumer_secret' => 'b',
		'mpesa_shortcode'      => '600000',
		'mpesa_passkey'        => 'p',
	)
);
$GLOBALS['MANAGER'] = false;
check( 'buyers see live networks only', array( 'mpesa' ) === array_keys( Registry::offered( 'KES' ) ) );
check( 'both count as configured', array( 'mpesa', 'airtel' ) === array_keys( Registry::available( 'KES' ) ) );
$GLOBALS['MANAGER'] = true;
check( 'store managers also see sandbox networks', array( 'mpesa', 'airtel' ) === array_keys( Registry::offered( 'KES' ) ) );
check( 'networks for another currency are hidden', array() === Registry::offered( 'UGX' ) );
$GLOBALS['MANAGER'] = false;

/* ------------------------------------------------------------------ */
group( 'Confirming payments' );

/** A network whose status answers are scripted by the test. */
final class Sokolipa_Fake_Network implements Network {
	public $queries = 0;
	public $answer;
	public function __construct() { $this->answer = new StatusResult( StatusResult::PENDING ); }
	public function id() { return 'fake'; }
	public function label() { return 'Fake Money'; }
	public function country() { return 'KE'; }
	public function currency() { return 'KES'; }
	public function is_configured() { return true; }
	public function is_live() { return true; }
	public function form_fields() { return array(); }
	public function request_push( \WC_Order $order, $msisdn ) { return new \Sokolipa\Networks\PushResult( 'ref-' . $order->get_id(), 'Check your phone.' ); }
	public function query_status( \WC_Order $order ) { $this->queries++; return $this->answer; }
	public function parse_callback( \WP_REST_Request $request ) { return null; }
	public function callback_ack() { return array(); }
}

$fake = new Sokolipa_Fake_Network();
sokolipa_test_networks( array( 'fake' => $fake ) );

$order = new WC_Order( 100, 'KES' );
$order->update_meta_data( Orders::META_NETWORK, 'fake' );
$order->update_meta_data( Orders::META_REF, 'ref-1' );
$order->update_meta_data( Orders::META_STATUS, StatusResult::PENDING );
$order->update_meta_data( Orders::META_STARTED, time() );

Orders::confirm( $order );
check( 'no status query in the first seconds after the prompt', 0 === $fake->queries );

$order->update_meta_data( Orders::META_STARTED, time() - 30 );
Orders::confirm( $order );
check( 'queries once the buyer has had time to approve', 1 === $fake->queries );

Orders::confirm( $order );
check( 'waits between queries', 1 === $fake->queries );

Orders::confirm( $order, true );
check( 'a callback or the poller can force a query', 2 === $fake->queries );

$fake->answer = new StatusResult( StatusResult::PAID, 'RCPT123' );
$order->update_meta_data( Orders::META_LAST_QUERY, time() - 60 );
$result = Orders::confirm( $order );
check( 'paid answer marks the order paid', $order->is_paid() && StatusResult::PAID === $result->status );
check( 'receipt becomes the transaction id', 'RCPT123' === $order->transaction_id && 'RCPT123' === $order->get_meta( Orders::META_RECEIPT ) );

$before = $fake->queries;
Orders::confirm( $order, true );
check( 'paid orders are never queried again', $before === $fake->queries );

$failed = new WC_Order( 100, 'KES' );
$failed->update_meta_data( Orders::META_NETWORK, 'fake' );
$failed->update_meta_data( Orders::META_STARTED, time() - 30 );
$fake->answer = new StatusResult( StatusResult::FAILED, '', 'The payment prompt was cancelled on the phone.' );
Orders::confirm( $failed );
check( 'failed answer fails a pending order', 'failed' === $failed->get_status() && StatusResult::FAILED === $failed->get_meta( Orders::META_STATUS ) );
check( 'the reason is kept for the waiting screen', 'The payment prompt was cancelled on the phone.' === $failed->get_meta( Orders::META_REASON ) );

$callback_order = new WC_Order( 100, 'KES' );
$callback_order->update_meta_data( Orders::META_NETWORK, 'fake' );
$callback_order->update_meta_data( Orders::META_REF, 'ref-cb' );
$callback_order->update_meta_data( Orders::META_STARTED, time() );
$fake->answer = new StatusResult( StatusResult::PENDING );
Orders::apply_callback( $fake, new CallbackHint( 'ref-cb', $callback_order->get_id(), 'RCB1', StatusResult::PAID ) );
check( 'a callback alone never marks an order paid', ! $callback_order->is_paid() );
check( 'a callback still triggers a check with the network', $fake->queries > $before );
$fake->answer = new StatusResult( StatusResult::PAID, '' );
Orders::apply_callback( $fake, new CallbackHint( 'ref-cb', $callback_order->get_id(), 'RCB1', StatusResult::PAID ) );
check( 'paid once the network confirms, with the callback receipt', $callback_order->is_paid() && 'RCB1' === $callback_order->get_meta( Orders::META_RECEIPT ) );

/* ------------------------------------------------------------------ */
group( 'Delivery place' );

$classic = new WC_Order( 1, 'KES' );
$classic->update_meta_data( Orders::META_DELIVERY, 'Kilimani, Nairobi' );
check( 'printed by the plugin for classic-checkout orders', 'Kilimani, Nairobi' === Orders::delivery_place_to_print( $classic ) );
$block = new WC_Order( 1, 'KES' );
$block->update_meta_data( Orders::META_DELIVERY, 'Westlands, Nairobi' );
$block->update_meta_data( '_wc_other/sokolipa/delivery-place', 'Westlands, Nairobi' );
check( 'left to WooCommerce for block-checkout orders', '' === Orders::delivery_place_to_print( $block ) );

/* ------------------------------------------------------------------ */
echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
