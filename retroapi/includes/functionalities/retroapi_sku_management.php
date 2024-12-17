<?php
// if file is being called directly or not in the wordpress
if (! defined('ABSPATH')) exit; // Exit if accessed directly
/*
 * Package: RetroAPI
 * @subpackage retroapi/admin 
 * @since 1.0.0
 * @author Arshad Shah
 */

if (!class_exists('retroapi_sku_management')) {


    /**
     * Custom SKU Generator for Retro Gaming Products
     */
    class retroapi_sku_management
    {
        /**
         * Initialize hooks
         */
        public static function init()
        {
            add_action('save_post', [__CLASS__, 'assign_sku_on_product_save'], 10, 2);
            add_action('edited_term', [__CLASS__, 'refresh_frontend'], 10, 3);
            add_action('create_term', [__CLASS__, 'refresh_frontend'], 10, 3);
            add_action('delete_term', [__CLASS__, 'refresh_frontend'], 10, 3);
            add_action('woocommerce_attribute_updated',[__CLASS__, 'refresh_frontend'], 10, 2);
            add_action('woocommerce_attribute_deleted',[__CLASS__, 'refresh_frontend'], 10, 2);
            add_action('woocommerce_attribute_added',[__CLASS__, 'refresh_frontend'], 10, 2);


        }
        public static function assign_sku_on_product_save($post_id, $post)
        {
            // Check if the product already has an SKU, to prevent overwriting
            
            $product = wc_get_product($post_id);

            if ($product) {

                // Fetch full values
                $pa_condition = self::get_first_term_of_attribute($post_id, 'pa_condition'); // e.g., Nintendo Entertainment System
                $pa_platform = self::get_first_term_of_attribute($post_id, 'pa_platform'); // e.g., Console
                $product_type = self::get_first_term_of_attribute($post_id, 'pa_product-type'); // e.g., New
                $color = self::get_first_term_of_attribute($post_id, 'pa_color',);
                $model_variant = self::get_first_term_of_attribute($post_id, 'pa_variant',);

                $sku = $pa_platform . $product_type . $model_variant . $pa_condition  . $color  . $post_id;

                // Set the SKU
                $product->set_sku($sku);
                $product->save(); // Save the product to update the SKU        
            }
            self::refresh_frontend();
        }
        // Function to get the first term of a specific attribute
        public static function get_first_term_of_attribute($product_id, $attribute_slug)
        {
            // Get the product object
            $product = wc_get_product($product_id);

            // Check if the product exists
            if (!$product) {
                return '';
            }

            // Get all attributes of the product
            $attributes = $product->get_attributes();

            // Check if the specific attribute exists
            if (isset($attributes[$attribute_slug])) {
                $attribute = $attributes[$attribute_slug];

                if ($attribute->is_taxonomy()) {
                    // Get the first term for taxonomy-based attributes
                    $terms = wc_get_product_terms($product_id, $attribute_slug, ['fields' => 'ids']);
                    if (!empty($terms)) {
                        $term_id = $terms[0]; // Get the first term ID
                        // Get the custom field value of the term
                        $custom_field_value = get_term_meta($term_id, "term_abbreviation", true);
                        if (!empty($custom_field_value)) {
                            return $custom_field_value . '-';
                        }
                    }
                }
            }

            return '';
        }
        public static function refresh_frontend()
        {
            // Make the POST request
            $response = wp_remote_post('https://retrofam-dev.vercel.app/api/revalidate');

            if (is_wp_error($response)) {
                error_log('API POST request failed: ' . $response->get_error_message());
            }
        }
    }
}
