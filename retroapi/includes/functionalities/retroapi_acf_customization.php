<?php
// if file is being called directly or not in the wordpress
if (! defined('ABSPATH')) exit; // Exit if accessed directly
/*
 * Package: RetroAPI
 * @subpackage retroapi/admin 
 * @since 1.0.0
 * @author Arshad Shah
 */

if (!class_exists('retroapi_acf_customization')) {
    class retroapi_acf_customization
    {
        public static function init()
        {
            // Get the menu object by name
            add_filter('acf/format_value', ['retroapi_acf_customization', 'retroapi_acf_format_value'], 10, 3);
        }
        public static function retroapi_acf_format_value($value, $post_id, $field)
        {
            // Define the fields that should use the same format
            $target_fields = ['section_products'];

            // Check if the field name is in the target list
            if (in_array($field['name'], $target_fields, true)) {
                if (!empty($value)) {
                    // Format the data
                    $value = array_map(function ($post) {
                        return [
                            'id' => $post->ID,
                            'title' => $post->post_title,
                            'url' => get_permalink($post->ID),
                            'thumbnail' => get_the_post_thumbnail_url($post->ID, 'thumbnail'),
                        ];
                    }, $value);
                }
            }

            return $value;
        }
    }
}
