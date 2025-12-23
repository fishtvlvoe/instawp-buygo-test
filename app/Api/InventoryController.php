<?php

namespace BuyGo\Core\Api;

use WP_REST_Request;
use WP_REST_Response;
use BuyGo\Core\Services\InventoryService;
use BuyGo\Core\Services\DebugService;

/**
 * Inventory Controller - 庫存管理 API 控制器
 * 
 * 提供庫存管理相關的 REST API 端點
 * 
 * @package BuyGo\Core\Api
 * @version 1.0.0
 */
class InventoryController extends BaseController {

    private $inventoryService;
    private $debugService;

    public function __construct()
    {
        parent::__construct();
        $this->inventoryService = new InventoryService();
        $this->debugService = new DebugService();
    }

    public function register_routes() {
        // 批量檢查庫存可用性
        register_rest_route($this->namespace, '/inventory/check', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'check_inventory_availability'],
                'permission_callback' => [$this, 'check_read_permission'],
                'args' => [
                    'items' => [
                        'description' => '商品項目陣列',
                        'type' => 'array',
                        'required' => true,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'product_variation_id' => [
                                    'type' => 'integer',
                                    'required' => true
                                ],
                                'quantity' => [
                                    'type' => 'integer',
                                    'minimum' => 1,
                                    'required' => true
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        // 手動調整庫存
        register_rest_route($this->namespace, '/inventory/adjust', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'adjust_inventory'],
                'permission_callback' => [$this, 'check_edit_permission'],
                'args' => [
                    'product_variation_id' => [
                        'description' => '商品變化 ID',
                        'type' => 'integer',
                        'required' => true
                    ],
                    'quantity' => [
                        'description' => '調整數量（正數增加，負數減少）',
                        'type' => 'integer',
                        'required' => true
                    ],
                    'reason' => [
                        'description' => '調整原因',
                        'type' => 'string',
                        'enum' => ['manual_adjustment', 'restock', 'damage', 'expired', 'correction'],
                        'default' => 'manual_adjustment'
                    ],
                    'reference_id' => [
                        'description' => '參考 ID',
                        'type' => 'string'
                    ]
                ]
            ]
        ]);

        // 取得低庫存商品
        register_rest_route($this->namespace, '/inventory/low-stock', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_low_stock_products'],
                'permission_callback' => [$this, 'check_read_permission'],
                'args' => [
                    'threshold' => [
                        'description' => '低庫存警告閾值',
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 100,
                        'default' => 5
                    ]
                ]
            ]
        ]);

        // 取得缺貨商品
        register_rest_route($this->namespace, '/inventory/out-of-stock', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_out_of_stock_products'],
                'permission_callback' => [$this, 'check_read_permission']
            ]
        ]);

        // 庫存統計報告
        register_rest_route($this->namespace, '/inventory/report', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_inventory_report'],
                'permission_callback' => [$this, 'check_read_permission'],
                'args' => [
                    'period' => [
                        'description' => '統計期間',
                        'type' => 'string',
                        'enum' => ['today', 'week', 'month', 'quarter', 'year'],
                        'default' => 'month'
                    ]
                ]
            ]
        ]);
    }

    /**
     * 檢查讀取權限
     */
    public function check_read_permission($request) {
        $user = wp_get_current_user();
        
        return in_array('administrator', (array)$user->roles, true) || 
               in_array('buygo_admin', (array)$user->roles, true) ||
               in_array('buygo_seller', (array)$user->roles, true) || 
               in_array('buygo_helper', (array)$user->roles, true);
    }

    /**
     * 檢查編輯權限
     */
    public function check_edit_permission($request) {
        $user = wp_get_current_user();
        
        return in_array('administrator', (array)$user->roles, true) || 
               in_array('buygo_admin', (array)$user->roles, true) ||
               in_array('buygo_seller', (array)$user->roles, true);
    }

    /**
     * 批量檢查庫存可用性
     */
    public function check_inventory_availability(WP_REST_Request $request) {
        $startTime = microtime(true);
        
        $this->debugService->log('InventoryController', '批量檢查庫存可用性請求', [
            'params' => $request->get_params()
        ]);

        try {
            $items = $request->get_param('items');
            
            if (empty($items) || !is_array($items)) {
                throw new \Exception('商品項目陣列不能為空');
            }

            $results = $this->inventoryService->batchCheckInventoryAvailability($items);
            
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $response = [
                'success' => true,
                'data' => $results,
                'meta' => [
                    'total_items' => count($items),
                    'available_items' => count(array_filter($results, function($item) {
                        return $item['available'];
                    })),
                    'unavailable_items' => count(array_filter($results, function($item) {
                        return !$item['available'];
                    }))
                ]
            ];

            $this->debugService->logApiRequest(
                '/inventory/check',
                ['items_count' => count($items)],
                $response['meta'],
                $responseTime,
                'success'
            );

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $errorResponse = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'INVENTORY_CHECK_ERROR'
            ];

            $this->debugService->logApiRequest(
                '/inventory/check',
                $request->get_params(),
                $errorResponse,
                $responseTime,
                'error'
            );

            return new WP_REST_Response($errorResponse, 400);
        }
    }

    /**
     * 手動調整庫存
     */
    public function adjust_inventory(WP_REST_Request $request) {
        $startTime = microtime(true);
        
        $this->debugService->log('InventoryController', '手動調整庫存請求', [
            'params' => $request->get_params()
        ]);

        try {
            $productVariationId = (int)$request->get_param('product_variation_id');
            $quantity = (int)$request->get_param('quantity');
            $reason = $request->get_param('reason');
            $referenceId = $request->get_param('reference_id') ?? '';

            $success = $this->inventoryService->adjustInventory($productVariationId, $quantity, $reason, $referenceId);
            
            if (!$success) {
                throw new \Exception('庫存調整失敗');
            }

            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $response = [
                'success' => true,
                'message' => '庫存調整成功',
                'data' => [
                    'product_variation_id' => $productVariationId,
                    'quantity_adjusted' => $quantity,
                    'reason' => $reason,
                    'reference_id' => $referenceId
                ]
            ];

            $this->debugService->logApiRequest(
                '/inventory/adjust',
                $request->get_params(),
                $response,
                $responseTime,
                'success'
            );

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $errorResponse = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'INVENTORY_ADJUST_ERROR'
            ];

            $this->debugService->logApiRequest(
                '/inventory/adjust',
                $request->get_params(),
                $errorResponse,
                $responseTime,
                'error'
            );

            return new WP_REST_Response($errorResponse, 400);
        }
    }

    /**
     * 取得低庫存商品
     */
    public function get_low_stock_products(WP_REST_Request $request) {
        $startTime = microtime(true);
        
        try {
            $threshold = (int)$request->get_param('threshold');
            
            $lowStockProducts = $this->inventoryService->getLowStockProducts($threshold);
            
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $response = [
                'success' => true,
                'data' => $lowStockProducts,
                'meta' => [
                    'threshold' => $threshold,
                    'count' => count($lowStockProducts)
                ]
            ];

            $this->debugService->logApiRequest(
                '/inventory/low-stock',
                ['threshold' => $threshold],
                ['count' => count($lowStockProducts)],
                $responseTime,
                'success'
            );

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $errorResponse = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'LOW_STOCK_ERROR'
            ];

            $this->debugService->logApiRequest(
                '/inventory/low-stock',
                $request->get_params(),
                $errorResponse,
                $responseTime,
                'error'
            );

            return new WP_REST_Response($errorResponse, 500);
        }
    }

    /**
     * 取得缺貨商品
     */
    public function get_out_of_stock_products(WP_REST_Request $request) {
        $startTime = microtime(true);
        
        try {
            $outOfStockProducts = $this->inventoryService->getOutOfStockProducts();
            
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $response = [
                'success' => true,
                'data' => $outOfStockProducts,
                'meta' => [
                    'count' => count($outOfStockProducts)
                ]
            ];

            $this->debugService->logApiRequest(
                '/inventory/out-of-stock',
                [],
                ['count' => count($outOfStockProducts)],
                $responseTime,
                'success'
            );

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $errorResponse = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'OUT_OF_STOCK_ERROR'
            ];

            $this->debugService->logApiRequest(
                '/inventory/out-of-stock',
                [],
                $errorResponse,
                $responseTime,
                'error'
            );

            return new WP_REST_Response($errorResponse, 500);
        }
    }

    /**
     * 取得庫存統計報告
     */
    public function get_inventory_report(WP_REST_Request $request) {
        $startTime = microtime(true);
        
        try {
            $period = $request->get_param('period');
            
            // 計算時間範圍
            $dateRange = $this->calculateDateRange($period);
            
            // 取得庫存統計資料
            $report = $this->generateInventoryReport($dateRange);
            
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $response = [
                'success' => true,
                'data' => $report,
                'meta' => [
                    'period' => $period,
                    'date_range' => $dateRange,
                    'generated_at' => current_time('mysql')
                ]
            ];

            $this->debugService->logApiRequest(
                '/inventory/report',
                ['period' => $period],
                ['report_items' => count($report)],
                $responseTime,
                'success'
            );

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $errorResponse = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'INVENTORY_REPORT_ERROR'
            ];

            $this->debugService->logApiRequest(
                '/inventory/report',
                $request->get_params(),
                $errorResponse,
                $responseTime,
                'error'
            );

            return new WP_REST_Response($errorResponse, 500);
        }
    }

    /**
     * 計算日期範圍
     * 
     * @param string $period 期間
     * @return array
     */
    private function calculateDateRange(string $period): array
    {
        $now = current_time('mysql');
        $startDate = '';
        
        switch ($period) {
            case 'today':
                $startDate = date('Y-m-d 00:00:00');
                break;
            case 'week':
                $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
                break;
            case 'month':
                $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
                break;
            case 'quarter':
                $startDate = date('Y-m-d 00:00:00', strtotime('-90 days'));
                break;
            case 'year':
                $startDate = date('Y-m-d 00:00:00', strtotime('-365 days'));
                break;
            default:
                $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
        }
        
        return [
            'start_date' => $startDate,
            'end_date' => $now
        ];
    }

    /**
     * 生成庫存統計報告
     * 
     * @param array $dateRange 日期範圍
     * @return array
     */
    private function generateInventoryReport(array $dateRange): array
    {
        global $wpdb;
        
        $table = $wpdb->prefix . 'buygo_inventory_logs';
        
        // 庫存變動統計
        $sql = $wpdb->prepare("
            SELECT 
                reason,
                change_type,
                COUNT(*) as transaction_count,
                SUM(quantity) as total_quantity
            FROM {$table} 
            WHERE created_at BETWEEN %s AND %s
            GROUP BY reason, change_type
            ORDER BY transaction_count DESC
        ", $dateRange['start_date'], $dateRange['end_date']);

        $inventoryChanges = $wpdb->get_results($sql, ARRAY_A);
        
        // 最活躍的商品
        $sql = $wpdb->prepare("
            SELECT 
                product_variation_id,
                COUNT(*) as transaction_count,
                SUM(CASE WHEN change_type = 'increase' THEN quantity ELSE 0 END) as total_increase,
                SUM(CASE WHEN change_type = 'decrease' THEN quantity ELSE 0 END) as total_decrease
            FROM {$table} 
            WHERE created_at BETWEEN %s AND %s
            GROUP BY product_variation_id
            ORDER BY transaction_count DESC
            LIMIT 10
        ", $dateRange['start_date'], $dateRange['end_date']);

        $activeProducts = $wpdb->get_results($sql, ARRAY_A);
        
        return [
            'inventory_changes' => $inventoryChanges,
            'most_active_products' => $activeProducts,
            'summary' => [
                'total_transactions' => array_sum(array_column($inventoryChanges, 'transaction_count')),
                'total_increase' => array_sum(array_column($inventoryChanges, 'total_quantity')),
                'period' => $dateRange
            ]
        ];
    }
}