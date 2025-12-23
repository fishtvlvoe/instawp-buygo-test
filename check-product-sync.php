<?php
/**
 * 檢查商品資料同步狀態
 * 
 * 使用方式：
 * 訪問：https://test.buygo.me/wp-content/plugins/buygo/check-product-sync.php?password=delete123
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
    <title>商品資料同步檢查</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1400px;
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
        .status-ok { color: #28a745; font-weight: bold; }
        .status-missing { color: #dc3545; font-weight: bold; }
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 商品資料同步檢查</h1>

<?php

// 1. 查詢所有 fluent-products
$products = $wpdb->get_results("
    SELECT ID, post_title, post_status, post_date
    FROM {$wpdb->posts}
    WHERE post_type = 'fluent-products'
    ORDER BY ID ASC
");

echo '<div class="info">';
echo '<h3>📦 Posts 資料表（wp_posts）</h3>';
echo '找到 <strong>' . count($products) . '</strong> 個 fluent-products 類型的商品';
echo '</div>';

if (empty($products)) {
    echo '<div class="warning">⚠️ 沒有找到任何 fluent-products</div>';
} else {
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>ID</th>';
    echo '<th>商品名稱</th>';
    echo '<th>狀態</th>';
    echo '<th>建立時間</th>';
    echo '<th>FluentCart Details</th>';
    echo '<th>FluentCart Variations</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    
    foreach ($products as $product) {
        $productId = $product->ID;
        
        // 檢查 fct_product_details
        $detail = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}fct_product_details 
            WHERE post_id = %d
        ", $productId));
        
        // 檢查 fct_product_variations
        $variations = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}fct_product_variations 
            WHERE post_id = %d
        ", $productId));
        
        echo '<tr>';
        echo '<td>' . $productId . '</td>';
        echo '<td>' . esc_html($product->post_title) . '</td>';
        echo '<td>' . $product->post_status . '</td>';
        echo '<td>' . date('Y-m-d H:i', strtotime($product->post_date)) . '</td>';
        
        // FluentCart Details 狀態
        if ($detail) {
            echo '<td class="status-ok">✅ 存在';
            if (isset($detail->min_price)) {
                echo '<br>價格: ' . number_format($detail->min_price / 100, 2);
            }
            echo '</td>';
        } else {
            echo '<td class="status-missing">❌ 缺失</td>';
        }
        
        // FluentCart Variations 狀態
        if (!empty($variations)) {
            echo '<td class="status-ok">✅ ' . count($variations) . ' 個變體';
            if (isset($variations[0]->item_price)) {
                echo '<br>價格: ' . number_format($variations[0]->item_price / 100, 2);
            }
            echo '</td>';
        } else {
            echo '<td class="status-missing">❌ 缺失</td>';
        }
        
        echo '</tr>';
    }
    
    echo '</tbody></table>';
}

// 2. 總結統計
$totalProducts = count($products);
$productsWithDetails = $wpdb->get_var("
    SELECT COUNT(DISTINCT post_id) 
    FROM {$wpdb->prefix}fct_product_details 
    WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'fluent-products')
");
$productsWithVariations = $wpdb->get_var("
    SELECT COUNT(DISTINCT post_id) 
    FROM {$wpdb->prefix}fct_product_variations 
    WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'fluent-products')
");

echo '<div class="info">';
echo '<h3>📊 統計摘要</h3>';
echo '<ul>';
echo '<li>總商品數（wp_posts）: <strong>' . $totalProducts . '</strong></li>';
echo '<li>有 FluentCart Details 的商品: <strong>' . $productsWithDetails . '</strong></li>';
echo '<li>有 FluentCart Variations 的商品: <strong>' . $productsWithVariations . '</strong></li>';
echo '<li>缺少 Details 的商品: <strong>' . ($totalProducts - $productsWithDetails) . '</strong></li>';
echo '<li>缺少 Variations 的商品: <strong>' . ($totalProducts - $productsWithVariations) . '</strong></li>';
echo '</ul>';
echo '</div>';

// 3. 診斷建議
if ($totalProducts > 0 && ($productsWithDetails == 0 || $productsWithVariations == 0)) {
    echo '<div class="warning">';
    echo '<h3>⚠️ 診斷結果</h3>';
    echo '<p><strong>問題：</strong>資料不同步！</p>';
    echo '<p>這些商品存在於 wp_posts 資料表，但缺少 FluentCart 的相關資料（fct_product_details 或 fct_product_variations）。</p>';
    echo '<p><strong>可能原因：</strong></p>';
    echo '<ul>';
    echo '<li>商品是直接寫入資料庫，沒有透過 FluentCart API 建立</li>';
    echo '<li>商品建立時發生錯誤，沒有正確完成同步</li>';
    echo '<li>資料表結構不完整</li>';
    echo '</ul>';
    echo '<p><strong>建議解決方案：</strong></p>';
    echo '<ul>';
    echo '<li>使用批次刪除工具清除這些殘留商品</li>';
    echo '<li>重新透過正常流程（LINE 上架或後台手動建立）建立商品</li>';
    echo '</ul>';
    echo '</div>';
}

?>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd;">
            <p style="color: #666;">
                檔案位置：<code><?php echo __FILE__; ?></code>
            </p>
        </div>
    </div>
</body>
</html>
