<?php
/**
 * MTN MoMo adapter: MoMo API Collections "request to pay".
 *
 * @package Sokolipa
 */

namespace Sokolipa\Networks;

use Sokolipa\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Every MTN market on the MoMo API. Credentials from https://momodeveloper.mtn.com/ (sandbox)
 * and the MoMo partner portal of the operating country (live).
 */
final class Mtn extends AbstractNetwork {

	const BASE_SANDBOX = 'https://sandbox.momodeveloper.mtn.com';
	const BASE_LIVE    = 'https://proxy.momoapi.mtn.com';

	/**
	 * X-Target-Environment values and the country/currency each one charges in.
	 *
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function markets() {
		return array(
			'mtnuganda'        => array( 'UG', 'UGX', 'Uganda' ),
			'mtnghana'         => array( 'GH', 'GHS', 'Ghana' ),
			'mtnrwanda'        => array( 'RW', 'RWF', 'Rwanda' ),
			'mtnzambia'        => array( 'ZM', 'ZMW', 'Zambia' ),
			'mtncameroon'      => array( 'CM', 'XAF', 'Cameroon' ),
			'mtnivorycoast'    => array( 'CI', 'XOF', 'Côte d’Ivoire' ),
			'mtnbenin'         => array( 'BJ', 'XOF', 'Benin' ),
			'mtncongo'         => array( 'CG', 'XAF', 'Congo-Brazzaville' ),
			'mtnliberia'       => array( 'LR', 'LRD', 'Liberia' ),
			'mtnsouthsudan'    => array( 'SS', 'SSP', 'South Sudan' ),
			'mtnswaziland'     => array( 'SZ', 'SZL', 'Eswatini' ),
			'mtnguineaconakry' => array( 'GN', 'GNF', 'Guinea' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function id() {
		return 'mtn';
	}

	/**
	 * @inheritDoc
	 */
	public function label() {
		return 'MTN MoMo';
	}

	/**
	 * @inheritDoc
	 */
	public function country() {
		$market = $this->market();
		return $market ? $market[0] : 'UG';
	}

	/**
	 * @inheritDoc
	 */
	public function currency() {
		if ( ! $this->is_live() ) {
			return 'EUR'; // The MoMo sandbox prices everything in EUR.
		}
		$market = $this->market();
		return $market ? $market[1] : 'UGX';
	}

	/**
	 * @inheritDoc
	 */
	public function is_configured() {
		return $this->enabled()
			&& '' !== $this->setting( 'subscription_key' )
			&& '' !== $this->setting( 'api_user' )
			&& '' !== $this->setting( 'api_key' )
			&& ( ! $this->is_live() || null !== $this->market() );
	}

	/**
	 * @inheritDoc
	 */
	public function form_fields() {
		$fields = $this->section(
			__( 'MTN MoMo (MoMo API Collections)', 'sokolipa-checkout' ),
			sprintf(
				/* translators: 1: MoMo developer portal link, 2: callback URL */
				__( 'Subscribe to the Collections product on %1$s for sandbox keys; live keys come from your country\'s MoMo partner portal after onboarding. Buyers get a request-to-pay prompt. Callback URL: %2$s', 'sokolipa-checkout' ),
				'<a href="https://momodeveloper.mtn.com/" target="_blank" rel="noopener">momodeveloper.mtn.com</a>',
				'<code>' . esc_html( $this->callback_url() ) . '</code>'
			)
		);

		$options = array();
		foreach ( self::markets() as $env => $market ) {
			$options[ $env ] = $market[2] . ' (' . $market[1] . ')';
		}

		$fields['mtn_market']           = array(
			'title'       => __( 'Market', 'sokolipa-checkout' ),
			'type'        => 'select',
			'default'     => 'mtnuganda',
			'options'     => $options,
			'description' => __( 'Sets the X-Target-Environment, the currency and the phone format. In sandbox the currency is always EUR.', 'sokolipa-checkout' ),
			'desc_tip'    => true,
		);
		$fields['mtn_subscription_key'] = array(
			'title'       => __( 'Collections subscription key', 'sokolipa-checkout' ),
			'type'        => 'password',
			'description' => __( 'The Ocp-Apim-Subscription-Key of your Collections subscription.', 'sokolipa-checkout' ),
			'desc_tip'    => true,
		);
		$fields['mtn_api_user']         = array(
			'title'       => __( 'API user ID', 'sokolipa-checkout' ),
			'type'        => 'text',
			'description' => __( 'A UUID. In sandbox, create it with the API user endpoint; live, from the partner portal.', 'sokolipa-checkout' ),
			'desc_tip'    => true,
		);
		$fields['mtn_api_key']          = array(
			'title' => __( 'API key', 'sokolipa-checkout' ),
			'type'  => 'password',
		);

		return $fields;
	}

	/**
	 * @inheritDoc
	 */
	public function request_push( \WC_Order $order, $msisdn ) {
		$reference = wp_generate_uuid4();

		$body = array(
			'amount'       => $this->amount_for( $order ),
			'currency'     => $this->currency(),
			'externalId'   => (string) $order->get_id(),
			'payer'        => array(
				'partyIdType' => 'MSISDN',
				'partyId'     => $msisdn,
			),
			/* translators: 1: store name, 2: order number */
			'payerMessage' => mb_substr( sprintf( __( '%1$s order %2$s', 'sokolipa-checkout' ), wp_specialchars_decode( get_bloginfo( 'name' ) ), $order->get_order_number() ), 0, 160 ),
			'payeeNote'    => mb_substr( 'Order ' . $order->get_order_number(), 0, 160 ),
		);

		$headers = array_merge( $this->headers(), array( 'X-Reference-Id' => $reference ) );
		if ( $this->is_live() ) {
			// The sandbox only accepts the callback host registered with the API user, so polling covers it there.
			$headers['X-Callback-Url'] = $this->callback_url();
		}

		try {
			$response = $this->http( 'POST', $this->base() . '/collection/v1_0/requesttopay', $headers, $body );
		} catch ( NetworkException $e ) {
			$e->provider_ref = $reference;
			throw $e;
		}

		if ( 202 !== $response['status'] ) {
			$message = isset( $response['body']['message'] ) ? (string) $response['body']['message'] : '';
			Logger::error( 'mtn: request to pay rejected', array( 'order' => $order->get_id(), 'status' => $response['status'], 'message' => $message ) );
			throw new NetworkException( esc_html( $this->friendly_error( $response['status'], $message ) ) );
		}

		return new PushResult(
			$reference,
			__( 'Check your phone and approve the MTN MoMo payment with your PIN.', 'sokolipa-checkout' )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function query_status( \WC_Order $order ) {
		$reference = (string) $order->get_meta( '_sokolipa_provider_ref' );
		if ( '' === $reference ) {
			return new StatusResult( StatusResult::FAILED, '', __( 'No MoMo request on this order.', 'sokolipa-checkout' ) );
		}

		$response = $this->http(
			'GET',
			$this->base() . '/collection/v1_0/requesttopay/' . rawurlencode( $reference ),
			$this->headers()
		);

		$data = $response['body'];

		if ( 200 !== $response['status'] || empty( $data['status'] ) ) {
			return new StatusResult( StatusResult::PENDING, '', __( 'No result yet.', 'sokolipa-checkout' ) );
		}

		switch ( strtoupper( (string) $data['status'] ) ) {
			case 'SUCCESSFUL':
				return new StatusResult( StatusResult::PAID, isset( $data['financialTransactionId'] ) ? (string) $data['financialTransactionId'] : '' );
			case 'FAILED':
				$reason = isset( $data['reason'] ) ? $data['reason'] : '';
				$reason = is_array( $reason ) ? ( isset( $reason['message'] ) ? $reason['message'] : ( isset( $reason['code'] ) ? $reason['code'] : '' ) ) : (string) $reason;
				return new StatusResult( StatusResult::FAILED, '', $this->reason_text( $reason ) );
			default:
				return new StatusResult( StatusResult::PENDING, '', __( 'Waiting for the buyer to approve.', 'sokolipa-checkout' ) );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function parse_callback( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['status'] ) ) {
			return null;
		}
		// MoMo echoes our externalId (the order id) and its own reference; either finds the order.
		$order_id = isset( $body['externalId'] ) ? (int) $body['externalId'] : 0;
		$ref      = isset( $body['referenceId'] ) ? (string) $body['referenceId'] : '';
		$receipt  = isset( $body['financialTransactionId'] ) ? (string) $body['financialTransactionId'] : '';
		$claimed  = 'SUCCESSFUL' === strtoupper( (string) $body['status'] ) ? StatusResult::PAID : StatusResult::FAILED;

		return new CallbackHint( $ref, $order_id, $receipt, $claimed );
	}

	/**
	 * Headers common to every Collections call.
	 *
	 * @return array
	 * @throws NetworkException When the token cannot be obtained.
	 */
	private function headers() {
		return array(
			'Authorization'             => 'Bearer ' . $this->token(),
			'Ocp-Apim-Subscription-Key' => $this->setting( 'subscription_key' ),
			'X-Target-Environment'      => $this->is_live() ? $this->setting( 'market', 'mtnuganda' ) : 'sandbox',
		);
	}

	/**
	 * Collections access token.
	 *
	 * @return string
	 * @throws NetworkException When the credentials are rejected.
	 */
	private function token() {
		$user = $this->setting( 'api_user' );
		$key  = $this->setting( 'api_key' );
		$sub  = $this->setting( 'subscription_key' );

		return $this->cached_token(
			md5( $user . $key . $sub ),
			function () use ( $user, $key, $sub ) {
				$response = $this->http(
					'POST',
					$this->base() . '/collection/token/',
					array(
						'Authorization'             => 'Basic ' . base64_encode( $user . ':' . $key ),
						'Ocp-Apim-Subscription-Key' => $sub,
					),
					''
				);
				return array(
					'token' => isset( $response['body']['access_token'] ) ? (string) $response['body']['access_token'] : '',
					'ttl'   => isset( $response['body']['expires_in'] ) ? (int) $response['body']['expires_in'] : 3600,
				);
			}
		);
	}

	/**
	 * The configured market, or null when unknown.
	 *
	 * @return array|null
	 */
	private function market() {
		$markets = self::markets();
		$env     = $this->setting( 'market', 'mtnuganda' );
		return isset( $markets[ $env ] ) ? $markets[ $env ] : null;
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
	 * Buyer-facing text for a FAILED reason code.
	 *
	 * @param string $reason Reason code or message.
	 * @return string
	 */
	private function reason_text( $reason ) {
		$map = array(
			'PAYER_NOT_FOUND'                => __( 'That number is not registered on MTN MoMo.', 'sokolipa-checkout' ),
			'NOT_ENOUGH_FUNDS'               => __( 'The MoMo balance is not enough for this order.', 'sokolipa-checkout' ),
			'PAYER_LIMIT_REACHED'            => __( 'The MoMo account has reached its limit for today.', 'sokolipa-checkout' ),
			'EXPIRED'                        => __( 'The prompt expired before it was approved.', 'sokolipa-checkout' ),
			'APPROVAL_REJECTED'              => __( 'The payment was rejected on the phone.', 'sokolipa-checkout' ),
			'INTERNAL_PROCESSING_ERROR'      => __( 'MTN could not process the prompt. Please try again.', 'sokolipa-checkout' ),
			'TRANSACTION_CANCELED'           => __( 'The payment was cancelled.', 'sokolipa-checkout' ),
			'SERVICE_UNAVAILABLE'            => __( 'MTN MoMo is temporarily unavailable. Please try again shortly.', 'sokolipa-checkout' ),
		);
		$key = strtoupper( trim( (string) $reason ) );
		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}
		return '' !== $reason ? (string) $reason : __( 'The payment did not go through.', 'sokolipa-checkout' );
	}

	/**
	 * Request-time errors.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Operator message.
	 * @return string
	 */
	private function friendly_error( $status, $message ) {
		if ( 401 === $status || 403 === $status ) {
			return __( 'MTN MoMo rejected the store\'s API keys. The merchant needs to check the Sokolipa Checkout settings.', 'sokolipa-checkout' );
		}
		if ( 409 === $status ) {
			return __( 'A request with this reference already exists. Please try again.', 'sokolipa-checkout' );
		}
		return '' !== $message ? $message : __( 'MTN MoMo did not accept the payment request. Please try again.', 'sokolipa-checkout' );
	}
}
