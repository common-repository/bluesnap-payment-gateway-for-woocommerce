<?php

trait WC_Bluesnap_Gateway_Addons_Trait {

	/**
	 * It will process the payment depending on its type.
	 *
	 * @param int $order_id
	 * @param string $transaction_type
	 * @param null|string $override_total
	 * @param bool $payment_complete
	 *
	 * @return array
	 * @throws Exception
	 */
	public function process_payment( $order_id, $transaction_type = self::AUTH_AND_CAPTURE, $override_total = null, $payment_complete = true ) {
		if ( $this->has_subscription( $order_id ) ) {
			return $this->process_subscription( $order_id );
		} elseif ( $this->has_pre_order( $order_id ) ) {
			return $this->process_pre_order( $order_id );
		}
		// Fallback to normal process payment.
		return parent::process_payment( $order_id );
	}

	public function create_ondemand_wallet( $order_id, $force_amount = null, $scheduled = false ) {
		$order = wc_get_order( $order_id );
		try {
			$payload = $this->get_adapted_payload_for_ondemand_wallet( $order );

			// When using ACH, the API ignores the (new) ACH source if a shopperID is set. So we need to update the shopper first.
			if ( 'token' !== $payload->type &&
			! empty( $payload->payload['vaultedShopperId'] ) &&
			! empty( $payload->payload['paymentSource'] ) &&
			! empty( $payload->payload['paymentSource']['ecpInfo'] ) &&
			! empty( $payload->payload['paymentSource']['ecpInfo']['ecp'] )
			) {
				$this->handle_add_posted_shopper_method( $payload );
				$payload = $this->adapt_new_ach_subscription_payload_to_saved( $payload );
			}

			$create_payload = array_merge(
				array(
					'amount'                => bluesnap_format_decimal( ! is_null( $force_amount ) ? $force_amount : $order->get_total(), $order->get_currency() ),
					'currency'              => $order->get_currency(),
					'merchantTransactionId' => $order_id,
				),
				$payload->payload
			);

			if ( $scheduled ) {
				$create_payload['scheduled'] = (bool) $scheduled;
			}

			$subscription = WC_Bluesnap_API::create_ondemand_subscription( $create_payload );

			if ( ! $payload->shopper_id ) {
				$payload->shopper_id = $subscription['vaultedShopperId'];
				$payload->shopper->set_bluesnap_shopper_id( $payload->shopper_id );
			}
			if ( isset( $payload->saveable ) && $payload->saveable ) {
				WC_Bluesnap_Token::create_wc_token( isset( $subscription['paymentSource']['creditCardInfo'] ) ? $subscription['paymentSource']['creditCardInfo'] : $subscription['paymentSource']['ecpInfo'] );
			}

			if ( ! empty( $subscription['subscriptionId'] ) ) {
				$this->save_related_addon_order_info( $order, $subscription['subscriptionId'] );
			}

			if ( $subscription['amount'] > 0 ) {
				$this->add_order_success_note( $order, $subscription['transactionId'] );
			}

			if ( ! ( isset( $subscription['processingInfo']['processingStatus'] ) && 'PENDING' === $subscription['processingInfo']['processingStatus'] ) ) {
				$order->update_meta_data( '_bluesnap_charge_captured', 'yes' );
				$order->payment_complete( $subscription['transactionId'] );
			} else {
				$order->update_meta_data( '_bluesnap_charge_captured', 'no' );
				$order->set_transaction_id( $subscription['transactionId'] );

				if ( $subscription['amount'] > 0 ) {
					/* Translators: Transaction ID */
					$order->update_status( 'on-hold', sprintf( __( 'Charge pending confirmation from BlueSnap (Charge ID: %s).', 'woocommerce-bluesnap-gateway' ), $subscription['transactionId'] ) );
				}
			}
			$order->save();
		} catch ( Exception $e ) {
			self::hpf_clean_transaction_token_session();
			WC()->session->set( 'refresh_totals', true ); // this triggers refresh of the checkout area
			WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
			wc_add_notice( $e->getMessage(), 'error' );

			do_action( 'wc_gateway_bluesnap_process_payment_error', $e, $order );
			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}

		// It is a success anyways, since the order at this point is completed.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);

	}

	/**
	 * Update subscription Id asynchronously
	 *
	 * @param WC_Order $order
	 * @param int $subscription_id
	 *
	 * @return void
	 */
	public function update_subscription_id( $order, $subscription_id ) {
		$this->save_related_addon_order_info( $order, $subscription_id );
	}

	/**
	 * It process a subscription.
	 * For free trials subscriptions, we need to create or update vaulted shopper with its cc (from ptoken) ourselves.
	 * This Bluesnap limitation happens with prepaid (0$ orders) too.
	 *
	 * @param $order_id
	 *
	 * @return array
	 * @throws Exception
	 */
	public function process_subscription( $order_id ) {
		if ( $this->is_subs_change_payment() ) {
			return $this->change_or_add_payment_method( $order_id );
		}

		$order = wc_get_order( $order_id );

		if ( 'no' === $order->get_meta( '_bluesnap_charge_captured', true ) && WC_BLUESNAP_ACH_GATEWAY_ID === $order->get_payment_method() ) {
			$this->get_order_ondemand_wallet_id( $order, false ); // Abort if on ACH and we already have processed a transaction.
		}

		if ( ! $this->get_order_ondemand_wallet_id( $order ) ) {
			$subs = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'any' ) ) );
			if ( ! empty( $subs ) ) {
				$sub_keys = array_keys( $subs );
				$sub_id   = reset( $sub_keys );

				if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $sub_id ) ) {
					do_action( 'wc_bluesnap_maybe_migrate', $sub_id ); // Handle edge cases were we are here but our subscription is not migrated from the old plugin.
					$sub = wc_get_order( $sub_id );
					$order->update_meta_data( self::ORDER_ONDEMAND_WALLET_ID, $this->get_order_ondemand_wallet_id( $sub ) );
					$order->save();
				}
			}
		}

		if ( $this->get_order_ondemand_wallet_id( $order ) ) {
			return $this->change_or_add_payment_method( $order_id, true );
		} else {
			return $this->create_ondemand_wallet( $order_id, null, true );
		}
	}

	/**
	 * It process a preorder.
	 * If charged upfront, normal flow, otherwise process as preorder.
	 * For used tokens, it AUTH_ONLY with 0 dollars value, for new cards, we save card ourselves (edge case from Bluensap)
	 *
	 * @param $order_id
	 *
	 * @return array
	 * @throws Exception
	 */
	public function process_pre_order( $order_id ) {
		if ( WC_Pre_Orders_Order::order_requires_payment_tokenization( $order_id ) ) {

			$response = $this->create_ondemand_wallet( $order_id, 0 );

			if ( 'success' === $response['result'] ) {
				// Remove from cart
				WC()->cart->empty_cart();
				// Mark order as preordered
				WC_Pre_Orders_Order::mark_order_as_pre_ordered( $order_id );
			}

			return $response;
		}
		// Preorder charged upfront or order used "pay later" gateway
		// and now is a normal order needing payment, normal process.
		return parent::process_payment( $order_id );
	}



	/**
	 * It will add/change payment method to vaulted shopper (updated or created) from the payload.
	 * When a subscription changes its method to be payed.
	 * When a subscription (with free trial) or prepaid (0 value), cc needs to be saved to vaulted shopper.
	 *
	 * @param $order_id
	 *
	 * @return array
	 */
	protected function change_or_add_payment_method( $order_id, $do_payment = false ) {
		$order = wc_get_order( $order_id );
		try {
			$payload = $this->get_adapted_payload_for_ondemand_wallet( $order );
			unset( $payload->payload['softDescriptor'] );

			// ACH doesn't support subscription update.
			if ( WC_BLUESNAP_ACH_GATEWAY_ID !== $this->id ) {
				$transaction = WC_Bluesnap_API::update_subscription(
					$this->get_order_ondemand_wallet_id( $order, false ),
					$payload->payload
				);
			}

			if ( ! $this->is_subs_change_payment() ) {
				$this->maybe_update_payment_method_info( $order );
			}

			if ( $do_payment ) {
				$this->process_addon_payment( $order );
			}

			if ( isset( $transaction ) && isset( $payload->saveable ) && $payload->saveable ) {
				WC_Bluesnap_Token::create_wc_token( isset( $transaction['paymentSource']['creditCardInfo'] ) ? $transaction['paymentSource']['creditCardInfo'] : $transaction['paymentSource']['ecpInfo'] ); // ?
			}

			self::hpf_clean_transaction_token_session();
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		} catch ( Exception $e ) {
			self::hpf_clean_transaction_token_session();
			WC()->session->set( 'refresh_totals', true ); // this triggers refresh of the checkout area
			WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
			wc_add_notice( $e->getMessage(), 'error' );

			add_filter( 'woocommerce_subscriptions_process_payment_for_change_method_via_pay_shortcode', array( $this, 'do_redirect_on_failure_to_change' ) );

			$redirect = $this->get_return_url( $order );

			if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order ) ) {
				$redirect = $order->get_change_payment_method_url();
			} else {
				do_action( 'wc_gateway_bluesnap_process_payment_error', $e, $order );
			}

			return array(
				'result'   => 'failure',
				'redirect' => $redirect,
			);
		}
	}

	/**
	 * Maybe update the payment method info of a subscription.
	 *
	 * @param WC_Order $order The new subscription order.
	 */
	private function maybe_update_payment_method_info( $order ) {
		if ( ! $order ) {
			return;
		}

		// Since running WC_Bluesnap_API::update_subscription updates the payment method on the BS side, any subsequent payment attempt will happen with the new gateway.
		// So the payment method of the subscription should reflect that.
		// And doing a switch, by design doesn't update the subscription's method.
		$subscription = wcs_is_subscription( $order ) ? $order : null;

		if ( is_null( $subscription ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'any' ) ) );
			$subscription  = ! empty( $subscriptions ) ? reset( $subscriptions ) : $subscription;
		}

		if ( ! $subscription ) {
			return;
		}

		$payment_request_order = 'yes' === $order->get_meta( '_bluesnap_payment_request_order', true );
		$payment_request_title = $order->get_meta( '_bluesnap_payment_request_title', true );

		$title = $this->title;

		if ( $payment_request_order && $payment_request_title ) {
			$title = $payment_request_title;
		}
		
		$subscription->set_payment_method( $this->id );
		$subscription->set_payment_method_title( $title );
		$subscription->save();

		// Prevent to change the payment method again.
		if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
			remove_action( 'woocommerce_subscriptions_paid_for_failed_renewal_order', 'WC_Subscriptions_Change_Payment_Gateway::change_failing_payment_method', 10, 2 );
		}
	}

	public function do_redirect_on_failure_to_change( $result ) {
		if ( 'success' == $result['result'] ) {
			return;
		}

		wp_redirect( $result['redirect'] );
		exit;
	}

	/**
	 * Process Renewal subscriptions process. WC called.
	 *
	 * @param $amount_to_charge
	 * @param WC_Order $renewal_order
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
		try {
			$this->process_addon_payment( $renewal_order );

			do_action( 'processed_subscription_payments_for_order', $renewal_order );
			do_action( 'wc_bluesnap_scheduled_subscription_success', $amount_to_charge, $renewal_order );
		} catch ( Exception | WC_Bluesnap_API_Exception $e ) {

			$order_note = __( 'Error processing scheduled_subscription_payment. Reason: ', 'woocommerce-bluesnap-gateway' ) . $e->getMessage();

			if ( ! $renewal_order->has_status( 'failed' ) ) {
				$renewal_order->update_status( 'failed', $order_note );
			} else {
				$renewal_order->add_order_note( $order_note );
			}

			if ( isset( $_REQUEST['process_early_renewal'] ) && ! wp_doing_cron() ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wc_add_notice( $e->getMessage(), 'error' );
			}

			WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );

			do_action( 'processed_subscription_payment_failure_for_order', $renewal_order );
			do_action( 'wc_bluesnap_scheduled_subscription_failure', $amount_to_charge, $renewal_order );
		}
	}



	/**
	 * Hooked when a subscription gets cancelled.
	 *
	 * @param WC_Subscription $subscription
	 */
	public function cancel_subscription( $subscription ) {

		$bluesnap_gateway_ids = array(
			WC_BLUESNAP_ACH_GATEWAY_ID,
			WC_BLUESNAP_GATEWAY_ID,
		);

		if ( ! in_array( $subscription->get_payment_method(), $bluesnap_gateway_ids, true ) ) {
			return;
		}

		WC_Bluesnap_API::update_subscription(
			$this->get_order_ondemand_wallet_id( $subscription, false ),
			array(
				'status' => 'CANCELED',
			)
		);
	}

	/**
	 * Process Preorder when it's time. WC called.
	 *
	 * @param WC_Order $order
	 */
	public function scheduled_pre_order_payment( $order ) {
		try {

			if ( ! $this->get_order_ondemand_wallet_id( $order, true ) ) {
				return false;
			}

			$this->process_addon_payment( $order );

			if ( ! ( 'no' === $order->get_meta( '_bluesnap_charge_captured', true ) && WC_BLUESNAP_ACH_GATEWAY_ID === $order->get_payment_method() ) ) {
				$this->cancel_subscription( $order );
			}

			do_action( 'wc_bluesnap_scheduled_preorder_success', $order );
		} catch ( WC_Bluesnap_API_Exception $e ) {
			$order_note = __( 'Error processing preorder payment. Reason: ', 'woocommerce-bluesnap-gateway' ) . $e->getMessage();
			$order->update_status( 'failed', $order_note );
			WC_Bluesnap_Logger::log( $order_note, 'error' );
			do_action( 'wc_bluesnap_scheduled_preorder_failure', $order );
		}
	}

	/**
	 * Wrapper to process payment for different addons needs.
	 *
	 * @param WC_Order $order
	 * @param $amount_to_charge
	 *
	 * @return array
	 * @throws WC_Bluesnap_API_Exception
	 */
	protected function process_addon_payment( $order ) {

		// Take care of Orders from the old plugin which are stored with the Base shop currency
		$subscription_id = $order->get_meta( '_subscription_renewal' );
		$parent_id       = ! empty( $subscription_id ) ? wc_get_order( $subscription_id )->get_parent_id() : null;
		$parent_order    = $parent_id ? wc_get_order( $parent_id ) : $order;

		$charged_currency = $parent_order->get_meta( '_charged_currency' );
		if ( ! empty( $charged_currency ) && $charged_currency !== $order->get_currency() ) {
			$ex_rate  = $parent_order->get_meta( '_bsnp_ex_rate' );
			$amount   = ! empty( $ex_rate ) ? ( floatval( $ex_rate ) * $order->get_total() ) : $order->get_total();
			$currency = $charged_currency;
			$order->update_meta_data( '_charged_currency', $charged_currency );
			$order->update_meta_data( '_bsnp_ex_rate', $ex_rate );
			$order->save();
		} else {
			$amount   = $order->get_total();
			$currency = $order->get_currency();
		}

		$transaction = WC_Bluesnap_API::create_ondemand_subscription_charge(
			$this->get_order_ondemand_wallet_id( $order, false ),
			array(
				'amount'                => bluesnap_format_decimal( $amount, $currency ),
				'currency'              => $currency,
				'merchantTransactionId' => $order->get_id(),
			)
		);

		if ( $transaction['amount'] > 0 ) {
			$this->add_order_success_note( $order, $transaction['transactionId'] );

			if ( ! ( isset( $transaction['processingInfo']['processingStatus'] ) && 'PENDING' === $transaction['processingInfo']['processingStatus'] ) ) {
				$order->payment_complete( $transaction['transactionId'] );
				$order->update_meta_data( '_bluesnap_charge_captured', 'yes' );
			} else {
				$order->update_meta_data( '_bluesnap_charge_captured', 'no' );
				$order->set_transaction_id( $transaction['transactionId'] );
				/* Translators: Transaction ID */
				$order->update_status( 'on-hold', sprintf( __( 'Charge pending confirmation from BlueSnap (Charge ID: %s).', 'woocommerce-bluesnap-gateway' ), $transaction['transactionId'] ) );
			}

			$order->save();

			do_action( 'wc_gateway_bluesnap_renewal_payment_complete', $order->get_id(), $transaction );

			if ( ! empty( $charged_currency ) && $charged_currency !== $order->get_currency() ) {
				$this->update_changed_currency_order_totals( $order, $amount, $currency );
			}
		}

		return $transaction;
	}


	/**
	 * Update the order total of the renewed order when the subscription is charged in a different currency than the one its stored in,
	 * In order for the order total in the backend to reflect the correct charged amount in the stored currency based on the current exchange rate.
	 *
	 * @param WC_Order $order
	 * @param float    $total
	 * @param string   $currency
	 */
	protected function update_changed_currency_order_totals( $order, $total, $currency ) {
		$multicurrency     = WC_Bluesnap_Multicurrency::get_instance();
		$current_ex_rate   = $multicurrency->get_currency_rates( $currency );
		$base_ex_rate      = $multicurrency->get_currency_rates( get_woocommerce_currency() );
		$effective_ex_rate = $current_ex_rate / $base_ex_rate;
		$new_total         = round( $total / $effective_ex_rate, 2 );

		$order->set_total( $new_total );
		$order->update_meta_data( '_bluesnap_ex_rate', $effective_ex_rate );
		$order->update_meta_data( '_bluesnap_original_total', $total );
		$order->save();
	}

	/**
	 * Usefull Addon meta information for a given order and its related. We save its corresponding token and vaulted shopper used.
	 * Used on subscriptions and preorders.
	 *
	 * @param int $vaulted_shopper_id
	 * @param int $wc_token_id
	 * @param int $order_id
	 */
	public function save_related_addon_order_info( $order, $bluesnap_subscription_id ) {
		$order_id = $order->get_id();
		$orders   = array();

		if ( $this->has_subscription( $order_id ) ) {
			if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order_id, 'any' ) ) {
				$orders = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => array( 'any' ) ) );
			} elseif ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order_id ) ) {
				$orders = wcs_get_subscriptions_for_renewal_order( $order_id );
			} elseif ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order_id ) ) {
				$order  = wc_get_order( $order_id );
				$orders = array( $order );
			}
			$orders[] = $order;
		} elseif ( $this->has_pre_order( $order_id ) ) {
			$order  = wc_get_order( $order_id );
			$orders = array( $order );
		}
		foreach ( $orders as $order ) {
			$order->update_meta_data( self::ORDER_ONDEMAND_WALLET_ID, $bluesnap_subscription_id );
			$order->save();
		}
	}

	protected function cart_contains_subscription() {
		if ( class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return true;
		}
		if ( function_exists( 'wcs_cart_contains_renewal' ) && wcs_cart_contains_renewal() ) {
			return true;
		}
		if ( function_exists( 'wcs_cart_contains_resubscribe' ) && wcs_cart_contains_resubscribe() ) {
			return true;
		}
		return false;
	}


	/**
	 * Get the shortest interval from subscriptions contained in the cart.
	 * WARNING: This may return null for renewals and resubscibes.
	 *
	 * @return mixed
	 */
	protected function get_cart_shortest_subscription_interval() {

		$shortest = null;

		if ( function_exists( 'WC' ) && WC()->cart ) {
			$carts = ! empty( WC()->cart->recurring_carts ) ? WC()->cart->recurring_carts : array( WC()->cart );

			foreach ( $carts as $cart ) {
				foreach ( $cart->cart_contents as $cart_item ) {
					if ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
						$interval = WC_Subscriptions_Product::get_interval( $cart_item['data'] );
						$period   = WC_Subscriptions_Product::get_period( $cart_item['data'] );

						switch ( $period ) {
							case 'year':
								$interval_in_days = intval( $interval ) * 365;
								break;
							case 'month':
								$interval_in_days = intval( $interval ) * 30;
								break;
							case 'week':
								$interval_in_days = intval( $interval ) * 7;
								break;
							default:
								$interval_in_days = intval( $interval );
								break;
						}

						$shortest = ( $interval_in_days < $shortest || is_null( $shortest ) ) ? $interval_in_days : $shortest;
					}
				}
			}
		}

		return $shortest;
	}

	/**
	 * Hide save card checkbox, when cart contains subscription products it will save it regardless.
	 *
	 * @param $display_tokenization
	 *
	 * @return bool
	 */
	public function maybe_hide_save_checkbox( $display_tokenization ) {
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			if ( $this->is_subs_change_payment() ) {
				return true;
			}
		} else {
			if ( $this->cart_contains_subscription() ) {
				return true;
			}
			if ( class_exists( 'WC_Pre_Orders_Cart' ) && WC_Pre_Orders_Cart::cart_contains_pre_order() && WC_Pre_Orders_Product::product_is_charged_upon_release( WC_Pre_Orders_Cart::get_pre_order_product() ) ) {
				return true;
			}
		}
		return $display_tokenization;
	}

	/**
	 * Checks if page is pay for order and change subs payment page.
	 *
	 * @return bool
	 */
	protected function is_subs_change_payment() {
		return ( isset( $_GET['pay_for_order'] ) && isset( $_GET['change_payment_method'] ) ); // WPCS: CSRF ok.
	}


	/**
	 * Check if we are paying for a renewal
	 * based on the cart's contents
	 *
	 * @return bool
	 */
	protected function is_order_pay_renewal() {
		return ! empty( $this->get_cart_renewal_order() );
	}


	/**
	 * Get the current payment method of the renewal order that is in the cart.
	 *
	 * @return mixed
	 */
	protected function get_cart_renewal_order_method() {
		$order = $this->get_cart_renewal_order();
		return $order ? $order->get_payment_method() : null;
	}


	/**
	 * Get the renewal order from the cart items, or false if cart contents don't have a renewal.
	 *
	 * @return mixed
	 */
	protected function get_cart_renewal_order() {

		if ( function_exists( 'WC' ) && WC()->cart && ! empty( WC()->cart->cart_contents ) ) {

			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( ! empty( $cart_item['subscription_renewal'] ) && isset( $cart_item['subscription_renewal']['renewal_order_id'] ) ) {
					return wc_get_order( $cart_item['subscription_renewal']['renewal_order_id'] );
				}
			}
		}

		return false;
	}


	/**
	 * Is $order_id a subscription?
	 * @param  int  $order_id
	 * @return boolean
	 */
	protected function has_subscription( $order_id ) {
		return ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id, 'any' ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) );
	}

	/**
	 * @param $default
	 * @param $order_id
	 *
	 * @return bool
	 */
	public function force_save_payment_method( $default, $order_id ) {
		return ( $this->has_subscription( $order_id ) ) ? true : $default;
	}

	/**
	 * @param $order_id
	 *
	 * @return bool
	 */
	protected function has_pre_order( $order_id ) {
		return class_exists( 'WC_Pre_Orders_Order' ) && WC_Pre_Orders_Order::order_contains_pre_order( $order_id );
	}

	public function add_subscription_free_trial( $items, $item, $cart_item, $payment_request ) {
		$cart_item_key = $cart_item['key'];
		$_product      = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );

		if ( ! WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
			return $items;
		}

		if ( empty( WC()->cart->recurring_carts ) ) {
			$cart_item_recurring = $cart_item;
		} else {
			$recurring_cart_key  = WC_Subscriptions_Cart::get_recurring_cart_key( $cart_item );
			$cart_item_recurring = isset( WC()->cart->recurring_carts[ $recurring_cart_key ] ) ? WC()->cart->recurring_carts[ $recurring_cart_key ]->get_cart_item( $cart_item_key ) : $cart_item;
		}

		$amount = $cart_item_recurring['line_subtotal'];

		if ( WC()->cart->display_prices_including_tax() ) {
			$amount += $cart_item_recurring['line_subtotal_tax'];
		}

		$quantity       = isset( $cart_item_recurring['quantity'] ) ? $cart_item_recurring['quantity'] : 1;
		$quantity_label = 1 < $quantity ? ' (x' . $quantity . ')' : '';

		$product_name = version_compare( WC_VERSION, '3.0', '<' ) ? $cart_item_recurring['data']->post->post_title : $cart_item_recurring['data']->get_name();

		$items = array( 
			$payment_request->display_item_template(
				array(
					'label' => $product_name . $quantity_label,
					'type'  => 'LINE_ITEM',
					'price' => bluesnap_format_decimal( $amount ),
				)
			),
		);

		$trial_length = WC_Subscriptions_Product::get_trial_length( $_product );
		$trial_period = WC_Subscriptions_Product::get_trial_period( $_product );

		if ( 0 != $trial_length ) {
			$trial_string = wcs_get_subscription_trial_period_strings( $trial_length, $trial_period );
			// translators: 1$: trial length (e.g.: "with 4 months free trial")
			$subscription_string = sprintf( __( '- with %1$s free trial', 'woocommerce-bluesnap-gateway' ), $trial_string );

			$items[] = $payment_request->display_item_template(
				array(
					'label' => $subscription_string,
					'type'  => 'LINE_ITEM',
					'price' => bluesnap_format_decimal( - $amount ),
				)
			);
		}

		if ( WC()->cart->display_prices_including_tax() ) {
			$sign_up_fee = wcs_get_price_including_tax(
				$_product,
				array(
					'price' => WC_Subscriptions_Product::get_sign_up_fee( $_product ),
					'qty'   => $quantity,
				)
			);
		} else {
			$sign_up_fee = wcs_get_price_excluding_tax(
				$_product,
				array(
					'price' => WC_Subscriptions_Product::get_sign_up_fee( $_product ),
					'qty'   => $quantity,
				)
			);
		}

		if ( 0 != $sign_up_fee ) {
			$items[] = $payment_request->display_item_template(
				array(
					'label' => $sign_up_fee > 0 ? __( '- Sign Up Fee', 'woocommerce-bluesnap-gateway' ) : __( '- Sign Up Discount', 'woocommerce-bluesnap-gateway' ),
					'type'  => 'LINE_ITEM',
					'price' => bluesnap_format_decimal( $sign_up_fee ),
				)
			);
		}

		return $items;
	}

	public function add_pre_order_line( $items, $order_total, $subtotal, $payment_request ) {

		if ( ! ( class_exists( 'WC_Pre_Orders_Order' ) && WC_Pre_Orders_Cart::cart_contains_pre_order() && WC_Pre_Orders_Product::product_is_charged_upon_release( WC_Pre_Orders_Cart::get_pre_order_product() ) ) ) {
			return $items;
		}

		$_product = WC_Pre_Orders_Cart::get_pre_order_product();

		$availaibility = WC_Pre_Orders_Product::get_localized_availability_date( WC_Pre_Orders_Cart::get_pre_order_product() );

		$items[] = $payment_request->display_item_template(
			array(
				// translators: 1$: date string (e.g.: "to be paid November 22")
				'label' => sprintf( __( '- to be paid %1$s', 'woocommerce-bluesnap-gateway' ), $availaibility ),
				'type'  => 'LINE_ITEM',
				'price' => bluesnap_format_decimal( - (float) $order_total ),
			)
		);

		return $items;
	}

	public function remove_pre_order_from_total( $order_total ) {
		if ( class_exists( 'WC_Pre_Orders_Order' ) && WC_Pre_Orders_Cart::cart_contains_pre_order() && WC_Pre_Orders_Product::product_is_charged_upon_release( WC_Pre_Orders_Cart::get_pre_order_product() ) ) {
			return 0;
		}
		return $order_total;
	}

	public function bump_apple_pay_version( $version ) {
		if ( class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
			$total = WC()->cart->get_total( false );
			if ( 0 === (int) $total ) { // total to be paid now is 0
				return 4;
			}
		}
		if ( class_exists( 'WC_Pre_Orders_Order' ) && WC_Pre_Orders_Cart::cart_contains_pre_order() && WC_Pre_Orders_Product::product_is_charged_upon_release( WC_Pre_Orders_Cart::get_pre_order_product() ) ) {
			return 4;
		}
		return $version;
	}

	public function add_recurring_totals( $items, $order_total, $subtotal, $payment_request ) {
		if ( ! ( class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) ) {
			return $items;
		}
		if ( empty( WC()->cart->recurring_carts ) ) {
			return $items;
		}

		foreach ( WC()->cart->recurring_carts as $key => $cart ) {
			if ( 0 === $cart->next_payment_date ) {
				continue;
			}

			$first_renewal_date = date_i18n( wc_date_format(), wcs_date_to_time( get_date_from_gmt( $cart->next_payment_date ) ) );
			// translators: placeholder is a date
			$order_total_html = sprintf( __( 'First renewal: %s', 'woocommerce-bluesnap-gateway' ), $first_renewal_date );

			$string = trim( wcs_cart_price_string( '', $cart ) );
			if ( '/' == substr( $string, 0, 1 ) ) {
				$string = __( 'Every', 'woocommerce-bluesnap-gateway' ) . substr( $string, 1 );
			} else {
				$string = ucfirst( $string );
			}
			$string = esc_html( $string );

			$order_total_html = $string . ' - ' . $order_total_html;

			$items[] = $payment_request->display_item_template(
				array(
					// translators: 1$: date string (e.g.: "to be paid November 22")
					'label' => '- ' . $order_total_html,
					'type'  => 'LINE_ITEM',
					'price' => bluesnap_format_decimal( $cart->get_total( 'db' ) ),
				)
			);
		}

		return $items;
	}

	public function cancel_subscription_on_chargeback( $body, $order ) {
		// Do cancellation of subscriptions related to renewals or subscription switch charges.
		$subs = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'any' ) ) );
		if ( ! empty( $subs ) ) {
			foreach ( $subs as $sub ) {
				if ( $sub->can_be_updated_to( 'cancelled' ) ) {
					// translators: $1: opening link tag, $2: order number, $3: closing link tag
					$sub->update_status( 'cancelled', wp_kses( sprintf( __( 'Subscription cancelled because order %1$s#%2$s%3$s received a chargeback.', 'woocommerce-bluesnap-gateway' ), sprintf( '<a href="%s">', esc_url( wcs_get_edit_post_link( wcs_get_objects_property( $order, 'id' ) ) ) ), $order->get_order_number(), '</a>' ), array( 'a' => array( 'href' => true ) ) ) );
					$sub->update_meta_data( '_bluesnap_chargebacked', 'yes' );
					$sub->save();
				}
			}
		}
	}

	/**
	 * @param $order
	 *
	 * @return array
	 * @throws Exception
	 */
	protected function get_order_ondemand_wallet_id( $order, $allow_missing = true ) {

		$ret = $order->get_meta( self::ORDER_ONDEMAND_WALLET_ID );

		if ( ! $allow_missing && empty( $ret ) ) {

			$error_msg = __( 'The Subscription ID for this order is missing. The request cannot be processed.', 'woocommerce-bluesnap-gateway' );

			if ( WC_BLUESNAP_ACH_GATEWAY_ID === $order->get_payment_method() ) {
				$error_msg = __( 'This Subscription is pending. Please try again after the initial payment has been processed.', 'woocommerce-bluesnap-gateway' );
			}

			throw new Exception( $error_msg );
		} else {
			return $ret;
		}
	}
}
