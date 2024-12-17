<?php
if (!defined('ABSPATH')) exit;

class RetroAPI_Tax_Fields
{
    public static function init()
    {
        add_filter('woocommerce_tax_rate_admin_fields', [__CLASS__, 'add_custom_tax_rate_fields'], 10, 2);
        add_action('woocommerce_tax_rate_updated', [__CLASS__, 'save_custom_tax_rate_fields'], 10, 2);
        add_action('woocommerce_tax_rate_added', [__CLASS__, 'save_custom_tax_rate_fields'], 10, 2);
    }

    public static function add_custom_tax_rate_fields($fields, $tax_rate_id = 0)
    {
        // Sales Tax Rate Field
        $fields[] = [
            'label'       => __('Sales Tax Rate', 'retroapi'),
            'name'        => 'sales_tax_rate',
            'type'        => 'text',
            'desc_tip'    => __('Enter the sales tax rate', 'retroapi'),
            'value'       => $tax_rate_id ? get_option('retroapi_sales_tax_rate_' . $tax_rate_id, '') : '',
        ];

        // Normal Tax Rate Field
        $fields[] = [
            'label'       => __('Normal Tax Rate', 'retroapi'),
            'name'        => 'normal_tax_rate',
            'type'        => 'text',
            'desc_tip'    => __('Enter the normal tax rate', 'retroapi'),
            'value'       => $tax_rate_id ? get_option('retroapi_normal_tax_rate_' . $tax_rate_id, '') : '',
        ];

        return $fields;
    }

    public static function save_custom_tax_rate_fields($tax_rate_id, $fields)
    {
        // Save Sales Tax Rate
        if (isset($_POST['sales_tax_rate'])) {
            $sales_tax_rate = sanitize_text_field($_POST['sales_tax_rate']);
            update_option('retroapi_sales_tax_rate_' . $tax_rate_id, $sales_tax_rate);
        }

        // Save Normal Tax Rate
        if (isset($_POST['normal_tax_rate'])) {
            $normal_tax_rate = sanitize_text_field($_POST['normal_tax_rate']);
            update_option('retroapi_normal_tax_rate_' . $tax_rate_id, $normal_tax_rate);
        }
    }
}


