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
        // Handle the API request and return tax and shipping details
        public static function retrovgame_get_tax_and_shipping($request)
        {
            $params = $request->get_json_params();
            $country = $request->get_param('country');
            $state = $request->get_param('state');
            $postcode = $request->get_param('postcode');
            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);

            // Switch language using WPML if lang is provided and different from default
            if ($lang && function_exists('do_action')) {
                do_action('wpml_switch_language', $lang);
            }
            // Validate input parameters
            if (empty($country) || empty($state) || empty($postcode)) {
                return new WP_Error('missing_params', 'Country, state, and postal code are required.', ['status' => 400]);
            }

            $country = sanitize_text_field($country);
            $state = sanitize_text_field($state);
            // $state = sanitize_text_field(self::get_state_code_by_name($country, $state));
            $postcode = wc_normalize_postcode(wc_clean($postcode));

            // Get tax details with proper tax class
            $tax_details = self::get_tax_details($country, $state, $postcode, $lang);

            // Get shipping costs
            $shipping_costs = self::get_shipping_costs($country, $state, $postcode);

            if (empty($tax_details)) {
                return new WP_Error('No Taxes Available', 'No Taxes Available for your Area', ['status' => 400]);
            }

            return [
                'success' => true,
                'tax_details' => $tax_details,

                // 'shipping_costs' => $shipping_costs,
            ];
        }
        public static function get_state_code_by_name($country_code, $state_name)
        {
            $states = WC()->countries->get_states();

            if (isset($states[$country_code])) {
                foreach ($states[$country_code] as $code => $name) {
                    if (strtolower($name) === strtolower($state_name)) {
                        return $code;
                    }
                }
            }

            return null;
        }

        public static function get_country_code_by_name($country_name)
        {
            $countries = WC()->countries->get_countries();
            $country_name = strtolower($country_name);

            // First try exact match
            foreach ($countries as $code => $name) {
                if (strtolower($name) === $country_name) {
                    return $code;
                }
            }

            // Try closest match if exact not found
            $highest_similarity = 0;
            $closest_code = null;

            foreach ($countries as $code => $name) {
                similar_text($country_name, strtolower($name), $percent);
                if ($percent > $highest_similarity) {
                    $highest_similarity = $percent;
                    $closest_code = $code;
                }
            }

            return $closest_code;
        }

        // get mega menu or header menu endpoint 
        public static function retrovgame_get_header_menu_details(WP_REST_Request $request)
        {
            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);
            $default_lang = apply_filters('wpml_default_language', null);

            // Switch language using WPML if lang is provided and different from default
            if ($lang && function_exists('do_action')) {
                do_action('wpml_switch_language', $lang);
            }

            // For ACF option fields, you might need to use the WPML element ID
            $header_menu = get_field("header_menu", 'option');

            // Debug information
            $debug_info = [
                'requested_lang' => $lang,
                'default_lang' => $default_lang,
                'has_field_value' => !empty($header_menu)
            ];

            return new WP_REST_Response(array(
                'data' => $header_menu,
                'debug' => $debug_info, // Remove this in production
                'status' => 200
            ), 200);
        }
        public static function get_tax_details($country, $state, $postcode, $language)
        {
            global $wpdb;
            // Query the tax rates directly from the database
            $query = $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates 
                WHERE tax_rate_country = %s 
                OR tax_rate_country = ''",
                $country
            );
            $rates = $wpdb->get_results($query);
            $tax_details = [];
            foreach ($rates as $rate) {
                // Check if state matches or if it's a country-wide rate
                if (!empty($rate->tax_rate_state) && $rate->tax_rate_state !== $state) {
                    continue;
                }
                // Check postcode if exists
                if (!empty($rate->tax_rate_postcode)) {
                    $postcode_query = $wpdb->prepare(
                        "SELECT location_code FROM {$wpdb->prefix}woocommerce_tax_rate_locations 
                        WHERE tax_rate_id = %d AND location_type = 'postcode'",
                        $rate->tax_rate_id
                    );
                    $postcodes = $wpdb->get_col($postcode_query);
                    $postcode_match = false;
                    foreach ($postcodes as $rate_postcode) {
                        if (self::postcode_matches($postcode, $rate_postcode)) {
                            $postcode_match = true;
                            break;
                        }
                    }
                    if (!$postcode_match) {
                        continue;
                    }
                }

                // Get tax rate name (city fallback, optional)
                $rate_name_query = $wpdb->prepare(
                    "SELECT name FROM {$wpdb->prefix}woocommerce_tax_rate_locations 
                    WHERE tax_rate_id = %d AND location_type = 'city' LIMIT 1",
                    $rate->tax_rate_id
                );
                $rate_city = $wpdb->get_var($rate_name_query);

                // Get rate_code from tax rate locations
                $rate_code_query = $wpdb->prepare(
                    "SELECT location_code FROM {$wpdb->prefix}woocommerce_tax_rate_locations 
                    WHERE tax_rate_id = %d AND location_type = 'code' LIMIT 1",
                    $rate->tax_rate_id
                );
                $rate_code = $wpdb->get_var($rate_code_query);

                // If no specific rate code is found, generate one based on the rate information
                if (empty($rate_code)) {
                    $rate_code = 'TAX-' . strtoupper($country);
                    if (!empty($rate->tax_rate_state)) {
                        $rate_code .= '-' . strtoupper($rate->tax_rate_state);
                    }
                    $rate_code .= '-' . str_replace('.', '', $rate->tax_rate);
                }

                // Translate the tax rate name
                $rate_name = !empty($rate->tax_rate_name) ? $rate->tax_rate_name : 'Tax';
                if (function_exists('apply_filters')) {
                    $rate_name = apply_filters(
                        'wpml_translate_single_string',
                        $rate_name,
                        'woocommerce',
                        'Tax rate name: ' . $rate_name,
                        $language
                    );
                }

                // Generate label based on rate name and percentage
                $label = $rate_name;
                if (strpos($label, $rate->tax_rate . '%') === false) {
                    $label .= ' (' . $rate->tax_rate . '%)';
                }

                $postalcodes = self::get_postal_code_by_tax_rate_id($rate->tax_rate_id);
                if (in_array($postcode, $postalcodes)) {
                    $tax_details[] = [
                        'rate_id' => (int) $rate->tax_rate_id,
                        'name' => $rate_name,
                        'rate' => (float) $rate->tax_rate,
                        'shipping' => (bool) $rate->tax_rate_shipping,
                        'compound' => (bool) $rate->tax_rate_compound,
                        'priority' => (int) $rate->tax_rate_priority,
                        'class' => !empty($rate->tax_rate_class) ? $rate->tax_rate_class : 'standard',
                        'country' => $rate->tax_rate_country,
                        'state' => $rate->tax_rate_state,
                        'postcode' => $postcode,
                        'city' => $rate_city ?: '',
                        'order' => (int) $rate->tax_rate_order,
                        'rate_code' => $rate_code,
                        'label' => $label
                    ];
                }
            }

            // Base rate code is commented out in the original code
            // If needed, add the same rate_code and label logic to the base rate section

            return $tax_details;
        }

        public static function get_postal_code_by_tax_rate_id($tax_rate_id)
        {
            global $wpdb;

            // Table name for WooCommerce tax rates
            $table_name = $wpdb->prefix . 'woocommerce_tax_rate_locations';

            // Query to get the postal code associated with the tax rate ID
            $postal_codes = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT location_code 
                     FROM $table_name 
                     WHERE tax_rate_id = %d AND location_type = 'postcode'",
                    $tax_rate_id
                )
            );

            return $postal_codes;
        }

        public static function get_shipping_costs($country, $state, $postcode)
        {
            $shipping_costs = [];

            // Get all shipping zones
            $data_store = WC_Data_Store::load('shipping-zone');
            $raw_zones = $data_store->get_zones();
            $zones = array_map(function ($zone_data) {
                return new WC_Shipping_Zone($zone_data->zone_id);
            }, $raw_zones);

            // Add default zone (zone ID 0)
            $zones[] = new WC_Shipping_Zone(0);

            foreach ($zones as $zone) {
                if (self::zone_matches_address($zone, $country, $state, $postcode)) {
                    $methods = $zone->get_shipping_methods(true);

                    foreach ($methods as $method) {
                        $method_id = $method->id;
                        $method_title = $method->get_title();
                        $cost = self::get_method_cost($method);

                        if ($cost !== false) {
                            $shipping_costs[] = [
                                'zone_id' => $zone->get_zone_id(),
                                'zone_name' => $zone->get_zone_name(),
                                'method_id' => $method_id,
                                'method_title' => $method_title,
                                'cost' => $cost
                            ];
                        }
                    }
                }
            }

            return $shipping_costs;
        }

        public static function zone_matches_address($zone, $country, $state, $postcode)
        {
            // If it's the default zone (ID 0), it matches when no other zones match
            if ($zone->get_zone_id() === 0) {
                return true;
            }

            $zone_locations = $zone->get_zone_locations();

            if (empty($zone_locations)) {
                return false;
            }

            $location_matches = false;

            foreach ($zone_locations as $location) {
                switch ($location->type) {
                    case 'country':
                        if ($location->code === $country) {
                            $location_matches = true;
                        }
                        break;
                    case 'state':
                        if ($location->code === $country . ':' . $state) {
                            $location_matches = true;
                        }
                        break;
                    case 'postcode':
                        if (self::postcode_matches($postcode, $location->code)) {
                            $location_matches = true;
                        }
                        break;
                }
            }

            return $location_matches;
        }

        private static function get_method_cost($method)
        {
            if (!$method || !is_object($method)) {
                return false;
            }

            // Handle flat rate shipping
            if ($method instanceof WC_Shipping_Flat_Rate) {
                return floatval($method->get_option('cost') ?: 0);
            }

            // Handle free shipping
            if ($method instanceof WC_Shipping_Free_Shipping) {
                return 0;
            }

            // Handle local pickup
            if ($method instanceof WC_Shipping_Local_Pickup) {
                return floatval($method->get_option('cost') ?: 0);
            }

            // For other methods, try to get the cost if available
            if (method_exists($method, 'get_option') && method_exists($method, 'get_instance_id')) {
                return floatval($method->get_option('cost') ?: 0);
            }

            return 0;
        }

        private static function postcode_matches($postcode, $zone_postcode)
        {
            // Convert the postcodes to uppercase for comparison
            $postcode = strtoupper(trim($postcode));
            $zone_postcode = strtoupper(trim($zone_postcode));

            // Check for wildcards and ranges
            if (strpos($zone_postcode, '*') !== false) {
                $zone_postcode = str_replace('*', '.*', $zone_postcode);
                return (bool) preg_match("/^{$zone_postcode}$/i", $postcode);
            }

            if (strpos($zone_postcode, '-') !== false) {
                list($start, $end) = explode('-', $zone_postcode);
                return ($postcode >= trim($start) && $postcode <= trim($end));
            }

            return $postcode === $zone_postcode;
        }
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

        // callback function to get the homepage data
        public static function get_homepage_data(WP_REST_Request $request)
        {

            $language_code = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);

            // Get the original page by slug — in default language
            $default_page = get_page_by_path('home', OBJECT, 'page');
            if (!$default_page) {
                return new WP_Error('page_not_found', 'Default home page not found', ['status' => 404]);
            }

            // Always try to get the translated page ID
            $translated_id = apply_filters('wpml_object_id', $default_page->ID, 'page', false, $language_code);

            if (empty($translated_id)) {
                return new WP_Error('page_not_found', 'Page not found for language ' . $language_code, ['status' => 404]);
            }

            $page = get_post($translated_id);

            if ($page) {
                $acf_fields = function_exists('get_fields') ? get_fields($page->ID) : [];
                $acf_fields = self::enhance_acf_fields($acf_fields, $language_code);

                $retro_page_data = [
                    'id'             => $page->ID,
                    'title'          => $page->post_title,
                    'acf'            => $acf_fields,
                ];

                return rest_ensure_response($retro_page_data);
            } else {
                return new WP_Error('page_not_found', 'Page not found', ['status' => 404]);
            }
        }


        // callback function to get the brand taxonomy
        public static function get_brands_data(WP_REST_Request $request)
        {
            // Get language from request
            $lang = $request->get_param('lang');

            // Switch language using WPML if lang is provided
            if ($lang && function_exists('do_action')) {
                do_action('wpml_switch_language', $lang);
            }
            $brands = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => false, // Change to true if you only want terms with posts
            ]);

            if (is_wp_error($brands)) {
                return rest_ensure_response(['error' => $brands->get_error_message()]);
            }

            $response = [];
            foreach ($brands as $brand) {
                // Get all ACF fields for the term
                $acf_fields = function_exists('get_fields') ? get_fields('term_' . $brand->term_id) : [];
                $is_a_brands = $acf_fields['is_a_brands'] ?? '';

                // Only include terms where is_a_brands is 'yes'
                if ($is_a_brands === 'yes') {
                    // Get featured image (if available)
                    $thumbnail_id = get_term_meta($brand->term_id, 'thumbnail_id', true);
                    $featured_image = $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : '';

                    $response[] = [
                        'id'          => $brand->term_id,
                        'name'        => $brand->name,
                        'slug'        => $brand->slug,
                        'description' => $brand->description,
                        'count'       => $brand->count,
                        'link'        => get_term_link($brand),
                        'featured_image' => $featured_image,
                        'acf'         => $acf_fields, // Include all ACF fields
                    ];
                }
            }

            return rest_ensure_response($response);
        }



        // callback function for getting the testimonials
        public static function get_testimonials_data(WP_REST_Request $request)
        {

            $lang = $request->get_param('lang');
            // Switch WPML language if provided
            if ($lang && function_exists('do_action')) {
                do_action('wpml_switch_language', $lang);
            }
            $args = [
                'post_type'      => 'testimonial',
                'posts_per_page' => -1, // Get all testimonials
                'post_status'    => 'publish',
            ];

            $query = new WP_Query($args);

            if (!$query->have_posts()) {
                return rest_ensure_response([]);
            }

            $response = [];
            while ($query->have_posts()) {
                $query->the_post();

                // Fetch ACF fields if available
                $acf_fields = function_exists('get_fields') ? get_fields(get_the_ID()) : [];

                $response[] = [
                    'id'          => get_the_ID(),
                    'slug'        => get_post_field('post_name', get_the_ID()),
                    'title'       => get_the_title(),
                    'date'        => get_the_date(),
                    'featured_image' => get_the_post_thumbnail_url(get_the_ID(), 'full'),
                    'acf'         => $acf_fields, // Include ACF fields
                ];
            }

            wp_reset_postdata();

            return rest_ensure_response($response);
        }

        public static function retrovgame_contact_us(WP_REST_Request $request)
        {
            // Get the form data from the request
            $name = sanitize_text_field($request->get_param('name'));
            $phone = sanitize_text_field($request->get_param('phone'));
            $email = sanitize_email($request->get_param('email'));
            $subject = sanitize_text_field($request->get_param('subject'));
            $message = sanitize_textarea_field($request->get_param('message'));

            // Validate required fields
            if (empty($name) || empty($phone) || empty($email) || empty($subject) || empty($message)) {
                return new WP_REST_Response('All fields are required.', 400);
            }

            // Prepare the email content
            $to = get_option('admin_email');

            $email_subject = "New Contact Form Submission: " . $subject;
            $email_message = "
        Name: $name
        Phone: $phone
        Email: $email
        Subject: $subject
        Message: $message
    ";

            // Set email headers
            $headers = [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'From' => $email, // The email will be sent from the provided email
                'Reply-To' => $email,
            ];

            // Send the email using wp_mail
            $mail_sent = wp_mail($to, $email_subject, $email_message, $headers);

            // Check if the email was sent successfully
            if ($mail_sent) {
                return new WP_REST_Response(array(
                    'message' => "Thank you for your message. We'll get back to you soon!",
                    'status' => 200
                ), 200);
            } else {
                return new WP_REST_Response('Failed to send the message.', 500);
            }
        }
        // subscribe now email Callback
        public static function retrovgame_subscribe_now(WP_REST_Request $request)
        {
            // Get the form data from the request
            $email = sanitize_email($request->get_param('email'));

            // Validate required fields
            if (empty($email)) {
                return new WP_REST_Response(array('Error' => 'Enter A valid Email!!', 'status' => 400), 400);
            }

            // Prepare the email content
            $to = $email;
            $email_subject = "Thank You Email Subscription";
            $message = 'Hi there,<br><br>Thank you for subscribing to our updates. 
                        Stay tuned for exciting news and offers!<br><br>
                        <strong>Best Regards,</strong><br>Retrovgames Team';

            // Set email headers correctly
            $headers = "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: admin@retrovgames.com\r\n";
            $headers .= "Reply-To: admin@retrovgames.com\r\n";

            // Send the email using wp_mail
            $mail_sent = wp_mail($to, $email_subject, $message, $headers);

            // Check if the email was sent successfully
            if ($mail_sent) {
                return new WP_REST_Response(array(
                    'message' => "Thank you for the subscription",
                    'status' => 200
                ), 200);
            } else {
                return new WP_REST_Response('Failed to send the message.', 500);
            }
        }


        /**
         * Callback function to handle storing product IDs
         *
         * @param WP_REST_Request $request Full details about the request
         * @return WP_REST_Response|WP_Error Response object on success, or WP Error on failure
         */
        public static function retro_set_user_viewed_products(WP_REST_Request $request)
        {
            // Get the user ID from the request
            $user_id = absint($request->get_param('user_id'));

            // Validate that the user exists
            $user = get_userdata($user_id);
            if (!$user) {
                return new WP_Error(
                    'invalid_user',
                    'User does not exist',
                    array('status' => 404)
                );
            }

            // Validate input
            $product_ids = $request->get_param('product_ids');

            // Convert to array if not already an array
            if (!is_array($product_ids)) {
                // Try to split if it's a string with comma or other separators
                if (is_string($product_ids)) {
                    // Split by comma, space, or semicolon
                    $product_ids = preg_split('/[\s,;]+/', $product_ids, -1, PREG_SPLIT_NO_EMPTY);
                } elseif (is_numeric($product_ids)) {
                    // If it's a single number, wrap it in an array
                    $product_ids = array($product_ids);
                } else {
                    // If we can't convert, return an error
                    return new WP_Error(
                        'invalid_input',
                        'Product IDs must be an array, comma-separated string, or numeric value',
                        array('status' => 400)
                    );
                }
            }

            // Sanitize product IDs (ensure they are positive integers)
            $sanitized_product_ids = array_map('absint', $product_ids);

            // Remove any zero values that might have been created by sanitization
            $sanitized_product_ids = array_filter($sanitized_product_ids);

            // Check if any valid product IDs remain
            if (empty($sanitized_product_ids)) {
                return new WP_Error(
                    'invalid_products',
                    'No valid product IDs provided',
                    array('status' => 400)
                );
            }

            // Check if products exist (uncomment if needed)
            $verified_products = [];
            foreach ($sanitized_product_ids as $product_id) {
                if (wc_get_product($product_id)) {
                    // Get all available translations for this product
                    $translations = apply_filters('wpml_get_element_translations', null, $product_id, 'post_product');
                    if (!empty($translations)) {
                        foreach ($translations as $language_code => $translation) {
                            if ($translation->element_id) {
                                $verified_products[] = intval($translation->element_id);
                            }
                        }
                    } else {
                        $verified_products[] = $product_id;
                    }
                }
            }

            // Retrieve products to verify and return
            $existing_product_ids = get_user_meta($user_id, 'user_saved_product_ids', true);

            // Ensure existing_product_ids is an array (it might be an empty string on first use)
            $existing_product_ids = is_array($existing_product_ids) ? $existing_product_ids : array();

            // Merge and duplicate product IDs
            $merged_product_ids = array_values(array_unique(
                array_merge($existing_product_ids, $verified_products)
            ));
            // Store the product IDs in user meta
            $result = update_user_meta($user_id, 'user_saved_product_ids', $merged_product_ids);

            // Check if update was successful
            if ($result === false) {
                return new WP_Error(
                    'update_failed',
                    'Current Product IDs already exists in the viewed list',
                    array('status' => 400)
                );
            }

            // Retrieve products to verify and return
            $saved_products = get_user_meta($user_id, 'user_saved_product_ids', true);

            // Return successful response
            return rest_ensure_response(array(
                'message' => 'Product IDs successfully stored',
                'user_id' => $user_id,
                'saved_products' => $saved_products,
            ));
        }

        /**
         * Callback function to handle storing product IDs
         *
         * @param WP_REST_Request $request Full details about the request
         * @return WP_REST_Response|WP_Error Response object on success, or WP Error on failure
         */
        public static function retro_set_user_wishlist_products(WP_REST_Request $request)
        {
            // Get the user ID from the request
            $user_id = absint($request->get_param('user_id'));

            // Validate that the user exists
            $user = get_userdata($user_id);
            if (!$user) {
                return new WP_Error(
                    'invalid_user',
                    'User does not exist',
                    array('status' => 404)
                );
            }

            // Validate input
            $product_ids = $request->get_param('product_ids');

            // Convert to array if not already an array
            if (!is_array($product_ids)) {
                // Try to split if it's a string with comma or other separators
                if (is_string($product_ids)) {
                    // Split by comma, space, or semicolon
                    $product_ids = preg_split('/[\s,;]+/', $product_ids, -1, PREG_SPLIT_NO_EMPTY);
                } elseif (is_numeric($product_ids)) {
                    // If it's a single number, wrap it in an array
                    $product_ids = array($product_ids);
                } else {
                    // If we can't convert, return an error
                    return new WP_Error(
                        'invalid_input',
                        'Product IDs must be an array, comma-separated string, or numeric value',
                        array('status' => 400)
                    );
                }
            }

            // Sanitize product IDs (ensure they are positive integers)
            $sanitized_product_ids = array_map('absint', $product_ids);

            // Remove any zero values that might have been created by sanitization
            $sanitized_product_ids = array_filter($sanitized_product_ids);

            // Check if any valid product IDs remain
            if (empty($sanitized_product_ids)) {
                return new WP_Error(
                    'invalid_products',
                    'No valid product IDs provided',
                    array('status' => 400)
                );
            }

            // Check if products exist (uncomment if needed)
            $verified_products = [];
            foreach ($sanitized_product_ids as $product_id) {
                if (wc_get_product($product_id)) {
                    // Get all available translations for this product
                    $translations = apply_filters('wpml_get_element_translations', null, $product_id, 'post_product');
                    if (!empty($translations)) {
                        foreach ($translations as $language_code => $translation) {
                            if ($translation->element_id) {
                                $verified_products[] = intval($translation->element_id);
                            }
                        }
                    } else {
                        $verified_products[] = $product_id;
                    }
                }
            }

            // Retrieve products to verify and return
            $existing_product_ids = get_user_meta($user_id, 'user_wishlisted_product_ids', true);

            // Ensure existing_product_ids is an array (it might be an empty string on first use)
            $existing_product_ids = is_array($existing_product_ids) ? $existing_product_ids : array();

            // Merge and deduplicate product IDs
            $merged_product_ids = array_values(array_unique(
                array_merge($existing_product_ids, $verified_products)
            ));
            // Store the product IDs in user meta
            $result = update_user_meta($user_id, 'user_wishlisted_product_ids', $merged_product_ids);

            // Check if update was successful
            if ($result === false) {
                return new WP_Error(
                    'update_failed',
                    'Failed to update product IDs',
                    array('status' => 500)
                );
            }

            // Retrieve products to verify and return
            $saved_products = get_user_meta($user_id, 'user_wishlisted_product_ids', true);

            // Return successful response
            return rest_ensure_response(array(
                'message' => 'Product IDs successfully stored',
                'user_id' => $user_id,
                'saved_products' => $saved_products,
            ));
        }

        // callback function to get viewed products by user
        public static function get_user_viewed_products(WP_REST_Request $request)
        {
            // Get the user ID from the request
            $user_id = absint($request->get_param('user_id'));
            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);

            // Validate that the user exists
            $user = get_userdata($user_id);
            if (!$user) {
                return new WP_Error(
                    'invalid_user',
                    'User does not exist',
                    array('status' => 404)
                );
            }

            // Retrieve products to verify and return
            $viewed_products = get_user_meta($user_id, 'user_saved_product_ids', true);

            $viewed_products_data = [];
            foreach ($viewed_products as $product_id) {
                $product_id = apply_filters('wpml_object_id', $product_id, 'product', true, $lang);

                $current_product_data = self::get_products_by_ids($product_id);
                $viewed_products_data[] = $current_product_data;
            }

            // Return successful response
            return rest_ensure_response(array(
                'user_id' => $user_id,
                'viewed_products' => $viewed_products,
                'viewed_products_data' => $viewed_products_data,
            ));
        }

        // callback function to get get_user_wishlisted_products products by user
        public static function get_user_wishlisted_products(WP_REST_Request $request)
        {
            // Get the user ID from the request
            $user_id = absint($request->get_param('user_id'));
            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);


            // Validate that the user exists
            $user = get_userdata($user_id);
            if (!$user) {
                return new WP_Error(
                    'invalid_user',
                    'User does not exist',
                    array('status' => 404)
                );
            }

            // Retrieve products to verify and return
            $wishlisted_products = get_user_meta($user_id, 'user_wishlisted_product_ids', true);

            // Retrieve products to verify and return

            $wishlisted_products_data = [];
            foreach ($wishlisted_products as $product_id) {
                $original_id = apply_filters('wpml_object_id', $product_id, 'product', true, $lang);
                $current_product_data = self::get_products_by_ids($original_id);
                $wishlisted_products_data[] = $current_product_data;
            }
            // Return successful response
            return rest_ensure_response(array(
                'user_id' => $user_id,
                'wishlisted_products' => $wishlisted_products,
                'wishlisted_products_data' => $wishlisted_products_data,
            ));
        }

        // get get_products_bought_along callback function
        public static function get_products_bought_along(WP_REST_Request $request)
        {
            // Get product IDs from the request
            // Get product IDs from the request
            $product_ids = array_map('intval', (array) $request['product_ids']);
            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);

            // Validate product IDs
            $validated_products = [];
            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if ($product) {
                    // Get the original product ID (in default language)
                    $original_id = apply_filters('wpml_object_id', $product_id, 'product', true, apply_filters('wpml_default_language', null));

                    // Now get all translations for this original product
                    $translations = apply_filters('wpml_get_element_translations', null, $original_id, 'post_product');

                    if (!empty($translations)) {
                        foreach ($translations as $language_code => $translation) {
                            if ($translation->element_id && !in_array($translation->element_id, $validated_products)) {
                                $validated_products[] = $translation->element_id;
                            }
                        }
                    } else {
                        // If no translations found, at least use the current product
                        $validated_products[] = $product_id;
                    }
                }
            }

            if (empty($validated_products)) {
                return new WP_REST_Response(['error' => 'Invalid Product IDs', 'validated' => $product_ids, 'lang' => $lang], 404);
            }

            // Query completed orders
            $args = [
                'limit' => -1,
                'status' => 'completed',
                'type' => 'shop_order',
            ];

            $orders = wc_get_orders($args);

            // Related products tracking
            $related_products = [];

            // Iterate through each order
            foreach ($orders as $order) {
                $order_items = $order->get_items();
                $order_product_ids = [];

                // Collect product IDs in this order
                foreach ($order_items as $item) {
                    $current_product_id = $item->get_product_id();
                    $order_product_ids[] = $current_product_id;
                }

                // Check if any of the target products are in this order
                if (array_intersect($validated_products, $order_product_ids)) {
                    // Find other products in the same order
                    foreach ($order_items as $item) {
                        $related_product_id = $item->get_product_id();

                        // Skip the target products themselves
                        if (in_array($related_product_id, $validated_products)) {
                            continue;
                        }

                        // Track related products
                        if (!isset($related_products[$related_product_id])) {
                            $related_products[$related_product_id] = [
                                'count' => 0,
                                'product' => wc_get_product($related_product_id)
                            ];
                        }
                        $related_products[$related_product_id]['count']++;
                    }
                }
            }

            // Sort related products by frequency
            uasort($related_products, function ($a, $b) {
                return $b['count'] - $a['count'];
            });

            // Prepare response
            $response_products = [];
            $max_products = 10; // Limit to top 10 related products
            $count = 0;

            foreach ($related_products as $product_id => $product_data) {
                if ($count >= $max_products) break;

                $product = $product_data['product'];
                if (!$product) continue;
                $product = wc_get_product($product_id);
                if ($product) {
                    $product_id = apply_filters('wpml_object_id', $product_id, 'product', true, $lang);

                    $featured_image = wp_get_attachment_image_src(get_post_thumbnail_id($product_id), 'full');

                    // Fetch reviews
                    $comments = get_comments(['post_id' => $product_id, 'type' => 'review']);
                    $total_reviews = count($comments);

                    // Calculate total ratings
                    $total_rating = 0;
                    foreach ($comments as $comment) {
                        $rating = get_comment_meta($comment->comment_ID, 'rating', true);
                        if ($rating) {
                            $total_rating += (int) $rating;
                        }
                    }

                    // Fetch product URL
                    $product_url = get_permalink($product_id);

                    // Fetch assigned categories
                    $categories = get_the_terms($product_id, 'product_cat');
                    $category_names = [];
                    if (!empty($categories) && !is_wp_error($categories)) {
                        foreach ($categories as $category) {
                            $category_names[] = $category->name;
                        }
                    }

                    // Fetch price details
                    $price_data = [];
                    if ($product->is_type('variable')) {
                        // Get price range for variable products
                        $price_data['min_price'] = $product->get_variation_price('min');
                        $price_data['max_price'] = $product->get_variation_price('max');
                    } else {
                        // Get regular and sale price for simple products
                        $price_data['regular_price'] = $product->get_regular_price();
                        $price_data['sale_price'] = $product->get_sale_price();
                    }
                    $attributes_list = self::get_product_attributes_array($product_id);
                    $sold_this_month = self::retro_sold_counter($product_id);
                    $response_products[] = [
                        'id'             => $product_id,
                        'slug'           => $product->get_slug(),
                        'product_type'   => $product->get_type(),
                        'name'           => $product->get_name(),
                        'on_sale'     => $product->is_on_sale(),
                        'price'          => $price_data,
                        'description'    => $product->get_description(),
                        'product_url'    => $product_url,
                        'categories'     => $category_names,
                        'featured_image' => $featured_image ? $featured_image[0] : null,
                        'total_reviews'  => $total_reviews,
                        'total_rating'   => $total_reviews > 0 ? $total_rating : null,
                        'attributes' => $attributes_list,
                        'sold_this_month' => $sold_this_month
                    ];
                }

                $count++;
            }
            if (count($response_products) == 0) {
                $response_products = self::bought_along_fallback_products($validated_products);
            }
            return new WP_REST_Response([
                'original_product_ids' => $validated_products,
                'related_products' => $response_products,
            ], 200);
        }


        public static function bought_along_fallback_products($product_ids)
        {
            $product_info = [];

            $args = array(
                'post_type' => 'product',
                'posts_per_page' => 8,
                'post__not_in' => $product_ids, // Exclude the current product

            );

            $related_products = new WP_Query($args);

            if ($related_products->have_posts()) {
                while ($related_products->have_posts()) {
                    $related_products->the_post();
                    // Display product information
                    $product_id = get_the_ID(); // Replace with your product ID
                    $product = wc_get_product($product_id);

                    // Get product data
                    $product_url = get_permalink($product_id);
                    $category_names = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
                    $featured_image = wp_get_attachment_image_src(get_post_thumbnail_id($product_id), 'full');
                    $total_reviews = get_comments_number($product_id); // Total reviews
                    $total_rating = $product->get_average_rating(); // Average rating

                    $price_data = [];
                    if ($product->is_type('variable')) {
                        // Get price range for variable products
                        $price_data['min_price'] = $product->get_variation_price('min');
                        $price_data['max_price'] = $product->get_variation_price('max');
                    } else {
                        // Get regular and sale price for simple products
                        $price_data['regular_price'] = $product->get_regular_price();
                        $price_data['sale_price'] = $product->get_sale_price();
                    }
                    $attributes_list = self::get_product_attributes_array($product_id);
                    $sold_this_month = self::retro_sold_counter($product_id);

                    // Prepare the product info array
                    $product_info[] = [
                        'id'             => $product_id,
                        'slug'           => $product->get_slug(),
                        'product_type'   => $product->get_type(),
                        'name'           => $product->get_name(),
                        'on_sale'     => $product->is_on_sale(),
                        'price'          => $price_data,
                        'description'    => $product->get_description(),
                        'product_url'    => $product_url,
                        'categories'     => $category_names,
                        'featured_image' => $featured_image ? $featured_image[0] : null,
                        'total_reviews'  => $total_reviews,
                        'total_rating'   => $total_reviews > 0 ? $total_rating : null,
                        'attributes' => $attributes_list,
                        'sold_this_month' => $sold_this_month

                    ];
                }
                wp_reset_postdata();
            }
            return $product_info;
        }
        // callback function to get search results
        public static function retrovgame_search(WP_REST_Request $request)
        {
            $search_term = sanitize_text_field($request->get_param('search'));
            $post_type   = $request->get_param('post_type') ? sanitize_text_field($request->get_param('post_type')) : 'product';

            // Base Query Arguments
            $args = [
                'post_type'      => ['post', 'product'],
                'posts_per_page' => -1,
                's'              => $search_term,
            ];

            $query = new WP_Query($args);

            $product_list = [];

            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    global $product;

                    if ($post_type === 'product' && class_exists('WooCommerce') && wc_get_product(get_the_ID())) {
                        $product        = wc_get_product(get_the_ID());
                        $price_data     = $product->get_price();
                        $product_url    = get_permalink($product->get_id());
                        $category_ids   = $product->get_category_ids();
                        $category_names = array_map(function ($cat_id) {
                            $term = get_term($cat_id);
                            return $term ? $term->name : '';
                        }, $category_ids);
                        $featured_image = wp_get_attachment_image_src(get_post_thumbnail_id($product->get_id()), 'full');
                        $total_reviews  = $product->get_review_count();
                        $total_rating   = $product->get_average_rating();
                        $attributes_list = self::get_product_attributes_array($product_id);
                        $sold_this_month = self::retro_sold_counter($product_id);

                        $product_list[] = [
                            'id'             => get_the_ID(),
                            'product_type'   => $product->get_type(),
                            'name'           => $product->get_name(),
                            'price'          => $price_data,
                            'description'    => $product->get_description(),
                            'product_url'    => $product_url,
                            'categories'     => $category_names,
                            'featured_image' => $featured_image ? $featured_image[0] : null,
                            'total_reviews'  => $total_reviews,
                            'total_rating'   => $total_reviews > 0 ? $total_rating : null,
                            'stock_quantity' => $product->get_stock_quantity(),
                            'attributes' => $attributes_list,
                            'sold_this_month' => $sold_this_month

                        ];
                    } else {
                        // Default post structure
                        $product_list[] = [
                            'id'          => get_the_ID(),
                            'name'        => get_the_title(),
                            'description' => get_the_excerpt(),
                            'product_url' => get_permalink(),
                            'categories'  => wp_get_post_categories(get_the_ID(), ['fields' => 'names']),
                            'featured_image' => has_post_thumbnail() ? wp_get_attachment_image_url(get_post_thumbnail_id(), 'full') : null,
                        ];
                    }
                }
                wp_reset_postdata();
            }

            if (empty($product_list)) {
                return new WP_Error('no_results', 'No search results found', ['status' => 404]);
            }

            return rest_ensure_response($product_list);
        }
        // callback function to get search results
        public static function retrovgame_search_autosuggest(WP_REST_Request $request)
        {
            global $wpdb;
            $search_term = sanitize_text_field($request->get_param('search'));

            if (empty($search_term)) {
                return new WP_REST_Response(['error' => 'No search term provided'], 400);
            }

            $search_term = '%' . $wpdb->esc_like($search_term) . '%';

            // Custom SQL query to search only in post_title
            $query = $wpdb->prepare("
                SELECT ID, post_title 
                FROM {$wpdb->posts} 
                WHERE post_type = 'product' 
                AND post_status = 'publish' 
                AND post_title LIKE %s 
                LIMIT 20
            ", $search_term);

            $results = $wpdb->get_results($query);

            $products = [];

            if (!empty($results)) {
                foreach ($results as $result) {
                    $product = wc_get_product($result->ID);
                    // Fetch price details
                    $price_data = [];
                    if ($product->is_type('variable')) {
                        // Get price range for variable products
                        $price_data['min_price'] = $product->get_variation_price('min');
                        $price_data['max_price'] = $product->get_variation_price('max');
                    } else {
                        // Get regular and sale price for simple products
                        $price_data['regular_price'] = $product->get_regular_price();
                        $price_data['sale_price'] = $product->get_sale_price();
                    }
                    $status = get_post_status($result->ID);
                    if ($status === 'publish') {

                        $products[] = [
                            'id'    => $product->get_id(),
                            'name'  => $product->get_name(),
                            'slug' =>   $product->get_slug(),
                            'featured_img' => get_the_post_thumbnail_url($product->get_id(), 'full'),
                            'price'          => $price_data,
                            'status' => $status
                        ];
                    }
                }
            }

            $results = [
                'products' => $products,
                'total_results' => count($results)
            ];
            return new WP_REST_Response($results, 200);
        }
        // get meta tags functions callback
        public static function retrovgame_get_meta_tags(WP_REST_Request $request){
            $metatags= get_field('meta_tags', 'option');
            return new WP_REST_Response(['metatags' => $metatags], 200);
        }
        // public static function elasticsearch_search(WP_REST_Request $request)
        // {

        //     // Ensure Elasticsearch constants are defined
        //     if (!defined('EP_HOST') || !defined('EP_INDEX_NAME')) {
        //         return new WP_REST_Response([
        //             'error' => 'Elasticsearch configuration missing',
        //             'message' => 'Host or Index Name not configured'
        //         ], 500);
        //     }

        //     // Get search parameters
        //     $query = sanitize_text_field($request->get_param('query') ?? '');
        //     $page = max(1, intval($request->get_param('page') ?? 1));
        //     $per_page = min(100, max(1, intval($request->get_param('per_page') ?? 10)));

        //     // Prepare the search request body
        //     $search_body = [
        //         'query' => [
        //             'bool' => [
        //                 'should' => [
        //                     ['multi_match' => [
        //                         'query' => $query,
        //                         'fields' => [
        //                             'post_title^3',
        //                             'post_content^2',
        //                             'post_excerpt',
        //                             'terms.category.name^1.5',
        //                             'terms.post_tag.name^1.5'
        //                         ],
        //                         'type' => 'best_fields',
        //                         'minimum_should_match' => '50%'
        //                     ]]
        //                 ]
        //             ]
        //         ],
        //         'highlight' => [
        //             'fields' => [
        //                 'post_content' => [
        //                     'fragment_size' => 150,
        //                     'number_of_fragments' => 1
        //                 ]
        //             ]
        //         ],
        //         'from' => ($page - 1) * $per_page,
        //         'size' => $per_page,
        //         'sort' => [
        //             '_score' => ['order' => 'desc']
        //         ]
        //     ];

        //     // Perform the Elasticsearch search
        //     try {
        //         // Use wp_remote_post for the search request
        //         $response = wp_remote_post(
        //             rtrim(EP_HOST, '/') . '/' . EP_INDEX_NAME . '/_search',
        //             [
        //                 'headers' => [
        //                     'Content-Type' => 'application/json'
        //                 ],
        //                 'body' => json_encode($search_body)
        //             ]
        //         );

        //         // Check for errors in the request
        //         if (is_wp_error($response)) {
        //             return new WP_REST_Response([
        //                 'error' => 'Elasticsearch search failed',
        //                 'message' => $response->get_error_message()
        //             ], 500);
        //         }

        //         // Parse the response
        //         $body = wp_remote_retrieve_body($response);
        //         $search_results = json_decode($body, true);

        //         // Transform Elasticsearch results
        //         $formatted_results = [
        //             'total' => $search_results['hits']['total']['value'] ?? 0,
        //             'page' => $page,
        //             'per_page' => $per_page,
        //             'results' => []
        //         ];

        //         // Process hits
        //         foreach ($search_results['hits']['hits'] as $hit) {
        //             $result = [
        //                 'id' => $hit['_id'],
        //                 'title' => $hit['_source']['post_title'] ?? '',
        //                 'content' => $hit['_source']['post_content'] ?? '',
        //                 'excerpt' => $hit['_source']['post_excerpt'] ?? '',
        //                 'url' => $hit['_source']['permalink'] ?? '',
        //                 'post_type' => $hit['_source']['post_type'] ?? ''
        //             ];

        //             // Add highlighted content if available
        //             if (isset($hit['highlight']['post_content'][0])) {
        //                 $result['highlighted_content'] = $hit['highlight']['post_content'][0];
        //             }

        //             $formatted_results['results'][] = $result;
        //         }

        //         return new WP_REST_Response($search_results, 200);
        //     } catch (Exception $e) {
        //         return new WP_REST_Response([
        //             'error' => 'Search failed',
        //             'message' => $e->getMessage()
        //         ], 500);
        //     }
        // }

        public static function retrovgame_product_sales_this_month(WP_REST_Request $request)
        {
            $product_id = $request->get_param('product_id');
            // Validate the product ID
            if (! $product_id || ! is_numeric($product_id) || (int) $product_id <= 0) {
                return new WP_Error('invalid_product_id', 'Product ID is required, must be numeric, and greater than 0.', ['status' => 400]);
            }

            $sales_count = self::retro_sold_counter($product_id);

            return rest_ensure_response([
                'product_id' => $product_id,
                'sales_this_month' => $sales_count,
            ]);
        }

        public static function retro_sold_counter($product_id)
        {


            // Get the first and last day of the current month
            $start_date = date('Y-m-01 00:00:00');
            $end_date   = date('Y-m-t 23:59:59');

            // Query WooCommerce orders for the current month
            $args = [
                'status' => ['wc-completed', 'wc-processing'], // Include only completed or processing orders
                'date_query' => [
                    'after'     => $start_date,
                    'before'    => $end_date,
                    'inclusive' => true,
                ],
                'limit' => -1, // Retrieve all orders in the date range
            ];

            $orders = wc_get_orders($args);
            $sales_count = 0;

            foreach ($orders as $order) {
                foreach ($order->get_items() as $item) {
                    if ((int) $item->get_product_id() === (int) $product_id) {
                        $sales_count += $item->get_quantity();
                    }
                }
            }
            return $sales_count;
        }
        // get the products data using their id 
        public static function retrovgame_get_product_by_id(WP_REST_Request $request)
        {
            $product_ids = $request->get_param('product_ids');
            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);


            if (!is_array($product_ids) && !empty($product_ids)) {
                $product_ids = explode(',', $product_ids);
            }

            if (empty($product_ids) || !is_array($product_ids) || count($product_ids) == 0) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Product ID is required, must be numeric, and greater than 0.',
                ], 400);
            }


            $products_data = [];
            foreach ($product_ids as $product_id) {
                $product_id = apply_filters('wpml_object_id', $product_id, 'product', true, $lang);

                $current_product_data = self::get_detailed_products_by_ids($product_id);
                $products_data[] = $current_product_data;
            };

            if (empty($products_data) || !is_array($products_data) || count($products_data) == 0) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'No products found.',
                ], 404);
            } else {
                return rest_ensure_response([
                    'success' => true,
                    'data' => $products_data,
                    '$products_ids' => $product_ids
                ]);
            }
        }
        // get the cart products details

        public static function retrovgame_get_cart_products_details(WP_REST_Request $request)
        {
            $params = $request->get_json_params();
            $products_input = $params['products'] ?? [];
            $coupon_code = $params['coupon_code'] ?? null;
            $shipping_method = $params['shipping_method'] ?? null;
            $shipping_method_data = $shipping_method ? self::get_shipping_methods_by_method_id($shipping_method) : null;
            $country = $params['country'] ?? null;
            $state = $params['state'] ?? null;
            $postcode = $params['postcode'] ?? null;
            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);

            // Validate input parameters
            // if (empty($country) || empty($state) || empty($postcode)) {
            //     return new WP_Error('missing_params', 'Country, state, and postal code are required.', ['status' => 400]);
            // }
            $tax_details = [];
            if (!empty($country) || !empty($state) || !empty($postcode)) {
                $country = sanitize_text_field($country);
                $country = sanitize_text_field(self::get_country_code_by_name($country));
                $state = sanitize_text_field($state);
                $state = sanitize_text_field(self::get_state_code_by_name($country, $state));
                $postcode = wc_normalize_postcode(wc_clean($postcode));

                // Get tax details with proper tax class
                $tax_details = self::get_tax_details($country, $state, $postcode, $lang);
            }
            $data = [];

            foreach ($products_input as $product_data) {
                $product_id = $product_data['id'];
                $variation_id = $product_data['variation_id'] ?? null;
                $additional_products_ids = $product_data['additional_products'] ?? [];
                $additional_games_ids = $product_data['additional_games'] ?? [];

                $product = wc_get_product($product_id);
                if (!$product) continue;
                $has_a_quantity_discount = get_field('has_a_quantity_discount', $product_id);
                $product_response = [
                    'id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'slug' => $product->get_slug(),
                    'type' => $product->get_type(),
                    'price' => wc_format_decimal($product->get_price()),
                    'on_sale' => $product->is_on_sale(),
                    'sale_price' => $product->get_sale_price(),
                    'regular_price' => $product->get_regular_price(),
                    'stock_quantity' => $product->get_stock_quantity(),
                    'images' => [
                        [
                            'id' => get_post_thumbnail_id($product->get_id()),
                            'src' => get_the_post_thumbnail_url($product->get_id(), 'full'),
                            'alt' => get_post_meta(get_post_thumbnail_id($product->get_id()), '_wp_attachment_image_alt', true),
                        ]
                    ],
                    'categories' => [],
                    'description' => wp_kses_post($product->get_description()),
                    'meta' => [
                        'has_a_quantity_discount' => $has_a_quantity_discount
                    ]
                ];

                // Images

                // foreach ($product->get_gallery_image_ids() as $image_id) {
                //     $product_response['images'][] = [
                //         'id' => get_post_thumbnail_id(),
                //         'src' => wp_get_attachment_url($image_id),
                //         'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                //     ];
                // }

                // Categories
                $term_objects = get_the_terms($product_id, 'product_cat');
                if (!is_wp_error($term_objects)) {
                    foreach ($term_objects as $term) {
                        $product_response['categories'][] = [
                            'id' => $term->term_id,
                            'name' => $term->name,
                            'slug' => $term->slug,
                        ];
                    }
                }

                // Variation
                if ($variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $product_response['variation'] = [
                            'id' => $variation->get_id(),
                            'regular_price' => $variation->get_regular_price(),
                            'sale_price' => $variation->get_sale_price(),
                            'current_price' => $variation->get_price(),
                            'stock_quantity' => $variation->get_stock_quantity(),
                            'stock_status' => $variation->get_stock_status(),
                        ];
                    }
                }

                // Additional Products
                $product_response['additionalProducts'] = [];
                foreach ($additional_products_ids as $id) {
                    $additional = wc_get_product($id);
                    if ($additional) {
                        $product_response['additionalProducts'][] = [
                            'id' => $additional->get_id(),
                            'type' => $additional->get_type(),
                            'name' => $additional->get_name(),
                            'slug' => $additional->get_slug(),
                            'price' => $additional->get_price(),
                            'on_sale' => $additional->is_on_sale(),
                            'sale_price' => $additional->get_sale_price(),
                            'regular_price' => $additional->get_regular_price(),
                        ];
                    }
                }

                // Additional Games
                $product_response['additionalGames'] = [];
                foreach ($additional_games_ids as $id) {
                    $additional = wc_get_product($id);
                    if ($additional) {
                        $product_response['additionalGames'][] = [
                            'id' => $additional->get_id(),
                            'type' => $additional->get_type(),
                            'name' => $additional->get_name(),
                            'slug' => $additional->get_slug(),
                            'price' => $additional->get_price(),
                            'on_sale' => $additional->is_on_sale(),
                            'sale_price' => $additional->get_sale_price(),
                            'regular_price' => $additional->get_regular_price(),
                        ];
                    }
                }

                $data[] = $product_response;
            }

            return rest_ensure_response([
                'success' => true,
                'data' => $data,
                'coupon' => self::get_coupon_data_by_code($coupon_code),
                'shipping_method' => $shipping_method_data,
                'tax_details' => $tax_details,
                "state_short_code" => $state,
                "country_short_code" => $country,
            ]);
        }
        /**
         * Get the complete coupon object by coupon code.
         *
         * @param string $coupon_code The coupon code.
         * @return WC_Coupon|false The coupon object or false if not found.
         */
        public static function get_coupon_data_by_code($coupon_code)
        {
            if (! class_exists('WC_Coupon')) {
                return false;
            }

            try {
                $coupon = new WC_Coupon($coupon_code);

                if (! $coupon->get_id()) {
                    return false;
                }

                $id = $coupon->get_id();
                $base_url = get_rest_url(null, 'wc/v3/coupons/' . $id);

                return [
                    'id' => $id,
                    'code' => $coupon->get_code(),
                    'amount' => $coupon->get_amount(),
                    'status' => get_post_status($coupon->get_id()),
                    'date_created' => $coupon->get_date_created() ? $coupon->get_date_created()->date('c') : null,
                    'date_created_gmt' => $coupon->get_date_created() ? $coupon->get_date_created()->date('c') : null,
                    'date_modified' => $coupon->get_date_modified() ? $coupon->get_date_modified()->date('c') : null,
                    'date_modified_gmt' => $coupon->get_date_modified() ? $coupon->get_date_modified()->date('c') : null,
                    'discount_type' => $coupon->get_discount_type(),
                    'description' => $coupon->get_description(),
                    'date_expires' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('c') : null,
                    'date_expires_gmt' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('c') : null,
                    'usage_count' => $coupon->get_usage_count(),
                    'individual_use' => $coupon->get_individual_use(),
                    'product_ids' => $coupon->get_product_ids(),
                    'excluded_product_ids' => $coupon->get_excluded_product_ids(),
                    'usage_limit' => $coupon->get_usage_limit(),
                    'usage_limit_per_user' => $coupon->get_usage_limit_per_user(),
                    'limit_usage_to_x_items' => $coupon->get_limit_usage_to_x_items(),
                    'free_shipping' => $coupon->get_free_shipping(),
                    'product_categories' => $coupon->get_product_categories(),
                    'excluded_product_categories' => $coupon->get_excluded_product_categories(),
                    'exclude_sale_items' => $coupon->get_exclude_sale_items(),
                    'minimum_amount' => $coupon->get_minimum_amount(),
                    'maximum_amount' => $coupon->get_maximum_amount(),
                    'email_restrictions' => $coupon->get_email_restrictions(),
                    'used_by' => $coupon->get_used_by(),
                    'meta_data' => array_map(function ($meta) {
                        return [
                            'id' => $meta->id,
                            'key' => $meta->key,
                            'value' => $meta->value,
                        ];
                    }, $coupon->get_meta_data()),
                    '_links' => [
                        'self' => [[
                            'href' => $base_url,
                            'targetHints' => [
                                'allow' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']
                            ]
                        ]],
                        'collection' => [[
                            'href' => get_rest_url(null, 'wc/v3/coupons')
                        ]],
                    ],
                ];
            } catch (Exception $e) {
                return ['error' => $e->getMessage()];
            }
        }

        // get filters data like term 

        public static function retrovgame_get_terms(WP_REST_Request $request)
        {
            $attribute_slugs = ['pa_brand', 'pa_condition', 'pa_genre', 'pa_players', 'pa_product-type'];

            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);

            // Switch language using WPML if lang is provided and different from default
            if ($lang && function_exists('do_action')) {
                do_action('wpml_switch_language', $lang);
            };

            $results = [];

            foreach ($attribute_slugs as $slug) {
                // Fetch terms for the attribute taxonomy
                $terms = get_terms([
                    'taxonomy' => $slug,
                    'hide_empty' => false,
                ]);

                // Initialize an empty array for each attribute
                $results[$slug] = [];

                if (!is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $results[$slug][] = [
                            'term_id' => $term->term_id,
                            'slug' => $term->slug,
                            'name' => $term->name,
                        ];
                    }
                } 
            }

            return new WP_REST_Response([
                'success' => true,
                'terms' => $results
            ]);
        }
        public static function filter_products_api_callback(WP_REST_Request $request)
        {
            $platform = $request->get_param('platform') ? array_map('sanitize_text_field', (array) $request->get_param('platform')) : [];
            $condition = $request->get_param('condition') ? array_map('sanitize_text_field', (array) $request->get_param('condition')) : [];
            $genre = $request->get_param('genre') ? array_map('sanitize_text_field', (array) $request->get_param('genre')) : [];
            $players = $request->get_param('players') ? array_map('sanitize_text_field', (array) $request->get_param('players')) : [];
            $product_type = $request->get_param('product-type') ? array_map('sanitize_text_field', (array) $request->get_param('product-type')) : [];
            $category = $request->get_param('category') ? array_map('sanitize_text_field', (array) $request->get_param('category')) : [];
            $paged = $request->get_param('page') ? intval($request->get_param('page')) : 1;
            $products_per_page = $request->get_param('products_per_page') ? intval($request->get_param('products_per_page')) : 8;
            $minprice = $request->get_param('minprice') ? intval($request->get_param('minprice')) : 1;
            $maxprice = $request->get_param('maxprice') ? intval($request->get_param('maxprice')) : 1;
            $sorting = $request->get_param('sorting') ? sanitize_text_field($request->get_param('sorting')) : '';
            $search = $request->get_param('search') ? sanitize_text_field($request->get_param('search')) : '';
            // Get the language parameter from the API request
            $lang = $request->get_param('lang');


            // if (empty($category) || count($category) <= 0) {
            //     return new WP_REST_Response([
            //         'success' => false,
            //         'message' => 'The category parameter is required and must be an integer.',
            //     ], 400);
            // }
            // Verify each category term exists and belongs to 'product_cat'
            $valid_categories = [];
            if (count($category) > 0) {


                foreach ($category as $cat_id) {
                    $term = get_term_by('id', $cat_id, 'product_cat');
                    if ($term && !is_wp_error($term)) {
                        $valid_categories[] = $cat_id;
                    }
                }

                // If no valid categories, return an error
                if (empty($valid_categories)) {
                    return new WP_REST_Response([
                        'success' => false,
                        'message' => 'Invalid category provided. Please use valid product category IDs.',
                    ], 400);
                }
            }
            // Switch WPML language if provided
            // if ($lang && function_exists('do_action')) {
            //     do_action('wpml_switch_language', $lang);
            // }
            // query arguments
            // Base query args
            $args = [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $products_per_page,
                'paged' => $paged,
            ];
            $optional_tax_queries = [];

            if (!empty($valid_categories)) {
                $optional_tax_queries[] = [
                    'taxonomy' => 'product_cat',
                    'field'    => 'id',
                    'terms'    => $valid_categories,
                    'operator' => 'IN',
                ];
            }

            if (!empty($search)) {
                $args['s'] = $search; // Add the search term to the query
            }
            // Initialize array for optional filters

            // Add optional tax queries only if filters are provided
            if (!empty($platform)) {
                $optional_tax_queries[] = [
                    'taxonomy' => 'pa_platform',
                    'field'    => 'id',
                    'terms'    => $platform,
                    'operator' => 'IN',
                ];
            }

            if (!empty($condition)) {
                $optional_tax_queries[] = [
                    'taxonomy' => 'pa_condition',
                    'field'    => 'id',
                    'terms'    => $condition,
                    'operator' => 'IN',
                ];
            }

            if (!empty($genre)) {
                $optional_tax_queries[] = [
                    'taxonomy' => 'pa_genre',
                    'field'    => 'id',
                    'terms'    => $genre,
                    'operator' => 'IN',
                ];
            }

            if (!empty($players)) {
                $optional_tax_queries[] = [
                    'taxonomy' => 'pa_players',
                    'field'    => 'id',
                    'terms'    => $players,
                    'operator' => 'IN',
                ];
            }

            if (!empty($product_type)) {
                $product_type_terms = [];
                $numeric_types = [];
                $name_types = [];

                // Separate numeric IDs from name strings
                foreach ($product_type as $type) {
                    if (is_numeric($type)) {
                        $numeric_types[] = intval($type);
                    } else {
                        $name_types[] = $type;
                    }
                }

                // Add any term IDs we found
                if (!empty($numeric_types)) {
                    $product_type_terms = array_merge($product_type_terms, $numeric_types);
                }

                // Look up any terms by name and add their IDs
                if (!empty($name_types)) {
                    foreach ($name_types as $type_name) {
                        $term = get_term_by('name', $type_name, 'pa_product-type');
                        if ($term && !is_wp_error($term)) {
                            $product_type_terms[] = $term->term_id;
                        }
                    }
                }

                // Only add the tax query if we found valid terms
                if (!empty($product_type_terms)) {
                    $optional_tax_queries[] = [
                        'taxonomy' => 'pa_product-type',
                        'field'    => 'id',
                        'terms'    => $product_type_terms,
                        'operator' => 'IN',
                    ];
                }
            }

            // If optional filters exist, add them with OR relation
            if (!empty($optional_tax_queries)) {
                $args['tax_query'][] = [
                    'relation' => 'AND', // Apply OR relation for optional filters
                    ...$optional_tax_queries, // Spread the filters to the query
                ];
            }

            // Run the query
            $query = new WP_Query($args);



            // Apply sorting logic
            if (!empty($sorting)) {
                global $wpdb;

                if ($sorting == 'price-asc') {
                    $args['orderby'] = 'meta_value_num';
                    $args['meta_key'] = '_price';
                    $args['order'] = 'ASC';
                } elseif ($sorting == 'price-desc') {
                    $args['orderby'] = 'meta_value_num';
                    $args['meta_key'] = '_price';
                    $args['order'] = 'DESC';
                } elseif ($sorting == 'oldfirst') {
                    $args['orderby'] = 'date';
                    $args['order'] = 'ASC';
                } elseif ($sorting == 'new-arrivals') {
                    $args['orderby'] = 'date';
                    $args['order'] = 'DESC';
                } elseif ($sorting == 'best-sellers') {
                    $args['orderby'] = 'meta_value_num';
                    $args['meta_key'] = 'total_sales';
                    $args['order'] = 'DESC';
                } elseif ($sorting == 'name-asc') { // A-Z Sorting
                    // For A-Z, sort alphabetic characters first, then special characters
                    $args['meta_query'] = [
                        'relation' => 'OR',
                        [
                            'key' => '_temp_alpha_sort',
                            'compare' => 'NOT EXISTS',
                        ],
                    ];

                    add_filter('posts_clauses', function ($clauses) use ($wpdb) {
                        $clauses['orderby'] = "CASE 
                            WHEN {$wpdb->posts}.post_title REGEXP '^[A-Za-z]' THEN 1
                            ELSE 2
                            END ASC, 
                            {$wpdb->posts}.post_title ASC";
                        return $clauses;
                    }, 10, 1);
                } elseif ($sorting == 'name-desc') { // Z-A Sorting
                    // For Z-A, sort special characters first, then alphabetic characters in reverse
                    $args['meta_query'] = [
                        'relation' => 'OR',
                        [
                            'key' => '_temp_alpha_sort',
                            'compare' => 'NOT EXISTS',
                        ],
                    ];

                    add_filter('posts_clauses', function ($clauses) use ($wpdb) {
                        $clauses['orderby'] = "CASE 
                            WHEN {$wpdb->posts}.post_title REGEXP '^[A-Za-z]' THEN 2
                            ELSE 1
                            END ASC, 
                            {$wpdb->posts}.post_title DESC";
                        return $clauses;
                    }, 10, 1);
                } elseif ($sorting == 'recommended') { // Recommended Sorting
                    $args['orderby'] = ['menu_order' => 'ASC', 'date' => 'DESC']; // Sort by menu order, then newest first
                }
            }

            // Apply price range filtering
            // Apply price range filtering
            if ($minprice >= 0 && $maxprice > 0) {
                // Get current language
                $current_lang = apply_filters('wpml_current_language', null);

                // Use the correct meta key based on language
                $price_meta_key = ($current_lang != 'en' && $lang) ? '_price_' . $lang : '_price';

                $args['meta_query'] = [
                    'relation' => 'AND',
                    [
                        'key'     => '_price',
                        'value'   => [$minprice, $maxprice],
                        'compare' => 'BETWEEN',
                        'type'    => 'NUMERIC',
                    ],
                ];
            }

            // Run the query
            $query = new WP_Query($args);

            if ($query->have_posts()) {
                $product_list = [];
                while ($query->have_posts()) {
                    $query->the_post();

                    global $product;

                    // Include the product loop template
                    $related_id = apply_filters('wpml_object_id', get_the_ID(), 'product', true, $lang);

                    $product = wc_get_product($related_id);
                    // $related_id = get_the_ID();
                    if ($product) {
                        $featured_image = wp_get_attachment_image_src(get_post_thumbnail_id($related_id), 'full');
                        // Fetch reviews
                        $comments = get_comments(['post_id' => $related_id, 'type' => 'review']);
                        $total_reviews = count($comments);

                        // Calculate total ratings
                        $total_rating = 0;
                        foreach ($comments as $comment) {
                            $rating = get_comment_meta($comment->comment_ID, 'rating', true);
                            if ($rating) {
                                $total_rating += (int)$rating;
                            }
                        }
                        // Fetch product URL
                        $product_url = get_permalink($related_id);

                        // Fetch assigned categories
                        $categories = get_the_terms($related_id, 'product_cat');
                        $category_names = [];
                        if (!empty($categories) && !is_wp_error($categories)) {
                            foreach ($categories as $category) {
                                $category_names[] = $category->name;
                            }
                        }
                        // Fetch price details
                        $price_data = [];
                        if ($product->is_type('variable')) {
                            // Get price range for variable products
                            $price_data['min_price'] = $product->get_variation_price('min');
                            $price_data['max_price'] = $product->get_variation_price('max');
                        } else {
                            // Get regular and sale price for simple products
                            $price_data['regular_price'] = $product->get_regular_price();
                            $price_data['sale_price'] = $product->get_sale_price();
                        }
                        $attributes_list = self::get_product_attributes_array($related_id);
                        $sold_this_month = self::retro_sold_counter($related_id);

                        $product_list[] = [
                            'id'             => $related_id,
                            'slug'           => $product->get_slug(),
                            'product_type'   => $product->get_type(),
                            'name'           => $product->get_name(),
                            'price'          => $price_data,
                            'description'    => $product->get_description(),
                            'product_url'    => $product_url,
                            'categories'     => $category_names,
                            'featured_image' => $featured_image ? $featured_image[0] : null,
                            'total_reviews'  => $total_reviews,
                            'total_rating'   => $total_reviews > 0 ? $total_rating : null, // Avoid division if no reviews
                            'stock_quantity' => $product->get_stock_quantity(),
                            'attributes' => $attributes_list,
                            'total_sales' => get_post_meta(get_the_ID(), 'total_sales', true),
                            'sold_this_month' => $sold_this_month
                        ];
                    }
                }

                wp_reset_postdata();


                return new WP_REST_Response([
                    'success'      => true,
                    'products'     => $product_list,
                    'total_pages'  => $query->max_num_pages,
                    'total_posts'  => $query->found_posts,
                ], 200);
            }

            return new WP_REST_Response([
                'success' => false,
                'message' => 'No products found.',
                'args' => $args
            ], 404);
        }

        private static function enhance_acf_fields($fields, $lang)
        {
            foreach ($fields as $field_key => $field_value) {
                if (is_array($field_value)) {
                    if (!empty($field_value) && is_numeric($field_value[0])) {
                        // Process relationship field (array of IDs)
                        $enhanced_data = [];
                        foreach ($field_value as $related_id) {
                            $related_id = apply_filters('wpml_object_id', $related_id, 'product', true, $lang);

                            $product = wc_get_product($related_id); // WooCommerce product object
                            if ($product) {
                                $featured_image = wp_get_attachment_image_src(get_post_thumbnail_id($related_id), 'full');
                                // Fetch reviews
                                $comments = get_comments(['post_id' => $related_id, 'type' => 'review']);
                                $total_reviews = count($comments);

                                // Calculate total ratings
                                $total_rating = 0;
                                foreach ($comments as $comment) {
                                    $rating = get_comment_meta($comment->comment_ID, 'rating', true);
                                    if ($rating) {
                                        $total_rating += (int)$rating;
                                    }
                                }
                                // Fetch product URL
                                $product_url = get_permalink($related_id);

                                // Fetch assigned categories
                                $categories = get_the_terms($related_id, 'product_cat');
                                $category_names = [];
                                if (!empty($categories) && !is_wp_error($categories)) {
                                    foreach ($categories as $category) {
                                        $category_names[] = $category->name;
                                    }
                                }
                                // Fetch price details
                                $price_data = [];
                                if ($product->is_type('variable')) {
                                    // Get price range for variable products
                                    $price_data['min_price'] = $product->get_variation_price('min');
                                    $price_data['max_price'] = $product->get_variation_price('max');
                                } else {
                                    // Get regular and sale price for simple products
                                    $price_data['regular_price'] = $product->get_regular_price();
                                    $price_data['sale_price'] = $product->get_sale_price();
                                }
                                $sales_count = self::retro_sold_counter($related_id);

                                $attributes_list = self::get_product_attributes_array($related_id);
                                $enhanced_data[] = [
                                    'id'             => $related_id,
                                    'slug'           => $product->get_slug(),
                                    'product_type'   => $product->get_type(),
                                    'name'           => $product->get_name(),
                                    'price'          => $price_data,
                                    'description'    => $product->get_short_description(),
                                    'product_url'    => $product_url,
                                    'categories'     => $category_names,
                                    'featured_image' => $featured_image ? $featured_image[0] : null,
                                    'total_reviews'  => $total_reviews,
                                    'total_rating'   => $total_reviews > 0 ? $total_rating : null, // Avoid division if no reviews
                                    'sold_this_month' => $sales_count,
                                    'attributes' => $attributes_list
                                ];
                            } else {
                                // If product is not found
                                $related_id = apply_filters('wpml_object_id', $related_id, 'post', true, $lang);

                                $post_type = get_post_type($related_id);
                                if ($post_type == 'post') {
                                    $slug = get_post_field('post_name', $related_id);

                                    $enhanced_data[] = [
                                        'id'             => $related_id,
                                        'slug' => $slug,
                                        'title' => get_the_title($related_id),
                                        'url' => get_the_permalink($related_id),
                                        'featured_image' => get_the_post_thumbnail_url($related_id, 'full'),
                                        'excerpt' => get_the_excerpt($related_id),
                                    ];
                                }
                            }
                        }
                        $fields[$field_key] = $enhanced_data;
                    } else {
                        // Recursively process nested fields (e.g., group fields)
                        $fields[$field_key] = self::enhance_acf_fields($field_value, $lang);
                    }
                }
            }
            return $fields;
        }
        public static function get_products_by_ids($product_ids)
        {
            global $product;

            $related_id = $product_ids;
            // Include the product loop template
            $product = wc_get_product($product_ids);
            if ($product) {
                $featured_image = wp_get_attachment_image_src(get_post_thumbnail_id($related_id), 'full');
                // Fetch reviews
                $comments = get_comments(['post_id' => $related_id, 'type' => 'review']);
                $total_reviews = count($comments);

                // Calculate total ratings
                $total_rating = 0;
                foreach ($comments as $comment) {
                    $rating = get_comment_meta($comment->comment_ID, 'rating', true);
                    if ($rating) {
                        $total_rating += (int)$rating;
                    }
                }
                // Fetch product URL
                $product_url = get_permalink($related_id);

                // Fetch assigned categories
                $categories = get_the_terms($related_id, 'product_cat');
                $category_names = [];
                if (!empty($categories) && !is_wp_error($categories)) {
                    foreach ($categories as $category) {
                        $category_names[] = $category->name;
                    }
                }
                // Fetch price details
                $price_data = [];
                if ($product->is_type('variable')) {
                    // Get price range for variable products
                    $price_data['min_price'] = $product->get_variation_price('min');
                    $price_data['max_price'] = $product->get_variation_price('max');
                } else {
                    // Get regular and sale price for simple products
                    $price_data['regular_price'] = $product->get_regular_price();
                    $price_data['sale_price'] = $product->get_sale_price();
                }
                $attributes_list = self::get_product_attributes_array($product_ids);
                $sold_this_month = self::retro_sold_counter($product_ids);

                $product_data = [
                    'id'             => $product_ids,
                    'slug'           => $product->get_slug(),
                    'product_type'   => $product->get_type(),
                    'name'           => $product->get_name(),
                    'on_sale'     => $product->is_on_sale(),
                    'price'          => $price_data,
                    'description'    => $product->get_description(),
                    'product_url'    => $product_url,
                    'categories'     => $category_names,
                    'featured_image' => $featured_image ? $featured_image[0] : null,
                    'total_reviews'  => $total_reviews,
                    'total_rating'   => $total_reviews > 0 ? $total_rating : null, // Avoid division if no reviews
                    'stock_quantity' => $product->get_stock_quantity(),
                    'attributes' => $attributes_list,
                    'sold_this_month' => $sold_this_month

                ];
                return $product_data;
            }
            return [];
        }
        public static function get_detailed_products_by_ids($product_ids)
        {
            global $product;

            $related_id = $product_ids;
            $product = wc_get_product($product_ids);

            if ($product) {
                $featured_image = wp_get_attachment_image_src(get_post_thumbnail_id($related_id), 'full');

                // Fetch reviews
                $comments = get_comments(['post_id' => $related_id, 'type' => 'review']);
                $total_reviews = count($comments);

                // Calculate total ratings
                $total_rating = 0;
                foreach ($comments as $comment) {
                    $rating = get_comment_meta($comment->comment_ID, 'rating', true);
                    if ($rating) {
                        $total_rating += (int)$rating;
                    }
                }

                // Fetch product URL
                $product_url = get_permalink($related_id);

                // Fetch assigned categories
                $categories = get_the_terms($related_id, 'product_cat');
                $category_names = [];
                if (!empty($categories) && !is_wp_error($categories)) {
                    foreach ($categories as $category) {
                        $category_names[] = $category->name;
                    }
                }

                // Fetch price details
                $price_data = [];
                $variation_details = [];

                if ($product->is_type('variable')) {
                    // Get price range for variable products
                    $price_data['min_price'] = $product->get_variation_price('min');
                    $price_data['max_price'] = $product->get_variation_price('max');

                    // Get all variation objects
                    $variation_ids = $product->get_children();
                    foreach ($variation_ids as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        if ($variation && $variation->exists()) {
                            $variation_details[] = [
                                'id'              => $variation->get_id(),
                                'attributes'      => $variation->get_attributes(),
                                'regular_price'   => $variation->get_regular_price(),
                                'sale_price'      => $variation->get_sale_price(),
                                'current_price'   => $variation->get_price(),
                                'stock_quantity'  => $variation->get_stock_quantity(),
                                'stock_status'    => $variation->get_stock_status(),
                                'managing_stock'  => $variation->managing_stock(),
                                'backorders'      => $variation->get_backorders(),
                                'sku'             => $variation->get_sku(),
                                'weight'          => $variation->get_weight(),
                                'dimensions'      => [
                                    'length' => $variation->get_length(),
                                    'width'  => $variation->get_width(),
                                    'height' => $variation->get_height(),
                                ],
                                'parent_id'       => $variation->get_parent_id(),
                            ];
                        }
                    }
                } else {
                    // Get regular and sale price for simple products
                    $price_data['regular_price'] = $product->get_regular_price();
                    $price_data['sale_price'] = $product->get_sale_price();
                }

                $attributes_list = self::get_product_attributes_array($product_ids);
                $sold_this_month = self::retro_sold_counter($product_ids);

                $connected_products = get_post_meta($product_ids, 'connected_products', true);
                $add_a_game = get_post_meta($product_ids, 'add_a_game', true);

                $product_data = [
                    'id'                 => $product_ids,
                    'slug'               => $product->get_slug(),
                    'product_type'       => $product->get_type(),
                    'name'               => $product->get_name(),
                    'price'              => $price_data,
                    'description'        => $product->get_description(),
                    'product_url'        => $product_url,
                    'categories'         => $category_names,
                    'featured_image'     => $featured_image ? $featured_image[0] : null,
                    'total_reviews'      => $total_reviews,
                    'total_rating'       => $total_reviews > 0 ? $total_rating : null,
                    'stock_quantity'     => $product->get_stock_quantity(),
                    'attributes'         => $attributes_list,
                    'sold_this_month'    => $sold_this_month,
                    'connected_products' => $connected_products,
                    'add_a_game'         => $add_a_game,
                ];

                if (!empty($variation_details)) {
                    $product_data['variations'] = $variation_details;
                }

                return $product_data;
            }

            return [];
        }


        /** get the categories details by id */

        public static function retrovgame_get_category_details_by_id(WP_REST_Request $request)
        {
            $categoryid = $request->get_param('category_id');
            $category_slug = $request->get_param('category_slug');

            if (empty($categoryid) && empty($category_slug)) {
                return new WP_Error('Invalid category', array('status' => 500));
            }

            if (!empty($category_slug)) {
                $categoryid = get_term_by('slug', $category_slug, 'product_cat')->term_id;
            }

            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);

            if (empty($categoryid) ||  empty(get_the_category_by_ID($categoryid))) {
                return new WP_Error('Invalid category', array('status' => 500));
            }
            $original_id = apply_filters('wpml_object_id', $categoryid, 'product_cat', true, $lang);

            $category_info = self::get_woocommerce_category_details($original_id);

            return new WP_REST_Response([
                'success'      => true,
                'category_info'     => $category_info,
            ], 200);
        }

        public static function get_woocommerce_category_details($category_id)
        {
            // Get the main category
            $category = get_term($category_id, 'product_cat');

            if (!$category || is_wp_error($category)) {
                return null;
            }

            // Get category thumbnail
            $thumbnail_id = get_term_meta($category_id, 'thumbnail_id', true);
            $thumbnail_url = $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : null;

            // Get category page URL
            $category_url = get_term_link($category);
            $acf_fields = get_fields("term_{$category_id}");

            // Prepare category details
            $category_details = [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'count' => $category->count,
                'featured_image' => $thumbnail_url,
                'url' => $category_url,
                'acf_fields' => $acf_fields,
                'parent_id' => $category->parent,
                'children' => []
            ];

            // Get child categories
            $child_categories = get_terms([
                'taxonomy' => 'product_cat',
                'parent' => $category_id,
                'hide_empty' => false
            ]);

            // Process child categories
            foreach ($child_categories as $child) {
                // Get child category thumbnail
                $child_thumbnail_id = get_term_meta($child->term_id, 'thumbnail_id', true);
                $child_thumbnail_url = $child_thumbnail_id ? wp_get_attachment_url($child_thumbnail_id) : null;

                // Get child category URL
                $child_url = get_term_link($child);
                $child_acf_fields = get_fields("term_{$child->term_id}");

                $category_details['children'][] = [
                    'id' => $child->term_id,
                    'name' => $child->name,
                    'slug' => $child->slug,
                    'description' => $child->description,
                    'count' => $child->count,
                    'featured_image' => $child_thumbnail_url,
                    'url' => $child_url,
                    'parent_id' => $child->parent,
                    'acf_fields' => $child_acf_fields

                ];
            }

            return $category_details;
        }

        // Example usage


        /*** add to cart functionality */

        public static function retrovgame_add_to_cart(WP_REST_Request $request)
        {
            // Check if WooCommerce is active
            if (!class_exists('WooCommerce')) {
                return new WP_Error('woocommerce_required', 'WooCommerce is not active', array('status' => 500));
            }
            global $woocommerce;

            // Get user/session parameters first
            $user_id = $request->get_param('user_id');
            // $session_id = $request->get_param('session_id');
            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);

            // Handle user authentication if user_id is provided
            if ($user_id) {
                wp_set_current_user($user_id);
                $user = get_user_by('id', $user_id);
                if (!$user) {
                    return new WP_Error('invalid_user', 'Invalid user ID', array('status' => 400));
                }
            }

            // Initialize WC session
            if (!WC()->session) {
                $session_handler = new WC_Session_Handler();
                // Create new session
                WC()->session = $session_handler;
                WC()->session->init();
            }

            // Ensure cart is initialized
            if (null === WC()->cart) {
                wc_load_cart();
            }



            // Get other parameters from the request
            $product_id = $request->get_param('product_id');
            $quantity = $request->get_param('quantity');
            $variation_attributes = $request->get_param('variation');

            // Validate product ID and quantity
            if (empty($product_id) || !is_numeric($product_id)) {
                return new WP_Error('invalid_product_id', 'Invalid product ID', array('status' => 400));
            }

            if (empty($quantity) || !is_numeric($quantity) || $quantity <= 0) {
                return new WP_Error('invalid_quantity', 'Invalid quantity', array('status' => 400));
            }

            try {
                // Get the product
                $product = wc_get_product($product_id);
                if (!$product) {
                    return new WP_Error('invalid_product', 'Product not found', array('status' => 400));
                }

                $variation_id = null;
                $cleaned_attributes = array();
                $found_cart_item_key = null;
                $new_quantity = $quantity;

                // Handle variable products
                if ($product->is_type('variable')) {
                    if (empty($variation_attributes)) {
                        return new WP_Error(
                            'missing_variation',
                            'Variation attributes are required for variable products',
                            array('status' => 400)
                        );
                    }

                    // Standardize variation attributes
                    foreach ($variation_attributes as $attribute_key => $attribute_value) {
                        $attribute_key = sanitize_title($attribute_key);
                        if (strpos($attribute_key, 'attribute_') !== 0) {
                            $attribute_key = 'attribute_' . $attribute_key;
                        }
                        $cleaned_attributes[$attribute_key] = sanitize_text_field($attribute_value);
                    }

                    // Find matching variation ID
                    $data_store = WC_Data_Store::load('product');
                    $variation_id = $data_store->find_matching_product_variation($product, $cleaned_attributes);

                    if (!$variation_id) {
                        return new WP_Error(
                            'no_matching_variation',
                            'No matching variation found for the provided attributes',
                            array('status' => 400)
                        );
                    }
                }

                // Check if the item already exists in cart
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    if ($cart_item['product_id'] == $product_id) {
                        // For simple products
                        if (!$product->is_type('variable')) {
                            $found_cart_item_key = $cart_item_key;
                            $new_quantity += $cart_item['quantity'];
                            break;
                        }
                        // For variable products, check if variation matches
                        elseif ($variation_id && $cart_item['variation_id'] == $variation_id) {
                            // Check if all attributes match
                            $attributes_match = true;
                            foreach ($cleaned_attributes as $key => $value) {
                                if (!isset($cart_item['variation'][$key]) || $cart_item['variation'][$key] !== $value) {
                                    $attributes_match = false;
                                    break;
                                }
                            }
                            if ($attributes_match) {
                                $found_cart_item_key = $cart_item_key;
                                $new_quantity += $cart_item['quantity'];
                                break;
                            }
                        }
                    }
                }

                // Check stock availability for the new quantity
                $product_with_stock = $variation_id ? wc_get_product($variation_id) : $product;
                if (!$product_with_stock->has_enough_stock($new_quantity)) {
                    return new WP_Error(
                        'insufficient_stock',
                        'Not enough stock available for the requested quantity',
                        array('status' => 400)
                    );
                }

                if ($found_cart_item_key) {
                    // Update existing cart item
                    WC()->cart->set_quantity($found_cart_item_key, $new_quantity);
                    $message = 'Cart item quantity updated successfully';
                } else {
                    // Add new item to cart
                    $added = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $cleaned_attributes);
                    if (!$added) {
                        return new WP_Error(
                            'cart_error',
                            'Failed to add the product to cart',
                            array('status' => 500)
                        );
                    }
                    $message = 'Product added to cart successfully';
                }

                // Calculate totals
                WC()->cart->calculate_totals();

                // Explicitly save the session data
                WC()->session->save_data();



                // Get cart items for response
                $cart_items = array();
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    $cart_items[] = array(
                        'key' => $cart_item_key,
                        'product_id' => $cart_item['product_id'],
                        'variation_id' => $cart_item['variation_id'],
                        'quantity' => $cart_item['quantity'],
                        'variation' => $cart_item['variation'],
                        'line_total' => $cart_item['line_total']
                    );
                }

                // Get current session ID

                // Prepare response
                $response_data = array(
                    'success' => true,
                    'message' => $message,
                    'cart_count' => WC()->cart->get_cart_contents_count(),
                    'cart_total' => $woocommerce->cart->total,
                    'cart_items' => $cart_items,
                );

                // Add variation data if applicable
                if ($variation_id && !empty($cleaned_attributes)) {
                    $response_data['product_data']['variation_id'] = $variation_id;
                    $response_data['product_data']['variation'] = $cleaned_attributes;
                }

                // Add user data if applicable
                if ($user_id) {
                    $response_data['user_id'] = $user_id;
                }

                return new WP_REST_Response($response_data, 200);
            } catch (Exception $e) {
                return new WP_Error(
                    'cart_error',
                    $e->getMessage(),
                    array('status' => 500)
                );
            }
        }

        /*** add to cart functionality */

        public static function retrovgame_get_product_variation_id(WP_REST_Request $request)
        {
            // Check if WooCommerce is active
            if (!class_exists('WooCommerce')) {
                return new WP_Error('woocommerce_required', 'WooCommerce is not active', array('status' => 500));
            }





            // Get other parameters from the request
            $product_id = $request->get_param('product_id');
            $variation_attributes = $request->get_param('variation');

            // Validate product ID and quantity
            if (empty($product_id) || !is_numeric($product_id)) {
                return new WP_Error('invalid_product_id', 'Invalid product ID', array('status' => 400));
            }

            if (!is_array($variation_attributes)) {
                return new WP_Error('invalid_variation', 'Invalid variation attributes', array('status' => 400));
            }

            // Get the product
            $product = wc_get_product($product_id);
            if (!$product) {
                return new WP_Error('invalid_product', 'Product not found', array('status' => 400));
            }

            $variation_id = null;
            $cleaned_attributes = array();
            $found_cart_item_key = null;

            // Handle variable products
            if ($product->is_type('variable')) {
                if (empty($variation_attributes)) {
                    return new WP_Error(
                        'missing_variation',
                        'Variation attributes are required for variable products',
                        array('status' => 400)
                    );
                }

                // Standardize variation attributes
                foreach ($variation_attributes as $attribute_key => $attribute_value) {
                    $attribute_key = sanitize_title($attribute_key);
                    if (strpos($attribute_key, 'attribute_') !== 0) {
                        $attribute_key = 'attribute_' . $attribute_key;
                    }
                    $cleaned_attributes[$attribute_key] = sanitize_text_field($attribute_value);
                }

                // Find matching variation ID
                $data_store = WC_Data_Store::load('product');
                $variation_id = $data_store->find_matching_product_variation($product, $cleaned_attributes);

                if (!$variation_id) {
                    return new WP_Error(
                        'no_matching_variation',
                        'No matching variation found for the provided attributes',
                        array('status' => 400)
                    );
                }
            } else {
                return new WP_Error(
                    'Product is not a variable product',
                    array('status' => 400)
                );
            }
            // Get current session ID
            $variable_product = wc_get_product($variation_id);

            $price = [];

            $price['regular_price'] = $variable_product->get_regular_price();
            $price['sale_price'] = $variable_product->get_sale_price();

            //$regular_price = $variable_product->get_regular_price();
            //$sale_price = $variable_product->get_sale_price();
            // $price = $variable_product->get_price();
            // Prepare response
            $response_data = array(
                'success' => true,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'price' => $price
            );


            return new WP_REST_Response($response_data, 200);
        }

        /**
         * coupon validator
         */

        public static function retrovgame_validate_coupon(WP_REST_Request $request)
        {
            $coupon_code = $request->get_param('coupon_code');

            if (empty($coupon_code) || !is_string($coupon_code)) {
                return new WP_Error('invalid_coupon', 'Coupon code is required', ['status' => 400]);
            }

            // Fetch the coupon by code
            $coupon = new WC_Coupon($coupon_code);

            // Check if the coupon exists
            if (!$coupon->get_id()) {
                return new WP_Error('invalid_coupon', 'Coupon not found', ['status' => 404]);
            }

            // Gather coupon details
            $coupon_details = self::get_coupon_data_by_code($coupon_code);

            // Prepare response
            $response_data = array(
                'success' => true,
                'coupon_details' => $coupon_details,
            );


            return new WP_REST_Response($response_data, 200);
        }

        public static function retrovgame_get_cart_data(WP_REST_Request $request)
        {
            // Check if WooCommerce is active
            if (!class_exists('WooCommerce')) {
                return new WP_Error('woocommerce_required', 'WooCommerce is not active', array('status' => 500));
            }

            // Get user/session parameters
            $user_id = $request->get_param('user_id');
            $session_id = $request->get_param('session_id');

            // Validate that at least one identifier is provided
            if (empty($user_id) && empty($session_id)) {
                return new WP_Error(
                    'missing_identifier',
                    'Either user_id or session_id must be provided',
                    array('status' => 400)
                );
            }

            try {
                // Handle user authentication if user_id is provided
                if ($user_id) {
                    wp_set_current_user($user_id);
                    $user = get_user_by('id', $user_id);
                    if (!$user) {
                        return new WP_Error('invalid_user', 'Invalid user ID', array('status' => 400));
                    }
                }

                // Initialize WC session
                if (!WC()->session) {
                    $session_handler = new WC_Session_Handler();

                    if ($session_id) {
                        // Load existing session data
                        $session = $session_handler->get_session($session_id);

                        if ($session) {
                            // Initialize the session handler
                            WC()->session = $session_handler;
                            WC()->session->set_customer_id($session_id);

                            // Set session cookie
                            if (!headers_sent()) {
                                setcookie(
                                    'wc_session_cookie_' . COOKIEHASH,
                                    $session_id,
                                    time() + 86400,
                                    COOKIEPATH,
                                    COOKIE_DOMAIN
                                );
                                $_COOKIE['wc_session_cookie_' . COOKIEHASH] = $session_id;
                            }
                        }
                    } else {
                        // Create new session
                        WC()->session = $session_handler;
                        WC()->session->init();
                    }
                }

                // Ensure cart is initialized
                if (null === WC()->cart) {
                    wc_load_cart();
                }

                // If using session ID, load the existing cart data
                if ($session_id) {
                    $session = WC()->session->get_session($session_id);

                    if ($session) {
                        // Get existing cart data
                        $existing_cart = isset($session['cart']) ? maybe_unserialize($session['cart']) : '';

                        if (!empty($existing_cart)) {
                            // Clear current cart first
                            WC()->cart->empty_cart(true);

                            // Set the existing cart data to the session
                            WC()->session->set('cart', $existing_cart);

                            // Load the cart from session
                            WC()->cart->get_cart_from_session();
                        }
                    }
                }

                // Get cart items
                $cart_items = array();
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    $product = wc_get_product($cart_item['product_id']);
                    if (!$product) {
                        continue;
                    }

                    $item_data = array(
                        'key' => $cart_item_key,
                        'product_id' => $cart_item['product_id'],
                        'product_name' => $product->get_name(),
                        'quantity' => $cart_item['quantity'],
                        'line_total' => wc_price($cart_item['line_total']),
                        'line_total_raw' => $cart_item['line_total'],
                        'product_price' => wc_price($product->get_price()),
                        'product_price_raw' => $product->get_price(),
                        'product_image' => wp_get_attachment_url($product->get_image_id()),
                        'product_url' => get_permalink($cart_item['product_id'])
                    );

                    // Add variation data if it exists
                    if (!empty($cart_item['variation_id'])) {
                        $variation = wc_get_product($cart_item['variation_id']);
                        if ($variation) {
                            $item_data['variation_id'] = $cart_item['variation_id'];
                            $item_data['variation'] = $cart_item['variation'];
                            $item_data['variation_name'] = $variation->get_name();
                        }
                    }

                    $cart_items[] = $item_data;
                }

                // Calculate cart totals
                WC()->cart->calculate_totals();

                // Get coupon data if any
                $applied_coupons = array();
                foreach (WC()->cart->get_applied_coupons() as $coupon_code) {
                    $coupon = new WC_Coupon($coupon_code);
                    $applied_coupons[] = array(
                        'code' => $coupon_code,
                        'discount_amount' => wc_price($coupon->get_amount()),
                        'discount_type' => $coupon->get_discount_type()
                    );
                }

                // Get current session ID
                $current_session_id = $session_id ?: WC()->session->get_customer_id();

                // Prepare response
                $response_data = array(
                    'success' => true,
                    'cart_count' => WC()->cart->get_cart_contents_count(),
                    'cart_total' => strip_tags(WC()->cart->get_cart_total()),
                    'cart_total_raw' => WC()->cart->get_total('raw'),
                    'cart_subtotal' => strip_tags(WC()->cart->get_cart_subtotal()),
                    'cart_subtotal_raw' => WC()->cart->get_subtotal(),
                    'cart_tax_total' => wc_price(WC()->cart->get_total_tax()),
                    'cart_tax_total_raw' => WC()->cart->get_total_tax(),
                    'cart_discount_total' => wc_price(WC()->cart->get_discount_total()),
                    'cart_discount_total_raw' => WC()->cart->get_discount_total(),
                    'cart_items' => $cart_items,
                    'applied_coupons' => $applied_coupons,
                    'session_id' => $current_session_id
                );

                // Add user data if applicable
                if ($user_id) {
                    $response_data['user_id'] = $user_id;
                }

                return new WP_REST_Response($response_data, 200);
            } catch (Exception $e) {
                return new WP_Error(
                    'cart_error',
                    $e->getMessage(),
                    array('status' => 500)
                );
            }
        }

        public static function retrovgame_get_faqs(WP_REST_Request $request)
        {
            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);

            // Switch language using WPML if lang is provided
            if ($lang && function_exists('do_action')) {
                do_action('wpml_switch_language', $lang);
            }

            // Get all FAQ posts with language argument
            $faq_posts = get_posts([
                'post_type'      => 'faq',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'suppress_filters' => false, // Important: Allow WPML to filter the results
            ]);

            $current_language = apply_filters('wpml_current_language', null);
            $faqs_data = [];

            // Loop through each FAQ post and get the ACF fields
            foreach ($faq_posts as $faq) {
                // Make sure we're getting ACF fields in the correct language
                $acf_fields = get_fields($faq->ID);

                // Skip if no ACF fields and add debug info
                if (empty($acf_fields)) {
                    // Uncomment for debugging
                    // error_log("No ACF fields found for FAQ ID: {$faq->ID} in language: {$current_language}");
                }

                $faq_data = [
                    'id'         => $faq->ID,
                    'lang'       => $lang,
                    'current'    => $current_language,
                    'title'      => $faq->post_title,
                    'acf_fields' => $acf_fields ?: [], // Ensure it's at least an empty array
                ];

                // Add the FAQ data to the result array
                $faqs_data[] = $faq_data;
            }

            if (count($faqs_data) > 0) {
                return new WP_REST_Response([
                    "Success" => true,
                    "data" => $faqs_data
                ]);
            } else {
                return new WP_REST_Response([
                    "Success" => false,
                    "data" => "No FAQs found for language: {$current_language}"
                ]);
            }
        }

        // get the single post details from the id 
        public static function retrovgame_get_singlepost_details_by_id(WP_REST_Request $request)
        {
            $post_id = $request->get_param('id');
            $post_slug = $request->get_param('slug');
            $language_code = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);

            // Fetch post by ID or slug
            if ($post_id) {
                $post = get_post($post_id);
            } elseif ($post_slug) {
                $post = get_page_by_path($post_slug, OBJECT, 'post');
            } else {
                return new WP_Error('missing_param', 'Post ID or Slug is required', ['status' => 400]);
            }

            if (!$post) {
                return new WP_Error('no_post', 'Invalid Post ID or Slug', ['status' => 404]);
            }

            // Get fully rendered post content
            $content = apply_filters('the_content', $post->post_content);

            // Get theme styles and Gutenberg styles
            $styles = '';

            // Get theme's main stylesheet
            $theme_stylesheet = get_stylesheet_directory() . '/style.css';
            if (file_exists($theme_stylesheet)) {
                $styles .= file_get_contents($theme_stylesheet);
            }

            // Get Gutenberg block styles
            $gutenberg_css = includes_url('css/dist/block-library/style.min.css'); // Core styles
            $styles .= file_get_contents(ABSPATH . wp_parse_url($gutenberg_css, PHP_URL_PATH));

            // Get ACF fields
            $acf_fields = function_exists('get_fields') ? get_fields($post->ID) : [];
            $acf_fields = self::enhance_acf_fields($acf_fields, $language_code);

            // Get related posts (based on the same category)
            $related_posts = [];
            $categories = get_the_category($post->ID);
            if (!empty($categories)) {
                $category_ids = wp_list_pluck($categories, 'term_id');
                $related_posts_query = new WP_Query(array(
                    'post_type'      => 'post',
                    'posts_per_page' => 5, // Number of related posts to fetch
                    'post__not_in'   => array($post->ID), // Exclude the current post
                    'category__in'   => $category_ids, // Posts in the same category
                    'orderby'        => 'rand', // Random order
                ));

                if ($related_posts_query->have_posts()) {
                    while ($related_posts_query->have_posts()) {
                        $related_posts_query->the_post();
                        $related_post_id = get_the_ID();

                        // Get author details
                        $author_id = get_the_author_meta('ID');
                        $author_name = get_the_author_meta('display_name', $author_id);
                        $author_profile_image = get_avatar_url($author_id, ['size' => 96]); // Adjust size as needed

                        // Get ACF field value (max_read_time)
                        $max_read_time = function_exists('get_field') ? get_field('max_read_time', $related_post_id) : null;

                        // Get thumbnail URL
                        $thumbnail_url = get_the_post_thumbnail_url($related_post_id, 'medium'); // Adjust size as needed

                        $related_posts[] = array(
                            'id' => $related_post_id,
                            'title' => get_the_title(),
                            'permalink' => get_permalink(),
                            'excerpt' => get_the_excerpt(),
                            'thumbnail' => $thumbnail_url,
                            'author' => array(
                                'name' => $author_name,
                                'profile_image' => $author_profile_image,
                            ),
                            'max_read_time' => $max_read_time, // ACF field value
                        );
                    }
                    wp_reset_postdata(); // Reset the global post data
                }
            }

            // get author details 
            $author_id = $post->post_author;
            $author_name = get_the_author_meta('display_name', $author_id);
            $author_profile_image = get_avatar_url($author_id);


            return new WP_REST_Response(array(
                'id'        => $post->ID,
                'title'     => get_the_title($post->ID),
                'slug' => get_post_field('post_name', $post->ID),
                'thumbnail' => get_the_post_thumbnail_url($post->ID, 'full'),
                'published_date' => get_the_date('Y-m-d H:i:s', $post->ID),
                'author_details' => array(
                    'id' => $author_id,
                    'name' => $author_name,
                    'profile_image' => $author_profile_image
                ),
                'permalink' => get_permalink($post->ID),
                'acf_data'  => $acf_fields,
                'related_posts' => $related_posts, // Include related posts in the response
            ), 200);
        }



        //get website contact details
        public static function retrovgame_get_website_contact_details(WP_REST_Request $request)
        {
            if (function_exists('get_fields')) {
                $options = get_field('contact_details', 'option'); // Fetch ACF fields from the specific options page

                return new WP_REST_Response(array(
                    "Success" => true,
                    "data" => $options
                ), 200);
            }
            return new WP_Error('acf_not_found', 'ACF is not active or available', array('status' => 404));
        }

        public static function retrovgame_cart_exclusive_offer(WP_REST_Request $request)
        {
            $language_code = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);

            if (function_exists('get_fields')) {
                $acf_fields = get_field('cart_discount_data', 'option'); // Fetch ACF fields from the specific options page

                $acf_fields = self::enhance_acf_fields($acf_fields, $language_code);


                return new WP_REST_Response(array(
                    "Success" => true,
                    "data" => $acf_fields
                ), 200);
            }
            return new WP_Error('acf_not_found', 'ACF is not active or available', array('status' => 404));
        }

        // api to fetch the product type terms
        public static function retrovgame_get_product_type_terms(WP_REST_Request $request)
        {
            // Get language from request
            $lang = $request->get_param('lang');

            // Switch language using WPML if lang is provided
            if ($lang && function_exists('do_action')) {
                do_action('wpml_switch_language', $lang);
            }

            $terms = get_terms(array(
                'taxonomy'   => 'pa_product-type',
                'hide_empty' => false,
            ));

            if (is_wp_error($terms)) {
                return new WP_Error('no_terms', 'No terms found', array('status' => 404));
            }

            $response = array();

            foreach ($terms as $term) {
                $acf_fields = function_exists('get_fields') ? get_fields($term) : array();

                $response[] = array(
                    'id'          => $term->term_id,
                    'name'        => $term->name,
                    'slug'        => $term->slug,
                    'description' => $term->description,
                    'acf'         => $acf_fields,
                );
            }

            return new WP_REST_Response(array(
                "Success" => true,
                "data" => $response
            ), 200);
        }


        // api CB to return variation details
        public static function retrovgame_get_product_variations(WP_REST_Request $request)
        {
            $product_id = $request->get_param('product_id');
            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);
            $product_id = apply_filters('wpml_object_id', $product_id, 'product', false, $lang);

            $product = wc_get_product($product_id);
            $title = get_the_title($product_id);

            if (!$product || !$product->is_type('variable')) {
                return new WP_Error('invalid_product', __('Invalid or non-variable product.', 'shahwptheme'), ['status' => 404]);
            }

            $variations = [];
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    $variations[] = [
                        'id'        => $variation_id,
                        'title' => $title,
                        'price'     => $variation->get_price(),
                        'attributes' => $variation->get_attributes(),
                    ];
                }
            }
            return new WP_REST_Response(array(
                "Success" => true,
                "data" => $variations
            ), 200);
        }


        public static function get_product_attributes_array($product_id)
        {
            $product = wc_get_product($product_id);
            $attributes_array = [];

            if ($product) {
                $attributes = $product->get_attributes();

                foreach ($attributes as $attribute) {
                    if ($attribute->is_taxonomy()) {
                        // Get taxonomy-based attributes
                        $attribute_values = wc_get_product_terms($product_id, $attribute->get_name(), ['fields' => 'names']);
                    } else {
                        // Get custom product attributes (stored as an array)
                        $attribute_values = $attribute->get_options();
                        if (!is_array($attribute_values)) {
                            $attribute_values = [$attribute_values]; // Ensure it's always an array
                        }
                    }

                    $attributes_array[$attribute->get_name()] = $attribute_values;
                }
            }

            return $attributes_array;
        }

        // recomendation system
        public static function retrovgame_get_recomended_products(WP_REST_Request $request)
        {
            $product_ids = $request->get_param('ids');
            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);

            if (empty($product_ids)) {
                return new WP_Error('invalid_product', 'No product IDs provided', array('status' => 400));
            }

            // Convert comma-separated values into an array and sanitize
            $product_ids = array_map('intval', explode(',', $product_ids));
            $product_ids = array_filter($product_ids); // Remove empty values

            if (empty($product_ids)) {
                return new WP_Error('invalid_product', 'Invalid product IDs', array('status' => 400));
            }

            $category_ids = [];

            // Loop through each product and get its categories
            foreach ($product_ids as $product_id) {
                if (! get_post_status($product_id)) {
                    continue; // Skip invalid product IDs
                }

                $terms = get_the_terms($product_id, 'product_cat');

                if (! empty($terms) && ! is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $category_ids[] = $term->term_id;
                    }
                }
            }

            $category_ids = array_unique($category_ids); // Remove duplicates

            if (empty($category_ids)) {
                return new WP_Error('no_category', 'No categories found for the given products', array('status' => 404));
            }

            // Query products with the same categories
            $args = array(
                'post_type'      => 'product',
                'posts_per_page' => 10,
                'post__not_in'   => $product_ids, // Exclude provided product IDs
                'tax_query'      => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => $category_ids,
                    ),
                ),
            );

            $query = new WP_Query($args);

            $products = [];

            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    global $product;
                    $product_id = get_the_ID();
                    $product_id = apply_filters('wpml_object_id', $product_id, 'product', true, $lang);
                    $product_info = self::get_products_by_ids($product_id);
                    $products[] = $product_info;
                }
            }

            wp_reset_postdata();

            return new WP_REST_Response(array(
                "Success" => true,
                "recommended_products" => $products
            ), 200);
        }

        // get the list of most selling products
        public static function retrovgame_get_best_seller_products(WP_REST_Request $request)
        {
            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);

            $args = array(
                'post_type'      => 'product',
                'posts_per_page' => 10, // Change the number of products if needed
                'meta_key'       => 'total_sales',
                'orderby'        => 'meta_value_num',
                'order'          => 'DESC',
            );

            $query = new WP_Query($args);

            $products = [];

            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    global $product;
                    $product_id = get_the_ID();
                    $product_id = apply_filters('wpml_object_id', $product_id, 'product', true, $lang);
                    $product_info = self::get_products_by_ids($product_id);
                    $products[] = $product_info;
                }
            }

            wp_reset_postdata();

            $seller_details =  [
                'success'      => true,
                'products'     => $products,
                'total_pages'  => $query->max_num_pages,
                'total_posts'  => $query->found_posts,
            ];

            return new WP_REST_Response($seller_details, 200);
        }

        // get term data
        public static function retrovgame_get_product_term_data(WP_REST_Request $request)
        {
            $term_id = intval($request->get_param('id'));
            $lang = $request->get_param('lang') ?: apply_filters('wpml_default_language', null);


            if (empty($term_id) || $term_id <= 0) {
                return new WP_Error('invalid_id', 'Invalid attribute term ID', array('status' => 400));
            }

            // Get term data
            $term = get_term($term_id);



            if (is_wp_error($term) || empty($term)) {
                return new WP_Error('not_found', 'Attribute term not found', array('status' => 404));
            }

            // Get ACF fields if ACF is installed
            $acf_fields = function_exists('get_fields') ? get_fields($term) : [];

            // Prepare response data
            $response = array(
                'term_id'     => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'taxonomy'    => $term->taxonomy,
                'description' => $term->description,
                'acf'         => $acf_fields, // Includes ACF fields
            );

            return rest_ensure_response($response);
        }

        // fetch the shipping method
        public static function retrovgame_get_shipping_methods(WP_REST_Request $request)
        {
            if (!class_exists('WC_Shipping_Zones')) {
                return new WP_Error('woocommerce_missing', __('WooCommerce is not installed or activated.', 'shahwptheme'), ['status' => 500]);
            }

            $zones = WC_Shipping_Zones::get_zones();
            $response = [];

            foreach ($zones as $zone) {
                $zone_name = $zone['zone_name'];
                $zone_id = $zone['zone_id'];
                $shipping_methods = $zone['shipping_methods'];

                $methods_data = [];
                $available_methods = [];

                foreach ($shipping_methods as $method) {
                    $method_id = $method->id;
                    $method_title = $method->get_title();
                    $method_enabled = $method->is_enabled();
                    $method_cost = isset($method->instance_settings['cost']) ? floatval($method->instance_settings['cost']) : 0;
                    $estimated_delivery = isset($method->instance_settings['estimated_delivery']) ? $method->instance_settings['estimated_delivery'] : 'N/A';

                    if ($method_enabled) {
                        $methods_data[] = [
                            'instance_id'  => $method->instance_id,
                            'id'           => $method->id, // General method type (e.g., flat_rate, free_shipping)
                            'title'        => $method->title,
                            'enabled'      => $method->enabled,
                            'zone_id'      => $zone['id'],
                            'zone_name'    => $zone['zone_name'],
                            'cost'         => isset($method->settings['cost']) ? $method->settings['cost'] : 0,
                            'tax_status'   => isset($method->settings['tax_status']) ? $method->settings['tax_status'] : '',
                            'estimated_delivery' => $estimated_delivery, // Example custom field
                        ];
                        $available_methods[] = $method_title;
                    }
                }

                $response[] = [
                    'zone_id'          => $zone_id,
                    'zone_name'        => $zone_name,
                    'available_methods' => $available_methods,
                    'methods'          => $methods_data,
                ];
            }

            return rest_ensure_response($response);
        }

        /**
         * Get shipping methods available for a specific country
         *
         * @param WP_REST_Request $request
         * @return WP_REST_Response
         */
        public static function retrovgame_get_shipping_methods_using_countrycode($request)
        {
            $country_code = strtoupper($request['country']);
            // Validate country code
            $countries = WC()->countries->get_countries();
            if (!isset($countries[$country_code])) {
                return new WP_REST_Response(array(
                    'status' => 'error',
                    'message' => 'Invalid country code'
                ), 400);
            }

            $shipping_methods = array();
            // Get all shipping zones
            $shipping_zones = WC_Shipping_Zones::get_zones();

            // Loop through each shipping zone
            foreach ($shipping_zones as $zone_data) {
                $zone = new WC_Shipping_Zone($zone_data['id']);
                $zone_locations = $zone->get_zone_locations();

                // Check if this zone applies to the requested country
                $country_in_zone = false;

                // If there are no locations, this is the "Everywhere" zone
                if (empty($zone_locations)) {
                    $country_in_zone = true;
                } else {
                    foreach ($zone_locations as $location) {
                        if ($location->type === 'country' && $location->code === $country_code) {
                            $country_in_zone = true;
                            break;
                        }

                        // Check continent locations using correct method
                        if ($location->type === 'continent') {
                            // Get the countries that belong to this continent
                            $continent_countries = self::get_countries_for_continent($location->code);
                            if (in_array($country_code, $continent_countries)) {
                                $country_in_zone = true;
                                break;
                            }
                        }
                    }
                }

                // If country is in this zone, add its shipping methods
                if ($country_in_zone) {
                    $methods = $zone->get_shipping_methods(true); // true = enabled methods only
                    foreach ($methods as $method) {
                        $shipping_methods[] = array(
                            'zone_id' => $zone_data['id'],
                            'zone_name' => $zone_data['zone_name'],
                            'method_id' => $method->id,
                            'instance_id' => $method->instance_id,
                            'method_title' => $method->get_title(),
                            'method_description' => $method->get_method_description(),
                            'settings' => self::get_method_settings($method),
                        );
                    }
                }
            }

            // Also check the "Rest of the World" zone (always applies unless overridden)
            $rest_of_world = new WC_Shipping_Zone(0);
            $worldwide_methods = $rest_of_world->get_shipping_methods(true);
            foreach ($worldwide_methods as $method) {
                $shipping_methods[] = array(
                    'zone_id' => 0,
                    'zone_name' => 'Rest of the World',
                    'method_id' => $method->id,
                    'instance_id' => $method->instance_id,
                    'method_title' => $method->get_title(),
                    'method_description' => $method->get_method_description(),
                    'settings' => self::get_method_settings($method),
                );
            }

            return new WP_REST_Response(array(
                'status' => 'success',
                'country' => $country_code,
                'country_name' => $countries[$country_code],
                'shipping_methods' => $shipping_methods
            ), 200);
        }

        /**
         * Get shipping methods available using method id
         *
         * @param method_id $method_id
         * @return method data
         */
        public static function get_shipping_methods_by_method_id($method_id)
        {
            if (! class_exists('WC_Shipping_Zones')) {
                return null;
            }

            $result = [];

            // Get all zones and append 'Rest of the World'
            $zones = WC_Shipping_Zones::get_zones();

            $zones[] = array(
                'id'              => 0,
                'zone_name'       => 'Rest of the World',
                'shipping_methods' => WC_Shipping_Zones::get_zone(0)->get_shipping_methods(),
            );

            foreach ($zones as $zone) {
                foreach ($zone['shipping_methods'] as $method) {
                    if ($method->id === $method_id) {
                        $estimated_delivery = isset($method->instance_settings['estimated_delivery']) ? $method->instance_settings['estimated_delivery'] : 'N/A';

                        $result[] = array(
                            'zone_id'            => (int) $zone['id'],
                            'zone_name'          => $zone['zone_name'],
                            'method_id'          => $method->id,
                            'instance_id'        => (int) $method->instance_id,
                            'method_title'       => $method->title,
                            'method_description' => $method->get_method_description(),
                            'settings'           => self::get_method_settings($method)

                        );
                    }
                }
            }

            return $result;
        }

        /**
         * Get countries that belong to a specific continent
         *
         * @param string $continent_code
         * @return array
         */
        public static function get_countries_for_continent($continent_code)
        {
            // Define mapping of continent codes to country codes
            $continents = array(
                'AF' => array('AO', 'BF', 'BI', 'BJ', 'BW', 'CD', 'CF', 'CG', 'CI', 'CM', 'CV', 'DJ', 'DZ', 'EG', 'EH', 'ER', 'ET', 'GA', 'GH', 'GM', 'GN', 'GQ', 'GW', 'KE', 'KM', 'LR', 'LS', 'LY', 'MA', 'MG', 'ML', 'MR', 'MU', 'MW', 'MZ', 'NA', 'NE', 'NG', 'RE', 'RW', 'SC', 'SD', 'SH', 'SL', 'SN', 'SO', 'SS', 'ST', 'SZ', 'TD', 'TG', 'TN', 'TZ', 'UG', 'YT', 'ZA', 'ZM', 'ZW'),
                'AS' => array('AE', 'AF', 'AM', 'AZ', 'BD', 'BH', 'BN', 'BT', 'CC', 'CN', 'CX', 'CY', 'GE', 'HK', 'ID', 'IL', 'IN', 'IO', 'IQ', 'IR', 'JO', 'JP', 'KG', 'KH', 'KP', 'KR', 'KW', 'KZ', 'LA', 'LB', 'LK', 'MM', 'MN', 'MO', 'MV', 'MY', 'NP', 'OM', 'PH', 'PK', 'PS', 'QA', 'SA', 'SG', 'SY', 'TH', 'TJ', 'TL', 'TM', 'TR', 'TW', 'UZ', 'VN', 'YE'),
                'EU' => array('AD', 'AL', 'AT', 'AX', 'BA', 'BE', 'BG', 'BY', 'CH', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FO', 'FR', 'GB', 'GG', 'GI', 'GR', 'HR', 'HU', 'IE', 'IM', 'IS', 'IT', 'JE', 'LI', 'LT', 'LU', 'LV', 'MC', 'MD', 'ME', 'MK', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'RS', 'RU', 'SE', 'SI', 'SJ', 'SK', 'SM', 'UA', 'VA', 'XK'),
                'NA' => array('AG', 'AI', 'AW', 'BB', 'BL', 'BM', 'BQ', 'BS', 'BZ', 'CA', 'CR', 'CU', 'CW', 'DM', 'DO', 'GD', 'GL', 'GP', 'GT', 'HN', 'HT', 'JM', 'KN', 'KY', 'LC', 'MF', 'MQ', 'MS', 'MX', 'NI', 'PA', 'PM', 'PR', 'SV', 'SX', 'TC', 'TT', 'US', 'VC', 'VG', 'VI'),
                'OC' => array('AS', 'AU', 'CK', 'FJ', 'FM', 'GU', 'KI', 'MH', 'MP', 'NC', 'NF', 'NR', 'NU', 'NZ', 'PF', 'PG', 'PN', 'PW', 'SB', 'TK', 'TO', 'TV', 'UM', 'VU', 'WF', 'WS'),
                'SA' => array('AR', 'BO', 'BR', 'CL', 'CO', 'EC', 'FK', 'GF', 'GY', 'PE', 'PY', 'SR', 'UY', 'VE'),
                'AN' => array('AQ', 'BV', 'GS', 'HM', 'TF')
            );

            return isset($continents[$continent_code]) ? $continents[$continent_code] : array();
        }

        /**
         * Extract relevant settings from shipping method
         *
         * @param WC_Shipping_Method $method
         * @return array
         */
        public static function get_method_settings($method)
        {
            $settings = array();
            // Common settings to extract
            $setting_keys = array(
                'title',
                'cost',
                'min_amount',
                'requires',
                'estimated_delivery', // Our custom field
            );
            foreach ($setting_keys as $key) {
                if (method_exists($method, 'get_option')) {
                    $value = $method->get_option($key);
                    if (!empty($value)) {
                        $settings[$key] = $value;
                    }
                }
            }
            return $settings;
        }

        public static function retrovgame_get_page_content(WP_REST_Request $request)
        {
            $page_id = $request['id'];

            $post = get_post($page_id);
            if (!$post || $post->post_type !== 'page') {
                return new WP_Error('not_found', 'Page not found', array('status' => 404));
            }

            // Get the fully processed HTML content
            $html_content = apply_filters('the_content', $post->post_content);

            // Get block editor styles (CSS)
            $block_css = self::custom_get_gutenberg_styles();
            $acf_fields = function_exists('get_fields') ? get_fields($post->ID) : [];

            return rest_ensure_response(array(
                'id'      => $post->ID,
                'title'   => get_the_title($post),
                'html'    => $html_content,
                'css'     => $block_css,
                'acf'     => $acf_fields
            ));
        }
        /**
         * Function to get the necessary CSS styles for Gutenberg blocks
         */
        public static function custom_get_gutenberg_styles()
        {
            ob_start();

            // Enqueue front-end styles for blocks
            wp_enqueue_style('wp-block-library'); // Core block styles
            wp_enqueue_style('wp-block-library-theme'); // Theme block styles
            wp_enqueue_style('global-styles'); // Global styles (if available)

            // Print styles to capture them
            wp_print_styles();

            return ob_get_clean();
        }
        // get shipping method details 
        public static function retrovgame_get_shipping_method_details(WP_REST_Request $request)
        {
            $instance_id = intval($request->get_param('instance_id'));

            // Get all shipping zones
            $zones = WC_Shipping_Zones::get_zones();
            $method_details = [];

            foreach ($zones as $zone) {
                foreach ($zone['shipping_methods'] as $method) {
                    if ($method->instance_id === $instance_id) {
                        $estimated_delivery = isset($method->instance_settings['estimated_delivery']) ? $method->instance_settings['estimated_delivery'] : 'N/A';

                        $method_details = [
                            'instance_id'  => $method->instance_id,
                            'id'           => $method->id, // General method type (e.g., flat_rate, free_shipping)
                            'title'        => $method->title,
                            'enabled'      => $method->enabled,
                            'zone_id'      => $zone['id'],
                            'zone_name'    => $zone['zone_name'],
                            'cost'         => isset($method->settings['cost']) ? $method->settings['cost'] : 0,
                            'tax_status'   => isset($method->settings['tax_status']) ? $method->settings['tax_status'] : '',
                            'estimated_delivery' => $estimated_delivery, // Example custom field
                        ];
                        break 2;
                    }
                }
            }

            if (empty($method_details)) {
                return new WP_Error('no_method', 'Shipping method not found', ['status' => 404]);
            }

            return rest_ensure_response($method_details);
        }
        //set exchange rates
        public static function retrovgame_set_exchange_rate(WP_REST_Request $request)
        {
            $rates = $request->get_json_params();

            // Validate input
            if (!is_array($rates) || empty($rates)) {
                return new WP_REST_Response(['error' => 'Invalid data. Expecting JSON with currency => value pairs.'], 400);
            }

            // Store in database as a single option
            update_option('custom_exchange_rates', $rates);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Exchange rates stored successfully.',
                'stored_data' => $rates
            ], 200);
        }
        // get exchange rate
        public static function retrovgame_get_exchange_rate(WP_REST_Request $request)
        {
            $rates = get_option('custom_exchange_rates');

            if (!$rates || !is_array($rates)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'No exchange rates found.'
                ], 404);
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => $rates
            ], 200);
        }
    }
}
