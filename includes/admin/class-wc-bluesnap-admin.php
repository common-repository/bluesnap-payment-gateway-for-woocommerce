<?php
/**
 * WordPress Plugin Boilerplate Admin
 *
 * @class    WC_Bluesnap_Admin
 * @author   SAU/CAL
 * @category Admin
 * @package  Woocommerce_Bluesnap_Gateway/Admin
 * @version  1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WC_Bluesnap_Admin class.
 */
class WC_Bluesnap_Admin {

	const REVIEWS_URL = 'https://wordpress.org/support/plugin/bluesnap-payment-gateway-for-woocommerce/reviews/';

	const SUPPORT_TICKET = 'https://wordpress.org/support/plugin/bluesnap-payment-gateway-for-woocommerce/#new-topic-0';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->includes();
		add_action( 'current_screen', array( $this, 'conditional_includes' ) );
		add_filter( 'plugin_action_links_' . WC_BLUESNAP_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ), 999, 2 );
		
		// Admin notices.
		add_action( 'admin_notices', array( $this, 'show_notices' ) );
		add_action( 'admin_notices', array( $this, 'review_prompt' ) );

		// Ajax actions for admin notices.
		add_action( 'wp_ajax_bluesnap_dismiss_admin_notice', array( $this, 'dismiss_admin_notice' ) );
		add_action( 'wp_ajax_bluesnap_dismiss_review_prompt', array( $this, 'ajax_dismiss_review_prompt' ) );
	}

	/**
	 * Include any classes we need within admin.
	 */
	public function includes() {
		include_once 'woocommerce-bluesnap-gateway-admin-functions.php';
		include_once 'class-wc-bluesnap-admin-assets.php';
	}

	/**
	 * Include admin files conditionally.
	 */
	public function conditional_includes() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		switch ( $screen->id ) {
			case 'dashboard':
			case 'options-permalink':
			case 'users':
			case 'user':
			case 'profile':
			case 'user-edit':
		}
	}

	/**
	 * Adds plugin action links.
	 */
	public function plugin_action_links( $links, $test ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bluesnap' ) . '">' . esc_html__( 'Settings', 'woocommerce-bluesnap-gateway' ) . '</a>',
		);
		return array_merge( $plugin_links, $links );
	}

	public function get_admin_notices_dismissed() {
		$notices = get_user_meta( get_current_user_id(), 'bluesnap_admin_notices_dismissed', true );
		$notices = ! empty( $notices ) ? $notices : array();
		$notices = is_array( $notices ) ? $notices : array( $notices );
		return $notices;
	}

	public function is_admin_notice_active( $notice ) {
		$notices = $this->get_admin_notices_dismissed();
		return ! isset( $notices[ $notice ] );
	}

	public function dismiss_admin_notice() {
		check_ajax_referer( 'bluesnap-dismiss-admin-notice', 'nonce' );

		$notice = $_GET['notice_id'];
		if ( empty( $notice ) ) {
			return;
		}

		$notices            = $this->get_admin_notices_dismissed();
		$notices[ $notice ] = 1;
		update_user_meta( get_current_user_id(), 'bluesnap_admin_notices_dismissed', $notices );

		die( 1 );
	}

	public function show_notices() {
		$enabled = WC_Bluesnap()->get_option( 'enabled' ) === 'yes';
		if ( ! $enabled ) {
			return;
		}

		$testmode = 'yes' === WC_Bluesnap()->get_option( 'testmode' );

		if ( ! $testmode && ! wc_checkout_is_https() && $this->is_admin_notice_active( 'bluesnap-notice-https' ) ) {
			?>
			<div data-dismissible="bluesnap-notice-https" class="notice notice-warning is-dismissible">
				<?php /* translators: 1) link */ ?>
				<p><?php echo wp_kses_post( sprintf( __( 'Bluesnap is enabled, but a SSL certificate is not detected. Your checkout may not be secure! Please ensure your server has a valid <a href="%1$s" target="_blank">SSL certificate</a>', 'woocommerce-bluesnap-gateway' ), 'https://en.wikipedia.org/wiki/Transport_Layer_Security' ) ); ?></p>
			</div>
			<?php
		}
	}


	/**
	 * Display a prompt for reviews!
	 *
	 * @return void
	 */
	public function review_prompt() {
		global $current_section;

		$screen = get_current_screen();

		// Ensures we are in plugin's settings page.
		if ( ! isset( $screen, $screen->id ) || 'woocommerce_page_wc-settings' !== $screen->id || 'bluesnap' !== $current_section ) {
			return;
		}

		$enabled = WC_Bluesnap()->get_option( 'enabled' ) === 'yes';
		if ( ! $enabled ) {
			return;
		}

		$anniversary_date  = get_option( 'woocommerce_bluesnap_anniversary_date' );
		$hidden_until_date = get_option( 'woocommerce_bluesnap_hidden_until_date' );

		if ( ! $anniversary_date ) {
			$anniversary_date = time();
			update_option( 'woocommerce_bluesnap_anniversary_date', $anniversary_date );
		}

		if ( ! $hidden_until_date ) {
			$hidden_until_date = strtotime( '+1 month', time() );
			update_option( 'woocommerce_bluesnap_hidden_until_date', $hidden_until_date );
		}

		if ( $hidden_until_date > time() ) {
			return;
		}
		?>
			<div class="notice notice-info bluesnap-review-prompt">
				<p>
					<?php esc_html_e( 'We\'d be grateful if you could give our plugin a 5-star rating. Your reviews help us continue to grow.', 'woocommerce-gateway-bluesnap' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( self::REVIEWS_URL ); ?>" target="_blank" rel="nofollow" class="button-secondary">
						<?php esc_html_e( 'Yes you deserve it!', 'woocommerce-gateway-bluesnap' ); ?>
					</a>
				</p>
				<p>
					<a class="bluesnap-review-prompt-dismiss" href="#" target="_blank" rel="nofollow" >
						<?php esc_html_e( 'Hide this message / Already did!', 'woocommerce-gateway-bluesnap' ); ?>
					</a>
				</p>
				<p>
					<a href="<?php echo esc_url( self::SUPPORT_TICKET ); ?>" target="_blank" rel="nofollow" >
						<?php esc_html_e( 'Actually, I need help...', 'woocommerce-gateway-bluesnap' ); ?>
					</a>
				</p>
			</div>
		<?php
	}

	/**
	 * Handle plugin's review prompt dismissal.
	 *
	 * @return void
	 */
	public function ajax_dismiss_review_prompt() {
		check_ajax_referer( 'bluesnap-dismiss-prompt-review', 'security' );

		$anniversary_date = get_option( 'woocommerce_bluesnap_anniversary_date' );

		// Start from the stored anniversary date.
		// Add a year in each loop until the result is in the future.
		$hidden_until_date = is_numeric( $anniversary_date ) ? (int) $anniversary_date : time();
		while ( $hidden_until_date < time() ) {
			$hidden_until_date = strtotime( '+1 year', $hidden_until_date );
		}

		update_option( 'woocommerce_bluesnap_hidden_until_date', $hidden_until_date );

		wp_die( '', 200 );
	}

}

return new WC_Bluesnap_Admin();
