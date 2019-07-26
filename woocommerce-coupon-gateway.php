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
defined( 'WCG_COOKIE' ) or define( 'WCG_COOKIE', 'wcg_code' );
define( 'WCG_TESTING', false);

// if ( strpos( get_site_url(), '.local') ) {
//     define( 'WCG_TESTING', true);
// }

add_action('init', 'check_query_string_coupon_code', 1);

function check_query_string_coupon_code() {
    global $current_user;
    $cookie_name = WCG_COOKIE;
    $coupon_code = '';
    $thank_you_page = get_thank_you_page();

    // if user is admin, allow thru to site

    if ( in_array( 'administrator', $current_user->roles) 
            || is_admin() 
            || is_login_page()
            //|| $_REQUEST['wc-ajax'] 
            || is_thank_you_page()
            ) {
        output_testing_info( 'you are authorized');
        //return;
    } else if ( trim($_GET['wcg']) != "" ) {
        $coupon_code = trim($_GET['wcg']);
        
        // Check if the coupon code is valid
        // If so, save the coupon code as a cookie
        wcg_check_code_validity($coupon_code);
        setcookie( $cookie_name, $coupon_code);
        output_testing_info('query string has valid code: '. $coupon_code );
    } else if ( isset($_COOKIE[ $cookie_name ] )) {
        $coupon_code = $_COOKIE[ $cookie_name ];
        
        // Check if the coupon code is STILL valid
        // If so, let them in
        wcg_check_code_validity($coupon_code);
        output_testing_info( 'cookie has valid code: ' . $coupon_code); 
    } else {
        wcg_check_code_validity($coupon_code);
    }
}

function is_login_page() {
    return in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'));
}

function is_thank_you_page() {
    $thank_you_page = get_thank_you_page();
    $is_thank_you = strpos( $_SERVER['REQUEST_URI'], $thank_you_page ); 
    return $is_thank_you;
}

function get_thank_you_page() {
    return 'thank-you';
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
        // echo __('The link you used has expired.', 'woocommerce-coupon-gateway');
        // die();
        $thank_you_page = get_thank_you_page();
        wp_redirect( $thank_you_page );
    }    
}

function output_testing_info( $text ) {
    if ( WCG_TESTING == true) {
        ?>
        <div style="color: white; background-color: #666; padding: 30px; text-align: right;">
            <p style="margin-bottom:0;"><?php echo $text; ?></p>
        </div>
        <?php 
    }
}

add_action( 'woocommerce_payment_complete', 'wcg_mark_coupon_used', 10, 1);

function wcg_mark_coupon_used( $order_id ) {
    $order = new WC_Order( $order_id );
    $coupon_code = $_COOKIE[ WCG_COOKIE ];
    $order->apply_coupon( $coupon_code );
    output_testing_info( "Coupon '". $coupon_code. "' has been used!" );
}

