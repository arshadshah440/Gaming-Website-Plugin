<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if the original class exists
if (class_exists('WC_Shipping_Free_Shipping')) {
    
    class WC_Custom_Free_Shipping_Method extends WC_Shipping_Free_Shipping {
        
        public function __construct($instance_id = 0) {
            parent::__construct($instance_id);
            $this->init();
        }
        
        public function init() {
            parent::init();
            
            // Add the custom field
            $this->form_fields['custom_field'] = array(
                'title'       => __('Custom Field', 'woocommerce'),
                'type'        => 'text',
                'description' => __('Enter your custom value here', 'woocommerce'),
                'default'     => '',
                'desc_tip'    => true,
            );
            
            // Load the custom field value
            $this->custom_field = $this->get_option('custom_field');
        }
    }
    
}