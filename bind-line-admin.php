<?php
/**
 * 一鍵綁定 LINE 帳號到 WordPress 管理員
 * 
 * 訪問方式：https://test.buygo.me/wp-content/plugins/buygo/bind-line-admin.php?password=delete123
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

// 取得當前登入的管理員
$currentUser = wp_get_current_user();

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>一鍵綁定 LINE 帳號</title>
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
            font-size: 16px;
        }
        .btn:hover {
            background: #0056b3;
        }
        .info-box {
            background: #e7f3ff;
            border: 1px solid #b3d7ff;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔗 一鍵綁定 LINE 帳號</h1>
        
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <?php
            $action = $_POST['action'] ?? '';
            
            if ($action === 'bind_line' && $lineUid && $currentUser->ID) {
                // 綁定 LINE UID 到當前用戶
                update_user_meta($currentUser->ID, '_mygo_line_uid', $lineUid);
                
                // 同時添加「賣家」角色
                update_user_meta($currentUser->ID, '_mygo_role', 'seller');
                
                echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 4px; margin: 20px 0;">';
                echo '<h2 class="status-ok">✅ 綁定成功！</h2>';
                echo '<p>✅ LINE UID 已綁定到你的帳號</p>';
                echo '<p>✅ 已添加「賣家」角色</p>';
                echo '<p style="margin-top: 20px;"><strong>現在可以到 LINE 測試上傳商品了！</strong></p>';
                echo '</div>';
                
                // 重新查詢
                $currentLineUid = get_user_meta($currentUser->ID, '_mygo_line_uid', true);
                $currentRole = get_user_meta($currentUser->ID, '_mygo_role', true);
            }
            ?>
        <?php endif; ?>
        
        <h2>📋 目前狀態</h2>
        
        <div class="info-box">
            <p><strong>要綁定的 LINE UID：</strong></p>
            <p><code><?php echo $lineUid; ?></code></p>
        </div>
        
        <div class="info-box">
            <p><strong>WordPress 用戶：</strong></p>
            <p>用戶名稱：<?php echo $currentUser->display_name; ?></p>
            <p>Email：<?php echo $currentUser->user_email; ?></p>
            <p>WordPress 角色：<?php echo implode(', ', $currentUser->roles); ?></p>
        </div>
        
        <?php
        $currentLineUid = get_user_meta($currentUser->ID, '_mygo_line_uid', true);
        $currentRole = get_user_meta($currentUser->ID, '_mygo_role', true);
        ?>
        
        <?php if ($currentLineUid === $lineUid && $currentRole === 'seller'): ?>
            <div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 4px; margin: 20px 0;">
                <p class="status-ok">✅ 已完成綁定！</p>
                <p><strong>綁定的 LINE UID：</strong><code><?php echo $currentLineUid; ?></code></p>
                <p><strong>BuyGo 角色：</strong><?php echo $currentRole; ?></p>
                <p style="margin-top: 15px;">現在可以到 LINE 測試上傳商品了！</p>
            </div>
        <?php else: ?>
            <form method="POST" style="margin-top: 20px;">
                <input type="hidden" name="action" value="bind_line">
                <p>點擊下方按鈕，將 LINE UID <code><?php echo $lineUid; ?></code> 綁定到你的帳號：</p>
                <button type="submit" class="btn">🔗 立即綁定並添加賣家權限</button>
            </form>
        <?php endif; ?>
        
        <hr style="margin: 40px 0;">
        
        <p style="color: #666;">
            <strong>注意：</strong>這個腳本使用完畢後請刪除或停用，以確保安全。
        </p>
    </div>
</body>
</html>
