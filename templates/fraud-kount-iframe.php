<?php

/**
 * Fraud Kount's iframe implementation
 *
 * https://developers.bluesnap.com/docs/fraud-prevention#section-step-2-embed-bluesnap-data-in-your-checkout-page
 *
 * @link       https://saucal.com
 * @since      1.0.0
 *
 * @package    Woocommerce_Bluesnap_Gateway
 * @subpackage Woocommerce_Bluesnap_Gateway/public/partials
 */
?>
<div style='position: relative;'>
	<iframe width='1' height='1' frameborder='0' scrolling='no' src='<?php echo esc_attr( $domain ); ?>/servlet/logo.htm?s=<?php echo esc_attr( $fraud_id ); ?>&d=<?php echo esc_attr( $developer_id ); ?>' style='position: absolute;'>
		<img width='1' height='1' src='<?php echo esc_attr( $domain ); ?>/servlet/logo.gif?s=<?php echo esc_attr( $fraud_id ); ?>&d=<?php echo esc_attr( $developer_id ); ?>'>
	</iframe>
</div>
