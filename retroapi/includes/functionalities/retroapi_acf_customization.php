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
            // add_filter('acf/format_value', ['retroapi_acf_customization', 'retroapi_acf_format_value'], 10, 3);
            add_filter('acf/update_value/name=connected_products', ['retroapi_acf_customization', 'retroapi_acf_connected_value'], 10, 4);
            add_filter('acf/update_value/name=add_a_game', ['retroapi_acf_customization', 'retroapi_acf_connected_value'], 10, 4);
            add_filter('acf/load_value/name=connected_products', ['retroapi_acf_customization', 'retroapi_acf_format_value'], 10, 3);
            add_filter('acf/load_value/name=add_a_game', ['retroapi_acf_customization', 'retroapi_acf_format_value'], 10, 3);
            add_filter('acf/fields/relationship/query', ['retroapi_acf_customization', 'filter_acf_relationship_only_simple_products'], 10, 3);
            add_filter('acf/validate_value', ['retroapi_acf_customization', 'validate_acf_relationship_only_simple'], 10, 4);
        }

        public static function validate_acf_relationship_only_simple($valid, $value, $field, $input)
        {
            $target_fields = ['connected_products', 'add_a_game'];

            if (in_array($field['name'], $target_fields) && !empty($value)) {
                foreach ((array) $value as $product_id) {
                    if (!has_term('simple', 'product_type', $product_id)) {
                        return 'Only simple products are allowed.';
                    }
                }
            }

            return $valid;
        }
        public static function filter_acf_relationship_only_simple_products($args, $field, $post_id)
        {
            // Only target specific field(s) by name/key
            $target_fields = ['connected_products', 'add_a_game'];

            if (in_array($field['name'], $target_fields)) {
                $args['tax_query'] = [
                    [
                        'taxonomy' => 'product_type',
                        'field'    => 'slug',
                        'terms'    => ['simple'],
                    ],
                ];
            }

            return $args;
        }

        public static function retroapi_acf_format_value($value, $post_id, $field)
        {
            if (empty($value)) {
                return $value;
            } else {
                if (is_array($value)) {
                    $value = array_map(function ($val) {
                        return isset($val['id']) ? $val['id'] : $val;
                    }, $value);
                }
                return $value;
            }
        }
        // Function to format the value

        public static function retroapi_acf_connected_value($value, $post_id, $field, $original)
        {
            if (!empty($value)) {
                // Format the data
                $value = array_map(function ($post) {
                    return self::get_addons_products_by_ids($post);
                }, $value);
            }
            return $value;
        }
        public static function get_addons_products_by_ids($product_ids)
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
                // $price_data = [];
                // if ($product->is_type('variable')) {
                //     // Get price range for variable products
                //     $price_data['min_price'] = $product->get_variation_price('min');
                //     $price_data['max_price'] = $product->get_variation_price('max');
                // } else {
                //     // Get regular and sale price for simple products
                //     $price_data['regular_price'] = $product->get_regular_price();
                //     $price_data['sale_price'] = $product->get_sale_price();
                // }
                $attributes_list = self::get_product_attributes_array($product_ids);
                // $sold_this_month = self::retro_sold_counter($product_ids);

                $product_data = [
                    'id'             => $product_ids,
                    'type'           => $product->get_type(),
                    'slug'           => $product->get_slug(),
                    'product_type'   => $product->get_type(),
                    'name'           => $product->get_name(),
                    'price' => wc_format_decimal($product->get_price()),
                    'on_sale' => $product->is_on_sale(),
                    'sale_price' => $product->get_sale_price(),
                    'regular_price' => $product->get_regular_price(),
                    'product_url'    => $product_url,
                    // 'categories'     => $category_names,
                    // // 'featured_image' => $featured_image ? $featured_image[0] : null,
                    // 'total_reviews'  => $total_reviews,
                    // 'total_rating'   => $total_reviews > 0 ? $total_rating : null, // Avoid division if no reviews
                    'stock_quantity' => $product->get_stock_quantity(),
                    // 'attributes' => $attributes_list,
                    // 'sold_this_month' => $sold_this_month

                ];
                return $product_data;
            }
            return [];
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
    }
}
