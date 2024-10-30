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
 * Class WC_Bluesnap_Apple_Pay
 */
class WC_Bluesnap_Apple_Pay extends WC_Bluesnap_Payment_Request {

	/**
	 * The type of the payment request, populated by its slug.
	 *
	 * @var string
	 */
	protected $type = 'apple_pay';

	/**
	 * The title of the payment request.
	 *
	 * @var string
	 */
	protected $title = 'Apple Pay';

	/**
	 * Apple Simulator Tx Identifier.
	 */
	const APPLE_PAY_SIMULATOR_TX_IDENTIFIER = 'Simulated Identifier';

	/**
	 * WC_Bluesnap_Apple_Pay constructor.
	 */
	public function __construct() {

		$option = WC_Bluesnap()->get_option( $this->type );

		$this->enabled          = ( ! empty( $option ) && 'yes' === $option ) ? true : false;
		$this->version_required = 2;

		if ( ! $this->enabled ) {
			return;
		}

		add_filter( 'woocommerce_bluesnap_gateway_enqueue_scripts', array( $this, 'modify_frontend_data' ), 20, 1 );

		// Add Apple Pay Button to Cart
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'display_payment_request_button_html' ), 1 );
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'display_payment_request_button_separator_html' ), 2 );

		// Add Apple Pay Button to Checkout
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'display_payment_request_button_html' ), 1 );
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'display_payment_request_button_separator_html' ), 2 );

		// Add Apple Pay Button to Pay Order Screen
		add_action( 'woocommerce_pay_order_before_submit', array( $this, 'display_payment_request_button_html' ), 1 );
		add_action( 'woocommerce_pay_order_before_submit', array( $this, 'display_payment_request_button_separator_html' ), 2 );

		// Add Apple Pay Button to Change Payment Screen
		add_action( 'woocommerce_subscriptions_change_payment_before_submit', array( $this, 'display_payment_request_button_html' ), 1 );
		add_action( 'woocommerce_subscriptions_change_payment_before_submit', array( $this, 'display_payment_request_button_separator_html' ), 2 );

		add_filter( 'woocommerce_validate_postcode', array( $this, 'postal_code_validation' ), 10, 3 );
		add_filter( 'wc_gateway_bluesnap_transaction_payment_method_payload', array( $this, 'payment_request_payment_payload' ), 10, 2 );
		add_filter( 'wc_gateway_bluesnap_alternate_payment', array( $this, 'try_alternate_payment' ), 10, 4 );

		if ( $this->type === $this->get_payment_request_type() ) {
			add_action( 'wc_ajax_bluesnap_create_apple_wallet', array( $this, 'ajax_create_apple_wallet' ) );
		}

		parent::__construct();
	}

	/**
	 * Apple Wallet creation.
	 */
	public function ajax_create_apple_wallet() {
		check_ajax_referer( 'wc-gateway-bluesnap-ajax-create_apple_wallet', 'security' );

		$validation_url = false;
		if ( isset( $_POST['validation_url'] ) && ! empty( $_POST['validation_url'] ) ) {  // WPCS: CSRF ok.
			$validation_url = esc_url_raw( $_POST['validation_url'] );
		}

		if ( $validation_url ) {
			try {
				$result = WC_Bluesnap_API::create_apple_wallet( $validation_url );
				wp_send_json_success( $result );
			} catch ( WC_Bluesnap_API_Exception $e ) {
				WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
			}
		}
		wp_send_json_error();
	}

	/**
	 * Modify localized data for this specific PMR.
	 * @param $args
	 *
	 * @return array
	 */
	public function modify_frontend_data( $args ) {

		if ( isset( $args['woocommerce-bluesnap-payment-request']['data'] ) ) {
			$args['woocommerce-bluesnap-payment-request']['data'][ $this->type . '_enabled' ]        = $this->enabled;
			$args['woocommerce-bluesnap-payment-request']['data']['version_required'][ $this->type ] = $this->pr_version_required();
		}

		return $args;
	}

	/**
	 * Display the payment request button.
	 *
	 * @since 1.2.0
	 */
	public function display_payment_request_button_html() {
		$bluesnap_gateway = $this->get_payment_request_available_gateway();
		if ( ! $bluesnap_gateway ) {
			return;
		}

		if ( $this->payment_request_maybe_require_account() ) {
			?>
			<div id="wc-bluesnap-apple-pay-wrapper" style="clear:both;padding-top:1.5em;text-align:center;display:none;">
				<div id="wc-bluesnap-apple-pay-button-cont">
					<?php esc_html_e( 'Apple Pay is available, but an account is required.', 'woocommerce-bluesnap-gateway' ); ?><br/>
					<a href="<?php echo esc_url( add_query_arg( array( 'bs_pmr_signup_redirect' => '' ), wc_get_page_permalink( 'myaccount' ) ) ); ?>"><?php esc_html_e( 'Log In or Register', 'woocommerce-bluesnap-gateway' ); ?></a>.
				</div>
			</div>
			<?php
			return;
		}
		?>
		<div id="wc-bluesnap-apple-pay-wrapper" style="clear:both;padding-top:1.5em;text-align:center;display:none;">
			<div id="wc-bluesnap-apple-pay-button-cont">
				<a href="#" class="apple-pay-button apple-pay-button-black"></a>
				<?php $bluesnap_gateway->render_fraud_kount_iframe(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Display payment request button separator.
	 *
	 * @since 1.2.0
	 */
	public function display_payment_request_button_separator_html() {
		$bluesnap_gateway = $this->get_payment_request_available_gateway();
		if ( ! $bluesnap_gateway ) {
			return;
		}
		?>
		<p id="wc-bluesnap-apple-pay-button-separator" style="margin-top:1.5em;text-align:center;display:none;">&mdash; <?php esc_html_e( 'OR', 'woocommerce-bluesnap-gateway' ); ?> &mdash;</p>
		<?php
	}

	/**
	 * Removes postal code validation from WC.
	 *
	 * @since 1.2.0
	 */
	public function postal_code_validation( $valid, $postcode, $country ) {
		$gateways = WC()->payment_gateways->get_available_payment_gateways();

		if ( ! isset( $gateways[ WC_BLUESNAP_GATEWAY_ID ] ) ) {
			return $valid;
		}

		if ( $this->type !== $this->get_payment_request_type() ) {
			return $valid;
		}

		/**
		 * Currently Apple Pay truncates postal codes from UK and Canada to first 3 characters
		 * when passing it back from the shippingcontactselected object. This causes WC to invalidate
		 * the order and not let it go through. The remedy for now is just to remove this validation.
		 * Note that this only works with shipping providers that don't validate full postal codes.
		 */
		if ( 'GB' === $country || 'CA' === $country ) {
			return true;
		}

		return $valid;
	}

	/**
	 * Handles converting posted data
	 *
	 * @since 1.2.0
	 */
	protected function normalize_posted_data_for_order( $posted_data ) {
		$billing  = $this->normalize_contact( isset( $posted_data['billingContact'] ) ? $posted_data['billingContact'] : array() );
		$shipping = $this->normalize_contact( isset( $posted_data['shippingContact'] ) ? $posted_data['shippingContact'] : array() );

		$billing['email'] = $shipping['email'];
		unset( $shipping['email'] );
		$billing['phone'] = $shipping['phone'];
		unset( $shipping['phone'] );

		$this->fill_contact_variables( 'billing', $billing );
		$this->fill_contact_variables( 'shipping', $shipping );

		$_POST['order_comments']            = '';
		$_POST['payment_method']            = WC_BLUESNAP_GATEWAY_ID;
		$_POST['ship_to_different_address'] = '1';
		$_POST['terms']                     = '1';
	}

	/**
	 * Transform contact info to match expected format & labels.
	 *
	 * @param array $contact
	 * @return array
	 */
	protected function normalize_contact( $contact ) {
		if ( empty( $contact['countryCode'] ) && isset( $contact['country'] ) ) {
			$contact['countryCode'] = $this->clear_country_code( $contact['country'] );
		}

		$wc_contact = array(
			'first_name' => '',
			'last_name'  => '',
			'company'    => '',
			'email'      => '',
			'phone'      => '',
			'country'    => '',
			'address_1'  => '',
			'address_2'  => '',
			'city'       => '',
			'state'      => '',
			'postcode'   => '',
		);

		if ( ! empty( $contact['givenName'] ) && is_string( $contact['givenName'] ) ) {
			$wc_contact['first_name'] = $contact['givenName'];
		}

		if ( ! empty( $contact['familyName'] ) && is_string( $contact['familyName'] ) ) {
			$wc_contact['last_name'] = $contact['familyName'];
		}

		if ( ! empty( $contact['emailAddress'] ) && is_string( $contact['emailAddress'] ) ) {
			$wc_contact['email'] = $contact['emailAddress'];
		}

		if ( ! empty( $contact['phoneNumber'] ) && is_string( $contact['phoneNumber'] ) ) {
			$wc_contact['phone'] = $contact['phoneNumber'];
		}

		if ( ! empty( $contact['countryCode'] ) && is_string( $contact['countryCode'] ) ) {
			$wc_contact['country'] = $contact['countryCode'];
		}

		if ( ! empty( $contact['addressLines'] ) && is_array( $contact['addressLines'] ) ) {
			$lines                   = $contact['addressLines'];
			$wc_contact['address_1'] = array_shift( $lines );
			$wc_contact['address_2'] = implode( ', ', $lines );
		}

		if ( ! empty( $contact['locality'] ) && is_string( $contact['locality'] ) ) {
			$wc_contact['city'] = $contact['locality'];
		}

		if ( ! empty( $contact['administrativeArea'] ) && is_string( $contact['administrativeArea'] ) ) {
			$wc_contact['state'] = $contact['administrativeArea'];
		}

		if ( ! empty( $contact['postalCode'] ) && is_string( $contact['postalCode'] ) ) {
			$wc_contact['postcode'] = $contact['postalCode'];
		}

		$wc_contact = $this->maybe_normalize_hong_kong_contact( $wc_contact );

		$wc_contact = $this->normalize_state( $wc_contact );

		return $wc_contact;
	}

	/**
	 * Due to a bug in Apple Pay, the "Region" part of a Hong Kong address is delivered in
	 * `shipping_postcode`, so we need some special case handling for that.
	 *
	 * @param array $wc_contact The contact information.
	 */
	protected function maybe_normalize_hong_kong_contact( $wc_contact ) {

		if ( ! isset( $wc_contact['country'] ) || 'HK' !== $wc_contact['country'] || empty( $wc_contact['postcode'] ) ) {
			return $wc_contact;
		}

		$wc_contact['state']    = $wc_contact['postcode'];
		$wc_contact['postcode'] = '';

		return $wc_contact;
	}


	/**
	 * Set the encoded payment token onto the request payload.
	 *
	 * @param object $ret
	 * @param WC_Order $order
	 * @return object
	 */
	public function payment_request_payment_payload( $ret, $order ) {
		if ( ! isset( $_POST['payment_request_type'] ) ) { // WPCS: CSRF ok. // check if apple_pay
			return $ret;
		}

		if ( 'apple_pay' == $_POST['payment_request_type'] && isset( $_POST['decoded_payment_token'] ) ) { // WPCS: CSRF ok.
			$ret->type    = 'payment_request_' . $_POST['payment_request_type']; // WPCS: CSRF ok.
			$ret->payload = array_merge(
				$ret->payload,
				array(
					'wallet' => array(
						'applePay' => array(
							'encodedPaymentToken' => base64_encode( wp_json_encode( $_POST['decoded_payment_token'] ) ), // WPCS: CSRF ok.
						),
					),
				)
			);
		}

		return $ret;
	}

	/**
	 * Template function to return specific arguments in the expected format.
	 *
	 * @param array $args
	 * @return array
	 */
	public function display_item_template( $args ) {

		return array(
			'label'  => $args['label'],
			'amount' => $args['price'],
		);
	}

	/**
	 * Template function to return specific arguments in the expected format.
	 *
	 * @param array $args
	 * @return array
	 */
	public function display_items_template( $args ) {
		return array(
			'lineItems' => $args['items'],
			'total'     => array(
				'label'  => $args['label'],
				'amount' => $args['amount'],
				'type'   => $args['type'],
			),
		);
	}


	/**
	 * Convert returned payload & add expected data.
	 *
	 * @param array $pr
	 * @return array
	 */
	protected function payment_request_convert( $pr ) {

		$ret             = array();
		$ret            += $pr['order_data'];
		$billing_fields  = array();
		$shipping_fields = array();

		if ( isset( $pr['billing_required'] ) && $pr['billing_required'] ) {
			$billing_fields  = array_merge( $billing_fields, array( 'postalAddress', 'name' ) );
			$shipping_fields = array_merge( $shipping_fields, array( 'email', 'phone' ) );
		}

		if ( isset( $pr['shipping_required'] ) && $pr['shipping_required'] ) {
			$shipping_fields = array_merge( $shipping_fields, array( 'postalAddress' ) );
		}

		$ret += array(
			'supportedNetworks'             => array( 'amex', 'discover', 'masterCard', 'visa' ),
			'merchantCapabilities'          => array( 'supports3DS' ),
			'requiredBillingContactFields'  => $billing_fields,
			'requiredShippingContactFields' => $shipping_fields,
		);

		return $ret;
	}

	/**
	 * Allow ApplePay's test mode to short-circuit the gateway's process_payment().
	 *
	 * @param boolean $attempt Setting to false short-circuit's process_payment().
	 * @param WC_Order $order
	 * @param $payload $payload
	 * @param WC_Bluesnap_Gateway $gateway
	 * @return boolean
	 */
	public function try_alternate_payment( $attempt, $order, $payload, $gateway ) {

		if ( $this->type !== $this->get_payment_request_type() ) {
			return $attempt;
		}

		$test = ( ! empty( WC_Bluesnap()->get_option( 'testmode' ) && 'yes' === WC_Bluesnap()->get_option( 'testmode' ) ) ) ? true : false;

		if ( isset( $_POST['decoded_payment_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $test && self::APPLE_PAY_SIMULATOR_TX_IDENTIFIER === $_POST['decoded_payment_token']['token']['transactionIdentifier'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$gateway->add_order_success_note( $order, self::APPLE_PAY_SIMULATOR_TX_IDENTIFIER );
				$order->payment_complete( self::APPLE_PAY_SIMULATOR_TX_IDENTIFIER );
				return false; // return false to avoid other attempts
			}
		}

		return $attempt;
	}
}

return new WC_Bluesnap_Apple_Pay();
