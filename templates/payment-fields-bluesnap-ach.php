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

<fieldset id="wc-<?php echo esc_attr( $gateway->id ); ?>-ach-form" class="wc-ach-payment-form wc-payment-form">

	<div class="form-row form-row-wide">
		<label for="<?php echo esc_attr( $gateway->id ); ?>-account-number"><?php esc_html_e( 'Account Number', 'woocommerce-bluesnap-gateway' ); ?> <span class="required">*</span></label>
		<input type="text" id="<?php echo esc_attr( $gateway->id ); ?>-account-number" name="<?php echo esc_attr( $gateway->id ); ?>-account-number" class="<?php echo esc_attr( $gateway->id ); ?>-input-div">
	</div>

	<div class="form-row form-row-wide">
		<label for="<?php echo esc_attr( $gateway->id ); ?>-routing-number"><?php esc_html_e( 'Routing Number', 'woocommerce-bluesnap-gateway' ); ?> <span class="required">*</span></label>
		<input type="text" id="<?php echo esc_attr( $gateway->id ); ?>-routing-number" name="<?php echo esc_attr( $gateway->id ); ?>-routing-number" class="<?php echo esc_attr( $gateway->id ); ?>-input-div">
	</div>

	<div class="form-row form-row-wide">
		<label for="<?php echo esc_attr( $gateway->id ); ?>-account-type"><?php esc_html_e( 'Account Type', 'woocommerce-bluesnap-gateway' ); ?> <span class="required">*</span></label>
		<select id="<?php echo esc_attr( $gateway->id ); ?>-account-type" name="<?php echo esc_attr( $gateway->id ); ?>-account-type" class="<?php echo esc_attr( $gateway->id ); ?>-input-div">
			<option value=""><?php esc_html_e( 'Select Account type', 'woocommerce-bluesnap-gateway' ); ?></option>
			<option value="consumer-checking"><?php esc_html_e( 'Consumer checking', 'woocommerce-bluesnap-gateway' ); ?></option>
			<option value="consumer-savings"><?php esc_html_e( 'Consumer savings', 'woocommerce-bluesnap-gateway' ); ?></option>
			<option value="corporate-checking"><?php esc_html_e( 'Corporate checking', 'woocommerce-bluesnap-gateway' ); ?></option>
			<option value="corporate-savings"><?php esc_html_e( 'Corporate savings', 'woocommerce-bluesnap-gateway' ); ?></option>
		</select>
	</div>

	<?php if ( ! is_add_payment_method_page() ) : ?>
	<div class="form-row form-row-wide">
		<input type="checkbox" id="<?php echo esc_attr( $gateway->id ); ?>-user-consent" name="<?php echo esc_attr( $gateway->id ); ?>-user-consent" class="<?php echo esc_attr( $gateway->id ); ?>-checkbox-div" value="yes" style="width:auto;">
		<label for="<?php echo esc_attr( $gateway->id ); ?>-user-consent" style="display:inline;"><?php esc_html_e( 'I authorize this Electronic Check (ACH/ECP) transaction and agree to this debit of my account.', 'woocommerce-bluesnap-gateway' ); ?> <span class="required">*</span></label>
	</div>
	<?php endif; ?>

</fieldset>
