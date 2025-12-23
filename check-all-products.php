<?php
/**
 * 全面檢查所有商品資料表
 * 
 * 使用方式：
 * 訪問：https://test.buygo.me/wp-content/plugins/buygo/check-all-products.php?password=delete123
 */

define('ADMIN_PASSWORD', 'delete123');

// 載入 WordPress
require_once(__DIR__ . '/../../../wp-load.php');

// 檢查權限
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    if (!empty(ADMIN_PASSWORD)) {
        $inputPassword = $_GET['password'] ?? '';
        if ($inputPassword !== ADMIN_PASSWORD) {
            die('❌ 權限不足或密碼錯誤。請加上 ?password=delete123');
        }
    } else {
        die('❌ 權限不足。請先登入 WordPress 後台。');
    }
}

global $wpdb;

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>全面商品資料檢查</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1600px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; }
        h2 { color: #555; margin-top: 30px; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 13px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .danger {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
        pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 全面商品資料檢查</h1>

        <h2>1️⃣ WordPress Posts (wp_posts)</h2>
        <?php
        // 查詢所有與產品相關的 post_type
        $postTypes = ['fluent-products', 'product', 'fc_product'];
        
        foreach ($postTypes as $postType) {
            $count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s
            ", $postType));
            
            echo "<div class='info'>";
            echo "<strong>{$postType}:</strong> {$count} 筆";
            
            if ($count > 0) {
                $products = $wpdb->get_results($wpdb->prepare("
                    SELECT ID, post_title, post_status FROM {$wpdb->posts} 
                    WHERE post_type = %s 
                    LIMIT 10
                ", $postType));
                
                echo "<ul>";
                foreach ($products as $p) {
                    echo "<li>ID: {$p->ID}, 標題: " . esc_html($p->post_title) . ", 狀態: {$p->post_status}</li>";
                }
                echo "</ul>";
            }
            echo "</div>";
        }
        ?>

        <h2>2️⃣ FluentCart 資料表</h2>
        <?php
        // FluentCart Product Details
        $fctDetails = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}fct_product_details LIMIT 10
        ");
        echo "<div class='info'>";
        echo "<strong>fct_product_details:</strong> " . count($fctDetails) . " 筆";
        if (!empty($fctDetails)) {
            echo "<pre>" . print_r($fctDetails, true) . "</pre>";
        }
        echo "</div>";
        
        // FluentCart Product Variations
        $fctVariations = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}fct_product_variations LIMIT 10
        ");
        echo "<div class='info'>";
        echo "<strong>fct_product_variations:</strong> " . count($fctVariations) . " 筆";
        if (!empty($fctVariations)) {
            echo "<pre>" . print_r($fctVariations, true) . "</pre>";
        }
        echo "</div>";
        ?>

        <h2>3️⃣ Plus One 模組資料表</h2>
        <?php
        // Plus One Orders (這可能是測試商品的來源)
        $plusOneOrders = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}mygo_plus_one_orders LIMIT 10
        ");
        echo "<div class='info'>";
        echo "<strong>mygo_plus_one_orders:</strong> " . count($plusOneOrders) . " 筆";
        if (!empty($plusOneOrders)) {
            echo "<pre>" . print_r($plusOneOrders, true) . "</pre>";
        } else {
            echo "<p>⚠️ 資料表可能不存在或是空的</p>";
        }
        echo "</div>";
        ?>

        <h2>4️⃣ 所有資料表列表</h2>
        <?php
        $tables = $wpdb->get_results("SHOW TABLES LIKE '{$wpdb->prefix}%'", ARRAY_N);
        echo "<div class='info'>";
        echo "<strong>找到 " . count($tables) . " 個資料表：</strong>";
        echo "<ul>";
        foreach ($tables as $table) {
            $tableName = $table[0];
            
            // 只顯示可能與商品相關的資料表
            if (stripos($tableName, 'product') !== false || 
                stripos($tableName, 'fct_') !== false || 
                stripos($tableName, 'mygo') !== false ||
                stripos($tableName, 'buygo') !== false) {
                
                $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$tableName}`");
                echo "<li><strong>{$tableName}</strong>: {$count} 筆</li>";
            }
        }
        echo "</ul>";
        echo "</div>";
        ?>

        <h2>5️⃣ BuyGo Core 產品 API 呼叫測試</h2>
        <?php
        echo "<div class='info'>";
        echo "<p>嘗試透過 REST API 取得 BuyGo 的商品列表...</p>";
        
        // 模擬 API 呼叫
        $request = new \WP_REST_Request('GET', '/buygo/v1/products');
        $response = rest_do_request($request);
        
        if ($response->is_error()) {
            echo "<div class='danger'>";
            echo "<strong>API 錯誤：</strong>";
            echo "<pre>" . print_r($response->as_error(), true) . "</pre>";
            echo "</div>";
        } else {
            $data = $response->get_data();
            echo "<strong>API 回傳：</strong>";
            echo "<pre>" . print_r($data, true) . "</pre>";
        }
        echo "</div>";
        ?>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd;">
            <p style="color: #666;">
                檔案位置：<code><?php echo __FILE__; ?></code>
            </p>
        </div>
    </div>
</body>
</html>
