<?php
defined( 'ABSPATH' ) || exit; // Exit if accessed directly

add_action( 'admin_notices',function(){
  $active_plugins = get_option( 'active_plugins' );
  if( $active_plugins && is_array( $active_plugins ) ){
    $n = count( $active_plugins );
    if( $n > 20 && !defined( 'EOS_DP_PLUGIN_BASE_NAME' ) && !file_exists( WP_PLUGIN_DIR.'/freesoul-deactivate-plugins/freesoul-deactivate-plugins.php' ) ){
      if( !class_exists( 'PluginOrganizer' ) && !class_exists( 'Plf_setting' ) ){
        $user_meta = get_user_meta( get_current_user_id(), 'dismissed_wp_notices', true );
        if( !$user_meta || !isset( $user_meta['fdp_dismiss_suggestion_notice'] ) || 'dismissed' !== $user_meta['fdp_dismiss_suggestion_notice'] ){
          $url = admin_url( 'plugin-install.php?tab=plugin-information&plugin=freesoul-deactivate-plugins' );
          ?>
          <div id="fdp-suggestion-notice" class="updated notice notice-warning is-dismissible" style="display:block !important">
            <?php wp_nonce_field( 'fdp_dismiss_suggestion_notices','fdp_dismiss_suggestion_notices' ); ?>
            <p>You have <?php echo $n; ?> active plugins. Normally, WordPress loads all of them everywhere, no matter if they do something useful.</p>
            <p>If you have many heavy plugins, they may slow down the page loading.</p>
            <p>Did you know there is a way to load them only where needed?</p>
            <p>In your case <a href="<?php echo esc_url( $url ); ?>" target="_fdp_details">Freesoul Deactivate Plugins</a> may help you to get rid of so many plugins.
            <p><a class="button" href="<?php echo esc_url( $url ); ?>" target="_fdp_details">More about Freesoul Deactivate Plugins</a></p>
          </div>
          <?php
        }
      }
    }
  }
} );

add_action( 'admin_footer',function(){
  ?>
  <script>
  function fdp_dismiss_suggestion_notices(){
    document.getElementById('fdp-suggestion-notice').addEventListener('click',function(e){
      var r = new XMLHttpRequest(),f=new FormData(),b=e.target;
      if(e.target===this.getElementsByClassName('notice-dismiss')[0]){
        f.append("nonce",document.getElementById('fdp_dismiss_suggestion_notices').value);
        r.open("POST",'<?php echo admin_url( 'admin-ajax.php' ); ?>?action=eos_fdp_dismiss_suggestion_notice',true);
        r.send(f);
      }
    });
  }
  fdp_dismiss_suggestion_notices();
  </script>
  <?php
},9999999 );
