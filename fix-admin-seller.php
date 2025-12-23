<?php
/**
 * 快速修復腳本：給管理員添加賣家角色
 * 
 * 訪問方式：https://test.buygo.me/wp-content/plugins/buygo/fix-admin-seller.php?password=delete123
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

// 你的 LINE UID（從日誌中取得）
$lineUid = 'U823e48d899eb99be6fb49d53609048d9';

// 找到對應的 WordPress 用戶
global $wpdb;
$wpUserId = $wpdb->get_var($wpdb->prepare(
    "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_mygo_line_uid' AND meta_value = %s",
    $lineUid
));

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>快速修復：賣家權限</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 800px;
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
        .status-ok { color: #28a745; font-weight: bold; }
        .status-error { color: #dc3545; font-weight: bold; }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin: 10px 5px 10px 0;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background: #0056b3;
        }
        pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 快速修復：賣家權限</h1>
        
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <?php
            $action = $_POST['action'] ?? '';
            
            if ($action === 'add_seller_role' && $wpUserId) {
                // 添加賣家角色
                update_user_meta($wpUserId, '_mygo_role', 'seller');
                echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 4px; margin: 20px 0;">';
                echo '<p class="status-ok">✅ 成功！已將「賣家」角色添加到你的帳號。</p>';
                echo '<p>現在可以到 LINE 測試上傳商品了！</p>';
                echo '</div>';
                
                // 重新查詢
                $currentRole = get_user_meta($wpUserId, '_mygo_role', true);
                $user = get_user_by('ID', $wpUserId);
            }
            ?>
        <?php endif; ?>
        
        <h2>📋 目前狀態</h2>
        
        <p><strong>LINE UID：</strong><code><?php echo $lineUid; ?></code></p>
        
        <?php if ($wpUserId): ?>
            <?php 
            $user = get_user_by('ID', $wpUserId);
            $currentRole = get_user_meta($wpUserId, '_mygo_role', true);
            ?>
            <p class="status-ok">✅ 已找到對應的 WordPress 用戶</p>
            <p><strong>用戶 ID：</strong><?php echo $wpUserId; ?></p>
            <p><strong>用戶名稱：</strong><?php echo $user->display_name; ?></p>
            <p><strong>Email：</strong><?php echo $user->user_email; ?></p>
            <p><strong>WordPress 角色：</strong><?php echo implode(', ', $user->roles); ?></p>
            <p><strong>BuyGo 自定義角色：</strong><?php echo $currentRole ?: '（未設定）'; ?></p>
            
            <?php if ($currentRole === 'seller' || in_array('administrator', $user->roles)): ?>
                <p class="status-ok">✅ 你已經有權限！可以到 LINE 測試上傳商品了。</p>
            <?php else: ?>
                <form method="POST" style="margin-top: 20px;">
                    <input type="hidden" name="action" value="add_seller_role">
                    <button type="submit" class="btn">🛠️ 添加「賣家」角色</button>
                </form>
            <?php endif; ?>
            
        <?php else: ?>
            <p class="status-error">❌ 找不到對應的 WordPress 用戶</p>
            <p>請確認你的 LINE 帳號是否已在網站完成綁定</p>
            <p>綁定網址：<a href="<?php echo home_url('/line-bind'); ?>"><?php echo home_url('/line-bind'); ?></a></p>
        <?php endif; ?>
        
        <hr style="margin: 40px 0;">
        
        <p style="color: #666;">
            <strong>注意：</strong>這個腳本修復後請刪除或停用，以確保安全。
        </p>
    </div>
</body>
</html>
