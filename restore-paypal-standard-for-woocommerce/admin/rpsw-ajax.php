<?php
defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_eos_fdp_dismiss_suggestion_notice','eos_fdp_dismiss_suggestion_notice' );
//Dismiss admin notice
function eos_fdp_dismiss_suggestion_notice(){
  if( isset( $_POST['nonce'] ) && wp_verify_nonce( sanitize_text_field( $_POST['nonce'] ),'fdp_dismiss_suggestion_notices' ) ){
    $user_meta = get_user_meta( get_current_user_id(), 'dismissed_wp_notices', true );
    if( !$user_meta || !is_array( $user_meta ) ){
      $user_meta = array();
    }
    $user_meta['fdp_dismiss_suggestion_notice'] = 'dismissed';
    update_user_meta( get_current_user_id(),'dismissed_wp_notices',$user_meta );
    echo 1;
  }
  die();
  exit;
}
