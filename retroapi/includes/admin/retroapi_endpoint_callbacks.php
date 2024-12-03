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
        public static function get_testimonials_data() {
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
                                $enhanced_data[] = [
                                    'id'             => $related_id,
                                    'product_type'   => $product->get_type(),
                                    'name'           => $product->get_name(),
                                    'price'          => $price_data,
                                    'description'    => $product->get_description(),
                                    'product_url'    => $product_url,
                                    'categories'     => $category_names,
                                    'featured_image' => $featured_image ? $featured_image[0] : null,
                                    'total_reviews'  => $total_reviews,
                                    'total_rating'   => $total_reviews > 0 ? $total_rating : null, // Avoid division if no reviews
                                ];
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
    }
}
