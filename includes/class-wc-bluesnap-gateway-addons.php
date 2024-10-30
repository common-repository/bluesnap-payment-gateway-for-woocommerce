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
 * Class to handle all woo addons integrations.
 * - Subscriptions.
 *
 * Class WC_Bluesnap_Gateway_Addons
 */
class WC_Bluesnap_Gateway_Addons extends WC_Bluesnap_Gateway {

	use WC_Bluesnap_Gateway_Addons_Trait;

	const ORDER_ONDEMAND_WALLET_ID = '_bluesnap_ondemand_subscription_id';

	/**
	 * WC_Bluesnap_Addons constructor.
	 */
	public function __construct() {
		parent::__construct();

		// Subscriptions related hooks.
		if ( class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' ) ) {
			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
			add_action( 'wc_gateway_bluesnap_save_subscription_id', array( $this, 'update_subscription_id' ), 10, 2 );
			add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_subscription' ) );
			add_action( 'wc_gateway_bluesnap_chargeback_ipn', array( $this, 'cancel_subscription_on_chargeback' ), 10, 2 );
			// Filters
			add_filter( 'wc_' . $this->id . '_save_payment_method', array( $this, 'force_save_payment_method' ), 1, 2 );
			add_filter( 'wc_gateway_bluesnap_payment_request_cart_item_line_items', array( $this, 'add_subscription_free_trial' ), 10, 4 );
			add_filter( 'wc_gateway_bluesnap_payment_request_items', array( $this, 'add_recurring_totals' ), 10, 4 );
			add_filter( 'bluesnap_3ds_total_amount', array( $this, 'maybe_remove_3ds_amount' ), 20, 1 );
		}

		// PreOrders hooks.
		if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
			add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array( $this, 'scheduled_pre_order_payment' ) );
			add_filter( 'wc_gateway_bluesnap_payment_request_items', array( $this, 'add_pre_order_line' ), 10, 4 );
			add_filter( 'wc_gateway_bluesnap_payment_request_calculated_total', array( $this, 'remove_pre_order_from_total' ), 10 );
			add_filter( 'bluesnap_3ds_total_amount', array( $this, 'remove_pre_order_from_total' ) );
		}

		add_filter( 'wc_gateway_bluesnap_payment_request_apple_pay_version_required', array( $this, 'bump_apple_pay_version' ) );
		add_filter( 'wc_' . $this->id . '_hide_save_payment_method_checkbox', array( $this, 'maybe_hide_save_checkbox' ) );
	}


	protected function get_adapted_payload_for_ondemand_wallet( $order ) {
		$payload = $this->fetch_transaction_payment_method_payload( $order );
		switch ( $payload->type ) {
			case 'new_card':
				if ( empty( $payload->payload['vaultedShopperId'] ) ) {
					$payload->payload['payerInfo'] = $payload->payer_info;
				}
				unset( $payload->payload['cardHolderInfo'] );
				$payload->payload['paymentSource'] = array(
					'creditCardInfo' => array(
						'pfToken'            => $payload->payload['pfToken'],
						'billingContactInfo' => $payload->billing_info,
					),
				);
				unset( $payload->payload['pfToken'] );
				unset( $payload->payload['storeCard'] );
				break;
			case 'token':
				$payload->payload['paymentSource'] = array(
					'creditCardInfo' => array(
						'creditCard'         => $payload->payload['creditCard'],
						'billingContactInfo' => $payload->billing_info,
					),
				);
				unset( $payload->payload['creditCard'] );
				break;
			default:
				$payload = apply_filters( 'wc_gateway_bluesnap_get_adapted_payload_for_ondemand_wallet_' . $payload->type, $payload );
				$payload = apply_filters( 'wc_gateway_bluesnap_get_adapted_payload_for_ondemand_wallet', $payload );
				break;
		}
		return $payload;
	}


	public function addons_related_payment_request_restrictions( $show, $post, $payment_request ) {

		if ( $this->is_order_pay_renewal() ) {
			$order = $this->get_cart_renewal_order();

			return $payment_request->get_title() === $order->get_payment_method_title();
		}

		return true;
	}


	public function maybe_remove_3ds_amount( $amount ) {
		if ( $this->is_subs_change_payment() ) {
			$amount = 0;
		}

		return $amount;
	}
}
