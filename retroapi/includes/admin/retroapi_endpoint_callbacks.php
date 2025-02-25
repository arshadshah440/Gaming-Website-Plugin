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

            // Validate input parameters
            if (empty($params['country']) || empty($params['state']) || empty($params['postcode'])) {
                return new WP_Error('missing_params', 'Country, state, and postal code are required.', ['status' => 400]);
            }

            $country = sanitize_text_field($params['country']);
            $state = sanitize_text_field($params['state']);
            $postcode = wc_normalize_postcode(wc_clean($params['postcode']));

            // Get tax details with proper tax class
            $tax_details = self::get_tax_details($country, $state, $postcode);

            // Get shipping costs
            $shipping_costs = self::get_shipping_costs($country, $state, $postcode);

            return [
                'success' => true,
                'tax_details' => $tax_details,
                'shipping_costs' => $shipping_costs,
            ];
        }

        public static function get_tax_details($country, $state, $postcode)
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

                // Get tax rate name
                $rate_name_query = $wpdb->prepare(
                    "SELECT name FROM {$wpdb->prefix}woocommerce_tax_rate_locations 
                    WHERE tax_rate_id = %d AND location_type = 'city' LIMIT 1",
                    $rate->tax_rate_id
                );
                $rate_name = $wpdb->get_var($rate_name_query);
                $postalcodes = self::get_postal_code_by_tax_rate_id($rate->tax_rate_id);

                if (in_array($postcode, $postalcodes)) {
                    $tax_details[] = [
                        'rate_id' => (int) $rate->tax_rate_id,
                        'name' => !empty($rate->tax_rate_name) ? $rate->tax_rate_name : 'Tax',
                        'rate' => (float) $rate->tax_rate,
                        'shipping' => (bool) $rate->tax_rate_shipping,
                        'compound' => (bool) $rate->tax_rate_compound,
                        'priority' => (int) $rate->tax_rate_priority,
                        'class' => !empty($rate->tax_rate_class) ? $rate->tax_rate_class : 'standard',
                        'country' => $rate->tax_rate_country,
                        'state' => $rate->tax_rate_state,
                        'postcode' => $postcode,
                        'city' => $rate_name ?: '',
                        'order' => (int) $rate->tax_rate_order
                    ];
                }
            }

            // If no rates found, return the base rate
            if (empty($tax_details)) {
                $base_country = WC()->countries->get_base_country();
                $base_state = WC()->countries->get_base_state();

                $query = $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates 
                    WHERE tax_rate_country = %s 
                    AND (tax_rate_state = %s OR tax_rate_state = '') 
                    ORDER BY tax_rate_priority ASC, tax_rate_order ASC LIMIT 1",
                    $base_country,
                    $base_state
                );

                $base_rate = $wpdb->get_row($query);

                if ($base_rate) {
                    $tax_details[] = [
                        'rate_id' => (int) $base_rate->tax_rate_id,
                        'name' => !empty($base_rate->tax_rate_name) ? $base_rate->tax_rate_name : 'Tax',
                        'rate' => (float) $base_rate->tax_rate,
                        'shipping' => (bool) $base_rate->tax_rate_shipping,
                        'compound' => (bool) $base_rate->tax_rate_compound,
                        'priority' => (int) $base_rate->tax_rate_priority,
                        'class' => !empty($base_rate->tax_rate_class) ? $base_rate->tax_rate_class : 'standard',
                        'country' => $base_rate->tax_rate_country,
                        'state' => $base_rate->tax_rate_state,
                        'is_base_rate' => true
                    ];
                }
            }

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
        public static function get_homepage_data()
        {
            $page_name = 'home';
            $page = get_page_by_path($page_name, OBJECT, 'page'); // Fetch by slug

            if ($page) {
                // Fetch ACF fields
                $acf_fields = function_exists('get_fields') ? get_fields($page->ID) : [];

                // Enhance the ACF fields recursively
                $acf_fields = self::enhance_acf_fields($acf_fields);

                $retro_page_data = [
                    'id'    => $page->ID,
                    'title' => $page->post_title,
                    'acf'   => $acf_fields,
                ];

                return rest_ensure_response($retro_page_data);
            } else {
                return new WP_Error('page_not_found', 'Page not found', ['status' => 404]);
            }
        }

        // callback function to get the brand taxonomy
        public static function get_brands_data()
        {
            $brands = get_terms([
                'taxonomy'   => 'brand',
                'hide_empty' => false, // Change to true if you only want terms with posts
            ]);

            if (is_wp_error($brands)) {
                return rest_ensure_response(['error' => $brands->get_error_message()]);
            }

            $response = [];
            foreach ($brands as $brand) {
                $acf_fields = function_exists('get_fields') ? get_fields('term_' . $brand->term_id) : [];

                $response[] = [
                    'id'          => $brand->term_id,
                    'name'        => $brand->name,
                    'slug'        => $brand->slug,
                    'description' => $brand->description,
                    'count'       => $brand->count,
                    'link'        => get_term_link($brand),
                    'acf'         => $acf_fields, // Include ACF fields
                ];
            }

            return rest_ensure_response($response);
        }

        // callback function for getting the testimonials
        public static function get_testimonials_data()
        {
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
            $to = 'pigetoj151@pofmagic.com'; // Change this to your desired email address
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

        /**
         * Callback function to handle storing product IDs
         *
         * @param WP_REST_Request $request Full details about the request
         * @return WP_REST_Response|WP_Error Response object on success, or WP Error on failure
         */
        public static function store_user_viewed_products(WP_REST_Request $request)
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
                    $verified_products[] = $product_id;
                }
            }

            // Retrieve products to verify and return
            $existing_product_ids = get_user_meta($user_id, 'user_saved_product_ids', true);

            // Ensure existing_product_ids is an array (it might be an empty string on first use)
            $existing_product_ids = is_array($existing_product_ids) ? $existing_product_ids : array();

            // Merge and deduplicate product IDs
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
                    array('status' => 500)
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
        public static function store_user_wishlist_products(WP_REST_Request $request)
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
                    $verified_products[] = $product_id;
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
                $current_product_data = self::get_products_by_ids($product_id);
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
        public static function get_products_bought_along($request)
        {
            // Get product IDs from the request
            $product_ids = array_map('intval', (array) $request['product_ids']);

            // Validate product IDs
            $validated_products = [];
            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $validated_products[] = $product_id;
                }
            }

            if (empty($validated_products)) {
                return new WP_REST_Response(['error' => 'Invalid Product IDs'], 404);
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

                    $response_products[] = [
                        'id'             => $product_id,
                        'product_type'   => $product->get_type(),
                        'name'           => $product->get_name(),
                        'price'          => $price_data,
                        'description'    => $product->get_description(),
                        'product_url'    => $product_url,
                        'categories'     => $category_names,
                        'featured_image' => $featured_image ? $featured_image[0] : null,
                        'total_reviews'  => $total_reviews,
                        'total_rating'   => $total_reviews > 0 ? $total_rating : null,
                    ];
                }

                $count++;
            }

            return new WP_REST_Response([
                'original_product_ids' => $validated_products,
                'related_products' => $response_products,
            ], 200);
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
                $current_product_data = self::get_products_by_ids($product_id);
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

        // get filters data like term 

        public static function retrovgame_get_terms(WP_REST_Request $request)
        {
            $attribute_slugs = ['pa_platform', 'pa_condition', 'pa_genre', 'pa_players', 'pa_product-type'];

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
                } else {
                    return new WP_REST_Response([
                        'success' => false,
                        'message' => 'No terms found.',
                    ]);
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
            $product_type = $request->get_param('product-type') ? array_map('intval', (array) $request->get_param('product-type')) : [];
            $category = $request->get_param('category') ? array_map('sanitize_text_field', (array) $request->get_param('category')) : [];
            $paged = $request->get_param('page') ? intval($request->get_param('page')) : 1;
            $products_per_page = $request->get_param('products_per_page') ? intval($request->get_param('products_per_page')) : 8;
            $minprice = $request->get_param('minprice') ? intval($request->get_param('minprice')) : 1;
            $maxprice = $request->get_param('maxprice') ? intval($request->get_param('maxprice')) : 1;
            $sorting = $request->get_param('sorting') ? sanitize_text_field($request->get_param('sorting')) : '';
            $search = $request->get_param('search') ? sanitize_text_field($request->get_param('search')) : '';

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

            // query arguments
            // Base query args
            $args = [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $products_per_page,
                'paged' => $paged,
            ];

            if (!empty($category)) {
                $optional_tax_queries[] = [
                    'taxonomy' => 'product_cat',
                    'field'    => 'id',
                    'terms'    => $category,
                    'operator' => 'IN',
                ];
            }

            if (!empty($search)) {
                $args['s'] = $search; // Add the search term to the query
            }
            // Initialize array for optional filters
            $optional_tax_queries = [];

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
                $optional_tax_queries[] = [
                    'taxonomy' => 'pa_product-type',
                    'field'    => 'id',
                    'terms'    => $product_type,
                    'operator' => 'IN',
                ];
            }

            // If optional filters exist, add them with OR relation
            if (!empty($optional_tax_queries)) {
                $args['tax_query'][] = [
                    'relation' => 'OR', // Apply OR relation for optional filters
                    ...$optional_tax_queries, // Spread the filters to the query
                ];
            }

            // Run the query
            $query = new WP_Query($args);



            // Apply sorting logic
            if (!empty($sorting)) {
                if ($sorting == 'price') {
                    $args['orderby'] = 'meta_value_num';
                    $args['meta_key'] = '_price';
                    $args['order'] = 'ASC';
                } elseif ($sorting == 'oldfirst') {
                    $args['orderby'] = 'date';
                    $args['order'] = 'ASC';
                } elseif ($sorting == 'newfirst') {
                    $args['orderby'] = 'date';
                    $args['order'] = 'DESC';
                }
            }

            // Apply price range filtering
            if ($minprice >= 0 && $maxprice > 0) {
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
                    $product = wc_get_product(get_the_ID());
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
                            'total_rating'   => $total_reviews > 0 ? $total_rating : null, // Avoid division if no reviews
                            'stock_quantity' => $product->get_stock_quantity(),

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
            ], 404);
        }

        private static function enhance_acf_fields($fields)
        {
            foreach ($fields as $field_key => $field_value) {
                if (is_array($field_value)) {
                    if (!empty($field_value) && is_numeric($field_value[0])) {
                        // Process relationship field (array of IDs)
                        $enhanced_data = [];
                        foreach ($field_value as $related_id) {
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

                                $enhanced_data[] = [
                                    'id'             => $related_id,
                                    'product_type'   => $product->get_type(),
                                    'name'           => $product->get_name(),
                                    'price'          => $price_data,
                                    'description'    => $product->get_short_description(),
                                    'product_url'    => $product_url,
                                    'categories'     => $category_names,
                                    'featured_image' => $featured_image ? $featured_image[0] : null,
                                    'total_reviews'  => $total_reviews,
                                    'total_rating'   => $total_reviews > 0 ? $total_rating : null, // Avoid division if no reviews
                                    'sold_this_month' => $sales_count
                                ];
                            } else {
                                // If product is not found
                                $post_type = get_post_type($related_id);
                                if ($post_type == 'post') {
                                    $enhanced_data[] = [
                                        'id'             => $related_id,
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
                        $fields[$field_key] = self::enhance_acf_fields($field_value);
                    }
                }
            }
            return $fields;
        }
        private static function get_products_by_ids($product_ids)
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
                $product_data = [
                    'id'             => $product_ids,
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

                ];
                return $product_data;
            }
            return [];
        }

        /** get the categories details by id */

        public static function retrovgame_get_category_details_by_id(WP_REST_Request $request)
        {
            $categoryid = $request->get_param('category_id');
            if (empty($categoryid) ||  empty(get_the_category_by_ID($categoryid))) {
                return new WP_Error('Invalid category', array('status' => 500));
            }
            $category_info = self::get_woocommerce_category_details($categoryid);

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

            // Prepare category details
            $category_details = [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'count' => $category->count,
                'thumbnail' => $thumbnail_url,
                'url' => $category_url,
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

                $category_details['children'][] = [
                    'id' => $child->term_id,
                    'name' => $child->name,
                    'slug' => $child->slug,
                    'description' => $child->description,
                    'count' => $child->count,
                    'thumbnail' => $child_thumbnail_url,
                    'url' => $child_url,
                    'parent_id' => $child->parent
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
            //$regular_price = $variable_product->get_regular_price();
            //$sale_price = $variable_product->get_sale_price();
            $price = $variable_product->get_price();
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
            $coupon_details = [
                'id'               => $coupon->get_id(),
                'code'             => $coupon->get_code(),
                'discount_type'    => $coupon->get_discount_type(),
                'amount'           => $coupon->get_amount(),
                'description'      => $coupon->get_description(),
                'usage_count'      => $coupon->get_usage_count(),
                'usage_limit'      => $coupon->get_usage_limit(),
                'usage_limit_per_user' => $coupon->get_usage_limit_per_user(),
                'expiry_date'      => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d') : null,
                'individual_use'   => $coupon->get_individual_use(),
                'product_ids'      => $coupon->get_product_ids(),
                'exclude_product_ids' => $coupon->get_excluded_product_ids(),
                'free_shipping'    => $coupon->get_free_shipping(),
            ];

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
            // Get all FAQ posts
            $faq_posts = get_posts([
                'post_type'      => 'faq',  // The CPT name 'faq'
                'posts_per_page' => -1,     // Get all posts
                'post_status'    => 'publish', // Only published posts
            ]);

            $faqs_data = [];

            // Loop through each FAQ post and get the ACF fields
            foreach ($faq_posts as $faq) {
                $faq_data = [
                    'id'            => $faq->ID,
                    'title'         => $faq->post_title,
                    'acf_fields'    => get_fields($faq->ID), // Get all ACF fields
                ];

                // Add the FAQ data to the result array
                $faqs_data[] = $faq_data;
            }

            if (count($faqs_data) > 0) {
                return new WP_REST_Response(array(
                    "Success" => true,
                    "data" => $faqs_data
                ));
            } else {
                return new WP_REST_Response(array(
                    "Success" => false,
                    "data" => "No FAQs found"
                ));
            }
        }
    }
}
