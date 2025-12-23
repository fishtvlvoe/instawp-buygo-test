<?php
/**
 * 批次清理孤兒商品資料腳本
 * 
 * 專門清理 FluentCart 資料表中的孤兒資料（wp_posts 中已不存在的商品）
 * 
 * 使用方式：
 * 1. 在瀏覽器訪問：https://test.buygo.me/wp-content/plugins/buygo/delete-orphan-products.php?password=delete123
 * 
 * 安全提示：
 * - 使用完畢後請刪除此檔案
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
    <title>批次清理孤兒商品資料</title>
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
        .warning-box {
            background: #fff3cd;
            color: #856404;
            padding: 20px;
            border-radius: 4px;
            margin: 20px 0;
            border-left: 4px solid #ffc107;
        }
        .info-box {
            background: #d1ecf1;
            color: #0c5460;
            padding: 20px;
            border-radius: 4px;
            margin: 20px 0;
            border-left: 4px solid #17a2b8;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
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
            padding: 12px 24px;
            background: #dc3545;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
        }
        .btn:hover {
            background: #c82333;
        }
        .checkbox {
            width: 20px;
            height: 20px;
        }
        .actions {
            margin: 20px 0;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #007bff;
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #007bff;
        }
        .stat-label {
            color: #6c757d;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗑️ 批次清理孤兒商品資料</h1>

        <div class="warning-box">
            <h3>⚠️ 什麼是孤兒資料？</h3>
            <p><strong>孤兒資料（Orphaned Data）</strong>是指 FluentCart 資料表中存在，但對應的 WordPress 文章（wp_posts）已經不存在的商品資料。</p>
            <p>這些資料會導致：</p>
            <ul>
                <li>❌ BuyGo 後台顯示空白商品（沒有名稱、圖片）</li>
                <li>❌ FluentCart 後台無法顯示這些商品</li>
                <li>❌ 資料庫佔用不必要的空間</li>
            </ul>
            <p><strong>此工具會安全地清理這些孤兒資料。</strong></p>
        </div>

<?php

// 處理刪除請求
if (isset($_POST['delete_orphans'])) {
    $orphanIds = $_POST['orphan_ids'] ?? [];
    
    if (empty($orphanIds)) {
        echo '<div class="warning-box">⚠️ 請選擇要刪除的孤兒資料</div>';
    } else {
        echo '<div class="success">';
        echo '<h3>🔄 清理結果：</h3>';
        
        $deletedDetails = 0;
        $deletedVariations = 0;
        $failedCount = 0;
        
        foreach ($orphanIds as $postId) {
            $postId = intval($postId);
            
            echo "🔄 正在清理 Post ID: {$postId}...<br>";
            
            try {
                // 1. 刪除 fct_product_details
                $detailsDeleted = $wpdb->delete(
                    $wpdb->prefix . 'fct_product_details',
                    ['post_id' => $postId],
                    ['%d']
                );
                
                if ($detailsDeleted) {
                    echo "  ✅ 已刪除 Details 資料<br>";
                    $deletedDetails++;
                }
                
                // 2. 刪除 fct_product_variations
                $variationsDeleted = $wpdb->delete(
                    $wpdb->prefix . 'fct_product_variations',
                    ['post_id' => $postId],
                    ['%d']
                );
                
                if ($variationsDeleted) {
                    echo "  ✅ 已刪除 {$variationsDeleted} 個 Variations 資料<br>";
                    $deletedVariations += $variationsDeleted;
                }
                
                // 3. 刪除所有可能的 postmeta（如果有的話）
                $wpdb->delete($wpdb->postmeta, ['post_id' => $postId], ['%d']);
                
                echo "  ✅ 清理完成！<br><br>";
                
            } catch (\Exception $e) {
                echo "  ❌ 清理失敗: " . $e->getMessage() . "<br><br>";
                $failedCount++;
            }
        }
        
        echo "<hr>";
        echo "<strong>總計：</strong><br>";
        echo "✅ 成功清理 Details: {$deletedDetails} 筆<br>";
        echo "✅ 成功清理 Variations: {$deletedVariations} 筆<br>";
        echo "❌ 失敗: {$failedCount} 個<br>";
        echo '</div>';
        
        echo '<div class="actions">';
        echo '<a href="' . $_SERVER['PHP_SELF'] . '?password=' . ADMIN_PASSWORD . '" class="btn" style="background: #28a745;">🔄 重新整理頁面</a>';
        echo '</div>';
    }
}

// 查詢所有孤兒資料
$orphanDetails = $wpdb->get_results("
    SELECT pd.*
    FROM {$wpdb->prefix}fct_product_details pd
    LEFT JOIN {$wpdb->posts} p ON pd.post_id = p.ID
    WHERE p.ID IS NULL
    ORDER BY pd.post_id ASC
");

if (empty($orphanDetails)) {
    echo '<div class="info-box">';
    echo '<h3>✅ 太好了！沒有找到任何孤兒資料</h3>';
    echo '<p>您的資料庫很乾淨，所有 FluentCart 商品都有對應的 WordPress 文章。</p>';
    echo '</div>';
} else {
    $totalOrphans = count($orphanDetails);
    
    // 統計孤兒變體數量
    $orphanPostIds = array_column($orphanDetails, 'post_id');
    $orphanPostIdsStr = implode(',', $orphanPostIds);
    $totalOrphanVariations = $wpdb->get_var("
        SELECT COUNT(*) 
        FROM {$wpdb->prefix}fct_product_variations 
        WHERE post_id IN ({$orphanPostIdsStr})
    ");
    
    ?>
    <div class="stats">
        <div class="stat-card">
            <div class="stat-number"><?php echo $totalOrphans; ?></div>
            <div class="stat-label">孤兒 Details 資料</div>
        </div>
        <div class="stat-card" style="border-left-color: #dc3545;">
            <div class="stat-number" style="color: #dc3545;"><?php echo $totalOrphanVariations; ?></div>
            <div class="stat-label">孤兒 Variations 資料</div>
        </div>
    </div>
    
    <form method="POST" id="deleteForm">
        <div class="actions">
            <label>
                <input type="checkbox" id="select-all" class="checkbox">
                <strong>全選</strong>
            </label>
            <button type="submit" name="delete_orphans" class="btn" id="deleteBtn">
                🗑️ 刪除選取的孤兒資料（確認後執行）
            </button>
            <input type="hidden" name="confirmed" id="confirmedInput" value="no">
        </div>
        
        <div id="confirmBox" style="display: none; background: #fff3cd; padding: 20px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #ffc107;">
            <h3>⚠️ 確認刪除</h3>
            <p>你即將刪除選取的孤兒資料，此操作將：</p>
            <ul>
                <li>刪除 <strong>fct_product_details</strong> 資料</li>
                <li>刪除 <strong>fct_product_variations</strong> 資料</li>
                <li>刪除相關的 <strong>postmeta</strong> 資料</li>
            </ul>
            <p style="color: #dc3545; font-weight: bold;">⚠️ 此操作無法復原！</p>
            <div style="margin-top: 15px;">
                <button type="button" class="btn" style="background: #28a745;" onclick="confirmDelete()">✅ 我確定要刪除</button>
                <button type="button" class="btn" style="background: #6c757d;" onclick="cancelDelete()">❌ 取消</button>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">選取</th>
                    <th>Post ID</th>
                    <th>價格範圍（分）</th>
                    <th>Stock</th>
                    <th>Type</th>
                    <th>建立時間</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orphanDetails as $detail): ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="orphan_ids[]" value="<?php echo $detail->post_id; ?>" class="orphan-checkbox checkbox">
                        </td>
                        <td><strong><?php echo $detail->post_id; ?></strong></td>
                        <td>
                            <?php 
                            if ($detail->min_price !== null) {
                                if ($detail->min_price === $detail->max_price) {
                                    echo number_format($detail->min_price);
                                } else {
                                    echo number_format($detail->min_price) . ' - ' . number_format($detail->max_price);
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td><?php echo $detail->stock_status ?? '-'; ?></td>
                        <td><?php echo $detail->type ?? '-'; ?></td>
                        <td><?php echo $detail->created_at ? date('Y-m-d H:i', strtotime($detail->created_at)) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </form>

    <script>
        // 全選功能
        document.getElementById('select-all').addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.orphan-checkbox');
            checkboxes.forEach(cb => cb.checked = e.target.checked);
        });

        // 兩步驟確認流程
        document.getElementById('deleteForm').addEventListener('submit', function(e) {
            const confirmed = document.getElementById('confirmedInput').value;

            if (confirmed !== 'yes') {
                e.preventDefault();

                // 檢查是否有勾選任何項目
                const checkedBoxes = document.querySelectorAll('.orphan-checkbox:checked');
                if (checkedBoxes.length === 0) {
                    alert('請至少選擇一個孤兒資料');
                    return;
                }

                // 顯示確認框
                document.getElementById('confirmBox').style.display = 'block';
                document.getElementById('confirmBox').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        function confirmDelete() {
            document.getElementById('confirmedInput').value = 'yes';
            document.getElementById('confirmBox').style.display = 'none';
            document.getElementById('deleteForm').submit();
        }

        function cancelDelete() {
            document.getElementById('confirmBox').style.display = 'none';
            document.getElementById('confirmedInput').value = 'no';
        }
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
