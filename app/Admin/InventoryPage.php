<?php

namespace BuyGo\Core\Admin;

use BuyGo\Core\Services\InventoryService;
use BuyGo\Core\Services\DebugService;

/**
 * Inventory Page - 庫存管理後台頁面
 * 
 * 提供庫存管理的 WordPress 管理後台界面
 * 
 * @package BuyGo\Core\Admin
 * @version 1.0.0
 */
class InventoryPage {

    private $inventoryService;
    private $debugService;

    public function __construct()
    {
        $this->inventoryService = new InventoryService();
        $this->debugService = new DebugService();
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * 新增管理選單
     */
    public function add_admin_menu(): void
    {
        add_submenu_page(
            'buygo-dashboard',
            '庫存管理',
            '庫存管理',
            'manage_options',
            'buygo-inventory',
            [$this, 'render_page']
        );
        
        add_submenu_page(
            'buygo-dashboard',
            '庫存歷史',
            '庫存歷史',
            'manage_options',
            'buygo-inventory-history',
            [$this, 'render_history_page']
        );
    }

    /**
     * 載入腳本和樣式
     */
    public function enqueue_scripts($hook): void
    {
        if (strpos($hook, 'buygo-inventory') === false) {
            return;
        }

        // Vue.js 和相關依賴
        wp_enqueue_script('vue', 'https://unpkg.com/vue@3/dist/vue.global.js', [], '3.0.0', true);
        wp_enqueue_script('axios', 'https://unpkg.com/axios/dist/axios.min.js', [], '1.0.0', true);
        
        // Tailwind CSS
        wp_enqueue_style('tailwindcss', 'https://unpkg.com/tailwindcss@^2/dist/tailwind.min.css', [], '2.0.0');
        
        // 自定義腳本
        wp_enqueue_script(
            'buygo-inventory-management',
            plugin_dir_url(__FILE__) . '../../assets/js/inventory-management.js',
            ['vue', 'axios'],
            '1.0.0',
            true
        );

        // 傳遞資料到前端
        wp_localize_script('buygo-inventory-management', 'buygoInventory', [
            'apiUrl' => rest_url('buygo/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'currentUser' => wp_get_current_user()->ID,
            'permissions' => [
                'canEdit' => current_user_can('manage_options'),
                'canView' => current_user_can('read')
            ]
        ]);
    }

    /**
     * 渲染庫存管理頁面
     */
    public function render_page(): void
    {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">庫存管理</h1>
            
            <div id="buygo-inventory-app" class="mt-4">
                <!-- Vue 組件將在這裡渲染 -->
                <div class="loading-placeholder bg-gray-100 rounded-lg p-8 text-center">
                    <p class="text-gray-600">載入庫存管理界面中...</p>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const { createApp } = Vue;
            
            // 載入 InventoryManagement 組件
            import('/wp-content/plugins/buygo/assets/components/InventoryManagement.vue')
                .then(module => {
                    const app = createApp({
                        components: {
                            InventoryManagement: module.default
                        },
                        template: `
                            <InventoryManagement 
                                :api-base-url="apiBaseUrl"
                            />
                        `,
                        data() {
                            return {
                                apiBaseUrl: buygoInventory.apiUrl
                            }
                        }
                    });
                    
                    app.mount('#buygo-inventory-app');
                })
                .catch(error => {
                    console.error('載入庫存管理組件失敗:', error);
                    document.getElementById('buygo-inventory-app').innerHTML = `
                        <div class="notice notice-error">
                            <p>載入庫存管理界面失敗，請重新整理頁面或聯絡管理員。</p>
                        </div>
                    `;
                });
        });
        </script>

        <style>
        .wrap {
            margin: 20px 20px 0 2px;
        }
        
        .loading-placeholder {
            min-height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* 確保 Tailwind CSS 樣式正常載入 */
        .notice {
            background: #fff;
            border-left: 4px solid #dc3232;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin: 5px 15px 2px;
            padding: 1px 12px;
        }
        </style>
        <?php
    }

    /**
     * 渲染庫存歷史頁面
     */
    public function render_history_page(): void
    {
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">庫存歷史</h1>
            
            <?php if ($productId > 0): ?>
                <a href="<?php echo admin_url('admin.php?page=buygo-inventory'); ?>" class="page-title-action">返回庫存管理</a>
            <?php endif; ?>
            
            <div class="mt-4">
                <?php $this->render_inventory_history_table($productId); ?>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染庫存歷史表格
     */
    private function render_inventory_history_table(int $productId = 0): void
    {
        global $wpdb;
        
        $table = $wpdb->prefix . 'buygo_inventory_logs';
        
        // 建立查詢
        $where = $productId > 0 ? $wpdb->prepare("WHERE product_variation_id = %d", $productId) : "";
        $sql = "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT 100";
        
        $results = $wpdb->get_results($sql, ARRAY_A);
        
        if (empty($results)) {
            echo '<div class="notice notice-info"><p>沒有找到庫存變更記錄。</p></div>';
            return;
        }
        
        ?>
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column">時間</th>
                        <th scope="col" class="manage-column">商品 ID</th>
                        <th scope="col" class="manage-column">變更類型</th>
                        <th scope="col" class="manage-column">數量</th>
                        <th scope="col" class="manage-column">原因</th>
                        <th scope="col" class="manage-column">參考 ID</th>
                        <th scope="col" class="manage-column">舊庫存</th>
                        <th scope="col" class="manage-column">新庫存</th>
                        <th scope="col" class="manage-column">操作者</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['created_at']); ?></td>
                            <td><?php echo esc_html($row['product_variation_id']); ?></td>
                            <td>
                                <span class="badge <?php echo $row['change_type'] === 'increase' ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo $row['change_type'] === 'increase' ? '增加' : '減少'; ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($row['quantity']); ?></td>
                            <td><?php echo esc_html($this->formatReason($row['reason'])); ?></td>
                            <td><?php echo esc_html($row['reference_id'] ?: '-'); ?></td>
                            <td><?php echo esc_html($row['old_inventory']); ?></td>
                            <td><?php echo esc_html($row['new_inventory']); ?></td>
                            <td><?php echo esc_html($this->getOperatorName($row['operator_id'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <style>
        .badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: bold;
            line-height: 1;
            color: #fff;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 4px;
        }
        
        .badge-success {
            background-color: #28a745;
        }
        
        .badge-warning {
            background-color: #ffc107;
            color: #212529;
        }
        
        .wp-list-table th,
        .wp-list-table td {
            padding: 8px 10px;
        }
        </style>
        <?php
    }

    /**
     * 格式化變更原因
     */
    private function formatReason(string $reason): string
    {
        $reasons = [
            'order_placed' => '訂單下單',
            'order_cancelled' => '訂單取消',
            'order_quantity_increased' => '訂單數量增加',
            'order_quantity_decreased' => '訂單數量減少',
            'manual_adjustment' => '手動調整',
            'restock' => '補貨',
            'damage' => '損壞',
            'expired' => '過期',
            'correction' => '修正'
        ];
        
        return $reasons[$reason] ?? $reason;
    }

    /**
     * 取得操作者名稱
     */
    private function getOperatorName(?int $operatorId): string
    {
        if (!$operatorId) {
            return '系統';
        }
        
        $user = get_userdata($operatorId);
        return $user ? ($user->display_name ?: $user->user_login) : "用戶 #{$operatorId}";
    }

    /**
     * 取得庫存統計資料
     */
    public function get_inventory_stats(): array
    {
        try {
            $lowStockProducts = $this->inventoryService->getLowStockProducts(5);
            $outOfStockProducts = $this->inventoryService->getOutOfStockProducts();
            
            return [
                'low_stock_count' => count($lowStockProducts),
                'out_of_stock_count' => count($outOfStockProducts),
                'low_stock_products' => array_slice($lowStockProducts, 0, 5),
                'out_of_stock_products' => array_slice($outOfStockProducts, 0, 5)
            ];
            
        } catch (\Exception $e) {
            $this->debugService->log('InventoryPage', '取得庫存統計失敗', [
                'error' => $e->getMessage()
            ], 'error');
            
            return [
                'low_stock_count' => 0,
                'out_of_stock_count' => 0,
                'low_stock_products' => [],
                'out_of_stock_products' => []
            ];
        }
    }

    /**
     * AJAX 處理：取得庫存統計
     */
    public function ajax_get_inventory_stats(): void
    {
        check_ajax_referer('buygo_inventory_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('權限不足');
        }
        
        $stats = $this->get_inventory_stats();
        wp_send_json_success($stats);
    }
}