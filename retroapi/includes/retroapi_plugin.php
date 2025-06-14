<?php

if (!defined('ABSPATH')) exit;

require_once LPCD_PLUGIN_PATH . 'includes/admin/retroapi_endpoints.php';
require_once LPCD_PLUGIN_PATH . 'includes/admin/retroapi_endpoint_callbacks.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_acf_customization.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_sku_management.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_image_optimizer.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_tax_fields.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_shipping_methods.php';
require_once LPCD_PLUGIN_PATH . 'includes/functionalities/retroapi_cron_jobs.php';

if (!class_exists('retroapi_plugin')) {

    class retroapi_plugin
    {
        public static function retroapi_init()
        {
            self::retroapi_endpoints();
            retroapi_sku_management::init();
            Advanced_AVIF_Converter::init();
            RetroAPI_Shipping_Methods::init();
            Retroapi_Exchange_Rate_Cron::init();
            retroapi_acf_customization::init();

            add_action('woocommerce_rest_insert_order', function ($order, $request) {
                $order->calculate_totals(); // This calculates taxes, shipping, etc.
                $order->save();
            }, 10, 2);

            $products = get_posts([
                'post_type' => 'product',
                'posts_per_page' => -1,
                'fields' => 'ids',
            ]);

            foreach ($products as $product_id) {
                delete_post_meta($product_id, 'seo_fields');
                delete_post_meta($product_id, 'seo_fields_meta_title');
                delete_post_meta($product_id, 'seo_fields_meta_description');
            }

            add_filter('woocommerce_rest_prepare_product_object', [__CLASS__, 'add_acf_swatch_colors_to_api_response'], 10, 3);
            add_action('woocommerce_after_add_attribute_fields', [__CLASS__, 'custom_add_attribute_type_field']);
            add_action('woocommerce_after_edit_attribute_fields', [__CLASS__, 'custom_edit_attribute_type_field']);
            // Save field on Add Attribute
            add_action('woocommerce_attribute_added', [__CLASS__, 'save_retro_custom_attribute_type'], 10, 2);


            // Save field on Edit Attribute
            add_action('woocommerce_attribute_updated', [__CLASS__, 'save_retro_custom_attribute_type'], 10, 3);
            // RetroAPI_Tax_Fields::init();

        }
        public static function custom_add_attribute_type_field()
        {
?>
            <div class="form-field">
                <label for="retro_custom_attribute_type"><?php esc_html_e('Select the Attribute Type', 'textdomain'); ?></label>
                <select name="retro_custom_attribute_type" id="retro_custom_attribute_type">
                    <option value="select">Select</option>
                    <option value="swatch">Swatch</option>
                    <option value="text">Text</option>
                    <option value="radio">radio</option>
                </select>
            </div>
        <?php
        }

        public static function custom_edit_attribute_type_field($term)
        {
            $taxonomy_id = sanitize_text_field($_GET['edit'] ?? '');
            $taxonomy = wc_attribute_taxonomy_name_by_id(intval($taxonomy_id));

            $saved = get_option('retro_custom_attribute_type_' . $taxonomy);

        ?>
            <tr class="form-field <?php echo $term->taxonomy; ?>" id="<?php echo $term->taxonomy; ?>">
                <th scope="row" valign="top"><label for="retro_custom_attribute_type"><?php esc_html_e('Select the Attribute Type', 'textdomain'); ?></label></th>
                <td>
                    <select name="retro_custom_attribute_type" id="retro_custom_attribute_type">
                        <option value="select" <?php selected($saved, 'select'); ?>>Select</option>
                        <option value="swatch" <?php selected($saved, 'swatch'); ?>>Swatch</option>
                        <option value="text" <?php selected($saved, 'text'); ?>>Text</option>
                        <option value="radio" <?php selected($saved, 'radio'); ?>>radio</option>
                    </select>
                    <p class="description"><?php esc_html_e('Choose how this attribute will behave.', 'textdomain'); ?></p>
                </td>
            </tr>
<?php
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

            $soldthismonth = self::retro_sold_counter($response->data['id']);

            $response->data['sold_this_month'] = $soldthismonth;

            $reviews = self::get_product_reviews_with_acf($response->data['id']);

            $response->data['reviews'] = $reviews;

            // attributes Updates

            foreach ($response->data['attributes'] as &$attribute) {
                $attribute_name = strtolower(str_replace(' ', '-', $attribute['name'])); // Convert to slug format
                $taxonomy = wc_attribute_taxonomy_name_by_id($attribute['id']);
                $attribute_slug = str_replace('pa_', '', $taxonomy);
                $type = get_option("retro_custom_attribute_type_$taxonomy", 'select');
                $attribute['attribute_type'] = $type;
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

                    $attribute_type = $term ? get_field('select_the_type', 'term_' . $term->term_id) : 'simple';

                    // Ensure the option is always an object
                    $option = [
                        'name' => $option,
                        'swatch_color' => $swatch_color,
                        'slug' => $term->slug
                        // 'attribute_type' => $attribute_type
                    ];
                }
            }

            // updating related id to use actual product data
            // $related_products_data = [];
            // if (count($response->data['related_ids']) > 0) {
            //     foreach ($response->data['related_ids'] as &$related_id) {
            //         $relatd_data = retroapi_endpoints_callbacks::get_products_by_ids($related_id);
            //         $related_products_data[] = $relatd_data;
            //     }
            //     $response->data['related_ids'] = $related_products_data;
            // }
            $seo_fields = get_field("seo_meta_fields", $response->data['id']);
            $response->data['seo_fields'] = $seo_fields;
            $variation_details = [];
            if (count($response->data['variations']) > 0) {
                foreach ($response->data['variations'] as &$variation) {
                    $variation_data = self::get_variation_details($variation);
                    $variation_details[] = $variation_data;
                }
                $response->data['variations'] = $variation_details;
            }

            return $response;
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
        // Hook into WooCommerce REST API response
        public static function get_product_reviews_with_acf($product_id)
        {

            // Get approved reviews for the product
            $comments = get_approved_comments($product_id);

            $reviews = [];

            foreach ($comments as $comment) {
                $review = [
                    'id' => $comment->comment_ID,
                    'author_name' => $comment->comment_author,
                    'content' => $comment->comment_content,
                    'avatar' => get_avatar_url($comment->comment_author_email),
                    'rating' => get_comment_meta($comment->comment_ID, 'rating', true),
                    'date_created' => $comment->comment_date,
                    'acf_fields' => [],
                ];

                $galleryimagesids = get_comment_meta($comment->comment_ID, 'review_product_images', true);

                $gallery = [];

                foreach ($galleryimagesids as $galleryimagesid) {
                    $gallery[] = wp_get_attachment_url($galleryimagesid);
                }
                $review['gallery'] = $gallery;


                // // Include ACF fields if available
                // if (function_exists('get_fields')) {
                //     if (!empty($acf_fields)) {
                //         $review['acf_fields'] = $acf_fields;
                //     }

                // }

                $reviews[] = $review;
            }

            return $reviews;
        }
        public static function  save_retro_custom_attribute_type($attribute_id, $attribute_name, $attribute_old_name = null)
        {
            if (!is_admin() || !isset($_POST['retro_custom_attribute_type'])) {
                return;
            }

            $type = sanitize_text_field($_POST['retro_custom_attribute_type']);
            $taxonomy = wc_attribute_taxonomy_name_by_id($attribute_id);

            if (!$taxonomy) {
                return;
            }

            update_option('retro_custom_attribute_type_' . $taxonomy, $type);
        }
        public static function get_variation_details($variation_id)
        {
            // Get the variation product object
            $variation = wc_get_product($variation_id);
            $thumbnail_id = get_post_thumbnail_id( $variation_id );
            $thumbnail_url="";
            if ( $thumbnail_id ) {
                $thumbnail_url = wp_get_attachment_url( $thumbnail_id );
            }

            if ($variation && $variation->is_type('variation')) {
                return [

                    'id'              => $variation_id,
                    'attributes'      => $variation->get_attributes(),
                    'on_sale'         => $variation->is_on_sale(),
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
                    'thumbnail' => $thumbnail_url
                ];
            }

            return ['error' => 'Invalid variation ID or not a variation product.'];
        }
    }
}
