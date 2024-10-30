<?php
/**
 * @author   SAU/CAL
 * @category Class
 * @package  Woocommerce_Bluesnap_Gateway/Classes
 * @version  2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Bluesnap_Payment_Request
 */
abstract class WC_Bluesnap_Payment_Request {

	/**
	 * Controls if the specific instance is enabled or not.
	 *
	 * @var bool
	 */
	protected $enabled;

	/**
	 * The type of the payment request, populated by its slug.
	 *
	 * @var string
	 */
	protected $type;

	/**
	 * The title of the payment request.
	 *
	 * @var string
	 */
	protected $title;

	/**
	 * The required API version
	 *
	 * @var integer
	 */
	protected $version_required;

	/**
	 * Whether the instance is already initialized or not.
	 * Used to allow specific things to be initiated only once.
	 *
	 * @var boolean
	 */
	protected static $initialized = false;

	/**
	 * Used as a global in a specific scenario to hold a WC_Subscription object.
	 *
	 * @var WC_Subscription/boolean
	 */
	protected $revert_sub_pm_title_change = false;

	/**
	 * Handles converting posted data
	 *
	 * @since 1.2.0
	 */
	abstract protected function normalize_posted_data_for_order( $posted_data );

	/**
	 * Transform contact info to match expected format & labels.
	 *
	 * @param array $contact
	 * @return array
	 */
	abstract protected function normalize_contact( $contact );

	/**
	 * Template function to return specific arguments in the expected format.
	 *
	 * @param array $args
	 * @return array
	 */
	abstract protected function display_item_template( $args );

	/**
	 * Template function to return specific arguments in the expected format.
	 *
	 * @param array $args
	 * @return array
	 */
	abstract protected function display_items_template( $args );

	/**
	 * Convert returned payload & add expected data.
	 *
	 * @param array $pr
	 * @return array
	 */
	abstract protected function payment_request_convert( $pr );

	/**
	 * Meta data that contains the ondemand wallet id.
	 *
	 * @var string
	 */
	const ORDER_ONDEMAND_WALLET_ID = '_bluesnap_ondemand_subscription_id';

	/**
	 * Hook in ajax handlers.
	 */
	public function __construct() {

		$this->add_ajax_events(); // runs on all PMRs but the method checks for PMR type

		add_action( 'wc_gateway_bluesnap_' . $this->type . '_pay_order_complete', array( $this, 'add_order_meta' ), 10 );

		add_action( 'woocommerce_pre_payment_complete', array( $this, 'add_order_meta' ), 10, 2 );

		// Set the payment method title
		add_filter( 'woocommerce_gateway_title', array( $this, 'filter_gateway_title' ), 10, 2 );

		add_action( 'wc_bluesnap_scheduled_subscription_failure', array( $this, 'add_order_meta_to_renewal' ), 10, 2 );
		add_action( 'woocommerce_scheduled_subscription_payment_' . WC_BLUESNAP_GATEWAY_ID, array( $this, 'add_order_meta_to_renewal' ), 2, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'add_order_meta' ), 10 );
		add_action( 'woocommerce_checkout_create_subscription', array( $this, 'add_order_meta' ), 10 );
		add_action( 'wc_gateway_bluesnap_renewal_payment_complete', array( $this, 'add_order_meta' ), 10, 2 );
		add_filter( 'wc_gateway_bluesnap_get_adapted_payload_for_ondemand_wallet', array( $this, 'adapted_payload_for_ondemand_wallet' ) );

		add_filter( 'wcs_renewal_order_meta_query', array( $this, 'remove_renewal_order_meta_query' ), 10 );

		if ( self::$initialized ) {
			return;
		}

		// Things that only run once for all instances:
		add_filter( 'woocommerce_bluesnap_gateway_enqueue_scripts', array( $this, 'add_payment_request_js' ), 10, 2 );

		// Force session to be set
		add_action( 'template_redirect', array( $this, 'set_session' ) );

		add_filter( 'woocommerce_subscription_payment_method_to_display', array( $this, 'filter_subscription_gateway_title' ), 10, 3 );
		add_action( 'woocommerce_subscription_failing_payment_method_updated_' . WC_BLUESNAP_GATEWAY_ID, array( $this, 'handle_failing_payment_method_updated' ), 10, 2 );
		add_filter( 'wc_gateway_bluesnap_validate_fields', array( $this, 'validate_alternate_payment' ) );
		add_filter( 'wc_gateway_bluesnap_payment_request_cart_compatible', array( $this, 'check_cart_compat' ) );
		add_action( 'woocommerce_login_form_end', array( $this, 'maybe_add_redirect_field' ) );
		add_action( 'woocommerce_register_form_end', array( $this, 'maybe_add_redirect_field' ) );

		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'filter_gateway_title_order_totals' ), 10, 2 );

		self::$initialized = true;
	}

	/**
	 * Returns the title of the Payment request.
	 *
	 * @return string
	 */
	public function get_title() {
		return $this->title;
	}

	/**
	 * Returns the type (slug) of the Payment request, or Null if non found.
	 *
	 * @return string/null
	 */
	protected function get_payment_request_type() {
		return isset( $_POST['payment_request_type'] ) ? wc_clean( $_POST['payment_request_type'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Get the order source from the URL params.
	 *
	 * @return array
	 */
	public function get_order_source() {

		if ( ! is_wc_endpoint_url( 'order-pay' ) ) {
			return 'cart';
		}

		return array(
			'order' => (int) get_query_var( 'order-pay' ),
			'key'   => wp_unslash( $_GET['key'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * Check if the current page is the change payment method.
	 *
	 * @return bool
	 */
	public function is_payment_change_method() {

		$is_change_payment_method_request = ! empty( $_GET['change_payment_method'] ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( $is_change_payment_method_request && is_wc_endpoint_url( 'order-pay' ) ) {
			return true;
		}

		if ( ! empty( $_POST['is_change_payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}

		return false;
	}

	/**
	 * Enqueue Payment request scripts.
	 *
	 * @param $args
	 *
	 * @return array
	 */
	public function add_payment_request_js( $args, $handler ) {
		$args['woocommerce-bluesnap-payment-request'] = array(
			'src'  => $handler->localize_asset( 'js/frontend/woocommerce-bluesnap-payment-request.js' ),
			'data' => array(
				'ajax_url'                 => WC_Bluesnap()->ajax_url(),
				'wc_ajax_url'              => WC_AJAX::get_endpoint( '%%endpoint%%' ),
				'merchant_id'              => WC_Bluesnap()->get_option( 'merchant_id' ),
				'merchant_soft_descriptor' => WC_Bluesnap()->get_option( 'soft_descriptor' ),
				'version_required'         => array(),
				'test_mode'                => ( ! empty( WC_Bluesnap()->get_option( 'testmode' ) && 'yes' === WC_Bluesnap()->get_option( 'testmode' ) ) ) ? true : false,
				'cart_compatible'          => (int) apply_filters( 'wc_gateway_bluesnap_payment_request_cart_compatible', true ),
				'request_info_source'      => $this->get_order_source(),
				'change_payment_page'      => $this->is_payment_change_method(),
				'nonces'                   => array(
					'checkout'               => wp_create_nonce( 'woocommerce-process_checkout' ),
					'create_apple_wallet'    => wp_create_nonce( 'wc-gateway-bluesnap-ajax-create_apple_wallet' ),
					'get_shipping_options'   => wp_create_nonce( 'wc-gateway-bluesnap-ajax-get_shipping_options' ),
					'update_shipping_method' => wp_create_nonce( 'wc-gateway-bluesnap-ajax-update_shipping_method' ),
					'get_payment_request'    => wp_create_nonce( 'wc-gateway-bluesnap-ajax-get_payment_request' ),
				),
				'i18n'                     => array(
					'google_pay' => array(
						'checkout_error'              => esc_attr__( 'Error processing Google Pay. Please try again.', 'woocommerce-bluesnap-gateway' ),
						'not_compatible_with_cart'    => esc_attr__( 'Google Pay is not available for you due to the contents of your cart being incompatible with it. Please attempt the standard checkout options.', 'woocommerce-bluesnap-gateway' ),
						'device_not_compat_with_cart' => esc_attr__( 'Google Pay is not available for you due to the contents of your cart being incompatible with your OS version. Upgrade if possible, or attempt the standard checkout options.', 'woocommerce-bluesnap-gateway' ),
						'not_able_to_make_payments'   => esc_attr__( 'Google Pay is not available for you because you don\'t have any payment methods set up. Please set it up, or attempt the standard checkout options.', 'woocommerce-bluesnap-gateway' ),
					),
					'apple_pay'  => array(
						'checkout_error'              => esc_attr__( 'Error processing Apple Pay. Please try again.', 'woocommerce-bluesnap-gateway' ),
						'not_compatible_with_cart'    => esc_attr__( 'Apple Pay is not available for you due to the contents of your cart being incompatible with it. Please attempt the standard checkout options.', 'woocommerce-bluesnap-gateway' ),
						'device_not_compat_with_cart' => esc_attr__( 'Apple Pay is not available for you due to the contents of your cart being incompatible with your OS version. Upgrade if possible, or attempt the standard checkout options.', 'woocommerce-bluesnap-gateway' ),
						'not_able_to_make_payments'   => esc_attr__( 'Apple Pay is not available for you because you don\'t have any payment methods set up. Please set it up, or attempt the standard checkout options.', 'woocommerce-bluesnap-gateway' ),
					),
				),
			),
		);
		return $args;
	}


	/**
	 * Hook in methods - uses WordPress ajax handlers (admin-ajax).
	 *
	 * @return void
	 */
	public function add_ajax_events() {
		$ajax_events = array(
			'create_pmr_payment'     => true,
			'get_payment_request'    => true,
			'get_shipping_options'   => true,
			'update_shipping_method' => true,
		);

		/**
		 * This method is hooked on all Payment request instances.
		 * As all of these ajax calls should have payment_request_type set,
		 * we can check it and abort on all but the needed instance.
		 */
		if ( is_null( $this->type ) || $this->type !== $this->get_payment_request_type() ) {
			return;
		}

		foreach ( $ajax_events as $ajax_event => $nopriv ) {
			add_action( 'wc_ajax_bluesnap_' . $ajax_event, array( $this, 'ajax_' . $ajax_event ) );
		}
	}

	/**
	 * Return the required API version for the specific Payment request.
	 *
	 * @return integer
	 */
	public function pr_version_required() {
		WC()->payment_gateways();
		return apply_filters( 'wc_gateway_bluesnap_payment_request_' . $this->type . '_version_required', $this->version_required );
	}

	/**
	 * Check if the items in cart are compatible/supported by the current Payment request.
	 *
	 * @param boolean $ret
	 * @return boolean
	 */
	public function check_cart_compat( $ret ) {
		if ( ! $this->allowed_items_in_cart() ) {
			return false;
		}

		return $ret;
	}

	/**
	 * Create the payment / process checkout for the current payment request.
	 *
	 * @return void
	 */
	public function ajax_create_pmr_payment() {
		$payment_token = false;

		if ( ! isset( $_POST['payment_request_source'] ) || ( WC()->cart->is_empty() && 'cart' === $_POST['payment_request_source'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_send_json_error( __( 'Empty cart', 'woocommerce-bluesnap-gateway' ) );
		}

		if ( isset( $_POST['payment_token'] ) && ! empty( $_POST['payment_token'] ) ) {  // WPCS: CSRF ok.
			$payment_token = json_decode( base64_decode( $_POST['payment_token'] ), true ); // WPCS: CSRF ok.
			if ( ! isset( $payment_token['token'] ) && ! isset( $payment_token['paymentMethodData'] ) ) {
				wp_send_json_error();
			}
		} else {
			wp_send_json_error();
		}

		if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			define( 'WOOCOMMERCE_CHECKOUT', true );
		}

		$this->normalize_posted_data_for_order( $payment_token );

		$this->validate_state();

		$_POST['decoded_payment_token'] = $payment_token;

		if ( isset( $_POST['payment_request_source'] ) && 'cart' !== $_POST['payment_request_source'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->pay_for_order( absint( $_POST['payment_request_source']['order'] ), $_POST['payment_request_source']['key'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} else {
			WC()->checkout()->process_checkout();
		}

		die( 0 );
	}

	/**
	 * Handle Payment for a specific order.
	 * This is a clone of WC_Form_Handler::pay_action.
	 *
	 * @param integer $order_id
	 * @param string  $order_key
	 * @return void
	 *
	 * @throws Exception On payment error.
	 */
	protected function pay_for_order( $order_id, $order_key ) {

		wc_nocache_headers();

		$nonce_value = wc_get_var( $_REQUEST['_wpnonce'], '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! wp_verify_nonce( $nonce_value, 'woocommerce-process_checkout' ) ) {
			return;
		}

		// Pay for existing order.
		$order = wc_get_order( $order_id );

		if ( $order_id === $order->get_id() && hash_equals( $order->get_order_key(), $order_key ) && ( $order->needs_payment() || $this->is_payment_change_method() ) ) {

			do_action( 'woocommerce_before_pay_action', $order );

			WC()->customer->set_props(
				array(
					'billing_country'  => $order->get_billing_country() ? $order->get_billing_country() : null,
					'billing_state'    => $order->get_billing_state() ? $order->get_billing_state() : null,
					'billing_postcode' => $order->get_billing_postcode() ? $order->get_billing_postcode() : null,
					'billing_city'     => $order->get_billing_city() ? $order->get_billing_city() : null,
				)
			);
			WC()->customer->save();

			if ( $this->is_payment_change_method() ) {
				return $this->change_payment_method( $order );
			}

			if ( ! empty( $_POST['terms-field'] ) && empty( $_POST['terms'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				wc_add_notice( __( 'Please read and accept the terms and conditions to proceed with your order.', 'woocommerce-bluesnap-gateway' ), 'error' );
				return;
			}

			// Update payment method.
			if ( $order->needs_payment() ) {
				try {
					$payment_method_id = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing

					if ( ! $payment_method_id ) {
						throw new Exception( __( 'Invalid payment method.', 'woocommerce-bluesnap-gateway' ) );
					}

					$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
					$payment_method     = isset( $available_gateways[ $payment_method_id ] ) ? $available_gateways[ $payment_method_id ] : false;

					if ( ! $payment_method ) {
						throw new Exception( __( 'Invalid payment method.', 'woocommerce-bluesnap-gateway' ) );
					}

					$order->set_payment_method( $payment_method );
					$order->save();

					$payment_method->validate_fields();

					if ( 0 === wc_notice_count( 'error' ) ) {

						$result = $payment_method->process_payment( $order_id );

						// Redirect to success/confirmation/payment page.
						if ( isset( $result['result'] ) && 'success' === $result['result'] ) {
							$result = apply_filters( 'woocommerce_payment_successful_result', $result, $order_id );

							do_action( 'wc_gateway_bluesnap_' . $this->type . '_pay_order_complete', $order_id );
							do_action( 'wc_gateway_bluesnap_' . str_replace( '_', '', $this->type ) . '_pay_order_complete', $order_id ); // Kept for back-compat.

							wp_send_json( $result );
						}
					}
				} catch ( Exception $e ) {
					wc_add_notice( $e->getMessage(), 'error' );

					$response = array(
						'result'   => 'failure',
						'messages' => $e->getMessage(),
					);

					wp_send_json( $response );

				}
			} else {
				// No payment was required for order.
				$order->payment_complete();

				$response = array(
					'result'   => 'success',
					'redirect' => $order->get_checkout_order_received_url(),
				);

				wp_send_json( $response );
			}
		}
	}

	/**
	 * Get the payment request object populated from the data of the given order.
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	public function order_get_payment_request( $order ) {
		$order = wc_get_order( $order );

		// Set mandatory payment details.
		$data = array(
			'shipping_required' => false,
			'billing_required'  => true,
			'order_data'        => array(
				'currencyCode' => $order->get_currency(),
				'countryCode'  => substr( WC()->countries->get_base_country(), 0, 2 ),
			),
		);

		$data['order_data'] += $this->build_display_items_from_order( $order );

		if ( $this->is_payment_change_method() ) {
			$data['order_data'] = $this->modify_order_before_payment_method( $data['order_data'] );
		}

		wp_send_json_success( $this->payment_request_convert( $data ) );
	}

	/**
	 * Gets the payment request object
	 *
	 * @return void
	 */
	public function ajax_get_payment_request() {
		check_ajax_referer( 'wc-gateway-bluesnap-ajax-get_payment_request', 'security' );

		$source = isset( $_POST['payment_request_source'] ) ? $_POST['payment_request_source'] : 'cart';

		if ( 'cart' !== $source ) {

			if ( isset( $source['order'] ) ) {
				$order_id  = (int) $source['order'];
				$order_key = $source['key'];
				$order     = wc_get_order( $order_id );
				if ( $order_id === $order->get_id() && $order_key === $order->get_order_key() && ( $order->needs_payment() || $this->is_payment_change_method() ) ) {
					return $this->order_get_payment_request( $order );
				} else {
					return wp_send_json_error();
				}
			}
			return wp_send_json_error();
		}

		if ( ! is_a( WC()->cart, 'WC_Cart' ) ) {
			wp_send_json_error();
		}

		// Set mandatory payment details.
		$data = array(
			'shipping_required' => WC()->cart->needs_shipping(),
			'billing_required'  => true,
			'order_data'        => array(
				'currencyCode' => get_woocommerce_currency(),
				'countryCode'  => substr( WC()->countries->get_base_country(), 0, 2 ),
			),
		);

		$data['order_data'] += $this->build_display_items( false );

		wp_send_json_success( $this->payment_request_convert( $data ) );
	}

	/**
	 * Get shipping options.
	 *
	 * @see WC_Cart::get_shipping_packages().
	 * @see WC_Shipping::calculate_shipping().
	 * @see WC_Shipping::get_packages().
	 */
	public function ajax_get_shipping_options() {
		check_ajax_referer( 'wc-gateway-bluesnap-ajax-get_shipping_options', 'security' );

		wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );

		WC()->cart->calculate_totals();

		try {
			// Set the shipping package.
			$posted = json_decode( base64_decode( $_POST['address'] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

			$posted = $this->normalize_contact( $posted );

			$this->calculate_shipping( apply_filters( 'wc_gateway_bluesnap_payment_request_shipping_posted_values', $posted ) );

			// Set the shipping options.
			$data     = array();
			$packages = $this->get_shipping_packages();

			if ( ! empty( $packages ) && WC()->customer->has_calculated_shipping() ) {
				foreach ( $packages as $package_key => $package ) {
					if ( empty( $package['rates'] ) ) {
						throw new Exception( __( 'Unable to find shipping method for address.', 'woocommerce-bluesnap-gateway' ) );
					}

					foreach ( $package['rates'] as $key => $rate ) {
						$shipping_cost = $rate->cost;
						if ( WC()->cart->display_prices_including_tax() ) {
							$shipping_cost += $rate->get_shipping_tax();
						}
						$data['shippingMethods'][] = array(
							'identifier' => $rate->id,
							'label'      => $rate->label,
							'detail'     => '',
							'amount'     => bluesnap_format_decimal( $shipping_cost ),
						);
					}
				}
			} else {
				throw new Exception( __( 'Unable to find shipping method for address.', 'woocommerce-bluesnap-gateway' ) );
			}

			$chosen_now = array();
			if ( ! empty( $chosen_shipping_methods ) ) {
				if ( count( $chosen_shipping_methods ) > 1 ) {
					$chosen_shipping_methods = array_slice( array_values( $chosen_shipping_methods ), 0, 1 );
				}

				$available = wp_list_pluck( $data['shippingMethods'], 'identifier' );

				$chosen_now = array_values( array_intersect( $available, $chosen_shipping_methods ) );

				if ( count( $chosen_now ) > 1 ) {
					$chosen_now = array( $chosen_now[0] );
				}

				foreach ( $data['shippingMethods'] as $key => $shipping_data ) {
					if ( isset( $chosen_now[0] ) && $chosen_now[0] === $shipping_data['identifier'] ) {
						unset( $data['shippingMethods'][ $key ] );
						array_unshift( $data['shippingMethods'], $shipping_data );
						$data['shippingMethods'] = array_values( $data['shippingMethods'] );
						break;
					}
				}
			}

			if ( 'google_pay' === $this->get_payment_request_type() ) {
				$data['currencyCode'] = get_woocommerce_currency();
				$data['countryCode']  = substr( WC()->countries->get_base_country(), 0, 2 );
			}

			if ( empty( $chosen_now ) && isset( $data['shippingMethods'][0] ) ) {
				$chosen_now = array( $data['shippingMethods'][0]['identifier'] );
			}

			if ( ! empty( $chosen_now ) ) {
				// Add recurring shipping keys to the chosen shipping methods.
				$chosen_now = $this->maybe_add_recurring_to_chosen_shipping( $chosen_now );

				// Auto select the first shipping method.
				WC()->session->set( 'chosen_shipping_methods', $chosen_now );
			}

			$data += $this->build_display_items();

			wp_send_json_success( $data );
		} catch ( Exception $e ) {
			$data             += $this->build_display_items();
			$data['errorCode'] = 'invalid_shipping_address';
			$data['message']   = $e->getMessage();

			wp_send_json_error( $data );
		}
	}

	/**
	 * Get the shipping packages for the current cart.
	 */
	protected function get_shipping_packages() {

		$packages = WC()->shipping->get_packages();

		$packages = $this->maybe_add_subscriptions_shipping_packages( $packages );

		return $packages;
	}

	/**
	 * Maybe add subscriptions packages.
	 *
	 * @param array $packages The shipping packages.
	 */
	protected function maybe_add_subscriptions_shipping_packages( array $packages ) {
		if ( ! class_exists( 'WC_Subscriptions_Cart' ) || ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return $packages;
		}

		if ( ! isset( WC()->cart->recurring_carts ) || ! is_array( WC()->cart->recurring_carts ) ) {
			return $packages;
		}

		// When there is only a subscription with free trial in the cart packages will be empty, so we need to add the recurring package as fallback.
		if ( ! empty( $packages ) ) {
			return $packages;
		}

		foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {
			if ( ! WC_Subscriptions_Cart::cart_contains_subscriptions_needing_shipping( $recurring_cart ) ) {
				continue;
			}

			WC_Subscriptions_Cart::set_calculation_type( 'recurring_total' );
			WC_Subscriptions_Cart::set_recurring_cart_key( $recurring_cart_key );
			WC_Subscriptions_Cart::set_cached_recurring_cart( $recurring_cart );

			foreach ( $recurring_cart->get_shipping_packages() as $recurring_cart_package_key => $recurring_cart_package ) {
				$package = WC_Subscriptions_Cart::get_calculated_shipping_for_package( $recurring_cart_package );

				$packages[ $recurring_cart_package_key ] = $package;

				// Remove the original shipping package that matches the recurring cart, to avoid duplicates.
				$original_package_index = isset( $recurring_cart_package['package_index'] ) ? $recurring_cart_package['package_index'] : 0;

				if (
					isset( $packages[ $original_package_index ] ) &&
					isset( $packages[ $original_package_index ]['rates'] ) &&
					isset( $package['rates'] ) &&
					$package['rates'] == $packages[ $original_package_index ]['rates']
				) {
					unset( $packages[ $original_package_index ] );
				}
			}

			WC_Subscriptions_Cart::set_calculation_type( 'none' );
			WC_Subscriptions_Cart::set_recurring_cart_key( 'none' );
		}

		return $packages;
	}

	/**
	 * Maybe add recurring shipping to chosen shipping method.
	 *
	 * @param array $chosen_now The chosen shipping packages.
	 */
	protected function maybe_add_recurring_to_chosen_shipping( array $chosen_now ) {
		if ( ! class_exists( 'WC_Subscriptions_Cart' ) || ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return $chosen_now;
		}

		if ( ! isset( WC()->cart->recurring_carts ) || ! is_array( WC()->cart->recurring_carts ) ) {
			return $chosen_now;
		}

		foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {
			if ( ! WC_Subscriptions_Cart::cart_contains_subscriptions_needing_shipping( $recurring_cart ) ) {
				continue;
			}

			WC_Subscriptions_Cart::set_calculation_type( 'recurring_total' );
			WC_Subscriptions_Cart::set_recurring_cart_key( $recurring_cart_key );
			WC_Subscriptions_Cart::set_cached_recurring_cart( $recurring_cart );

			foreach ( $recurring_cart->get_shipping_packages() as $recurring_cart_package_key => $recurring_cart_package ) {
				$package = WC_Subscriptions_Cart::get_calculated_shipping_for_package( $recurring_cart_package );

				foreach ( $package['rates'] as $rate ) {
					if ( current( $chosen_now ) === $rate->get_id() ) {
						$chosen_now[ $recurring_cart_package_key ] = current( $chosen_now );
					}
				}
			}

			WC_Subscriptions_Cart::set_calculation_type( 'none' );
			WC_Subscriptions_Cart::set_recurring_cart_key( 'none' );
		}

		return $chosen_now;
	}

	/**
	 * Update shipping method.
	 */
	public function ajax_update_shipping_method() {
		check_ajax_referer( 'wc-gateway-bluesnap-ajax-update_shipping_method', 'security' );

		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
		$shipping_method         = filter_input( INPUT_POST, 'method', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );

		if ( is_array( $shipping_method ) ) {
			foreach ( $shipping_method as $i => $value ) {
				$chosen_shipping_methods[ $i ] = wc_clean( $value );
			}
		}

		WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );

		WC()->cart->calculate_totals();

		$data  = array();
		$data += $this->build_display_items();

		wp_send_json_success( $data );
	}

	/**
	 * Calculate and set shipping method.
	 *
	 * @since 1.2.0
	 * @param array $address
	 */
	protected function calculate_shipping( $address = array() ) {
		global $states;

		$country   = $address['country'];
		$state     = $address['state'];
		$postcode  = $address['postcode'];
		$city      = $address['city'];
		$address_1 = $address['address_1'];
		$address_2 = $address['address_2'];

		$country_class = new WC_Countries();
		$country_class->load_country_states();

		/**
		 * In some versions of Chrome, state can be a full name. So we need
		 * to convert that to abbreviation as WC is expecting that.
		 */
		if ( isset( $states[ $country ] ) && is_array( $states[ $country ] ) && ! array_key_exists( $state, $states[ $country ] ) && 2 < strlen( $state ) ) {
			$state = array_search( ucfirst( strtolower( $state ) ), $states[ $country ] );
		}

		WC()->shipping->reset_shipping();

		if ( $postcode && WC_Validation::is_postcode( $postcode, $country ) ) {
			$postcode = wc_format_postcode( $postcode, $country );
		}

		if ( $country ) {
			WC()->customer->set_location( $country, $state, $postcode, $city );
			WC()->customer->set_shipping_location( $country, $state, $postcode, $city );
		} else {
			version_compare( WC_VERSION, '3.0', '<' ) ? WC()->customer->set_to_base() : WC()->customer->set_billing_address_to_base();
			version_compare( WC_VERSION, '3.0', '<' ) ? WC()->customer->set_shipping_to_base() : WC()->customer->set_shipping_address_to_base();
		}

		if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
			WC()->customer->calculated_shipping( true );
		} else {
			WC()->customer->set_calculated_shipping( true );
			WC()->customer->save();
		}

		$packages = array();

		$packages[0]['contents']                 = WC()->cart->get_cart();
		$packages[0]['contents_cost']            = 0;
		$packages[0]['applied_coupons']          = WC()->cart->applied_coupons;
		$packages[0]['user']['ID']               = get_current_user_id();
		$packages[0]['destination']['country']   = $country;
		$packages[0]['destination']['state']     = $state;
		$packages[0]['destination']['postcode']  = $postcode;
		$packages[0]['destination']['city']      = $city;
		$packages[0]['destination']['address']   = $address_1;
		$packages[0]['destination']['address_2'] = $address_2;

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( $item['data']->needs_shipping() ) {
				if ( isset( $item['line_total'] ) ) {
					$packages[0]['contents_cost'] += $item['line_total'];
				}
			}
		}

		$packages = apply_filters( 'woocommerce_cart_shipping_packages', $packages );

		WC()->shipping->calculate_shipping( $packages );
	}

	/**
	 * Sets the WC customer session if one is not set.
	 * This is needed so nonces can be verified by AJAX Request.
	 *
	 * @since 1.2.0
	 */
	public function set_session() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ( isset( WC()->session ) && WC()->session->has_session() ) ) {
			return;
		}

		$session_class = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
		$wc_session    = new $session_class();

		if ( version_compare( WC_VERSION, '3.3', '>=' ) ) {
			$wc_session->init();
		}

		$wc_session->set_customer_session_cookie( true );
	}

	/**
	 * Check PMR availability.
	 *
	 * @since 1.3.0
	 */
	protected function get_payment_request_available_gateway() {
		global $post;

		$gateways = WC()->payment_gateways->get_available_payment_gateways();

		if ( ! isset( $gateways[ WC_BLUESNAP_GATEWAY_ID ] ) ) {
			return;
		}

		if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
			return;
		}

		if ( is_checkout() && ! apply_filters( 'wc_gateway_bluesnap_show_payment_request_on_checkout', true, $post, $this ) ) {
			return;
		}

		if ( is_cart() && ! apply_filters( 'wc_gateway_bluesnap_show_payment_request_on_cart', true, $post, $this ) ) {
			return;
		}

		$switch = class_exists( 'WC_Subscriptions_Switcher' ) ? WC_Subscriptions_Switcher::cart_contains_switches() : false;

		// Check if on a Subscription Switch and subscription's payment method isn't a PMR.
		if ( $switch ) {
			$cart_item       = reset( $switch );
			$subscription_id = $cart_item['subscription_id'];
			$subscription    = $subscription_id ? wc_get_order( $subscription_id ) : null;

			if ( ! $subscription || ! is_object( $subscription ) ) {
				return;
			}

			// No PMR on downgrade.
			if ( ! WC()->cart->needs_payment() ) {
				return;
			}
		}

		if ( ! $this->allowed_items_in_cart() ) {
			return;
		}

		return $gateways[ WC_BLUESNAP_GATEWAY_ID ];
	}

	/**
	 * Filters the gateway title to reflect Payment Request type
	 *
	 * @param string $title
	 * @param string $id the ID of the payment gateway
	 *
	 * @return string
	 */
	public function filter_gateway_title( $title, $id ) {
		global $post;

		$order = false;

		if ( is_a( $post, 'WP_Post' ) ) {
			$order = wc_get_order( $post->ID );
		}
		if ( ! $order && isset( $_GET['page'] ) && 'wc-orders' === wc_clean( $_GET['page'] ) && isset( $_GET['id'] ) ) {
			$order = wc_get_order( absint( $_GET['id'] ) );
		}
		if ( ! $order && is_wc_endpoint_url( 'view-order' ) ) {
			$order = wc_get_order( get_query_var( 'view-order' ) );
		}
		if ( ! $order && is_wc_endpoint_url( 'view-subscription' ) ) {
			$order = wc_get_order( get_query_var( 'view-subscription' ) );
		}
		if ( ! $order ) {
			return $title;
		}

		$method_title = is_object( $order ) ? $order->get_payment_method_title() : '';

		if ( WC_BLUESNAP_GATEWAY_ID === $id && ! empty( $method_title ) && $this->title === $method_title ) {
			return $method_title;
		}

		return $title;
	}

	/**
	 * Get the updated payment method title (fix issues on emails)
	 *
	 * @param array    $item_totals The order item totals.
	 * @param WC_Order $order The order object.
	 */
	public function filter_gateway_title_order_totals( $item_totals, $order ) {

		if ( ! $order || ! is_object( $order ) ) {
			return $item_totals;
		}

		if ( ! isset( $item_totals['payment_method'] ) || ! isset( $item_totals['payment_method']['value'] ) ) {
			return $item_totals;
		}

		$order->get_data_store()->read( $order );

		$item_totals['payment_method']['value'] = $order->get_payment_method_title();

		return $item_totals;
	}

	/**
	 * Essentially ignores the "// Use the current title of the payment gateway when available" in WC_Subscription::get_payment_method_to_display()
	 *
	 * @param string          $payment_method_to_display
	 * @param WC_Subscription $subscription
	 * @param string          $context
	 *
	 * @return string
	 */
	public function filter_subscription_gateway_title( $payment_method_to_display, $subscription, $context ) {

		if ( ! $subscription->is_manual() ) {
			$payment_method_to_display = $subscription->get_payment_method_title();
		}

		return $payment_method_to_display;
	}

	/**
	 * Check if the Order was paid through a PMR
	 *
	 * @param object $order
	 *
	 * @return bool
	 */
	private function order_method_is_payment_request( $order ) {

		if ( ! $order || ! is_object( $order ) ) {
			return false;
		}

		return WC_BLUESNAP_GATEWAY_ID === $order->get_payment_method() && $this->title === $order->get_payment_method_title();
	}

	/**
	 * Add needed order meta
	 *
	 * @since 1.2.0
	 * @param int $order_id
	 */
	public function add_order_meta( $order_id, $transaction = null ) {
		if ( is_null( $this->get_payment_request_type() ) && is_null( $transaction ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		$payment_request_type = $this->get_payment_request_type();

		if ( empty( $payment_request_type ) ) {
			$payment_request_type = is_array( $transaction ) && isset( $transaction['paymentSource'], $transaction['paymentSource']['wallet'], $transaction['paymentSource']['wallet']['walletType'] ) ? strtolower( wc_clean( $transaction['paymentSource']['wallet']['walletType'] ) ) : '';
		}

		if ( $this->type === $payment_request_type ) {
			$order->update_meta_data( '_bluesnap_payment_request_order', 'yes' );
			$order->update_meta_data( '_bluesnap_payment_request_title', $this->title );
			$order->set_payment_method_title( $this->title );
			$order->save();
		}
	}

	/**
	 * Handle setting of the PM title to the PR's title if parent subscription is the PR of the instance
	 * Since we don't have any info on the (newly created) actual order if it fails.
	 */
	public function add_order_meta_to_renewal( $amount, $renewal_order ) {

		$subscription = wcs_get_subscriptions_for_order( $renewal_order, array( 'order_type' => array( 'renewal' ) ) );

		if ( ! empty( $subscription ) && $this->order_method_is_payment_request( reset( $subscription ) ) ) {
			$renewal_order->set_payment_method_title( $this->title );
			$renewal_order->save();
		}
	}

	/**
	 * Remove the PR meta from the newly created renewal order.
	 *
	 * @param string $meta_query The meta query.
	 */
	public function remove_renewal_order_meta_query( $meta_query ) {

		$meta_query .= " AND `meta_key` NOT IN ('_bluesnap_payment_request_order', '_bluesnap_payment_request_title')";

		return $meta_query;
	}

	/**
	 * Checks to make sure product type is supported.
	 *
	 * @since 1.2.0
	 * @return array
	 */
	public function supported_product_types() {
		return apply_filters(
			'wc_gateway_bluesnap_payment_request_supported_types',
			array(
				'simple',
				'variable',
				'variation',
				'subscription',
				'subscription_variation',
				'booking',
				'bundle',
				'composite',
				'mix-and-match',
			)
		);
	}

	/**
	 * Checks the cart to see if all items are allowed to used.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	public function allowed_items_in_cart() {
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );

			if ( ! in_array( $_product->get_type(), $this->supported_product_types() ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Clean the country code
	 *
	 * @param string $country
	 *
	 * @return string
	 */
	protected function clear_country_code( $country ) {
		$clean_country_codes = array(
			'australia'             => 'AU',
			'austria'               => 'AT',
			'canada'                => 'CA',
			'schweiz'               => 'CH',
			'deutschland'           => 'DE',
			'hongkong'              => 'HK',
			'saudiarabia'           => 'SA',
			'espaa'                 => 'ES',
			'singapore'             => 'SG',
			'us'                    => 'US',
			'usa'                   => 'US',
			'unitedstatesofamerica' => 'US',
			'unitedstates'          => 'US',
			'england'               => 'GB',
			'gb'                    => 'GB',
			'uk'                    => 'GB',
			'unitedkingdom'         => 'GB',
		);

		$country = preg_replace( '/[^a-z]+/', '', strtolower( $country ) );

		return isset( $clean_country_codes[ $country ] ) ? $clean_country_codes[ $country ] : null;
	}

	/**
	 * Fill the POST variables for the WC methods to find.
	 *
	 * @param string $type
	 * @param array  $wc_contact
	 *
	 * @return void
	 */
	protected function fill_contact_variables( $type, $wc_contact ) {
		foreach ( $wc_contact as $prop => $val ) {
			$_POST[ $type . '_' . $prop ] = $val;
		}
	}

	/**
	 * Normalizes the state/county field because in some
	 * cases, the state/county field is formatted differently from
	 * what WC is expecting and throws an error. An example
	 * for Ireland the county dropdown in Chrome shows "Co. Clare" format
	 *
	 * @since 1.2.0
	 */
	public function normalize_state( $contact ) {
		$billing_country = ! empty( $contact['country'] ) ? wc_clean( $contact['country'] ) : '';
		$billing_state   = ! empty( $contact['state'] ) ? wc_clean( $contact['state'] ) : '';

		if ( $billing_state && $billing_country ) {
			$contact['state'] = $this->get_normalized_state( $billing_state, $billing_country );
		}

		return $contact;
	}


	/**
	 * The Payment Request API provides its own validation for the address form.
	 * For some countries, it might not provide a state field, so we need to return a more descriptive
	 * error message, indicating that the Payment Request button is not supported for that country.
	 *
	 * @since 3.0.0
	 */
	protected function validate_state() {
		$wc_checkout     = WC_Checkout::instance();
		$posted_data     = $wc_checkout->get_posted_data();
		$checkout_fields = $wc_checkout->get_checkout_fields();
		$countries       = WC()->countries->get_countries();

		$is_supported          = true;
		$not_supported_country = '';

		// Checks if billing state is missing and is required.
		if ( ! empty( $checkout_fields['billing']['billing_state']['required'] ) && '' === $posted_data['billing_state'] ) {
			$is_supported          = false;
			$not_supported_country = isset( $countries[ $posted_data['billing_country'] ] ) ? $countries[ $posted_data['billing_country'] ] : $posted_data['billing_country'];
		}
		
		// Checks if shipping state is missing and is required.
		if ( WC()->cart->needs_shipping_address() && ! empty( $checkout_fields['shipping']['shipping_state']['required'] ) && '' === $posted_data['shipping_state'] ) {
			$is_supported          = false;
			$not_supported_country = isset( $countries[ $posted_data['shipping_country'] ] ) ? $countries[ $posted_data['shipping_country'] ] : $posted_data['shipping_country'];
		}

		if ( ! $is_supported ) {
			wc_add_notice(
				sprintf(
					/* translators: 1) payment type 2) country. */
					__( '%1$s is not supported in %2$s because some required fields couldn\'t be verified. Please proceed to the checkout page and try again or choose a different method.', 'woocommerce-bluesnap-gateway' ),
					$this->title,
					$not_supported_country,
				),
				'error'
			);
		}
	}


	/**
	 * Checks if given state is normalized.
	 *
	 * @param string $state State.
	 * @param string $country Two-letter country code.
	 *
	 * @return bool Whether state is normalized or not.
	 */
	public function is_normalized_state( $state, $country ) {
		$wc_states = WC()->countries->get_states( $country );
		return is_array( $wc_states ) && array_key_exists( $state, $wc_states );
	}

	/**
	 * Sanitize string for comparison.
	 *
	 * @param string $string String to be sanitized.
	 *
	 * @return string The sanitized string.
	 */
	public function sanitize_string( $string ) {
		return trim( wc_strtolower( remove_accents( $string ) ) );
	}

	/**
	 * Get normalized state from Payment Request API dropdown list of states.
	 *
	 * @param string $state   Full state name or state code.
	 * @param string $country Two-letter country code.
	 *
	 * @return string Normalized state or original state input value.
	 */
	public function get_normalized_state_from_pr_states( $state, $country ) {       
		$pr_states = WC_Bluesnap_Payment_Request_States::STATES;

		if ( ! isset( $pr_states[ $country ] ) ) {
			return $state;
		}

		foreach ( $pr_states[ $country ] as $wc_state_abbr => $pr_state ) {
			$sanitized_state_string = $this->sanitize_string( $state );
			// Checks if input state matches with Payment Request state code (0), name (1) or localName (2).
			if (
				( ! empty( $pr_state[0] ) && $sanitized_state_string === $this->sanitize_string( $pr_state[0] ) ) ||
				( ! empty( $pr_state[1] ) && $sanitized_state_string === $this->sanitize_string( $pr_state[1] ) ) ||
				( ! empty( $pr_state[2] ) && $sanitized_state_string === $this->sanitize_string( $pr_state[2] ) )
			) {
				return $wc_state_abbr;
			}
		}

		return $state;
	}

	/**
	 * Get normalized state from WooCommerce list of translated states.
	 *
	 * @param string $state   Full state name or state code.
	 * @param string $country Two-letter country code.
	 *
	 * @return string Normalized state or original state input value.
	 */
	public function get_normalized_state_from_wc_states( $state, $country ) {
		$wc_states = WC()->countries->get_states( $country );

		if ( is_array( $wc_states ) ) {
			foreach ( $wc_states as $wc_state_abbr => $wc_state_value ) {
				if ( preg_match( '/' . preg_quote( $wc_state_value, '/' ) . '/i', $state ) ) {
					return $wc_state_abbr;
				}
			}
		}

		return $state;
	}

	/**
	 * Gets the normalized state/county field because in some
	 * cases, the state/county field is formatted differently from
	 * what WC is expecting and throws an error. An example
	 * for Ireland, the county dropdown in Chrome shows "Co. Clare" format.
	 *
	 * @param string $state   Full state name or an already normalized abbreviation.
	 * @param string $country Two-letter country code.
	 *
	 * @return string Normalized state abbreviation.
	 */
	public function get_normalized_state( $state, $country ) {
		// If it's empty or already normalized, skip.
		if ( ! $state || $this->is_normalized_state( $state, $country ) ) {
			return $state;
		}

		// Try to match state from the Payment Request API list of states.
		$state = $this->get_normalized_state_from_pr_states( $state, $country );

		// If it's normalized, return.
		if ( $this->is_normalized_state( $state, $country ) ) {
			return $state;
		}

		// If the above doesn't work, fallback to matching against the list of translated
		// states from WooCommerce.
		return $this->get_normalized_state_from_wc_states( $state, $country );
	}

	/**
	 * Filter out specific errors that need to be ignored.
	 *
	 * @param object $errors
	 *
	 * @return object
	 */
	public function validate_alternate_payment( $errors ) {
		if ( ! isset( $_POST['decoded_payment_token'] ) ) { // WPCS: CSRF ok.
			return $errors;
		}

		if ( $errors->get_error_message( 'card_info_invalid' ) ) {
			$errors->remove( 'card_info_invalid' );
		}

		if ( $errors->get_error_message( 'threeds_reference_invalid' ) ) {
			$errors->remove( 'threeds_reference_invalid' );
		}

		return $errors;
	}

	/**
	 * Builds the line items to pass to Payment Request
	 *
	 * @since 1.2.0
	 */
	protected function build_display_items( $show_shipping = true ) {
		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}

		WC()->payment_gateways();

		$items     = array();
		$discounts = 0;

		// Default show only subtotal instead of itemization.
		if ( apply_filters( 'wc_gateway_bluesnap_payment_request_show_itemization', true ) ) {
			WC()->cart->calculate_totals();
			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$amount         = $this->get_product_price( $cart_item['data'] );
				$quantity_label = 1 < $cart_item['quantity'] ? ' (x' . $cart_item['quantity'] . ')' : '';

				$product_name = version_compare( WC_VERSION, '3.0', '<' ) ? $cart_item['data']->post->post_title : $cart_item['data']->get_name();

				$item = $this->display_item_template(
					array(
						'label' => $product_name . $quantity_label,
						'type'  => 'LINE_ITEM',
						'price' => bluesnap_format_decimal( $amount ),
					)
				);

				$items_to_add = apply_filters( 'wc_gateway_bluesnap_payment_request_cart_item_line_items', array( $item ), $item, $cart_item, $this );

				foreach ( $items_to_add as $item ) {
					$items[] = $item;
				}
			}
		}

		if ( version_compare( WC_VERSION, '3.2', '<' ) ) {
			$discounts = wc_format_decimal( WC()->cart->get_cart_discount_total(), WC()->cart->dp );
		} else {
			$applied_coupons = array_values( WC()->cart->get_coupon_discount_totals() );

			foreach ( $applied_coupons as $amount ) {
				$discounts += (float) $amount;
			}
		}

		$subtotal = WC()->cart->get_subtotal();

		if ( WC()->cart->display_prices_including_tax() ) {
			$subtotal += WC()->cart->get_subtotal_tax();
		}

		$items = apply_filters( 'wc_gateway_bluesnap_payment_request_items_subtotal', $items, bluesnap_format_decimal( $subtotal ) );

		$discounts   = wc_format_decimal( $discounts, WC()->cart->dp );
		$tax         = wc_format_decimal( WC()->cart->tax_total + WC()->cart->shipping_tax_total, WC()->cart->dp );
		$shipping    = $this->get_cart_shipping_total();
		$items_total = wc_format_decimal( WC()->cart->cart_contents_total, WC()->cart->dp ) + $discounts;
		$order_total = version_compare( WC_VERSION, '3.2', '<' ) ? wc_format_decimal( $items_total + $tax + $shipping - $discounts, WC()->cart->dp ) : WC()->cart->get_total( false );

		if ( wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() ) {
			$items[] = $this->display_item_template(
				array(
					'label' => esc_html( __( 'Tax', 'woocommerce-bluesnap-gateway' ) ),
					'type'  => 'TAX',
					'price' => bluesnap_format_decimal( $tax ),
				)
			);
		}

		if ( $show_shipping && WC()->cart->needs_shipping() ) {
			$items[] = $this->display_item_template(
				array(
					'label' => esc_html( __( 'Shipping', 'woocommerce-bluesnap-gateway' ) ),
					'type'  => 'LINE_ITEM',
					'price' => bluesnap_format_decimal( $shipping ),
				)
			);
		}

		if ( WC()->cart->has_discount() ) {
			$items[] = $this->display_item_template(
				array(
					'label' => esc_html( __( 'Discount', 'woocommerce-bluesnap-gateway' ) ),
					'type'  => 'LINE_ITEM',
					'price' => bluesnap_format_decimal( $discounts ),
				)
			);
		}

		if ( version_compare( WC_VERSION, '3.2', '<' ) ) {
			$cart_fees = WC()->cart->fees;
		} else {
			$cart_fees = WC()->cart->get_fees();
		}

		// Include fees and taxes as display items.
		foreach ( $cart_fees as $key => $fee ) {
			$items[] = $this->display_item_template(
				array(
					'label' => $fee->name,
					'type'  => 'LINE_ITEM',
					'price' => bluesnap_format_decimal( $fee->amount ),
				)
			);
		}

		$items = apply_filters( 'wc_gateway_bluesnap_payment_request_items', $items, $order_total, bluesnap_format_decimal( $subtotal ), $this );

		return $this->display_items_template(
			array(
				'items'  => $items,
				'label'  => get_option( 'blogname' ),
				'amount' => bluesnap_format_decimal( max( 0, apply_filters( 'wc_gateway_bluesnap_payment_request_calculated_total', $order_total, $order_total, WC()->cart ) ) ),
				'type'   => 'final',
			)
		);
	}

	/**
	 * Build the displayItems array from a specific order
	 *
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	protected function build_display_items_from_order( $order ) {
		$order = wc_get_order( $order );

		$prices_include_taxes = 'incl' === WC()->cart->get_tax_price_display_mode();

		WC()->payment_gateways();

		$items     = array();
		$subtotal  = 0;
		$discounts = 0;

		// Default show only subtotal instead of itemization.
		if ( apply_filters( 'wc_gateway_bluesnap_payment_request_show_itemization', true ) ) {
			foreach ( $order->get_items() as $cart_item_key => $order_item ) {
				$amount         = $order->get_line_subtotal( $order_item, $prices_include_taxes );
				$subtotal      += $amount;
				$qty            = $order_item->get_quantity( 'edit' );
				$quantity_label = 1 < $qty ? ' (x' . $qty . ')' : '';

				$product_name = $order_item->get_name();

				$item = $this->display_item_template(
					array(
						'label' => $product_name . $quantity_label,
						'type'  => 'LINE_ITEM',
						'price' => bluesnap_format_decimal( $amount ),
					)
				);

				$items_to_add = apply_filters( 'wc_gateway_bluesnap_payment_request_order_item_line_items', array( $item ), $item, $order_item );

				foreach ( $items_to_add as $item ) {
					$items[] = $item;
				}
			}
		}

		$items = apply_filters( 'wc_gateway_bluesnap_payment_request_order_items_subtotal', $items, bluesnap_format_decimal( $subtotal, $order->get_currency() ) );

		$discounts   = bluesnap_format_decimal( $order->get_total_discount(), $order->get_currency() );
		$tax         = bluesnap_format_decimal( $order->get_total_tax( 'edit' ), $order->get_currency() );
		$shipping    = bluesnap_format_decimal( $order->get_shipping_total( 'edit' ) + ( $prices_include_taxes ? $order->get_shipping_tax( 'edit' ) : 0 ), $order->get_currency() );
		$items_total = bluesnap_format_decimal( $order->get_subtotal( 'edit' ), $order->get_currency() ) + $discounts;
		$order_total = bluesnap_format_decimal( $order->get_total( 'edit' ), $order->get_currency() );

		if ( $order->get_total_tax( 'edit' ) && ! $prices_include_taxes ) {
			$items[] = $this->display_item_template(
				array(
					'label' => esc_html( __( 'Tax', 'woocommerce-bluesnap-gateway' ) ),
					'type'  => 'TAX',
					'price' => bluesnap_format_decimal( $tax, $order->get_currency() ),
				)
			);
		}

		if ( $order->get_shipping_total() ) {
			$items[] = $this->display_item_template(
				array(
					'label' => esc_html( __( 'Shipping', 'woocommerce-bluesnap-gateway' ) ),
					'type'  => 'LINE_ITEM',
					'price' => bluesnap_format_decimal( $shipping, $order->get_currency() ),
				)
			);
		}

		if ( $order->get_total_discount() ) {
			$items[] = $this->display_item_template(
				array(
					'label' => esc_html( __( 'Discount', 'woocommerce-bluesnap-gateway' ) ),
					'type'  => 'LINE_ITEM',
					'price' => bluesnap_format_decimal( $discounts, $order->get_currency() ),
				)
			);
		}

		$cart_fees = $order->get_fees();

		// Include fees and taxes as display items.
		foreach ( $cart_fees as $key => $fee ) {
			$items[] = $this->display_item_template(
				array(
					'label' => $fee->get_name(),
					'type'  => 'LINE_ITEM',
					'price' => bluesnap_format_decimal( $fee->get_total(), $order->get_currency() ),
				)
			);
		}

		$items = apply_filters( 'wc_gateway_bluesnap_payment_request_order_items', $items, $order_total, bluesnap_format_decimal( $subtotal, $order->get_currency() ) );

		return $this->display_items_template(
			array(
				'items'  => $items,
				'label'  => get_option( 'blogname' ),
				'amount' => bluesnap_format_decimal( max( 0, apply_filters( 'wc_gateway_bluesnap_payment_request_calculated_total', $order_total, $order_total, WC()->cart ) ), $order->get_currency() ),
				'type'   => 'final',
			)
		);
	}

	/**
	 * Get the product row price per item.
	 *
	 * @param WC_Product $product Product object.
	 * @return float
	 */
	protected function get_product_price( $product ) {
		if ( WC()->cart->display_prices_including_tax() ) {
			return wc_get_price_including_tax( $product );
		} else {
			return wc_get_price_excluding_tax( $product );
		}
	}

	/**
	 * Get the shipping total depending on the tax display configuration.
	 *
	 * @return float
	 */
	protected function get_cart_shipping_total() {
		if ( WC()->cart->display_prices_including_tax() ) {
			return wc_format_decimal( WC()->cart->shipping_total + WC()->cart->shipping_tax_total, WC()->cart->dp );
		} else {
			return wc_format_decimal( WC()->cart->shipping_total, WC()->cart->dp );
		}
	}

	/**
	 * Adapt the payload
	 *
	 * @param Object $ret
	 * @return Object
	 */
	public function adapted_payload_for_ondemand_wallet( $ret ) {
		if ( is_null( $this->get_payment_request_type() ) ) {
			return $ret;
		}

		if ( $this->type === $this->get_payment_request_type() && isset( $_POST['decoded_payment_token'] ) ) { // WPCS: CSRF ok.
			$ret->payload['paymentSource'] = array(
				'wallet' => $ret->payload['wallet'],
			);
			unset( $ret->payload['wallet'] );
		}

		return $ret;
	}

	/**
	 * Maybe add a hidden field that triggers redirection
	 *
	 * @return void
	 */
	public function maybe_add_redirect_field() {
		if ( ! isset( $_REQUEST['bs_pmr_signup_redirect'] ) ) {
			return;
		}
		?>
		<input type="hidden" name="redirect" value="<?php echo esc_url( wc_get_page_permalink( 'checkout' ) ); ?>" />
		<?php
	}

	/**
	 * Determine if an account is required for the specific cart contents.
	 *
	 * @return boolean
	 */
	public function payment_request_maybe_require_account() {
		if ( is_user_logged_in() ) { // we're logged in, we don't care for the cart contents at this point
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );

			if ( in_array( $_product->get_type(), array( 'subscription', 'subscription_variation' ) ) ) {
				// we're not logged in, and we found a subscription, so we need to display the login form
				return true;
			}
		}
		return false;
	}

	/**
	 * Use our class var to save the current subscription.
	 * If we are here, its possible that WC_Subscriptions_Change_Payment_Gateway::change_failing_payment_method() changed the PM title from our PaymentRequest title to BlueSnap
	 *
	 * @param [type] $subscription
	 * @param [type] $renewal_order
	 * @return void
	 */
	public function handle_failing_payment_method_updated( $subscription, $renewal_order ) {
		$this->revert_sub_pm_title_change = $subscription;
	}


	/**
	 * Adjust the order items before changing the payment method.
	 *
	 * @param array $order_data The order data.
	 */
	protected function modify_order_before_payment_method( $order_data ) {

		if ( isset( $order_data['displayItems'] ) ) {
			$order_data['displayItems'] = array();
		}

		if ( isset( $order_data['totalPrice'] ) ) {
			$order_data['totalPrice'] = bluesnap_format_decimal( 0 );
		}

		return $order_data;
	}

	/**
	 * Change the payment method to the current payment request.
	 *
	 * @param WC_Order $order The order to change the payment method.
	 *
	 * @throws Exception Missing Bluesnap Subscription ID.
	 */
	protected function change_payment_method( $order ) {

		try {
			$ondemand_wallet_id = $order->get_meta( self::ORDER_ONDEMAND_WALLET_ID );

			if ( ! $ondemand_wallet_id ) {
				throw new Exception( __( 'Bluesnap Subscription ID missing.', 'woocommerce-bluesnap-gateway' ) );
			}

			if ( ! isset( $_POST['payment_token'] ) || empty( $_POST['payment_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				throw new Exception( __( 'Payment Token missing.', 'woocommerce-bluesnap-gateway' ) );
			}

			$payment_token = wc_clean( wp_unslash( $_POST['payment_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			$this->update_subscription_payment_method( $payment_token, $ondemand_wallet_id );

			$this->update_payment_method_title( $order );

			$response = array(
				'result'   => 'success',
				'redirect' => $order->get_view_order_url(),
			);

			wp_send_json( $response );

		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );

			$response = array(
				'result'   => 'failure',
				'messages' => $e->getMessage(),
			);

			wp_send_json( $response );

		}
	}

	/**
	 * Update a subscription's payment method.
	 *
	 * @param string $payment_token      The payment token.
	 * @param string $ondemand_wallet_id The Bluesnap Subscription ID.
	 */
	protected function update_subscription_payment_method( $payment_token, $ondemand_wallet_id ) {
		$payload = array(
			'paymentSource' => array(
				'wallet' => array(
					'walletType'          => strtoupper( $this->type ),
					'encodedPaymentToken' => $payment_token,
				),
			),
		);

		WC_Bluesnap_API::update_subscription(
			$ondemand_wallet_id,
			$payload
		);
	}

	/**
	 * Update the payment method title to a subscription.
	 *
	 * @param WC_Order $order The order to update the payment method.
	 */
	protected function update_payment_method_title( $order ) {
		$subscription = wcs_is_subscription( $order ) ? $order : null;

		if ( is_null( $subscription ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'any' ) ) );
			$subscription  = ! empty( $subscriptions ) ? reset( $subscriptions ) : $subscription;
		}

		if ( $subscription ) {
			if ( WC_BLUESNAP_GATEWAY_ID !== $subscription->get_payment_method() ) {
				$subscription->set_payment_method( WC_BLUESNAP_GATEWAY_ID );
			}
			$subscription->set_payment_method_title( $this->title );
			$subscription->save();

			$notice = $subscription->has_payment_gateway() ? __( 'Payment method updated.', 'woocommerce-bluesnap-gateway' ) : __( 'Payment method added.', 'woocommerce-bluesnap-gateway' );

			wc_add_notice( $notice );
		}
	}
}
