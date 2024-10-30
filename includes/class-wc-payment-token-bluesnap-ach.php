<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * BlueSnap ACH Payment Token.
 *
 * Representation of a payment token for ACH/ECP.
 *
 */
class WC_Payment_Token_Bluesnap_ACH extends WC_Payment_Token_ECheck {

	/** @protected string Token Type String. */
	protected $type = 'Bluesnap_ACH';

	/**
	 * Stores ACH payment token data.
	 *
	 * @var array
	 */
	protected $extra_data = array(
		'account_type'          => '',
		'public_account_number' => '',
		'public_routing_number' => '',
	);

	/**
	 * Hook prefix
	 */
	protected function get_hook_prefix() {
		return 'woocommerce_payment_token_bluesnap_ach_get_';
	}

	public function get_account_type( $context = 'view' ) {
		return $this->get_prop( 'account_type', $context );
	}

	public function get_public_account_number( $context = 'view' ) {
		return $this->get_prop( 'public_account_number', $context );
	}

	public function get_public_routing_number( $context = 'view' ) {
		return $this->get_prop( 'public_routing_number', $context );
	}

	public function set_account_type( $account_type ) {
		$this->set_prop( 'account_type', $account_type );
	}

	public function set_public_account_number( $public_account_number ) {
		$this->set_prop( 'public_account_number', $public_account_number );
	}

	public function set_public_routing_number( $public_routing_number ) {
		$this->set_prop( 'public_routing_number', $public_routing_number );
	}

	/**
	 * Get type to display to user.
	 *
	 * @since  2.6.0
	 * @param  string $deprecated Deprecated since WooCommerce 3.0.
	 * @return string
	 */
	public function get_display_name( $deprecated = '' ) {
		$display = sprintf(
			/* translators: Public account number (last digits of Account number) */
			__( 'ACH/ECP with public account number %1$s', 'woocommerce' ),
			$this->get_public_account_number()
		);
		return $display;
	}

	/**
	 * Validate ACH payment tokens.
	 *
	 * These fields are required by all eCheck payment tokens:
	 *
	 * @since 2.6.0
	 * @return boolean True if the passed data is valid
	 */
	public function validate() {
		if ( ! $this->get_account_type( 'edit' ) ) {
			return false;
		}

		if ( ! $this->get_public_account_number( 'edit' ) ) {
			return false;
		}

		if ( ! $this->get_public_routing_number( 'edit' ) ) {
			return false;
		}

		return true;
	}

	public function delete( $force_delete = false ) {
		global $wp;
		if ( ! isset( $wp->query_vars['delete-payment-method'] ) ) {
			return parent::delete( $force_delete );
		}

		if ( absint( $wp->query_vars['delete-payment-method'] ) !== $this->get_id() ) {
			return parent::delete( $force_delete );
		}

		$shortcircuit = apply_filters( 'wc_gateway_bluesnap_delete_ach_from_my_account', null, $this, $force_delete );

		if ( ! is_null( $shortcircuit ) ) {
			wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
			exit();
		}

		return parent::delete( $force_delete );
	}
}
