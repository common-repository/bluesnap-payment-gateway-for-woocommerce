<?php
/**
 * Admin chargeback order email
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/admin-chargeback-order.php
 *
 * @link       https://saucal.com
 * @since      1.0.0
 *
 * @package    Woocommerce_Bluesnap_Gateway
 * @subpackage Woocommerce_Bluesnap_Gateway/public/partials/emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %1$s: Order number. %2$s: Customer full name. */ ?>
<p><?php printf( esc_html__( 'Payment for order #%1$s from %2$s has received a chargeback.', 'woocommerce-bluesnap-gateway' ), esc_html( $order->get_order_number() ), esc_html( $order->get_formatted_billing_full_name() ) ); ?></p>

<p><?php esc_html_e( 'A chargeback occurs when a shopper contacts their card issuing bank and disputes a transaction they see on their statements.', 'woocommerce-bluesnap-gateway' ); ?></p>

<?php /* translators: %1$s: Bluesnap Transaction ID. */ ?>
<p><?php printf( esc_html__( 'If you would like to know more, please log in to your BlueSnap account. You can find the order using Order locator. Invoice ID %1$s.', 'woocommerce-bluesnap-gateway' ), esc_html( $order->get_transaction_id() ) ); ?></p>

<?php
if ( ! empty( $chargeback_reason ) ) {
	?>
	<?php /* translators: %1$s: Reason. */ ?>
	<p><?php printf( esc_html__( 'The bank informs that the reason for the chargeback is: %1$s', 'woocommerce-bluesnap-gateway' ), esc_html( $chargeback_reason ) ); ?></p>
	<?php
}
?>

<p><?php esc_html_e( 'The order was as follows:', 'woocommerce-bluesnap-gateway' ); ?></p>
<?php

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::email_footer() Output the email footer
*/
do_action( 'woocommerce_email_footer', $email );
