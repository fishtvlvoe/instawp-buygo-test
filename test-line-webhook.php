<?php
/**
 * LINE Webhook 診斷工具
 * 
 * 使用方式：
 * 訪問：https://buygo.me/wp-content/plugins/buygo/test-line-webhook.php?password=delete123
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

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LINE Webhook 診斷工具</title>
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
        h1 { color: #333; }
        h2 { color: #555; margin-top: 30px; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .test-item {
            background: #f8f9fa;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
            border-left: 4px solid #007bff;
        }
        .status-ok {
            color: #28a745;
            font-weight: bold;
        }
        .status-error {
            color: #dc3545;
            font-weight: bold;
        }
        .status-warning {
            color: #ffc107;
            font-weight: bold;
        }
        pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 13px;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin: 10px 5px 10px 0;
        }
        .btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 LINE Webhook 診斷工具</h1>

        <h2>1️⃣ Webhook URL 設定檢查</h2>
        
        <?php
        $siteUrl = get_site_url();
        $webhookUrl = $siteUrl . '/wp-json/mygo/v1/line-webhook';
        ?>
        
        <div class="test-item">
            <strong>正確的 Webhook URL：</strong><br>
            <code><?php echo $webhookUrl; ?></code>
            <p style="margin-top: 10px;">請在 <a href="https://developers.line.biz/console/" target="_blank">LINE Developers Console</a> 確認 Webhook URL 設定為此網址</p>
        </div>

        <h2>2️⃣ WordPress REST API 檢查</h2>
        
        <?php
        // 檢查 REST API 是否可用
        $restUrl = rest_url();
        ?>
        
        <div class="test-item">
            <strong>WordPress REST API 基礎 URL：</strong><br>
            <code><?php echo $restUrl; ?></code>
            
            <?php if ($restUrl): ?>
                <p class="status-ok">✅ REST API 已啟用</p>
            <?php else: ?>
                <p class="status-error">❌ REST API 未啟用</p>
            <?php endif; ?>
        </div>

        <h2>3️⃣ LINE 設定檢查</h2>
        
        <?php
        $accessToken = get_option('mygo_line_channel_access_token', '');
        $channelSecret = get_option('mygo_line_channel_secret', '');
        ?>
        
        <div class="test-item">
            <strong>Channel Access Token：</strong>
            <?php if (!empty($accessToken)): ?>
                <span class="status-ok">✅ 已設定</span>
                <p style="font-size: 12px; color: #666;">長度：<?php echo strlen($accessToken); ?> 字元</p>
            <?php else: ?>
                <span class="status-error">❌ 未設定</span>
            <?php endif; ?>
        </div>

        <div class="test-item">
            <strong>Channel Secret：</strong>
            <?php if (!empty($channelSecret)): ?>
                <span class="status-ok">✅ 已設定</span>
                <p style="font-size: 12px; color: #666;">長度：<?php echo strlen($channelSecret); ?> 字元</p>
            <?php else: ?>
                <span class="status-error">❌ 未設定</span>
            <?php endif; ?>
        </div>

        <h2>4️⃣ Webhook Endpoint 測試</h2>
        
        <?php
        // 測試 Webhook endpoint 是否可訪問
        $webhookTestUrl = $siteUrl . '/wp-json/mygo/v1/line-webhook';
        ?>
        
        <div class="test-item">
            <p>測試 Webhook endpoint 是否存在...</p>
            
            <?php
            $response = wp_remote_get($webhookTestUrl);
            $statusCode = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            ?>
            
            <strong>測試結果：</strong><br>
            <p>狀態碼：<code><?php echo $statusCode; ?></code></p>
            
            <?php if ($statusCode == 405): ?>
                <p class="status-ok">✅ Endpoint 存在（405 = Method Not Allowed，這是正常的，因為 GET 請求不被允許）</p>
                <p style="color: #28a745;">✅ Webhook URL 正確，LINE 應該可以訪問！</p>
            <?php elseif ($statusCode == 404): ?>
                <p class="status-error">❌ Endpoint 不存在（404 Not Found）</p>
                <p style="color: #dc3545;">❌ 這就是 LINE 回報 404 的原因！</p>
            <?php elseif ($statusCode == 200): ?>
                <p class="status-ok">✅ Endpoint 存在</p>
                <p>回應內容：</p>
                <pre><?php echo esc_html(substr($body, 0, 500)); ?></pre>
            <?php else: ?>
                <p class="status-warning">⚠️ 狀態碼異常：<?php echo $statusCode; ?></p>
                <p>回應內容：</p>
                <pre><?php echo esc_html(substr($body, 0, 500)); ?></pre>
            <?php endif; ?>
        </div>

        <h2>5️⃣ 註冊的 REST API 路由檢查</h2>
        
        <div class="test-item">
            <?php
            $routes = rest_get_server()->get_routes();
            $targetRoutes = [];
            
            foreach ($routes as $route => $endpoints) {
                if (strpos($route, '/buygo/') !== false) {
                    $targetRoutes[$route] = $endpoints;
                }
            }
            ?>
            
            <strong>已註冊的 buygo/* 路由：</strong>
            <?php if (!empty($targetRoutes)): ?>
                <ul>
                    <?php foreach ($targetRoutes as $route => $endpoints): ?>
                        <li>
                            <code><?php echo $route; ?></code>
                            <?php if ($route === '/buygo/v1/line-webhook'): ?>
                                <span class="status-ok">✅ LINE Webhook 路由已註冊！</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="status-error">❌ 沒有找到任何 buygo/* 路由！</p>
                <p>這表示 REST API 路由沒有正確註冊。</p>
            <?php endif; ?>
        </div>

        <h2>6️⃣ Permalink 設定檢查</h2>
        
        <div class="test-item">
            <?php
            $permalinkStructure = get_option('permalink_structure');
            ?>
            
            <strong>Permalink 結構：</strong>
            <?php if (!empty($permalinkStructure)): ?>
                <span class="status-ok">✅ 已設定</span>
                <p><code><?php echo $permalinkStructure; ?></code></p>
            <?php else: ?>
                <span class="status-error">❌ 使用預設結構（Plain）</span>
                <p style="color: #dc3545;">⚠️ REST API 可能無法正常運作！請到後台設定 → 固定網址，設定為「文章名稱」或其他結構。</p>
            <?php endif; ?>
        </div>

        <h2>7️⃣ 模擬 LINE Webhook 測試</h2>
        
        <div class="test-item">
            <p>你可以手動發送測試 Webhook 請求：</p>
            <button class="btn" onclick="testWebhook()">📤 發送測試 Webhook</button>
            <div id="testResult" style="margin-top: 15px;"></div>
        </div>

        <h2>📝 診斷摘要與建議</h2>
        
        <div class="test-item" style="border-left-color: #28a745;">
            <?php
            $issues = [];
            
            if (empty($accessToken)) {
                $issues[] = '❌ Channel Access Token 未設定';
            }
            if (empty($channelSecret)) {
                $issues[] = '❌ Channel Secret 未設定';
            }
            if (empty($permalinkStructure)) {
                $issues[] = '❌ Permalink 結構未設定（使用 Plain）';
            }
            if ($statusCode == 404) {
                $issues[] = '❌ Webhook Endpoint 不存在（404）';
            }
            if (empty($mygoRoutes)) {
                $issues[] = '❌ REST API 路由未註冊';
            }
            ?>
            
            <?php if (empty($issues)): ?>
                <h3 style="color: #28a745;">✅ 所有檢查都通過！</h3>
                <p>Webhook 設定看起來正常。如果 LINE 仍然回報 404，請檢查：</p>
                <ul>
                    <li>LINE Developers Console 的 Webhook URL 是否正確</li>
                    <li>是否有防火牆或 CDN 阻擋 LINE 的請求</li>
                    <li>伺服器的 URL Rewrite 規則是否正確</li>
                </ul>
            <?php else: ?>
                <h3 style="color: #dc3545;">發現以下問題：</h3>
                <ul>
                    <?php foreach ($issues as $issue): ?>
                        <li><?php echo $issue; ?></li>
                    <?php endforeach; ?>
                </ul>
                
                <h4>建議修復步驟：</h4>
                <ol>
                    <?php if (empty($permalinkStructure)): ?>
                        <li>到 WordPress 後台 → 設定 → 固定網址 → 選擇「文章名稱」</li>
                    <?php endif; ?>
                    <?php if (empty($accessToken) || empty($channelSecret)): ?>
                        <li>到 WordPress 後台 → BuyGo Core → 設定 → 填寫 LINE Channel Access Token 和 Secret</li>
                    <?php endif; ?>
                    <?php if (empty($mygoRoutes)): ?>
                        <li>嘗試停用並重新啟用 BuyGo 外掛</li>
                        <li>或到 WordPress 後台 → 設定 → 固定網址 → 點擊「儲存變更」（刷新 Rewrite Rules）</li>
                    <?php endif; ?>
                </ol>
            <?php endif; ?>
        </div>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd;">
            <p style="color: #666;">
                檔案位置：<code><?php echo __FILE__; ?></code>
            </p>
        </div>
    </div>

    <script>
        async function testWebhook() {
            const resultDiv = document.getElementById('testResult');
            resultDiv.innerHTML = '⏳ 正在發送測試請求...';
            
            try {
                const response = await fetch('<?php echo $webhookTestUrl; ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        events: [{
                            type: 'message',
                            message: {
                                type: 'text',
                                text: 'test'
                            },
                            source: {
                                userId: 'TEST_USER'
                            },
                            replyToken: 'TEST_TOKEN'
                        }]
                    })
                });
                
                const data = await response.json();
                
                resultDiv.innerHTML = `
                    <strong>測試結果：</strong><br>
                    狀態碼：<code>${response.status}</code><br>
                    回應：<pre>${JSON.stringify(data, null, 2)}</pre>
                `;
                
                if (response.status === 200) {
                    resultDiv.innerHTML += '<p class="status-ok">✅ Webhook 可以正常接收請求！</p>';
                }
            } catch (error) {
                resultDiv.innerHTML = `<p class="status-error">❌ 錯誤：${error.message}</p>`;
            }
        }
    </script>
</body>
</html>
