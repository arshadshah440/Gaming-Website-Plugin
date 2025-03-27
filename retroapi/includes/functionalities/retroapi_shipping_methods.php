<?php
if (!defined('ABSPATH')) exit;

class RetroAPI_Shipping_Methods
{
    public static function init()
    {
        add_filter('woocommerce_shipping_instance_form_fields_flat_rate', [__CLASS__, 'add_estimated_delivery_field']);
        add_action('woocommerce_shipping_zone_method_saved', [__CLASS__, 'save_custom_field_free_shipping']);
        add_filter('woocommerce_shipping_methods', [__CLASS__, 'modify_free_shipping_method']);
        add_filter('woocommerce_shipping_instance_form_fields_free_shipping', [__CLASS__, 'add_custom_field_after_requires']);
    }
    // Add a custom field to Flat Rate and Free Shipping settings
    public static function add_estimated_delivery_field($fields)
    {
        $fields['estimated_delivery'] = [
            'title'       => __('Estimated Delivery Time In Days', 'shahwptheme'),
            'type'        => 'number',
            'description' => __('Enter the estimated delivery time (e.g., 3-5 days).', 'shahwptheme'),
            'default'     => '4',
            'desc_tip'    => true,
        ];
        return $fields;
    }
    public static function add_custom_field_after_requires($fields)
    {
        // Create a new array to store the modified fields
        $modified_fields = array();

        // Loop through original fields to insert our custom field after "title" (name) field
        foreach ($fields as $key => $field) {
            $modified_fields[$key] = $field;

            // Place our custom field right after the "title" field
            if ($key === 'title') {
                $modified_fields['estimated_delivery'] = array(
                    'title'       => __('Estimated Delivery Time In Days', 'shahwptheme'),
                    'type'        => 'number',
                    'description' => __('Enter the estimated delivery time (e.g., 3-5 days).', 'shahwptheme'),
                    'default'     => '4',
                    'desc_tip'    => true,
                );
            }
        }

        return $modified_fields;
    }
    public static function save_custom_field_free_shipping($instance_id)
    {
        $shipping_method = WC_Shipping_Zones::get_shipping_method($instance_id);

        if ($shipping_method->id === 'free_shipping' && isset($_POST['woocommerce_free_shipping_custom_field'])) {
            $shipping_method->set_option('custom_field', sanitize_text_field($_POST['woocommerce_free_shipping_custom_field']));
        }
    }
    public static  function modify_free_shipping_method($methods)
    {
        // Include the custom free shipping class
        require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_newshipping_class.php';

        // Replace the original free shipping method with our custom one
        $methods['free_shipping'] = 'WC_Custom_Free_Shipping_Method';

        return $methods;
    }
}
