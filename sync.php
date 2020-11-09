<?php
/**
 * Plugin Name: Discourse WC Subscriptions Sync
 * Description: Use Discourse as a community engine for your WordPress blog
 * Version: 1.0.0
 * Author: fzngagan@gmail.com
 * Author URI: https://github.com/fzngagan
 * Plugin URI: https://github.com/paviliondev/wp-discourse-wc-memberships-sync
 * GitHub Plugin URI: https://github.com/paviliondev/wp-discourse-wc-memberships-sync
 */

use WPDiscourse\Utilities\Utilities as Utilities;

// use status changed hook, 
//active statuses: active and pending cancellation
// all statuses wcs_get_subscription_statuses
define('SUBSCRIPTION_PRODUCT_ID', 76);
define('SUBSCRIPTION_DISCOURSE_GROUP', 'locker');
const PV_SUBSCRIPTION_ACTIVE_STATUSES = array('active');

add_action('woocommerce_subscription_status_updated', 'pv_handle_subscription_update', 10 , 3);
function pv_handle_subscription_update ($subscription, $new_status, $old_status) {
	if(!$subscription->has_product(SUBSCRIPTION_PRODUCT_ID)) return;

	$active = (in_array($new_status, PV_SUBSCRIPTION_ACTIVE_STATUSES));
	$user_id = $subscription->get_user_id();
	$user = pv_create_or_get_user($user_id);
	pv_update_discourse_membership($user, SUBSCRIPTION_DISCOURSE_GROUP, $active);
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
// debugging function
function pv_print_and_die($obj) {
	echo '<pre>';
	print_r($obj);
	echo '</pre>';
	die();
}
