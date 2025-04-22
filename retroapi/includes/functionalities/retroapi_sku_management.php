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
            add_action('woocommerce_attribute_updated', [__CLASS__, 'refresh_frontend'], 10, 2);
            add_action('woocommerce_attribute_deleted', [__CLASS__, 'refresh_frontend'], 10, 2);
            add_action('woocommerce_attribute_added', [__CLASS__, 'refresh_frontend'], 10, 2);
        }
        public static function assign_sku_on_product_save($post_id, $post)
        {
            // Check if the product already has an SKU, to prevent overwriting
            $product = wc_get_product($post_id);

            if ($product) {
                if ($product->is_type('variable')) {
                    // Get all variations of the variable product
                    $variations = $product->get_children();

                    foreach ($variations as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        if ($variation) {
                            // Fetch attributes specific to this variation
                            $pa_condition = self::get_first_term_of_attribute($variation_id, 'pa_condition');
                            $pa_platform = self::get_first_term_of_attribute($variation_id, 'pa_platform');
                            $product_type = self::get_first_term_of_attribute($variation_id, 'pa_product-type');
                            $color = self::get_first_term_of_attribute($variation_id, 'pa_color');
                            $model_variant = self::get_first_term_of_attribute($variation_id, 'pa_variant');
                            $storage = self::get_first_term_of_attribute($variation_id, 'pa_storage');

                            // Generate the SKU based on variation attributes and product ID
                            $sku = $pa_platform . $product_type . $model_variant . $storage . $color . $pa_condition . $post_id;

                            // Check if SKU is unique before assigning
                            // Check if SKU is unique before assigning
                            if (empty($product->get_sku())) {
                                // Check if the SKU is not already used by another product
                                if (wc_get_product_id_by_sku($sku) === 0) {
                                    $variation->set_sku($sku);
                                    $variation->save(); // Save the product to update the SKU        
                                } else {
                                    error_log("Duplicate SKU found: " . $sku . " for product ID: " . $post_id);
                                }
                            }
                        }
                    }
                } else {
                    // For simple products, generate SKU based on the main product's attributes
                    $pa_condition = self::get_first_term_of_attribute($post_id, 'pa_condition');
                    $pa_platform = self::get_first_term_of_attribute($post_id, 'pa_platform');
                    $product_type = self::get_first_term_of_attribute($post_id, 'pa_product-type');
                    $color = self::get_first_term_of_attribute($post_id, 'pa_color');
                    $model_variant = self::get_first_term_of_attribute($post_id, 'pa_variant');

                    // Generate the SKU based on product attributes
                    $sku = $pa_platform . $product_type . $model_variant . $pa_condition . $color . $post_id;

                    // Check if SKU is unique before assigning
                    if (empty($product->get_sku())) {
                        // Check if the SKU is not already used by another product
                        if (wc_get_product_id_by_sku($sku) === 0) {
                            $product->set_sku($sku);
                            $product->save(); // Save the product to update the SKU        
                        } else {
                            error_log("Duplicate SKU found: " . $sku . " for product ID: " . $post_id);
                        }
                    }
                }
            }

            // Refresh frontend (e.g., API calls or cache clearing)
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

            // For variations, get the attributes of the variation directly
            if ($product->is_type('variation')) {
                $attributes = $product->get_attributes();
            } else {
                // For simple or variable products, get the attributes of the product
                $attributes = $product->get_attributes();
            }

            // Check if the specific attribute exists
            if (isset($attributes[$attribute_slug])) {
                $attribute = $attributes[$attribute_slug];

                // If the attribute is a taxonomy-based attribute (object)
                if (is_object($attribute) && $attribute->is_taxonomy()) {
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
                } elseif (is_string($attribute)) {
                    // Handle the case where it's a custom attribute, not a taxonomy-based one
                    return $attribute . '-'; // You can adjust this if you need custom handling for non-taxonomy attributes
                }
            }

            return '';
        }



        public static function refresh_frontend()
        {
            // Make the POST request
            $response = wp_remote_post('https://staging.retrofam.com/api/revalidate');

            if (is_wp_error($response)) {
                error_log('API POST request failed: ' . $response->get_error_message());
            }
        }
    }
}
