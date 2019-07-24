<?php

/*
Plugin Name: WooCommerce Coupon Gateway
Plugin URI: https://wordpress.org/plugins/password-protected/
Description: A very simple way to quickly password protect your WordPress site with a single password. Please note: This plugin does not restrict access to uploaded files and images and does not work with some caching setups.
Version: 1.0.0
Author: DarnGood LLC
Text Domain: woocommerce-coupon-gateway
License: GPLv2
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

// defined( 'WCG_COOKIE_NAME' ) or define( 'WCG_COOKIE_NAME', 'wcg_code');

add_action('init', 'check_query_string_coupon_code', 1);

function check_query_string_coupon_code() {
    global $current_user;
    $cookie_name = 'wcg_code';
    $coupon_code = null;

    //setcookie( 'fucking_cookies' , 'how about now cookie?', time() + 31556926 );

    // if user is admin, allow thru to site
    if ( in_array( 'administrator', $current_user->roles) || is_admin() || is_login_page() ) {
        return;
    } else if ( $_GET['wcg'] && $_GET['wcg'] != null ) {
        $coupon_code = $_GET['wcg'];
        
        // Check if the coupon code is valid
        // If so, save the coupon code as a cookie
        wcg_check_code_validity($coupon_code);
        setcookie( $cookie_name, $coupon_code);
        
        echo 'query string: '. $coupon_code;
        return;
    } else if ( isset($_COOKIE[ $cookie_name ] )) {
        $coupon_code = $_COOKIE[ $cookie_name ];
        
        // Check if the coupon code is STILL valid
        // If so, let them in
        wcg_check_code_validity($coupon_code);
 
        echo 'valid cookie is set: ' . $coupon_code;
        return;
    } else {
        wcg_check_code_validity($coupon_code);
        return;
    }
}

function is_login_page() {
    return in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'));
}


function wcg_check_code_validity($coupon_code) {
    // See if 'coupon_code' is a valid coupon code
    // if valid, return true
    // else, return false
    $coupon = new WC_Coupon( $coupon_code );
    $coupon_data = $coupon->get_data();

    if ( $coupon_code == null ) {
        echo __('You are not authorized to view this website.', 'woocommerce-coupon-gateway');
        die();
    } else if ( $coupon_data[id] == 0 ) {
        echo __('The link you used is not valid.', 'woocommerce-coupon-gateway');
        die();
    } else if ( $coupon_data['usage_count'] >= $coupon_data['usage_limit'] ) {
        echo __('The link you used has expired.', 'woocommerce-coupon-gateway');
        die();
    }

    
    return true;
}