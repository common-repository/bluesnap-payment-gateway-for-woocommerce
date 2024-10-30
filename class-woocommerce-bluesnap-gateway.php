<?php
/**
 * Installation related functions and actions.
 *
 * @author   SAU/CAL
 * @category Core
 * @package  Woocommerce_Bluesnap_Gateway
 * @version  1.3.5
 */

if ( ! class_exists( 'Woocommerce_Bluesnap_Gateway' ) ) :

	final class Woocommerce_Bluesnap_Gateway {

		/**
		 * Woocommerce_Bluesnap_Gateway version.
		 *
		 * @var string
		 */

		public $version = '3.1.0';

		public $db_version = '2.5.0';

		/**
		 * The single instance of the class.
		 *
		 * @var Woocommerce_Bluesnap_Gateway
		 * @since 1.0.0
		 */
		protected static $_instance = null;

		protected static $_initialized = false;

		/**
		 * WC_Bluesnap_Gateway instance.
		 *
		 * @var WC_Bluesnap_Gateway
		 */
		public static $bluesnap_gateway;

		/**
		 * Main Woocommerce_Bluesnap_Gateway Instance.
		 *
		 * Ensures only one instance of Woocommerce_Bluesnap_Gateway is loaded or can be loaded.
		 *
		 * @since 1.0.0
		 * @static
		 * @see WC_Bluesnap()
		 * @return Woocommerce_Bluesnap_Gateway - Main instance.
		 */
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
				self::$_instance->initalize_plugin();
			}
			return self::$_instance;
		}

		/**
		 * Gets options from instanced WC_Bluesnap_Gateway if instanced by WC already.
		 *
		 * @param $key
		 *
		 * @return mixed|null
		 */
		public function get_option( $key ) {
			$bluesnap_settings = get_option( 'woocommerce_bluesnap_settings' );
			return isset( $bluesnap_settings[ $key ] ) ? $bluesnap_settings[ $key ] : null;
		}

		/**
		 * Update plugin options on demand
		 *
		 * @param $key
		 * @param $value
		 * @return void
		 */
		public function update_option( $key, $value ) {
			$bluesnap_settings         = get_option( 'woocommerce_bluesnap_settings' );
			$bluesnap_settings[ $key ] = $value;
			update_option( 'woocommerce_bluesnap_settings', $bluesnap_settings );
		}

		/**
		 * Cloning is forbidden.
		 * @since 1.0.0
		 */
		public function __clone() {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'woocommerce-bluesnap-gateway' ), '1.0.0' );
		}

		/**
		 * Unserializing instances of this class is forbidden.
		 * @since 1.0.0
		 */
		public function __wakeup() {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'woocommerce-bluesnap-gateway' ), '1.0.0' );
		}

		/**
		 * Woocommerce_Bluesnap_Gateway Initializer.
		 */
		public function initalize_plugin() {
			if ( self::$_initialized ) {
				_doing_it_wrong( __FUNCTION__, esc_html__( 'Only a single instance of this class is allowed. Use singleton.', 'woocommerce-bluesnap-gateway' ), '1.0.0' );
				return;
			}
			self::$_initialized = true;

			$this->early_includes();

			add_action( 'plugins_loaded', array( $this, 'maybe_continue_init' ), -1 );
		}

		/**
		 * Actually do initialization if plugin dependencies are met.
		 */
		public function maybe_continue_init() {
			if ( ! $this->check_dependencies() ) {
				add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
				return;
			}

			$this->define_constants();
			$this->includes();
			$this->init_hooks();

			if ( class_exists( 'Bsnp_Payment_Gateway' ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
				deactivate_plugins( 'bluesnap-powered-buy-platform-for-woocommerce/bluesnap-powered-buy-platform-for-woocommerce.php' );
			}

			do_action( 'woocommerce_bluesnap_gateway_loaded' );
		}

		/**
		 * Returns true if dependencies are met, false if not.
		 *
		 * @return bool
		 */
		public function check_dependencies() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return false;
			}
			return true;
		}

		/**
		 * Prints notice about requirements not met.
		 *
		 * @return void
		 */
		public function dependency_notice() {
			?>
			<div class="notice notice-error">
				<p><strong><?php echo esc_html__( 'WooCommerce Bluesnap Gateway is inactive because WooCommerce is not installed and active.', 'woocommerce-bluesnap-gateway' ); ?></strong></p>
			</div>
			<?php
		}

		/**
		 * Define WC_Bluesnap Constants.
		 */
		private function define_constants() {
			$upload_dir = wp_upload_dir();

			$this->define( 'WC_BLUESNAP_PLUGIN_BASENAME', plugin_basename( WC_BLUESNAP_PLUGIN_FILE ) );
			$this->define( 'WC_BLUESNAP_VERSION', $this->version );
			$this->define( 'WC_BLUESNAP_DB_VERSION', $this->db_version );
			$this->define( 'WC_BLUESNAP_GATEWAY_ID', 'bluesnap' );
			$this->define( 'WC_BLUESNAP_ACH_GATEWAY_ID', 'bluesnap_ach' );
			$this->define( 'WC_BLUESNAP_GATEWAY_OLD_ID', 'wc_gateway_bluesnap_cc' );
			$this->define( 'REFUND_REASON_PREFIX', 'wc_reason:' );
		}

		/**
		 * Define constant if not already set.
		 *
		 * @param  string $name
		 * @param  string|bool $value
		 */
		private function define( $name, $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

		/**
		 * What type of request is this?
		 *
		 * @param  string $type admin, ajax, cron or frontend.
		 * @return bool
		 */
		private function is_request( $type ) {
			switch ( $type ) {
				case 'admin':
					return is_admin();
				case 'ajax':
					return defined( 'DOING_AJAX' );
				case 'cron':
					return defined( 'DOING_CRON' );
				case 'frontend':
					return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' );
			}
		}

		/**
		 * Include required core files used in admin and on the frontend.
		 */
		private function early_includes() {
			include_once 'includes/class-wc-bluesnap-install.php';
			include_once 'includes/class-wc-bluesnap-migrator.php';
		}

		/**
		 * Include required core files used in admin and on the frontend.
		 */
		private function includes() {
			include_once 'includes/class-wc-bluesnap-autoloader.php';
			include_once 'includes/woocommerce-bluesnap-gateway-core-functions.php';

			if ( $this->is_request( 'admin' ) ) {
				include_once 'includes/admin/class-wc-bluesnap-admin.php';
			}

			if ( $this->is_request( 'frontend' ) ) {
				include_once 'includes/class-wc-bluesnap-frontend-assets.php'; // Frontend Scripts
				include_once 'includes/class-wc-bluesnap-api.php';
				include_once 'includes/class-wc-bluesnap-api-exception.php';
				include_once 'includes/class-wc-bluesnap-logger.php';
				include_once 'includes/class-wc-bluesnap-shopper.php';
				include_once 'includes/class-wc-bluesnap-token.php';
				include_once 'includes/class-wc-bluesnap-ipn-webhooks.php';
			}

			// Include token class
			include_once 'includes/class-wc-payment-token-bluesnap-cc.php';
			include_once 'includes/class-wc-payment-token-bluesnap-ach.php';

			// Widgets
			include_once 'includes/widgets/class-wc-bluesnap-widget-multicurrency.php';

			// Include multicurrency support
			include_once 'includes/class-wc-bluesnap-multicurrency.php';

			// Support for Payment requests
			include_once 'includes/class-wc-bluesnap-apple-pay.php';
			include_once 'includes/class-wc-bluesnap-google-pay.php';
			include_once 'includes/constants/class-wc-bluesnap-payment-request-states.php';
		}

		/**
		 * Hook into actions and filters.
		 * @since  1.0.0
		 */
		private function init_hooks() {
			add_action( 'init', array( $this, 'init' ), 0 );
			add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ), 0 );

			// Add custom query vars to the WC_Order query.
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'handle_custom_ipn_query' ) );
			add_filter( 'woocommerce_order_query_args', array( $this, 'handle_custom_ipn_query' ) );
		}

		/**
		 * Init Woocommerce_Bluesnap_Gateway when WordPress Initialises.
		 */
		public function init() {
			// Before init action.
			do_action( 'before_woocommerce_bluesnap_gateway_init' );

			// Set up localisation.
			$this->load_plugin_textdomain();

			// Init action.
			do_action( 'woocommerce_bluesnap_gateway_init' );
		}

		/**
		 * Hooks when plugin_loaded
		 */
		public function plugins_loaded() {
			$this->payment_methods_includes();
			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
			add_filter( 'woocommerce_email_classes', array( $this, 'add_custom_emails' ) );
			add_action( 'wc_ajax_bluesnap_reset_hpf', array( 'WC_Bluesnap_Gateway', 'hpf_maybe_reset_transaction_token_session' ) );
			add_action( 'wp_login', array( 'WC_Bluesnap_Gateway', 'hpf_clean_transaction_token_session' ) );
		}

		/**
		 * Include required payment-methods files.
		 */
		public function payment_methods_includes() {

			// Include its abstract
			include_once 'includes/payment-methods/abstract-wc-bluesnap-payment-gateway.php';

			include_once 'includes/trait-wc-bluesnap-addons.php';

			$methods = array(
				'',
			);
			foreach ( $methods as $method ) {
				include_once 'includes/payment-methods/class-wc-bluesnap-gateway' . $method . '.php';
			}

			// Include gateway addons
			include_once 'includes/class-wc-bluesnap-gateway-addons.php';
			include_once 'includes/class-wc-bluesnap-gateway-addons-ach.php';
			include_once 'includes/class-wc-bluesnap-order-handler.php';
		}

		/**
		 * Add the gateways to WooCommerce.
		 * @param array $methods
		 *
		 * @return array
		 */
		public function add_gateways( $methods ) {
			$methods[] = 'WC_Bluesnap_Gateway_Addons';
			$methods[] = 'WC_Bluesnap_Gateway_Addons_ACH';
			return $methods;
		}

		/**
		 * Add the custom emails from Bluesnap to WooCommerce.
		 * @param array $emails
		 *
		 * @return array
		 */
		public function add_custom_emails( $emails ) {
			$emails['WC_Bluesnap_Email_Chargeback_Order'] = include_once 'includes/class-wc-bluesnap-email-chargeback-order.php';
			return $emails;
		}

		/**
		 * Load Localisation files.
		 *
		 * Note: the first-loaded translation file overrides any following ones if the same translation is present.
		 *
		 * Locales found in:
		 *      - WP_LANG_DIR/woocommerce-bluesnap-gateway/woocommerce-bluesnap-gateway-LOCALE.mo
		 *      - WP_LANG_DIR/plugins/woocommerce-bluesnap-gateway-LOCALE.mo
		 */
		private function load_plugin_textdomain() {
			$locale = apply_filters( 'plugin_locale', get_locale(), 'woocommerce-bluesnap-gateway' );

			load_textdomain( 'woocommerce-bluesnap-gateway', WP_LANG_DIR . '/woocommerce-bluesnap-gateway/woocommerce-bluesnap-gateway-' . $locale . '.mo' );
			load_plugin_textdomain( 'woocommerce-bluesnap-gateway', false, plugin_basename( dirname( __FILE__ ) ) . '/i18n/languages' );
		}

		/**
		 * Get the plugin url.
		 * @return string
		 */
		public function plugin_url() {
			return untrailingslashit( plugins_url( '/', __FILE__ ) );
		}

		/**
		 * Get the plugin path.
		 * @return string
		 */
		public function plugin_path() {
			return untrailingslashit( plugin_dir_path( __FILE__ ) );
		}

		/**
		 * @param string $image
		 *
		 * @return string
		 */
		public function images_url( $image = '' ) {
			return $this->plugin_url() . '/assets/images/' . $image;
		}

		/**
		 * Get the template path.
		 * @return string
		 */
		public function template_path() {
			return apply_filters( 'woocommerce_bluesnap_gateway_template_path', 'woocommerce-bluesnap-gateway/' );
		}

		/**
		 * Get Ajax URL.
		 * @return string
		 */
		public function ajax_url() {
			return admin_url( 'admin-ajax.php', 'relative' );
		}

		/**
		 * Return credit card type slug
		 *
		 * @since 2.5.2
		 * @see https://support.bluesnap.com/docs/faqs-hosted-payment-fields#how-can-i-upgrade-from-v30-to-v40-of-the-hosted-payment-fields
		 *
		 * @param string $card_type CardType
		 *
		 * @return string
		 */
		public static function get_card_type_slug( $card_type = '' ) {

			if ( empty( $card_type ) ) {
				return '';
			}

			$card_type = strtolower( $card_type );

			switch ( $card_type ) {
				case 'americanexpress':
				case 'american express':
					$slug = 'AMEX';
					break;
				case 'dinersclub':
				case 'diners club':
					$slug = 'DINERS';
					break;
				case 'discover':
					$slug = 'DISCOVER';
					break;
				case 'mastercard':
				case 'master card':
					$slug = 'MASTERCARD';
					break;
				case 'visa':
					$slug = 'VISA';
					break;
				default:
					$slug = $card_type;
					break;
			}

			return strtoupper( $slug );
		}


		/**
		 * Return if 3DS is enabled
		 *
		 * @since 2.5.2
		 *
		 * @return boolean
		 */
		public static function is_3d_secure_enabled() {

			$settings = get_option( 'woocommerce_bluesnap_settings' );

			if ( empty( $settings ) ) {
				return false;
			}

			return ( ! empty( $settings['_3D_secure'] ) && 'yes' === $settings['_3D_secure'] ) ? true : false;
		}


		/**
	 * Check if we need to set a custom meta query for the Orders query.
	 *
	 * @param  array $query_vars The query vars.
	 * @return array
	 */
		public function handle_custom_ipn_query( $query_vars ) {
			if ( ! empty( $query_vars['ipn_transaction_id'] ) ) {

				if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
					$query_vars['transaction_id'] = esc_attr( $query_vars['ipn_transaction_id'] );
				} else {
					$query_vars['meta_query'][] = array(
						'key'   => '_bluesnap_invoice_id',
						'value' => esc_attr( $query_vars['ipn_transaction_id'] ),
					);
	
					$query_vars['meta_query'][] = array(
						'key'   => '_transaction_id',
						'value' => esc_attr( $query_vars['ipn_transaction_id'] ),
					);
	
					$query_vars['meta_query']['relation'] = 'OR';
				}
			}

			return $query_vars;
		}
	}

endif;
