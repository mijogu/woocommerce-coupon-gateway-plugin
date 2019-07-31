<?php

/*
Plugin Name: WooCommerce Coupon Gateway
Description: This plugin is designed to prevent users from accessing a WooCommerce-anabled WordPress website unless they are admins or they have a valid Coupon code.
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
            || is_thank_you_page()
            || is_oops_page()
        ) {
        output_testing_info( 'you are authorized');
        //return;
    } else if ( trim($_GET['wcg']) != "" ) {
        $coupon_code = trim($_GET['wcg']);
        
        // Check if the coupon code is valid
        // If so, save the coupon code as a cookie
        wcg_check_code_validity($coupon_code);
        setcookie( $cookie_name, $coupon_code);
        wcg_check_page_access();
        output_testing_info('query string has valid code: '. $coupon_code );
    } else if ( isset($_COOKIE[ $cookie_name ] )) {
        $coupon_code = $_COOKIE[ $cookie_name ];
        
        // Check if the coupon code is STILL valid
        // If so, let them in
        wcg_check_code_validity($coupon_code);
        wcg_check_page_access();
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

function is_oops_page() {
    $oops_page = get_oops_page();
    $is_oops_page = strpos( $_SERVER['REQUEST_URI'], $oops_page );
    return $is_oops_page;
}

function get_thank_you_page() {
    return 'congrats';
}

function get_oops_page() {
    return 'oops';
}

function wcg_check_page_access() {
    /*
    if ( is_product() 
        || is_page( 'delivery-information' ) 
        // || is_home()
        || is_page( '/' ) 
    ) {
        return;
    } else {
        wp_redirect( site_url() );
        exit;
    }
    */
    return true;
}

function wcg_check_code_validity($coupon_code) {
    // Check to see if 'coupon_code' is a valid coupon code
    $coupon = new WC_Coupon( $coupon_code );
    $coupon_data = $coupon->get_data();

    if ( $coupon_code == null ) {
        // No code was given
        $oops_page = get_oops_page();
        wp_redirect( site_url( "/$oops_page/" ) );
        exit;
    } else if ( $coupon_data[id] == 0 ) {
        // Code was given, but is invalid/incomplete
        $oops_page = get_oops_page();
        wp_redirect( site_url( "/$oops_page/" ) );
        exit;
    } else if ( $coupon_data['usage_count'] >= $coupon_data['usage_limit'] ) {
        // Code is valid, but has reached usage limit
        $thank_you_page = get_thank_you_page();
        wp_redirect( site_url( "/$thank_you_page/" ) );
        exit;
    }    
}

// For testing purposes only, will cause unexpected results if used in production
function output_testing_info( $text ) {
    if ( WCG_TESTING == true) {
        ?>
        <div style="color: white; background-color: #666; padding: 30px; text-align: right;">
            <p style="margin-bottom:0;"><?php echo $text; ?></p>
        </div>
        <?php 
    }
}

// After a successful transaction, apply the coupon code
// that was saved as a cookie to the Order. 
add_action( 'woocommerce_payment_complete', 'wcg_mark_coupon_used', 10, 1);
function wcg_mark_coupon_used( $order_id ) {
    $order = new WC_Order( $order_id );
    $coupon_code = $_COOKIE[ WCG_COOKIE ];
    $order->apply_coupon( $coupon_code );
    output_testing_info( "Coupon '". $coupon_code. "' has been used!" );
}

// Add "gift review" section before checkout
add_action( 'woocommerce_review_order_before_payment', 'wcg_gift_review' );
function wcg_gift_review() {

    global $woocommerce;
    $items = $woocommerce->cart->get_cart();
    $int = 0;

    foreach($items as $item => $values) { 
        if ($int > 0) break; // Should only be 1 item in cart, but just sanity checking that we're only showing 1.
        
        $product =  wc_get_product( $values['data']->get_id() );
        $product_detail = wc_get_product( $values['product_id'] );

        echo '<div id="gift_review">'
            . '<div class="gift_header">Your Gift</div>'
            . $product_detail->get_image( 'thumbnail' ) //( size, attr )
            . '<div class="gift_prod_title">' . $product->get_title() . '</div>'
            . '<a href="/">Select a different gift</a>'
            . '</div>';
    } 
}

// Changing Add to Cart button text to custom text in individual product pages
add_filter('woocommerce_product_single_add_to_cart_text', 'wcg_custom_cart_button_text');
function wcg_custom_cart_button_text() {
    return __('Select Gift', 'woocommerce');
}

//  Skip cart, go straight to checkout
add_filter('woocommerce_add_to_cart_redirect', 'wcg_add_to_cart_redirect');
function wcg_add_to_cart_redirect() {
    global $woocommerce;
    $checkout_url = wc_get_checkout_url();
    return $checkout_url;
}


// Change "place order" button text
add_filter( 'woocommerce_order_button_text', 'wcg_rename_place_order_button' );
function wcg_rename_place_order_button() {
   return 'Send Gift'; 
}

// Empty cart before adding new item
add_filter( 'woocommerce_add_to_cart_validation', 'wcg_remove_cart_item_before_add_to_cart', 20, 3 );
function wcg_remove_cart_item_before_add_to_cart( $passed, $product_id, $quantity ) {
    if( ! WC()->cart->is_empty())
        WC()->cart->empty_cart();
    return $passed;
}

// After comple
add_action( 'template_redirect', 'wcg_custom_redirect_after_purchase' );
function wcg_custom_redirect_after_purchase() {
	global $wp;
	if ( is_checkout() && !empty( $wp->query_vars['order-received'] ) ) {
        $thank_you_page = get_thank_you_page();
        wp_redirect( site_url( "/$thank_you_page/" ) );
		exit;
	}
}
