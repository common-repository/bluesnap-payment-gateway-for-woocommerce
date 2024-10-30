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
 * BlueSnap Shopper Object
 * https://developers.bluesnap.com/v8976-JSON/docs/returning-shopper-tutorial
 *
 * Class WC_Bluesnap_Shopper
 * https://developers.bluesnap.com/v8976-JSON/docs/create-vaulted-shopper
 */
class WC_Bluesnap_Shopper {

	/**
	 * Bluesnap user meta Id.
	 */
	const BLUESNAP_SHOPPER_META_ID = '_bluesnap_shopper_id';

	/**
	 * @var WC_Customer
	 */
	private $wc_customer;

	/**
	 * @var string
	 */
	private static $shopper_id_cache = '';

	private $forced_shopper_id = null;

	/**
	 * WC_Bluesnap_Shopper constructor.
	 *
	 * @param null $wp_user_id
	 *
	 * @throws Exception
	 */
	public function __construct( $wp_user_id = null, $forced_shopper_id = false ) {
		$wp_user_id              = ( $wp_user_id ) ? $wp_user_id : get_current_user_id();
		$this->wc_customer       = new WC_Customer( $wp_user_id );
		$this->forced_shopper_id = $forced_shopper_id;
	}

	/**
	 * @param int $bluesnap_shopper_id
	 */
	public function set_bluesnap_shopper_id( $bluesnap_shopper_id ) {
		self::$shopper_id_cache = $bluesnap_shopper_id;
		if ( ! $this->wc_customer->get_id() ) {
			return;
		}
		$this->wc_customer->update_meta_data( self::BLUESNAP_SHOPPER_META_ID, $bluesnap_shopper_id );
		$this->wc_customer->save();
	}

	/**
	 * @return int
	 */
	public function get_bluesnap_shopper_id() {
		if ( ! $this->wc_customer->get_id() && ! $this->forced_shopper_id ) {
			return self::$shopper_id_cache;
		}

		return $this->forced_shopper_id ? $this->forced_shopper_id : $this->wc_customer->get_meta( self::BLUESNAP_SHOPPER_META_ID, true );
	}
}

