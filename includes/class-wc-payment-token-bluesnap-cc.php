<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WooCommerce Credit Card Payment Token.
 *
 * Representation of a payment token for credit cards.
 *
 */
class WC_Payment_Token_Bluesnap_CC extends WC_Payment_Token_CC {

	/** @protected string Token Type String. */
	protected $type = 'Bluesnap_CC';

	/**
	 * Hook prefix
	 */
	protected function get_hook_prefix() {
		return 'woocommerce_payment_token_bluesnap_cc_get_';
	}

	public function delete( $force_delete = false ) {
		global $wp;
		if ( ! isset( $wp->query_vars['delete-payment-method'] ) ) {
			return parent::delete( $force_delete );
		}

		if ( absint( $wp->query_vars['delete-payment-method'] ) !== $this->get_id() ) {
			return parent::delete( $force_delete );
		}

		$shortcircuit = apply_filters( 'wc_gateway_bluesnap_delete_cc_from_my_account', null, $this, $force_delete );

		if ( ! is_null( $shortcircuit ) ) {
			wp_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
			exit();
			return false; // not deleted
		}

		return parent::delete( $force_delete );
	}
}
