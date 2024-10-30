<?php

/**
 * Multicurrency html wrapper
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

<span class="bluesnap-multicurrency-html <?php echo ( $hide ) ? 'currency-hide' : 'currency-show'; ?>" currency="<?php echo esc_attr( $currency ); ?>">
	<?php echo $html; // WPCS: XSS ok, sanitization ok. ?>
</span>
