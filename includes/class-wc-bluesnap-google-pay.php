<?php
/**
 * @author   SAU/CAL
 * @category Class
 * @package  Woocommerce_Bluesnap_Gateway/Classes
 * @version  3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Bluesnap_Google_Pay
 */
class WC_Bluesnap_Google_Pay extends WC_Bluesnap_Payment_Request {

	/**
	 * The type of the payment request, populated by its slug.
	 *
	 * @var string
	 */
	protected $type = 'google_pay';

	/**
	 * The title of the payment request.
	 *
	 * @var string
	 */
	protected $title = 'Google Pay';

	/**
	 * WC_Bluesnap_Google_Pay constructor.
	 */
	public function __construct() {

		if ( ! $this->is_enabled() ) {
			return;
		}

		add_filter( 'woocommerce_bluesnap_gateway_enqueue_scripts', array( $this, 'modify_frontend_data' ), 20, 1 );

		// Add Google Pay Button to Cart
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'display_payment_request_button_html' ), 1 );
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'display_payment_request_button_separator_html' ), 2 );

		// Add Google Pay Button to Checkout
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'display_payment_request_button_html' ), 1 );
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'display_payment_request_button_separator_html' ), 2 );

		// Add Google Pay Button to Pay Order Screen
		add_action( 'woocommerce_pay_order_before_submit', array( $this, 'display_payment_request_button_html' ), 1 );
		add_action( 'woocommerce_pay_order_before_submit', array( $this, 'display_payment_request_button_separator_html' ), 2 );

		// Add Google Pay Button to Change Payment Screen
		add_action( 'woocommerce_subscriptions_change_payment_before_submit', array( $this, 'display_payment_request_button_html' ), 1 );
		add_action( 'woocommerce_subscriptions_change_payment_before_submit', array( $this, 'display_payment_request_button_separator_html' ), 2 );

		add_filter( 'wc_gateway_bluesnap_transaction_payment_method_payload', array( $this, 'payment_request_payment_payload' ), 10, 2 );
		add_filter( 'wc_gateway_bluesnap_payment_request_items_subtotal', array( $this, 'add_subtotal_to_display_items' ), 10, 2 );
		add_filter( 'wc_gateway_bluesnap_payment_request_order_items_subtotal', array( $this, 'add_subtotal_to_display_items' ), 10, 2 );

		parent::__construct();
	}


	/**
	 * Check if Google Pay is enabled and available.
	 *
	 * @return bool
	 */
	protected function is_enabled() {
		$option = WC_Bluesnap()->get_option( $this->type );

		$this->enabled = ! empty( $option ) && 'yes' === $option;

		if ( ! $this->enabled ) {
			return false;
		}

		$test_mode = WC_Bluesnap()->get_option( 'testmode' );

		$test_mode = ! empty( $test_mode ) && 'yes' === $test_mode;

		if ( $test_mode ) {
			return true;
		}

		$google_merchant_id = WC_Bluesnap()->get_option( 'google_pay_merchant_id' );

		if ( empty( $google_merchant_id ) ) {
			$this->enabled = false;
			return false;
		}

		return true;
	}

	/**
	 * Modify localized data for this specific PMR.
	 * @param $args
	 *
	 * @return array
	 */
	public function modify_frontend_data( $args ) {

		if ( isset( $args['woocommerce-bluesnap-payment-request']['data'] ) ) {
			$args['woocommerce-bluesnap-payment-request']['data'][ $this->type . '_enabled' ]     = $this->enabled;
			$args['woocommerce-bluesnap-payment-request']['data'][ $this->type . '_merchant_id' ] = strval( WC_Bluesnap()->get_option( 'google_pay_merchant_id' ) );
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
			<div id="wc-bluesnap-google-pay-wrapper" class="requires-account" style="clear:both;padding-top:1.5em;text-align:center;display:none;">
				<div> 
					<?php esc_html_e( 'Google Pay is available, but an account is required.', 'woocommerce-bluesnap-gateway' ); ?><br/>
					<a href="<?php echo esc_url( add_query_arg( array( 'bs_pmr_signup_redirect' => '' ), wc_get_page_permalink( 'myaccount' ) ) ); ?>"><?php esc_html_e( 'Log In or Register', 'woocommerce-bluesnap-gateway' ); ?></a>.
				</div>
			</div>
			<?php
			return;
		}
		?>
		<div id="wc-bluesnap-google-pay-wrapper" style="clear:both;padding-top:1.5em;text-align:center;display:none;">
			<div id="wc-bluesnap-google-pay-button-cont">
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
		<p id="wc-bluesnap-google-pay-button-separator" style="margin-top:1.5em;text-align:center;display:none;">&mdash; <?php esc_html_e( 'OR', 'woocommerce-bluesnap-gateway' ); ?> &mdash;</p>
		<?php
	}

	/**
	 * Set the encoded payment token onto the request payload.
	 *
	 * @param object $ret
	 * @param WC_Order $order
	 * @return object
	 */
	public function payment_request_payment_payload( $ret, $order ) {
		if ( ! isset( $_POST['payment_request_type'] ) ) { // WPCS: CSRF ok. // check if google_pay
			return $ret;
		}

		if ( $this->type == $_POST['payment_request_type'] && isset( $_POST['decoded_payment_token'] ) ) { // WPCS: CSRF ok.
			$ret->type    = 'payment_request_' . $_POST['payment_request_type']; // WPCS: CSRF ok.
			$ret->payload = array_merge(
				$ret->payload,
				array(
					'wallet' => array(
						'walletType'          => 'GOOGLE_PAY',
						'encodedPaymentToken' => base64_encode( wp_json_encode( $_POST['decoded_payment_token'] ) ),
					),
				)
			);
		}

		return $ret;
	}

	/**
	 * Handles converting posted data
	 * @since 1.2.0
	 */
	protected function normalize_posted_data_for_order( $posted_data ) {
		$billing  = $this->normalize_contact( isset( $posted_data['paymentMethodData']['info']['billingAddress'] ) ? $posted_data['paymentMethodData']['info']['billingAddress'] : array() ); // $posted_data['info']['billingAddress']
		$shipping = $this->normalize_contact( isset( $posted_data['shippingAddress'] ) ? $posted_data['shippingAddress'] : array() ); // $shipping

		if ( isset( $posted_data['email'] ) && is_string( $posted_data['email'] ) ) {
			$billing['email'] = $posted_data['email'];
		}

		unset( $shipping['email'] );

		if ( ! empty( $shipping['phone'] ) ) {
			$billing['phone'] = $shipping['phone'];
		}
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
			$contact['countryCode'] = $this->clear_country_code( $contact['country'] ); // This is likely not needed in GooglePay, but shouldn't do any harm either.
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

		if ( ! empty( $contact['name'] ) && is_string( $contact['name'] ) ) {

			$name_parts = explode( ' ', $contact['name'] );

			$wc_contact['first_name'] = ! empty( $name_parts[0] ) ? $name_parts[0] : '';
			array_shift( $name_parts );
			$wc_contact['last_name'] = ! empty( $name_parts ) ? implode( ' ', $name_parts ) : '';
		}

		if ( ! empty( $contact['email'] ) && is_string( $contact['email'] ) ) {
			$wc_contact['email'] = $contact['email'];
		}

		if ( ! empty( $contact['phoneNumber'] ) && is_string( $contact['phoneNumber'] ) ) {
			$wc_contact['phone'] = $contact['phoneNumber'];
		}

		if ( ! empty( $contact['countryCode'] ) && is_string( $contact['countryCode'] ) ) {
			$wc_contact['country'] = $contact['countryCode'];
		}

		if ( ! empty( $contact['address1'] ) && is_string( $contact['address1'] ) ) {
			$wc_contact['address_1'] = $contact['address1'];
		}

		if ( ! empty( $contact['address2'] ) && is_string( $contact['address2'] ) ) {
			$wc_contact['address_2'] = $contact['address2'];
		}

		if ( ! empty( $contact['address3'] ) && is_string( $contact['address3'] ) ) {
			$wc_contact['address_2'] = $wc_contact['address2'] . ' ' . $contact['address3'];
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

		$wc_contact = $this->normalize_state( $wc_contact );

		return $wc_contact;
	}

	/**
	 * Template function to return specific arguments in the expected format.
	 *
	 * @param array $args
	 * @return array
	 */
	public function display_item_template( $args ) {

		return array(
			'label' => $args['label'],
			'type'  => $args['type'],
			'price' => $args['price'],
		);
	}

	/**
	 * Adds the 'SUBTOTAL' line item to the payload.
	 *
	 * @param array $items
	 * @param string $subtotal
	 * @return array
	 */
	public function add_subtotal_to_display_items( $items, $subtotal ) {

		if ( $this->type !== $this->get_payment_request_type() ) {
			return $items;
		}

		$items[] = array(
			'label' => esc_html( __( 'Subtotal', 'woocommerce-bluesnap-gateway' ) ),
			'type'  => 'SUBTOTAL',
			'price' => $subtotal,
		);

		return $items;
	}

	/**
	 * Template function to return specific arguments in the expected format.
	 *
	 * @param array $args
	 * @return array
	 */
	public function display_items_template( $args ) {
		return array(
			'displayItems'     => $args['items'],
			'totalPrice'       => $args['amount'],
			'totalPriceLabel'  => esc_html__( 'Total', 'woocommerce-bluesnap-gateway' ),
			'totalPriceStatus' => strtoupper( $args['type'] ),
		);
	}

	/**
	 * Convert returned payload & add expected data.
	 *
	 * @param array $pr
	 * @return array
	 */
	protected function payment_request_convert( $pr ) {

		return array(
			'transactionInfo'     => $pr['order_data'],
			'billingAddressInfo'  => array(
				'billingAddressRequired'   => isset( $pr['billing_required'] ) && $pr['billing_required'],
				'billingAddressParameters' => array(
					'format'              => 'FULL',
					'phoneNumberRequired' => true,
				),
			),
			'shippingAddressInfo' => array(
				'shippingAddressRequired'   => isset( $pr['shipping_required'] ) && $pr['shipping_required'],
				'shippingAddressParameters' => array(
					'allowedCountryCodes' => array_keys( WC()->countries->get_allowed_countries() ),
					'phoneNumberRequired' => true,
				),
			),
		);
	}
}

return new WC_Bluesnap_Google_Pay();
