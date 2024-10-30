<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles and process orders from asyncronous flows.
 *
 *
 */
class WC_Bluesnap_Order_Handler extends WC_Bluesnap_Gateway {
	private static $instance;

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		self::$instance = $this;

		add_action( 'woocommerce_order_status_on-hold_to_processing', array( $this, 'capture_payment' ) );
		add_action( 'woocommerce_order_status_on-hold_to_completed', array( $this, 'capture_payment' ) );
		add_action( 'woocommerce_order_status_on-hold_to_cancelled', array( $this, 'cancel_payment' ) );
		add_action( 'woocommerce_order_status_on-hold_to_refunded', array( $this, 'cancel_payment' ) );
		add_action( 'admin_init', array( $this, 'maybe_refresh_ach_status' ) );
	}

	/**
	 * Public access to instance object.
	 *
	 */
	public static function get_instance() {
		return self::$instance;
	}

	public function maybe_refresh_ach_status() {

		if ( ! isset( $_GET['refresh_ach_status'] ) || ! is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$order          = isset( $_GET['post'] ) ? wc_get_order( absint( $_GET['post'] ) ) : false;
		$payment_method = is_a( $order, 'WC_Order' ) ? $order->get_payment_method() : false;

		if ( ! empty( $payment_method ) && WC_BLUESNAP_ACH_GATEWAY_ID === $payment_method ) {
			$this->refresh_ach_status( $order );
			wp_safe_redirect( remove_query_arg( 'refresh_ach_status', home_url() . $_SERVER['REQUEST_URI'] ) );
			die();
		}
	}


	public function refresh_ach_status( $order ) {

		$transaction_id = $order->get_transaction_id();

		if ( WC_BLUESNAP_ACH_GATEWAY_ID !== $order->get_payment_method() || ! $transaction_id ) {
			return;
		}

		try {

			$result = WC_Bluesnap_API::retrieve_alt_transaction( $transaction_id );

			$status = isset( $result['processingInfo'] ) && isset( $result['processingInfo']['processingStatus'] ) ? $result['processingInfo']['processingStatus'] : false;

			switch ( $status ) {
				case 'PENDING':
					$order->update_status( 'on-hold', __( 'ACH Transaction not yet approved', 'woocommerce-gateway-bluesnap' ) );
					break;
				case 'SUCCESS':
					$order->update_status( 'processing', __( 'ACH Transaction approved', 'woocommerce-gateway-bluesnap' ) );
					$order->update_meta_data( '_bluesnap_charge_captured', 'yes' );
					$order->save();

					if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, 'any' ) && ! empty( $result['subscriptionId'] ) ) {
						do_action( 'wc_gateway_bluesnap_save_subscription_id', $order, $result['subscriptionId'] );
					}

					break;
				case 'FAIL':
					$order->update_status( 'failed', __( 'ACH Transaction was canceled and the shopper\'s account was not debited', 'woocommerce-gateway-bluesnap' ) );
					break;
				case 'REFUNDED':
					$order->update_status( 'refunded', __( 'ACH Transaction was refunded in response to a chargeback or refund requested by the shopper', 'woocommerce-gateway-bluesnap' ) );
					break;
				default:
					/* Translators: the order status as returned from the API */
					$order->add_order_note( sprintf( __( 'ACH Transaction status retrieved manually: %s', 'woocommerce-gateway-bluesnap' ), $status ) );
					break;
			}
		} catch ( WC_Bluesnap_API_Exception $e ) {
			WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
			$order->add_order_note( __( 'ACH Transaction status retrieval failed.', 'woocommerce-gateway-bluesnap' ) );

			return new WP_Error( 'refresh_ach_status_error', __( 'An error occurred during the ACH refresh status request. Review the WooCommerce logs for specific details about the error. If the logs don\'t resolve the issue, contact BlueSnap Merchant Support for assistance.', 'woocommerce-bluesnap-gateway' ) );
		}

	}

	/**
	 * Capture payment when the order is changed from on-hold to complete or processing.
	 *
	 * @param  int $order_id
	 */
	public function capture_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( 'bluesnap' === $order->get_payment_method() ) {

			$transaction_id       = $order->get_transaction_id();
			$captured             = $order->get_meta( '_bluesnap_charge_captured', true );
			$is_bluesnap_captured = false;

			if ( $transaction_id && 'no' === $captured ) {
				$order_total = $order->get_total();

				if ( 0 < $order->get_total_refunded() ) {
					$order_total = $order_total - $order->get_total_refunded();
				}

				try {

					// First retrieve charge to see if it has been captured.
					$result = WC_Bluesnap_API::retrieve_transaction( $transaction_id );

					if ( ! empty( $result->error ) ) {
						/* translators: error message */
						$order->add_order_note( sprintf( __( 'Unable to capture charge! %s', 'woocommerce-gateway-bluesnap' ), $result->error->message ) );
					} elseif ( self::AUTH_ONLY === $result['cardTransactionType'] ) {

						$payload = array(
							'amount'              => bluesnap_format_decimal( $order->get_total(), $order->get_currency() ),
							'transactionId'       => $transaction_id,
							'cardTransactionType' => self::CAPTURE,
						);

						$result = WC_Bluesnap_API::create_transaction( $payload, 'PUT' );

						if ( ! empty( $result->error ) ) {
							/* translators: error message */
							$order->update_status( 'failed', sprintf( __( 'Unable to capture charge! %s', 'woocommerce-gateway-bluesnap' ), $result->error->message ) );
						} else {
							$is_bluesnap_captured = true;
						}
					} elseif ( self::AUTH_ONLY !== $result['cardTransactionType'] ) {
						$is_bluesnap_captured = true;
					}

					if ( $is_bluesnap_captured ) {
						/* translators: transaction id */
						$order->add_order_note( sprintf( __( 'Bluesnap charge complete (Charge ID: %s)', 'woocommerce-gateway-bluesnap' ), $result['transactionId'] ) );
						$order->update_meta_data( '_bluesnap_charge_captured', 'yes' );

						if ( is_callable( array( $order, 'save' ) ) ) {
							$order->save();
						}

						do_action( 'woocommerce_bluesnap_process_manual_capture', $order, $result );
					}
				} catch ( Exception $e ) {
					WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
					/* translators: Error message */
					$order->update_status( 'on-hold', sprintf( __( 'Unable to capture charge! %s', 'woocommerce-gateway-bluesnap' ), $e->getMessage() ) );

					do_action( 'wc_gateway_bluesnap_process_manual_capture_error', $e, $order );
				}
			}
		}
	}

	/**
	 * Cancel pre-auth on refund/cancellation.
	 *
	 * @param  int $order_id
	 */
	public function cancel_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( 'bluesnap' === $order->get_payment_method() ) {

			$captured = $order->get_meta( '_bluesnap_charge_captured', true );

			if ( 'no' === $captured ) {
				$this->process_refund( $order_id );
			}

			// This hook fires when admin manually changes order status to cancel.
			do_action( 'woocommerce_bluesnap_process_manual_cancel', $order );
		}
	}
}

new WC_Bluesnap_Order_Handler();
