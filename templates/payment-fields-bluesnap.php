<?php

/**
 * Provide a public-facing view for bluesnap checkout.
 *
 * @link       https://saucal.com
 * @since      1.0.0
 *
 * @package    Woocommerce_Bluesnap_Gateway
 * @subpackage Woocommerce_Bluesnap_Gateway/public/partials
 */
?>

<script type="text/javascript">
	if( typeof woocommerce_bluesnap_gateway_general_params == "undefined" ) {
		woocommerce_bluesnap_gateway_general_params = {};
	}
	<?php
	echo sprintf(
		'woocommerce_bluesnap_gateway_general_params.token = %s;',
		wp_json_encode( WC_Bluesnap_Gateway::get_hosted_payment_field_token() )
	);
	?>
</script>
<fieldset id="wc-<?php echo esc_attr( $gateway->id ); ?>-cc-form" class="wc-credit-card-form wc-payment-form">
	<div class="form-row form-row-wide">
		<label for="<?php echo esc_attr( $gateway->id ); ?>-card-number"><?php esc_html_e( 'Card Number', 'woocommerce-bluesnap-gateway' ); ?> <span class="required">*</span></label>
		<div class="ccn-area">
			<div data-bluesnap="ccn" id="<?php echo esc_attr( $gateway->id ); ?>-card-number" class="<?php echo esc_attr( $gateway->id ); ?>-input-div"></div>
			<div id="card-logo" class="input-group-addon"><img src="<?php echo esc_attr( WC_Bluesnap()->images_url( 'generic-card.png' ) ); ?>"></div>
		</div>
	</div>
	<div class="form-row form-row-first">
		<label for="<?php echo esc_attr( $gateway->id ); ?>-card-expiry"><?php esc_html_e( 'Expiry Date', 'woocommerce-bluesnap-gateway' ); ?> <span class="required">*</span></label>
		<div data-bluesnap="exp" id="<?php echo esc_attr( $gateway->id ); ?>-card-expiry" class="<?php echo esc_attr( $gateway->id ); ?>-input-div"></div>
	</div>
	<div class="form-row form-row-last">
		<label for="<?php echo esc_attr( $gateway->id ); ?>-card-cvc">
			<?php esc_html_e( 'CVC', 'woocommerce-bluesnap-gateway' ); ?>
			<span class="required">*</span>
			<a href="#" class="bluesnap-cvc-tooltip-trigger"
				title="<?php esc_attr_e( "What's this?", 'woocommerce-bluesnap-gateway' ); ?>" tabindex="32000">?</a>
			<div class="bluesnap-cvc-tooltip"><?php esc_html_e( 'The CVC (Card Validation Code) is a 3 or 4 digit code on the reverse side of Visa, MasterCard and Discover cards and on the front of American Express cards.', 'woocommerce-bluesnap-gateway' ); ?></div>
		</label>
		<div data-bluesnap="cvv" id="<?php echo esc_attr( $gateway->id ); ?>-card-cvc" class="<?php echo esc_attr( $gateway->id ); ?>-input-div"></div>
	</div>
	<div class="clear"></div>
	<input type="hidden" name="bluesnap_card_info" id="bluesnap_card_info"/>
	<input type="hidden" name="bluesnap_3ds_reference_id" id="bluesnap_3ds_reference_id"/>
</fieldset>
