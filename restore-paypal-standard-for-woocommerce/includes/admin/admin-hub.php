<?php
/**
 * Admin Hub Page for Accept PayPal & Stripe with Subscriptions for WooCommerce
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the admin hub menu page under WooCommerce
 */
function rpsfw_register_admin_hub_menu() {
    add_submenu_page(
        'woocommerce',
        __( 'Accept PayPal & Stripe Settings', 'restore-paypal-standard-for-woocommerce' ),
        __( 'Accept PayPal & Stripe Settings', 'restore-paypal-standard-for-woocommerce' ),
        'manage_woocommerce',
        'rpsfw-settings-hub',
        'rpsfw_render_admin_hub_page'
    );
}
add_action( 'admin_menu', 'rpsfw_register_admin_hub_menu', 60 );

/**
 * Check if a gateway is enabled and configured
 *
 * @param string $gateway_id Gateway ID
 * @return array Status array with 'enabled', 'configured', and 'testmode' keys
 */
function rpsfw_get_gateway_status( $gateway_id ) {
    $settings = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );
    
    $status = array(
        'enabled' => isset( $settings['enabled'] ) && $settings['enabled'] === 'yes',
        'configured' => false,
        'testmode' => isset( $settings['testmode'] ) && $settings['testmode'] === 'yes',
    );
    
    // Check configuration based on gateway type
    switch ( $gateway_id ) {
        case 'restore_paypal_standard':
            // The gateway pays out to sandbox_email in test mode and email in
            // live mode, so "configured" has to follow the field the gateway
            // would actually use. Checking 'email' alone reported a store that
            // is set up in test mode as unconfigured while it took payments.
            $status['configured'] = $status['testmode']
                ? ! empty( $settings['sandbox_email'] )
                : ! empty( $settings['email'] );

            // Either mode being set up also counts, matching the Stripe and
            // PayPal Commerce cases below.
            if ( ! $status['configured'] ) {
                $status['configured'] = ! empty( $settings['email'] ) || ! empty( $settings['sandbox_email'] );
            }
            break;
            
        case 'rpsfw_paypal_commerce':
            // PayPal Commerce is configured if seller_id is set in ppcp_onboarding for either environment
            $onboarding = isset( $settings['ppcp_onboarding'] ) ? $settings['ppcp_onboarding'] : array();
            $env = $status['testmode'] ? 'sandbox' : 'live';
            
            // Check current environment first, then check both
            $status['configured'] = ! empty( $onboarding[ $env ]['seller_id'] ) || 
                                   ! empty( $onboarding['live']['seller_id'] ) || 
                                   ! empty( $onboarding['sandbox']['seller_id'] );
            break;
            
        case 'rpsfw_stripe':
            // Stripe is configured if account ID is set for either test or live mode
            // Check current mode first
            if ( $status['testmode'] ) {
                $status['configured'] = ! empty( $settings['acct_id_test'] );
            } else {
                $status['configured'] = ! empty( $settings['acct_id_live'] );
            }
            
            // Also check if either mode is configured
            if ( ! $status['configured'] ) {
                $status['configured'] = ! empty( $settings['acct_id_test'] ) || ! empty( $settings['acct_id_live'] );
            }
            break;
    }
    
    return $status;
}

/**
 * Render status badge HTML
 *
 * @param array $status Status array from rpsfw_get_gateway_status
 * @return string HTML for status badge
 */
function rpsfw_render_status_badge( $status ) {
    $html = '<span class="rpsfw-status-badges">';
    
    // Status badge first
    if ( $status['enabled'] && $status['configured'] ) {
        $html .= '<span class="rpsfw-status-badge rpsfw-status-enabled">' . esc_html__( 'Enabled', 'restore-paypal-standard-for-woocommerce' ) . '</span>';
    } elseif ( $status['configured'] ) {
        $html .= '<span class="rpsfw-status-badge rpsfw-status-disabled">' . esc_html__( 'Disabled', 'restore-paypal-standard-for-woocommerce' ) . '</span>';
    } else {
        $html .= '<span class="rpsfw-status-badge rpsfw-status-not-configured">' . esc_html__( 'Not Configured', 'restore-paypal-standard-for-woocommerce' ) . '</span>';
    }
    
    // Mode badge (only shown if configured AND enabled)
    if ( $status['configured'] && $status['enabled'] ) {
        if ( $status['testmode'] ) {
            $html .= '<span class="rpsfw-status-badge rpsfw-status-testmode">' . esc_html__( 'Test Mode', 'restore-paypal-standard-for-woocommerce' ) . '</span>';
        } else {
            $html .= '<span class="rpsfw-status-badge rpsfw-status-livemode">' . esc_html__( 'Live Mode', 'restore-paypal-standard-for-woocommerce' ) . '</span>';
        }
    }
    
    $html .= '</span>';
    
    return $html;
}

/**
 * Render the admin hub page
 */
function rpsfw_render_admin_hub_page() {
    // Build settings URLs
    $paypal_standard_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=restore_paypal_standard' );
    $paypal_commerce_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=rpsfw_paypal_commerce' );
    $stripe_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=rpsfw_stripe' );
    
    // Get gateway statuses
    $paypal_standard_status = rpsfw_get_gateway_status( 'restore_paypal_standard' );
    $paypal_commerce_status = rpsfw_get_gateway_status( 'rpsfw_paypal_commerce' );
    $stripe_status = rpsfw_get_gateway_status( 'rpsfw_stripe' );
    
    // External links
    $documentation_url = 'https://wpplugin.org/documentation/';
    $support_url = 'https://wordpress.org/support/plugin/restore-paypal-standard-for-woocommerce/';
    ?>
    <div class="wrap rpsfw-admin-hub">
        <h1><?php echo esc_html( RPSFW_PLUGIN_NAME ); ?> <span class="rpsfw-version-badge">v<?php echo esc_html( RPSFW_VERSION ); ?></span></h1>

        <div class="rpsfw-hub-top">
            <div class="rpsfw-hub-new-callout">
                <span class="rpsfw-hub-new-badge"><?php esc_html_e( 'New in version 4', 'restore-paypal-standard-for-woocommerce' ); ?></span>
                <p><?php esc_html_e( 'This plugin now includes 3 different gateways, PayPal Standard, PayPal Commerce, and Stripe.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
                <p><?php esc_html_e( 'Before this version, this plugin only included PayPal Standard. If you would like to keep using PayPal Standard only, you can do that. The other gateways will not be enabled unless you choose to, and we won\'t be removing PayPal Standard in any future release.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
            </div>

            <div class="rpsfw-hub-note-box">
                <div class="rpsfw-hub-note-nav">
                    <button type="button" class="rpsfw-hub-note-arrow" data-dir="prev" aria-label="<?php esc_attr_e( 'Previous text', 'restore-paypal-standard-for-woocommerce' ); ?>">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                    </button>
                    <button type="button" class="rpsfw-hub-note-arrow" data-dir="next" aria-label="<?php esc_attr_e( 'Next text', 'restore-paypal-standard-for-woocommerce' ); ?>">
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </button>
                </div>

                <div class="rpsfw-hub-note-views">
                    <div class="rpsfw-hub-note-view is-active" data-view="1">
                        <h2 class="rpsfw-hub-note-title"><?php esc_html_e( 'Completely free. No catch.', 'restore-paypal-standard-for-woocommerce' ); ?></h2>
                        <p class="rpsfw-hub-note"><?php esc_html_e( 'This plugin is 100% free. There are no setup fees, no transaction fees added by us, and no monthly charges. What you see is what you get.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
                        <p class="rpsfw-hub-note"><?php esc_html_e( 'There is no "Pro" or premium version locked behind a paywall, and no features held back to upsell you later. Every gateway and every feature is included for everyone, at no cost.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
                    </div>

                    <div class="rpsfw-hub-note-view" data-view="2">
                        <h2 class="rpsfw-hub-note-title"><?php esc_html_e( 'Payments that keep running, without disruption.', 'restore-paypal-standard-for-woocommerce' ); ?></h2>
                        <p class="rpsfw-hub-note"><?php esc_html_e( 'With many WordPress payment plugins, recurring subscription charges aren\'t actually initiated by PayPal or Stripe. They\'re triggered by WooCommerce, the plugin itself, or a middleman server run by the plugin vendor. That means your payments depend on that vendor\'s infrastructure staying up and working - and if it doesn\'t, neither do your charges.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
                        <p class="rpsfw-hub-note"><?php esc_html_e( 'With our plugin, there\'s no middleman. The subscription lives directly in PayPal or Stripe, and the charge is initiated by them. That matters more than you might think - if you ever decide to stop using WooCommerce or our plugin, or if something happens to your website, your customers\' subscriptions will keep processing without any interruption. Nothing breaks or needs to be migrated. Your business keeps running and your customers keep paying.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="rpsfw-hub-grid">
            <!-- PayPal Standard -->
            <div class="rpsfw-hub-box">
                <div class="rpsfw-hub-box-header">
                    <div class="rpsfw-hub-box-icon">
                        <img src="<?php echo esc_url( RPSFW_PLUGIN_URL . 'assets/images/paypal-logo.svg' ); ?>" alt="PayPal" class="rpsfw-gateway-logo">
                    </div>
                    <?php echo wp_kses_post( rpsfw_render_status_badge( $paypal_standard_status ) ); ?>
                </div>
                <h2><?php esc_html_e( 'PayPal Standard', 'restore-paypal-standard-for-woocommerce' ); ?></h2>
                <p><?php esc_html_e( 'Classic PayPal checkout that redirects customers to PayPal to complete payment.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
                <a href="<?php echo esc_url( $paypal_standard_url ); ?>" class="button button-primary"><?php esc_html_e( 'Configure', 'restore-paypal-standard-for-woocommerce' ); ?></a>
            </div>
            
            <!-- PayPal Commerce -->
            <div class="rpsfw-hub-box">
                <div class="rpsfw-hub-box-header">
                    <div class="rpsfw-hub-box-icon">
                        <img src="<?php echo esc_url( RPSFW_PLUGIN_URL . 'assets/images/paypal-logo.svg' ); ?>" alt="PayPal" class="rpsfw-gateway-logo">
                    </div>
                    <?php echo wp_kses_post( rpsfw_render_status_badge( $paypal_commerce_status ) ); ?>
                </div>
                <h2><?php esc_html_e( 'PayPal Commerce', 'restore-paypal-standard-for-woocommerce' ); ?></h2>
                <p><?php esc_html_e( 'Advanced PayPal integration with on-site checkout, Pay Later, and card processing.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
                <a href="<?php echo esc_url( $paypal_commerce_url ); ?>" class="button button-primary"><?php esc_html_e( 'Configure', 'restore-paypal-standard-for-woocommerce' ); ?></a>
            </div>
            
            <!-- Stripe -->
            <div class="rpsfw-hub-box">
                <div class="rpsfw-hub-box-header">
                    <div class="rpsfw-hub-box-icon">
                        <img src="<?php echo esc_url( RPSFW_PLUGIN_URL . 'assets/images/stripe-logo.png' ); ?>" alt="Stripe" class="rpsfw-gateway-logo">
                    </div>
                    <?php echo wp_kses_post( rpsfw_render_status_badge( $stripe_status ) ); ?>
                </div>
                <h2><?php esc_html_e( 'Stripe', 'restore-paypal-standard-for-woocommerce' ); ?></h2>
                <p><?php esc_html_e( 'Accept credit cards, debit cards, and other payment methods via Stripe.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
                <a href="<?php echo esc_url( $stripe_url ); ?>" class="button button-primary"><?php esc_html_e( 'Configure', 'restore-paypal-standard-for-woocommerce' ); ?></a>
            </div>

        </div>

        <!-- Subscriptions (built-in) with Community Support and Leave a Review -->
        <div class="rpsfw-hub-grid rpsfw-hub-grid-subscriptions">
            <?php if ( class_exists( 'RPSFW_Subscriptions_Admin' ) ) : ?>
                <?php RPSFW_Subscriptions_Admin::render_hub_card(); ?>
            <?php endif; ?>

            <!-- Community Support -->
            <div class="rpsfw-hub-box rpsfw-hub-box-secondary">
                <div class="rpsfw-hub-box-icon">
                    <span class="dashicons dashicons-sos rpsfw-support-icon"></span>
                </div>
                <h2><?php esc_html_e( 'Community Support', 'restore-paypal-standard-for-woocommerce' ); ?></h2>
                <p><?php esc_html_e( 'Need help? Submit a support request and we\'ll assist you.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
                <a href="<?php echo esc_url( $support_url ); ?>" class="button" target="_blank"><?php esc_html_e( 'Get Support', 'restore-paypal-standard-for-woocommerce' ); ?></a>
            </div>

            <!-- Leave a Review -->
            <div class="rpsfw-hub-box rpsfw-hub-box-secondary">
                <div class="rpsfw-hub-box-icon">
                    <span class="dashicons dashicons-star-filled rpsfw-star-icon"></span>
                </div>
                <h2><?php esc_html_e( 'Leave a Review', 'restore-paypal-standard-for-woocommerce' ); ?></h2>
                <p><?php esc_html_e( 'Enjoying the plugin? A lot of work went into this 100% free plugin. Please leave us a review!', 'restore-paypal-standard-for-woocommerce' ); ?></p>
                <a href="https://wordpress.org/support/plugin/restore-paypal-standard-for-woocommerce/reviews/" class="button" target="_blank"><?php esc_html_e( 'Leave a Review', 'restore-paypal-standard-for-woocommerce' ); ?></a>
            </div>
        </div>

        <hr class="rpsfw-hub-divider">

        <!-- A Company You Can Trust — centered on its own row -->
        <div class="rpsfw-hub-grid-trust">
            <div class="rpsfw-hub-box rpsfw-hub-box-secondary rpsfw-hub-box-trust">
                <div class="rpsfw-hub-box-icon">
                    <span class="dashicons dashicons-flag rpsfw-flag-icon"></span>
                </div>
                <h2><?php esc_html_e( 'A Company You Can Trust', 'restore-paypal-standard-for-woocommerce' ); ?></h2>
                <p><?php esc_html_e( 'Founded in 2015, we are an official PayPal and Stripe partner. Our software is built in the United States, and our support team is based in the United States too.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
                <a href="https://wpplugin.org" class="button" target="_blank"><?php esc_html_e( 'View Our Website', 'restore-paypal-standard-for-woocommerce' ); ?></a>
            </div>
        </div>
    </div>
    
    <style>
        .rpsfw-admin-hub {
            max-width: 1200px;
        }
        .rpsfw-version-badge {
            font-size: 12px;
            font-weight: 400;
            color: #555;
            vertical-align: middle;
        }
        .rpsfw-hub-description {
            font-size: 14px;
            color: #646970;
            margin-bottom: 8px;
        }
        .rpsfw-hub-top {
            display: grid;
            grid-template-columns: calc((100% - 40px) / 3) 1fr;
            gap: 20px;
            align-items: stretch;
            margin: 0 0 24px;
        }
        @media screen and (max-width: 782px) {
            .rpsfw-hub-top {
                grid-template-columns: 1fr;
            }
        }
        .rpsfw-hub-note-box {
            position: relative;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            padding: 20px 24px;
        }
        .rpsfw-hub-note-box .rpsfw-hub-note-title {
            margin-top: 0;
            padding-right: 72px;
        }
        .rpsfw-hub-note-nav {
            position: absolute;
            top: 12px;
            right: 12px;
            display: flex;
            gap: 6px;
            z-index: 2;
        }
        .rpsfw-hub-note-arrow {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            padding: 0;
            background: #f6f7f7;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            color: #2271b1;
            cursor: pointer;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .rpsfw-hub-note-arrow:hover {
            background: #2271b1;
            color: #fff;
        }
        .rpsfw-hub-note-arrow .dashicons {
            font-size: 18px;
            width: 18px;
            height: 18px;
            line-height: 18px;
        }
        .rpsfw-hub-note-views {
            display: grid;
        }
        .rpsfw-hub-note-view {
            grid-area: 1 / 1;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.6s ease, visibility 0.6s ease;
        }
        .rpsfw-hub-note-view.is-active {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .rpsfw-hub-note-view .rpsfw-hub-note:last-child {
            margin-bottom: 0;
        }
        .rpsfw-hub-note {
            font-size: 13px;
            color: #646970;
            line-height: 1.6;
            margin-bottom: 16px;
        }
        .rpsfw-hub-note-box .rpsfw-hub-note:last-child {
            margin-bottom: 0;
        }
        .rpsfw-hub-new-callout {
            position: relative;
            background: #f0f6fc;
            border: 1px solid #2271b1;
            border-radius: 4px;
            padding: 20px 24px 16px;
        }
        .rpsfw-hub-new-badge {
            display: inline-block;
            background: #2271b1;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 10px;
            border-radius: 3px;
            margin-bottom: 10px;
        }
        .rpsfw-hub-new-callout p {
            font-size: 14px;
            color: #1d2327;
            line-height: 1.6;
            margin: 0 0 10px;
        }
        .rpsfw-hub-new-callout p:last-child {
            margin-bottom: 0;
        }
        .rpsfw-hub-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        .rpsfw-hub-grid-subscriptions {
            margin-top: 20px;
        }
        .rpsfw-hub-grid-trust {
            display: flex;
            justify-content: center;
        }
        .rpsfw-hub-box-trust {
            max-width: 520px;
            width: 100%;
            text-align: center;
            align-items: center;
        }
        .rpsfw-hub-box-trust .rpsfw-hub-box-icon {
            margin-bottom: 8px;
        }
        .rpsfw-hub-box-trust .button {
            align-self: center;
        }
        .rpsfw-hub-box {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            padding: 24px;
            display: flex;
            flex-direction: column;
        }
        .rpsfw-hub-box-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .rpsfw-hub-box-icon {
            margin-bottom: 0;
        }
        .rpsfw-hub-box-icon .dashicons {
            font-size: 32px;
            width: 32px;
            height: 32px;
            color: #2271b1;
        }
        .rpsfw-gateway-logo {
            height: 28px;
            width: auto;
            max-width: 100px;
        }
        .rpsfw-hub-box h2 {
            margin: 0 0 8px 0;
            font-size: 16px;
            font-weight: 600;
        }
        .rpsfw-hub-box p {
            color: #646970;
            margin: 0 0 16px 0;
            flex-grow: 1;
        }
        .rpsfw-hub-box .button {
            align-self: flex-start;
        }
        .rpsfw-hub-box-secondary .rpsfw-hub-box-icon .dashicons {
            color: #646970;
        }
        .rpsfw-hub-box-secondary .rpsfw-hub-box-icon .rpsfw-docs-icon {
            color: #2271b1;
        }
        .rpsfw-hub-box-secondary .rpsfw-hub-box-icon .rpsfw-support-icon {
            color: #00a32a;
        }
        .rpsfw-hub-box-secondary .rpsfw-hub-box-icon .rpsfw-star-icon {
            color: #ffb900;
        }
        /* Status badges */
        .rpsfw-status-badges {
            display: flex;
            gap: 4px;
        }
        .rpsfw-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            line-height: 1;
            border: 1px solid #c3c4c7;
        }
        .rpsfw-status-enabled {
            background: #d4edda;
            color: #155724;
        }
        .rpsfw-status-disabled {
            background: #fff3cd;
            color: #856404;
        }
        .rpsfw-status-not-configured {
            background: #f8d7da;
            color: #721c24;
        }
        .rpsfw-status-testmode {
            background: #fff3cd;
            color: #856404;
        }
        .rpsfw-status-livemode {
            background: #d4edda;
            color: #155724;
        }
        .rpsfw-status-coming-soon {
            background: #e2e3e5;
            color: #383d41;
        }
        .rpsfw-hub-divider {
            border: none;
            margin: 12px 0;
        }
        .rpsfw-hub-box-secondary .rpsfw-hub-box-icon .rpsfw-flag-icon {
            color: #2271b1;
        }
        .rpsfw-partner-list {
            margin: 0;
            padding: 0;
            list-style: none;
            color: #646970;
            font-size: 13px;
            line-height: 1.8;
        }
    </style>
    <script>
        ( function() {
            var box = document.querySelector( '.rpsfw-hub-note-box' );
            if ( ! box ) {
                return;
            }
            var views = box.querySelectorAll( '.rpsfw-hub-note-view' );
            var arrows = box.querySelectorAll( '.rpsfw-hub-note-arrow' );
            if ( views.length < 2 || ! arrows.length ) {
                return;
            }
            var current = 0;
            function show( index ) {
                current = ( index + views.length ) % views.length;
                views.forEach( function( view, i ) {
                    view.classList.toggle( 'is-active', i === current );
                } );
            }
            arrows.forEach( function( arrow ) {
                arrow.addEventListener( 'click', function() {
                    show( arrow.getAttribute( 'data-dir' ) === 'prev' ? current - 1 : current + 1 );
                } );
            } );
        } )();
    </script>
    <?php
}
