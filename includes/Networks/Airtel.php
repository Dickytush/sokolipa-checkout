<?php
/**
 * Airtel Money adapter: Airtel Africa Open API "USSD push" collection.
 *
 * @package Sokolipa
 */

namespace Sokolipa\Networks;

use Sokolipa\Logger;
use Sokolipa\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * Airtel Money across Airtel Africa's markets. Credentials from https://developers.airtel.africa/.
 */
final class Airtel extends AbstractNetwork {

	const BASE_SANDBOX = 'https://openapiuat.airtel.africa';
	const BASE_LIVE    = 'https://openapi.airtel.africa';

	/**
	 * Countries Airtel Money serves on the Open API, with their currency.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function markets() {
		return array(
			'UG' => array( 'UGX', 'Uganda' ),
			'KE' => array( 'KES', 'Kenya' ),
			'TZ' => array( 'TZS', 'Tanzania' ),
			'RW' => array( 'RWF', 'Rwanda' ),
			'ZM' => array( 'ZMW', 'Zambia' ),
			'MW' => array( 'MWK', 'Malawi' ),
			'NG' => array( 'NGN', 'Nigeria' ),
			'CD' => array( 'CDF', 'DR Congo' ),
			'CG' => array( 'XAF', 'Congo-Brazzaville' ),
			'GA' => array( 'XAF', 'Gabon' ),
			'TD' => array( 'XAF', 'Chad' ),
			'NE' => array( 'XOF', 'Niger' ),
			'MG' => array( 'MGA', 'Madagascar' ),
			'SC' => array( 'SCR', 'Seychelles' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function id() {
		return 'airtel';
	}

	/**
	 * @inheritDoc
	 */
	public function label() {
		return 'Airtel Money';
	}

	/**
	 * @inheritDoc
	 */
	public function country() {
		$country = strtoupper( $this->setting( 'country', 'UG' ) );
		$markets = self::markets();
		return isset( $markets[ $country ] ) ? $country : 'UG';
	}

	/**
	 * @inheritDoc
	 */
	public function currency() {
		$markets = self::markets();
		return $markets[ $this->country() ][0];
	}

	/**
	 * @inheritDoc
	 */
	public function is_configured() {
		return $this->enabled()
			&& '' !== $this->setting( 'client_id' )
			&& '' !== $this->setting( 'client_secret' );
	}

	/**
	 * @inheritDoc
	 */
	public function form_fields() {
		$fields = $this->section(
			__( 'Airtel Money (Airtel Africa Open API)', 'sokolipa-checkout' ),
			sprintf(
				/* translators: 1: Airtel developer portal link, 2: callback URL */
				__( 'Register on %1$s, create an app with the Collection product and paste its client ID and secret. Buyers get a USSD push prompt. Callback URL to register: %2$s', 'sokolipa-checkout' ),
				'<a href="https://developers.airtel.africa/" target="_blank" rel="noopener">developers.airtel.africa</a>',
				'<code>' . esc_html( $this->callback_url() ) . '</code>'
			)
		);

		$options = array();
		foreach ( self::markets() as $code => $market ) {
			$options[ $code ] = $market[1] . ' (' . $market[0] . ')';
		}

		$fields['airtel_country']       = array(
			'title'   => __( 'Country', 'sokolipa-checkout' ),
			'type'    => 'select',
			'default' => 'UG',
			'options' => $options,
		);
		$fields['airtel_client_id']     = array(
			'title' => __( 'Client ID', 'sokolipa-checkout' ),
			'type'  => 'text',
		);
		$fields['airtel_client_secret'] = array(
			'title' => __( 'Client secret', 'sokolipa-checkout' ),
			'type'  => 'password',
		);
		$fields['airtel_public_key']    = array(
			'title'       => __( 'Airtel RSA public key (optional)', 'sokolipa-checkout' ),
			'type'        => 'textarea',
			'css'         => 'min-height: 90px; font-family: monospace;',
			'description' => __( 'Only for markets where Airtel requires signed requests: paste the public key from the developer portal and the plugin sends the encrypted x-key and x-signature headers (payments v2). Leave empty for plain v1 requests.', 'sokolipa-checkout' ),
			'desc_tip'    => true,
		);

		return $fields;
	}

	/**
	 * @inheritDoc
	 */
	public function request_push( \WC_Order $order, $msisdn ) {
		$country  = $this->country();
		$currency = $this->currency();
		$txn_id   = 'SK' . $order->get_id() . 'T' . substr( (string) time(), -6 ) . wp_rand( 10, 99 );
		$body     = array(
			/* translators: %s: order number */
			'reference'   => mb_substr( sprintf( __( 'Order %s', 'sokolipa-checkout' ), $order->get_order_number() ), 0, 64 ),
			'subscriber'  => array(
				'country'  => $country,
				'currency' => $currency,
				'msisdn'   => Phone::national( $msisdn, $country ),
			),
			'transaction' => array(
				'amount'   => 0 + $this->amount_for( $order ),
				'country'  => $country,
				'currency' => $currency,
				'id'       => $txn_id,
			),
		);

		$headers = array_merge( $this->headers(), array( 'Accept' => '*/*' ) );
		$version = 'v1';

		if ( '' !== $this->setting( 'public_key' ) ) {
			$version = 'v2';
			$headers = array_merge( $headers, $this->signature_headers( $body ) );
		}

		try {
			$response = $this->http( 'POST', $this->base() . '/merchant/' . $version . '/payments/', $headers, $body );
		} catch ( NetworkException $e ) {
			$e->provider_ref = $txn_id;
			throw $e;
		}
		$data     = $response['body'];
		$ok       = isset( $data['status']['success'] ) ? (bool) $data['status']['success'] : ( 200 === $response['status'] );

		if ( ! $ok || 200 !== $response['status'] ) {
			$message = isset( $data['status']['message'] ) ? (string) $data['status']['message'] : '';
			Logger::error( 'airtel: push rejected', array( 'order' => $order->get_id(), 'status' => $response['status'], 'message' => $message, 'code' => isset( $data['status']['result_code'] ) ? $data['status']['result_code'] : '' ) );
			throw new NetworkException( esc_html( $this->friendly_error( $response['status'], $message ) ) );
		}

		$provider_id = isset( $data['data']['transaction']['id'] ) && '' !== (string) $data['data']['transaction']['id'] ? (string) $data['data']['transaction']['id'] : $txn_id;

		return new PushResult(
			$provider_id,
			__( 'Check your phone and approve the Airtel Money payment with your PIN.', 'sokolipa-checkout' ),
			array( 'txn_id' => $txn_id )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function query_status( \WC_Order $order ) {
		$txn_id = (string) $order->get_meta( '_sokolipa_provider_ref' );
		if ( '' === $txn_id ) {
			return new StatusResult( StatusResult::FAILED, '', __( 'No Airtel Money request on this order.', 'sokolipa-checkout' ) );
		}

		$response = $this->http( 'GET', $this->base() . '/standard/v1/payments/' . rawurlencode( $txn_id ), $this->headers() );
		$data     = $response['body'];
		$txn      = isset( $data['data']['transaction'] ) && is_array( $data['data']['transaction'] ) ? $data['data']['transaction'] : array();
		$status   = isset( $txn['status'] ) ? strtoupper( (string) $txn['status'] ) : '';

		if ( 'TS' === $status ) {
			return new StatusResult( StatusResult::PAID, isset( $txn['airtel_money_id'] ) ? (string) $txn['airtel_money_id'] : '' );
		}
		if ( 'TF' === $status ) {
			return new StatusResult( StatusResult::FAILED, '', isset( $txn['message'] ) && '' !== $txn['message'] ? (string) $txn['message'] : __( 'The payment did not go through.', 'sokolipa-checkout' ) );
		}
		return new StatusResult( StatusResult::PENDING, '', __( 'Waiting for the buyer to approve.', 'sokolipa-checkout' ) );
	}

	/**
	 * @inheritDoc
	 */
	public function parse_callback( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || empty( $body['transaction'] ) || ! is_array( $body['transaction'] ) ) {
			return null;
		}
		$txn     = $body['transaction'];
		$ref     = isset( $txn['id'] ) ? (string) $txn['id'] : '';
		$code    = isset( $txn['status_code'] ) ? strtoupper( (string) $txn['status_code'] ) : '';
		$receipt = isset( $txn['airtel_money_id'] ) ? (string) $txn['airtel_money_id'] : '';

		return new CallbackHint( $ref, 0, $receipt, 'TS' === $code ? StatusResult::PAID : StatusResult::FAILED );
	}

	/**
	 * Headers common to every call.
	 *
	 * @return array
	 * @throws NetworkException When the token cannot be obtained.
	 */
	private function headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->token(),
			'X-Country'     => $this->country(),
			'X-Currency'    => $this->currency(),
		);
	}

	/**
	 * OAuth2 client-credentials token.
	 *
	 * @return string
	 * @throws NetworkException When the credentials are rejected.
	 */
	private function token() {
		$id     = $this->setting( 'client_id' );
		$secret = $this->setting( 'client_secret' );

		return $this->cached_token(
			md5( $id . $secret ),
			function () use ( $id, $secret ) {
				$response = $this->http(
					'POST',
					$this->base() . '/auth/oauth2/token',
					array( 'Accept' => '*/*' ),
					array(
						'client_id'     => $id,
						'client_secret' => $secret,
						'grant_type'    => 'client_credentials',
					)
				);
				return array(
					'token' => isset( $response['body']['access_token'] ) ? (string) $response['body']['access_token'] : '',
					'ttl'   => isset( $response['body']['expires_in'] ) ? (int) $response['body']['expires_in'] : 180,
				);
			}
		);
	}

	/**
	 * The x-signature / x-key pair Airtel requires on payments v2:
	 * the JSON payload AES-256-CBC encrypted with a random key and IV (x-signature),
	 * and "base64(key):base64(iv)" RSA-encrypted with Airtel's public key (x-key).
	 *
	 * @param array $body Request body.
	 * @return array
	 * @throws NetworkException When the public key is unusable.
	 */
	private function signature_headers( array $body ) {
		$pem = $this->public_key_pem();
		$key = openssl_pkey_get_public( $pem );
		if ( false === $key ) {
			throw new NetworkException( esc_html__( 'The Airtel public key in settings could not be read. Paste it exactly as the developer portal shows it.', 'sokolipa-checkout' ) );
		}

		$aes_key = random_bytes( 32 );
		$iv      = random_bytes( 16 );
		$payload = wp_json_encode( $body );

		$encrypted = openssl_encrypt( $payload, 'AES-256-CBC', $aes_key, OPENSSL_RAW_DATA, $iv );
		$key_iv    = base64_encode( $aes_key ) . ':' . base64_encode( $iv );
		$x_key     = '';

		if ( false === $encrypted || ! openssl_public_encrypt( $key_iv, $x_key, $key, OPENSSL_PKCS1_PADDING ) ) {
			throw new NetworkException( esc_html__( 'Could not sign the Airtel request. Check the public key in settings.', 'sokolipa-checkout' ) );
		}

		return array(
			'x-signature' => base64_encode( $encrypted ),
			'x-key'       => base64_encode( $x_key ),
		);
	}

	/**
	 * The configured public key as PEM, whether it was pasted as PEM or as bare base64.
	 *
	 * @return string
	 */
	private function public_key_pem() {
		$raw = trim( $this->setting( 'public_key' ) );
		if ( false !== strpos( $raw, '-----BEGIN' ) ) {
			return $raw;
		}
		$clean = preg_replace( '/\s+/', '', $raw );
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( $clean, 64, "\n" ) . "-----END PUBLIC KEY-----\n";
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
	 * Request-time errors.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Operator message.
	 * @return string
	 */
	private function friendly_error( $status, $message ) {
		if ( 401 === $status || 403 === $status ) {
			return __( 'Airtel Money rejected the store\'s API keys. The merchant needs to check the Sokolipa Checkout settings.', 'sokolipa-checkout' );
		}
		if ( false !== stripos( $message, 'subscriber' ) || false !== stripos( $message, 'msisdn' ) ) {
			return __( 'That number is not registered on Airtel Money. Check it and try again.', 'sokolipa-checkout' );
		}
		return '' !== $message ? $message : __( 'Airtel Money did not accept the payment request. Please try again.', 'sokolipa-checkout' );
	}
}
