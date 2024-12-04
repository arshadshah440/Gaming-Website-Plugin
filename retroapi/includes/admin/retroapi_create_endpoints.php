<?php
// if file is being called directly or not in the wordpress
if (! defined('ABSPATH')) exit; // Exit if accessed directly
/*
 * Package: RetroAPI
 * @subpackage retroapi/admin 
 * @since 1.0.0
 * @author Arshad Shah
 */

require_once LPCD_PLUGIN_PATH . 'includes/admin/retroapi_endpoint_callbacks.php';

if (!class_exists('retroapi_create_endpoints')) {
    class retroapi_create_endpoints
    {
        public static function retroapi_init_endpoints()
        {
            // Register the API endpoint for menu
            register_rest_route('retroapi/v2', '/get_header_menu', array(
                'methods' => 'POST',
                'callback' => ['retroapi_endpoints_callbacks', 'get_header_menu'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint for homepage data
            register_rest_route('retroapi/v2', '/get_homepage_data', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_homepage_data'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for brands data
            register_rest_route('retroapi/v2', '/brands', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_brands_data'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for testimonial data
            register_rest_route('retroapi/v2', '/testimonials', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_testimonials_data'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for product filteration
            register_rest_route('retroapi/v2', '/filter_products', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'filter_products_api_callback'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
        }
        // Permission callback to check JWT token
        public static function set_authentication_token()
        {
            // Check if thewp_send_json_error( $data:mixed|null, $status_code:integer|null, $options:integer )
            if (is_user_logged_in()) {
                return true;
            }
            return new WP_Error('rest_forbidden', esc_html__('You cannot access this resource without being logged in.'), array('status' => 401));
        }
    }
}
