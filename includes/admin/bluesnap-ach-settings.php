<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return apply_filters(
	'wc_bluesnap_ach_settings',
	array(
		'enabled'     => array(
			'title'       => __( 'Enable/Disable', 'woocommerce-bluesnap-gateway' ),
			'label'       => __( 'Enable BlueSnap ACH/ECP', 'woocommerce-bluesnap-gateway' ),
			'type'        => 'checkbox',
			'description' => '',
			'default'     => 'no',
		),
		'title'       => array(
			'title'       => __( 'Title', 'woocommerce-bluesnap-gateway' ),
			'type'        => 'text',
			'description' => __( 'Type here the name that you want the user to see', 'woocommerce-bluesnap-gateway' ),
			'default'     => __( 'ACH/ECP Transactions', 'woocommerce-bluesnap-gateway' ),
			'desc_tip'    => true,
		),
		'description' => array(
			'title'       => __( 'Description', 'woocommerce-bluesnap-gateway' ),
			'type'        => 'text',
			'description' => __( 'Enter your payment method description here (optional)', 'woocommerce-bluesnap-gateway' ),
			'default'     => __( 'Pay using an ACH/ECP Transaction', 'woocommerce-bluesnap-gateway' ),
			'desc_tip'    => true,
		),
	)
);
