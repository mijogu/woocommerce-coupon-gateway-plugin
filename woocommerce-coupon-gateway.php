<?php

/*
Plugin Name: WooCommerce Coupon Gateway
Description: This plugin is designed to prevent users from accessing a WooCommerce-anabled WordPress website unless they are admins or they have a valid Coupon code.
Version: 1.0.0
Author: DarnGood LLC
Text Domain: woocommerce-coupon-gateway
License: GPLv2
*/


/////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////
//   PHASE 1 : COUPON CODE / COOKIES 
/////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );
defined( 'WCG_COOKIE_CODE' ) or define( 'WCG_COOKIE_CODE', 'wcg_code' );
defined( 'WCG_COOKIE_NAME' ) or define( 'WCG_COOKIE_NAME', 'wcg_name' );
define( 'WCG_TESTING', false);

// if ( strpos( get_site_url(), '.local') ) {
//     define( 'WCG_TESTING', true);
// }

//add_action('init', 'check_query_string_coupon_code', 10);
// add_action('wp_loaded', 'check_query_string_coupon_code', 10);
add_action('parse_request', 'check_query_string_coupon_code', 10);

function check_query_string_coupon_code() {
        
    global $current_user;
    $cookie_code = WCG_COOKIE_CODE;
    $cookie_name = WCG_COOKIE_NAME;

    $coupon_code = '';
    $thank_you_page = get_thank_you_page();

    $first_name = trim($_GET['fname']);
    if ( !empty( $first_name ) ) {
        setcookie( $cookie_name, $first_name);
    }

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
        setcookie( $cookie_code, $coupon_code);
        wcg_check_page_access();
        output_testing_info('query string has valid code: '. $coupon_code );
    } else if ( isset($_COOKIE[ $cookie_code ] )) {
        $coupon_code = $_COOKIE[ $cookie_code ];
        
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
    $url_parts = explode('?', $_SERVER[ REQUEST_URI ], 2);

    if ( in_array( $url_parts[0], [ '/', '/delivery-information'] ) 
        || strpos( $url_parts[0], '/product') == 0
    ) {
        return;
    } else {
        wp_redirect( site_url() );
        exit;
    }    
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
    $coupon_code = $_COOKIE[ WCG_COOKIE_CODE ];
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

// After completed purhcase, redirect to Thank You page
add_action( 'template_redirect', 'wcg_custom_redirect_after_purchase' );
function wcg_custom_redirect_after_purchase() {
	global $wp;
	if ( is_checkout() && !empty( $wp->query_vars['order-received'] ) ) {
        $thank_you_page = get_thank_you_page();
        wp_redirect( site_url( "/$thank_you_page/" ) );
		exit;
	}
}


// Shortcode that displays cookie data
function wcg_cookie( $atts ) {  
    extract( shortcode_atts( array(
        'cookie' => 'cookie',
    ), $atts ) );
    return $_COOKIE[$cookie];  
}
add_shortcode('wcg_cookie', 'wcg_cookie'); 




/////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////
//                        PHASE 2 : API ADDITIONS 
/////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////




// Register custom fields with the REST API
add_action( 'rest_api_init', 'wcg_api_init');

function wcg_api_init() {
    $custom_meta_fields = array(
        'status',
        'first_name',
        'last_name',
        'address_1',
        'address_2',
        'city',
        'state',
        'zip',
        'phone',
    );

    foreach ( $custom_meta_fields as $field ) {
        register_rest_field( 
            'user', 
            $field, 
            array(
                'get_callback'      => 'wcg_get_usermeta_cb',
                'update_callback'   => 'wcg_update_usermeta_cb'
            )
        );
    }

    // "coupon_code" needs a specific update_callback
    // that will create a new Coupon and assign the code to this field
    register_rest_field(
        'user',
        'coupon_code',
        array(
            'get_callback'      => 'wcg_get_usermeta_cb',
            'update_callback'   => 'wcg_update_coupon_code_cb'
        )
    );


    // "products_viewed" needs a specific get_callback
    // NOTE: the API will NOT UPDATE this field -- this feature should not be needed
    register_rest_field(
        'user',
        'products_viewed',
        array(
            'get_callback'      => 'wcg_get_user_products_viewed_cb',
            'update_callback'   => 'wcg_update_usermeta_cb'
        )
    );
}

// The value must be set to 'createcoupon' in order for a 
// coupon to be generated and assigned to the user
function wcg_update_coupon_code_cb( $value, $user, $field_name ) {
    if ( $value == 'createcoupon' ) {
        $email = $user->data->user_email;
        $value = generate_coupon( $email, $user->ID );
    } 
    return update_user_meta( $user->ID, $field_name, $value );
}


function generate_coupon( $email, $user_id ) {
    // Generate coupon code from hashed email address
    // This should guarantee uniqueness, since there 
    // won't be duplicate email addresses for users. 
    $coupon_code = hash( 'md5', $email, false ) . "*$user_id";
    
    // Create coupon and get ID
    $coupon = array(
        'post_title'    => $coupon_code,
        'post_content'  => '',
        'post_status'   => 'publish',
        'post_author'   => 1, 
        'post_type'     => 'shop_coupon'
    );
    $new_coupon_id = wp_insert_post( $coupon );

    if ( $new_coupon_id > 0 ) {
        
        // Add coupon meta
        update_post_meta( $new_coupon_id, 'discount_type', 'fixed_cart' );
        update_post_meta( $new_coupon_id, 'coupon_amount', '100.00' );
        update_post_meta( $new_coupon_id, 'usage_limit', 1 );
        update_post_meta( $new_coupon_id, 'free_shipping', false );
        // update_post_meta( $new_coupon_id, 'individual_use', false );
        // update_post_meta( $new_coupon_id, 'product_ids', '' );
        // update_post_meta( $new_coupon_id, 'exclude_product_ids', '' );
        // update_post_meta( $new_coupon_id, 'expiry_date', '' );
        // update_post_meta( $new_coupon_id, 'apply_before_tax', 'yes' );

    } else {
        $coupon_code = "ERROR_COUPON_NOT_CREATED";
    }

    return $coupon_code;
}


function wcg_get_user_products_viewed_cb( $user, $field_name, $request ) {
    //$products = get_user_meta( $user['id'], $field_name, false );
    $userId = 'user_' . $user['id'];
    $field = get_field( $field_name, $userId );
    $products = array();
    if ( have_rows( $field_name, $userId ) ) {
        while ( have_rows( $field_name, $userId ) ) {
            the_row();
            $products[] = array(
                get_sub_field( "product_id"),
                get_sub_field( "product_name"),
                get_sub_field( "date_viewed")
            );
        }
    }
    return $products;
}


function wcg_get_usermeta_cb( $user, $field_name, $request ) {
    return get_user_meta( $user['id'], $field_name, true);
}
function wcg_update_usermeta_cb( $value, $user, $field_name ) {
    return update_user_meta( $user->ID, $field_name, $value );
}


if ( class_exists('ACF') ) {
    
    // Save ACF fields automatically
    add_filter( 'acf/settings/save_json', function() {
        return dirname(__FILE__) . '/acf-json';
    });

    // Load ACF fields automatically
    add_filter( 'acf/settings/load_json', function( $paths ) {
        $paths[] = dirname( __FILE__ ) . '/acf-json'; 
        return $paths;    
    });
}

add_action('wp', 'wcg_record_product_page_visit', 10);

// Save user visit to a product page
    // fields:
        // product_id
        // product_name
        // date_viewed -- Y-m-d H:i:s
function wcg_record_product_page_visit() {
    global $post;

    // If user views a product page, record the visit.
    if ( is_product() ) {
        // Only record the visit for users that have a 
        // cookie with a coupon code. 
        $user_id = wcg_get_customer_id_by_coupon_cookie();
        if ( $user_id > 0 ) {
            
            $row = array(
                'product_id'    => $post->ID,
                'product_name'  => $post->post_title,
                'date_viewed'   => current_time( 'Y-m-d H:i:s' )
            );
            add_row( 'products_viewed', $row, 'user_'.$user_id );
        }
    }
}

// Override / populate checkout fields
// https://docs.woocommerce.com/document/tutorial-customising-checkout-fields-using-actions-and-filters/
// https://gist.github.com/Bobz-zg/1d59f0835678c3787597121255a959d3
add_filter('woocommerce_checkout_get_value', 'wcg_populate_checkout_fields', 10, 2 );

// Our hooked in function - $fields is passed via the filter!
function wcg_populate_checkout_fields( $input, $key ) {
    $user_id = wcg_get_customer_id_by_coupon_cookie();
    $user_key = 'user_' . $user_id;
    
    switch ($key) :
		case 'billing_first_name':
            return get_user_meta( $user_id, 'first_name', true );
            break;            
        case 'billing_last_name':
            return get_user_meta( $user_id, 'last_name', true );
            break;
		case 'billing_address_1':
            return get_user_meta( $user_id, 'address_1', true );
            break;
		case 'billing_address_2':
            return get_user_meta( $user_id, 'address_2', true );
            break;
		case 'billing_city':
            return get_user_meta( $user_id, 'city', true );
            break;
		case 'billing_state':
            return get_user_meta( $user_id, 'state', true );
            break;
		case 'billing_postcode':
            return get_user_meta( $user_id, 'zip', true );
            break;
		case 'billing_phone':
            return get_user_meta( $user_id, 'phone', true );
            break;
		case 'billing_email':
            $user_data = get_userdata( $user_id );
            return $user_data->user_email;
            break;
	endswitch;
    return $fields;
}

// Get the current user OBJECT by the Coupon Code cookie
function wcg_get_customer_object_by_coupon_cookie() {
    $user_id = wcg_get_customer_id_by_coupon_cookie();
    if ( $user_id > 0 ) {
        $user = get_user_by( 'ID', $user_id );
        return $user;
    }
    return false;
}

// Get the current user's ID from the Coupon Code cookie
function wcg_get_customer_id_by_coupon_cookie() {
    if ( isset( $_COOKIE[ WCG_COOKIE_CODE ] ) ) {
        $coupon_code = $_COOKIE[ WCG_COOKIE_CODE ];
        $user_id = substr( $coupon_code, strrpos( $coupon_code, '*') + 1 );
        return $user_id;
    }
}
