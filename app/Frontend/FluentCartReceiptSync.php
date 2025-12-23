<?php

namespace BuyGo\Core\Frontend;

use BuyGo\Core\Services\DebugService;
use BuyGo\Core\Services\ContactDataService;

/**
 * FluentCart 收據頁面資料同步
 * 
 * 在收據頁面抓取訂單資料並同步到 BuyGo 系統
 */
class FluentCartReceiptSync
{
    private $debugService;
    private $contactDataService;

    public function __construct()
    {
        $this->debugService = new DebugService();
        $this->contactDataService = new ContactDataService();
        $this->init();
    }

    private function init()
    {
        // 在收據頁面載入時執行同步
        add_action('wp_footer', [$this, 'sync_on_receipt_page']);
        
        $this->debugService->log('FluentCartReceiptSync', '收據頁面同步器初始化完成');
    }

    /**
     * 在收據頁面執行同步
     */
    public function sync_on_receipt_page()
    {
        // 檢查是否為收據頁面
        if (!$this->is_receipt_page()) {
            return;
        }

        $this->debugService->log('FluentCartReceiptSync', '偵測到收據頁面', [
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'query' => $_GET,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        // 從 URL 參數取得訂單資訊
        $trxHash = $_GET['trx_hash'] ?? '';
        $method = $_GET['method'] ?? '';

        if (empty($trxHash)) {
            $this->debugService->log('FluentCartReceiptSync', '收據頁面沒有 trx_hash 參數', [
                'all_params' => $_GET
            ]);
            return;
        }

        // 根據 trx_hash 查詢訂單
        $orderData = $this->getOrderByTrxHash($trxHash);
        
        if (!$orderData) {
            $this->debugService->log('FluentCartReceiptSync', '找不到對應的訂單', [
                'trx_hash' => $trxHash,
                'method' => $method
            ]);
            
            // 嘗試其他方法查詢
            $this->tryAlternativeOrderLookup($trxHash);
            return;
        }

        // 執行同步
        $this->syncOrderData($orderData);

        // 在頁面上顯示同步狀態（用於除錯）
        if (current_user_can('manage_options')) {
            $this->show_debug_info($orderData);
        }

        // 發送同步成功的 JavaScript 事件
        $this->send_sync_event($orderData);
    }

    /**
     * 檢查是否為收據頁面
     */
    private function is_receipt_page(): bool
    {
        $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
        
        // 檢查 URL 是否包含收據頁面的標識
        $receiptIndicators = [
            'receipt',
            'thank-you',
            'order-received',
            'confirmation'
        ];

        foreach ($receiptIndicators as $indicator) {
            if (strpos($currentUrl, $indicator) !== false) {
                return true;
            }
        }

        // 檢查是否有 FluentCart 收據頁面的參數
        return isset($_GET['trx_hash']) && isset($_GET['fct_redirect']);
    }

    /**
     * 根據 trx_hash 取得訂單資料
     */
    private function getOrderByTrxHash(string $trxHash): ?array
    {
        global $wpdb;

        try {
            // 嘗試多種可能的欄位名稱
            $possibleFields = [
                'transaction_hash',
                'hash',
                'payment_hash',
                'trx_hash'
            ];

            $order = null;
            foreach ($possibleFields as $field) {
                // 檢查欄位是否存在
                $columnExists = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = %s 
                    AND TABLE_NAME = %s 
                    AND COLUMN_NAME = %s
                ", DB_NAME, $wpdb->prefix . 'fct_orders', $field));

                if ($columnExists) {
                    $order = $wpdb->get_row($wpdb->prepare("
                        SELECT o.*, c.email, c.first_name, c.last_name, c.user_id
                        FROM {$wpdb->prefix}fct_orders o
                        LEFT JOIN {$wpdb->prefix}fct_customers c ON o.customer_id = c.id
                        WHERE o.{$field} = %s
                        LIMIT 1
                    ", $trxHash), ARRAY_A);

                    if ($order) {
                        $this->debugService->log('FluentCartReceiptSync', "找到訂單 (使用欄位: {$field})", [
                            'order_id' => $order['id'],
                            'customer_email' => $order['email']
                        ]);
                        break;
                    }
                }
            }

            // 如果還是找不到，嘗試從訂單 meta 或其他地方查詢
            if (!$order) {
                // 嘗試從最近的訂單中找（作為備用方案）
                $recentOrders = $wpdb->get_results("
                    SELECT o.*, c.email, c.first_name, c.last_name, c.user_id
                    FROM {$wpdb->prefix}fct_orders o
                    LEFT JOIN {$wpdb->prefix}fct_customers c ON o.customer_id = c.id
                    ORDER BY o.created_at DESC
                    LIMIT 5
                ", ARRAY_A);

                if (!empty($recentOrders)) {
                    $order = $recentOrders[0]; // 使用最新的訂單
                    $this->debugService->log('FluentCartReceiptSync', '使用最新訂單作為備用方案', [
                        'order_id' => $order['id']
                    ]);
                }
            }

            return $order;

        } catch (\Exception $e) {
            $this->debugService->log('FluentCartReceiptSync', '查詢訂單失敗', [
                'error' => $e->getMessage(),
                'trx_hash' => $trxHash
            ], 'error');

            return null;
        }
    }

    /**
     * 同步訂單資料
     */
    private function syncOrderData(array $orderData)
    {
        try {
            $customerId = $orderData['customer_id'];
            $email = $orderData['email'];
            $updated = false;

            // 解析 billing_address
            if (!empty($orderData['billing_address'])) {
                $billingData = json_decode($orderData['billing_address'], true);
                if ($billingData) {
                    // 同步電話 - 支援多種可能的結構
                    $phone = $this->extractPhoneFromAddress($billingData);
                    if ($phone) {
                        $result = $this->contactDataService->updateCustomerPhone(
                            $customerId, 
                            $email, 
                            $phone, 
                            'receipt_page_sync'
                        );
                        if ($result) $updated = true;
                    }

                    // 同步帳單地址
                    $result = $this->contactDataService->updateCustomerAddress(
                        $customerId,
                        $email,
                        $billingData,
                        'billing',
                        'receipt_page_sync'
                    );
                    if ($result) $updated = true;
                }
            }

            // 解析 shipping_address
            if (!empty($orderData['shipping_address'])) {
                $shippingData = json_decode($orderData['shipping_address'], true);
                if ($shippingData) {
                    // 同步運送電話 - 支援多種可能的結構
                    $phone = $this->extractPhoneFromAddress($shippingData);
                    if ($phone) {
                        $result = $this->contactDataService->updateCustomerPhone(
                            $customerId, 
                            $email, 
                            $phone, 
                            'receipt_page_sync'
                        );
                        if ($result) $updated = true;
                    }

                    // 同步運送地址
                    $result = $this->contactDataService->updateCustomerAddress(
                        $customerId,
                        $email,
                        $shippingData,
                        'shipping',
                        'receipt_page_sync'
                    );
                    if ($result) $updated = true;
                }
            }

            if ($updated) {
                $this->debugService->log('FluentCartReceiptSync', '收據頁面同步成功', [
                    'order_id' => $orderData['id'],
                    'customer_email' => $email
                ]);
            } else {
                $this->debugService->log('FluentCartReceiptSync', '收據頁面沒有需要同步的資料', [
                    'order_id' => $orderData['id']
                ]);
            }

        } catch (\Exception $e) {
            $this->debugService->log('FluentCartReceiptSync', '收據頁面同步失敗', [
                'error' => $e->getMessage(),
                'order_id' => $orderData['id'] ?? 'unknown'
            ], 'error');
        }
    }

    /**
     * 顯示除錯資訊（僅管理員可見）
     */
    private function show_debug_info(array $orderData)
    {
        ?>
        <div style="position: fixed; bottom: 20px; right: 20px; background: white; border: 2px solid #0073aa; padding: 15px; border-radius: 5px; z-index: 9999; max-width: 400px; font-size: 12px;">
            <h4 style="margin: 0 0 10px 0; color: #0073aa;">🔄 BuyGo 收據頁面同步</h4>
            <p><strong>訂單 ID:</strong> <?php echo $orderData['id']; ?></p>
            <p><strong>客戶 Email:</strong> <?php echo $orderData['email']; ?></p>
            <p><strong>TRX Hash:</strong> <?php echo $_GET['trx_hash'] ?? 'N/A'; ?></p>
            
            <?php if (!empty($orderData['billing_address'])): ?>
                <p><strong>帳單地址:</strong> 有資料</p>
                <?php 
                $billingData = json_decode($orderData['billing_address'], true);
                if ($billingData && !empty($billingData['phone'])): 
                ?>
                    <p><strong>帳單電話:</strong> <?php echo $billingData['phone']; ?></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if (!empty($orderData['shipping_address'])): ?>
                <p><strong>運送地址:</strong> 有資料</p>
                <?php 
                $shippingData = json_decode($orderData['shipping_address'], true);
                if ($shippingData && !empty($shippingData['phone'])): 
                ?>
                    <p><strong>運送電話:</strong> <?php echo $shippingData['phone']; ?></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <p style="color: green; margin: 10px 0 0 0;">✅ 資料已同步到 BuyGo 系統</p>
        </div>
        <?php
    }

    /**
     * 嘗試其他方法查詢訂單
     */
    private function tryAlternativeOrderLookup(string $trxHash)
    {
        global $wpdb;
        
        try {
            // 嘗試從最近的訂單中找（作為備用方案）
            $recentOrders = $wpdb->get_results("
                SELECT o.*, c.email, c.first_name, c.last_name, c.user_id
                FROM {$wpdb->prefix}fct_orders o
                LEFT JOIN {$wpdb->prefix}fct_customers c ON o.customer_id = c.id
                ORDER BY o.created_at DESC
                LIMIT 5
            ", ARRAY_A);

            if (!empty($recentOrders)) {
                $order = $recentOrders[0]; // 使用最新的訂單
                $this->debugService->log('FluentCartReceiptSync', '使用最新訂單作為備用方案', [
                    'order_id' => $order['id'],
                    'trx_hash' => $trxHash
                ]);
                
                // 執行同步
                $this->syncOrderData($order);
            }
            
        } catch (\Exception $e) {
            $this->debugService->log('FluentCartReceiptSync', '備用查詢失敗', [
                'error' => $e->getMessage(),
                'trx_hash' => $trxHash
            ], 'error');
        }
    }

    /**
     * 發送同步成功的 JavaScript 事件
     */
    private function send_sync_event(array $orderData)
    {
        ?>
        <script>
        // 發送自定義事件通知前端同步完成
        document.addEventListener('DOMContentLoaded', function() {
            const syncEvent = new CustomEvent('buygo:receipt:synced', {
                detail: {
                    orderId: <?php echo json_encode($orderData['id']); ?>,
                    customerEmail: <?php echo json_encode($orderData['email']); ?>,
                    timestamp: new Date().toISOString()
                }
            });
            document.dispatchEvent(syncEvent);
            
            // 也可以通過 console 通知開發者
            console.log('[BuyGo] 收據頁面同步完成', {
                orderId: <?php echo json_encode($orderData['id']); ?>,
                customerEmail: <?php echo json_encode($orderData['email']); ?>
            });
        });
        </script>
        <?php
    }

    /**
     * 從地址資料中提取電話號碼（支援多種結構）
     * 
     * @param array $addressData 地址資料陣列
     * @return string|null
     */
    private function extractPhoneFromAddress(array $addressData): ?string
    {
        // 方法 1: 直接從第一層取得
        if (!empty($addressData['phone'])) {
            return $addressData['phone'];
        }

        // 方法 2: 從 meta.other_data.phone 取得（FluentCart 實際結構）
        if (isset($addressData['meta']['other_data']['phone']) && !empty($addressData['meta']['other_data']['phone'])) {
            return $addressData['meta']['other_data']['phone'];
        }

        // 方法 3: 從 other_data.phone 取得
        if (isset($addressData['other_data']['phone']) && !empty($addressData['other_data']['phone'])) {
            return $addressData['other_data']['phone'];
        }

        // 方法 4: 遞迴搜尋
        return $this->recursiveSearchPhone($addressData);
    }

    /**
     * 遞迴搜尋陣列中的電話號碼
     * 
     * @param array $data 要搜尋的陣列
     * @return string|null
     */
    private function recursiveSearchPhone(array $data): ?string
    {
        foreach ($data as $key => $value) {
            if (stripos($key, 'phone') !== false && !empty($value) && is_string($value)) {
                if (preg_match('/[\d\+\-\(\)\s]{8,}/', $value)) {
                    return $value;
                }
            }
            
            if (is_array($value)) {
                $phone = $this->recursiveSearchPhone($value);
                if ($phone) {
                    return $phone;
                }
            }
        }

        return null;
    }
}