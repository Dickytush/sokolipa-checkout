<?php
/**
 * M-Pesa (Safaricom Daraja) adapter: Lipa na M-Pesa Online (STK push).
 *
 * @package Sokolipa
 */

namespace Sokolipa\Networks;

use Sokolipa\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Kenya. Credentials from https://developer.safaricom.co.ke/ (Daraja).
 */
final class Mpesa extends AbstractNetwork {

	const BASE_SANDBOX = 'https://sandbox.safaricom.co.ke';
	const BASE_LIVE    = 'https://api.safaricom.co.ke';

	/**
	 * @inheritDoc
	 */
	public function id() {
		return 'mpesa';
	}

	/**
	 * @inheritDoc
	 */
	public function label() {
		return 'M-Pesa';
	}

	/**
	 * @inheritDoc
	 */
	public function country() {
		return 'KE';
	}

	/**
	 * @inheritDoc
	 */
	public function currency() {
		return 'KES';
	}

	/**
	 * @inheritDoc
	 */
	public function is_configured() {
		return $this->enabled()
			&& '' !== $this->setting( 'consumer_key' )
			&& '' !== $this->setting( 'consumer_secret' )
			&& '' !== $this->setting( 'shortcode' )
			&& '' !== $this->setting( 'passkey' );
	}

	/**
	 * @inheritDoc
	 */
	public function form_fields() {
		$fields = $this->section(
			__( 'M-Pesa (Safaricom Daraja, Kenya)', 'sokolipa-checkout' ),
			sprintf(
				/* translators: 1: Daraja portal link, 2: callback URL */
				__( 'Create an app on the %1$s portal with the "Lipa Na M-Pesa Online" product, then paste the keys below. Buyers get an STK prompt and pay in KES. Register this callback URL in your app if Safaricom asks for one: %2$s', 'sokolipa-checkout' ),
				'<a href="https://developer.safaricom.co.ke/" target="_blank" rel="noopener">Daraja</a>',
				'<code>' . esc_html( $this->callback_url() ) . '</code>'
			)
		);

		$fields['mpesa_consumer_key']    = array(
			'title' => __( 'Consumer key', 'sokolipa-checkout' ),
			'type'  => 'text',
		);
		$fields['mpesa_consumer_secret'] = array(
			'title' => __( 'Consumer secret', 'sokolipa-checkout' ),
			'type'  => 'password',
		);
		$fields['mpesa_type']            = array(
			'title'   => __( 'Shortcode type', 'sokolipa-checkout' ),
			'type'    => 'select',
			'default' => 'paybill',
			'options' => array(
				'paybill' => __( 'Paybill (CustomerPayBillOnline)', 'sokolipa-checkout' ),
				'till'    => __( 'Buy Goods till (CustomerBuyGoodsOnline)', 'sokolipa-checkout' ),
			),
		);
		$fields['mpesa_shortcode']       = array(
			'title'       => __( 'Business shortcode', 'sokolipa-checkout' ),
			'type'        => 'text',
			'description' => __( 'Your paybill number, or for Buy Goods the store number (head office). Sandbox: 174379.', 'sokolipa-checkout' ),
			'desc_tip'    => true,
		);
		$fields['mpesa_partyb']          = array(
			'title'       => __( 'Till number (Buy Goods only)', 'sokolipa-checkout' ),
			'type'        => 'text',
			'description' => __( 'The till number the money lands on, when it differs from the store number above. Leave empty for paybills.', 'sokolipa-checkout' ),
			'desc_tip'    => true,
		);
		$fields['mpesa_passkey']         = array(
			'title'       => __( 'Lipa na M-Pesa passkey', 'sokolipa-checkout' ),
			'type'        => 'password',
			'description' => __( 'Issued by Safaricom when your shortcode goes live. The sandbox passkey is on the Daraja test credentials page.', 'sokolipa-checkout' ),
			'desc_tip'    => true,
		);
		$fields['mpesa_account_prefix']  = array(
			'title'       => __( 'Account reference prefix', 'sokolipa-checkout' ),
			'type'        => 'text',
			'default'     => 'ORDER',
			'description' => __( 'Shown on the buyer\'s prompt and statement, followed by the order number. Letters and digits, 12 characters max in total.', 'sokolipa-checkout' ),
			'desc_tip'    => true,
		);

		return $fields;
	}

	/**
	 * @inheritDoc
	 */
	public function request_push( \WC_Order $order, $msisdn ) {
		$timestamp = $this->timestamp();
		$shortcode = $this->setting( 'shortcode' );
		$partyb    = '' !== $this->setting( 'partyb' ) ? $this->setting( 'partyb' ) : $shortcode;
		$prefix    = preg_replace( '/[^A-Za-z0-9]/', '', $this->setting( 'account_prefix', 'ORDER' ) );
		$reference = mb_substr( $prefix . $order->get_order_number(), 0, 12 );

		$body = array(
			'BusinessShortCode' => $shortcode,
			'Password'          => base64_encode( $shortcode . $this->setting( 'passkey' ) . $timestamp ),
			'Timestamp'         => $timestamp,
			'TransactionType'   => 'till' === $this->setting( 'type', 'paybill' ) ? 'CustomerBuyGoodsOnline' : 'CustomerPayBillOnline',
			'Amount'            => (int) ceil( (float) $order->get_total() ),
			'PartyA'            => $msisdn,
			'PartyB'            => $partyb,
			'PhoneNumber'       => $msisdn,
			'CallBackURL'       => $this->callback_url(),
			'AccountReference'  => $reference,
			/* translators: %s: order number */
			'TransactionDesc'   => mb_substr( sprintf( __( 'Order %s', 'sokolipa-checkout' ), $order->get_order_number() ), 0, 13 ),
		);

		$response = $this->http(
			'POST',
			$this->base() . '/mpesa/stkpush/v1/processrequest',
			array( 'Authorization' => 'Bearer ' . $this->token() ),
			$body
		);

		$data = $response['body'];

		if ( 200 !== $response['status'] || empty( $data['CheckoutRequestID'] ) || ( isset( $data['ResponseCode'] ) && '0' !== (string) $data['ResponseCode'] ) ) {
			$message = isset( $data['errorMessage'] ) ? $data['errorMessage'] : ( isset( $data['ResponseDescription'] ) ? $data['ResponseDescription'] : '' );
			Logger::error( 'mpesa: STK push rejected', array( 'order' => $order->get_id(), 'status' => $response['status'], 'message' => $message ) );
			throw new NetworkException( esc_html( $this->friendly_error( $message ) ) );
		}

		return new PushResult(
			(string) $data['CheckoutRequestID'],
			__( 'Check your phone and enter your M-Pesa PIN to complete the payment.', 'sokolipa-checkout' ),
			array( 'merchant_request_id' => isset( $data['MerchantRequestID'] ) ? (string) $data['MerchantRequestID'] : '' )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function query_status( \WC_Order $order ) {
		$checkout_id = (string) $order->get_meta( '_sokolipa_provider_ref' );
		if ( '' === $checkout_id ) {
			return new StatusResult( StatusResult::FAILED, '', __( 'No M-Pesa request on this order.', 'sokolipa-checkout' ) );
		}

		$timestamp = $this->timestamp();
		$shortcode = $this->setting( 'shortcode' );
		$response  = $this->http(
			'POST',
			$this->base() . '/mpesa/stkpushquery/v1/query',
			array( 'Authorization' => 'Bearer ' . $this->token() ),
			array(
				'BusinessShortCode' => $shortcode,
				'Password'          => base64_encode( $shortcode . $this->setting( 'passkey' ) . $timestamp ),
				'Timestamp'         => $timestamp,
				'CheckoutRequestID' => $checkout_id,
			)
		);

		$data = $response['body'];

		// "The transaction is being processed" comes back as an error envelope while the buyer is on the PIN screen.
		if ( isset( $data['errorCode'] ) ) {
			if ( '500.001.1001' === (string) $data['errorCode'] ) {
				return new StatusResult( StatusResult::PENDING, '', __( 'Waiting for the buyer to enter the PIN.', 'sokolipa-checkout' ) );
			}
			return new StatusResult( StatusResult::PENDING, '', isset( $data['errorMessage'] ) ? (string) $data['errorMessage'] : '' );
		}

		if ( ! isset( $data['ResultCode'] ) ) {
			return new StatusResult( StatusResult::PENDING, '', __( 'No result yet.', 'sokolipa-checkout' ) );
		}

		$code = (string) $data['ResultCode'];
		$desc = isset( $data['ResultDesc'] ) ? (string) $data['ResultDesc'] : '';

		if ( '0' === $code ) {
			// The query confirms success but carries no receipt; the callback supplies it when it lands.
			return new StatusResult( StatusResult::PAID, (string) $order->get_meta( '_sokolipa_receipt_hint' ), $desc );
		}

		if ( $this->still_processing( $code, $desc ) ) {
			return new StatusResult( StatusResult::PENDING, '', __( 'Waiting for the buyer to enter the PIN.', 'sokolipa-checkout' ) );
		}

		return new StatusResult( StatusResult::FAILED, '', $this->result_reason( $code, $desc ) );
	}

	/**
	 * While the buyer is still on the PIN screen the query can answer with a non-zero
	 * ResultCode whose description reads "The transaction is still under processing"
	 * (seen in the sandbox), or with 1001 (a push already in progress for the subscriber).
	 * Neither is a final outcome, so the order keeps waiting.
	 *
	 * @param string $code ResultCode.
	 * @param string $desc ResultDesc.
	 * @return bool
	 */
	private function still_processing( $code, $desc ) {
		if ( in_array( $code, array( '4999', '1001' ), true ) ) {
			return true;
		}
		return false !== stripos( $desc, 'processing' ) || false !== stripos( $desc, 'in process' );
	}

	/**
	 * @inheritDoc
	 */
	public function parse_callback( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || empty( $body['Body']['stkCallback'] ) ) {
			return null;
		}
		$cb      = $body['Body']['stkCallback'];
		$ref     = isset( $cb['CheckoutRequestID'] ) ? (string) $cb['CheckoutRequestID'] : '';
		$code    = isset( $cb['ResultCode'] ) ? (string) $cb['ResultCode'] : '';
		$receipt = '';

		if ( ! empty( $cb['CallbackMetadata']['Item'] ) && is_array( $cb['CallbackMetadata']['Item'] ) ) {
			foreach ( $cb['CallbackMetadata']['Item'] as $item ) {
				if ( isset( $item['Name'], $item['Value'] ) && 'MpesaReceiptNumber' === $item['Name'] ) {
					$receipt = (string) $item['Value'];
				}
			}
		}

		return new CallbackHint( $ref, 0, $receipt, '0' === $code ? StatusResult::PAID : StatusResult::FAILED );
	}

	/**
	 * @inheritDoc
	 */
	public function callback_ack() {
		return array(
			'ResultCode' => 0,
			'ResultDesc' => 'Accepted',
		);
	}

	/**
	 * Safaricom refuses callback URLs that contain "mpesa" or "safaricom", so this one says "stk".
	 *
	 * @return string
	 */
	public function callback_url() {
		return rest_url( 'sokolipa/v1/callback/stk' );
	}

	/**
	 * OAuth token (client credentials).
	 *
	 * @return string
	 * @throws NetworkException When the credentials are rejected.
	 */
	private function token() {
		$key    = $this->setting( 'consumer_key' );
		$secret = $this->setting( 'consumer_secret' );

		return $this->cached_token(
			md5( $key . $secret ),
			function () use ( $key, $secret ) {
				$response = $this->http(
					'GET',
					$this->base() . '/oauth/v1/generate?grant_type=client_credentials',
					array( 'Authorization' => 'Basic ' . base64_encode( $key . ':' . $secret ) )
				);
				return array(
					'token' => isset( $response['body']['access_token'] ) ? (string) $response['body']['access_token'] : '',
					'ttl'   => isset( $response['body']['expires_in'] ) ? (int) $response['body']['expires_in'] : 3599,
				);
			}
		);
	}

	/**
	 * Daraja timestamp: YYYYMMDDHHmmss in Nairobi time.
	 *
	 * @return string
	 */
	private function timestamp() {
		return wp_date( 'YmdHis', null, new \DateTimeZone( 'Africa/Nairobi' ) );
	}

	/**
	 * Host for the configured environment.
	 *
	 * @return string
	 */
	private function base() {
		return $this->is_live() ? self::BASE_LIVE : self::BASE_SANDBOX;
	}

	/**
	 * Buyer-facing reason for a non-zero ResultCode.
	 *
	 * @param string $code ResultCode.
	 * @param string $desc ResultDesc.
	 * @return string
	 */
	private function result_reason( $code, $desc ) {
		switch ( $code ) {
			case '1032':
				return __( 'The payment prompt was cancelled on the phone.', 'sokolipa-checkout' );
			case '1037':
				return __( 'The phone did not respond in time. Make sure it is on and has signal, then try again.', 'sokolipa-checkout' );
			case '1':
				return __( 'The M-Pesa balance is not enough for this order.', 'sokolipa-checkout' );
			case '2001':
				return __( 'The PIN entered was wrong.', 'sokolipa-checkout' );
			case '1019':
			case '1025':
			case '9999':
				return __( 'M-Pesa could not process the prompt. Please try again.', 'sokolipa-checkout' );
			default:
				return '' !== $desc ? $desc : __( 'The payment did not go through.', 'sokolipa-checkout' );
		}
	}

	/**
	 * Turns Daraja's error text into something a buyer can act on.
	 *
	 * @param string $message Operator message.
	 * @return string
	 */
	private function friendly_error( $message ) {
		if ( false !== stripos( $message, 'Invalid Access Token' ) || false !== stripos( $message, 'Invalid Authentication' ) ) {
			return __( 'M-Pesa rejected the store\'s API keys. The merchant needs to check the Sokolipa Checkout settings.', 'sokolipa-checkout' );
		}
		if ( false !== stripos( $message, 'Invalid PhoneNumber' ) || false !== stripos( $message, 'PartyA' ) ) {
			return __( 'That does not look like a Safaricom M-Pesa number. Use the format 07XX XXX XXX.', 'sokolipa-checkout' );
		}
		return '' !== $message ? $message : __( 'M-Pesa did not accept the payment request. Please try again.', 'sokolipa-checkout' );
	}
}
