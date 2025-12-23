<?php
/**
 * 批次刪除測試商品腳本
 * 
 * 使用方式：
 * 1. 在瀏覽器訪問：https://test.buygo.me/wp-content/plugins/buygo/delete-test-products.php?password=delete123
 * 2. 或在終端執行：php delete-test-products.php
 * 
 * 安全提示：
 * - 使用完畢後請刪除此檔案
 * - 或設定密碼保護（見下方 ADMIN_PASSWORD）
 */

// 設定密碼保護（留空則不需要密碼）
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

// 輸出 HTML 頭部
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>批次刪除測試商品</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1200px;
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
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #e74c3c;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .btn:hover {
            background: #c0392b;
        }
        .btn-primary {
            background: #3498db;
        }
        .btn-primary:hover {
            background: #2980b9;
        }
        .success {
            background: #d4edda;
            color: #155724;
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
        .checkbox {
            width: 20px;
            height: 20px;
        }
        .actions {
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗑️ 批次刪除測試商品</h1>

<?php

// 處理刪除請求
if (isset($_POST['delete_products'])) {
    $productIds = $_POST['product_ids'] ?? [];
    
    if (empty($productIds)) {
        echo '<div class="warning">⚠️ 請選擇要刪除的商品</div>';
    } else {
        echo '<div class="success">';
        echo '<h3>刪除結果：</h3>';
        
        $cartService = new \Mygo\Services\FluentCartService();
        $deletedCount = 0;
        $failedCount = 0;
        
        foreach ($productIds as $productId) {
            $productId = intval($productId);
            $product = get_post($productId);
            
            if (!$product) {
                echo "❌ 商品 ID {$productId} 不存在<br>";
                $failedCount++;
                continue;
            }
            
            echo "🔄 正在刪除：{$product->post_title} (ID: {$productId})...<br>";
            
            try {
                // 1. 取得相關資訊
                $feedId = get_post_meta($productId, '_mygo_feed_id', true);
                $imageId = get_post_meta($productId, '_mygo_image_id', true);
                
                // 2. 刪除 FluentCart 商品
                $cartDeleted = $cartService->deleteProduct($productId);
                
                // 3. 刪除 FluentCommunity 貼文
                if ($feedId && class_exists('\\FluentCommunity\\App\\Models\\Feed')) {
                    try {
                        $feed = \FluentCommunity\App\Models\Feed::find($feedId);
                        if ($feed) {
                            $feed->delete();
                            echo "  ✅ 已刪除社群貼文 (Feed ID: {$feedId})<br>";
                        }
                    } catch (\Exception $e) {
                        echo "  ⚠️ 刪除社群貼文失敗: " . $e->getMessage() . "<br>";
                    }
                }
                
                // 4. 刪除商品圖片
                if ($imageId) {
                    wp_delete_attachment($imageId, true);
                    echo "  ✅ 已刪除圖片 (Attachment ID: {$imageId})<br>";
                }
                
                // 5. 刪除所有 post meta
                global $wpdb;
                $wpdb->delete($wpdb->postmeta, ['post_id' => $productId], ['%d']);
                
                if ($cartDeleted) {
                    echo "  ✅ 刪除成功！<br><br>";
                    $deletedCount++;
                } else {
                    echo "  ❌ FluentCart 刪除失敗<br><br>";
                    $failedCount++;
                }
                
            } catch (\Exception $e) {
                echo "  ❌ 刪除失敗: " . $e->getMessage() . "<br><br>";
                $failedCount++;
            }
        }
        
        echo "<hr>";
        echo "<strong>總計：</strong> 成功 {$deletedCount} 個，失敗 {$failedCount} 個";
        echo '</div>';
    }
}

// 查詢所有 FluentCart 商品
global $wpdb;
$products = $wpdb->get_results("
    SELECT p.ID, p.post_title, p.post_status, p.post_date,
           pd.min_price, pd.max_price
    FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->prefix}fct_product_details pd ON p.ID = pd.post_id
    WHERE p.post_type = 'fluent-products'
    ORDER BY p.ID ASC
");

if (empty($products)) {
    echo '<div class="warning">📦 沒有找到任何商品</div>';
} else {
    ?>
    <form method="POST">
        <div class="actions">
            <label>
                <input type="checkbox" id="select-all" class="checkbox">
                全選
            </label>
            <button type="submit" name="delete_products" class="btn" onclick="return confirm('確定要刪除選取的商品嗎？此操作無法復原！')">
                🗑️ 刪除選取的商品
            </button>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">選取</th>
                    <th>ID</th>
                    <th>商品名稱</th>
                    <th>價格範圍</th>
                    <th>狀態</th>
                    <th>建立日期</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="product_ids[]" value="<?php echo $product->ID; ?>" class="product-checkbox checkbox">
                        </td>
                        <td><?php echo $product->ID; ?></td>
                        <td><?php echo esc_html($product->post_title); ?></td>
                        <td>
                            <?php 
                            if ($product->min_price !== null) {
                                $minPrice = number_format($product->min_price / 100, 0);
                                $maxPrice = number_format($product->max_price / 100, 0);
                                if ($minPrice === $maxPrice) {
                                    echo "NT$ {$minPrice}";
                                } else {
                                    echo "NT$ {$minPrice} - {$maxPrice}";
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td><?php echo $product->post_status; ?></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($product->post_date)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="actions">
            <button type="submit" name="delete_products" class="btn" onclick="return confirm('確定要刪除選取的商品嗎？此操作無法復原！')">
                🗑️ 刪除選取的商品
            </button>
        </div>
    </form>
    
    <script>
        // 全選功能
        document.getElementById('select-all').addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.product-checkbox');
            checkboxes.forEach(cb => cb.checked = e.target.checked);
        });
    </script>
    <?php
}
?>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd;">
            <p style="color: #666;">
                <strong>⚠️ 使用完畢後請記得刪除此檔案，以確保安全。</strong><br>
                檔案位置：<code><?php echo __FILE__; ?></code>
            </p>
        </div>
    </div>
</body>
</html>
