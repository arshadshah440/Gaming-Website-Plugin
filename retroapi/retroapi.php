<?php
/*
 * Plugin Name:       Retro API
 * Description:       plugin to create the api required for this project
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Arshad Shah
 * Author URI:        https://arshadwpdev.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       retroapi
 */


// if file is being called directly or not in the wordpress
if (!defined('WPINC')) {
    die;
}

/**
 * The main plugin class
 */

if (!class_exists('retroapi_main')) {
    class retroapi_main
    {
        public function __construct()
        {
            $this->retroapi_define_constants();
            $this->retroapi_include_files();
            $this->retroapi_load_plugin();
           
        }
        public function retroapi_include_files()
        {
            require_once plugin_dir_path(__FILE__) . 'includes/retroapi_plugin.php';
        }
        public function retroapi_define_constants()
        {
            // plugin version name
            define('LPCD_PLUGIN_VERSION', '1.0.0');
            define('LPCD_PLUGIN_PATH', plugin_dir_path(__FILE__));
            define('LPCD_PLUGIN_URL', plugins_url('', __FILE__));
        }
        public function retroapi_load_plugin()
        {
            retroapi_plugin::retroapi_init();
        }
    }
    new retroapi_main();
}
