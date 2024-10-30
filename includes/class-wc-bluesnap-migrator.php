<?php
/**
 * @author   SAU/CAL
 * @category Class
 * @package  Woocommerce_Bluesnap_Gateway/Classes
 * @version  2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * BlueSnap Migrator
 *
 * Class WC_Bluesnap_Migrator
 */
class WC_Bluesnap_Migrator {

	/**
	 * Hook in methods.
	 */
	public function __construct() {
		add_action( 'current_screen', array( $this, 'maybe_migrate_old_order_data_backend' ) );
		add_action( 'woocommerce_scheduled_subscription_payment', array( $this, 'maybe_migrate_old_order_data' ), ~PHP_INT_MAX, 1 );
		add_action( 'admin_init', array( $this, 'maybe_migrate_old_plugin_settings' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'checkout_maybe_grab_stored_cc_api' ) );
		add_action( 'woocommerce_before_account_payment_methods', array( $this, 'checkout_maybe_grab_stored_cc_api' ) );
		add_action( 'woocommerce_view_order', array( $this, 'maybe_migrate_old_order_data' ), ~PHP_INT_MAX, 1 );
		add_action( 'woocommerce_account_view-subscription_endpoint', array( $this, 'maybe_migrate_old_order_data' ), 10, 1 );
		add_action( 'wc_bluesnap_maybe_migrate', array( $this, 'maybe_migrate_old_order_data' ), 10, 1 );
	}

	/**
	 * Copy old plugin's settings if new
	 * setting don't exist.
	 *
	 * @return void
	 */
	public function maybe_migrate_old_plugin_settings() {

		$db_version = WC_Bluesnap()->get_option( 'db_version' );

		if ( wp_doing_ajax() || wp_doing_cron() || ( $db_version && version_compare( WC_BLUESNAP_DB_VERSION, $db_version, '<=' ) ) ) {
			return;
		}

		$old_settings = get_option( 'woocommerce_wc_gateway_bluesnap_cc_settings' );

		if ( empty( $old_settings ) ) {
			return;
		}

		$new_settings = get_option( 'woocommerce_bluesnap_settings' );

		if ( isset( $new_settings['old_settings_migrated'] ) && $new_settings['old_settings_migrated'] ) {
			return;
		}

		$mapped_settings = array(
			'enabled'              => 'enabled',
			'testmode'             => 'environment',
			'title'                => 'title',
			'description'          => 'description',
			'ipn'                  => 'ipn',
			'api_username'         => 'api_login',
			'api_password'         => 'password',
			'merchant_id'          => 'merchant_id',
			'multicurrency'        => 'cs_status',
			'currencies_supported' => 'cs_currencies',
		);

		foreach ( $mapped_settings as $new_key => $old_key ) {
			if ( ! isset( $new_settings[ $new_key ] ) && isset( $old_settings[ $old_key ] ) ) {
				$new_settings[ $new_key ] = $old_settings[ $old_key ];
			}
		}

		$new_settings['old_settings_migrated'] = true;

		WC_Bluesnap()->update_option( 'db_version', WC_BLUESNAP_DB_VERSION );

		update_option( 'woocommerce_bluesnap_settings', $new_settings );
	}

	/**
	 * Migrate old order data
	 * When an Order (or subscription) is loaded in the backend.
	 */
	public function maybe_migrate_old_order_data_backend() {

		$order_id = $this->get_current_edit_order();

		if ( $order_id ) {
			$this->maybe_migrate_old_order_data( $order_id );
		}
	}


	/**
	 * Is order edit page?
	 *
	 * @return int|null
	 */
	public function get_current_edit_order() {
		if ( wp_doing_ajax() || wp_doing_cron() || ! is_admin() ) {
			return null;
		}

		$screen  = get_current_screen();

		if ( 'post' === $screen->base && ( 'shop_order' === $screen->post_type || 'shop_subscription' === $screen->post_type ) ) {
			$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $post_id;
		}

		if ( class_exists( 'CustomOrdersTableController' ) && wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() && $screen->base === wc_get_page_screen_id( 'shop-order' ) ) {
			$post_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $post_id;
		}

		return null;
	}


	/**
	 * Migrate old order data on demand
	 *
	 * @param int $order_id
	 * @return void
	 */
	public function maybe_migrate_old_order_data( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$payment_method = $order->get_payment_method();

		if ( ! empty( $payment_method ) && WC_BLUESNAP_GATEWAY_OLD_ID !== $payment_method ) {
			return;
		}

		$customer_id = $order->get_user_id();

		if ( is_a( $order, 'WC_Subscription' ) ) {

			$initial_order_id = $order->get_parent_id();

			$initial_order = wc_get_order( $initial_order_id );

			if ( ! $initial_order || WC_BLUESNAP_GATEWAY_OLD_ID !== $initial_order->get_meta( '_payment_method', true ) ) {
				// Initial subscription wasn't with old plugin either.
				return;
			}

			$order->set_payment_method( WC_BLUESNAP_GATEWAY_ID );

			foreach ( $order->get_related_orders() as $subscription_order_id ) {
				$subscription_order = wc_get_order( $subscription_order_id );
				
				if ( $subscription_order ) {
					continue;
				}

				if ( WC_BLUESNAP_GATEWAY_OLD_ID === $subscription_order->get_payment_method() ) {
					$subscription_order->set_payment_method( WC_BLUESNAP_GATEWAY_ID );
				}

				if ( $subscription_order_id === $initial_order_id && $subscription_order->get_meta( '_bluesnap_subscription_id', true ) ) {
					$order->update_meta_data( '_bluesnap_ondemand_subscription_id', $subscription_order->get_meta( '_bluesnap_subscription_id', true ) );
				}

				if ( $subscription_order->get_meta( '_bluesnap_invoice_id', true ) ) {
					$subscription_order->set_transaction_id( $subscription_order->get_meta( '_bluesnap_invoice_id', true ) );
				}

				if ( $subscription_order->get_meta( '_bluesnap_shopper_id', true ) ) {
					$this->handle_shopper_id( $customer_id, $subscription_order->get_meta( '_bluesnap_shopper_id', true ) );
				}

				$subscription_order->save();
			}
		} else {

			if ( WC_BLUESNAP_GATEWAY_OLD_ID !== $order->get_meta( '_payment_method', true ) ) {
				// Initial subscription wasn't with old plugin either.
				return;
			}

			$order->set_payment_method( WC_BLUESNAP_GATEWAY_ID );

			if ( $order->get_meta( '_bluesnap_invoice_id', true ) ) {
				$order->set_transaction_id( $order->get_meta( '_bluesnap_invoice_id', true ) );
			}

			if ( $order->get_meta( '_bluesnap_shopper_id', true ) ) {
				$this->handle_shopper_id( $customer_id, $order->get_meta( '_bluesnap_shopper_id', true ) );
			}
		}

		$order->save();
	}

	public function checkout_maybe_grab_stored_cc_api() {

		if ( ! is_user_logged_in() ) {
			return; // no point.
		}

		$customer_id = get_current_user_id();
		$user_meta   = get_user_meta( $customer_id );

		if ( isset( $user_meta['_bsnp_shopper_id'] ) && ! isset( $user_meta['_bluesnap_checked_stored_ccs'] ) ) {
			$this->handle_shopper_id( $customer_id, reset( $user_meta['_bsnp_shopper_id'] ) );
			update_user_meta( $customer_id, '_bluesnap_checked_stored_ccs', true );
		}
	}

	public function handle_shopper_id( $customer_id, $old_shopper_id ) {

		$shopper = new WC_Bluesnap_Shopper( $customer_id );
		WC_Bluesnap_Token::refresh_user_source_tokens_from_api( $customer_id, $old_shopper_id );

		if ( ! $shopper->get_bluesnap_shopper_id() ) {
			$shopper->set_bluesnap_shopper_id( $old_shopper_id );
		}
	}
}

new WC_Bluesnap_Migrator();
