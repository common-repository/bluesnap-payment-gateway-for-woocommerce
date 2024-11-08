<?php
/**
 * Class WC_Bluesnap_Email_Chargeback_Order file.
 *
 * @package WooCommerce\Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Bluesnap_Email_Chargeback_Order', false ) ) :

	/**
	 * Failed Order Email.
	 *
	 * An email sent to the admin when payment fails to go through.
	 *
	 * @class       WC_Bluesnap_Email_Chargeback_Order
	 * @extends     WC_Email
	 */
	class WC_Bluesnap_Email_Chargeback_Order extends WC_Email {

		private $reason = '';

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id             = 'chargeback_order';
			$this->title          = __( 'Chargeback received for order', 'woocommerce-bluesnap-gateway' );
			$this->description    = __( 'Chargeback received for order emails are sent to chosen recipient(s) when the issuing bank initiates a refund.', 'woocommerce-bluesnap-gateway' );
			$this->template_base  = WC_Bluesnap()->plugin_path() . '/templates/';
			$this->template_html  = 'emails/admin-chargeback-order.php';
			$this->template_plain = 'emails/plain/admin-chargeback-order.php';
			$this->placeholders   = array(
				'{site_title}'   => $this->get_blogname(),
				'{order_date}'   => '',
				'{order_number}' => '',
			);

			// Call parent constructor.
			parent::__construct();

			// Other settings.
			$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
		}

		/**
		 * Get email subject.
		 */
		public function get_default_subject() {
			return __( '[{site_title}] Chargeback received for order ({order_number})', 'woocommerce-bluesnap-gateway' );
		}

		/**
		 * Get email heading.
		 */
		public function get_default_heading() {
			return __( 'Chargeback received for order', 'woocommerce-bluesnap-gateway' );
		}

		/**
		 * Trigger the sending of this email.
		 *
		 * @param int            $order_id The order ID.
		 * @param WC_Order|false $order Order object.
		 */
		public function trigger( $order_id, $reason = '' ) {
			$this->setup_locale();

			if ( $order_id ) {
				$order = wc_get_order( $order_id );
			}

			if ( is_a( $order, 'WC_Order' ) ) {
				$this->object                         = $order;
				$this->placeholders['{order_date}']   = wc_format_datetime( $this->object->get_date_created() );
				$this->placeholders['{order_number}'] = $this->object->get_order_number();
				$this->reason                         = $reason;
			}

			if ( $this->is_enabled() && $this->get_recipient() ) {
				$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}

			$this->restore_locale();
		}

		/**
		 * Get content html.
		 *
		 * @access public
		 * @return string
		 */
		public function get_content_html() {
			return wc_get_template_html(
				$this->template_html, array( // phpcs:ignore PEAR.Functions.FunctionCallSignature.MultipleArguments
					'order'             => $this->object,
					'email_heading'     => $this->get_heading(),
					'chargeback_reason' => $this->reason,
					'sent_to_admin'     => true,
					'plain_text'        => false,
					'email'             => $this,
				),
				'',
				$this->template_base
			);
		}

		/**
		 * Get content plain.
		 *
		 * @return string
		 */
		public function get_content_plain() {
			return wc_get_template_html(
				$this->template_plain, array( // phpcs:ignore PEAR.Functions.FunctionCallSignature.MultipleArguments
					'order'             => $this->object,
					'email_heading'     => $this->get_heading(),
					'chargeback_reason' => $this->reason,
					'sent_to_admin'     => true,
					'plain_text'        => true,
					'email'             => $this,
				),
				'',
				$this->template_base
			);
		}

		/**
		 * Initialise settings form fields.
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'    => array(
					'title'   => __( 'Enable/Disable', 'woocommerce-bluesnap-gateway' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable this email notification', 'woocommerce-bluesnap-gateway' ),
					'default' => 'yes',
				),
				'recipient'  => array(
					'title'       => __( 'Recipient(s)', 'woocommerce-bluesnap-gateway' ),
					'type'        => 'text',
					/* translators: %s: WP admin email */
					'description' => sprintf( __( 'Enter recipients (comma separated) for this email. Defaults to %s.', 'woocommerce-bluesnap-gateway' ), '<code>' . esc_attr( get_option( 'admin_email' ) ) . '</code>' ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => true,
				),
				'subject'    => array(
					'title'       => __( 'Subject', 'woocommerce-bluesnap-gateway' ),
					'type'        => 'text',
					'desc_tip'    => true,
					/* translators: %s: list of placeholders */
					'description' => sprintf( __( 'Available placeholders: %s', 'woocommerce-bluesnap-gateway' ), '<code>{site_title}, {order_date}, {order_number}</code>' ),
					'placeholder' => $this->get_default_subject(),
					'default'     => '',
				),
				'heading'    => array(
					'title'       => __( 'Email heading', 'woocommerce-bluesnap-gateway' ),
					'type'        => 'text',
					'desc_tip'    => true,
					/* translators: %s: list of placeholders */
					'description' => sprintf( __( 'Available placeholders: %s', 'woocommerce-bluesnap-gateway' ), '<code>{site_title}, {order_date}, {order_number}</code>' ),
					'placeholder' => $this->get_default_heading(),
					'default'     => '',
				),
				'email_type' => array(
					'title'       => __( 'Email type', 'woocommerce-bluesnap-gateway' ),
					'type'        => 'select',
					'description' => __( 'Choose which format of email to send.', 'woocommerce-bluesnap-gateway' ),
					'default'     => 'html',
					'class'       => 'email_type wc-enhanced-select',
					'options'     => $this->get_email_type_options(),
					'desc_tip'    => true,
				),
			);
		}
	}

endif;

return new WC_Bluesnap_Email_Chargeback_Order();
