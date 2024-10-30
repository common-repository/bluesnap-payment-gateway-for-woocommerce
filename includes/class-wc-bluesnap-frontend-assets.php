<?php
/**
 * Handle frontend scripts
 *
 * @class       WC_Bluesnap_Frontend_Scripts
 * @version     1.3.3
 * @package     Woocommerce_Bluesnap_Gateway/Classes/
 * @category    Class
 * @author      SAU/CAL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WC_Bluesnap()->plugin_path() . '/includes/class-wc-bluesnap-assets.php';

/**
 * WC_Bluesnap_Frontend_Scripts Class.
 */
class WC_Bluesnap_Frontend_Assets extends WC_Bluesnap_Assets {

	/**
	 * Multicurrency enabled.
	 *
	 * @var bool
	 */
	private $multicurrency = false;

	private $payment_request = false;

	/**
	 * Hook in methods.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'load_scripts' ) );
		add_action( 'wp_print_scripts', array( $this, 'localize_printed_scripts' ), 5 );
		add_action( 'wp_print_footer_scripts', array( $this, 'localize_printed_scripts' ), 5 );
		add_filter( 'woocommerce_bluesnap_gateway_enqueue_scripts', array( $this, 'add_multicurrency_js' ) );

		$this->multicurrency   = ( ! empty( WC_Bluesnap()->get_option( 'multicurrency' ) && 'yes' === WC_Bluesnap()->get_option( 'multicurrency' ) ) ) ? true : false;
		$apple_pay             = ( ! empty( WC_Bluesnap()->get_option( 'apple_pay' ) && 'yes' === WC_Bluesnap()->get_option( 'apple_pay' ) ) ) ? true : false;
		$google_pay            = ( ! empty( WC_Bluesnap()->get_option( 'google_pay' ) && 'yes' === WC_Bluesnap()->get_option( 'google_pay' ) ) ) ? true : false;
		$this->payment_request = $apple_pay || $google_pay;

		add_filter( 'woocommerce_bluesnap_gateway_enqueue_styles', array( $this, 'maybe_remove_unneeded_css' ), 100, 2 );
		add_filter( 'woocommerce_bluesnap_gateway_enqueue_scripts', array( $this, 'maybe_remove_unneeded_js' ), 100, 2 );
	}

	/**
	 * Get styles for the frontend.
	 * @access private
	 * @return array
	 */
	public function get_styles() {
		return apply_filters(
			'woocommerce_bluesnap_gateway_enqueue_styles',
			array(
				'woocommerce-bluesnap-gateway-general' => array(
					'src' => $this->localize_asset( 'css/frontend/woocommerce-bluesnap-gateway.css' ),
				),
			)
		);
	}

	/**
	 * Get styles for the frontend.
	 * @access private
	 * @return array
	 */
	public function get_scripts() {
		return apply_filters(
			'woocommerce_bluesnap_gateway_enqueue_scripts',
			array(
				'woocommerce-bluesnap-gateway-general' => array(
					'enqueue' => false,
					'src'     => $this->localize_asset( 'js/frontend/woocommerce-bluesnap-gateway.js' ),
					'data'    => array(
						'ajax_url' => WC_Bluesnap()->ajax_url(),
					),
				),
			),
			$this
		);
	}

	/**
	 * Enqueue multicurrency js only if it is active.
	 * @param $args
	 *
	 * @return array
	 */
	public function add_multicurrency_js( $args ) {
		if ( 'yes' === WC_Bluesnap()->get_option( 'multicurrency' ) ) {
			$args['woocommerce-bluesnap-multicurrency'] = array(
				'src'  => $this->localize_asset( 'js/frontend/woocommerce-bluesnap-multicurrency.js' ),
				'data' => array(
					'ajax_url'    => WC_Bluesnap()->ajax_url(),
					'cookie_name' => WC_Bluesnap_Multicurrency::COOKIE_CURRENCY_NAME,
				),
			);
		}
		return $args;
	}


	public function maybe_remove_unneeded_css( $args ) {
		if ( ! $this->multicurrency && ! $this->payment_request && ! is_checkout() && ! is_add_payment_method_page() && ! is_checkout_pay_page() ) {
			unset( $args['woocommerce-bluesnap-gateway-general'] );
		}

		return $args;
	}

	public function maybe_remove_unneeded_js( $args ) {
		if ( ! $this->multicurrency && ! $this->payment_request && ! is_checkout() && ! is_add_payment_method_page() && ! is_checkout_pay_page() ) {
			unset( $args['woocommerce-bluesnap-gateway-general'] );
		}

		return $args;
	}

}

new WC_Bluesnap_Frontend_Assets();
