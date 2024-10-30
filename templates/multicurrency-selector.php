<?php

/**
 * Multicurrency Selector html
 *
 * This file is used to markup the public-facing aspects of the plugin.
 *
 * @link       https://saucal.com
 * @since      1.0.0
 *
 * @package    Woocommerce_Bluesnap_Gateway
 * @subpackage Woocommerce_Bluesnap_Gateway/public/partials
 */
?>


<?php $currency_selected = WC_Bluesnap_Multicurrency::get_currency_user_selected(); ?>
<form class="bluesnap-multicurrency" method="post" action="<?php echo esc_attr( WC_AJAX::get_endpoint( 'bluesnap_set_multicurrency' ) ); ?>">
	<input type="hidden" name="action" value="bluesnap_multicurrency_action">
	<select name="bluesnap_currency_selector" class="bluesnap_currency_selector">
		<option <?php selected( $currency_selected, $original_currency ); ?> value="<?php echo esc_attr( $original_currency ); ?>"><?php esc_html_e( 'Default Currency', 'woocommerce-bluesnap-gateway' ); ?></option>
		<?php foreach ( $options as $option ) : ?>
			<option <?php selected( $currency_selected, $option ); ?> value="<?php echo esc_attr( $option ); ?>" <?php echo ( 'all' !== $allowed && ! isset( $allowed[ $option ] ) ) ? 'disabled="disabled"' : ''; ?>><?php echo esc_html( $option ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php WC_Bluesnap_Multicurrency::nonce_field(); ?>
</form>
