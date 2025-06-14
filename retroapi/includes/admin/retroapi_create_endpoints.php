<?php
// if file is being called directly or not in the wordpress
if (! defined('ABSPATH')) exit; // Exit if accessed directly
/*
 * Package: RetroAPI
 * @subpackage retroapi/admin 
 * @since 1.0.0
 * @author Arshad Shah
 */

require_once LPCD_PLUGIN_PATH . 'includes/admin/retroapi_endpoint_callbacks.php';

if (!class_exists('retroapi_create_endpoints')) {
    class retroapi_create_endpoints
    {
        public static function retroapi_init_endpoints()
        {
            // Register the API endpoint for menu
            register_rest_route('retroapi/v2', '/get_header_menu', array(
                'methods' => 'POST',
                'callback' => ['retroapi_endpoints_callbacks', 'get_header_menu'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint for homepage data
            register_rest_route('retroapi/v2', '/get_homepage_data', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_homepage_data'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for brands data
            register_rest_route('retroapi/v2', '/brands', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_brands_data'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for testimonial data
            register_rest_route('retroapi/v2', '/testimonials', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_testimonials_data'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for Customer Login
            register_rest_route('retroapi/v2', '/filter_products', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'filter_products_api_callback'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for Customer Login
            register_rest_route('retroapi/v2', '/filter_price_range', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_price_range_api_callback'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for product filteration
            register_rest_route('retroapi/v2', '/user_login', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'authenticate_user_and_get_details'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for product filteration
            register_rest_route('retroapi/v2', '/user_login_remember', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'secure_login_and_get_user_data'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // User Registration Endpoint
            register_rest_route('retroapi/v2', '/user_register', array(
                'methods' => 'POST',
                'callback' => ['retroapi_endpoints_callbacks', 'register_user_with_profile'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Alternative update endpoint without user_id in URL
            register_rest_route('retroapi/v2', '/user_profile', array(
                'methods' => 'PUT',
                'callback' => ['retroapi_endpoints_callbacks', 'update_user_profile'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Get User Profile Endpoint
            register_rest_route('retroapi/v2', '/user_profile/(?P<user_id>\d+)', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_user_profile'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
                'args' => array(
                    'user_id' => array(
                        'required' => true,
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric($param);
                        }
                    ),
                ),
            ));
            // Get User Orders Endpoint (My Orders)
            register_rest_route('retroapi/v2', '/user_orders/(?P<user_id>\d+)', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_user_orders'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
                'args' => array(
                    'user_id' => array(
                        'required' => true,
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric($param);
                        }
                    ),
                    'per_page' => array(
                        'default' => 10,
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric($param) && $param > 0;
                        }
                    ),
                    'page' => array(
                        'default' => 1,
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric($param) && $param > 0;
                        }
                    ),
                    'status' => array(
                        'default' => 'any',
                        'validate_callback' => function($param, $request, $key) {
                            $valid_statuses = array('any', 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'trash');
                            return in_array($param, $valid_statuses);
                        }
                    ),
                ),
            ));
            // Get Order Details Endpoint
            register_rest_route('retroapi/v2', '/order_details', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_order_details'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
                'args' => array(
                    'order_id' => array(
                        'required' => true,
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric($param);
                        }
                    ),
                ),
            ));
            // Send Email Endpoint
            register_rest_route('retroapi/v2', '/send_email', array(
                'methods' => 'POST',
                'callback' => ['retroapi_endpoints_callbacks', 'send_email'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
                'args' => array(
                    'to_email' => array(
                        'required' => true,
                        'validate_callback' => function($param, $request, $key) {
                            return is_email($param);
                        },
                        'sanitize_callback' => 'sanitize_email',
                    ),
                    'subject' => array(
                        'required' => true,
                        'validate_callback' => function($param, $request, $key) {
                            return !empty(trim($param)) && strlen($param) <= 200;
                        },
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'message' => array(
                        'required' => true,
                        'validate_callback' => function($param, $request, $key) {
                            return !empty(trim($param));
                        },
                        'sanitize_callback' => 'wp_kses_post',
                    ),
                    'from_email' => array(
                        'required' => false,
                        'default' => 'help@retrofam.com',
                        'validate_callback' => function($param, $request, $key) {
                            return empty($param) || is_email($param);
                        },
                        'sanitize_callback' => 'sanitize_email',
                    ),
                    'from_name' => array(
                        'required' => false,
                        'default' => 'RetroFam Help',
                        'validate_callback' => function($param, $request, $key) {
                            return empty($param) || strlen($param) <= 100;
                        },
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'reply_to' => array(
                        'required' => false,
                        'default' => 'help@retrofam.com',
                        'validate_callback' => function($param, $request, $key) {
                            return empty($param) || is_email($param);
                        },
                        'sanitize_callback' => 'sanitize_email',
                    ),
                    'cc' => array(
                        'required' => false,
                        'validate_callback' => function($param, $request, $key) {
                            if (empty($param)) return true;
                            if (is_array($param)) {
                                foreach ($param as $email) {
                                    if (!is_email($email)) return false;
                                }
                                return true;
                            }
                            return is_email($param);
                        },
                    ),
                    'bcc' => array(
                        'required' => false,
                        'validate_callback' => function($param, $request, $key) {
                            if (empty($param)) return true;
                            if (is_array($param)) {
                                foreach ($param as $email) {
                                    if (!is_email($email)) return false;
                                }
                                return true;
                            }
                            return is_email($param);
                        },
                    ),
                    'content_type' => array(
                        'required' => false,
                        'default' => 'text/html',
                        'validate_callback' => function($param, $request, $key) {
                            return in_array($param, ['text/html', 'text/plain']);
                        },
                    ),
                    'attachments' => array(
                        'required' => false,
                        'validate_callback' => function($param, $request, $key) {
                            if (empty($param)) return true;
                            if (!is_array($param)) return false;
                            foreach ($param as $attachment) {
                                if (!is_numeric($attachment) && !filter_var($attachment, FILTER_VALIDATE_URL)) {
                                    return false;
                                }
                            }
                            return true;
                        },
                    ),
                ),
            ));
            // Register the API endpoint for viewed products by user storing
            register_rest_route('retroapi/v2', '/set_user_viewed_products', array(
                'methods' => 'POST',
                'callback' => ['retroapi_endpoints_callbacks', 'retro_set_user_viewed_products'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint for add to whishlist
            register_rest_route('retroapi/v2', '/set_user_wishlist_products', array(
                'methods' => 'POST',
                'callback' => ['retroapi_endpoints_callbacks', 'retro_set_user_wishlist_products'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint for viewed products by user
            register_rest_route('retroapi/v2', '/get_user_viewed_products', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_user_viewed_products'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint for wishlisted products by user
            register_rest_route('retroapi/v2', '/get_user_wishlisted_products', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_user_wishlisted_products'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for viewed products by user
            register_rest_route('retroapi/v2', '/get_products_bought_along', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'get_products_bought_along'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for viewed products by user
            register_rest_route('retroapi/v2', '/wp_search', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_search'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint for autosuggest completion
            register_rest_route('retroapi/v2', '/wp_search_autosuggest', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_search_autosuggest'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint for viewed products by user
            register_rest_route('retroapi/v2', '/product_sales_this_month', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_product_sales_this_month'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint for viewed products by user
            register_rest_route('retroapi/v2', '/get_productdata_by_id', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_product_by_id'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for filters data
            register_rest_route('retroapi/v2', '/retrovgame_terms', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_terms'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for Add to cart endpoint
            register_rest_route('retroapi/v2', '/add_to_cart', array(
                'methods' => 'POST',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_add_to_cart'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for get product variation id endpoint
            register_rest_route('retroapi/v2', '/get_product_variation_id', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_product_variation_id'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint for get mega menu
            register_rest_route('retroapi/v2', '/get_header_menu_details', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_header_menu_details'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for validation of the coupon code endpoint
            register_rest_route('retroapi/v2', '/validate_coupon', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_validate_coupon'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for get  cart endpoint
            register_rest_route('retroapi/v2', '/get_cart_data', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_cart_data'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for get  cart endpoint
            register_rest_route('retroapi/v2', '/get_faqs', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_faqs'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for contact us  endpoint
            register_rest_route('retroapi/v2', '/contact_us', array(
                'methods' => 'POST',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_contact_us'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint for contact us  endpoint
            register_rest_route('retroapi/v2', '/subscribe_now', array(
                'methods' => 'POST',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_subscribe_now'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint tax and shipping details  endpoint
            register_rest_route('retroapi/v2', '/get_tax_and_shipping', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_tax_and_shipping'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // Register the API endpoint tax and shipping details  endpoint
            register_rest_route('retroapi/v2', '/get_category_details_by_id', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_category_details_by_id'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint tax and shipping details  endpoint
            register_rest_route('retroapi/v2', '/get_website_contact_details', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_website_contact_details'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint tax and shipping details  endpoint
            register_rest_route('retroapi/v2', '/get_single_post', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_singlepost_details_by_id'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));


            // Register the API endpoint cart_exclusive_offer
            register_rest_route('retroapi/v2', '/cart_exclusive_offer', array(
                'methods' => 'GET',

                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_cart_exclusive_offer'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint Product Type Attribute terms fetching
            register_rest_route('retroapi/v2', '/get_product_type_terms', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_product_type_terms'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // get list of most sold products
            register_rest_route('retroapi/v2', '/get_best_seller_products', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_best_seller_products'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // get list of most sold products
            register_rest_route('retroapi/v2', '/get_shipping_methods', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_shipping_methods'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // get list of most sold products
            register_rest_route('retroapi/v2', '/get_page_content', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_page_content'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // set exchange rates
            register_rest_route('retroapi/v2', '/set_exchange_rate', array(
                'methods' => 'POST',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_set_exchange_rate'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // get exchange rates
            register_rest_route('retroapi/v2', '/get_exchange_rate', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_exchange_rate'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // get exchange rates
            register_rest_route('retroapi/v2', '/get_cart_products_details', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_cart_products_details'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // get list of shipping methods details using id
            register_rest_route('retroapi/v2', '/get_shipping_method_details', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_shipping_method_details'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // Register the API endpoint for recommendation system
            register_rest_route('retroapi/v2', '/get_recomended_products', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_recomended_products'],
                'args'     => array(
                    'ids' => array(
                        'required'          => true,
                        'validate_callback' => function ($param) {
                            $ids = explode(',', $param);
                            return !empty($ids) && array_reduce($ids, function ($carry, $id) {
                                return $carry && is_numeric($id);
                            }, true);
                        }
                    ),
                ),
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            register_rest_route('retroapi/v2', '/get_product_variations', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_product_variations'],
                'args'     => [
                    'product_id' => [
                        'required' => true,
                        'validate_callback' => function ($param, $request, $key) {
                            return is_numeric($param);
                        }
                    ]
                ],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // endpoint to fetch attributes data
            register_rest_route('retroapi/v2', '/get_product_term_data', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_product_term_data'],
                'args'     => array(
                    'id' => array(
                        'required'          => true,
                        'validate_callback' => function ($param) {
                            return is_numeric($param) && intval($param) > 0;
                        }
                    ),
                ),
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
            // get shipping details based in country
            register_rest_route('retroapi/v2', '/get_shipping_methods_using_countrycode/(?P<country>\w+)', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_shipping_methods_using_countrycode'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));

            // get Meta Tags details
            register_rest_route('retroapi/v2', '/get_meta_tags', array(
                'methods' => 'GET',
                'callback' => ['retroapi_endpoints_callbacks', 'retrovgame_get_meta_tags'],
                'permission_callback' => ["retroapi_create_endpoints", "set_authentication_token"],
            ));
        }
        // Permission callback to check JWT token
        public static function set_authentication_token(WP_REST_Request $request)
        {
            // Check if thewp_send_json_error( $data:mixed|null, $status_code:integer|null, $options:integer )
            $api_key = $request->get_header('Authorization'); // Extract API key from the Authorization header
            $stored_key = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczovL2Rldi5yZXRyb2ZhbS5jb20iLCJpYXQiOjE3MzMyMzcyNzksIm5iZiI6MTczMzIzNzI3OSwiZXhwIjoxNzMzODQyMDc5LCJkYXRhIjp7InVzZXIiOnsiaWQiOiIxIn19fQ.8qO5lcgAolmKvs_YpdsAaG2n_qaWLeSaqEPBf9H2cA8'; // Retrieve stored API key

            if (empty($api_key) || $api_key !== 'Bearer ' . $stored_key) {
                return new WP_Error('rest_forbidden', 'Invalid or missing API key', ['status' => 403]);
            }

            return true; // Proceed if the key is valid
        }
    }
}
