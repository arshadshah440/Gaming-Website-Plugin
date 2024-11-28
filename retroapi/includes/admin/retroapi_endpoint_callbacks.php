<?php
// if file is being called directly or not in the wordpress
if (! defined('ABSPATH')) exit; // Exit if accessed directly
/*
 * Package: RetroAPI
 * @subpackage retroapi/admin 
 * @since 1.0.0
 * @author Arshad Shah
 */

if (!class_exists('retroapi_endpoints_callbacks')) {
    class retroapi_endpoints_callbacks
    {
        public static function get_header_menu()
        {
            // Get the menu object by name
            $menu_name = 'Primary Menu'; // Replace with the exact menu name
            $menu = wp_get_nav_menu_object($menu_name);

            if (!$menu) {
                return new WP_Error('menu_not_found', 'Menu not found', ['status' => 404]);
            }

            // Get menu items
            $menu_items = wp_get_nav_menu_items($menu->term_id);

            // Format the menu items into an array
            $formatted_items = [];
            foreach ($menu_items as $item) {
                $formatted_items[] = [
                    'id'        => $item->ID,
                    'title'     => $item->title,
                    'url'       => $item->url,
                    'parent'    => $item->menu_item_parent,
                    'classes'   => $item->classes,
                    'target'    => $item->target,
                    'description' => $item->description,
                ];
            }

            return rest_ensure_response($formatted_items);
        }
    }
}
