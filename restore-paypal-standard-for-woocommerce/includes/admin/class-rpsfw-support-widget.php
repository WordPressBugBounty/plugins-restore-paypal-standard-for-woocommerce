<?php
/**
 * Support contact widget.
 *
 * A launcher button in the bottom-right corner of this plugin's own admin
 * screens that opens a small chat-style panel with a contact form. Submitting
 * it emails the plugin's support address.
 *
 * Deliberately limited to this plugin's screens (its settings hub and the three
 * gateway settings pages) — it is support for the plugin, not for the store, so
 * it has no business following the merchant around the rest of wp-admin, and it
 * never appears on the front end.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Support_Widget
 */
class RPSFW_Support_Widget {

	/**
	 * Where to send the merchant when the helpdesk cannot be reached from here.
	 */
	const SUPPORT_URL = 'https://wpplugin.org/support/';

	/**
	 * Helpdesk REST base. Requests open a ticket directly, so support does not
	 * depend on this store being able to send email - which matters, because a
	 * store with broken mail is exactly the one whose owner needs help.
	 */
	const API_BASE = 'https://wpplugin.org/wp-json/rpsfw-support/v1';

	/**
	 * Shared enrolment key, used ONCE to obtain this store's own signing key.
	 *
	 * This ships in a public plugin, so it is not a secret: it exists to turn
	 * away drive-by bots, not determined abuse. The per-site key issued at
	 * registration is the real credential - it never leaves this install and
	 * can be revoked on its own.
	 */
	const ENROLMENT_KEY = 'rpsfw_enrol_5f3c9a1d7b2e48c6a0d94f1e6b8c3702';

	/**
	 * Option holding this store's issued credentials.
	 */
	const CREDENTIALS_OPTION = 'rpsfw_support_credentials';

	/**
	 * AJAX action name.
	 */
	const AJAX_ACTION = 'rpsfw_send_support_message';

	/**
	 * Nonce action.
	 */
	const NONCE_ACTION = 'rpsfw-support-widget';

	/**
	 * Minimum seconds between two messages from the same user. Stops a stuck
	 * button or an impatient double-click turning into a flood of duplicate
	 * tickets.
	 */
	const THROTTLE_SECONDS = 30;

	/**
	 * Wire up.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax' ) );
	}

	/**
	 * Whether the current screen is one of this plugin's own.
	 *
	 * @return bool
	 */
	private static function is_plugin_screen() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the current screen, not acting on input.
		$page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'rpsfw-settings-hub' === $page ) {
			return true;
		}

		return ( 'wc-settings' === $page && 'checkout' === $tab && in_array( $section, self::gateway_ids(), true ) );
	}

	/**
	 * This plugin's gateway ids, in the order the dropdown should list them.
	 *
	 * @return array id => label
	 */
	private static function gateways() {
		return array(
			'restore_paypal_standard' => __( 'PayPal Standard', 'restore-paypal-standard-for-woocommerce' ),
			'rpsfw_paypal_commerce'   => __( 'PayPal Commerce', 'restore-paypal-standard-for-woocommerce' ),
			'rpsfw_stripe'            => __( 'Stripe', 'restore-paypal-standard-for-woocommerce' ),
		);
	}

	/**
	 * Gateway ids only.
	 *
	 * @return array
	 */
	private static function gateway_ids() {
		return array_keys( self::gateways() );
	}

	/**
	 * Cache-busting version for an asset.
	 *
	 * Uses the file's modification time rather than the plugin version, so an
	 * edited stylesheet or script is picked up immediately instead of serving a
	 * stale copy under an unchanged ?ver=. Falls back to the plugin version if
	 * the file cannot be read.
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string
	 */
	private static function asset_version( $relative_path ) {
		$file = RPSFW_PLUGIN_DIR . $relative_path;

		return file_exists( $file ) ? (string) filemtime( $file ) : RPSFW_VERSION;
	}

	/**
	 * Enqueue assets on this plugin's screens only.
	 */
	public static function enqueue() {
		if ( ! self::is_plugin_screen() ) {
			return;
		}

		wp_enqueue_style(
			'rpsfw-support-widget',
			RPSFW_PLUGIN_URL . 'assets/css/support-widget.css',
			array(),
			self::asset_version( 'assets/css/support-widget.css' )
		);

		wp_enqueue_script(
			'rpsfw-support-widget',
			RPSFW_PLUGIN_URL . 'assets/js/support-widget.js',
			array( 'jquery' ),
			self::asset_version( 'assets/js/support-widget.js' ),
			true
		);

		wp_localize_script(
			'rpsfw-support-widget',
			'rpsfwSupport',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'sending'    => __( 'Sending…', 'restore-paypal-standard-for-woocommerce' ),
					'send'       => __( 'Send', 'restore-paypal-standard-for-woocommerce' ),
					'incomplete' => __( 'Please fill in every field before sending.', 'restore-paypal-standard-for-woocommerce' ),
					'badEmail'   => __( 'That email address does not look right.', 'restore-paypal-standard-for-woocommerce' ),
					'failed'     => sprintf(
						/* translators: %s: support site URL */
						__( 'Your message could not be sent. Please try again, or open a ticket at %s.', 'restore-paypal-standard-for-woocommerce' ),
						self::SUPPORT_URL
					),
				),
			)
		);
	}

	/**
	 * Render the launcher and panel markup in the admin footer.
	 */
	public static function render() {
		if ( ! self::is_plugin_screen() ) {
			return;
		}

		$user = wp_get_current_user();
		?>
		<div id="rpsfw-support" class="rpsfw-support is-closed">
			<button type="button" class="rpsfw-support-launcher" aria-expanded="false" aria-controls="rpsfw-support-panel">
				<span class="rpsfw-support-launcher-icon" aria-hidden="true">?</span>
				<span class="rpsfw-support-launcher-text"><?php esc_html_e( 'Contact Support', 'restore-paypal-standard-for-woocommerce' ); ?></span>
			</button>

			<div class="rpsfw-support-panel" id="rpsfw-support-panel" role="dialog" aria-modal="false" aria-label="<?php esc_attr_e( 'Contact Support', 'restore-paypal-standard-for-woocommerce' ); ?>">
				<div class="rpsfw-support-header">
					<span class="rpsfw-support-title"><?php esc_html_e( 'Contact Support', 'restore-paypal-standard-for-woocommerce' ); ?></span>
					<button type="button" class="rpsfw-support-minimize" aria-label="<?php esc_attr_e( 'Minimize', 'restore-paypal-standard-for-woocommerce' ); ?>">&minus;</button>
				</div>

				<div class="rpsfw-support-body">
					<p class="rpsfw-support-intro"><?php esc_html_e( 'Send us a message and we will reply by email.', 'restore-paypal-standard-for-woocommerce' ); ?></p>

					<div class="rpsfw-support-notice" role="alert" hidden></div>

					<form class="rpsfw-support-form">
						<p>
							<label for="rpsfw-support-name"><?php esc_html_e( 'Your name', 'restore-paypal-standard-for-woocommerce' ); ?></label>
							<input type="text" id="rpsfw-support-name" name="name" value="<?php echo esc_attr( $user ? $user->display_name : '' ); ?>" autocomplete="name">
						</p>
						<p>
							<label for="rpsfw-support-email"><?php esc_html_e( 'Email address', 'restore-paypal-standard-for-woocommerce' ); ?></label>
							<input type="email" id="rpsfw-support-email" name="email" value="<?php echo esc_attr( $user ? $user->user_email : '' ); ?>" autocomplete="email">
						</p>
						<p>
							<label for="rpsfw-support-site"><?php esc_html_e( 'Site URL (optional)', 'restore-paypal-standard-for-woocommerce' ); ?></label>
							<input type="url" id="rpsfw-support-site" name="site_url" value="<?php echo esc_url( home_url() ); ?>" autocomplete="url">
						</p>
						<p>
							<label for="rpsfw-support-gateway"><?php esc_html_e( 'Payment processor', 'restore-paypal-standard-for-woocommerce' ); ?></label>
							<select id="rpsfw-support-gateway" name="gateway" required>
								<option value=""><?php esc_html_e( 'Choose one…', 'restore-paypal-standard-for-woocommerce' ); ?></option>
								<?php foreach ( self::gateways() as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="rpsfw-support-message"><?php esc_html_e( 'How can we help you?', 'restore-paypal-standard-for-woocommerce' ); ?></label>
							<textarea id="rpsfw-support-message" name="message" rows="5"></textarea>
						</p>
						<p class="rpsfw-support-actions">
							<button type="submit" class="button button-primary rpsfw-support-send"><?php esc_html_e( 'Send', 'restore-paypal-standard-for-woocommerce' ); ?></button>
						</p>
						<p class="rpsfw-support-privacy description">
							<?php esc_html_e( 'No details about your site are shared with us other than the information visible in this form.', 'restore-paypal-standard-for-woocommerce' ); ?>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle the form submission and send the email.
	 */
	public static function handle_ajax() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'restore-paypal-standard-for-woocommerce' ) ) );
		}

		$user_id   = get_current_user_id();
		$throttle  = 'rpsfw_support_sent_' . $user_id;
		if ( get_transient( $throttle ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You just sent a message. Please wait a moment before sending another.', 'restore-paypal-standard-for-woocommerce' ),
				)
			);
		}

		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';
		$gateway  = isset( $_POST['gateway'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway'] ) ) : '';
		$message  = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		$gateways = self::gateways();

		// The processor has no default - support needs to know which one this
		// is about, so it must be chosen rather than inherited from whichever
		// option happened to be first.
		if ( '' === $name || '' === $message || ! isset( $gateways[ $gateway ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please fill in every field before sending.', 'restore-paypal-standard-for-woocommerce' ) ) );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'That email address does not look right.', 'restore-paypal-standard-for-woocommerce' ) ) );
		}

		$gateway_label = $gateways[ $gateway ];

		// Preferred path: open a ticket in the helpdesk directly.
		$ticket_id = self::create_remote_ticket( $name, $email, $site_url, $gateway_label, $message );

		if ( $ticket_id > 0 ) {
			set_transient( $throttle, 1, self::THROTTLE_SECONDS );

			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %d: helpdesk ticket number */
						__( 'Thanks — ticket #%d has been created. We will reply to the email address you gave.', 'restore-paypal-standard-for-woocommerce' ),
						$ticket_id
					),
				)
			);
		}

		// The helpdesk could not be reached, this store cannot make outbound
		// requests, or it has been revoked. There is no email path - point the
		// merchant at the support site instead of failing silently.
		wp_send_json_error(
			array(
				/* translators: %s: support site URL */
				'message' => sprintf(
					__( 'Your message could not be sent. Please try again, or open a ticket at %s.', 'restore-paypal-standard-for-woocommerce' ),
					self::SUPPORT_URL
				),
			)
		);
	}

	/**
	 * Open a ticket in the helpdesk.
	 *
	 * @param string $name          Sender name.
	 * @param string $email         Sender email.
	 * @param string $site_url      Site URL, may be ''.
	 * @param string $gateway_label Gateway chosen.
	 * @param string $message       Message body.
	 * @return int Ticket id, or 0 when the helpdesk could not be used.
	 */
	private static function create_remote_ticket( $name, $email, $site_url, $gateway_label, $message ) {
		$credentials = self::get_credentials();
		if ( ! $credentials ) {
			return 0;
		}

		$payload = wp_json_encode(
			array(
				'name'     => $name,
				'email'    => $email,
				'site_url' => $site_url,
				'gateway'  => $gateway_label,
				'message'  => $message,
				'hp'       => '',
			)
		);

		$response = self::signed_post(
			self::API_BASE . '/ticket',
			$payload,
			$credentials['site_key'],
			array( 'X-RPSFW-Site' => $credentials['site_id'] )
		);

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// A rejected key usually means the record was rebuilt on the helpdesk
		// (or this site was restored from a backup). Drop the stored
		// credentials so the next attempt re-enrols cleanly.
		if ( 401 === $code ) {
			delete_option( self::CREDENTIALS_OPTION );
			return 0;
		}

		if ( 200 !== $code || empty( $body['ticket_id'] ) ) {
			return 0;
		}

		return (int) $body['ticket_id'];
	}

	/**
	 * This store's helpdesk credentials, enrolling on first use.
	 *
	 * @return array|false { site_id, site_key }
	 */
	private static function get_credentials() {
		$credentials = get_option( self::CREDENTIALS_OPTION );
		if ( is_array( $credentials ) && ! empty( $credentials['site_id'] ) && ! empty( $credentials['site_key'] ) ) {
			return $credentials;
		}

		// Enrolment deliberately sends NOTHING identifying - no site URL, no
		// name, nothing. The helpdesk mints an opaque id and a key and hands
		// them back, so it never learns this store's address unless the
		// merchant types it into the form themselves. That is what makes the
		// promise under the Send button true.
		$response = self::signed_post(
			self::API_BASE . '/register',
			wp_json_encode( array() ),
			self::ENROLMENT_KEY
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['site_id'] ) || empty( $body['site_key'] ) ) {
			return false;
		}

		$credentials = array(
			'site_id'  => sanitize_text_field( $body['site_id'] ),
			'site_key' => sanitize_text_field( $body['site_key'] ),
		);

		update_option( self::CREDENTIALS_OPTION, $credentials, false );

		return $credentials;
	}

	/**
	 * POST a signed request.
	 *
	 * The signature covers "<timestamp>.<nonce>.<body>" so the payload cannot
	 * be altered in transit and the request cannot be replayed. The timestamp
	 * is Unix epoch (UTC everywhere, regardless of this store's timezone).
	 *
	 * @param string $url     Endpoint.
	 * @param string $payload JSON body.
	 * @param string $key     Signing key.
	 * @param array  $headers Extra headers.
	 * @return array|WP_Error
	 */
	private static function signed_post( $url, $payload, $key, $headers = array() ) {
		$timestamp = time();
		$nonce     = wp_generate_password( 24, false );

		$headers = array_merge(
			array(
				'Content-Type'      => 'application/json',
				'X-RPSFW-Timestamp' => (string) $timestamp,
				'X-RPSFW-Nonce'     => $nonce,
				'X-RPSFW-Signature' => hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $payload, $key ),
			),
			$headers
		);

		return wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $payload,
			)
		);
	}

}

RPSFW_Support_Widget::init();
