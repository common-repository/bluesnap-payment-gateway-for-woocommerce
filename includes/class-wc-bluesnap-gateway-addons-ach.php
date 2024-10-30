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
class WC_Bluesnap_Gateway_Addons_ACH extends WC_Bluesnap_Gateway_ACH {

	const ORDER_ONDEMAND_WALLET_ID = '_bluesnap_ondemand_subscription_id';

	use WC_Bluesnap_Gateway_Addons_Trait;

	/**
	 * WC_Bluesnap_Addons constructor.
	 */
	public function __construct() {
		parent::__construct();

		// Subscriptions related hooks.
		if ( class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' ) ) {
			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
			add_action( 'wc_gateway_bluesnap_save_subscription_id', array( $this, 'update_subscription_id' ), 10, 2 );
			// Filters
			add_filter( 'wc_' . $this->id . '_save_payment_method', array( $this, 'force_save_payment_method' ), 1, 2 );
		}

		// PreOrders hooks.
		if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
			add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array( $this, 'scheduled_pre_order_payment' ) );
			add_action( 'wc_bluesnap_ach_preorder_ipn', array( $this, 'cancel_subscription' ), 10, 1 );
		}

		add_filter( 'wc_' . $this->id . '_hide_save_payment_method_checkbox', array( $this, 'maybe_hide_save_checkbox' ) );

		// Extra subscription related restrictions, on top of validation.
		add_filter( 'woocommerce_payment_gateway_get_new_payment_method_option_html', array( $this, 'maybe_remove_add_new_method_html' ), 10, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'maybe_remove_add_new_method' ) );
		add_filter( 'woocommerce_can_subscription_be_updated_to_cancelled', array( $this, 'subscription_can_be_cancelled' ), 10, 2 );
		add_filter( 'woocommerce_subscriptions_can_item_be_switched', array( $this, 'subscription_can_be_switched' ), 10, 3 );
		add_filter( 'woocommerce_can_subscription_be_updated_to_new-payment-method', array( $this, 'subscription_can_be_cancelled' ), 10, 2 );
		add_filter( 'woocommerce_subscriptions_can_user_renew_early', array( $this, 'subscription_can_be_renewed' ), 10, 2 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'extra_ach_notices' ), 20 );
	}

	protected function get_adapted_payload_for_ondemand_wallet( $order ) {
		$payload = $this->fetch_transaction_payment_method_payload( $order );
		switch ( $payload->type ) {
			case 'new_ach':
				if ( empty( $payload->payload['vaultedShopperId'] ) ) {
					$payload->payload['payerInfo'] = $payload->payer_info;
				}
				unset( $payload->payload['cardHolderInfo'] );
				$payload->payload['paymentSource'] = array(
					'ecpInfo' => array(
						'billingContactInfo' => $payload->billing_info,
						'ecp'                => $payload->payload['ecpTransaction'],
					),
				);

				unset( $payload->payload['ecpTransaction'] );
				unset( $payload->payload['pfToken'] );
				unset( $payload->payload['storeCard'] );
				break;
			case 'token':
				$payload->payload['paymentSource'] = array(
					'ecpInfo' => array(
						'billingContactInfo' => $payload->billing_info,
						'ecp'                => $payload->payload['ecpTransaction'],
					),
				);
				unset( $payload->payload['ecpTransaction'] );
				unset( $payload->payload['creditCard'] );
				break;
			default:
				$payload = apply_filters( 'wc_gateway_bluesnap_ach_get_adapted_payload_for_ondemand_wallet_' . $payload->type, $payload );
				$payload = apply_filters( 'wc_gateway_bluesnap_ach_get_adapted_payload_for_ondemand_wallet', $payload );
				break;
		}
		return $payload;
	}

	/**
	 * Validate extra restrictions for Subscriptions + ACH
	 *
	 * @return bool
	 */
	public function validate_fields() {

		$errors = new WP_Error();

		if ( $this->is_subs_change_payment() ) {
			$errors->add( 'ach_subscription_update_not_supported', __( 'You cannot switch a subscription into using an ACH account.', 'woocommerce-bluesnap-gateway' ) );
		}

		if ( $this->is_order_pay_renewal() && WC_BLUESNAP_ACH_GATEWAY_ID !== $this->get_cart_renewal_order_method() ) {
			$errors->add( 'ach_subscription_renewal_not_supported', __( 'You cannot switch a subscription into using an ACH account.', 'woocommerce-bluesnap-gateway' ) );
		}

		// Forbid adding a new ACH token when customer has an active subscription with ACH as payment method. Having an existing subscriptions means we already have a token saved on BlueSnap's side.
		if ( ! $this->get_id_saved_payment_token_selected() && ! $this->can_add_more_ach_accounts() ) {
			$errors->add( 'no_multiple_ach_subscription', __( 'You cannot save/add multiple ACH/ECP accounts because you have a non-cancelled subscription.', 'woocommerce-bluesnap-gateway' ) );
		}

		// Forbid purchase of subscriptions when customer has multiple ACH tokens stored.
		if ( is_checkout() && $this->cart_contains_subscription() && is_user_logged_in() && count( WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), WC_BLUESNAP_ACH_GATEWAY_ID ) ) > 1 ) {
			$errors->add( 'no_subscription_multiple_ach', __( 'You cannot purchase a subscription using this payment method because you have multiple ACH/ECP accounts saved.', 'woocommerce-bluesnap-gateway' ) );
		}

		// Forbid free trials
		if ( is_checkout() && class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_free_trial() ) {
			$errors->add( 'no_subscription_trial_ach', __( 'You cannot purchase a subscription offering a free-trial using this payment method.', 'woocommerce-bluesnap-gateway' ) );
		}

		// Forbid subscriptions < 1 week duration.
		if ( is_checkout() && ( class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) && 7 > $this->get_cart_shortest_subscription_interval() ) {
			$errors->add( 'no_subscription_trial_ach', __( 'You cannot purchase a subscription with a renewal period smaller than 1 week using this payment method.', 'woocommerce-bluesnap-gateway' ) );
		}

		$errors = apply_filters( 'wc_gateway_bluesnap_validate_fields', $errors );

		$errors_messages = $errors->get_error_messages();

		if ( ! empty( $errors_messages ) ) {
			foreach ( $errors_messages as $message ) {
				wc_add_notice( $message, 'error' );
			}
			return false;
		}

		return parent::validate_fields();
	}

	public function maybe_remove_add_new_method( $gateways ) {

		if ( 'no' === $this->enabled ) {
			return $gateways;
		}

		// Show No ACH on checkout when multiple ACH accounts saved
		if ( is_checkout() && wp_doing_ajax() && $this->cart_contains_subscription() && count( WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), WC_BLUESNAP_ACH_GATEWAY_ID ) ) > 1 ) {
			unset( $gateways[ WC_BLUESNAP_ACH_GATEWAY_ID ] );
		}

		// Show No ACH on checkout when subscription < 1 week duration.
		if ( isset( $gateways[ WC_BLUESNAP_ACH_GATEWAY_ID ] ) && is_checkout() && wp_doing_ajax() && ( class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) && 7 > $this->get_cart_shortest_subscription_interval() ) {
			unset( $gateways[ WC_BLUESNAP_ACH_GATEWAY_ID ] );
		}

		if ( isset( $gateways[ WC_BLUESNAP_ACH_GATEWAY_ID ] ) && is_checkout() && wp_doing_ajax() && $this->is_order_pay_renewal() && WC_BLUESNAP_ACH_GATEWAY_ID !== $this->get_cart_renewal_order_method() ) {
			unset( $gateways[ WC_BLUESNAP_ACH_GATEWAY_ID ] );
		}

		// Show No ACH on subscription change payment method. (API doesn't support a change to ACH)
		if ( isset( $gateways[ WC_BLUESNAP_ACH_GATEWAY_ID ] ) && $this->is_subs_change_payment() ) {
			unset( $gateways[ WC_BLUESNAP_ACH_GATEWAY_ID ] );
		}

		// Check if on a Subscription Switch and subscription's payment method isn't BS Apple Pay.
		if ( isset( $gateways[ WC_BLUESNAP_ACH_GATEWAY_ID ] ) && is_checkout() && wp_doing_ajax() ) {
			$switch = class_exists( 'WC_Subscriptions_Switcher' ) ? WC_Subscriptions_Switcher::cart_contains_switches() : false;

			if ( $switch ) {
				$cart_item       = reset( $switch );
				$subscription_id = $cart_item['subscription_id'];
				$subscription    = $subscription_id ? wc_get_order( $subscription_id ) : null;

				if ( WC_BLUESNAP_ACH_GATEWAY_ID !== $subscription->get_payment_method() ) {
					unset( $gateways[ WC_BLUESNAP_ACH_GATEWAY_ID ] );
				}
			}
		}

		// Show No ACH on Add payment method when we cannot add more.
		if ( isset( $gateways[ WC_BLUESNAP_ACH_GATEWAY_ID ] ) && is_add_payment_method_page() && ! $this->can_add_more_ach_accounts() ) {
			unset( $gateways[ WC_BLUESNAP_ACH_GATEWAY_ID ] );
		}

		return $gateways;
	}

	public function maybe_remove_add_new_method_html( $html, $gateway ) {
		if ( WC_BLUESNAP_ACH_GATEWAY_ID === $gateway->id && ! $this->can_add_more_ach_accounts() ) {
			$html = '';
		}

		return $html;
	}

	protected function can_add_more_ach_accounts() {
		$has_ach_subscription = false;

		if ( ! function_exists( 'wcs_get_users_subscriptions' ) || ! is_user_logged_in() ) {
			return true;
		}

		// Check early if we got a subscription in cart and a token saved.
		if ( is_checkout() && $this->cart_contains_subscription() && count( WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), WC_BLUESNAP_ACH_GATEWAY_ID ) ) > 0 ) {
			return false;
		}

		$subscriptions = wcs_get_users_subscriptions( get_current_user_id() );

		if ( $subscriptions ) {
			foreach ( $subscriptions as $subscription ) {
				if ( WC_BLUESNAP_ACH_GATEWAY_ID === $subscription->get_payment_method() && 'cancelled' !== $subscription->get_status() ) {
					$has_ach_subscription = true;
					break;
				}
			}
		}

		return ! $has_ach_subscription;
	}


	public function extra_ach_notices() {

		$notice = false;

		if ( ! $notice && is_checkout() && $this->cart_contains_subscription() && count( WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), WC_BLUESNAP_ACH_GATEWAY_ID ) ) > 1 ) {
			/* Translators: The gateway title */
			$notice = sprintf( __( '%s is not currently available for subscription products when there are multiple saved accounts.', 'woocommerce-bluesnap-gateway' ), $this->title );
		}

		if ( ! $notice && is_checkout() && ( class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) && 7 > $this->get_cart_shortest_subscription_interval() ) {
			/* Translators: The gateway title */
			$notice = sprintf( __( 'You can\'t purchase a subscription with a renewal period smaller than 1 week using %s.', 'woocommerce-bluesnap-gateway' ), $this->title );
		}

		if ( ! $notice && is_add_payment_method_page() && ! $this->can_add_more_ach_accounts() ) {
			/* Translators: The gateway title */
			$notice = sprintf( __( '%s doesn\'t support adding multiple accounts for existing or new subscriptions.', 'woocommerce-bluesnap-gateway' ), $this->title );
		}

		if ( $notice ) {
			wc_print_notice( $notice, 'notice' );
		}
	}


	protected function adapt_new_ach_subscription_payload_to_saved( $payment_method_data ) {

		$payment_method_data->payload['paymentSource']['ecpInfo']['ecp']['publicAccountNumber'] = substr( $payment_method_data->payload['paymentSource']['ecpInfo']['ecp']['accountNumber'], -5 );
		$payment_method_data->payload['paymentSource']['ecpInfo']['ecp']['publicRoutingNumber'] = substr( $payment_method_data->payload['paymentSource']['ecpInfo']['ecp']['routingNumber'], -5 );

		$payment_method_data->type     = 'token'; // Also avoids wc_gateway_bluesnap_new_ach_payment_success action from re-inserting the token.
		$payment_method_data->saveable = false;

		unset( $payment_method_data->payload['paymentSource']['ecpInfo']['ecp']['accountNumber'] );
		unset( $payment_method_data->payload['paymentSource']['ecpInfo']['ecp']['routingNumber'] );

		return $payment_method_data;
	}

	public function subscription_can_be_cancelled( $can_be_updated, $subscription ) {
		return $this->subscription_has_pending_ach_payment( $subscription ) ? false : $can_be_updated;
	}

	public function subscription_can_be_switched( $can_be_switched, $item, $subscription ) {
		return $this->subscription_has_pending_ach_payment( $subscription ) ? false : $can_be_switched;
	}

	public function subscription_can_be_renewed( $can_renew_early, $subscription ) {
		return $this->subscription_has_pending_ach_payment( $subscription ) ? false : $can_renew_early;
	}

	/**
	 * Checks that the last order related to the subscription is on ACH and hasn't been captured yet.
	 */
	private function subscription_has_pending_ach_payment( $subscription ) {

		if ( WC_BLUESNAP_ACH_GATEWAY_ID !== $subscription->get_payment_method() ) {
			return false;
		}

		$orders = $subscription->get_related_orders( 'all', array( 'parent', 'renewal', 'resubscribe', 'switch' ) );
		krsort( $orders );

		$captured = ! empty( $orders ) && is_array( $orders ) ? ( 'no' !== reset( $orders )->get_meta( '_bluesnap_charge_captured', true ) ) : false;

		return ! empty( $orders ) && ! $captured;
	}
}
