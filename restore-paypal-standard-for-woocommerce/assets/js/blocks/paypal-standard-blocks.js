/**
 * External dependencies
 */
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;
const { createElement } = window.wp.element;

/**
 * Internal dependencies
 */
const settings = getSetting( 'restore_paypal_standard_data', {} );

/**
 * Content component for PayPal Standard
 */
const Content = () => {
    const description = settings.description || '';
    return createElement( 'div', {
        dangerouslySetInnerHTML: { __html: description },
        style: { 
            marginTop: '8px',
            paddingTop: '8px',
            borderTop: '1px solid #ddd'
        }
    });
};

/**
 * Label component for PayPal Standard with icon
 * Displays only the PayPal logo for blocks checkout
 */
const Label = ( props ) => {
    const { PaymentMethodLabel } = props.components;
    const label = decodeEntities( settings.title || 'PayPal' );
    
    // Display only the PayPal logo for blocks
    if ( settings.iconUrl ) {
        return createElement( 'img', {
            src: settings.iconUrl,
            alt: 'PayPal',
            style: { 
                height: '24px',
                width: 'auto'
            }
        });
    }
    
    return createElement( PaymentMethodLabel, { text: label } );
};

/**
 * PayPal Standard payment method config object.
 */
const PayPalStandardPaymentMethod = {
    name: 'restore_paypal_standard',
    label: createElement( Label, null ),
    content: createElement( Content, null ),
    edit: createElement( Content, null ),
    canMakePayment: () => true,
    ariaLabel: decodeEntities( settings.title || 'PayPal' ),
    supports: {
        features: settings.supports || [],
    },
};

registerPaymentMethod( PayPalStandardPaymentMethod );
