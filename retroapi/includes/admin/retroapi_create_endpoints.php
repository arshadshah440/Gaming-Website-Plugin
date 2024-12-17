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

            // Register the API endpoint for viewed products by user storing
            register_rest_route('retroapi/v2', '/set_user_viewed_products', array(
                'methods' => 'POST',
                'callback' => ['retroapi_endpoints_callbacks', 'store_user_viewed_products'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint for viewed products by user
            register_rest_route('retroapi/v2', '/get_user_viewed_products', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_user_viewed_products'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
        }
        // Permission callback to check JWT token
        public static function set_authentication_token(WP_REST_Request $request)
        {
            // Check if thewp_send_json_error( $data:mixed|null, $status_code:integer|null, $options:integer )
            $api_key = $request->get_header('Authorization'); // Extract API key from the Authorization header
            $stored_key = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczovL2Rldi5yZXRyb2ZhbS5jb20iLCJpYXQiOjE3MzMyMzcyNzksIm5iZiI6MTczMzIzNzI3OSwiZXhwIjoxNzMzODQyMDc5LCJkYXRhIjp7InVzZXIiOnsiaWQiOiIxIn19fQ.8qO5lcgAolmKvs_YpdsAaG2n_qaWLeSaqEPBf9H2cA8'; // Retrieve stored API key

            if (empty($api_key) || $api_key !== 'Bearer ' . $stored_key) {
                return new WP_Error('rest_forbidden', 'Invalid or missing API key', ['status' => 403]);
            }

            return true; // Proceed if the key is valid
        }
    }
}
