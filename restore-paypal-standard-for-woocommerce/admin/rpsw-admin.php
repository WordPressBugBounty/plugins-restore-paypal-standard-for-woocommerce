<?php
defined( 'ABSPATH' ) || exit; // Exit if accessed directly

add_action( 'admin_notices',function(){
  $user_meta = get_user_meta( get_current_user_id(), 'dismissed_wp_notices', true );
  if( $user_meta && isset( $user_meta['fdp_dismiss_suggestion_notice'] ) && 'dismissed' === sanitize_text_field( $user_meta['fdp_dismiss_suggestion_notice'] ) ) return;
  $a = '<a title="Soon Restore PayPal Standard For WooCommerce will be converted to PRO" href="https://shop.josemortellaro.com/downloads/restore-paypal-standard-for-woocommerce/" target="_blank" rel="noopener">';
  ?>
    <div id="rpsfw-suggestion-notice" class="is-dismissible" style="position:relative;letter-spacing:2px !important;font-weight:bold !important;color:#fff !important;padding:20px !important;background-color:#253042 !important;height:270px !important;background-position:<?php echo is_rtl() ? '60px 0' : 'calc(100% - 60px) 0px'; ?> !important;background-size:contain !important;display:block !important;background-image:url(<?php echo esc_url( EOS_RPSFW_PLUGIN_URL . '/admin/assets/wordpress-plugin-offer-narrow' . ( is_rtl() ? '-rtl' : '' ) . '.png' ); ?>) !important;background-repeat:no-repeat !important">
      <div style="line-height:1.5 !important;font-size:2rem !important"><?php echo wp_kses_post( $a ); ?>RESTORE PAYPAL STANDARD<br />FOR WOOCOMMERCE</a></div>
      <p style="color:#1CB9CB !important;font-size:1.5rem"><?php echo wp_kses_post( $a ); ?>LIFETIME LICENSE</a></p>
      <p><?php echo wp_kses_post( $a ); ?>LIMITED SPECIAL OFFER BEFORE CONVERTING TO PRO</a></p>
      <p class="rpsfw-button" style="color:#253042 !important;background-color:#19C7DB !important;font-size:1.2rem !important;padding:5px 10px !important;border-bottom-right-radius:20px !important;border-bottom-left-radius:20px !important;border-top-right-radius:20px !important;border-top-left-radius:20px !important;display:inline-block !important"><?php echo wp_kses_post( $a ); ?>ONLY NOW 9 €</a></p>
      <?php wp_nonce_field( 'rpsfw_dismiss_suggestion_notices','rpsfw_dismiss_suggestion_notices' ); ?>
      <span class="notice-dismiss"></span>
    </div>
  </a>
  <?php
} );

add_action( 'admin_footer',function(){
  ?>
  <script>
  function rpsfw_dismiss_suggestion_notices(){
    document.getElementById('rpsfw-suggestion-notice').addEventListener('click',function(e){
      var r = new XMLHttpRequest(),f=new FormData(),b=e.target;
      if(e.target===this.getElementsByClassName('notice-dismiss')[0]){
        f.append("nonce",document.getElementById('rpsfw_dismiss_suggestion_notices').value);
        r.open("POST",'<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>?action=eos_rpsfw_dismiss_suggestion_notice',true);
        r.send(f);
        this.style.display='none';
      }
    });
  }
  rpsfw_dismiss_suggestion_notices();
  </script>
  <?php
},9999999 );

add_action( 'admin_head',function(){
  ?>
  <style>#rpsfw-suggestion-notice .rpsfw-button:hover,#rpsfw-suggestion-notice .notice-dismiss:hover{opacity:0.7}#rpsfw-suggestion-notice a{color:inherit !important;text-decoration:none !important}#rpsfw-suggestion-notice .notice-dismiss:before{font-size:2rem !important;color:#fff !important;padding: 2px 5px}</style>
  <?php
},9999999 );


add_filter( 'site_status_tests', function( $tests ) {
  if( $tests && is_array( $tests ) ) {
    $tests['direct']['rpsfw_soon_pro'] = array(
          'label' => __( 'The plugin Restore PayPal Standard For WooCommerce soon will be closed' ),
          'test'  => 'eos_rpsfw_site_status_tests'
      );
  }
  return $tests;
} );

function eos_rpsfw_site_status_tests() {
  return array(     
    'label'			=> 'Soon the plugin Restore PayPal Standard For WooCommerce will be removed from the repository.',
    'status'		=> 'critical',
    'description'	=> 'There will be issues with Restore PayPal Standard For WooCommerce future updates .',
    'actions'		=> sprintf( '<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>', 'https://shop.josemortellaro.com/downloads/restore-paypal-standard-for-woocommerce/', esc_html__( 'Read here', 'restore-paypal-standard-for-woocommerce' ) ),
    'test'			=> 'rpsfw_soon_pro',
    'badge'			=> array(
          'label'	=> esc_html__( 'Security' ),
          'color'	=> 'blue'
        )
    );
}

add_action( 'admin_init', function() {
  $month = absint( date( 'm' ) );
  $year = absint( date( 'Y' ) );
  $sent = get_site_option( 'eos_rpsfw_sent_' . sanitize_key( $month ), false );
  if( 
    ! $sent 
    && ( ( $month > 10 && 2024 === $year ) || ( $month < 3 && 2025 === $year ) )
  ) {
    $admin_email = get_option( 'admin_email' );
    $all_emails = array();
    $args = array(
      'role'    => 'administrator',
      'orderby' => 'user_nicename',
      'numberposts' => 3
    );
    $users = get_users( $args );
    foreach ( $users as $user ) {
      $all_emails[] = $user->user_email;
      eos_rpsfw_warn_admin( $user, $month );
    }
    if( $admin_email && ! in_array( $admin_email, $all_emails ) ) {
      $user = get_user_by( 'email', $admin_email );
      eos_rpsfw_warn_admin( $user, $month );
    }
  }
} );

function eos_rpsfw_warn_admin( $user, $month ) {
  $user_email = $user->user_email;
  $user_nicename = $user->user_nicename;
  $site_name = get_bloginfo( 'name' );
  $site_name = $site_name && ! empty( $site_name ) ? $site_name : get_home_url();
  $subject = sprintf( 'Issue on %s', $site_name );
  $msg = sprintf( 'Hi %s,',ucfirst( $user_nicename ) );
  $msg .= '<br /><br /><p>the plugin Restore PayPal Standard For WooCommerce will be soon removed from the repository.</p>';
  $msg .= '<p>It means there will be issues with the future updates of the plugin.</p>';
  $msg .= '<br /><p>However, we are offering a very special price for a lifetime license.</p>';
  $msg .= sprintf( '<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a> for more information.</p>', 'https://shop.josemortellaro.com/downloads/restore-paypal-standard-for-woocommerce/', 'Read here' );
  $msg .= '<br /><p>We want to make the transition as smooth as possible.</p>';
  $msg .= '<p>As a current user you have the option to upgrade at an incredible price, but you need to do it quickly.</p>';
  $msg .= '<p>If you do it now, you will even have a coupon that you can use for your next purchase.</p>';
  $msg .= '<p>We’ll continue offering basic support for the free version for a very limited time.</p>';
  $msg .= '<p>Then we will remove the plugin from the repository, and we will sell the PRO version at a normal price.</p>';
  $msg .= '<br /><br /><p><a style="text-decoration:none;color:#253042 !important;background-color:#19C7DB !important;font-size:20px !important;padding:5px 10px !important;border-bottom-right-radius:20px !important;border-bottom-left-radius:20px !important;border-top-right-radius:20px !important;border-top-left-radius:20px !important;display:inline-block !important" href="https://shop.josemortellaro.com/downloads/restore-paypal-standard-for-woocommerce/" rel="noopener">GET THE LIFETIME ONLY NOW FOR 9 €</a></p>';
  $msg .= '<br /><br /><br /><p>Have a great day!</p>';
  $msg .= '<br /><p>Jose</p>';
  $sent = wp_mail( sanitize_email( $user_email ), wp_kses_post( $subject ), wp_kses_post( $msg ), array( 'Content-Type: text/html; charset=UTF-8' ) );
  if( $sent ) {
    update_site_option( 'eos_rpsfw_sent_' . sanitize_key( $month ), 'sent' );
  }
}