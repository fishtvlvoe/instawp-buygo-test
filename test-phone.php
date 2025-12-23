<?php
/**
 * 簡單的電話提取測試腳本
 * 
 * 訪問：https://test.buygo.me/wp-content/plugins/buygo/test-phone.php
 */

// 載入 WordPress
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';

// 載入 PhoneExtractor
require_once __DIR__ . '/app/Services/PhoneExtractor.php';

// 設定標頭
header('Content-Type: application/json; charset=utf-8');

// 檢查權限
if (!current_user_can('manage_options')) {
    echo json_encode(['error' => '需要管理員權限'], JSON_UNESCAPED_UNICODE);
    exit;
}

global $wpdb;

$table_orders = $wpdb->prefix . 'fct_orders';
$table_addresses = $wpdb->prefix . 'fct_order_addresses';

// 取得最近 20 筆訂單
$orders = $wpdb->get_results("SELECT * FROM {$table_orders} ORDER BY id DESC LIMIT 20");

$results = [];

foreach ($orders as $order) {
    // 取得地址
    $shipping_address = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_addresses} WHERE order_id = %d AND type = 'shipping' LIMIT 1",
        $order->id
    ));
    
    $billing_address = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_addresses} WHERE order_id = %d AND type = 'billing' LIMIT 1",
        $order->id
    ));
    
    // 使用 PhoneExtractor 提取電話
    $extracted_phone = \BuyGo\App\Services\PhoneExtractor::extractPhoneFromAddresses($shipping_address, $billing_address);
    
    // Debug 資訊
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

$response = [
    'success' => true,
    'total_orders' => count($results),
    'with_phone' => count(array_filter($results, function($r) { return $r['has_phone']; })),
    'without_phone' => count(array_filter($results, function($r) { return !$r['has_phone']; })),
    'orders' => $results,
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
