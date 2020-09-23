<?php

/**
 * Plugin Name: WooCommerce Coupon Gateway
 * Description: This plugin is designed to prevent users from accessing a WooCommerce-anabled WordPress website unless they are admins or they have a valid Coupon code.
 * Version: 1.16
 * Author: DarnGood LLC
 * Text Domain: woocommerce-coupon-gateway
 * License: GPLv2
 */


/////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////
//   PHASE 1 : COUPON CODE / COOKIES 
/////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////

defined('ABSPATH') or die('No script kiddies please!');

// Define $_COOKIE-related constants
defined('WCG_CODE_COOKIE')      or define('WCG_CODE_COOKIE', 'wcg_code');
defined('WCG_REDIRECT_COOKIE')  or define('WCG_REDIRECT_COOKIE', 'wcg_redirect');
defined('WCG_COOKIE_NAME')      or define('WCG_COOKIE_NAME', 'wcg_name');

// Define API related constants
defined('WCG_USERS_PER_PAGE')   or define('WCG_USERS_PER_PAGE', 1000);

// Define important endpoints
defined('WCG_WELCOME_PAGE')     or define('WCG_WELCOME_PAGE', 'welcome');
defined('WCG_OOPS_PAGE')        or define('WCG_OOPS_PAGE', 'oops');
defined('WCG_THANKYOU_PAGE')    or define('WCG_THANKYOU_PAGE', 'congrats');
defined('WCG_CHECKOUT_PAGE')    or define('WCG_CHECKOUT_PAGE', 'delivery-information');

// Changing this from 'parse_request' to 'parse_query' seems to yield
// unwanted results. COOKIES don't work on first page visit. 
add_action('parse_request', 'wcg_check_query_string_coupon_code', 10);
// add_action('parse_query', 'wcg_check_query_string_coupon_code', 10);

function wcg_check_query_string_coupon_code()
{    
    global $current_user;
    $coupon_cookie = WCG_CODE_COOKIE;
    $coupon_code = '';
    $oops = WCG_OOPS_PAGE;
    $thanks = WCG_THANKYOU_PAGE;
    $welcome = WCG_WELCOME_PAGE;

    // if user is admin, let thru
    if (isset($_REQUEST['wc-ajax'])) {
        return;
    } elseif (in_array('administrator', $current_user->roles)){
        return;
    } elseif (
        strpos($_SERVER['REQUEST_URI'], $oops) == 1 || 
        strpos($_SERVER['REQUEST_URI'], $thanks) == 1 ||
        strpos($_SERVER['REQUEST_URI'], $welcome) == 1 
        ) {
        return;
    }
    
    // else check for code
    if (isset($_GET['wcg'])) {
        $coupon_code = trim($_GET['wcg']);
    } elseif (isset($_COOKIE[$coupon_cookie])) {
        $coupon_code = $_COOKIE[$coupon_cookie];               
    }

    // confirm code is valid
    // will redirect to OOPS if not valid
    // set 2 possible cookies
    // wcg_check_code_validity($coupon_code, $set_new_cookie); 
    $new_type_cookie = wcg_check_code_validity($coupon_code); 

    // when this is called, we've already confirmed the valid code
    wcg_is_accessible_page($new_type_cookie);
}

function is_login_page() 
{
    // This is deprecated according to:
    // https://wordpress.stackexchange.com/questions/12863/check-if-wp-login-is-current-page
    // return in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'));
    return $GLOBALS['pagenow'] === 'wp-login.php';
}


// Return true if page is allowed
// Redirect if page is not allowed
// Using the $new_type_cookie param is necessary on the initial page visit
function wcg_is_accessible_page($new_type_cookie = '') 
{
    $allowable_pages = array();

    // $allowable_pages[] = WCG_OOPS_PAGE; // this isn't needed bc oops is checked earlier
    $allowable_pages[] = WCG_THANKYOU_PAGE;
    $allowable_pages[] = WCG_CHECKOUT_PAGE;
    $allowable_pages[] = 'product';

    // TO DO need to check Products for type/category

    // $allowable_pages = array(, "", "product");
    $redirect_cookie = WCG_REDIRECT_COOKIE;
    $redirect_to = "/";
    $access = false;
    if($new_type_cookie != '') { 
        $redirect_to = $new_type_cookie;
    } elseif (isset($_COOKIE[$redirect_cookie])) {
        $redirect_to = $_COOKIE[$redirect_cookie];
    }
    $allowable_pages[] = $redirect_to;

    $url_parts = explode('?', $_SERVER['REQUEST_URI'], 2);

    foreach ($allowable_pages as $page) {
        if ($page == "/") {
            $access = $url_parts[0] == "/" ? true : false;
            // $access = true;
            break;
        } else {
            $pos = strpos($url_parts[0], $page);
            if ($pos !== false) {
                $access = true;
                break;
            }
        }
    } 

    if (!$access) {
        wp_redirect(site_url($redirect_to));
        exit;
    }

    return true;
}


function wcg_check_code_validity($coupon_code)
{
    // Check to see if 'coupon_code' is a valid coupon code
    $coupon = new WC_Coupon($coupon_code);
    $coupon_data = $coupon->get_data();
    $coupon_cookie = WCG_CODE_COOKIE;
    $redirect_cookie = WCG_REDIRECT_COOKIE;
    $coupon_type = '';

    if ($coupon_code == null || $coupon_data['id'] == 0) {
        // No code / invalid code was given
        $oops = WCG_OOPS_PAGE;
        wp_redirect(site_url($oops));
        exit;
    } elseif ($coupon_data['usage_count'] >= $coupon_data['usage_limit']){
        // Code is valid, but has reached usage limit
        $thanks = WCG_THANKYOU_PAGE;
        wp_redirect(site_url($thanks));
        exit;
    }

    if (!isset($_COOKIE[$coupon_cookie]) || $_COOKIE[$coupon_cookie] != $coupon_code) {
        // set cookie to expire (in a month)
        $expire = time()+86400*30;
        setcookie($coupon_cookie, $coupon_code, $expire);

        $user_id = wcg_get_customer_id_by_coupon_code($coupon_code);
        $coupon_data = wcg_get_coupon_data($coupon_code, null, $user_id);
        if (isset($coupon_data['type'])) {
            setcookie($redirect_cookie, $coupon_data['type'], $expire);
            $coupon_type = $coupon_data['type'];
        }
    }

    // returns the coupon type when cookie is set
    return $coupon_type;
}


// After a successful transaction, apply the coupon code
// that was saved as a cookie to the Order. 
//
// And update the coupon_status for the user to 'giftSelected' or 'giftConfirmed'
add_action('woocommerce_payment_complete', 'wcg_mark_coupon_used', 10, 1);
function wcg_mark_coupon_used($order_id)
{
    $order = new WC_Order($order_id);
    $order_items = $order->get_items();

    $coupon_code = $_COOKIE[WCG_CODE_COOKIE];
    $order->apply_coupon($coupon_code);

    $userID = wcg_get_customer_id_by_coupon_code($coupon_code);

    $coupon_data = wcg_get_coupon_data($coupon_code, '', $userID);
    if (is_wp_error($coupon_data)) {
        return $coupon_data;
    }

    $coupon_status = 'giftSelected';
    
    if ($coupon_data['is_confirmed'] == true) {
        $coupon_status = 'giftConfirmed';
        wcg_trigger_confirmed_email($order_id);
    }

    $row_number = $coupon_data['row_number'];

    $row_data = array(
        'coupon_status' => $coupon_status,
        'date_last_updated' => date('Y-m-d H:i:s'),
        'date_checkout' => date('Y-m-d H:i:s')
   );

    // check if user changed their default address
    $order_address = $order->get_address('shipping');
    $address_changed = '';
    if (wcg_was_address_changed($order_address, $userID)) {
        $row_data['is_address_changed'] = true;
    }

    // add the purchase information to coupon row
    // there should only be 1 product, but WooCommerece wants us to
    // use the loop regardless. 
    foreach ($order_items as $item){
        $row_data['product_id'] = $item->get_product_id();
        $row_data['product_name'] = $item->get_name();
        $row_data['order_id'] = $order_id;
        $row_data['address_changed'] = $address_changed;
    }

    update_row('coupons', $row_number, $row_data, "user_$userID");
}

function wcg_was_address_changed($order_address, $user_id)
{
    if (
        $order_address['address_1'] != get_field('address_1', "user_$user_id")
        || $order_address['address_2'] != get_field('address_2', "user_$user_id")
        || $order_address['city'] != get_field('city', "user_$user_id") 
        || $order_address['state'] != get_field('state', "user_$user_id")
        || $order_address['postcode'] != get_field('zip', "user_$user_id")
  ){
        return true;
    }
    return false;

}

// Get the row number of the active coupon for the user
// By default, will search by coupon_code
// else it will search by vehicle_id. 
// Returns all current data for coupon, including the row_number.
function wcg_get_coupon_data($coupon_code, $vehicle_id, $user_id)
{    
    $field_name = null;
    $field_value = null;
    $is_confirmed = null;
    $date_checkout = '';
    $type = null;

    if (!empty($coupon_code)) {
        // if coupon_code is supplied, find row by coupon_code
        $field_name = 'coupon_code';
        $field_value = $coupon_code;
    } elseif (!empty($vehicle_id)) {
        // else if vehicle_id is supplied, find row by vehicle_id
        $field_name = 'vehicle_id';
        $field_value = $vehicle_id;
    } else {
        // return error
        return new WP_ERROR('missing_coupon_identifier', 'You did not specify the coupon that needs updating. Please provide either the coupon_code or vehicle_id.');
        //throw new \Exception('You did not specify the coupon that needs updating. Please provide either the coupon_code or vehicle_id.');
    }
    
    $row_number = 0;
    if (have_rows('coupons', "user_$user_id")) {
        while(have_rows('coupons', "user_$user_id")) {
            the_row();
            // $my_row = get_row();
            if (get_sub_field($field_name) == $field_value) {
                $row_number = get_row_index();
                $coupon_code = get_sub_field('coupon_code');
                $vehicle_id = get_sub_field('vehicle_id');
                $coupon_status = get_sub_field('coupon_status');
                $order_id = get_sub_field('order_id');
                // $is_confirmed = get_sub_field('is_confirmed') === 'true' ? 'true' : '';
                $is_confirmed = get_sub_field('is_confirmed');
                $date_checkout = get_sub_field('date_checkout');
                $type = get_sub_field('type');
                break;
            } 
        }
    }    

    // throw error if there's no row to change
    if ($row_number == 0) {
        return new WP_ERROR('coupon_not_found', 'Could not locate this coupon for this user.');
        // throw new \Exception('Could not locate this coupon for this user.');
    }

    // other fields not being returned: product_id, product_name, is_address_changed, date_last_updated
    return array(
        'row_number' => $row_number,
        'coupon_code' => $coupon_code,
        'coupon_status' => $coupon_status,
        'vehicle_id' => $vehicle_id,
        'order_id' => $order_id, 
        'is_confirmed' => $is_confirmed,
        'date_checkout' => $date_checkout,
        'type' => $type
   );
}

// Add "gift review" section before checkout
add_action('woocommerce_review_order_before_payment', 'wcg_gift_review');
function wcg_gift_review()
{
    global $woocommerce;
    $items = $woocommerce->cart->get_cart();
    $int = 0;

    foreach($items as $item => $values) { 
        if ($int > 0) break; // Should only be 1 item in cart, but just sanity checking that we're only showing 1.
        
        $product =  wc_get_product($values['data']->get_id());
        $product_detail = wc_get_product($values['product_id']);

        echo '<div id="gift_review">'
            . '<div class="gift_header">Your Gift</div>'
            . $product_detail->get_image('cart_prod')//(size, attr)
            . '<div class="gift_prod_title">' . $product->get_title() . '</div>'
            . '<a href="/">Select a different gift</a>'
            . '</div>';
    } 
}


// Empty cart before adding new item
add_filter('woocommerce_add_to_cart_validation', 'wcg_remove_cart_item_before_add_to_cart', 20, 3);
function wcg_remove_cart_item_before_add_to_cart($passed, $product_id, $quantity)
{
    $user_id = wcg_get_customer_id_by_coupon_code();
    $product = wc_get_product($product_id);
    // update Products Removed From Cart
    $row = array(
        'product_id'    => $product_id,
        'product_name'  => $product->get_name(),
        'date_added'  => date('Y-m-d H:i:s')
    );
        add_row('products_selected', $row, "user_$user_id");

    if (!WC()->cart->is_empty()) {
        WC()->cart->empty_cart();
    }
    return $passed;
}

// After completed purhcase, redirect to Thank You page
add_action('template_redirect', 'wcg_custom_redirect_after_purchase');
function wcg_custom_redirect_after_purchase()
{
	global $wp;
	if (is_checkout() && !empty($wp->query_vars['order-received'])) {
        $thank_you_page = get_thank_you_page();
        wp_redirect(site_url("/$thank_you_page/"));
		exit;
	}
}


// Shortcode that displays cookie data
function wcg_cookie($atts)
{
    extract(shortcode_atts(array(
        'cookie' => 'cookie',
  ), $atts));
    return $_COOKIE[$cookie];  
}
add_shortcode('wcg_cookie', 'wcg_cookie'); 


// Shortcode that displays user data
function wcg_user_data($atts)
{
    extract(shortcode_atts(array(
        'name' => 'name',
  ), $atts));

    $user_id = wcg_get_customer_id_by_coupon_code();
    $user_meta = get_user_meta($user_id, $name, true);

    return $user_meta;

}
add_shortcode('wcg_user_data', 'wcg_user_data');




/////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////
//                        PHASE 2 : API ADDITIONS 
/////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////




// Register custom fields with the REST API
add_action('rest_api_init', 'wcg_api_init');

function wcg_api_init()
{
    $custom_meta_fields = array(
        'carvana_uid',
        'first_name',
        'last_name',
        'address_1',
        'address_2',
        'city',
        'state',
        'zip',
        'phone',
        'coupons',
        'products_viewed',
        'products_selected',
    );

    foreach ($custom_meta_fields as $field){
        switch ($field) :
            case 'products_viewed':
                // Field does not support UPDATE, only GET
                register_rest_field(
                    'user',
                    $field,
                    array(
                        'get_callback'      => 'wcg_get_user_products_viewed_cb',
                        'update_callback'   => null
                  )
              );
            break;
            case 'coupons':
                // Update will not allow updating all fields directly
                register_rest_field(
                    'user',
                    $field,
                    array(
                        'get_callback'      => 'wcg_get_user_coupons_cb',
                        'update_callback'   => 'wcg_update_user_coupons_cb'
                  )
              );
            break;           
            case 'products_selected':
                // Field does not support UPDATE, only GET
                register_rest_field(
                    'user',
                    $field,
                    array(
                        'get_callback'      => 'wcg_get_user_products_selected_cb',
                        'update_callback'   => null
                  )
              );
                break;
            default: 
                register_rest_field(
                    'user', 
                    $field, 
                    array(
                        'get_callback'      => 'wcg_get_usermeta_cb',
                        'update_callback'   => 'wcg_update_usermeta_cb'
                  )
              );
            break;
        endswitch;
    }
}

// This is outdated. 
// The original intent was to disallow new coupon codes if user already had one active. 
function wcg_check_able_to_assign_coupon($userID)
{
    $user = 'user_' . $userID;
    if (trim(get_field('coupon_code', $user)) == "")return true;
    return false;
}

// This is likely outdated code now.
// Artifact from when there was a single "coupon_code" field.
// MGunn April 28, 2020
function wcg_update_coupon_code($value, $user_id, $field)
{
    if (strpos($value, 'createcoupon') === 0) {
        //$myvalue = $value . "xxxxxx";
        $id = substr($user_id, strrpos($user_id, '_') + 1);
        $user = get_user_by('id', $id);
        $value = generate_coupon($user->user_email, $id);
    }
    return $value;    
}
add_filter('acf/update_value/name=coupon_code', 'wcg_update_coupon_code', 10, 3);


function generate_coupon($email, $user_id)
{
    // Generate coupon code from hashed email address
    // This should guarantee uniqueness, since there 
    // won't be duplicate email addresses for users. 
    $coupon_code = hash('md5', $email, false). "-" . time(). "-$user_id";
    
    // Create coupon and get ID
    $coupon = array(
        'post_title'    => $coupon_code,
        'post_content'  => '',
        'post_status'   => 'publish',
        'post_author'   => 1, 
        'post_type'     => 'shop_coupon'
  );
    $new_coupon_id = wp_insert_post($coupon);

    if ($new_coupon_id > 0){
        // Add coupon meta
        update_post_meta($new_coupon_id, 'discount_type', 'fixed_cart');
        update_post_meta($new_coupon_id, 'coupon_amount', '100.00');
        update_post_meta($new_coupon_id, 'usage_limit', 1);
        update_post_meta($new_coupon_id, 'free_shipping', false);
    } else {
        $coupon_code = "ERROR_COUPON_NOT_CREATED";
    }

    return $coupon_code;
}

function wcg_get_user_products_viewed_cb($user, $field_name, $request)
{
    //$products = get_user_meta($user['id'], $field_name, false);
    $userID = 'user_' . $user['id'];
    $field = get_field($field_name, $userID);
    $products = array();
    if (have_rows($field_name, $userID)) {
        while (have_rows($field_name, $userID)) {
            the_row();
            $products[] = array(
                'product_id' => get_sub_field("product_id"),
                'product_name' => get_sub_field("product_name"),
                'date_viewed' => get_sub_field("date_viewed")
          );
        }
    }
    return $products;
}


function wcg_get_user_products_selected_cb($user, $field_name, $request)
{
    $userID = 'user_' . $user['id'];
    $field = get_field($field_name, $userID);
    $products = array();
    if (have_rows($field_name, $userID)) {
        while (have_rows($field_name, $userID)) {
            the_row();
            $products[] = array(
                'product_id' => get_sub_field("product_id"),
                'product_name' => get_sub_field("product_name"),
                'date_added' => get_sub_field("date_added")
          );
        }
    }
    return $products;
}

function wcg_get_user_coupons_cb($user, $field_name, $request)
{
    $userID = 'user_' . $user['id'];
    $coupons = array();
    $coupon = array();

    // This below fixes an ACF "bug" where 'have_rows' returned false 
    // in responses to Updates API calls where the 'coupons' repeater field
    // was being updated.
    if(have_rows('field_5dc31a02b5f81', $userID)) {
        while (have_rows('field_5dc31a02b5f81', $userID)) {
            the_row();
            $product_id = get_sub_field("product_id");
            $order_id = get_sub_field("order_id");
            $product = wc_get_product($product_id);
            $is_address_changed = get_sub_field("is_address_changed");
            $order = new WC_Order($order_id);
            $address = $order->get_address(); // defaults to 'billing'
            $coupon = array(
                'coupon_code' => get_sub_field("coupon_code"),
                'type' => get_sub_field("type"),
                'is_confirmed' => get_sub_field("is_confirmed"),
                'coupon_status' => get_sub_field("coupon_status"),
                'vehicle_id' => get_sub_field("vehicle_id"),
                'order_id' => $order_id,
                'tracking_number' => get_sub_field("tracking_number"),
                'tracking_link' => get_sub_field("tracking_link"),
                'carrier' => get_sub_field("carrier"),
                'product_id' => get_sub_field("product_id"),
                'product_name' => get_sub_field("product_name"),
                'product_attributes' => wcg_get_product_attributes($product),
                'product_categories' => wcg_get_product_categories($product),
                'is_address_changed' => $is_address_changed,
                'date_last_updated' => get_sub_field("date_last_updated"),
                'date_checkout' => get_sub_field('date_checkout'),
                'shipped_to_phone' => $address['phone']
            );

            // If address was changed, include address from the Order
            if ($is_address_changed) {
                // get address details from order
                $coupon['shipped_to_street1'] = $address['address_1'];
                $coupon['shipped_to_street2'] = $address['address_2'];
                $coupon['shipped_to_city'] = $address['city'];
                $coupon['shipped_to_state'] = $address['state'];
                $coupon['shipped_to_zip'] = $address['postcode'];
            }

            $coupons[] = $coupon;
        } 
    }
    return $coupons;
}

// Return array of all product attributes
function wcg_get_product_attributes($product) 
{
    if ($product == null) return ''; 

    $attributes = $product->get_attributes();
    $attr_list = array();
    foreach ($attributes as $attr) {
        $attr_name = $attr->get_name();
        $attr_label = wc_attribute_label($attr_name);
        $attr_value = '';
        foreach (wp_get_post_terms($product->get_id(), $attr_name) as $term) {
            $attr_value .= ($attr_value == '') ? $term->name : ", $term->name";
        }
        $attr_list[$attr_label] = $attr_value;
    }
    return $attr_list;
}

// Return array of all product categories
function wcg_get_product_categories($product) 
{
    if ($product == null) return ''; 

    $cat_list = '';
    foreach (wp_get_post_terms($product->get_id(), 'product_cat') as $category) {
        $cat_list .= ($cat_list == '') ? $category->name : ", $category->name" ;
    }
    return $cat_list;
}

// Return array of order notes
function wcg_get_order_notes($order_id)
{
    if ($order_id == null) return '';

    $note_list = array();
    $notes = wc_get_order_notes(array('order_id' => $order_id, 'type' => 'internal'));
    foreach ($notes as $note) {
        $note_list[] = $note->content;
    }
    return $note_list;
}

// Return order status
function wcg_get_order_status($order_id)
{
    if ($order_id == null) return '';

    $order = wc_get_order($order_id);
    $status = $order->get_status();
    // return ($status == 'completed') ? 'shipped' : $status; // TODO remove this?
    return $status;
}

// Create Coupon or Update Coupon
function wcg_update_user_coupons_cb($value, $user, $field_name)
{
    // createcoupon 
    // registered
    // giftSelected
    // giftConfirmed
    // delivered
    // cancelled 
    $new_coupon_status = array_key_exists('coupon_status', $value) ? $value['coupon_status'] : null;
    $new_vehicle_id = array_key_exists('vehicle_id', $value) ? $value['vehicle_id'] : null;
    $new_is_confirmed = array_key_exists('is_confirmed', $value) ? $value['is_confirmed'] : null;
    $new_date_updated = date('Y-m-d H:i:s');
    
    $old_coupon_code = $value['coupon_code'];
    $new_coupon_code = '';
    $coupon_type = '';
    if (strpos($old_coupon_code, 'createcoupon') === 0) { // create a new coupon
        $delim = strpos($old_coupon_code, '|');
        if ($delim) {
            $coupon_type = substr($old_coupon_code, $delim+1);
        }
        
        $email = $user->data->user_email;
        $new_coupon_code = generate_coupon($email, $user->ID);
        // if status not specified when creating coupon,
        // set to 'registered'.
        if ($new_coupon_status == null) $new_coupon_status = 'registered';
    } else {
        $new_coupon_code = $old_coupon_code;
    }

    $row = array(
        'coupon_code'       => $new_coupon_code,
        'date_last_updated' => $new_date_updated,
        'type'              => $coupon_type
    );

    if ($new_coupon_status != null) $row['coupon_status'] = $new_coupon_status;
    if ($new_vehicle_id != null) $row['vehicle_id'] = $new_vehicle_id;
    if ($new_is_confirmed != null) $row['is_confirmed'] = $new_is_confirmed;

    if (strpos($old_coupon_code, 'createcoupon') === 0) { // create a new coupon
        add_row('coupons', $row, 'user_'.$user->ID);
    } else { // update an existing coupon
        $row_data = wcg_get_coupon_data($new_coupon_code, $new_vehicle_id, $user->ID);
        if (is_wp_error($row_data)) return $row_data;
        $row_number = $row_data['row_number'];

        // check if we're setting 'is_confirmed' to true AND there was already an order
        // if so, need to update the status and trigger the notification emails.
        if (
            $new_is_confirmed == true && 
            $row_data['coupon_status'] == 'giftSelected' && 
            !empty($row_data['order_id'])
        ) {
            $row['coupon_status'] = 'giftConfirmed';
            wcg_trigger_confirmed_email($row_data['order_id']);
        }

        update_row('coupons', $row_number, $row, 'user_'.$user->ID);

        // Handle coupon cancellation. 
        if (strtolower($new_coupon_status) == 'cancelled' || strtolower($new_coupon_status) == 'canceled') {
            
            if ($row_data['order_id'] != '') {
                // Cancel, when coupon has not yet been used -- no Order.
                $order = new WC_Order($row_data['order_id']);
                $order->update_status('cancelled');
            } else {
                // Cancel, when coupon was already used -- has Order.
                $coupon = new WC_Coupon($new_coupon_code);
                wp_update_post(array('ID' => $coupon->id, 'post_status' => 'draft'));
            }
        }
    }
}

function wcg_get_usermeta_cb($user, $field_name, $request)
{
    return get_user_meta($user['id'], $field_name, true);
}
function wcg_update_usermeta_cb($value, $user, $field_name)
{
    return update_user_meta($user->ID, $field_name, $value);
}


if (class_exists('ACF')){
    
    // Save ACF fields automatically
    add_filter('acf/settings/save_json', function() {
        return dirname(__FILE__) . '/acf-json';
    });

    // Load ACF fields automatically
    add_filter('acf/settings/load_json', function($paths){
        $paths[] = dirname(__FILE__). '/acf-json'; 
        return $paths;    
    });
}

add_action('wp', 'wcg_record_product_page_visit', 10);

// Save user visit to a product page
    // fields:
        // product_id
        // product_name
        // date_viewed -- Y-m-d H:i:s
function wcg_record_product_page_visit()
{
    global $post;

    // If user views a product page, record the visit.
    if (is_product()){
        // Only record the visit for users that have a 
        // cookie with a coupon code. 
        $user_id = wcg_get_customer_id_by_coupon_code();
        if ($user_id > 0){
            
            $row = array(
                'product_id'    => $post->ID,
                'product_name'  => $post->post_title,
                'date_viewed'   => date('Y-m-d H:i:s')
           );
            add_row('products_viewed', $row, 'user_'.$user_id);
        }
    }
}

// Override / populate checkout fields
// https://docs.woocommerce.com/document/tutorial-customising-checkout-fields-using-actions-and-filters/
// https://gist.github.com/Bobz-zg/1d59f0835678c3787597121255a959d3
add_filter('woocommerce_checkout_get_value', 'wcg_populate_checkout_fields', 10, 2);

// Our hooked in function - $fields is passed via the filter!
function wcg_populate_checkout_fields($input, $key)
{
    $user_id = wcg_get_customer_id_by_coupon_code();
    $user_key = 'user_' . $user_id;
    
    switch ($key) :
		case 'billing_first_name':
            return get_user_meta($user_id, 'first_name', true);
            break;            
        case 'billing_last_name':
            return get_user_meta($user_id, 'last_name', true);
            break;
		case 'billing_address_1':
            return get_user_meta($user_id, 'address_1', true);
            break;
		case 'billing_address_2':
            return get_user_meta($user_id, 'address_2', true);
            break;
		case 'billing_city':
            return get_user_meta($user_id, 'city', true);
            break;
		case 'billing_state':
            return get_user_meta($user_id, 'state', true);
            break;
		case 'billing_postcode':
            return get_user_meta($user_id, 'zip', true);
            break;
		case 'billing_phone':
            return get_user_meta($user_id, 'phone', true);
            break;
		case 'billing_email':
            $user_data = get_userdata($user_id);
            return $user_data->user_email;
            break;
	endswitch;
    // return $fields;
}

// Get the current user OBJECT by the Coupon Code cookie
function wcg_get_customer_object_by_coupon_cookie()
{
    $user_id = wcg_get_customer_id_by_coupon_code();
    if ($user_id > 0){
        $user = get_user_by('ID', $user_id);
        return $user;
    }
    return false;
}

// Get the current user's ID from the coupon_code parameter.
// If coupon_code is not supplied, get it from the COOKIE.
function wcg_get_customer_id_by_coupon_code($coupon_code = null)
{
    if ($coupon_code != null) {
        $coupon_code = $coupon_code;
    } elseif (isset($_GET['wcg'])) {
        $coupon_code = $_GET['wcg'];
    } elseif (isset($_COOKIE[ WCG_CODE_COOKIE ])) {
        $coupon_code = $_COOKIE[ WCG_CODE_COOKIE ];
    } else {
        return false;
    }
    $user_id = substr($coupon_code, strrpos($coupon_code, '-') + 1);
    return $user_id;
}


// Create an ACF Pro Options page 
if(function_exists('acf_add_options_page'))
{
	acf_add_options_page();
}


// Increase the APIs default maximum "per_page" amount. 
// Originally, "per_page" needed to be between 1 and 100
// Also, limit results only to Subscribers
add_filter('rest_user_query', 'wcg_change_user_query', 2, 10);

function wcg_change_user_query($prepared_args, $request)
{
    // increase the total results returned
    $max = max(WCG_USERS_PER_PAGE, (int) $request->get_param('custom_per_page'));
    $prepared_args['number'] = $max;

    // exclude admins from being returned
    $prepared_args['role'] = 'Subscriber';
    
    return $prepared_args;
}

// Remove unneeded User fields from API response
add_filter('rest_prepare_user', 'wcg_modify_rest_user_response', 10, 3);

function wcg_modify_rest_user_response($response, $user, $request)
{
    unset($response->data['coupon_status']);
    unset($response->data['link']);
    unset($response->data['description']);
    unset($response->data['url']);
    unset($response->data['avatar_urls']);
    unset($response->data['meta']);
    unset($response->data['slug']);
    unset($response->data['locale']);
    unset($response->data['nickname']);
    unset($response->data['roles']);
    unset($response->data['capabilities']);
    unset($response->data['extra_capabilities']);
    return $response;
}

// Trigger an email to go out when Carvana confirms coupon
function wcg_trigger_confirmed_email($order_id)
{
    $to = get_field('wcg_notification_emails', 'option');
    $subject = "Carvana approved shipping order #$order_id";
    $message = "Chris will customize this...";
    $is_success = wp_mail($to, $subject, $message);
}


// Hook into the save_post function looking for posts with
// "delivery-notification" category. These are shipping/delivery emails 
// coming from Shipstation. 
// Parse these posts for the desired data and save to the appropriate coupon.
add_action('save_post', 'wcg_parse_shipstation_email_posts', 11, 3);

function wcg_parse_shipstation_email_posts($post_id, $post, $update) 
{    
    if (!in_category('delivery-notification', $post)) {
        return;
    } 
    
    $content = $post->post_content;    
    
    // Most recently used 
    // $strip_characters = array(
    //     "\r\n", "\r", "\n", "\t", "&nbsp;", " ", "\\u00a0", "\0", "\x0B"
    // );
    // $replace_characters = array(
    //     '', '', '', '', '', '', '', '', ''
    // );
    // $stripped_content = strtolower(strip_tags($post->post_content));
    // $stripped_content = preg_replace("/[^a-zA-Z0-9]/", "", $stripped_content);
        
    $coupon_status = null;
    $order_num = null;
    $tracking_num = null;
    $tracking_link = null;
    $carrier = null;

    // check for post title to determine shipping vs delivery
    // $coupon_status = strpos($post->post_title, 'on its way') ? 'shipped' : 'delivered';
    $delivered_regex = '/id=[\'\"]delivered_email[\'\"]/';
    $shipped_regex = '/id=[\'\"]shipped_email[\'\"]/';
    if (preg_match($delivered_regex, $content)) {
        $coupon_status = 'delivered';
    } elseif (preg_match($shipped_regex, $content)) {
        $coupon_status = 'shipped';
    }
    
    // parse for order number
    $order_num_regex = '/<span[^>]*id=[\'|\"]order_number[\'|\"][^>]*>[\s]*([^<]+)[\s]*<\/span>/';
    if (preg_match($order_num_regex, $content, $match)) {
        $order_num = trim($match[1]);
    } else {
        // Missing order number means we cannot process this Email Post.
        return;
    }
    
    // parse for tracking number
    $tracking_num_regex = '/<span[^>]*id=[\'|\"]tracking_num[\'|\"][^>]*>[\s]*([^<]+)[\s]*<\/span>/';
    if (preg_match($tracking_num_regex, $content, $match)) {
        $tracking_num = trim($match[1]);
    }
    
    // parse for carrier
    $carrier_regex = '/<span[^>]*id=[\'|"]carrier_name[\'|"][^>]*>[\s]*([^<]+)[\s]*<\/span>/';
    if (preg_match($carrier_regex, $content, $match)) {
        $carrier = trim($match[1]);
    }
    
    // parse for tracking link
    $tracking_link_regex = '/<a[^>]*id=[\'|\"]tracking_link[\'|\"][^>]*href=[\'|\"][\s]*([^\'\"]+)[\s]*[\'|\"][^>]*>/';
    $tracking_link_regex_alt = '/<a[^>]*href=[\'|\"][\s]*([^\'\"]+)[\s]*[\'|\"][^>]*id=[\'|\"]tracking_link[\'|\"][^>]*>/';
    if (preg_match($tracking_link_regex, $content, $match)) {
        $tracking_link = trim($match[1]);
    } elseif (preg_match($tracking_link_regex_alt, $content, $match)) {
        $tracking_link = trim($match[1]);
    }
    
    // get the wc order
    $order = wc_get_order($order_num);

    // get coupon code for the order
    if ($order) {
        foreach ($order->get_used_coupons() as $coupon_code) {
            // get user id from coupon code
            $user_id = wcg_get_customer_id_by_coupon_code($coupon_code);
            if (!$user_id) continue;

            // find the correct coupon row
            $coupon_data = wcg_get_coupon_data($coupon_code ,'' , $user_id);
            if (!$coupon_data) continue;

            // update that coupon row
            $row = array(
                'coupon_status' => $coupon_status
            );
            if ($tracking_num != null)  $row['tracking_number'] = $tracking_num;
            if ($tracking_link != null) $row['tracking_link'] = $tracking_link;
            if ($carrier != null)       $row['carrier'] = $carrier;

            update_row('coupons', $coupon_data['row_number'], $row, "user_$user_id");
        }
    } else {
        return;
    }

    // Mark this email post as processed with custom category
    $cat_name = 'Email Processed';
    $cat_id = term_exists($cat_name, 'category');
    if (!$cat_id) {
        $cat_id = wp_insert_term($cat_name, 'category');
    }

    wp_set_post_categories($post_id, array($cat_id['term_id']), true);

    return;
}


// Disable Ajax Call from WooCommerce Checkout
add_action( 'wp_enqueue_scripts', 'wcg_dequeue_woocommerce_cart_fragments', 11); 
function wcg_dequeue_woocommerce_cart_fragments() { 
    wp_dequeue_script('wc-cart-fragments'); 
} 
