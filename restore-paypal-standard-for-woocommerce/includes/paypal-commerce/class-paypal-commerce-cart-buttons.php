<?php
/**
 * PayPal Commerce Cart Buttons.
 *
 * Handles displaying PayPal buttons on the cart page.
 *
 * @class       WC_PayPal_Commerce_Cart_Buttons
 * @version     1.0.0
 * @package     RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_PayPal_Commerce_Cart_Buttons Class.
 */
class WC_PayPal_Commerce_Cart_Buttons {

    /**
     * Gateway instance.
     *
     * @var WC_Gateway_PayPal_Commerce
     */
    private $gateway;

    /**
     * Constructor.
     */
    public function __construct() {
        // Classic cart hooks
        add_action( 'woocommerce_proceed_to_checkout', array( $this, 'render_cart_buttons' ), 30 );
        add_action( 'woocommerce_proceed_to_checkout', array( $this, 'render_cart_buttons_above' ), 5 );
        
        // Cart Block hooks
        add_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after', array( $this, 'enqueue_cart_block_scripts' ) );
        
        // General scripts
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        
        // Add inline rendering for cart block
        add_action( 'wp_footer', array( $this, 'render_cart_block_container' ) );
    }

    /**
     * Get gateway instance.
     *
     * @return WC_Gateway_PayPal_Commerce|null
     */
    private function get_gateway() {
        if ( $this->gateway ) {
            return $this->gateway;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
            return null;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( isset( $gateways['rpsfw_paypal_commerce'] ) ) {
            $this->gateway = $gateways['rpsfw_paypal_commerce'];
        }

        return $this->gateway;
    }

    /**
     * Whether the cart express button feature is available at all: the gateway
     * exists, is enabled, connected, and the cart-button option is on. This
     * deliberately does NOT consider the current cart contents — it controls
     * whether the scripts/container load, so the JS listener is present and can
     * react to cart changes even when the cart is momentarily not eligible.
     *
     * @return bool
     */
    private function is_available() {
        $gateway = $this->get_gateway();

        if ( ! $gateway ) {
            return false;
        }

        if ( 'yes' !== $gateway->get_option( 'enable_cart_buttons', 'no' ) ) {
            return false;
        }

        if ( 'yes' !== $gateway->get_option( 'enabled', 'no' ) ) {
            return false;
        }

        if ( ! $gateway->is_connected() ) {
            return false;
        }

        // Multiple distinct recurring schedules can't be billed by a single
        // plan-based PayPal subscription — hide the express buttons too.
        if ( class_exists( 'WC_PayPal_Commerce_Subscriptions' )
            && WC_PayPal_Commerce_Subscriptions::cart_contains_multiple_subscriptions() ) {
            return false;
        }

        return true;
    }

    /**
     * Whether the CURRENT cart is eligible to show the express button.
     *
     * Express checkout from the cart cannot reliably collect a shipping
     * address or calculate shipping cost before the buyer approves, which would
     * drop shipping (and shipping-related fees) from the order. So we only offer
     * it for carts that do not require shipping, and never for subscription
     * carts, which must pass through the checkout so the customer ends up with
     * an account that can manage the subscription. This is re-evaluated on every
     * cart update (page load + the updated_cart_totals AJAX refresh) so the
     * button appears/disappears as items are added or removed.
     *
     * @return bool
     */
    private function is_cart_eligible() {
        // Express checkout from the cart can't collect a shipping address or
        // calculate a shipping cost before the buyer approves. So whenever the
        // cart requires shipping we hide the express button entirely — the
        // whole wrapper, including the "or" separator — on both classic and
        // block cart pages. This applies to subscription carts too: even though
        // PayPal could capture the address at approval, the cart-page express
        // flow shouldn't offer checkout for anything that needs a shipping
        // address. Shippable customers proceed to the normal checkout instead.
        if ( WC()->cart && WC()->cart->needs_shipping() ) {
            return false;
        }

        // Subscriptions are never sold from the cart page. The express flow
        // approves the subscription at PayPal and builds the order itself,
        // skipping the checkout — so a guest never creates an account. The
        // resulting subscription record would carry no customer id, which means
        // it could not be viewed, paused or cancelled from My Account and would
        // never grant the subscriber role, leaving the customer with a
        // subscription only PayPal (or the store owner) can stop. Subscription
        // carts go through the normal checkout instead, where account creation
        // is enforced. Covers native records, WooCommerce Subscriptions product
        // types and plan-based (All Products for Subscriptions) carts.
        if ( class_exists( 'WC_PayPal_Commerce_Subscriptions' )
            && WC_PayPal_Commerce_Subscriptions::cart_contains_subscription() ) {
            return false;
        }

        return true;
    }

    /**
     * Check if cart buttons should be displayed (available AND the current cart
     * is eligible).
     *
     * @return bool
     */
    private function should_display() {
        return $this->is_available() && $this->is_cart_eligible();
    }

    /**
     * Render PayPal buttons above proceed to checkout.
     */
    public function render_cart_buttons_above() {
        if ( ! $this->is_available() ) {
            return;
        }

        $gateway = $this->get_gateway();
        $position = $gateway->get_option( 'cart_button_position', 'below' );
        
        if ( $position !== 'above' ) {
            return;
        }

        $this->output_button_html( false );
    }

    /**
     * Render PayPal buttons below proceed to checkout.
     */
    public function render_cart_buttons() {
        if ( ! $this->is_available() ) {
            return;
        }

        $gateway = $this->get_gateway();
        $position = $gateway->get_option( 'cart_button_position', 'below' );
        
        if ( $position !== 'below' ) {
            return;
        }

        $this->output_button_html( true );
    }

    /**
     * Output the button HTML.
     *
     * @param bool $below Whether buttons are below the checkout button.
     */
    private function output_button_html( $below ) {
        $gateway = $this->get_gateway();
        $separator_text = $gateway ? $gateway->get_option( 'cart_button_separator_text', __( 'or', 'restore-paypal-standard-for-woocommerce' ) ) : __( 'or', 'restore-paypal-standard-for-woocommerce' );
        ?>
        <style>
            .rpsfw-ppcp-cart-buttons {
                margin: 15px 0 !important;
                width: 100% !important;
            }
            .rpsfw-ppcp-cart-buttons.above {
                margin-bottom: 20px !important;
            }
            .rpsfw-ppcp-cart-buttons.below {
                margin-top: 20px !important;
            }
            .rpsfw-ppcp-cart-or {
                display: flex !important;
                align-items: center !important;
                text-align: center !important;
                width: 100% !important;
                margin: 15px 0 !important;
                color: #666 !important;
                font-size: 14px !important;
            }
            .rpsfw-ppcp-cart-or::before,
            .rpsfw-ppcp-cart-or::after {
                content: '' !important;
                flex: 1 !important;
                border-bottom: 1px solid #ccc !important;
            }
            .rpsfw-ppcp-cart-or::before {
                margin-right: 10px !important;
            }
            .rpsfw-ppcp-cart-or::after {
                margin-left: 10px !important;
            }
            #rpsfw-paypal-cart-button-container {
                width: 100% !important;
                min-height: 45px;
            }
        </style>
        <div class="rpsfw-ppcp-cart-buttons <?php echo $below ? 'below' : 'above'; ?>"<?php echo $this->is_cart_eligible() ? '' : ' style="display:none;"'; ?>>
            <?php if ( $below ) : ?>
                <p class="rpsfw-ppcp-cart-or"><?php echo esc_html( $separator_text ); ?></p>
            <?php endif; ?>
            
            <div id="rpsfw-paypal-cart-button-container"></div>
            
            <?php 
            // Add Pay Later message container if enabled
            if ( 'yes' === $gateway->get_option( 'paylater_messaging_enabled', 'no' ) && 
                 'yes' === $gateway->get_option( 'paylater_messaging_cart', 'no' ) && 
                 WC()->cart ) {
                $amount = WC()->cart->get_total( 'edit' );
                if ( $amount > 0 ) {
                    // Get messaging class instance
                    global $rpsfw_paylater_messaging;
                    if ( $rpsfw_paylater_messaging && method_exists( $rpsfw_paylater_messaging, 'render_cart_button_message' ) ) {
                        $rpsfw_paylater_messaging->render_cart_button_message( $amount );
                    }
                }
            }
            ?>
            
            <?php if ( ! $below ) : ?>
                <p class="rpsfw-ppcp-cart-or"><?php echo esc_html( $separator_text ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Enqueue cart button scripts.
     */
    public function enqueue_scripts() {
        if ( ! is_cart() && ! has_block( 'woocommerce/cart' ) ) {
            return;
        }

        if ( ! $this->is_available() ) {
            return;
        }

        $gateway = $this->get_gateway();

        // Get PayPal client ID
        $env = $gateway->testmode ? 'sandbox' : 'live';
        $onboarding = $gateway->get_option( 'ppcp_onboarding', array() );

        if ( empty( $onboarding[ $env ]['seller_id'] ) ) {
            return;
        }

        $client_id = $gateway->api->get_client_id( $env, $onboarding[ $env ] );

        if ( empty( $client_id ) ) {
            return;
        }

        // Get payment action setting
        $payment_action = $gateway->get_option( 'payment_action', 'capture' );
        $intent = ( $payment_action === 'authorize' ) ? 'authorize' : 'capture';

        // Build SDK URL parameters
        $sdk_args = array(
            'client-id' => $client_id,
            'currency'  => get_woocommerce_currency(),
            'intent'    => $intent,
        );

        // ALWAYS include both buttons and messages components
        // This ensures both PayPal buttons and Pay Later messaging work regardless of settings
        $sdk_args['components'] = 'buttons,messages';

        // Sandbox only: eligibility for Venmo / Pay Later is driven by the
        // buyer-country parameter (PayPal ignores the real IP/locale in
        // sandbox). Use the WooCommerce store base country so these buttons
        // can be tested. Never sent in live mode.
        if ( 'sandbox' === $env && function_exists( 'WC' ) && WC()->countries ) {
            $base_country = WC()->countries->get_base_country();
            if ( $base_country ) {
                $sdk_args['buyer-country'] = $base_country;
            }
        }

        // We render each funding source explicitly (per fundingSource) in JS
        // and gate it with isEligible(), so we deliberately do NOT build a
        // disable-funding list. Disabling a source here — especially
        // 'paypal' — can make other sources ineligible, notably the unbranded
        // "Debit or Credit Card" button. Hiding is handled entirely by which
        // sources the JS chooses to render.

        // enable-funding still makes the non-default sources the merchant has
        // turned on eligible for rendering.
        $enable_funding = array();
        if ( 'yes' === $gateway->get_option( 'enable_venmo', 'no' ) ) {
            $enable_funding[] = 'venmo';
        }
        if ( 'yes' === $gateway->get_option( 'enable_paylater', 'no' ) ) {
            $enable_funding[] = 'paylater';
        }
        
        if ( ! empty( $enable_funding ) ) {
            $sdk_args['enable-funding'] = implode( ',', $enable_funding );
        }

        /**
         * Allow extensions (e.g. WC Subscriptions integration) to flip the
         * SDK into subscription mode when the cart contains a subscription.
         */
        $sdk_args = apply_filters( 'rpsfw_ppcp_sdk_args', $sdk_args );

        // Enqueue PayPal SDK - use consistent handle across all files
        $sdk_url = add_query_arg( $sdk_args, 'https://www.paypal.com/sdk/js' );
        wp_enqueue_script( 'rpsfw-paypal-sdk', $sdk_url, array(), null, true );

        // Enqueue cart button script
        $cart_js = RPSFW_PLUGIN_DIR . 'assets/js/paypal-commerce-cart.js';
        wp_enqueue_script(
            'rpsfw-paypal-commerce-cart',
            RPSFW_PLUGIN_URL . 'assets/js/paypal-commerce-cart.js',
            array( 'jquery', 'rpsfw-paypal-sdk' ),
            file_exists( $cart_js ) ? filemtime( $cart_js ) : RPSFW_VERSION,
            true
        );

        // Get processing text
        $processing_text = $gateway->get_option( 'cart_processing_text', __( 'PayPal authorized. Processing your order...', 'restore-paypal-standard-for-woocommerce' ) );

        // Detect whether the cart contains a subscription so the JS
        // branches to the createSubscription flow instead of createOrder.
        $is_subscription = class_exists( 'WC_PayPal_Commerce_Subscriptions' )
            && WC_PayPal_Commerce_Subscriptions::cart_contains_subscription();

        // Localize script
        wp_localize_script(
            'rpsfw-paypal-commerce-cart',
            'rpsfwPayPalCommerceCart',
            array(
                'ajax_url'           => admin_url( 'admin-ajax.php' ),
                'create_nonce'       => wp_create_nonce( 'rpsfw-ppcp-create-order' ),
                'subscription_nonce' => wp_create_nonce( 'rpsfw-ppcp-create-subscription' ),
                'is_subscription'    => $is_subscription,
                'is_eligible'        => $this->is_cart_eligible(),
                'gateway_id'         => $gateway->id,
                'payment_action'     => $payment_action,
                'checkout_url'       => wc_get_checkout_url(),
                'processing_text'    => $processing_text,
                'funding'            => $gateway->get_funding_display_settings(),
            )
        );

        // Add inline styles
        $this->add_inline_styles();
    }

    /**
     * Add inline styles for cart buttons.
     */
    private function add_inline_styles() {
        $css = '
            .rpsfw-ppcp-cart-buttons {
                margin: 15px 0 !important;
                text-align: center !important;
                width: 100% !important;
            }
            .rpsfw-ppcp-cart-buttons.above {
                margin-bottom: 20px !important;
            }
            .rpsfw-ppcp-cart-buttons.below {
                margin-top: 20px !important;
            }
            .rpsfw-ppcp-cart-or {
                color: #666 !important;
                font-size: 14px !important;
                margin: 10px auto !important;
                text-align: center !important;
                display: block !important;
                width: 100% !important;
            }
            #rpsfw-paypal-cart-button-container {
                max-width: 400px !important;
                margin: 0 auto !important;
                min-height: 45px;
            }
            /* Cart Block specific styles */
            .wp-block-woocommerce-cart .rpsfw-ppcp-cart-buttons,
            #rpsfw-cart-block-paypal-container .rpsfw-ppcp-cart-buttons {
                margin: 20px auto !important;
                text-align: center !important;
                width: 100% !important;
            }
            #rpsfw-cart-block-paypal-container .rpsfw-ppcp-cart-or {
                text-align: center !important;
                display: block !important;
                width: 100% !important;
            }
        ';
        
        wp_add_inline_style( 'woocommerce-general', $css );
    }

    /**
     * Enqueue scripts for Cart Block.
     */
    public function enqueue_cart_block_scripts() {
        if ( ! $this->is_available() ) {
            return;
        }

        // Enqueue the same cart scripts
        $this->enqueue_scripts();
    }

    /**
     * Render cart block button container in footer.
     */
    public function render_cart_block_container() {
        if ( ! is_cart() && ! has_block( 'woocommerce/cart' ) ) {
            return;
        }

        // The cart block container is shown unconditionally by its inline
        // script once the block loads, and the block cart does not fire the
        // classic updated_cart_totals refresh. So gate it on full eligibility
        // (available AND no shipping required) to avoid showing the express
        // button for shippable carts.
        if ( ! $this->should_display() ) {
            return;
        }

        // Check if this is a cart block page
        if ( ! has_block( 'woocommerce/cart' ) ) {
            return;
        }

        $gateway = $this->get_gateway();
        $separator_text = $gateway ? $gateway->get_option( 'cart_button_separator_text', __( 'or', 'restore-paypal-standard-for-woocommerce' ) ) : __( 'or', 'restore-paypal-standard-for-woocommerce' );

        // Add container that will be moved into place by JavaScript
        ?>
        <style>
            #rpsfw-cart-block-paypal-container .rpsfw-ppcp-cart-buttons {
                margin: 20px 0 !important;
                width: 100% !important;
            }
            #rpsfw-cart-block-paypal-container .rpsfw-ppcp-cart-or {
                display: flex !important;
                align-items: center !important;
                text-align: center !important;
                width: 100% !important;
                margin: 15px 0 !important;
                color: #666 !important;
                font-size: 14px !important;
            }
            #rpsfw-cart-block-paypal-container .rpsfw-ppcp-cart-or::before,
            #rpsfw-cart-block-paypal-container .rpsfw-ppcp-cart-or::after {
                content: '' !important;
                flex: 1 !important;
                border-bottom: 1px solid #ccc !important;
            }
            #rpsfw-cart-block-paypal-container .rpsfw-ppcp-cart-or::before {
                margin-right: 10px !important;
            }
            #rpsfw-cart-block-paypal-container .rpsfw-ppcp-cart-or::after {
                margin-left: 10px !important;
            }
            #rpsfw-cart-block-paypal-container #rpsfw-paypal-cart-button-container {
                width: 100% !important;
                min-height: 45px;
            }
        </style>
        <div id="rpsfw-cart-block-paypal-container" style="display:none;">
            <div class="rpsfw-ppcp-cart-buttons below" style="width: 100%;">
                <p class="rpsfw-ppcp-cart-or"><?php echo esc_html( $separator_text ); ?></p>
                <div id="rpsfw-paypal-cart-button-container" style="width: 100%;"></div>
                <?php 
                // Add Pay Later message container if enabled
                if ( 'yes' === $gateway->get_option( 'paylater_messaging_enabled', 'no' ) && 
                     'yes' === $gateway->get_option( 'paylater_messaging_cart', 'no' ) && 
                     WC()->cart ) {
                    $amount = WC()->cart->get_total( 'edit' );
                    if ( $amount > 0 ) {
                        // Get messaging class instance
                        global $rpsfw_paylater_messaging;
                        if ( $rpsfw_paylater_messaging && method_exists( $rpsfw_paylater_messaging, 'render_cart_button_message' ) ) {
                            $rpsfw_paylater_messaging->render_cart_button_message( $amount );
                        }
                    }
                }
                ?>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            // The cart block ("Proceed to Checkout" area) markup has changed
            // across WooCommerce versions, so try several known selectors
            // rather than depending on one class. We insert our PayPal express
            // container right after the proceed-to-checkout block/button.
            var selectors = [
                '.wp-block-woocommerce-cart .wp-block-woocommerce-proceed-to-checkout-block',
                '.wp-block-woocommerce-cart .wc-block-cart__submit-container',
                '.wp-block-woocommerce-cart .wc-block-cart__submit',
                '.wp-block-woocommerce-cart .wc-block-cart__submit-button',
                '.wp-block-woocommerce-cart .wc-block-components-checkout-button',
                '.wc-block-cart .wc-block-cart__submit-container',
                '.wc-block-cart .wc-block-cart__submit'
            ];

            function findTarget() {
                for ( var i = 0; i < selectors.length; i++ ) {
                    var $el = $( selectors[ i ] ).first();
                    if ( $el.length ) {
                        return $el;
                    }
                }
                return $();
            }

            function placeContainer() {
                var $el = findTarget();
                if ( ! $el.length ) {
                    return false;
                }
                var $container = $( '#rpsfw-cart-block-paypal-container' );
                if ( ! $container.length ) {
                    return false;
                }
                // Prefer inserting after the proceed-to-checkout block wrapper
                // so our button sits just below the checkout button.
                var $target = $el.closest(
                    '.wp-block-woocommerce-proceed-to-checkout-block, .wc-block-cart__submit-container, .wc-block-cart__submit'
                );
                if ( ! $target.length ) {
                    $target = $el;
                }
                // Avoid re-inserting if it's already in place.
                if ( $target.next( '#rpsfw-cart-block-paypal-container' ).length ) {
                    $container.show();
                    return true;
                }
                $container.show();
                $target.after( $container );
                return true;
            }

            // Poll until the block has rendered the checkout area.
            var checkCartBlock = setInterval(function() {
                if ( placeContainer() ) {
                    clearInterval( checkCartBlock );

                    // The cart block re-renders (React) on quantity/coupon
                    // changes, which can move/replace the checkout area and
                    // drop our container. Re-place it whenever the block DOM
                    // mutates.
                    var cartRoot = document.querySelector( '.wp-block-woocommerce-cart, .wc-block-cart' );
                    if ( cartRoot && 'MutationObserver' in window ) {
                        var debounce;
                        new MutationObserver(function() {
                            clearTimeout( debounce );
                            debounce = setTimeout( placeContainer, 150 );
                        }).observe( cartRoot, { childList: true, subtree: true } );
                    }
                }
            }, 100);

            // Stop the initial poll after 15 seconds.
            setTimeout(function() {
                clearInterval( checkCartBlock );
            }, 15000);
        });
        </script>
        <?php
    }
}

// Initialize cart buttons
new WC_PayPal_Commerce_Cart_Buttons();
