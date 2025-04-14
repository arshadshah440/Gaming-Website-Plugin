<?php

if (!defined('ABSPATH')) {
    exit;
}

class Retroapi_Exchange_Rate_Cron
{
    // List of currencies to track
    private static $currencies = ['USD','JPY', 'GBP', 'MXN', 'AUD', 'NZD', 'CAD', 'CHF'];

    /**
     * Entry point - called from outside
     */
    public static function init()
    {
        add_action('wp', [__CLASS__, 'schedule_cron']);
        add_action('custom_daily_exchange_rate_event', [__CLASS__, 'fetch_and_store_rates']);
    }

    /**
     * Schedules the daily cron event if not already scheduled
     */
    public static function schedule_cron()
    {
        if (!wp_next_scheduled('custom_daily_exchange_rate_event')) {
            wp_schedule_event(time(), 'daily', 'custom_daily_exchange_rate_event');
        }
    }

    /**
     * Fetches exchange rates and stores selected ones in the database
     */
    public static function fetch_and_store_rates()
    {
        $api_url = 'https://v6.exchangerate-api.com/v6/54de83cb33dfa45353ea9bd9/latest/USD';
        $response = wp_remote_get($api_url);

        if (is_wp_error($response)) {
            error_log('[Exchange Rate Cron] API Error: ' . $response->get_error_message());
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['conversion_rates'])) {
            error_log('[Exchange Rate Cron] Invalid API Response Structure');
            return;
        }

        $rates = $body['conversion_rates'];
        $stored_rates = [];

        foreach (self::$currencies as $currency) {
            if (isset($rates[$currency])) {
                $stored_rates[$currency] = $rates[$currency];
            }
        }

        update_option('custom_exchange_rates', $stored_rates);
    }
}
