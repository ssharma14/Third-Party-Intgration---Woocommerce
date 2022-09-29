<?php
   /*
    Plugin Name: Third Party Intgration - Woocommerce
	Version: 1.0
    Description: To make api calls to third party app whenever there is a change 
    in woocommerce subscription data. 
    License: GPL2
    Requires PHP: 7.3
    *
    *
    * WC requires at least: 5.8
    * WC tested up to: 6.1
   */

if ( ! function_exists( 'add_filter' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
} 

include dirname(__FILE__) . '/db.php';
include dirname(__FILE__) . '/env.php';

//Init function

function third_party_integration_woocommerce_register_settings() {
	add_option( 'third_party_integration_woocommerce_option_name', 'This is my option value.');
	register_setting( 'third_party_integration_woocommerce_options_group', 'third_party_integration_woocommerce_option_name', 'third_party_integration_woocommerce_callback' );
}
add_action( 'admin_init', 'third_party_integration_woocommerce_register_settings' );

function third_party_integration_woocommerce_register_options_page() {
	add_options_page('Third Party Intgration - Woocommerce', 'Third Party Intgration - Woocommerce', 'manage_options', 'third_party_integration_woocommerce', 'third_party_integration_woocommerce_options_page');
}
add_action('admin_menu', 'third_party_integration_woocommerce_register_options_page');

function third_party_integration_woocommerce_options_page(){ ?>
    <h2>Third Party Intgration - Woocommerce</h2>
<?php 
}

global $cancel_date, $tier_table, $expirydate_table, $status_table, $wpdb, $tier, $access_token, $expiry_date, $status, $user_name, $user_uuid, $substatus, $calltype;

function uuid(){
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); 
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); 
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

add_action( 'user_register', 'assignuserid');
function assignuserid($user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix."third_party_integration";
    $user_info = get_userdata($user_id);
    $username = $user_info->user_login;
    $uuid = uuid();
    $insert_query = "INSERT INTO ".$table_name."(tier, uuid, username, substatus, 
    expirydate, calltype) VALUES('1', '".$uuid."', '".$username."', 'notset','0000-00-00',
    'userregistration')";
    $insertResult=$wpdb->query($insert_query);
}


//Whenever there is a change in subscription dates manually
if ( is_admin() ){
    add_action( 'save_post', 'get_subscriptions_new_dates' );
    function get_subscriptions_new_dates() {
        global $tier_table, $expirydate_table, $status_table, $wpdb, $tier, $access_token, $expiry_date, $status, $user_name, $user_uuid, $calltype, $username;
        $table_name = "td_third_party_integration";
        $count_query = "SELECT COUNT(*) FROM ".$table_name; 
        $countResult=$wpdb->get_var($count_query);
        $subscriptions = wcs_get_subscriptions(['subscriptions_per_page' => -1, 'order' => 'ASC']);
        foreach ( $subscriptions as $subscription ) {
            $subscriptionID = $subscription->get_id();
            $data = $subscription->get_data();
            $subscription_status = $subscription->get_status();
            $subscription_item = $subscription ->get_items();
            $next_payment = $subscription->get_date( 'next_payment_date' ,'site');
            $trial_end = $subscription->get_date( 'trial_end' ,'site');
            $end_date = $subscription->get_date( 'end' ,'site');
            $cancel_date = $subscription->get_date( 'cancelled' ,'site');
            $userid = $subscription->get_user_id();
            $user = get_user_by( 'id', $userid );
            $user_name = $user->user_login;
            $calltype = "datechange";
            $dtz = new DateTimeZone("America/Edmonton");
            $dt = new DateTime("now", $dtz);
            $current_datetime = $dt->format('Y-m-d H:i');
            $today = date("Y-m-d");
            //UUID
            $query = $wpdb->get_results("select * from ".$table_name." where username='" . $user_name ."'");
            foreach($query as $value){
                $username = $value->username;
                $user_uuid = $value->uuid;
                $expirydate_table = $value->expirydate;
                $status_table = $value->substatus;
                $tier_table = $value->tier;
            }

            //Expiry Date
            if($next_payment){
                $next_payment_date = date('Y-m-d', strtotime($next_payment));
                $expiry_date = $next_payment_date;
            }else if($trial_end){
                $trial_end_date = date('Y-m-d', strtotime($trial_end));
                $expiry_date = $trial_end_date;
            } else if($end_date){
                $end_date_date = date('Y-m-d', strtotime($end_date));
                $expiry_date = $end_date_date;
            } else if($cancel_date){
                $cancel_date_date = date('Y-m-d', strtotime($cancel_date));
                $expiry_date = $cancel_date_date;
            }

            //Subscription Status
            if($subscription_status == 'active'){
                $substatus = "active";
                $status = "true";
            }else if($subscription_status == 'on-hold'){
                $substatus = "on-hold";
                $status = "false";
            }else if($subscription_status == 'cancelled'){
                $substatus = "cancelled";
                $status = "false";
            }else if($subscription_status == 'expired'){
                $substatus = "expired";
                $status = "false";
            }else if($subscription_status == 'pending'){
                $substatus = "pending";
                $status = "false";
            }else if($subscription_status == 'pending-cancel'){
                $substatus = "pending-cancel";
                $status = "false";
            }

            //Subscription Tier
            foreach( $subscription_item as $item ){
                $product = $item->get_product();
                $product_id = $product->get_id();
                $product_cats_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
                foreach( $product_cats_ids as $cat_id ) {
                    $term = get_term_by( 'id', $cat_id, 'product_cat' );
                    $tier_name = $term->name;
                    if($tier_name == "Tier 3"){
                        $tier = 3;
                    } else if($tier_name == "Tier 2"){
                        $tier = 2;
                    } else{
                        $tier = 1;
                    }
                }
            }
            if($tier == 2 || $tier == 3){
                if($username == $user_name) {
                    if($substatus == "cancelled" && $cancel_date < $current_datetime){
                    }else{ 
                        if($next_payment >= $current_datetime ||  $trial_end >= $current_datetime || $end_date >= $current_datetime || $cancel_date >= $current_datetime) {
                            if($expiry_date != $expirydate_table || $substatus != $status_table || $tier != $tier_table){
                                subscription_api();
                                $update_query = "update ".$table_name." SET tier = '".$tier."', username = '".$user_name."', uuid = '".$user_uuid."', substatus = '".$substatus."', expirydate = '".$expiry_date."',
                                calltype = 'datechange' WHERE username='" . $user_name ."'"; 
                                $updateResult=$wpdb->query($update_query);
                            }
                        }
                    }
                }
            }
        }
    }
}


// //Subscription Renewal
add_action('woocommerce_subscription_renewal_payment_complete', 'renew_subscription');
function renew_subscription(){
    global $tier_table, $expirydate_table, $status_table, $wpdb, $tier, $access_token, $expiry_date, $status, $user_name, $user_uuid, $calltype, $username;
    $table_name = "td_third_party_integration";
    $count_query = "SELECT COUNT(*) FROM ".$table_name; 
 	$countResult=$wpdb->get_var($count_query);
     $subscriptions = wcs_get_subscriptions(['subscriptions_per_page' => -1, 'order' => 'ASC']);
    foreach ( $subscriptions as $subscription ) {
        $subscriptionID = $subscription->get_id();
        $data = $subscription->get_data();
        $subscription_status = $subscription->get_status();
        $subscription_item = $subscription ->get_items();
        $next_payment = $subscription->get_date( 'next_payment_date' ,'site');
        $trial_end = $subscription->get_date( 'trial_end' ,'site');
        $end_date = $subscription->get_date( 'end' ,'site');
        $cancel_date = $subscription->get_date( 'cancelled' ,'site');
        $userid = $subscription->get_user_id();
        $user = get_user_by( 'id', $userid );
        $user_name = $user->user_login;
        $calltype = "renewal";
        
        $today = date('Y-m-d');
        //UUID
        $query = $wpdb->get_results("select * from ".$table_name." where username='" . $user_name ."'");
        foreach($query as $value){
            $user_uuid = $value->uuid;
            $username = $value->username;
            $expirydate_table = $value->expirydate;
            $status_table = $value->substatus;
            $tier_table = $value->tier;
        }
        //Expiry Date
        if($next_payment){
            $next_payment_date = date('Y-m-d', strtotime($next_payment));
            $expiry_date = $next_payment_date;
        }else if($trial_end){
            $trial_end_date = date('Y-m-d', strtotime($trial_end));
            $expiry_date = $trial_end_date;
        } else if($end_date){
            $end_date_date = date('Y-m-d', strtotime($end_date));
            $expiry_date = $end_date_date;
        } else if($cancel_date){
            $cancel_date_date = date('Y-m-d', strtotime($cancel_date));
            $expiry_date = $cancel_date_date;
        }

        //Subscription Status
        if($subscription_status == 'active'){
            $substatus = "active";
            $status = "true";
        }else if($subscription_status == 'on-hold'){
            $substatus = "on-hold";
            $status = "false";
        }else if($subscription_status == 'cancelled'){
            $substatus = "cancelled";
            $status = "false";
        }else if($subscription_status == 'expired'){
            $substatus = "expired";
            $status = "false";
        }else if($subscription_status == 'pending'){
            $substatus = "pending";
            $status = "false";
        }else if($subscription_status == 'pending-cancel'){
            $substatus = "pending-cancel";
            $status = "false";
        }

        //Subscription Tier
        foreach( $subscription_item as $item ){
            $product = $item->get_product();
            $product_id = $product->get_id();
            $product_cats_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
            foreach( $product_cats_ids as $cat_id ) {
                $term = get_term_by( 'id', $cat_id, 'product_cat' );
                $tier_name = $term->name;
                if($tier_name == "Tier 3"){
                    $tier = 3;
                } else if($tier_name == "Tier 2"){
                    $tier = 2;
                } else{
                    $tier = 1;
                }
            }
        }
        if($tier == 2 || $tier == 3){
            if($username == $user_name) {
                if($substatus == "cancelled" && $cancel_date < $current_datetime){
                }else{ 
                    if($next_payment >= $today ||  $trial_end >= $today || $end_date >= $today || $cancel_date >= $today){
                        if($expiry_date != $expirydate_table || $substatus != $status_table || $tier != $tier_table){
                            subscription_api();
                            $update_query = "update ".$table_name." SET tier = '".$tier."', username = '".$user_name."', uuid = '".$user_uuid."', substatus = '".$substatus."', expirydate = '".$expiry_date."',
                            calltype = 'renewal' WHERE username='" . $user_name ."'"; 
                            $updateResult=$wpdb->query($update_query);
                        }
                    }
                }
            }
        }
    };
}

add_action('wcs_update_dates_after_early_renewal', 'earlyrenew_subscription');
function earlyrenew_subscription(){
    global $wpdb, $tier, $access_token, $expiry_date, $status, $user_name, $user_uuid, $substatus, $calltype;
    $table_name = "td_third_party_integration";
    $count_query = "SELECT COUNT(*) FROM ".$table_name; 
 	$countResult=$wpdb->get_var($count_query);
     $subscriptions = wcs_get_subscriptions(['subscriptions_per_page' => -1, 'order' => 'ASC']);
    foreach ( $subscriptions as $subscription ) {
        $subscriptionID = $subscription->get_id();
        $data = $subscription->get_data();
        $subscription_status = $subscription->get_status();
        $subscription_item = $subscription ->get_items();
        $next_payment = $subscription->get_date( 'next_payment_date' ,'site');
        $trial_end = $subscription->get_date( 'trial_end' ,'site');
        $end_date = $subscription->get_date( 'end' ,'site');
        $cancel_date = $subscription->get_date( 'cancelled' ,'site');
        $userid = $subscription->get_user_id();
        $user = get_user_by( 'id', $userid );
        $user_name = $user->user_login;
        $calltype = "renewal";
        $dtz = new DateTimeZone("America/Edmonton");
        $dt = new DateTime("now", $dtz);
        $current_datetime = $dt->format('Y-m-d H:i');
        $today = date("Y-m-d");
        //UUID
        $query = $wpdb->get_results("select uuid from ".$table_name." where username='" . $user_name ."'");
        foreach($query as $value){
            $user_uuid = $value->uuid;
        }

        //Expiry Date
        if($next_payment){
            $next_payment_date = date('Y-m-d', strtotime($next_payment));
            $expiry_date = $next_payment_date;
        }else if($trial_end){
            $trial_end_date = date('Y-m-d', strtotime($trial_end));
            $expiry_date = $trial_end_date;
        } else if($end_date){
            $end_date_date = date('Y-m-d', strtotime($end_date));
            $expiry_date = $end_date_date;
        } else if($cancel_date){
            $cancel_date_date = date('Y-m-d', strtotime($cancel_date));
            $expiry_date = $cancel_date_date;
        }

        //Subscription Status
        if($subscription_status == 'active'){
            $substatus = "active";
            $status = "true";
        }else if($subscription_status == 'on-hold'){
            $substatus = "on-hold";
            $status = "false";
        }else if($subscription_status == 'cancelled'){
            $substatus = "cancelled";
            $status = "false";
        }else if($subscription_status == 'expired'){
            $substatus = "expired";
            $status = "false";
        }else if($subscription_status == 'pending'){
            $substatus = "pending";
            $status = "false";
        }else if($subscription_status == 'pending-cancel'){
            $substatus = "pending-cancel";
            $status = "false";
        }

        //Subscription Tier
        foreach( $subscription_item as $item ){
            $product = $item->get_product();
            $product_id = $product->get_id();
            $product_cats_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
            foreach( $product_cats_ids as $cat_id ) {
                $term = get_term_by( 'id', $cat_id, 'product_cat' );
                $tier_name = $term->name;
                if($tier_name == "Tier 3"){
                    $tier = 3;
                } else if($tier_name == "Tier 2"){
                    $tier = 2;
                } else{
                    $tier = 1;
                }
            }
        }
        if($tier == 2 || $tier == 3){
            if($username == $user_name) {
                if($next_payment >= $today ||  $trial_end >= $today || $end_date >= $today || $cancel_date >= $today){
                    if($expiry_date != $expirydate_table || $substatus != $status_table || $tier != $tier_table){
                        subscription_api();
                        $update_query = "update ".$table_name." SET tier = '".$tier."', username = '".$user_name."', uuid = '".$user_uuid."', substatus = '".$substatus."', expirydate = '".$expiry_date."',
                        calltype = 'earlyrenewal' WHERE username='" . $user_name ."'"; 
                        $updateResult=$wpdb->query($update_query);
                    }
                }
            }
        }
    }
}

//Subscription Switched
add_action('woocommerce_subscriptions_switch_completed', 'switched_subscription');
function switched_subscription(){
    global $tier_table, $expirydate_table, $status_table, $wpdb, $tier, $access_token, $expiry_date, $status, $user_name, $user_uuid, $calltype, $username;
    $table_name = "td_third_party_integration";
    $count_query = "SELECT COUNT(*) FROM ".$table_name; 
	$countResult=$wpdb->get_var($count_query);
    $subscriptions = wcs_get_subscriptions(['subscriptions_per_page' => -1, 'order' => 'ASC']);
    $calltype = "switch";
    foreach ( $subscriptions as $subscription ) {
        $subscriptionID = $subscription->get_id();
        $data = $subscription->get_data();
        $subscription_status = $subscription->get_status();
        $subscription_item = $subscription ->get_items();
        $next_payment = $subscription->get_date( 'next_payment_date' ,'site');
        $trial_end = $subscription->get_date( 'trial_end' ,'site');
        $end_date = $subscription->get_date( 'end' ,'site');
        $cancel_date = $subscription->get_date( 'cancelled' ,'site');
        $userid = $subscription->get_user_id();
        $user = get_user_by( 'id', $userid );
        $user_name = $user->user_login;
        $dtz = new DateTimeZone("America/Edmonton");
        $dt = new DateTime("now", $dtz);
        $current_datetime = $dt->format('Y-m-d H:i');
        $today = date("Y-m-d");
        //UUID
        $query = $wpdb->get_results("select * from ".$table_name." where username='" . $user_name ."'");
        foreach($query as $value){
            $user_uuid = $value->uuid;
            $username = $value->username;
            $expirydate_table = $value->expirydate;
            $status_table = $value->substatus;
            $tier_table = $value->tier;
        }
        //Expiry Date
        if($next_payment){
            $next_payment_date = date('Y-m-d', strtotime($next_payment));
            $expiry_date = $next_payment_date;
        }else if($trial_end){
            $trial_end_date = date('Y-m-d', strtotime($trial_end));
            $expiry_date = $trial_end_date;
        } else if($end_date){
            $end_date_date = date('Y-m-d', strtotime($end_date));
            $expiry_date = $end_date_date;
        } else if($cancel_date){
            $cancel_date_date = date('Y-m-d', strtotime($cancel_date));
            $expiry_date = $cancel_date_date;
        }
        //Subscription Status
        if($subscription_status == 'active'){
            $substatus = "active";
            $status = "true";
        }else if($subscription_status == 'on-hold'){
            $substatus = "on-hold";
            $status = "false";
        }else if($subscription_status == 'cancelled'){
            $substatus = "cancelled";
            $status = "false";
        }else if($subscription_status == 'expired'){
            $substatus = "expired";
            $status = "false";
        }else if($subscription_status == 'pending'){
            $substatus = "pending";
            $status = "false";
        }else if($subscription_status == 'pending-cancel'){
            $substatus = "pending-cancel";
            $status = "false";
        }
        //Subscription Tier
        foreach( $subscription_item as $item ){
            $product = $item->get_product();
            $product_id = $product->get_id();
            $product_cats_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
            foreach( $product_cats_ids as $cat_id ) {
                $term = get_term_by( 'id', $cat_id, 'product_cat' );
                $tier_name = $term->name;
                if($tier_name == "Tier 3"){
                    $tier = 3;
                } else if($tier_name == "Tier 2"){
                    $tier = 2;
                } else{
                    $tier = 1;
                }
            }
        }

        //Covering both downgrade and upgrade
        if($tier == 1){
            $querytier = $wpdb->get_results("select tier from ".$table_name." where username='" . $user_name ."'");
            foreach($querytier as $value){
                $tier = $value->tier;
            }
            $substatus = "expired";
            $expiry_date = date('Y-m-d');
            if($username == $user_name) {
                if($substatus == "cancelled" && $cancel_date < $current_datetime){
                }else{
                    if($next_payment >= $today ||  $trial_end >= $today || $end_date >= $today || $cancel_date >= $today){
                        if($expiry_date != $expirydate_table || $substatus != $status_table || $tier != $tier_table){
                            subscription_api();
                            $update_query = "update ".$table_name." SET tier = '".$tier."', username = '".$user_name."', uuid = '".$user_uuid."', substatus = '".$substatus."', expirydate = '".$expiry_date."',
                            calltype = 'switched' WHERE username='" . $user_name ."'"; 
                            $updateResult=$wpdb->query($update_query);
                        }
                    }
                }
            }
        }else if($tier == 2 || $tier == 3){
            if($username == $user_name) {
                if($substatus == "cancelled" && $cancel_date < $current_datetime){
                }else{
                    if($next_payment >= $today ||  $trial_end >= $today || $end_date >= $today || $cancel_date >= $today){
                        if($expiry_date != $expirydate_table || $substatus != $status_table || $tier != $tier_table){
                            subscription_api();
                            $update_query = "update ".$table_name." SET tier = '".$tier."', username = '".$user_name."', uuid = '".$user_uuid."', substatus = '".$substatus."', expirydate = '".$expiry_date."',
                            calltype = 'switched' WHERE username='" . $user_name ."'"; 
                            $updateResult=$wpdb->query($update_query);
                        }
                    }
                }
            }
        }
    }
}

if ( !is_admin() ){
    // //Check for change in status - needed when user do cancel or resubscribe the subscription from myAccount or expired or cancelled
    add_action('woocommerce_subscription_status_updated', 'change_status_subscription');
    function change_status_subscription(){
        global $cancel_date, $tier_table, $expirydate_table, $status_table, $wpdb, $tier, $access_token, $expiry_date, $status, $user_name, $user_uuid, $calltype, $username;
        $table_name = "td_third_party_integration";
        $count_query = "SELECT COUNT(*) FROM ".$table_name; 
        $countResult=$wpdb->get_var($count_query);
        $subscriptions = wcs_get_subscriptions(['subscriptions_per_page' => -1, 'order' => 'ASC']);
        $calltype = "status-update";
        foreach ( $subscriptions as $subscription ) {
            $subscriptionID = $subscription->get_id();
            $data = $subscription->get_data();
            $subscription_status = $subscription->get_status();
            $subscription_item = $subscription ->get_items();
            $next_payment = $subscription->get_date( 'next_payment_date' ,'site');
            $trial_end = $subscription->get_date( 'trial_end' ,'site');
            $end_date = $subscription->get_date( 'end' ,'site');
            $cancel_date = $subscription->get_date( 'cancelled' ,'site');
            $userid = $subscription->get_user_id();
            $user = get_user_by( 'id', $userid );
            $user_name = $user->user_login;
            $dtz = new DateTimeZone("America/Edmonton");
            $dt = new DateTime("now", $dtz);
            $current_datetime = $dt->format('Y-m-d H:i');
            $today = date("Y-m-d");
            //UUID
            $query = $wpdb->get_results("select * from ".$table_name." where username='" . $user_name ."'");
            $count_query = "SELECT COUNT(*) FROM ".$table_name; 
            $countResult=$wpdb->get_var($count_query);
            foreach($query as $value){
                $user_uuid = $value->uuid;
                $username = $value->username;
                $expirydate_table = $value->expirydate;
                $status_table = $value->substatus;
                $tier_table = $value->tier;
            }
            //Expiry Date
            if($next_payment){
                $next_payment_date = date('Y-m-d', strtotime($next_payment));
                $expiry_date = $next_payment_date;
            }else if($trial_end){
                $trial_end_date = date('Y-m-d', strtotime($trial_end));
                $expiry_date = $trial_end_date;
            } else if($end_date){
                $end_date_date = date('Y-m-d', strtotime($end_date));
                $expiry_date = $end_date_date;
            } else if($cancel_date){
                $cancel_date_date = date('Y-m-d', strtotime($cancel_date));
                $expiry_date = $cancel_date_date;
            } else if($expiry_date){
                $cancel_date_date = date('Y-m-d', strtotime($cancel_date));
                $expiry_date = $cancel_date_date;
            }

            //Subscription Status
            if($subscription_status == 'active'){
                $substatus = "active";
                $status = "true";
            }else if($subscription_status == 'on-hold'){
                $substatus = "on-hold";
                $status = "true";
            }else if($subscription_status == 'cancelled'){
                $substatus = "cancelled";
                $status = "false";
            }else if($subscription_status == 'expired'){
                $substatus = "expired";
                $status = "false";
            }else if($subscription_status == 'pending'){
                $substatus = "pending";
                $status = "true";
            }else if($subscription_status == 'pending-cancel'){
                $substatus = "pending-cancel";
                $status = "true";
            }
            //Subscription Tier
            foreach( $subscription_item as $item ){
                $product = $item->get_product();
                $product_id = $product->get_id();
                $product_cats_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
                foreach( $product_cats_ids as $cat_id ) {
                    $term = get_term_by( 'id', $cat_id, 'product_cat' );
                    $tier_name = $term->name;
                    if($tier_name == "Tier 3"){
                        $tier = 3;
                    } else if($tier_name == "Tier 2"){
                        $tier = 2;
                    } else{
                        $tier = 1;
                    }
                }
            }
            if($tier == 2 || $tier == 3){
                if($username == $user_name){
                    if($substatus == "cancelled" && $cancel_date < $current_datetime){
                        
                    }else{ 
                        if($next_payment >= $today ||  $trial_end >= $today || $end_date >= $today || $cancel_date >= $today) {
                            if($expiry_date != $expirydate_table || $substatus != $status_table || $tier != $tier_table){
                                subscription_api();
                            }
                        }
                    }
                }
                if($username == $user_name ){
                    if($substatus == "cancelled" && $cancel_date < $current_datetime){
                        
                    }else{
                        if($next_payment >= $today ||  $trial_end >= $today || $end_date >= $today || $cancel_date >= $today){
                            if($expiry_date != $expirydate_table || $substatus != $status_table || $tier != $tier_table){
                                $update_query = "update ".$table_name." SET tier = '".$tier."', username = '".$user_name."', uuid = '".$user_uuid."', substatus = '".$substatus."', expirydate = '".$expiry_date."',
                                calltype = 'status-change' WHERE username='" . $user_name ."'"; 
                                $updateResult=$wpdb->query($update_query);
                            }
                        }
                    }
                }else{
                    $insert_query = "insert into ".$table_name."(tier, username, uuid, substatus, expirydate, calltype) 
                    values ('".$tier."', '".$user_name."', '".$user_uuid."', '".$substatus."', '".$expiry_date."' , 'status-change')";
                    $insertResult=$wpdb->query($insert_query);
                }
            }
        }
    }
}


//Authentication API Calls
function subscription_api(){
    global $tier, $access_token, $expiry_date, $status, $user_uuid;
    $dtz = new DateTimeZone("America/Edmonton");
    $dt = new DateTime("now", $dtz);
    //Authentication
    $body = [
        'email' => $email,
        'pwd' => $pwd
    ];
    $body = wp_json_encode($body);
    $args = array(
        'body'        => $body,
        'headers'     => [
            'Content-Type' => 'application/json',
        ],
        'timeout'     => 60,
        'redirection' => 5,
        'blocking'    => true,
        'httpversion' => '1.0',
        'sslverify'   => false,
        'data_format' => 'body',
    );
    $response = wp_remote_post( $api_url_auth, $args );
    $body = wp_remote_retrieve_body( $response );
    $json = json_decode($body, true);

    //Adding Subscription
    $subscription_body = [
        'patientDiscriminator' => $user_uuid,
        'expiry' => $expiry_date,
        'expired' => $status,
        'tier' => $tier
    ];
    $subscription_body = wp_json_encode( $subscription_body );
    $header = [
        'Authorization' => 'Bearer ' . $access_token,
        'Content-Type' => 'application/json'
    ];
    $args = array(
        'body'        => $subscription_body,
        'headers'     => $header,
        'timeout'     => 60,
        'redirection' => 5,
        'blocking'    => true,
        'httpversion' => '1.0',
        'sslverify'   => false,
        'data_format' => 'body',
    );
    $response2 = wp_remote_post($api_url_sub, $args);
}

?>