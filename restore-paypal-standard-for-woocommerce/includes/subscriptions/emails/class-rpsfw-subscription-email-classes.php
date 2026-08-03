<?php
/**
 * Subscription email classes.
 *
 * Loaded from the woocommerce_email_classes filter, so WC_Email exists.
 * Content is generated programmatically (WooCommerce email header/footer
 * plus a details table) rather than via template files, keeping the
 * emails consistent with the store's WooCommerce email styling.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

/**
 * Shared base for subscription emails.
 */
abstract class RPSFW_Subscription_Email extends WC_Email {

	/**
	 * Subscription this email is about.
	 *
	 * @var RPSFW_Subscription|null
	 */
	protected $subscription = null;

	/**
	 * Whether this is an admin-recipient email.
	 *
	 * @var bool
	 */
	protected $is_admin_email = false;

	/**
	 * Intro line above the details table. Subclasses set per-trigger.
	 *
	 * @var string
	 */
	protected $intro_text = '';

	/**
	 * Status used when rendering sample content for the email preview
	 * (when no live subscription object is available).
	 *
	 * @var string
	 */
	protected $preview_status = 'active';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->template_base  = '';
		$this->template_html  = '';
		$this->template_plain = '';

		parent::__construct();

		// Ensure a rich HTML email is sent. Without an explicit type,
		// WC_Email::get_email_type() falls back to plain text, which strips
		// all of the HTML formatting from these notifications.
		if ( empty( $this->email_type ) || ! in_array( $this->email_type, array_keys( $this->get_email_type_options() ), true ) ) {
			$this->email_type = 'html';
		}

		if ( $this->is_admin_email ) {
			$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
		}
	}

	/**
	 * Prepare shared state and send.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 */
	protected function trigger_for_subscription( $subscription ) {
		if ( ! $subscription instanceof RPSFW_Subscription || ! $subscription->exists() ) {
			return;
		}

		$this->setup_locale();

		$this->subscription = $subscription;
		$this->placeholders = array_merge(
			(array) $this->placeholders,
			array(
				'{subscription_id}' => $subscription->get_id(),
				'{product_name}'    => $subscription->get_item_name(),
				'{customer_name}'   => $subscription->get_customer_name(),
			)
		);

		if ( ! $this->is_admin_email ) {
			$order           = $subscription->get_parent_order();
			$this->recipient = $order ? $order->get_billing_email() : '';
			if ( ! $this->recipient && $subscription->get_customer_id() ) {
				$user            = get_userdata( $subscription->get_customer_id() );
				$this->recipient = $user ? $user->user_email : '';
			}
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/**
	 * Details table + intro, wrapped in the standard WooCommerce email
	 * header/footer.
	 *
	 * @return string
	 */
	public function get_content_html() {
		ob_start();

		do_action( 'woocommerce_email_header', $this->get_heading(), $this );

		$intro = $this->get_intro_line();
		if ( $intro ) {
			echo '<p style="margin:0 0 16px;">' . wp_kses_post( $intro ) . '</p>';
		}

		echo '<h2 style="color:#2c2d33;display:block;font-family:\'Helvetica Neue\',Helvetica,Roboto,Arial,sans-serif;font-size:18px;font-weight:bold;line-height:130%;margin:0 0 12px;text-align:left;">'
			. esc_html__( 'Subscription details', 'restore-paypal-standard-for-woocommerce' )
			. '</h2>';

		echo '<table cellspacing="0" cellpadding="0" border="0" style="width:100%;border:1px solid #e5e5e5;border-collapse:collapse;margin:0 0 24px;font-family:\'Helvetica Neue\',Helvetica,Roboto,Arial,sans-serif;font-size:14px;">';
		foreach ( $this->get_summary_columns() as $column ) {
			echo '<tr>';
			echo '<th scope="row" style="text-align:left;vertical-align:top;padding:12px 15px;background-color:#f8f8f8;border:1px solid #e5e5e5;font-weight:bold;color:#636363;width:35%;">' . esc_html( $column['heading'] ) . '</th>';
			echo '<td style="text-align:left;vertical-align:top;padding:12px 15px;border:1px solid #e5e5e5;color:#636363;">' . wp_kses_post( $column['html'] ) . '</td>';
			echo '</tr>';
		}
		echo '</table>';

		if ( ! $this->is_admin_email ) {
			$link = $this->get_view_link();
			if ( $link ) {
				echo '<p style="margin:0 0 24px;"><a href="' . esc_url( $link['url'] ) . '" style="display:inline-block;padding:12px 24px;background-color:#7f54b3;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;font-family:\'Helvetica Neue\',Helvetica,Roboto,Arial,sans-serif;">' . esc_html( $link['label'] ) . '</a></p>';
			}
		}

		if ( ! $this->is_admin_email && $this->get_additional_content() ) {
			echo '<hr style="border:none;border-top:1px solid #e5e5e5;margin:24px 0;">';
			echo '<div style="color:#636363;font-family:\'Helvetica Neue\',Helvetica,Roboto,Arial,sans-serif;font-size:14px;">' . wp_kses_post( wpautop( wptexturize( $this->get_additional_content() ) ) ) . '</div>';
		}

		do_action( 'woocommerce_email_footer', $this );

		return ob_get_clean();
	}

	/**
	 * Plain text version.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		$content = wp_strip_all_tags( $this->get_heading() ) . "\n\n";

		$intro = $this->get_intro_line();
		if ( $intro ) {
			$content .= wp_strip_all_tags( $intro ) . "\n\n";
		}

		$heading  = __( 'Subscription details', 'restore-paypal-standard-for-woocommerce' );
		$content .= $heading . "\n";
		$content .= str_repeat( '-', strlen( $heading ) ) . "\n";

		foreach ( $this->get_summary_columns() as $column ) {
			$content .= $column['heading'] . ': ' . wp_strip_all_tags( $column['plain'] ) . "\n";
		}

		if ( ! $this->is_admin_email ) {
			$link = $this->get_view_link();
			if ( $link ) {
				$content .= "\n" . $link['label'] . ': ' . $link['url'] . "\n";
			}
		}

		if ( ! $this->is_admin_email && $this->get_additional_content() ) {
			$content .= "\n" . wp_strip_all_tags( wptexturize( $this->get_additional_content() ) ) . "\n";
		}

		return $content;
	}

	/**
	 * Resolve the intro line, substituting sample values when rendering a
	 * preview (no live subscription).
	 *
	 * @return string
	 */
	protected function get_intro_line() {
		if ( ! $this->intro_text ) {
			return '';
		}

		if ( $this->subscription instanceof RPSFW_Subscription ) {
			return $this->format_string( $this->intro_text );
		}

		// Preview: fill placeholders with sample values first.
		$sample = str_replace(
			array( '{customer_name}', '{product_name}', '{subscription_id}' ),
			array( __( 'Jane Doe', 'restore-paypal-standard-for-woocommerce' ), __( 'Sample Product', 'restore-paypal-standard-for-woocommerce' ), '123' ),
			$this->intro_text
		);

		return $this->format_string( $sample );
	}

	/**
	 * Build the columns for the summary table, WooCommerce-Subscriptions
	 * style: a single horizontal row with column headers. Uses the live
	 * subscription when available, otherwise sample data for the preview.
	 *
	 * Each column is an array of 'heading', 'html' and 'plain'.
	 *
	 * @return array
	 */
	protected function get_summary_columns() {
		$subscription = $this->subscription;
		$is_preview   = ! ( $subscription instanceof RPSFW_Subscription );

		if ( $is_preview ) {
			$id_display   = '#123';
			$id_url       = $this->is_admin_email ? admin_url() : wc_get_page_permalink( 'myaccount' );
			$product      = __( 'Sample Product', 'restore-paypal-standard-for-woocommerce' );
			$total        = wp_strip_all_tags( wc_price( 19.99 ) ) . ' ' . rpsfw_format_subscription_period( 1, 'month' );
			$status_label = rpsfw_get_subscription_status_label( $this->preview_status );
			$gateway      = 'PayPal';
			$customer     = __( 'Jane Doe', 'restore-paypal-standard-for-woocommerce' );
			$next_label   = 'active' === $this->preview_status ? __( 'Next payment', 'restore-paypal-standard-for-woocommerce' ) : '';
			$next_value   = 'active' === $this->preview_status ? date_i18n( wc_date_format(), strtotime( '+1 month' ) ) : '';
		} else {
			$id_display   = '#' . $subscription->get_id();
			$id_url       = $this->is_admin_email ? get_edit_post_link( $subscription->get_id(), 'raw' ) : $subscription->get_view_url();
			$product      = $subscription->get_item_name() . ( $subscription->get_quantity() > 1 ? ' × ' . $subscription->get_quantity() : '' );
			$total        = wp_strip_all_tags( wc_price( $subscription->get_recurring_amount(), array( 'currency' => $subscription->get_currency() ) ) ) . ' ' . $subscription->get_formatted_schedule();
			$status_label = rpsfw_get_subscription_status_label( $subscription->get_status() );
			$gateway      = $subscription->get_gateway_title();
			$customer     = $subscription->get_customer_name();
			$next_label   = '';
			$next_value   = '';

			if ( $subscription->has_status( array( 'active', 'pending-cancel' ) ) && $subscription->get_next_payment_date() ) {
				$next_label = $subscription->has_status( 'pending-cancel' )
					? __( 'Active until', 'restore-paypal-standard-for-woocommerce' )
					: __( 'Next payment', 'restore-paypal-standard-for-woocommerce' );
				$next_value = $subscription->get_date_to_display( 'next_payment' );
			}
		}

		// Subscription cell — linked id (like WCS).
		$subscription_html = $id_url
			? '<a href="' . esc_url( $id_url ) . '">' . esc_html( $id_display ) . '</a>'
			: esc_html( $id_display );

		// Recurring total cell — amount + schedule, with next payment as a sub-line.
		$total_html  = '<strong>' . esc_html( $total ) . '</strong>';
		$total_plain = $total;
		if ( $next_label && $next_value ) {
			$total_html  .= '<br><span style="color:#777;font-size:13px;">' . esc_html( $next_label . ': ' . $next_value ) . '</span>';
			$total_plain .= ' (' . $next_label . ': ' . $next_value . ')';
		}

		// Status cell — label, with the payment method as a sub-line.
		$status_html  = esc_html( $status_label );
		$status_plain = $status_label;
		if ( $gateway ) {
			/* translators: %s: payment gateway title (e.g. PayPal, Stripe) */
			$via          = sprintf( __( 'via %s', 'restore-paypal-standard-for-woocommerce' ), $gateway );
			$status_html  .= '<br><span style="color:#777;font-size:13px;">' . esc_html( $via ) . '</span>';
			$status_plain .= ' (' . $via . ')';
		}

		$columns = array();

		$columns[] = array(
			'heading' => __( 'Subscription', 'restore-paypal-standard-for-woocommerce' ),
			'html'    => $subscription_html,
			'plain'   => $id_display,
		);

		if ( $this->is_admin_email ) {
			$columns[] = array(
				'heading' => __( 'Customer', 'restore-paypal-standard-for-woocommerce' ),
				'html'    => esc_html( $customer ),
				'plain'   => $customer,
			);
		}

		$columns[] = array(
			'heading' => __( 'Product', 'restore-paypal-standard-for-woocommerce' ),
			'html'    => esc_html( $product ),
			'plain'   => $product,
		);

		$columns[] = array(
			'heading' => __( 'Recurring total', 'restore-paypal-standard-for-woocommerce' ),
			'html'    => $total_html,
			'plain'   => $total_plain,
		);

		$columns[] = array(
			'heading' => __( 'Status', 'restore-paypal-standard-for-woocommerce' ),
			'html'    => $status_html,
			'plain'   => $status_plain,
		);

		return $columns;
	}

	/**
	 * The "view subscription" link shown below the table. Falls back to a
	 * sensible page for the preview when no subscription is available.
	 *
	 * @return array|null Array with 'url' and 'label', or null.
	 */
	protected function get_view_link() {
		if ( $this->subscription instanceof RPSFW_Subscription ) {
			$url = $this->is_admin_email
				? get_edit_post_link( $this->subscription->get_id(), 'raw' )
				: $this->subscription->get_view_url();
		} elseif ( $this->is_admin_email ) {
			$url = admin_url();
		} else {
			$url = wc_get_page_permalink( 'myaccount' );
		}

		if ( ! $url ) {
			return null;
		}

		return array(
			'url'   => $url,
			'label' => $this->is_admin_email
				? __( 'View the subscription in your dashboard', 'restore-paypal-standard-for-woocommerce' )
				: __( 'View your subscription', 'restore-paypal-standard-for-woocommerce' ),
		);
	}

	/**
	 * Standard form fields (subject/heading/enabled + admin recipient).
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'restore-paypal-standard-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'restore-paypal-standard-for-woocommerce' ),
				'default' => 'yes',
			),
		);

		if ( $this->is_admin_email ) {
			$this->form_fields['recipient'] = array(
				'title'       => __( 'Recipient(s)', 'restore-paypal-standard-for-woocommerce' ),
				'type'        => 'text',
				/* translators: %s: admin email */
				'description' => sprintf( __( 'Enter recipients (comma separated). Defaults to %s.', 'restore-paypal-standard-for-woocommerce' ), '<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>' ),
				'placeholder' => '',
				'default'     => '',
				'desc_tip'    => true,
			);
		}

		$this->form_fields['subject'] = array(
			'title'       => __( 'Subject', 'restore-paypal-standard-for-woocommerce' ),
			'type'        => 'text',
			'desc_tip'    => true,
			/* translators: %s: list of placeholders */
			'description' => sprintf( __( 'Available placeholders: %s', 'restore-paypal-standard-for-woocommerce' ), '<code>{site_title}, {subscription_id}, {product_name}, {customer_name}</code>' ),
			'placeholder' => $this->get_default_subject(),
			'default'     => '',
		);
		$this->form_fields['heading'] = array(
			'title'       => __( 'Email heading', 'restore-paypal-standard-for-woocommerce' ),
			'type'        => 'text',
			'desc_tip'    => true,
			'description' => __( 'The main heading contained within the email notification.', 'restore-paypal-standard-for-woocommerce' ),
			'placeholder' => $this->get_default_heading(),
			'default'     => '',
		);

		if ( ! $this->is_admin_email ) {
			$this->form_fields['additional_content'] = array(
				'title'       => __( 'Additional content', 'restore-paypal-standard-for-woocommerce' ),
				'description' => __( 'Text to appear below the main email content.', 'restore-paypal-standard-for-woocommerce' ),
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => __( 'N/A', 'restore-paypal-standard-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => $this->get_default_additional_content(),
				'desc_tip'    => true,
			);
		}

		$this->form_fields['email_type'] = array(
			'title'       => __( 'Email type', 'restore-paypal-standard-for-woocommerce' ),
			'type'        => 'select',
			'description' => __( 'Choose which format of email to send.', 'restore-paypal-standard-for-woocommerce' ),
			'default'     => 'html',
			'class'       => 'email_type wc-enhanced-select',
			'options'     => $this->get_email_type_options(),
			'desc_tip'    => true,
		);
	}
}

/**
 * Admin: a new subscription was purchased.
 */
class RPSFW_Email_New_Subscription extends RPSFW_Subscription_Email {

	public function __construct() {
		$this->id             = 'rpsfw_new_subscription';
		$this->title          = __( 'New subscription', 'restore-paypal-standard-for-woocommerce' );
		$this->description    = __( 'Sent to the store admin when a customer purchases a subscription (PayPal & Stripe built-in subscriptions).', 'restore-paypal-standard-for-woocommerce' );
		$this->is_admin_email = true;
		$this->intro_text     = __( 'You have received a new subscription from {customer_name}.', 'restore-paypal-standard-for-woocommerce' );

		add_action( 'rpsfw_subscription_activated_for_order_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}]: New subscription #{subscription_id}', 'restore-paypal-standard-for-woocommerce' );
	}

	public function get_default_heading() {
		return __( 'New subscription', 'restore-paypal-standard-for-woocommerce' );
	}

	/**
	 * Trigger.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param WC_Order           $order        Parent order.
	 */
	public function trigger( $subscription, $order = null ) {
		$this->object = $order;
		$this->trigger_for_subscription( $subscription );
	}
}

/**
 * Admin: a subscription was cancelled.
 */
class RPSFW_Email_Cancelled_Subscription extends RPSFW_Subscription_Email {

	public function __construct() {
		$this->id             = 'rpsfw_cancelled_subscription';
		$this->title          = __( 'Subscription cancelled', 'restore-paypal-standard-for-woocommerce' );
		$this->description    = __( 'Sent to the store admin when a subscription is cancelled (PayPal & Stripe built-in subscriptions).', 'restore-paypal-standard-for-woocommerce' );
		$this->is_admin_email = true;
		$this->preview_status = 'cancelled';
		$this->intro_text     = __( 'The following subscription has been cancelled. No further payments will be collected.', 'restore-paypal-standard-for-woocommerce' );

		add_action( 'rpsfw_subscription_status_cancelled_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}]: Subscription #{subscription_id} cancelled', 'restore-paypal-standard-for-woocommerce' );
	}

	public function get_default_heading() {
		return __( 'Subscription cancelled', 'restore-paypal-standard-for-woocommerce' );
	}

	/**
	 * Trigger.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param string             $old_status   Previous status.
	 */
	public function trigger( $subscription, $old_status = '' ) {
		$this->trigger_for_subscription( $subscription );
	}
}

/**
 * Customer: your subscription was cancelled.
 */
class RPSFW_Email_Customer_Subscription_Cancelled extends RPSFW_Subscription_Email {

	public function __construct() {
		$this->id             = 'rpsfw_customer_subscription_cancelled';
		$this->title          = __( 'Subscription cancelled (customer)', 'restore-paypal-standard-for-woocommerce' );
		$this->description    = __( 'Sent to the customer confirming their subscription has been cancelled (PayPal & Stripe built-in subscriptions).', 'restore-paypal-standard-for-woocommerce' );
		$this->customer_email = true;
		$this->preview_status = 'cancelled';
		$this->intro_text     = __( 'Your subscription has been cancelled. You will not be billed again.', 'restore-paypal-standard-for-woocommerce' );

		add_action( 'rpsfw_subscription_status_cancelled_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your {site_title} subscription has been cancelled', 'restore-paypal-standard-for-woocommerce' );
	}

	public function get_default_heading() {
		return __( 'Subscription cancelled', 'restore-paypal-standard-for-woocommerce' );
	}

	public function get_default_additional_content() {
		return __( 'If this was a mistake or you have any questions, please contact us.', 'restore-paypal-standard-for-woocommerce' );
	}

	/**
	 * Trigger.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param string             $old_status   Previous status.
	 */
	public function trigger( $subscription, $old_status = '' ) {
		$this->trigger_for_subscription( $subscription );
	}
}

/**
 * Customer: a renewal payment failed.
 */
class RPSFW_Email_Customer_Payment_Failed extends RPSFW_Subscription_Email {

	public function __construct() {
		$this->id             = 'rpsfw_customer_payment_failed';
		$this->title          = __( 'Subscription payment failed (customer)', 'restore-paypal-standard-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when a subscription renewal payment fails. The payment processor retries automatically (PayPal & Stripe built-in subscriptions).', 'restore-paypal-standard-for-woocommerce' );
		$this->customer_email = true;
		$this->intro_text     = __( 'A renewal payment for your subscription could not be processed. Your payment provider will retry automatically — please make sure your payment method is up to date to keep your subscription active.', 'restore-paypal-standard-for-woocommerce' );

		add_action( 'rpsfw_subscription_payment_failed_notification', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Payment problem with your {site_title} subscription', 'restore-paypal-standard-for-woocommerce' );
	}

	public function get_default_heading() {
		return __( 'Renewal payment failed', 'restore-paypal-standard-for-woocommerce' );
	}

	public function get_default_additional_content() {
		return __( 'If you need help updating your payment details, please contact us.', 'restore-paypal-standard-for-woocommerce' );
	}

	/**
	 * Trigger.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 */
	public function trigger( $subscription ) {
		$this->trigger_for_subscription( $subscription );
	}
}

/**
 * Customer: subscription ended (all payments collected).
 */
class RPSFW_Email_Customer_Subscription_Expired extends RPSFW_Subscription_Email {

	public function __construct() {
		$this->id             = 'rpsfw_customer_subscription_expired';
		$this->title          = __( 'Subscription ended (customer)', 'restore-paypal-standard-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when their subscription ends after collecting all scheduled payments (PayPal & Stripe built-in subscriptions).', 'restore-paypal-standard-for-woocommerce' );
		$this->customer_email = true;
		$this->preview_status = 'expired';
		$this->intro_text     = __( 'Your subscription has ended — all scheduled payments have been completed. Thank you!', 'restore-paypal-standard-for-woocommerce' );

		add_action( 'rpsfw_subscription_status_expired_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your {site_title} subscription has ended', 'restore-paypal-standard-for-woocommerce' );
	}

	public function get_default_heading() {
		return __( 'Subscription ended', 'restore-paypal-standard-for-woocommerce' );
	}

	public function get_default_additional_content() {
		return __( 'We hope to see you again soon.', 'restore-paypal-standard-for-woocommerce' );
	}

	/**
	 * Trigger.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param string             $old_status   Previous status.
	 */
	public function trigger( $subscription, $old_status = '' ) {
		$this->trigger_for_subscription( $subscription );
	}
}
