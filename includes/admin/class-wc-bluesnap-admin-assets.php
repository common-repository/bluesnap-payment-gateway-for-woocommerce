<?php
/**
 * Load assets
 *
 * @author      SAU/CAL
 * @category    Admin
 * @package     WC_Bluesnap/Admin
 * @version     1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WC_Bluesnap()->plugin_path() . '/includes/class-wc-bluesnap-assets.php';

/**
 * WC_Bluesnap_Admin_Assets Class.
 */
class WC_Bluesnap_Admin_Assets extends WC_Bluesnap_Assets {

	/**
	 * Hook in methods.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'load_scripts' ) );
		add_action( 'admin_print_scripts', array( $this, 'localize_printed_scripts' ), 5 );
		add_action( 'admin_print_footer_scripts', array( $this, 'localize_printed_scripts' ), 5 );
	}

	/**
	 * Get styles for the frontend.
	 * @access private
	 * @return array
	 */
	public function get_styles() {
		return apply_filters(
			'woocommerce_bluesnap_gateway_enqueue_admin_styles',
			array(
				'woocommerce-bluesnap-gateway-admin' => array(
					'src' => $this->localize_asset( 'css/admin/woocommerce-bluesnap-gateway-admin.css' ),
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
			'woocommerce_bluesnap_gateway_enqueue_admin_scripts',
			array(
				'woocommerce-bluesnap-gateway-admin' => array(
					'src'  => $this->localize_asset( 'js/admin/woocommerce-bluesnap-gateway-admin.js' ),
					'data' => array(
						'ajax_url' => WC_Bluesnap()->ajax_url(),
						'nonces'   => array(
							'dismiss_admin_notice'  => wp_create_nonce( 'bluesnap-dismiss-admin-notice' ),
							'dismiss_prompt_review' => wp_create_nonce( 'bluesnap-dismiss-prompt-review' ),
						),
					),
				),
			)
		);
	}

}

return new WC_Bluesnap_Admin_Assets();
