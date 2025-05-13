<?php
/**
 * Class rpsfw_Gateway_PayPal_Standard_Request file.
 *
 * @package WooPayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates requests to send to PayPal.
 */
class rpsfw_Gateway_PayPal_Standard_Request {

    /**
     * Stores line items to send to PayPal.
     *
     * @var array
     */
    protected $line_items = array();

    /**
     * Pointer to gateway making the request.
     *
     * @var rpsfw_Gateway_PayPal_Standard
     */
    protected $gateway;

    /**
     * Endpoint for requests from PayPal.
     *
     * @var string
     */
    protected $notify_url;

    /**
     * Endpoint for requests to PayPal.
     *
     * @var string
     */
    protected $endpoint;

    /**
     * Constructor.
     *
     * @param rpsfw_Gateway_PayPal_Standard $gateway Paypal gateway object.
     */
    public function __construct( $gateway ) {
        $this->gateway    = $gateway;
        $this->notify_url = WC()->api_request_url( 'rpsfw_Gateway_PayPal_Standard' );
        
        // Log the notify URL to debug
        rpsfw_Gateway_PayPal_Standard::log( 'Notify URL: ' . $this->notify_url );
    }

    /**
     * Get the PayPal request URL for an order.
     *
     * @param  WC_Order $order Order object.
     * @param  bool     $sandbox Whether to use sandbox mode or not.
     * @return string
     */
    public function get_request_url( $order, $sandbox = false ) {
        $this->endpoint    = $sandbox ? 'https://www.sandbox.paypal.com/cgi-bin/webscr?test_ipn=1&' : 'https://www.paypal.com/cgi-bin/webscr?';
        $paypal_args       = $this->get_paypal_args( $order );
        $paypal_args['bn'] = 'WPPlugin_SP';

        rpsfw_Gateway_PayPal_Standard::log( 'PayPal Request Args for order #' . $order->get_id() . ': ' . wc_print_r( $paypal_args, true ) );

        return $this->endpoint . http_build_query( $paypal_args, '', '&' );
    }

    /**
     * Get PayPal Args for passing to PayPal including the payment.
     *
     * @param  WC_Order $order Order object.
     * @return array
     */
    protected function get_paypal_args( $order ) {
        WC()->initialize_session();

        $order_id = $order->get_id();
        $this->line_items = array();
        
        $paypal_args = $this->get_transaction_args( $order );
        $paypal_args = $this->add_line_items( $paypal_args, $order );
        $paypal_args = $this->add_shipping( $paypal_args, $order );
        
        // Add tax
        if ( $order->get_total_tax() > 0 ) {
            $paypal_args['tax_cart'] = $this->number_format( $order->get_total_tax(), $order );
        }
        
        // Add discount
        if ( $order->get_total_discount() > 0 ) {
            $paypal_args['discount_amount_cart'] = $this->number_format( $order->get_total_discount(), $order );
        }
        
        // If no line items were added, add the full amount as a single item to prevent AMOUNT_MISSING error
        if ( empty($this->line_items) ) {
            $paypal_args['item_name_1'] = sprintf( __( 'Order %s', 'restore-paypal-standard-for-woocommerce' ), $order->get_order_number() );
            $paypal_args['quantity_1'] = 1;
            $paypal_args['amount_1'] = $this->number_format( $order->get_total() - $order->get_shipping_total() - $order->get_total_tax(), $order );
            
            // Log this action
            rpsfw_Gateway_PayPal_Standard::log( 'Adding single line item with total amount: ' . $paypal_args['amount_1'] );
        }
        
        // Add custom data
        $custom = array(
            'order_id'  => $order->get_id(),
            'order_key' => $order->get_order_key(),
        );
        
        $paypal_args['custom'] = json_encode( $custom );
        
        // Handle button check as we are replicating a POST request with GET
        $paypal_args['cmd'] = '_cart';
        $paypal_args['upload'] = 1;
        
        // Additional gateway options
        $paypal_args['invoice_prefix'] = $this->gateway->invoice_prefix;
        
        // Payment processor options
        $paypal_args['lc'] = get_locale();
        $paypal_args['currency_code'] = $this->get_paypal_currency( get_woocommerce_currency() );
        $paypal_args['charset'] = 'utf-8';
        
        // Store the order's referer
        $referer = wp_get_referer();
        if ( $referer ) {
            $paypal_args['referrer_url'] = $referer;
        }
        
        return apply_filters( 'woocommerce_paypal_standard_request_args', $paypal_args, $order );
    }

    /**
     * Get transaction args for paypal request, except for line item args.
     *
     * @param WC_Order $order Order object.
     * @return array
     */
    protected function get_transaction_args( $order ) {
        // Log all available settings to debug the issue
        rpsfw_Gateway_PayPal_Standard::log( 'DEBUG - Gateway settings: ' );
        rpsfw_Gateway_PayPal_Standard::log( 'Mode: ' . ($this->gateway->testmode ? 'sandbox' : 'live') );
        rpsfw_Gateway_PayPal_Standard::log( 'Email (live): ' . $this->gateway->get_option('email', 'unknown') );
        rpsfw_Gateway_PayPal_Standard::log( 'Sandbox Email: ' . $this->gateway->get_option('sandbox_email', 'unknown') );
        rpsfw_Gateway_PayPal_Standard::log( 'Gateway property email value: ' . $this->gateway->email );
        
        // Log the email being used
        rpsfw_Gateway_PayPal_Standard::log( 'Using email for business parameter: ' . $this->gateway->get_option( 'email' ) );
        

        // Get the business email based on the mode
        $testmode = 'yes' === $this->gateway->get_option('testmode', 'no');
        if ($testmode) {
            $business_email = $this->gateway->get_option('sandbox_email');
        } else {
            $business_email = $this->gateway->get_option('email');
        }

        
        $return_url = $this->gateway->get_option( 'use_non_standard_ux', 'no' ) === 'yes' ? 
            wc_get_checkout_url() : $this->gateway->get_return_url( $order );
            
        $cancel_url = $order->get_cancel_order_url_raw();
        
        // Check if the order contains only digital products
        $no_shipping = 1; // Default to no shipping required
        
        foreach ($order->get_items() as $order_item) {
            $product = $order_item->get_product();
            if ($product && !$product->is_virtual()) {
                $no_shipping = 0; // If there is a non-virtual product, shipping is required
                break;
            }
        }
        
        $transaction_args = array(
            'cmd'           => '_cart',
            'business'      => $business_email,
            'no_note'       => 1,
            'bn'            => 'WPPlugin_SP',
            'no_shipping'   => $no_shipping,
            'rm'            => is_ssl() ? 2 : 1,
            'return'        => $return_url,
            'cancel_return' => $cancel_url,
            'notify_url'    => $this->notify_url,
            'invoice'       => $this->gateway->invoice_prefix . $order->get_order_number(),
            'custom'        => json_encode(
                array(
                    'order_id'  => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                )
            ),
            'currency_code' => $this->get_paypal_currency( get_woocommerce_currency() ),
            'charset'       => 'utf-8',
            'paymentaction' => $this->gateway->get_option( 'paymentaction' ),
            'first_name'    => $this->limit_length( $order->get_billing_first_name(), 32 ),
            'last_name'     => $this->limit_length( $order->get_billing_last_name(), 64 ),
            'address1'      => $this->get_paypal_address( $order->get_billing_address_1(), 100 ),
            'address2'      => $this->get_paypal_address( $order->get_billing_address_2(), 100 ),
            'city'          => $this->get_paypal_address( $order->get_billing_city(), 40 ),
            'state'         => $this->get_paypal_state( $order->get_billing_country(), $order->get_billing_state() ),
            'zip'           => $this->limit_length( wc_format_postcode( $order->get_billing_postcode(), $order->get_billing_country() ), 32 ),
            'country'       => $this->get_paypal_country( $order->get_billing_country() ),
            'email'         => $this->get_paypal_email( $order->get_billing_email() ),
            'night_phone_a' => $this->get_paypal_phone( $order->get_billing_phone() ),
        );
        
        // Add shipping address if it exists and at least one product requires shipping
        if ($no_shipping === 0) {
            if ($order->get_shipping_address_1() && $order->get_shipping_address_1() !== $order->get_billing_address_1()) {
                // Only set address_override if the setting is enabled
                if ($this->gateway->get_option('address_override', 'no') === 'yes') {
                    $transaction_args['address_override'] = 1;
                }
                
                // Add shipping fields
                $transaction_args['first_name_ship'] = $this->limit_length($order->get_shipping_first_name(), 32);
                $transaction_args['last_name_ship']  = $this->limit_length($order->get_shipping_last_name(), 64);
                $transaction_args['address1_ship']   = $this->get_paypal_address($order->get_shipping_address_1(), 100);
                $transaction_args['address2_ship']   = $this->get_paypal_address($order->get_shipping_address_2(), 100);
                $transaction_args['city_ship']       = $this->get_paypal_address($order->get_shipping_city(), 40);
                $transaction_args['state_ship']      = $this->get_paypal_state($order->get_shipping_country(), $order->get_shipping_state());
                $transaction_args['zip_ship']        = $this->limit_length(wc_format_postcode($order->get_shipping_postcode(), $order->get_shipping_country()), 32);
                $transaction_args['country_ship']    = $this->get_paypal_country($order->get_shipping_country());
            } else {
                // If shipping is the same as billing or not provided, pass the billing details instead
                // Only set address_override if the setting is enabled
                if ($this->gateway->get_option('address_override', 'no') === 'yes') {
                    $transaction_args['address_override'] = 1;
                }
                
                $transaction_args['first_name_ship'] = $this->limit_length($order->get_billing_first_name(), 32);
                $transaction_args['last_name_ship']  = $this->limit_length($order->get_billing_last_name(), 64);
                $transaction_args['address1_ship']   = $this->get_paypal_address($order->get_billing_address_1(), 100);
                $transaction_args['address2_ship']   = $this->get_paypal_address($order->get_billing_address_2(), 100);
                $transaction_args['city_ship']       = $this->get_paypal_address($order->get_billing_city(), 40);
                $transaction_args['state_ship']      = $this->get_paypal_state($order->get_billing_country(), $order->get_billing_state());
                $transaction_args['zip_ship']        = $this->limit_length(wc_format_postcode($order->get_billing_postcode(), $order->get_billing_country()), 32);
                $transaction_args['country_ship']    = $this->get_paypal_country($order->get_billing_country());
            }
        }
        
        // Add item name for single item orders
        if ( 1 === count( $order->get_items() ) ) {
            foreach ( $order->get_items() as $item ) {
                $product = $item->get_product();
                $transaction_args['item_name'] = $this->get_item_name( $product, $item );
            }
        }
        
        // Add customer data
        $transaction_args = $this->add_customer_data( $transaction_args, $order );
        
        // Add page style preference
        $page_style = $this->gateway->get_option( 'page_style' );
        if ( ! empty( $page_style ) ) {
            $transaction_args['page_style'] = $page_style;
        }
        
        return $transaction_args;
    }

    /**
     * Add customer data to transaction args.
     *
     * @param array    $transaction_args Transaction args.
     * @param WC_Order $order Order object.
     * @return array
     */
    protected function add_customer_data( $transaction_args, $order ) {
        // Add customer details
        if ( $order->get_billing_first_name() || $order->get_billing_last_name() ) {
            $transaction_args['first_name'] = $order->get_billing_first_name();
            $transaction_args['last_name']  = $order->get_billing_last_name();
        }
        
        if ( $order->get_billing_email() ) {
            $transaction_args['email'] = $order->get_billing_email();
        }
        
        if ( $order->get_billing_phone() ) {
            $transaction_args['night_phone_a'] = $order->get_billing_phone();
        }
        
        if ( $order->get_billing_address_1() ) {
            $transaction_args['address1'] = $order->get_billing_address_1();
        }
        
        if ( $order->get_billing_address_2() ) {
            $transaction_args['address2'] = $order->get_billing_address_2();
        }
        
        if ( $order->get_billing_city() ) {
            $transaction_args['city'] = $order->get_billing_city();
        }
        
        if ( $order->get_billing_state() ) {
            $transaction_args['state'] = $order->get_billing_state();
        }
        
        if ( $order->get_billing_postcode() ) {
            $transaction_args['zip'] = $order->get_billing_postcode();
        }
        
        if ( $order->get_billing_country() ) {
            $transaction_args['country'] = $order->get_billing_country();
        }
        
        return $transaction_args;
    }

    /**
     * Add line items to the request.
     *
     * @param array    $paypal_args PayPal args.
     * @param WC_Order $order Order object.
     * @return array
     */
    protected function add_line_items( $paypal_args, $order ) {
        // Add line items
        $this->line_items = array(); // Reset line items
        
        $count = 1;
        
        foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
            if ( 'fee' === $item->get_type() ) {
                // Process fee items
                $paypal_args = $this->add_line_item( 
                    $paypal_args, 
                    $item->get_name(), 
                    1, 
                    $this->number_format( $item->get_total(), $order ), 
                    $count 
                );
                $this->line_items[] = $count; // Track that we added this line item
                $count++;
            } else {
                // Process regular product items
                if ( $item->get_quantity() ) {
                    $product = $item->get_product();
                    
                    if ( $product ) {
                        $item_line_total = $order->get_line_subtotal( $item, false, false );
                        
                        // Check if the line total is zero
                        if ( 0 === $item_line_total ) {
                            continue; // Skip zero amount line items
                        }
                        
                        // Divide by quantity to get the unit price
                        $item_unit_price = $item_line_total / $item->get_quantity();
                        
                        // Format the unit price
                        $item_unit_price_formatted = $this->number_format( $item_unit_price, $order );
                        
                        // Get product SKU
                        $sku = $product ? $product->get_sku() : '';
                        
                        $paypal_args = $this->add_line_item( 
                            $paypal_args, 
                            $item->get_name(), 
                            $item->get_quantity(), 
                            $item_unit_price_formatted, 
                            $count,
                            $sku 
                        );
                        $this->line_items[] = $count; // Track that we added this line item
                        $count++;
                    }
                }
            }
        }
        
        return $paypal_args;
    }

    /**
     * Add a line item to PayPal args.
     *
     * @param array  $paypal_args PayPal args.
     * @param string $item_name Item name.
     * @param int    $quantity Item quantity.
     * @param float  $amount Item amount.
     * @param int    $item_number Item number.
     * @param string $sku Product SKU.
     * @return array
     */
    protected function add_line_item( $paypal_args, $item_name, $quantity, $amount, $item_number, $sku = '' ) {
        $item_name = wp_strip_all_tags( $item_name );
        
        // If the line item name is too long, truncate it to avoid PayPal issues
        if ( strlen( $item_name ) > 127 ) {
            $item_name = substr( $item_name, 0, 124 ) . '...';
        }
        
        // Log the line item
        rpsfw_Gateway_PayPal_Standard::log( 'Adding line item: ' . $item_name . ' x ' . $quantity . ' @ ' . $amount );
        
        $paypal_args['item_name_' . $item_number] = $item_name;
        $paypal_args['quantity_' . $item_number]  = $quantity;
        $paypal_args['amount_' . $item_number]    = $amount;
        
        if ( $sku ) {
            $paypal_args['item_number_' . $item_number] = $sku;
        }
        
        return $paypal_args;
    }

    /**
     * Add shipping costs to PayPal args.
     *
     * @param array    $paypal_args PayPal args.
     * @param WC_Order $order Order object.
     * @return array
     */
    protected function add_shipping( $paypal_args, $order ) {
        // Add shipping costs
        if ( $order->get_shipping_total() > 0 ) {
            $paypal_args['shipping_1'] = $this->number_format( $order->get_shipping_total(), $order );
        }
        
        return $paypal_args;
    }

    /**
     * Format prices for PayPal.
     *
     * @param float    $price Price to format.
     * @param WC_Order $order Order object.
     * @return string
     */
    protected function number_format( $price, $order ) {
        return number_format( $price, 2, '.', '' );
    }

    /**
     * Get line item name.
     *
     * @param WC_Product $product Product object.
     * @param object     $item Line item.
     * @return string
     */
    protected function get_item_name( $product, $item ) {
        $item_name = $item->get_name();
        
        // Include variations if this is a variable product
        if ( $product && $product->is_type( 'variation' ) ) {
            $variation_data = wc_get_formatted_variation( $product, true );
            if ( $variation_data ) {
                $item_name .= ' (' . $variation_data . ')';
            }
        }
        
        // Ensure the item name is not too long for PayPal
        $item_name = substr( $item_name, 0, 127 );
        
        return html_entity_decode( wp_strip_all_tags( $item_name ), ENT_NOQUOTES, 'UTF-8' );
    }

    /**
     * Limit length of an arg.
     *
     * @param  string  $string Argument to limit.
     * @param  integer $limit Limit size in characters.
     * @return string
     */
    protected function limit_length( $string, $limit = 127 ) {
        $str_limit = $limit - 3;
        if ( function_exists( 'mb_strlen' ) ) {
            if ( mb_strlen( $string, 'UTF-8' ) > $limit ) {
                $string = mb_substr( $string, 0, $str_limit, 'UTF-8' ) . '...';
            }
        } else {
            if ( strlen( $string ) > $limit ) {
                $string = substr( $string, 0, $str_limit ) . '...';
            }
        }
        return $string;
    }

    /**
     * Get PayPal state code.
     *
     * @param string $country Country code.
     * @param string $state State code.
     * @return string
     */
    protected function get_paypal_state( $country, $state ) {
        if ( 'US' === $country ) {
            return $state;
        }

        $states = WC()->countries->get_states( $country );

        if ( $states && isset( $states[ $state ] ) ) {
            return $state;
        }

        return '';
    }

    /**
     * Get PayPal country code.
     *
     * @param string $country Country code.
     * @return string
     */
    protected function get_paypal_country( $country ) {
        // PayPal accepts 2-letter ISO country codes
        if ( strlen( $country ) === 2 ) {
            return strtoupper( $country );
        }
        
        // If we have a longer country code, try to get the 2-letter code
        $countries = WC()->countries->get_countries();
        foreach ( $countries as $code => $name ) {
            if ( $name === $country ) {
                return strtoupper( $code );
            }
        }
        
        return '';
    }

    /**
     * Get PayPal email.
     *
     * @param string $email Email address.
     * @return string
     */
    protected function get_paypal_email( $email ) {
        // PayPal has a 127 character limit for email addresses
        $email = $this->limit_length( $email, 127 );
        
        // Ensure it's a valid email format
        if ( ! is_email( $email ) ) {
            return '';
        }
        
        return $email;
    }

    /**
     * Get PayPal phone number.
     *
     * @param string $phone Phone number.
     * @return string
     */
    protected function get_paypal_phone( $phone ) {
        // Remove any non-numeric characters
        $phone = preg_replace( '/[^0-9]/', '', $phone );
        
        // PayPal has a 20 character limit for phone numbers
        return $this->limit_length( $phone, 20 );
    }

    /**
     * Get PayPal address.
     *
     * @param string $address Address.
     * @param int    $limit Character limit.
     * @return string
     */
    protected function get_paypal_address( $address, $limit = 100 ) {
        // Remove any HTML
        $address = wp_strip_all_tags( $address );
        
        // Remove any special characters that might cause issues
        $address = preg_replace( '/[^a-zA-Z0-9\s\-.,]/', '', $address );
        
        return $this->limit_length( $address, $limit );
    }

    /**
     * Get PayPal currency code.
     *
     * @param string $currency Currency code.
     * @return string
     */
    protected function get_paypal_currency( $currency ) {
        // PayPal accepts 3-letter ISO currency codes
        if ( strlen( $currency ) === 3 ) {
            return strtoupper( $currency );
        }
        
        // Try to get the currency code from WooCommerce
        $currencies = get_woocommerce_currencies();
        foreach ( $currencies as $code => $name ) {
            if ( $name === $currency ) {
                return strtoupper( $code );
            }
        }
        
        return 'USD'; // Default to USD if not found
    }

    /**
     * Format item name for PayPal.
     *
     * @param string $name Item name.
     * @return string
     */
    protected function format_paypal_item_name( $name ) {
        // Remove HTML
        $name = wp_strip_all_tags( $name );
        
        // Remove any special characters that might cause issues
        $name = preg_replace( '/[^a-zA-Z0-9\s\-.,]/', '', $name );
        
        // PayPal has a 127 character limit for item names
        return $this->limit_length( $name, 127 );
    }
} 