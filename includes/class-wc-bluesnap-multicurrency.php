<?php
/**
 * @author   SAU/CAL
 * @category Class
 * @package  Woocommerce_Bluesnap_Gateway/Classes
 * @version  1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Bluesnap_Multicurrency
 */
class WC_Bluesnap_Multicurrency {

	/**
	 * Cookie name.
	 */
	const COOKIE_CURRENCY_NAME = 'wc_bluesnap_currency';

	/**
	 * Transient rates
	 */
	const CURRENCY_RATES_TRANSIENT = 'wc_bluesnap_currency_rates';

	/**
	 * Subscription currency item data.
	 */
	const SUBSCRIPTION_CURRENCY_ITEM_DATA = 'wc_bluesnap_subscription_currency';

	/**
	 * @var string
	 */
	protected static $currency_selected;

	/**
	 * @var string
	 */
	public static $original_currency;

	/**
	 * @var array
	 */
	protected $conversion_rates_cache;

	protected static $currency_config;

	private static $instance;

	/**
	 * WC_Bluesnap_Multicurrency constructor.
	 */
	public function __construct() {
		self::$instance = $this;

		// Add multicurrency functionality if multicurrency is enabled and there is some currency selected.
		if ( 'yes' === WC_Bluesnap()->get_option( 'multicurrency' ) && ! empty( self::get_currencies_setting_selected() ) ) {
			// Adds shortcode and widget.
			add_action( 'widgets_init', array( $this, 'add_multicurrency_widget' ) );

			// Get all currency prices html on frontend to avoid caching issues
			add_filter( 'woocommerce_get_price_html', array( $this, 'enrich_woocommerce_get_price_html' ), 1, 2 );

			// WC Hooks to implement currency conversion
			$this->hook_convert_currency_prices();

			// Adapt WC Config accordingly on the fly
			add_filter( 'woocommerce_currency', array( $this, 'convert_currency_symbol' ) );
			add_filter( 'pre_option_woocommerce_currency_pos', array( $this, 'convert_currency_pos' ) );
			add_filter( 'wc_get_price_thousand_separator', array( $this, 'convert_thousand_sep' ) );
			add_filter( 'wc_get_price_decimal_separator', array( $this, 'convert_decimal_sep' ) );
			add_filter( 'wc_get_price_decimals', array( $this, 'convert_num_decimals' ) );

			// Get latest rates on woocommerce_check_cart_items.
			add_action( 'woocommerce_check_cart_items', array( $this, 'update_currency_rates_on_checkout' ) );

			// Get latest rates on woocommerce_checkout_update_order_review.
			add_action( 'woocommerce_checkout_update_order_review', array( $this, 'update_currency_rates_on_checkout' ) );

			// User Cookie handling.
			add_action( 'wc_ajax_bluesnap_set_multicurrency', array( $this, 'change_user_currency' ) );
			// Set original cookie without filter interference.
			add_action( 'init', array( $this, 'set_original_currency' ), -10 );

			add_filter( 'woocommerce_shipping_free_shipping_is_available', array( $this, 'free_shipping_is_available' ), 10, 3 );
			add_filter( 'woocommerce_shipping_legacy_free_shipping_is_available', array( $this, 'free_shipping_is_available' ), 10, 3 );

			// Currency change need to invalidate prices cache
			add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'modify_variation_prices_cache_hash' ), 10, 3 );

			// woocommerce_checkout_update_customer
			add_action( 'woocommerce_checkout_update_customer', array( $this, 'cust_save_currency_on_checkout' ), 10, 2 );
		}


		// Shortcode
		add_shortcode( 'bluesnap_multicurrency', array( $this, 'add_multicurrency_shortcode' ) );

		// admin
		add_filter( 'get_bluesnap_supported_currency_list', array( $this, 'get_bluesnap_supported_currency_list' ) );
		add_filter( 'wc_bluesnap_settings', array( $this, 'disable_multicurrency_field_if_not_enabled' ) );

		// Subscriptions specific.
		add_action( 'woocommerce_setup_cart_for_subscription_renewal', array( $this, 'set_cookie_on_subscription_renewal' ) );
		add_action( 'template_redirect', array( $this, 'maybe_set_cookie_on_switch' ) );
		add_action( 'woocommerce_subscriptions_switch_added_to_cart', array( $this, 'maybe_set_cookie_on_switch' ), 10, 1 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'add_renewal_currency_to_item_data' ), PHP_INT_MAX, 3 );

		// Admin hook, we get latest rates when getting settings actions
		add_action( 'wc_gateway_bluesnap_latest_currencies', array( $this, 'update_currency_rates' ) );
	}


	/**
	 * Hook methods responsible for the actual conversion.
	 */
	private function hook_convert_currency_prices() {
		add_filter( 'woocommerce_product_get_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		add_filter( 'woocommerce_product_sales_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		add_filter( 'woocommerce_shipping_rate_cost', array( $this, 'convert_shipping_rate_cost' ) );
		add_filter( 'woocommerce_get_shipping_tax', array( $this, 'convert_shipping_rate_cost' ), 1, 2 );
		add_filter( 'woocommerce_shipping_rate_taxes', array( $this, 'convert_shipping_rate_tax' ), 1, 2 );
		add_filter( 'woocommerce_subscriptions_product_sign_up_fee', array( $this, 'convert_currency_prices' ), 1, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		add_filter( 'woocommerce_product_variation_get__subscription_price', array( $this, 'convert_currency_prices' ), 1, 2 ); // needed in WC_Subscriptions_Product::get_price() for the get_meta_data call.
	}


	/**
	 * Unhook methods responsible for the actual conversion.
	 */
	private function unhook_convert_currency_prices() {
		remove_filter( 'woocommerce_product_get_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		remove_filter( 'woocommerce_product_get_regular_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		remove_filter( 'woocommerce_product_sales_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		remove_filter( 'woocommerce_shipping_rate_cost', array( $this, 'convert_shipping_rate_cost' ) );
		remove_filter( 'woocommerce_get_shipping_tax', array( $this, 'convert_shipping_rate_cost' ), 1, 2 );
		remove_filter( 'woocommerce_shipping_rate_taxes', array( $this, 'convert_shipping_rate_tax' ), 1, 2 );
		remove_filter( 'woocommerce_subscriptions_product_sign_up_fee', array( $this, 'convert_currency_prices' ), 1, 2 );
		remove_filter( 'woocommerce_variation_prices_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		remove_filter( 'woocommerce_variation_prices_regular_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		remove_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		remove_filter( 'woocommerce_product_variation_get_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		remove_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		remove_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'convert_currency_prices' ), 1, 2 );
		remove_filter( 'woocommerce_product_variation_get__subscription_price', array( $this, 'convert_currency_prices' ), 1, 2 ); // needed in WC_Subscriptions_Product::get_price() for the get_meta_data call.
	}

	/**
	 * Public access to instance object.
	 *
	 */
	public static function get_instance() {
		return self::$instance;
	}


	private static function get_currency_settings() {
		if ( isset( self::$currency_config ) ) {
			return self::$currency_config;
		}

		$currency_code = self::get_currency_user_selected();

		$locale_info     = include WC()->plugin_path() . '/i18n/locale-info.php';
		$currency_config = array();
		$default_data    = array(
			'currency_code' => $currency_code,
			'currency_pos'  => 'left',
			'decimal_sep'   => '.',
			'num_decimals'  => 2,
			'thousand_sep'  => ',',
		);

		foreach ( array( 'CLP', 'JPY', 'ISK', 'KRW', 'VND', 'XOF' ) as $no_dec_curr ) {
			$currency_config[ $no_dec_curr ]                 = $default_data;
			$currency_config[ $no_dec_curr ]['num_decimals'] = 0;
		}

		foreach ( $locale_info as $country => $data ) {
			$currency_config[ $data['currency_code'] ] = array_intersect_key(
				wp_parse_args(
					$data,
					$default_data
				),
				$default_data
			);
		}

		$currency_config = isset( $currency_config[ $currency_code ] ) ? $currency_config[ $currency_code ] : $default_data;

		self::$currency_config = $currency_config;

		return $currency_config;
	}


	/**
	 * When we are doing a switch, change to the currency of current subscription that is to be switched.
	 */
	public function maybe_set_cookie_on_switch( $subscription = null ) {

		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}

		if ( empty( $subscription ) && isset( $_GET['switch-subscription'] ) && isset( $_GET['item'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$subscription = wcs_get_subscription( absint( $_GET['switch-subscription'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( $subscription && $this->set_cookie_on_subscription_switch( $subscription ) ) {
			WC()->cart->calculate_totals();
		}

	}

	/**
	 * When a subscription is forced to Renew Now, we have to change cookie in case it is using another currency.
	 *
	 * @param WC_Subscription $subscription
	 * @param $cart_item_data
	 */
	public function set_cookie_on_subscription_renewal( $subscription ) {
		$subscription_renewal_currency = $subscription->get_currency();

		if ( self::$original_currency !== $subscription_renewal_currency ) {
			self::set_currency_user_selected( $subscription_renewal_currency, true );
		}
	}

	public function set_cookie_on_subscription_switch( $subscription ) {
		$subscription_switch_currency = $subscription->get_currency();

		if ( self::$currency_selected !== $subscription_switch_currency ) {
			self::set_currency_user_selected( $subscription_switch_currency, true );
			return true;
		}

		return false;
	}

	/**
	 * Multicurrency Selector Shortcode. Avalaible only when multicurrency is ready.
	 *
	 * @return string
	 */
	public function add_multicurrency_shortcode() {
		if ( 'yes' === WC_Bluesnap()->get_option( 'multicurrency' ) && ! empty( self::get_currencies_setting_selected() ) ) {
			return woocommerce_bluesnap_gateway_get_template_html(
				'multicurrency-selector.php',
				array(
					'options'           => self::get_currencies_setting_selected(),
					'original_currency' => self::$original_currency,
				)
			);
		}
		return '';
	}

	/**
	 * Registering multicurrency widget class.
	 */
	public function add_multicurrency_widget() {
		register_widget( 'WC_Bluesnap_Widget_Multicurrency' );
	}

	public static function nonce_field() {
		add_filter( 'nonce_user_logged_out', '__return_zero' );
		wp_nonce_field( 'bluesnap-multicurrency-nonce' );
		remove_filter( 'nonce_user_logged_out', '__return_zero' );
	}

	public static function verify_nonce() {
		add_filter( 'nonce_user_logged_out', '__return_zero' );
		$ret = wp_verify_nonce( $_REQUEST['_wpnonce'], 'bluesnap-multicurrency-nonce' );
		remove_filter( 'nonce_user_logged_out', '__return_zero' );
		return $ret;
	}

	/**
	 * Change cookie currency.
	 */
	public function change_user_currency() {

		if ( ! isset( $_REQUEST['action'] ) || 'bluesnap_multicurrency_action' !== $_REQUEST['action'] || ! self::verify_nonce() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( $this->allow_currency_change() ) {
			self::set_currency_user_selected( $_REQUEST['bluesnap_currency_selector'], true ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			WC()->cart->calculate_totals();
		} else {
			wc_add_notice( esc_html__( 'You cannot change currency while a subscription switch exists in your cart.', 'woocommerce-bluesnap-gateway' ), 'error' );
		}

		$referer = '';
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = wp_unslash( $_SERVER['HTTP_REFERER'] );
		}

		wp_safe_redirect( ! empty( $referer ) ? $referer : home_url() );
		exit;

	}


	/**
	 * Check any conditions required to allow switching currency.
	 */
	public function allow_currency_change() {
		if ( class_exists( 'WC_Subscriptions_Switcher' ) && WC_Subscriptions_Switcher::cart_contains_switches( 'any' ) ) {
			return false;
		}

		return true;
	}


	/**
	 * It return a specific rate or a full list of rates.
	 * If $conversion_rates_cache exists, it is an updated rate for checkout.
	 *
	 * @param null|string $currency
	 *
	 * @return array|float
	 */
	public function get_currency_rates( $currency = null ) {
		if ( isset( $this->conversion_rates_cache ) ) {
			$rates = $this->conversion_rates_cache;
		} elseif ( $rates = get_transient( self::CURRENCY_RATES_TRANSIENT ) ) { // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments, WordPress.CodeAnalysis.AssignmentInCondition.Found, Generic.CodeAnalysis.EmptyStatement
			// rates are coming from transient, and variable is set inside IF
		} else {
			$rates = $this->update_currency_rates();
		}

		if ( ! isset( $this->conversion_rates_cache ) ) {
			$this->conversion_rates_cache = $rates;
		}

		if ( $currency ) {
			if ( isset( $rates ) && is_array( $rates ) && isset( $rates[ $currency ] ) ) {
				return $rates[ $currency ];
			} else {
				return false;
			}
		} else {
			return $rates;
		}
	}

	/**
	 * Update currency rates into our rate transient.
	 * Updates each hour on demand, or forced when settings on the gateway changes.
	 *
	 * @param array|null $updated_rates updated rates injected.
	 *
	 * @return array
	 */
	public function update_currency_rates( $updated_rates = null ) {
		$conversions = array();
		try {
			if ( $updated_rates ) {
				$rates = $updated_rates;
			} else {
				$rates = WC_Bluesnap_API::retrieve_conversion_rate( self::$original_currency );
			}

			foreach ( $rates['currencyRate'] as $currency ) {
				$conversions[ $currency['quoteCurrency'] ] = $currency['conversionRate'];
			}
			// Add base rate 1 to 1 to avoid extra checks.
			$conversions[ $rates['baseCurrency'] ] = 1;
			set_transient( self::CURRENCY_RATES_TRANSIENT, $conversions, HOUR_IN_SECONDS );
		} catch ( WC_Bluesnap_API_Exception $e ) {
			WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
			return false;
		}
		return $conversions;
	}

	private $should_convert_flag = false;

	private $contains_renewal_curr = false;

	private function contains_renewal() {
		if ( $this->contains_renewal_curr ) {
			return $this->contains_renewal_curr;
		}

		if ( ! WC()->session || ! WC()->session->cart ) {
			return false;
		}

		foreach ( WC()->session->cart as $key => $item ) {
			if ( isset( $item['subscription_renewal'] ) && isset( $item['subscription_renewal'][ self::SUBSCRIPTION_CURRENCY_ITEM_DATA ] ) ) {
				$this->contains_renewal_curr = $item['subscription_renewal'][ self::SUBSCRIPTION_CURRENCY_ITEM_DATA ];
				return $this->contains_renewal_curr;
			}
		}

		return false;
	}

	private function _should_convert( $type = null ) {
		if ( is_bool( $this->get_currency_rates() ) ) {
			return false;
		}

		if ( is_admin() ) {
			return false;
		}

		if ( doing_action( 'woocommerce_variable_product_sync_data' ) ) {
			return false;
		}

		$renewal_curr = $this->contains_renewal();
		if ( $renewal_curr ) {
			self::$currency_selected = $renewal_curr;
			switch ( $type ) {
				case 'currency_prices':
				case 'shipping_rate_cost':
				case 'enrich_html':
					return false;
				default:
					return true;
			}
		}

		return true;
	}

	private function should_convert( $type = null ) {
		if ( $this->should_convert_flag ) {
			return false;
		}

		$this->should_convert_flag = true;
		$ret                       = $this->_should_convert( $type );
		$this->should_convert_flag = false;
		return $ret;
	}

	/**
	 * Hooks to change currency. Only on the frontend. Dashboard is left intact.
	 * @param $currency
	 *
	 * @return string
	 */
	public function convert_currency_symbol( $val ) {
		if ( ! $this->should_convert( 'currency_symbol' ) ) {
			return $val;
		}
		$currency_settings = self::get_currency_settings();
		return $currency_settings['currency_code'];
	}

	public function convert_currency_pos( $val ) {
		if ( ! $this->should_convert( 'currency_pos' ) ) {
			return $val;
		}
		$currency_settings = self::get_currency_settings();
		return $currency_settings['currency_pos'];
	}

	public function convert_thousand_sep( $val ) {
		if ( ! $this->should_convert( 'thousand_sep' ) ) {
			return $val;
		}
		$currency_settings = self::get_currency_settings();
		return $currency_settings['thousand_sep'];
	}

	public function convert_decimal_sep( $val ) {
		if ( ! $this->should_convert( 'decimal_sep' ) ) {
			return $val;
		}
		$currency_settings = self::get_currency_settings();
		return $currency_settings['decimal_sep'];
	}

	public function convert_num_decimals( $val ) {
		if ( ! $this->should_convert( 'num_decimals' ) ) {
			return $val;
		}
		$currency_settings = self::get_currency_settings();
		return $currency_settings['num_decimals'];
	}

	/**
	 * It converts prices, it always takes original prices from data.
	 * This way we avoid unwanted prices converted already from subscriptions (Renew Now) for instance.
	 *
	 * @param string $price
	 * @param WC_Product_Subscription $product
	 *
	 * @return float
	 */
	public function convert_currency_prices( $price, $product ) {
		if ( ! $this->should_convert( 'currency_prices' ) ) {
			return $price;
		}

		$product_data = $product->get_data();

		switch ( current_filter() ) {
			case 'woocommerce_product_get_price':
			case 'woocommerce_variation_prices_price':
			case 'woocommerce_product_variation_get_price':
			case 'woocommerce_product_variation_get__subscription_price':
				$original_price = isset( $product_data['price'] ) ? $product_data['price'] : $price;
				break;
			case 'woocommerce_product_get_regular_price':
			case 'woocommerce_variation_prices_regular_price':
			case 'woocommerce_product_variation_get_regular_price':
				$original_price = isset( $product_data['regular_price'] ) ? $product_data['regular_price'] : $price;
				break;
			case 'woocommerce_product_sales_price':
			case 'woocommerce_variation_prices_sale_price':
			case 'woocommerce_product_variation_get_sale_price':
				$original_price = isset( $product_data['sale_price'] ) ? $product_data['sale_price'] : $price;
				break;
			case 'woocommerce_subscriptions_product_sign_up_fee':
				static $original_signup_fee;

				$this->unhook_convert_currency_prices();

				// phpcs:disable WordPress.Security.NonceVerification.Recommended
				if ( is_null( $original_signup_fee ) &&
					isset( $_REQUEST['switch-subscription'] ) &&
					(
						( isset( $_REQUEST['product_id'] ) && ( $product->get_id() === absint( $_REQUEST['product_id'] ) ) ) ||
						( isset( $_REQUEST['variation_id'] ) && ( $product->get_id() === absint( $_REQUEST['variation_id'] ) ) )
					)
				) {
					$original_signup_fee[ $product->get_id() ] = WC_Subscriptions_Product::get_sign_up_fee( $product );
				}
				// phpcs:enable WordPress.Security.NonceVerification.Recommended

				$original_price = ! is_null( $original_signup_fee ) && isset( $original_signup_fee[ $product->get_id() ] ) ? $original_signup_fee[ $product->get_id() ] : WC_Subscriptions_Product::get_sign_up_fee( $product );

				$this->hook_convert_currency_prices();

				if ( $product->meta_exists( '_subscription_sign_up_fee_prorated' ) ) {
					return $price; // No conversion needed at this point.
				}

				break;
			default:
				$original_price = $price;
				break;
		}

		return '' !== $original_price ? $this->conversion_price_to( $original_price, self::get_currency_user_selected() ) : '';
	}

	public function convert_shipping_rate_cost( $cost ) {
		if ( ! $this->should_convert( 'shipping_rate_cost' ) ) {
			return $cost;
		}

		return $this->conversion_price_to( $cost, self::get_currency_user_selected() );
	}

	/**
	 * Convert the shipping rate taxes
	 */
	public function convert_shipping_rate_tax( $taxes ) {
		if ( ! $this->should_convert( 'shipping_rate_cost' ) ) {
			return $taxes;
		}

		foreach ( $taxes as $key => $tax ) {
			$taxes[ $key ] = $this->conversion_price_to( $tax, self::get_currency_user_selected() );
		}

		return $taxes;
	}

	public function free_shipping_is_available( $is_available, $package, $instance ) {
		$current_filter = current_filter();
		remove_filter( $current_filter, array( $this, 'free_shipping_is_available' ), 10 );
		$old_min              = $instance->min_amount;
		$instance->min_amount = $this->conversion_price_to( $old_min, self::get_currency_user_selected() );
		$is_available         = $instance->is_available( $package );
		add_filter( $current_filter, array( $this, 'free_shipping_is_available' ), 10, 3 );
		$instance->min_amount = $old_min;
		return $is_available;
	}

	/**
	 * As there may be some cache on the site, we need to inject all prices converted on the frontend, to be hadled by js
	 * in case is needed.
	 *
	 * @param string $price
	 * @param WC_Product $product
	 *
	 * @return string
	 */
	public function enrich_woocommerce_get_price_html( $price, $product ) {
		if ( ! $this->should_convert( 'enrich_html' ) ) {
			return $price;
		}

		$selected_price = $this->wrap_currency_html( self::get_currency_user_selected(), $price );

		$product_data           = $product->get_data();
		$original_regular_price = $product_data['regular_price'];
		$original_sale_price    = $product_data['sale_price'];
		$original_price         = $product_data['price'];

		// Get currencies we need to add to html, add the original one and remove the selected one.
		$currencies_selected = self::get_currencies_setting_selected();
		array_push( $currencies_selected, self::$original_currency );
		$currencies_needed = array_diff( $currencies_selected, array( self::get_currency_user_selected() ) );

		foreach ( $currencies_needed as $currency ) {
			$converted_regular_price = $this->conversion_price_to( $original_regular_price, $currency );
			$converted_sale_price    = $this->conversion_price_to( $original_sale_price, $currency );
			$converted_price_price   = $this->conversion_price_to( $original_price, $currency );

			if ( '' === $product->get_price() ) {
				$price = apply_filters( 'woocommerce_empty_price_html', '', $product );
			} elseif ( $product->is_on_sale() ) {
				$price = wc_format_sale_price(
					wc_get_price_to_display( $product, array( 'price' => $converted_regular_price ) ),
					wc_get_price_to_display( $product, array( 'price' => $converted_sale_price ) )
				) . $product->get_price_suffix();

				// Unfortunately is not possible to inject currency into wc_format_sale_price as it is possible on wc_price
				$pattern     = '#<span class="woocommerce-Price-currencySymbol">(.+?)</span>#';
				$replacement = '<span class="woocommerce-Price-currencySymbol">' . get_woocommerce_currency_symbol( $currency ) . '</span>';
				$price       = preg_replace( $pattern, $replacement, $price );
			} else {
				$price = wc_price(
					wc_get_price_to_display( $product, array( 'price' => $converted_price_price ) ),
					array( 'currency' => $currency )
				) . $product->get_price_suffix();
			}
			$selected_price .= $this->wrap_currency_html( $currency, $price, true );
		}

		return $selected_price;
	}

	/**
	 * Html wrapper for frontend output.
	 *
	 * @param $currency
	 * @param $html_price
	 * @param bool $hide
	 *
	 * @return string
	 */
	private function wrap_currency_html( $currency, $html_price, $hide = false ) {
		return woocommerce_bluesnap_gateway_get_template_html(
			'multicurrency-wrapper.php',
			array(
				'currency' => $currency,
				'html'     => $html_price,
				'hide'     => $hide,
			)
		);
	}

	/**
	 * @param $name
	 * @param $value
	 * @param int $duration
	 * @param string $path
	 *
	 * @return bool
	 */
	public static function set_cookie( $name, $value, $duration = 0, $path = '/' ) {
		$_COOKIE[ $name ] = $value;
		return setcookie( $name, $value, $duration, $path );
	}


	/**
	 * Save selected currency to user meta for logged in users.
	 */
	public static function save_to_user( $currency ) {
		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), '_bluesnap_wc_currency_' . get_current_blog_id(), $currency );
		}
	}

	/**
	 * @param $price
	 * @param $to
	 *
	 * @return float
	 */
	protected function conversion_price_to( $price, $to ) {
		return (float) $price * $this->get_currency_rates( $to );
	}

	/**
	 * On checkout, update rates and save it on internal cache.
	 */
	public function update_currency_rates_on_checkout() {
		$this->conversion_rates_cache = $this->update_currency_rates();
	}

	/**
	 * Retrieves list of selected currencies selected on the plugin settings.
	 *
	 * @return array
	 */
	public static function get_currencies_setting_selected() {
		$currencies = WC_Bluesnap()->get_option( 'currencies_supported' );
		return $currencies ? $currencies : array();
	}

	public static function get_currencies_allowed() {

		if ( isset( $_GET['switch-subscription'] ) && function_exists( 'wcs_get_subscription' ) ) {
			$subscription = wcs_get_subscription( absint( $_GET['switch-subscription'] ) );

			if ( $subscription ) {
				return array( $subscription->get_currency() );
			}
		}

		return 'all';
	}

	/**
	 * Get original currency without our filter.
	 */
	public function set_original_currency() {
		remove_filter( 'woocommerce_currency', array( $this, 'convert_currency_symbol' ) );
		self::$original_currency = get_woocommerce_currency();
		add_filter( 'woocommerce_currency', array( $this, 'convert_currency_symbol' ) );
	}

	private static function set_currency_user_selected( $selected, $save = false ) {

		self::$currency_selected = $selected;
		$available               = self::get_currencies_setting_selected();
		array_push( $available, self::$original_currency );

		if ( false === array_search( self::$currency_selected, $available, true ) ) {
			self::$currency_selected = self::$original_currency;
			$save                    = true;
		}

		if ( $save ) {
			self::set_cookie( self::COOKIE_CURRENCY_NAME, self::$currency_selected );
			self::save_to_user( self::$currency_selected );
		}

		self::$currency_config = null;
	}

	/**
	 * @return string
	 */
	public static function get_currency_user_selected() {
		if ( isset( self::$currency_selected ) ) {

			return self::$currency_selected;

		} elseif ( self::get_user_saved_currency() ) {

			$save = isset( $_COOKIE[ self::COOKIE_CURRENCY_NAME ] ) && ( self::get_user_saved_currency() !== $_COOKIE[ self::COOKIE_CURRENCY_NAME ] );
			self::set_currency_user_selected( self::get_user_saved_currency(), $save );

		} elseif ( isset( $_COOKIE[ self::COOKIE_CURRENCY_NAME ] ) ) {

			self::set_currency_user_selected( $_COOKIE[ self::COOKIE_CURRENCY_NAME ] );

		} else {

			self::set_currency_user_selected( self::$original_currency );

		}

		return self::$currency_selected;
	}


	/**
	 * Get the saved currency for the logged in user.
	 * Or false if not exists
	 *
	 * @return mixed
	 */
	private static function get_user_saved_currency() {

		static $currency;

		if ( is_null( $currency ) && is_user_logged_in() ) {
			$currency = get_user_meta( get_current_user_id(), '_bluesnap_wc_currency_' . get_current_blog_id(), true );
		}

		return ! empty( $currency ) ? $currency : false;
	}

	/**
	 * Remove default currency from currency list for admin settings.
	 * @param $supported_currencies
	 *
	 * @return array
	 */
	public function remove_default_currency( $supported_currencies ) {
		return array_diff( $supported_currencies, array( self::$original_currency ) );
	}

	protected function is_multicurrency_enabled() {
		return in_array( WC_Bluesnap()->get_option( 'multicurrency' ), array( 'yes', null ), true );
	}

	/**
	 * Getting updated currency list supported by Bluesnap. (only when API credentials are set).
	 *
	 * @return array
	 */
	public function get_bluesnap_supported_currency_list() {
		$conversions = array();

		if ( empty( WC_Bluesnap()->get_option( 'api_username' ) || empty( WC_Bluesnap()->get_option( 'api_password' ) ) ) ) {
			return $conversions;
		}

		if ( ! $this->is_multicurrency_enabled() ) {
			return $conversions;
		}

		try {
			$rates = WC_Bluesnap_API::retrieve_conversion_rate( get_woocommerce_currency() );
			do_action( 'wc_gateway_bluesnap_latest_currencies', $rates );
			foreach ( $rates['currencyRate'] as $currency ) {
				$conversions[ $currency['quoteCurrency'] ] = $currency['quoteCurrency'];
			}
		} catch ( WC_Bluesnap_API_Exception $e ) {
			WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
		}
		return $conversions;
	}

	public function disable_multicurrency_field_if_not_enabled( $fields ) {
		if ( ! $this->is_multicurrency_enabled() ) {
			unset( $fields['currencies_supported'] );
		}
		return $fields;
	}

	public function modify_variation_prices_cache_hash( $hash_array, $product, $for_display ) {

		$currency = self::get_currency_user_selected();

		if ( ! empty( $currency ) ) {
			$hash_array[] = self::get_currency_user_selected();
			$hash_array[] = $for_display;
		}

		return $hash_array;
	}


	/**
	 * Save the currency used to customer meta.
	 */
	public function cust_save_currency_on_checkout( $customer, $data ) {

		$currency = self::get_currency_user_selected();

		if ( $currency ) {
			self::set_currency_user_selected( $currency, true );
		}
	}


	/**
	 * Add the renewal currency to the cart item data so that it can be used to calculate the renewal price.
	 *
	 * @param array             $cart_item    Cart item data.
	 * @param WC_Order_Item     $line_item    Line item.
	 * @param WC_Abstract_Order $subscription Subscription order data.
	 *
	 * @return array
	 */
	public function add_renewal_currency_to_item_data( $cart_item, $line_item, $subscription ) {
		if ( ! empty( $cart_item['subscription_renewal'] ) ) {
			$cart_item['subscription_renewal'][ self::SUBSCRIPTION_CURRENCY_ITEM_DATA ] = $subscription->get_currency();
		}
		return $cart_item;
	}

}

new WC_Bluesnap_Multicurrency();
