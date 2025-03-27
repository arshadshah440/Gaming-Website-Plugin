<?php

if (!defined('ABSPATH')) exit;

require_once LPCD_PLUGIN_PATH . 'includes/admin/retroapi_endpoints.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_acf_customization.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_sku_management.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_image_optimizer.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_tax_fields.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_shipping_methods.php';

if (!class_exists('retroapi_plugin')) {

    class retroapi_plugin
    {
        public static function retroapi_init()
        {
            self::retroapi_endpoints();
            retroapi_sku_management::init();
            Advanced_AVIF_Converter::init();
            RetroAPI_Shipping_Methods::init();

            add_filter('woocommerce_rest_prepare_product_object', [__CLASS__, 'add_acf_swatch_colors_to_api_response'], 10, 3);

            // RetroAPI_Tax_Fields::init();

        }
        public static function retroapi_endpoints()
        {
            retroapi_endpoints::retroapi_init_endpoints();
        }
        public static function add_acf_swatch_colors_to_api_response($response, $object, $request)
        {
            if (empty($response->data['attributes'])) {
                return $response;
            }

            foreach ($response->data['attributes'] as &$attribute) {
                $attribute_name = strtolower(str_replace(' ', '-', $attribute['name'])); // Convert to slug format

                foreach ($attribute['options'] as &$option) {
                    // Try fetching the term by name
                    $term = get_term_by('name', $option, 'pa_' . $attribute_name);

                    // If term is not found, try fetching by slug
                    if (!$term) {
                        $term = get_term_by('slug', sanitize_title($option), 'pa_' . $attribute_name);
                    }

                    // Debug log if term is still not found
                    if (!$term) {
                        error_log("Term not found for option: " . $option . " in taxonomy: pa_" . $attribute_name);
                    }

                    // Get ACF field value if term exists
                    $swatch_color = $term ? get_field('pick_swatch_color', 'term_' . $term->term_id) : null;

                    // Ensure the option is always an object
                    $option = [
                        'name' => $option,
                        'swatch_color' => $swatch_color
                    ];
                }
            }

            return $response;
        }


        // Hook into WooCommerce REST API response

    }
}
