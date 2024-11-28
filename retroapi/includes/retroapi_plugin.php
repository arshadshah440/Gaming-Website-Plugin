<?php

if (!defined('ABSPATH')) exit;

require_once LPCD_PLUGIN_PATH . 'includes/admin/retroapi_endpoints.php';

if (!class_exists('retroapi_plugin')) {

    class retroapi_plugin
    {
        public static function retroapi_init()
        {
            self::retroapi_endpoints();
        }
        public static function retroapi_endpoints()
        {
            retroapi_endpoints::retroapi_init_endpoints();
        }
    }
}
