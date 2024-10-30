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
 * Class that handles Bluesnap ACH payment method.
 *
 * Class WC_Bluesnap_Gateway_ACH
 */
class WC_Bluesnap_Gateway_ACH extends WC_Bluesnap_Gateway {

	private $account_types = array(
		'consumer-checking'  => 'CONSUMER_CHECKING',
		'consumer-savings'   => 'CONSUMER_SAVINGS',
		'corporate-checking' => 'CORPORATE_CHECKING',
		'corporate-savings'  => 'CORPORATE_SAVINGS',
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id           = WC_BLUESNAP_ACH_GATEWAY_ID;
		$this->method_title = __( 'BlueSnap ACH', 'woocommerce-bluesnap-gateway' );

		$this->method_description = __( 'BlueSnap ACH Payment Gateway', 'woocommerce-bluesnap-gateway' );
		$this->supports           = array(
			'products',
			'refunds',
			'tokenization',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'pre-orders',
		);

		$this->fraud_id = $this->get_fraud_id();
		if ( empty( $this->fraud_id ) ) {
			$this->fraud_id = $this->set_fraud_id();
		}

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Needed for direct payment gateway
		$this->has_fields            = true;
		$this->rendered_fraud_iframe = false;

		$main_settings         = get_option( 'woocommerce_bluesnap_settings' );
		$this->title           = $this->get_option( 'title' );
		$this->enabled         = $this->get_option( 'enabled' );
		$this->description     = $this->get_option( 'description' );
		$this->soft_descriptor = ! empty( $main_settings['soft_descriptor'] ) ? $main_settings['soft_descriptor'] : '';
		$this->testmode        = ( ! empty( $main_settings['testmode'] ) && 'yes' === $main_settings['testmode'] ) ? true : false;
		$this->api_username    = ! empty( $main_settings['api_username'] ) ? $main_settings['api_username'] : '';
		$this->api_password    = ! empty( $main_settings['api_password'] ) ? $main_settings['api_password'] : '';
		$this->logging         = ( ! empty( $main_settings['logging'] ) && 'yes' === $main_settings['logging'] ) ? true : false;
		$this->capture_charge  = ( ! empty( $main_settings['capture_charge'] ) && 'yes' === $main_settings['capture_charge'] ) ? true : false;
		$this->_3D_secure      = ( ! empty( $main_settings['_3D_secure'] ) && 'yes' === $main_settings['_3D_secure'] ) ? true : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$this->saved_cards     = true; // Always saving ACH accounts
		$this->merchant_id     = ! empty( $main_settings['merchant_id'] ) ? $main_settings['merchant_id'] : '';

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wc_gateway_bluesnap_new_ach_payment_success', array( $this, 'save_payment_method_to_account' ), 10, 3 );
		add_filter( 'woocommerce_payment_gateway_save_new_payment_method_option_html', array( $this, 'replace_save_payment_method_checkbox' ), 10, 2 );

		WC_Bluesnap_Token::get_instance( $this->id );
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = require WC_Bluesnap()->plugin_path() . '/includes/admin/bluesnap-ach-settings.php';
	}


	/**
	 * Process Payment.
	 * First of all and most important is to process the payment.
	 * Second if needed, save payment token card.
	 *
	 * @param int $order_id
	 * @param string $transaction_type
	 * @param null|string $override_total
	 * @param bool $payment_complete
	 *
	 * @return array
	 * @throws Exception
	 */
	public function process_payment( $order_id, $transaction_type = null, $override_total = null, $payment_complete = true ) {

		try {
			$order  = wc_get_order( $order_id );
			$amount = ( isset( $override_total ) && is_numeric( $override_total ) ) ? (float) $override_total : $order->get_total();

			$payload = array(
				'amount'                => bluesnap_format_decimal( $amount, $order->get_currency() ),
				'currency'              => $order->get_currency(),
				'merchantTransactionId' => $order_id,
			);

			$attempt_payments = apply_filters( 'wc_gateway_bluesnap_alternate_payment', true, $order, $payload, $this );

			if ( $attempt_payments && ! $order->get_date_paid( 'edit' ) ) {
				$payment_method_data = $this->fetch_transaction_payment_method_payload( $order );
				if ( is_null( $payment_method_data ) || 'unknown' === $payment_method_data->type ) {
					throw new Exception( __( 'Something went wrong with your payment method selected.', 'woocommerce-bluesnap-gateway' ) );
				}

				// the alt_transaction endpoint doesn't support adding a new ACH account to an existing shopper.
				if ( $payment_method_data->payload['vaultedShopperId'] && $payment_method_data->payload['ecpTransaction'] && isset( $payment_method_data->payload['ecpTransaction']['accountNumber'] ) ) {
					$this->handle_add_posted_shopper_method( $payment_method_data ); 
					$payment_method_data = $this->adapt_new_ach_payload_to_saved( $payment_method_data );
				}

				$payload     = array_merge( $payload, $payment_method_data->payload );
				$transaction = WC_Bluesnap_API::create_alt_transaction( $payload );

				// Set bluesnap shopper id as soon as we have it available
				$shopper = new WC_Bluesnap_Shopper();
				if ( ! $shopper->get_bluesnap_shopper_id() ) {
					$shopper->set_bluesnap_shopper_id( $transaction['vaultedShopperId'] );
				}

				$order->update_meta_data( '_bluesnap_charge_captured', 'no' );
				$order->set_transaction_id( $transaction['transactionId'] );

				if ( $transaction['amount'] > 0 ) {
					/* Translators: Transaction ID */
					$order->update_status( 'on-hold', sprintf( __( 'Charge pending confirmation from BlueSnap (Charge ID: %s).', 'woocommerce-bluesnap-gateway' ), $transaction['transactionId'] ) );
				}

				if ( is_callable( array( $order, 'save' ) ) ) {
					$order->save();
				}

				do_action( 'wc_gateway_bluesnap_' . $payment_method_data->type . '_payment_success', $order, $payment_method_data, $transaction );
			}

			do_action( 'wc_gateway_bluesnap_process_payment_success', $order );

		} catch ( Exception $e ) {
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
	 * Can the order be refunded via this gateway?
	 * Refund is available only for captured charge (Accepted ACH transaction)
	 *
	 * @param  WC_Order $order Order object.
	 * @return bool If false, the automatic refund button is hidden in the UI.
	 */
	public function can_refund_order( $order ) {
		return $order && ( 'yes' === $order->get_meta( '_bluesnap_charge_captured', true ) ) && parent::can_refund_order( $order );
	}

	/**
	 * Add Payment Method hook on My account.
	 *
	 * @return array
	 * @throws Exception
	 */
	public function add_payment_method() {
		if ( ! wp_verify_nonce( $_POST['woocommerce-add-payment-method-nonce'], 'woocommerce-add-payment-method' ) ) {
			return array(
				'result'   => 'failure',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);
		}

		$ach_data = $this->fetch_transaction_new_ach_payload( WC()->customer );

		try {
			$this->handle_add_posted_shopper_method( $ach_data );
			return array(
				'result'   => 'success',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);

		} catch ( WC_Bluesnap_API_Exception $e ) {
			WC()->session->set( 'refresh_totals', true ); // this triggers refresh of the checkout area
			WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
			wc_add_notice( __( 'Authorization has failed for this transaction. Please try again or contact your bank for assistance', 'woocommerce-bluesnap-gateway' ), 'error' );
			do_action( 'wc_gateway_bluesnap_add_payment_method_error', $e );
			return array(
				'result'   => 'failure',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);
		}
	}

	/**
	 * Payment Form on checkout page
	 */
	public function payment_fields() {
		$this->render_fraud_kount_iframe();

		if ( ! empty( $this->description ) ) {
			echo apply_filters( 'wc_bluesnap_description', wpautop( wp_kses_post( $this->description ) ), $this->id ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$display_tokenization = $this->is_save_card_available();

		if ( $display_tokenization ) {
			$this->saved_payment_methods();
		}

		woocommerce_bluesnap_gateway_get_template( 'payment-fields-bluesnap-ach.php', array( 'gateway' => $this ) );

		if ( ! apply_filters( 'wc_' . $this->id . '_hide_save_payment_method_checkbox', ! $display_tokenization ) && ! is_add_payment_method_page() ) {
			$this->save_payment_method_checkbox();
		}
	}


	protected function fetch_transaction_payment_method_payload( $order ) {
		$ret = $this->get_base_payload_object( $order );
		$ret = apply_filters( 'wc_gateway_bluesnap_transaction_payment_method_payload', $ret, $order );
		if ( 'unknown' === $ret->type ) {
			// If payment saved token
			$payment_method_token = $this->get_id_saved_payment_token_selected();

			if ( $payment_method_token ) {
				$token         = WC_Bluesnap_Token::set_wc_token( $payment_method_token, 'WC_Payment_Token_Bluesnap_ACH' );
				$ret->type     = 'token';
				$ret->ecp_info = array(
					'public_account_number' => $token->get_token()->get_public_account_number(),
					'public_routing_number' => $token->get_token()->get_public_routing_number(),
					'account_type'          => $token->get_token()->get_account_type(),

				);
				$ret->payload = array_merge(
					$ret->payload,
					array(
						'ecpTransaction'      => array(
							'publicAccountNumber' => $token->get_token()->get_public_account_number(),
							'publicRoutingNumber' => $token->get_token()->get_public_routing_number(),
							'accountType'         => $token->get_token()->get_account_type(),
						),
						'authorizedByShopper' => true,
					)
				);
				$ret->token   = $payment_method_token;
			} else {
				$ret = $this->fetch_transaction_new_ach_payload( $order, $ret );
			}
		}

		return $ret;
	}


	protected function adapt_new_ach_payload_to_saved( $payment_method_data ) {

		$payment_method_data->payload['ecpTransaction']['publicAccountNumber'] = substr( $payment_method_data->payload['ecpTransaction']['accountNumber'], -5 );
		$payment_method_data->payload['ecpTransaction']['publicRoutingNumber'] = substr( $payment_method_data->payload['ecpTransaction']['routingNumber'], -5 );

		$payment_method_data->type = 'token'; // Also avoids wc_gateway_bluesnap_new_ach_payment_success action from re-inserting the token.

		unset( $payment_method_data->payload['ecpTransaction']['accountNumber'] );
		unset( $payment_method_data->payload['ecpTransaction']['routingNumber'] );

		return $payment_method_data;
	}


	protected function fetch_transaction_new_ach_payload( $customer_source, $ret = null ) {
		if ( is_null( $ret ) ) {
			$ret = $this->get_base_payload_object( $customer_source );
		} else {
			$ret = (object) $ret;
		}

		$ret->type    = 'new_ach';
		$ret->payload = array_merge(
			$ret->payload,
			array(
				'ecpTransaction'      => array(
					'accountNumber' => wp_unslash( $_POST[ $this->id . '-account-number' ] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
					'routingNumber' => wp_unslash( $_POST[ $this->id . '-routing-number' ] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
					'accountType'   => isset( $this->account_types[ $_POST[ $this->id . '-account-type' ] ] ) ? $this->account_types[ $_POST[ $this->id . '-account-type' ] ] : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				),
				'authorizedByShopper' => ( isset( $_POST[ $this->id . '-user-consent' ] ) && 'yes' === $_POST[ $this->id . '-user-consent' ] ) ? true : false, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);

		if ( empty( $ret->payload['vaultedShopperId'] ) && ! empty( $ret->payer_info ) ) {
			$ret->payload['payerInfo'] = $ret->payer_info;
		}

		$ret->saveable = true; // can be saved as new payment method

		return $ret;
	}


	/**
	 * Validate required Checkout fields: Card Holder Name and Surname (Space-separated)
	 * BlueSnap confirmation from credential Submission.
	 * If payment is done through payment token, just validate if token index exists.
	 *
	 * @return bool
	 */
	public function validate_fields() {

		if ( $this->supports( 'tokenization' ) && $this->saved_cards && $this->get_id_saved_payment_token_selected() ) {
			return true;
		}

		$errors = new WP_Error();

		// phpcs:disable WordPress.Security.NonceVerification.Missing

		if ( empty( $_POST[ $this->id . '-account-number' ] ) ) {
			$errors->add( 'missing_account_number', __( 'Missing account number.', 'woocommerce-bluesnap-gateway' ) );
		}
		if ( empty( $_POST[ $this->id . '-routing-number' ] ) ) {
			$errors->add( 'missing_routing_number', __( 'Missing routing number.', 'woocommerce-bluesnap-gateway' ) );
		}
		if ( empty( $_POST[ $this->id . '-account-type' ] ) || ! isset( $this->account_types[ $_POST[ $this->id . '-account-type' ] ] ) ) {
			$errors->add( 'missing_account_type', __( 'Please select an account type.', 'woocommerce-bluesnap-gateway' ) );
		}

		if ( ! preg_match( '/^\d{4,17}$/', $_POST[ $this->id . '-account-number' ] ) ) { // Account number. 4–17 digits.
			$errors->add( 'wrong_account_number', __( 'Account number must be 4-17 digits long.', 'woocommerce-bluesnap-gateway' ) );
		}

		if ( ! preg_match( '/^\d{9}$/', $_POST[ $this->id . '-routing-number' ] ) ) { // 9-digit routing number.
			$errors->add( 'wrong_routing_number', __( 'Routing number must be a 9 digit number.', 'woocommerce-bluesnap-gateway' ) );
		}

		if ( is_add_payment_method_page() || isset( $_GET['pay_for_order'] ) ) {
			$payer_info         = $this->get_payer_info_from_source( WC()->customer );
			$billing_company    = $payer_info && ! empty( $payer_info['companyName'] ) ? $payer_info['companyName'] : '';
			$billing_first_name = $payer_info && ! empty( $payer_info['firstName'] ) ? $payer_info['firstName'] : '';
			$billing_last_name  = $payer_info && ! empty( $payer_info['lastName'] ) ? $payer_info['lastName'] : '';
			$billing_country    = $payer_info && ! empty( $payer_info['country'] ) ? $payer_info['country'] : '';
			$billing_zip        = $payer_info && ! empty( $payer_info['zip'] ) ? $payer_info['zip'] : '';

			if ( empty( $billing_first_name ) || empty( $billing_last_name ) ) {
				$errors->add( 'missing_billing_names', sprintf( __( 'You cannot add a payment method of this type without providing your First and Last Name. Go to your <a href="%1$s">Account details</a> and enter your billing data.', 'woocommerce-bluesnap-gateway' ), esc_url( wc_get_account_endpoint_url( 'edit-account' ) ) ) );
			}
		} else {
			$billing_company = isset( $_POST['billing_company'] ) ? $_POST['billing_company'] : null;
			$billing_country = isset( $_POST['billing_country'] ) ? $_POST['billing_country'] : null;
			$billing_zip     = isset( $_POST['billing_postcode'] ) ? $_POST['billing_postcode'] : null;

			if ( 'USD' !== get_woocommerce_currency() ) {
				$errors->add( 'not_supported_currency', __( 'USD is the only currency supported by the selected payment method.', 'woocommerce-bluesnap-gateway' ) );
			}
			if ( empty( $_POST[ $this->id . '-user-consent' ] ) || 'yes' !== $_POST[ $this->id . '-user-consent' ] ) {
				$errors->add( 'no_user_consent', __( 'You need to Authorize this transaction.', 'woocommerce-bluesnap-gateway' ) );
			}
		}

		if ( ( ( empty( $billing_zip ) || 5 !== strlen( trim( $billing_zip ) ) ) && ! is_add_payment_method_page() ) || ( ! empty( $billing_zip ) && 5 !== strlen( trim( $billing_zip ) ) ) ) {
			$errors->add( 'wrong_postcode_length', __( 'Postcode must be 5 characters long.', 'woocommerce-bluesnap-gateway' ) );
		}

		if ( ( ( empty( $billing_country ) || 'US' !== $billing_country ) && ! is_add_payment_method_page() ) || ( ! empty( $billing_country ) && 'US' !== $billing_country ) ) {
			$errors->add( 'not_supported_country', __( 'US is the only country supported by the selected payment method.', 'woocommerce-bluesnap-gateway' ) );
		}

		if ( 0 === strpos( $_POST[ $this->id . '-account-type' ], 'corporate' ) && empty( $billing_company ) ) {
			$errors->add( 'missing_company_corporate', __( 'Using a corporate account requires a non empty company field.', 'woocommerce-bluesnap-gateway' ) );
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$errors = apply_filters( 'wc_gateway_bluesnap_validate_fields', $errors );

		$errors_messages = $errors->get_error_messages();
		if ( ! empty( $errors_messages ) ) {
			foreach ( $errors_messages as $message ) {
				wc_add_notice( $message, 'error' );
			}
			return false;
		}

		return true;
	}


	public function save_payment_method_to_account( $order, $payment_method_data, $transaction ) {
		try {
			// If customer wants to save its card or it is a subscription.
			if ( $this->is_save_card_available() ) {
				if ( $this->save_payment_method_selected() || $this->should_force_save_payment_method( $order ) ) {
					$saved_wc_token = $this->save_payment_method( $transaction );
					if ( ! $saved_wc_token ) {
						$this->save_payment_method_soft_fail( $order );
						return;
					}

					do_action( 'wc_gateway_bluesnap_save_payment_method_success', $transaction['vaultedShopperId'], $saved_wc_token, $order->get_id() );
				}
			}
		} catch ( WC_Bluesnap_API_Exception $e ) {
			$this->save_payment_method_soft_fail( $order, $e->getMessage() );
		} catch ( Exception $e ) {
			$this->save_payment_method_soft_fail( $order, $e->getMessage() );
		}
	}


	private function save_payment_method_soft_fail( $order, $extra_note = false ) {
		$order->add_order_note( __( 'User ACH Number could not be saved.', 'woocommerce-bluesnap-gateway' ) . ( $extra_note ? ' ' . $extra_note : '' ) );
		wc_add_notice( __( 'We could not save your ACH number, try next time.', 'woocommerce-bluesnap-gateway' ), 'error' );
	}


	protected function save_payment_method_selected() {
		return true;
	}


	/**
	 * Outputs a checkbox for saving a new payment method to the database.
	 *
	 * @since 2.6.0
	 */
	public function replace_save_payment_method_checkbox( $html, $gateway ) {

		if ( WC_BLUESNAP_ACH_GATEWAY_ID === $gateway->id ) {
			$html = '<p class="form-row woocommerce-SavedPaymentMethods-saveNew">' . esc_html__( 'This payment method will be saved to your account.', 'woocommerce-bluesnap-gateway' ) . '</p>';
		}

		return $html;
	}
}
