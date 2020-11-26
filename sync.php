<?php
/**
 * Plugin Name: Discourse WC Subscriptions Sync
 * Description: Sync WC Subscriptions with discourse groups
 * Version: 1.0.0
 * Author: fzngagan@gmail.com
 * Author URI: https://github.com/fzngagan
 * Plugin URI: https://github.com/paviliondev/wp-discourse-wc-subscriptions-sync
 * GitHub Plugin URI: https://github.com/paviliondev/wp-discourse-wc-subscriptions-sync
 */

use WPDiscourse\Utilities\Utilities as Utilities;

// use status changed hook, 
//active statuses: active and pending cancellation
// all statuses wcs_get_subscription_statuses
const SUBSCRIPTION_PRODUCT_IDS =  array(13104, 13106, 13113);
define('SUBSCRIPTION_DISCOURSE_GROUP', 'VIP');
const PV_SUBSCRIPTION_ACTIVE_STATUSES = array('active', 'pending-cancel');

add_action('woocommerce_subscription_status_updated', 'pv_handle_subscription_update', 10 , 3);
function pv_handle_subscription_update ($subscription, $new_status, $old_status) {
	if(!pv_subscription_contains_products(SUBSCRIPTION_PRODUCT_IDS, $subscription)) return;

	$active = (in_array($new_status, PV_SUBSCRIPTION_ACTIVE_STATUSES));
	$user_id = $subscription->get_user_id();
	$user = pv_create_or_get_user($user_id);
	pv_update_discourse_membership($user, SUBSCRIPTION_DISCOURSE_GROUP, $active);
}
// this function checks whether the subscription contains atleast
// one of the products tied to discourse group membership
function pv_subscription_contains_products($product_ids, $subscription) {
	foreach($product_ids as $product_id) {
		if($subscription->has_product($product_id)) {
			return true;
		}
	}

	return false;
}

function pv_create_or_get_user($user_id) {
	$user = get_userdata($user_id);
	$email = $user->get('user_email');
	$usr = Utilities::get_discourse_user_by_email($email);
	if (!is_wp_error($usr)) {
		return $user;
	}

	// create a discourse user
	$created = Utilities::create_discourse_user($user, true);

	if(!is_wp_error($created)) {
		return $user;
	}

	return false;
}

function pv_update_discourse_membership($user, $groups, $active) {
	$api_credentials = pv_get_api_credentials();
	
		if ( is_wp_error( $api_credentials ) ) {

			return new \WP_Error( 'wpdc_configuration_error', 'The Discourse Connection options are not properly configured.' );
		}
	$all_groups = Utilities::get_discourse_groups();
	$my_group_ids = array_map( function ($item) use ($groups) {
		if( in_array($item->name, (array)$groups)) {
			return $item->id;
		}
		return null;
	}, $all_groups);
	$my_group_ids = array_filter($my_group_ids);

	$method = $active ? "PUT" : "DELETE";
	foreach($my_group_ids as $my_group_id) {
		sleep(0.2); // so that we avoid hitting the discourse rate limits
		$url = sprintf("%s/%s/%s/%s", $api_credentials['url'], 'groups', $my_group_id, 'members.json');
		$response = wp_remote_post(
			$url,
			array(
				'method' => $method,
				'body' => array(
					'usernames' => $user->user_login,
					'emails' => '',
					'notify_users' => 'true'
				),
				'headers' => array(
					'Api-Key'      => sanitize_key( $api_credentials['api_key'] ),
					'Api-Username' => sanitize_text_field( $api_credentials['api_username'] )
				)
			),
		);
		if ( ! Utilities::validate( $response ) ) {

			return new \WP_Error( wp_remote_retrieve_response_code( $response ), 'An error was returned from Discourse when attempting to create a user.' );
		}

		$user_data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $user_data->success ) ) {

			return new \WP_Error( 'wpdc_response_error', $user_data->message );
		}

		if ( isset( $user_data->user_id ) ) {

			return $user_data->user_id;
		}

		return new \WP_Error( wp_remote_retrieve_response_code( $response ), 'The Discourse user could not be created.' );
	}
}


function pv_get_api_credentials() {
	$options      = Utilities::get_options();
	$url          = ! empty( $options['url'] ) ? $options['url'] : null;
	$api_key      = ! empty( $options['api-key'] ) ? $options['api-key'] : null;
	$api_username = ! empty( $options['publish-username'] ) ? $options['publish-username'] : null;

	if ( ! ( $url && $api_key && $api_username ) ) {

		return new \WP_Error( 'wpdc_configuration_error', 'The Discourse configuration options have not been set.' );
	}

	return array(
		'url'          => $url,
		'api_key'      => $api_key,
		'api_username' => $api_username,
	);
}

 function pv_create_discourse_user( $user, $password, $require_activation = true ) {

	$api_credentials = pv_get_api_credentials();
	if ( is_wp_error( $api_credentials ) ) {

		return new \WP_Error( 'wpdc_configuration_error', 'The Discourse Connection options are not properly configured.' );
	}

	if ( empty( $user ) || empty( $user->ID ) || is_wp_error( $user ) ) {

		return new \WP_Error( 'wpdc_user_not_set_error', 'The Discourse user you are attempting to create does not exist on WordPress.' );
	}

	$require_activation = apply_filters( 'wpdc_auto_create_user_require_activation', $require_activation, $user );
	$create_user_url    = esc_url_raw( "{$api_credentials['url']}/users" );
	$username           = $user->user_login;
	$name               = $user->display_name;
	$email              = $user->user_email;
	$password           = $password;
	$response           = wp_remote_post(
		$create_user_url,
		array(
			'method'  => 'POST',
			'body'    => array(
				'name'     => $name,
				'email'    => $email,
				'password' => $password,
				'username' => $username,
				'active'   => $require_activation ? 'false' : 'true',
				'approved' => 'true',
			),
			'headers' => array(
				'Api-Key'      => sanitize_key( $api_credentials['api_key'] ),
				'Api-Username' => sanitize_text_field( $api_credentials['api_username'] ),
			),
		)
	);

	if ( ! Utilities::validate( $response ) ) {

		return new \WP_Error( wp_remote_retrieve_response_code( $response ), 'An error was returned from Discourse when attempting to create a user.' );
	}

	$user_data = json_decode( wp_remote_retrieve_body( $response ) );
	if ( empty( $user_data->success ) ) {

		return new \WP_Error( 'wpdc_response_error', $user_data->message );
	}

	if ( isset( $user_data->user_id ) ) {

		return $user_data->user_id;
	}

	return new \WP_Error( wp_remote_retrieve_response_code( $response ), 'The Discourse user could not be created.' );
}

add_action('woocommerce_checkout_update_user_meta', 'pv_create_user_for_subscription', 10, 2);
function pv_create_user_for_subscription($customer_id, $data) {
		$cart_contents = WC()->cart->get_cart_contents();
		$product_ids = wp_list_pluck($cart_contents, 'product_id');
		if(!array_intersect($product_ids, SUBSCRIPTION_PRODUCT_IDS)) return;

		pv_create_discourse_user(get_userdata($customer_id), $data['account_password'], true);
}

add_action('woocommerce_after_checkout_validation', 'pv_validate_checkout_password', 10, 2);
 function pv_validate_checkout_password($data, $errors) {
	if(!$data['account_password'] || (strlen($data['account_password']) < 8)) {
		$errors->add('password_validation', 'password should be minimum 8 characters or more');
	}
}

// debugging function
function pv_print_and_die($obj) {
	echo '<pre>';
	print_r($obj);
	echo '</pre>';
	die();
}
