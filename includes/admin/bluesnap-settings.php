<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return apply_filters(
	'wc_bluesnap_settings',
	array(
		'activation'             => array(
			'description' => __( 'Get your credentials from <a href="https://bluesnap.com" target="_blank">here</a>', 'woocommerce-bluesnap-gateway' ),
			'type'        => 'title',
		),
		'enabled'                => array(
			'title'       => __( 'Enable/Disable', 'woocommerce-bluesnap-gateway' ),
			'label'       => __( 'Enable BlueSnap', 'woocommerce-bluesnap-gateway' ),
			'type'        => 'checkbox',
			'description' => '',
			'default'     => 'no',
		),
		'testmode'               => array(
			'title'       => __( 'Test mode', 'woocommerce-bluesnap-gateway' ),
			'label'       => __( 'Enable Test Mode', 'woocommerce-bluesnap-gateway' ),
			'type'        => 'checkbox',
			'description' => __( 'Place the payment gateway in test mode using test API keys.', 'woocommerce-bluesnap-gateway' ),
			'default'     => 'yes',
			'desc_tip'    => true,
		),
		'ipn'                    => array(
			'title'       => __( 'IPN configuration', 'woocommerce-bluesnap-gateway' ),
			'type'        => 'title',
			'description' => __( 'You must add the following webhook endpoint to your Bluesnap account <a href="https://support.bluesnap.com/docs/ipn-setup" target="_blank">here</a>: ', 'woocommerce-bluesnap-gateway' ) . add_query_arg( 'wc-api', 'bluesnap', trailingslashit( get_home_url() ) ),
		),
		'title'                  => array(
			'title'       => __( 'Title', 'woocommerce-bluesnap-gateway' ),
			'type'        => 'text',
			'description' => __( 'Type here the name that you want the user to see', 'woocommerce-bluesnap-gateway' ),
			'default'     => __( 'Credit/Debit Card', 'woocommerce-bluesnap-gateway' ),
			'desc_tip'    => true,
		),
		'description'            => array(
			'title'       => __( 'Description', 'woocommerce-bluesnap-gateway' ),
			'type'        => 'text',
			'description' => __( 'Enter your payment method description here (optional)', 'woocommerce-bluesnap-gateway' ),
			'default'     => __( 'Pay using your Credit/Debit Card', 'woocommerce-bluesnap-gateway' ),
			'desc_tip'    => true,
		),
		'api_username'           => array(
			'title'    => __( 'API User Key', 'woocommerce-bluesnap-gateway' ),
			'type'     => 'text',
			'desc_tip' => __( 'API User Key provided by BlueSnap.', 'woocommerce-bluesnap-gateway' ),
			'default'  => '',
		),
		'api_password'           => array(
			'title'    => __( 'API Password', 'woocommerce-bluesnap-gateway' ),
			'type'     => 'password',
			'desc_tip' => __( 'API Password provided by BlueSnap.', 'woocommerce-bluesnap-gateway' ),
			'default'  => '',
		),
		'merchant_id'            => array(
			'title'    => __( 'Merchant Id', 'woocommerce-bluesnap-gateway' ),
			'type'     => 'text',
			'desc_tip' => __( 'Merchant id from your Bluesnap account.', 'woocommerce-bluesnap-gateway' ),
			'default'  => '',
		),
		'soft_descriptor'        => array(
			'title'       => __( 'Soft Descriptor', 'woocommerce-bluesnap-gateway' ) . ' <span class="required" title="Required">*</span>',
			'type'        => 'text',
			'description' => __( 'This text will be displayed in your customers Credit Card statement.', 'woocommerce-bluesnap-gateway' ),
			'default'     => '',
			'desc_tip'    => true,
		),
		'capture_charge'         => array(
			'title'       => __( 'Capture', 'woocommerce-bluesnap-gateway' ),
			'type'        => 'checkbox',
			'description' => __( 'Set to Capture charge immediately. This setting has no effect subscriptions. Charges on orders related to subscriptions are always captured.', 'woocommerce-bluesnap-gateway' ),
			'default'     => 'yes',
			'desc_tip'    => __( 'Whether or not to immediately capture the charge. When unchecked, the charge issues an authorization and will need to be captured later.', 'woocommerce-bluesnap-gateway' ),
		),
		'_3D_secure'             => array(
			'title'       => __( '3D Secure', 'woocommerce-bluesnap-gateway' ),
			'type'        => 'checkbox',
			/* translators: %1$s URL to Bluesnap Support */
			'description' => sprintf( __( 'Contact <a href="%1$s">Merchant Support</a> and ask for 3D Secure to be enabled for your account before you activate it here.', 'woocommerce-bluesnap-gateway' ), 'https://bluesnap.zendesk.com/hc/en-us/requests/new?ticket_form_id=360000127087' ),
			'default'     => '',
			'desc_tip'    => false,
		),
		'saved_cards'            => array(
			'title'    => __( 'Saved Cards', 'woocommerce-bluesnap-gateway' ),
			'type'     => 'checkbox',
			'desc_tip' => __( 'Allow users to pay with saved cards on checkout. (Saved on BlueSnap server).', 'woocommerce-bluesnap-gateway' ),
			'default'  => '',
		),
		'multicurrency'          => array(
			'title'    => __( 'BlueSnap currency converter', 'woocommerce-bluesnap-gateway' ),
			'type'     => 'checkbox',
			'desc_tip' => __( 'Enable BlueSnap currency converter.', 'woocommerce-bluesnap-gateway' ),
			'default'  => 'yes',
		),
		'currencies_supported'   => array(
			'title'             => __( 'Select currencies to display in your shop', 'woocommerce-bluesnap-gateway' ),
			'type'              => 'multiselect',
			'options'           => get_bluesnap_supported_currency_list(),
			'css'               => 'height: auto;',
			'custom_attributes' => array(
				'size' => 10,
				'name' => 'currency_switcher_currencies',
			),
		),
		'google_pay'             => array(
			'title'       => __( 'Google Pay Wallet', 'woocommerce-bluesnap-gateway' ),
			/* translators: %1$s URL to Bluesnap Support */
			'description' => sprintf( __( 'Contact <a href="%1$s">Merchant Support</a> and ask for Google Pay Wallet to be enabled for your account before you activate it here.', 'woocommerce-bluesnap-gateway' ), 'https://bluesnap.zendesk.com/hc/en-us/requests/new?ticket_form_id=360000127087' ),
			'type'        => 'checkbox',
			'desc_tip'    => __( 'Allow users to pay with Google Pay.', 'woocommerce-bluesnap-gateway' ),
			'default'     => '',
		),
		'google_pay_merchant_id' => array(
			'title'       => __( 'Google Merchant ID', 'woocommerce-bluesnap-gateway' ),
			'type'        => 'text',
			'description' => sprintf( __( 'Request for Google Pay production access <a href="%1$s" target="_blank">here</a>. Google Pay won\'t be available in production mode until you enter the merchant ID.', 'woocommerce-bluesnap-gateway' ), 'https://pay.google.com/business/console/' ),
			'default'     => '',
		),
		'apple_pay'              => array(
			'title'       => __( 'Apple Pay Wallet', 'woocommerce-bluesnap-gateway' ),
			/* translators: %1$s URL to Bluesnap Support */
			'description' => sprintf( __( 'Contact <a href="%1$s">Merchant Support</a> and ask for Apple Pay Wallet to be enabled for your account before you activate it here.', 'woocommerce-bluesnap-gateway' ), 'https://bluesnap.zendesk.com/hc/en-us/requests/new?ticket_form_id=360000127087' ),
			'type'        => 'checkbox',
			'desc_tip'    => __( 'Allow users to pay with Apple pay.', 'woocommerce-bluesnap-gateway' ),
			'default'     => '',
		),
		'logging'                => array(
			'title'       => __( 'Logging', 'woocommerce-bluesnap-gateway' ),
			'label'       => __( 'Log debug messages', 'woocommerce-bluesnap-gateway' ),
			'type'        => 'checkbox',
			'description' => __( 'Save debug messages to the WooCommerce System Status log.', 'woocommerce-bluesnap-gateway' ),
			'default'     => 'no',
			'desc_tip'    => true,
		),
	)
);
