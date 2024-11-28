<?php
// if file is being called directly or not in the wordpress
if (! defined('ABSPATH')) exit; // Exit if accessed directly
/*
 * Package: RetroAPI
 * @subpackage retroapi/admin 
 * @since 1.0.0
 * @author Arshad Shah
 */
require_once LPCD_PLUGIN_PATH . 'includes/admin/retroapi_create_endpoints.php';

if (!class_exists('retroapi_endpoints')) {
    class retroapi_endpoints
    {
        public static function retroapi_init_endpoints()
        {
            add_action('rest_api_init', array(__CLASS__, 'retroapi_register_endpoints'));
        }
        public static function retroapi_register_endpoints() {
            retroapi_create_endpoints::retroapi_init_endpoints();  
        }
    }
}
