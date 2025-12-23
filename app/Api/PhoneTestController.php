<?php
/**
 * Phone Test Page - Debug Controller
 * 
 * 測試電話號碼提取功能
 */

namespace BuyGo\App\Api;

use WP_REST_Request;
use WP_REST_Response;
use BuyGo\App\Services\PhoneExtractor;

if (!defined('ABSPATH')) {
    exit;
}

class PhoneTestController
{
    /**
     * Register routes
     */
    public static function register_routes()
    {
        register_rest_route('buygo/v1', '/phone-test', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'test_phone_extraction'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Test phone extraction for recent orders
     */
    public static function test_phone_extraction(WP_REST_Request $request)
    {
        global $wpdb;
        
        $table_orders = $wpdb->prefix . 'fct_orders';
        $table_addresses = $wpdb->prefix . 'fct_order_addresses';
        
        // Get latest 20 orders
        $orders = $wpdb->get_results("SELECT * FROM {$table_orders} ORDER BY id DESC LIMIT 20");
        
        $results = [];
        
        foreach ($orders as $order) {
            // Get addresses
            $shipping_address = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$table_addresses} WHERE order_id = %d AND type = 'shipping' LIMIT 1",
                $order->id
            ) );
            
            $billing_address = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$table_addresses} WHERE order_id = %d AND type = 'billing' LIMIT 1",
                $order->id
            ) );
            
            // Extract phone using PhoneExtractor
            $extracted_phone = PhoneExtractor::extractPhoneFromAddresses($shipping_address, $billing_address);
            
            // Debug: show all possible phone sources
            $debug_info = [
                'shipping_phone_direct' => $shipping_address->phone ?? null,
                'shipping_meta' => $shipping_address->meta ?? null,
                'billing_phone_direct' => $billing_address->phone ?? null,
                'billing_meta' => $billing_address->meta ?? null,
            ];
            
            $results[] = [
                'order_id' => $order->id,
                'order_number' => '#' . $order->id,
                'customer_id' => $order->customer_id,
                'extracted_phone' => $extracted_phone,
                'has_phone' => !empty($extracted_phone),
                'debug' => $debug_info,
            ];
        }
        
        return new WP_REST_Response([
            'success' => true,
            'total_orders' => count($results),
            'with_phone' => count(array_filter($results, function($r) { return $r['has_phone']; })),
            'without_phone' => count(array_filter($results, function($r) { return !$r['has_phone']; })),
            'orders' => $results,
        ]);
    }
}
