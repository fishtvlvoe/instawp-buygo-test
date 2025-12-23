<?php
/**
 * BuyGo Helper Permissions Test Script
 * 
 * Usage: Access via browser at /wp-content/plugins/buygo/test-helper-permissions.php
 * Or run via WP-CLI: wp eval-file test-helper-permissions.php
 */

// Load WordPress
require_once('../../../wp-load.php');

// Security check
if (!current_user_can('manage_options')) {
    wp_die('權限不足：需要管理員權限才能執行此測試腳本。');
}

header('Content-Type: application/json; charset=utf-8');

use BuyGo\Core\Services\RoleManager;
use BuyGo\Core\Services\HelperManager;

$role_manager = new RoleManager();
$helper_manager = new HelperManager();

$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'tests' => [],
    'summary' => [
        'total' => 0,
        'passed' => 0,
        'failed' => 0
    ]
];

// Test 1: Check helpers table structure
$results['tests'][] = [
    'name' => 'Test 1: 檢查 helpers 資料庫結構',
    'status' => 'running'
];

global $wpdb;
$table_name = $wpdb->prefix . 'buygo_helpers';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

$results['tests'][0]['status'] = $table_exists ? 'passed' : 'failed';
$results['tests'][0]['result'] = $table_exists ? '資料表存在' : '資料表不存在！';
$results['summary']['total']++;
if ($table_exists) {
    $results['summary']['passed']++;
    
    // Get column info
    $columns = $wpdb->get_results("DESCRIBE $table_name");
    $results['tests'][0]['columns'] = array_map(function($col) {
        return [
            'field' => $col->Field,
            'type' => $col->Type,
            'null' => $col->Null,
            'key' => $col->Key,
            'default' => $col->Default
        ];
    }, $columns);
} else {
    $results['summary']['failed']++;
}

// Test 2: Get all helpers
$results['tests'][] = [
    'name' => 'Test 2: 查詢所有小幫手綁定關係',
    'status' => 'running'
];

$all_helpers = $wpdb->get_results("SELECT * FROM $table_name");
$results['tests'][1]['status'] = 'passed';
$results['tests'][1]['result'] = '找到 ' . count($all_helpers) . ' 筆小幫手綁定記錄';
$results['tests'][1]['data'] = array_map(function($helper) {
    return [
        'id' => $helper->id,
        'seller_id' => $helper->seller_id,
        'helper_id' => $helper->helper_id,
        'can_view_orders' => (bool) $helper->can_view_orders,
        'can_update_orders' => (bool) $helper->can_update_orders,
        'can_manage_products' => (bool) $helper->can_manage_products,
        'can_reply_customers' => (bool) $helper->can_reply_customers,
        'status' => $helper->status,
        'assigned_at' => $helper->assigned_at
    ];
}, $all_helpers);
$results['summary']['total']++;
$results['summary']['passed']++;

// Test 3: Test RoleManager helper methods
$results['tests'][] = [
    'name' => 'Test 3: 測試 RoleManager 小幫手權限方法',
    'status' => 'running'
];

if (count($all_helpers) > 0) {
    $test_helper = $all_helpers[0];
    $helper_id = $test_helper->helper_id;
    $seller_id = $test_helper->seller_id;
    
    // Test is_helper
    $is_helper = $role_manager->is_helper($helper_id);
    
    // Test helper_can
    $can_view = $role_manager->helper_can($helper_id, $seller_id, 'can_view_orders');
    $can_update = $role_manager->helper_can($helper_id, $seller_id, 'can_update_orders');
    $can_manage_products = $role_manager->helper_can($helper_id, $seller_id, 'can_manage_products');
    
    // Test get_helper_sellers
    $sellers = $role_manager->get_helper_sellers($helper_id);
    
    // Test can_access_seller_resource
    $can_access = $role_manager->can_access_seller_resource($helper_id, $seller_id, 'can_view_orders');
    
    $results['tests'][2]['status'] = 'passed';
    $results['tests'][2]['result'] = '所有方法執行成功';
    $results['tests'][2]['data'] = [
        'test_helper_id' => $helper_id,
        'test_seller_id' => $seller_id,
        'is_helper' => $is_helper,
        'can_view_orders' => $can_view,
        'can_update_orders' => $can_update,
        'can_manage_products' => $can_manage_products,
        'helper_sellers' => $sellers,
        'can_access_seller_resource' => $can_access
    ];
    $results['summary']['passed']++;
} else {
    $results['tests'][2]['status'] = 'skipped';
    $results['tests'][2]['result'] = '沒有小幫手記錄，跳過此測試';
}
$results['summary']['total']++;

// Test 4: Test HelperManager
$results['tests'][] = [
    'name' => 'Test 4: 測試 HelperManager 方法',
    'status' => 'running'
];

// Get all users with buygo_helper role
$helper_users = get_users(['role' => 'buygo_helper']);
$results['tests'][3]['helper_users_count'] = count($helper_users);
$results['tests'][3]['helper_users'] = array_map(function($user) {
    return [
        'id' => $user->ID,
        'login' => $user->user_login,
        'email' => $user->user_email,
        'roles' => $user->roles
    ];
}, $helper_users);

// Test get_seller_helpers for each unique seller
$sellers = array_unique(array_column($all_helpers, 'seller_id'));
$sellers_data = [];
foreach ($sellers as $seller_id) {
    $seller_helpers = $helper_manager->get_seller_helpers($seller_id);
    $sellers_data[] = [
        'seller_id' => $seller_id,
        'helpers_count' => count($seller_helpers),
        'helpers' => $seller_helpers
    ];
}
$results['tests'][3]['sellers_data'] = $sellers_data;
$results['tests'][3]['status'] = 'passed';
$results['tests'][3]['result'] = '成功查詢 ' . count($sellers) . ' 個賣家的小幫手資料';
$results['summary']['total']++;
$results['summary']['passed']++;

// Final summary
$results['summary']['success_rate'] = $results['summary']['total'] > 0 
    ? round(($results['summary']['passed'] / $results['summary']['total']) * 100, 2) . '%'
    : '0%';

echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
