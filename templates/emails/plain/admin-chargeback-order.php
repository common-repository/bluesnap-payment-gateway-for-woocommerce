<?php
/**
 * Admin failed order email (plain text)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/admin-failed-order.php
 *
 * @link       https://saucal.com
 * @since      1.0.0
 *
 * @package    Woocommerce_Bluesnap_Gateway
 * @subpackage Woocommerce_Bluesnap_Gateway/public/partials/emails/plain
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '= ' . $email_heading . " =\n\n"; // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped

/* translators: %1$s: Order number. %2$s: Customer full name. */
echo sprintf( esc_html__( 'Payment for order #%1$s from %2$s has received a chargeback.', 'woocommerce-bluesnap-gateway' ), esc_html( $order->get_order_number() ), esc_html( $order->get_formatted_billing_full_name() ) ) . "\n\n";

echo esc_html__( 'A chargeback occurs when a shopper contacts their card issuing bank and disputes a transaction they see on their statements.', 'woocommerce-bluesnap-gateway' ) . "\n\n";

/* translators: %1$s: Bluesnap Transaction ID. */
echo sprintf( esc_html__( 'If you would like to know more, please log in to your BlueSnap account. You can find the order using Order locator. Invoice ID %1$s.', 'woocommerce-bluesnap-gateway' ), esc_html( $order->get_transaction_id() ) ) . "\n\n";

if ( ! empty( $chargeback_reason ) ) {
	/* translators: %1$s: Reason for the chargeback. */
	echo sprintf( esc_html__( 'The bank informs that the reason for the chargeback is: %1$s', 'woocommerce-bluesnap-gateway' ), esc_html( $chargeback_reason ) ) . "\n\n";
}

echo esc_html__( 'The order was as follows:', 'woocommerce-bluesnap-gateway' ) . "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo esc_html( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
