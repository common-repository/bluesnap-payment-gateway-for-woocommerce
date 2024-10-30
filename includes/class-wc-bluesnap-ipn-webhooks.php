<?php
/**
 * @author   SAU/CAL
 * @category Class
 * @package  Woocommerce_Bluesnap_Gateway/Classes
 * @version  1.3.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Bluesnap_IPN_Webhooks
 *
 * Handle IPN requests from Bluesnap for offline events.
 * https://support.bluesnap.com/docs/default-ipns
 */
class WC_Bluesnap_IPN_Webhooks {

	/**
	 * https://support.bluesnap.com/docs/ipn-setup#section-1-setting-up-your-server-to-receive-ipns
	 * Prod IP whitelist.
	 */
	const BLUESNAP_PROD_IPS = array(
		'38.99.111.60',
		'38.99.111.160',
		'141.226.140.100',
		'141.226.141.100',
		'141.226.142.100',
		'141.226.143.100',
	);

	/**
	 * Sandbox IP Whitelist.
	 */
	const BLUESNAP_SANDBOX_IPS = array(
		'38.99.111.50',
		'38.99.111.150',
		'141.226.140.200',
		'141.226.141.200',
		'141.226.142.200',
		'141.226.143.200',
	);

	private $debounce_key;

	/**
	 * WC_Bluesnap_IPN_Webhooks constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_api_bluesnap', array( $this, 'check_for_ipn_request' ) );
	}

	/**
	 * Checks if the requet is a legit IPN and acts accordingly.
	 * @throws Exception
	 */
	public function check_for_ipn_request() {
		if ( ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}

		$headers  = $this->get_all_headers();
		$raw_body = file_get_contents( 'php://input' );

		$message = esc_html__( 'BlueSnap IPN request failed.', 'woocommerce-bluesnap-gateway' ) . ' ';
		try {
			$this->debounce_ipn_request( $raw_body );
			$body = $this->validate_source( $headers, $raw_body );
			$this->log_request( $headers, $raw_body );
			/* translators: %1$s transactionType */
			$message = sprintf( esc_html__( 'BlueSnap %1$s IPN request failed.', 'woocommerce-bluesnap-gateway' ), $body['transactionType'] ) . ' ';
			ob_start();
			$this->handle_ipn_request( $body );
			status_header( 200 );
			$response = ob_get_clean();
			$this->log_response( $response );
			$this->ipn_cleanup();
		} catch ( Exception $e ) {
			$message    .= $e->getMessage();
			$response    = wp_json_encode(
				array(
					'error'   => true,
					'message' => $message,
				)
			);
			$status_code = is_numeric( $e->getCode() ) ? $e->getCode() : 400;
			$this->log_response( $response, $status_code );
			WC_Bluesnap_Logger::log( $message, 'error', 'ipn' );
			$this->ipn_cleanup();
			status_header( $status_code );
			die( $response ); // WPCS: XSS ok
		}
		exit;
	}

	/**
	 * @param $raw_body
	 *
	 * @throws Exception
	 */
	public function handle_ipn_request( $body ) {
		switch ( $body['transactionType'] ) {
			case 'CHARGE':
			case 'RECURRING':
				$this->charge( $body );
				break;
			case 'DECLINE':
				$this->decline( $body );
				break;
			case 'CHARGEBACK':
				$this->chargeback( $body );
				break;
			case 'CHARGEBACK_STATUS_CHANGED':
				$this->chargeback_status_changed( $body );
				break;
			case 'REFUND':
				$this->refund( $body );
				break;
			case 'CANCELLATION_REFUND':
				$this->refund( $body, 'cancelReason', true );
				break;
			case 'SUBSCRIPTION_CHARGE_FAILURE':
				$this->handle_subscription_charge_failure( $body );
				break;
		}
	}

	protected function log_request( $headers, $raw_body ) {
		foreach ( $headers as $key => $value ) {
			$headers[ $key ] = $key . ': ' . $value;
		}

		$headers = implode( "\n", $headers );

		WC_Bluesnap_Logger::log( 'IPN Request from ' . WC_Geolocation::get_ip_address() . ': ' . "\n\n" . $headers . "\n\n" . $raw_body . "\n", 'debug', 'ipn' );
	}

	protected function log_response( $response, $code = 200 ) {
		if ( empty( $response ) ) {
			$response = '--- EMPTY STRING ---';
		}

		WC_Bluesnap_Logger::log( 'IPN Response Status ' . $code . ':' . "\n\n" . $response . "\n", 'debug', 'ipn' );
	}

	protected function charge( $body ) {
		$order = $this->get_wc_order( $body );

		if ( function_exists( 'wcs_order_contains_resubscribe' ) && ( wcs_order_contains_resubscribe( $order ) || wcs_order_contains_renewal( $order ) ) && 'RECURRING' !== $body['transactionType'] ) {
			return; // Ignore CHARGE IPN for renewals.
		}

		if ( WC_BLUESNAP_ACH_GATEWAY_ID === $order->get_payment_method() && 'no' === $order->get_meta( '_bluesnap_charge_captured', true ) ) {
			$order->add_order_note( __( 'ACH Transaction approved via IPN request.', 'woocommerce' ) );
			$order->update_meta_data( '_bluesnap_charge_captured', 'yes' );
			$order->payment_complete();
			$order->save();

			if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, 'any' ) && ! empty( $body['subscriptionId'] ) ) {
				do_action( 'wc_gateway_bluesnap_save_subscription_id', $order, $body['subscriptionId'] );
			}

			$contains_pre_order = class_exists( 'WC_Pre_Orders_Order' ) && WC_Pre_Orders_Order::order_contains_pre_order( $order );

			// The ondemand subscription charge of a PreOrder is finalized only on the RECURRING IPN.
			if ( 'RECURRING' === $body['transactionType'] && $contains_pre_order ) {
				do_action( 'wc_bluesnap_ach_preorder_ipn', $order );
			} elseif ( $contains_pre_order ) {
				$order->set_status( $order->needs_processing() ? 'processing' : 'completed' );
				$order->save();
			}
		}
	}

	protected function decline( $body ) {
		$order = $this->get_wc_order( $body );

		if ( WC_BLUESNAP_ACH_GATEWAY_ID === $order->get_payment_method() && 'failed' !== $order->get_status() ) {
			$reason = sanitize_text_field( $body['declineReason'] );
			/* translators: %1$s reason */
			$order->update_status( 'failed', sprintf( __( 'BlueSnap ACH Declined IPN request received. A chargeback has been created for this transaction. Reason: %1$s.', 'woocommerce-bluesnap-gateway' ), $reason ) );
			$order->update_meta_data( '_bluesnap_charge_captured', 'no' );
			$order->save();
		}
	}

	/**
	 * Sent when a refund is issued.
	 * https://support.bluesnap.com/docs/default-ipns#section-refund
	 * @param $body
	 *
	 * @throws Exception
	 */
	protected function refund( $body, $reason_param = 'reversalReason', $cancel_sub = false ) {

		$order  = $this->get_wc_order( $body );
		$reason = $body[ $reason_param ];
		$amount = $body['reversalAmount'];

		if ( strpos( $reason, REFUND_REASON_PREFIX ) !== false ) {
			return;
		}

		/* translators: %1$s reason, %2$s amount */
		$order->add_order_note( sprintf( __( 'BlueSnap REFUND IPN received. Reason: %1$s. Refund amount: %2$s.', 'woocommerce-bluesnap-gateway' ), $reason, $amount ) );

		if ( 'refunded' === $order->get_status() ) {
			return;
		}

		$refund = wc_create_refund(
			array(
				'amount'   => bluesnap_format_decimal( $amount, $order->get_currency() ),
				'reason'   => $reason,
				'order_id' => $order->get_id(),
			)
		);

		if ( $cancel_sub && function_exists( 'wcs_order_contains_subscription' ) ) {
			// Code copied and modified from WCS
			if ( wcs_order_contains_subscription( $order, 'any' ) ) {
				$subscriptions = wcs_get_subscriptions_for_order( wcs_get_objects_property( $order, 'id' ), array( 'order_type' => 'any' ) );
				foreach ( $subscriptions as $subscription ) {
					if ( $subscription->can_be_updated_to( 'cancelled' ) ) {
						// translators: $1: opening link tag, $2: order number, $3: closing link tag
						$subscription->update_status( 'cancelled', wp_kses( sprintf( __( 'Subscription cancelled for refunded order %1$s#%2$s%3$s.', 'woocommerce-bluesnap-gateway' ), sprintf( '<a href="%s">', esc_url( wcs_get_edit_post_link( wcs_get_objects_property( $order, 'id' ) ) ) ), $order->get_order_number(), '</a>' ), array( 'a' => array( 'href' => true ) ) ) );
					}
				}
			}
			// WCS copy end
		}

		if ( is_wp_error( $refund ) ) {
			/* translators: %1$s reason */
			throw new Exception( sprintf( __( 'wc_create_refund failed: %1$s.', 'woocommerce-bluesnap-gateway' ), $refund->get_error_message() ) );
		}
	}

	/**
	 * Sent when an event is received from CB911 that does not open a new chargeback.
	 * https://support.bluesnap.com/docs/default-ipns#section-chargeback_status_changed
	 * @param $body
	 */
	protected function chargeback_status_changed( $body ) {
		$order  = $this->get_wc_order( $body );
		$reason = $body['reversalReason'];
		/* translators: %1$s reason */
		$order->add_order_note( sprintf( __( 'BlueSnap CHARGEBACK_STATUS_CHANGED IPN received. Reason: %1$s.', 'woocommerce-bluesnap-gateway' ), $reason ) );
		do_action( 'wc_gateway_bluesnap_chargeback_status_changed_ipn', $body, $order );
	}


	/**
	 * Sent when a shopper challenges a transaction with their issuing bank, who then initiates the chargeback process.
	 * https://support.bluesnap.com/docs/default-ipns#section-chargeback
	 * @param $body
	 */
	protected function chargeback( $body ) {
		$order = $this->get_wc_order( $body );

		if ( 'on-hold' === $order->get_status() ) {
			return;
		}

		$reason = $body['reversalReason'];
		/* translators: %1$s reason */
		$order->update_status( 'on-hold', sprintf( __( 'BlueSnap CHARGEBACK IPN request received. A chargeback has been created for this transaction. Reason: %1$s.', 'woocommerce-bluesnap-gateway' ), $reason ) );
		$order->update_meta_data( '_bluesnap_chargebacked', 'yes' );
		$order->save();
		do_action( 'wc_gateway_bluesnap_chargeback_ipn', $body, $order );

		$this->send_failed_order_email( $order->get_id(), $reason );
	}

	public function send_failed_order_email( $order_id, $reason = '' ) {
		$emails = WC()->mailer()->get_emails();
		if ( ! empty( $emails ) && ! empty( $order_id ) ) {
			$emails['WC_Bluesnap_Email_Chargeback_Order']->trigger( $order_id, $reason );
		}
	}


	/**
	 * Sent when a Subscription Charge has failed, provided that this IPN is activate on BlueSnap's side.
	 * Used here only for ACH orders were the result of a transaction is async.
	 * 
	 * @param $body
	 *
	 * @throws Exception
	 */
	protected function handle_subscription_charge_failure( $body ) {

		if ( ! empty( $body['failureReason'] ) && preg_match( '/Payment\sID\s(\d{5,15})\s/', urldecode( $body['failureReason'] ), $matches ) ) {
			$order = $this->get_wc_order_by_transaction_id( $matches[1] );

			if ( ! $order ) {
				throw new Exception( esc_html__( 'No Order found.', 'woocommerce-bluesnap-gateway' ), 400 );
			}

			if ( WC_BLUESNAP_ACH_GATEWAY_ID !== $order->get_payment_method() ) {
				return; // If not ACH bail.
			}

			/* translators: %1$s reason */
			$order->update_status( 'failed', sprintf( __( 'BlueSnap ACH Subscription Charge failure IPN received. Reason: %1$s.', 'woocommerce-bluesnap-gateway' ), urldecode( $body['failureReason'] ) ) );
		} else {
			throw new Exception( esc_html__( 'No Payment ID detected in Charge Failure IPN.', 'woocommerce-bluesnap-gateway' ), 400 );
		}

	}


	/**
	 * @param $body
	 *
	 * @return bool|WC_Order
	 */
	private function get_wc_order( $body ) {

		if ( empty( $body['merchantTransactionId'] ) && empty( $body['referenceNumber'] ) ) {
			throw new Exception( esc_html__( 'Empty merchantTransactionId & referenceNumber', 'woocommerce-bluesnap-gateway' ), 400 );
			return false;
		} elseif ( empty( $body['merchantTransactionId'] ) ) {

			$order = $this->get_wc_order_by_transaction_id( $body['referenceNumber'] );

			if ( ! $order ) {
				throw new Exception( esc_html__( 'Empty merchantTransactionId', 'woocommerce-bluesnap-gateway' ), 400 );
				return false;
			}
		} else {
			$order_id = $body['merchantTransactionId'];
			$order    = wc_get_order( $order_id );
		}

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			throw new Exception( esc_html__( 'Order could not be found', 'woocommerce-bluesnap-gateway' ), 400 );
			return false;
		}

		return $order;
	}

	/**
	 * Returns a WC_Order by its transaction_id, by doing a meta search query.
	 *
	 * @param string $transaction_id
	 * @return object WC_Order
	 */
	private function get_wc_order_by_transaction_id( $transaction_id ) {

		$cache_key = 'get_wc_order_by_transaction_id_' . sanitize_key( $transaction_id );
		$orders    = get_transient( $cache_key );

		if ( false === $orders ) {
			$orders = wc_get_orders(
				array(
					'ipn_transaction_id' => $transaction_id,
					'limit'              => 1,
				)
			);

			set_transient( $cache_key, $orders ); // No expiry. Data not expected to change.
		}

		return ! empty( $orders ) ? reset( $orders ) : false;
	}

	/**
	 * Validate incoming request against IP and User-Agent.
	 *
	 * @return bool
	 */
	private function validate_source( $headers, $raw_body ) {
		if ( empty( $headers ) || empty( $raw_body ) ) {
			throw new Exception( esc_html__( 'Empty request', 'woocommerce-bluesnap-gateway' ), 400 );
			return false;
		}

		$remote_ip = WC_Geolocation::get_ip_address();

		if ( ! in_array( $remote_ip, $this->get_ip_whitelist() ) ) {
			throw new Exception( esc_html__( 'Invalid IPN source', 'woocommerce-bluesnap-gateway' ), 401 );
			return false;
		}

		if ( isset( $headers['User-Agent'] ) && 'BlueSnap' !== $headers['User-Agent'] ) {
			throw new Exception( esc_html__( 'Invalid User-Agent', 'woocommerce-bluesnap-gateway' ), 400 );
			return false;
		}

		$body = array();
		parse_str( $raw_body, $body );

		if ( empty( $body['transactionType'] ) ) {
			throw new Exception( esc_html__( 'transactionType not set', 'woocommerce-bluesnap-gateway' ), 401 );
			return false;
		}

		return $body;
	}

	/**
	 * @return array
	 */
	private function get_ip_whitelist() {
		$is_sandbox = ( 'yes' === WC_Bluesnap()->get_option( 'testmode' ) ) ? true : false;
		return ( $is_sandbox ) ? self::BLUESNAP_SANDBOX_IPS : self::BLUESNAP_PROD_IPS;
	}

	/**
	 * getallheaders is only available for apache, we need a fallback in case of nginx or others,
	 * http://php.net/manual/es/function.getallheaders.php
	 * @return array|false
	 */
	private function get_all_headers() {
		if ( ! function_exists( 'getallheaders' ) ) {
			$headers = array();
			foreach ( $_SERVER as $name => $value ) {
				if ( substr( $name, 0, 5 ) == 'HTTP_' ) {
					$headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
				}
			}
			return $headers;

		} else {
			return getallheaders();
		}
	}

	/**
	 * Do not allow the same IPN request to be processed concurrently.
	 *
	 * @param array $raw_body
	 * @return void
	 *
	 * @throws Exception
	 */
	private function debounce_ipn_request( $raw_body ) {

		$this->debounce_key = 'bluesnap_ipn_debounce_' . md5( $raw_body );

		if ( false !== get_transient( $this->debounce_key ) ) {
			throw new Exception( __( 'IPN request repeated too soon or previous request exited abnormally.', 'woocommerce-bluesnap-gateway' ) );
		}

		set_transient( $this->debounce_key, time(), 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Cleanup debounce transient after IPN was processed.
	 *
	 * @return void
	 */
	private function ipn_cleanup() {
		if ( $this->debounce_key ) {
			delete_transient( $this->debounce_key );
		}
	}

}

new WC_Bluesnap_IPN_Webhooks();
