<?php

namespace BuyGo\Core\Frontend;

use BuyGo\Core\Services\DebugService;

/**
 * FluentCart 即時同步前端載入器
 * 
 * 負責在 FluentCart 頁面載入即時同步 JavaScript
 */
class FluentCartRealtimeSync
{
    private $debugService;

    public function __construct()
    {
        $this->debugService = new DebugService();
        $this->init();
    }

    private function init()
    {
        // 在前端載入腳本
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // 在 FluentCart 頁面載入
        add_action('wp_footer', [$this, 'load_on_fluentcart_pages']);
        
        $this->debugService->log('FluentCartRealtimeSync', '前端載入器初始化完成');
    }

    /**
     * 載入前端腳本
     */
    public function enqueue_scripts()
    {
        // 檢查是否為 FluentCart 相關頁面
        if ($this->is_fluentcart_page()) {
            wp_enqueue_script(
                'buygo-fluentcart-sync',
                plugin_dir_url(dirname(__DIR__)) . 'resources/frontend/js/fluentcart-realtime-sync.js',
                ['jquery'],
                '1.0.0',
                true
            );

            // 傳遞配置到前端
            wp_localize_script('buygo-fluentcart-sync', 'buygoFluentCartConfig', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'restUrl' => rest_url('buygo/v1/'),
                'webhookUrl' => home_url('/buygo-fluentcart-webhook.php'),
                'nonce' => wp_create_nonce('buygo_fluentcart_sync'),
                'debug' => defined('WP_DEBUG') && WP_DEBUG
            ]);

            $this->debugService->log('FluentCartRealtimeSync', '前端腳本已載入', [
                'page' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);
        }
    }

    /**
     * 在 FluentCart 頁面載入額外的初始化代碼
     */
    public function load_on_fluentcart_pages()
    {
        if ($this->is_fluentcart_page()) {
            ?>
            <script type="text/javascript">
            // 確保 FluentCart 完全載入後再初始化
            document.addEventListener('DOMContentLoaded', function() {
                // 延遲載入，確保 FluentCart 的動態內容已載入
                setTimeout(function() {
                    if (typeof window.buygoFluentCartSync !== 'undefined') {
                        console.log('[BuyGo] FluentCart 即時同步已啟動');
                    } else {
                        console.log('[BuyGo] 等待 FluentCart 即時同步載入...');
                        
                        // 如果主腳本還沒載入，再等一下
                        setTimeout(function() {
                            if (typeof window.buygoFluentCartSync !== 'undefined') {
                                console.log('[BuyGo] FluentCart 即時同步延遲啟動成功');
                            }
                        }, 2000);
                    }
                }, 1000);
            });

            // 監聽 FluentCart 的自定義事件（如果有的話）
            document.addEventListener('fluentcart:loaded', function() {
                console.log('[BuyGo] FluentCart 載入完成，重新初始化同步');
                if (typeof window.buygoFluentCartSync !== 'undefined' && 
                    typeof window.buygoFluentCartSync.reinitialize === 'function') {
                    window.buygoFluentCartSync.reinitialize();
                }
            });
            </script>
            <?php
        }
    }

    /**
     * 檢查是否為 FluentCart 相關頁面
     */
    private function is_fluentcart_page(): bool
    {
        // 檢查多種可能的 FluentCart 頁面標識
        $indicators = [
            // URL 包含關鍵字
            'checkout',
            'cart',
            'fluent-cart',
            'fluentcart',
            // 查詢參數
            'fct_checkout',
            'fluent_checkout'
        ];

        $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
        $queryString = $_SERVER['QUERY_STRING'] ?? '';

        foreach ($indicators as $indicator) {
            if (strpos($currentUrl, $indicator) !== false || 
                strpos($queryString, $indicator) !== false) {
                return true;
            }
        }

        // 檢查是否有 FluentCart 的 shortcode 或內容
        global $post;
        if ($post && (
            strpos($post->post_content, '[fluent_cart') !== false ||
            strpos($post->post_content, '[fct_') !== false ||
            strpos($post->post_content, 'fluent-cart') !== false
        )) {
            return true;
        }

        // 檢查是否有 FluentCart 的 CSS 類別或 JavaScript 變數
        if (function_exists('is_plugin_active') && is_plugin_active('fluent-cart/fluent-cart.php')) {
            // 如果 FluentCart 外掛啟用，在所有頁面都載入（但只在有表單時才啟動）
            return true;
        }

        return false;
    }

    /**
     * 手動觸發同步（用於測試）
     */
    public function manual_sync_trigger()
    {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足');
        }

        ?>
        <div style="position: fixed; top: 20px; right: 20px; z-index: 9999; background: white; padding: 15px; border: 2px solid #0073aa; border-radius: 5px;">
            <h4>BuyGo FluentCart 同步測試</h4>
            <button onclick="testFluentCartSync()" style="background: #0073aa; color: white; padding: 8px 15px; border: none; border-radius: 3px; cursor: pointer;">
                測試即時同步
            </button>
            <div id="sync-result" style="margin-top: 10px; font-size: 12px;"></div>
        </div>

        <script>
        function testFluentCartSync() {
            const resultDiv = document.getElementById('sync-result');
            resultDiv.innerHTML = '測試中...';

            // 模擬表單資料
            const testData = {
                customer_email: 'test@example.com',
                billing_phone: '0912345678',
                billing_full_name: '測試客戶',
                billing_address_1: '台北市信義區信義路五段7號',
                billing_city: '台北市',
                billing_state: '台北市',
                billing_postcode: '110',
                billing_country: 'TW',
                sync_type: 'manual_test',
                timestamp: new Date().toISOString()
            };

            fetch('<?php echo home_url('/buygo-fluentcart-webhook.php'); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(testData)
            })
            .then(response => response.json())
            .then(data => {
                resultDiv.innerHTML = `
                    <div style="color: green;">✅ 測試成功</div>
                    <div>處理筆數: ${data.processed || 0}</div>
                    <div>時間: ${data.timestamp || 'N/A'}</div>
                `;
            })
            .catch(error => {
                resultDiv.innerHTML = `<div style="color: red;">❌ 測試失敗: ${error.message}</div>`;
            });
        }
        </script>
        <?php
    }
}