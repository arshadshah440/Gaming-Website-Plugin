<?php

if (!defined('ABSPATH')) exit;

require_once LPCD_PLUGIN_PATH . 'includes/admin/retroapi_endpoints.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_acf_customization.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_sku_management.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_image_optimizer.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_tax_fields.php';

if (!class_exists('retroapi_plugin')) {

    class retroapi_plugin
    {
        public static function retroapi_init()
        {
            self::retroapi_endpoints();
            retroapi_sku_management::init();
            Advanced_AVIF_Converter::init();
            // RetroAPI_Tax_Fields::init();

        }
        public static function retroapi_endpoints()
        {
            retroapi_endpoints::retroapi_init_endpoints();
        }
    }
}
