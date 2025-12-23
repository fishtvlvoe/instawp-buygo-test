<?php

namespace BuyGo\Core\Api;

use WP_REST_Request;
use WP_REST_Response;
use BuyGo\Core\Services\ProductService;
use BuyGo\Core\Services\DebugService;

/**
 * Product Controller - 商品管理 API 控制器
 * 
 * 整合 FluentCart 商品功能並提供 BuyGo 特有的 API
 * 
 * @package BuyGo\Core\Api
 * @version 2.0.0
 */
class ProductController extends BaseController {

    private $productService;
    private $debugService;

    public function __construct()
    {
        // BaseController 沒有建構函式，不需要呼叫 parent::__construct()
        $this->productService = new ProductService();
        $this->debugService = new DebugService();
    }

    public function register_routes() {
        // 商品列表
        register_rest_route($this->namespace, '/products', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_read_permission'],
                'args' => [
                    'status' => [
                        'description' => '商品狀態篩選',
                        'type' => 'string',
                        'enum' => ['all', 'publish', 'draft', 'private'],
                        'default' => 'all'
                    ],
                    'search' => [
                        'description' => '搜尋關鍵字',
                        'type' => 'string'
                    ],
                    'view_mode' => [
                        'description' => '顯示模式',
                        'type' => 'string',
                        'enum' => ['frontend', 'backend'],
                        'default' => 'frontend'
                    ]
                ]
            ]
        ]);

        // 庫存管理
        register_rest_route($this->namespace, '/products/(?P<id>\d+)/inventory', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'update_inventory'],
                'permission_callback' => [$this, 'check_edit_permission'],
                'args' => [
                    'action' => [
                        'description' => '庫存操作類型',
                        'type' => 'string',
                        'enum' => ['increase', 'decrease', 'set'],
                        'required' => true
                    ],
                    'quantity' => [
                        'description' => '數量',
                        'type' => 'integer',
                        'minimum' => 1,
                        'required' => true
                    ],
                    'reason' => [
                        'description' => '變更原因',
                        'type' => 'string',
                        'enum' => ['manual_adjustment', 'order_placed', 'order_cancelled', 'restock', 'damage'],
                        'required' => true
                    ],
                    'reference_id' => [
                        'description' => '參考 ID（如訂單 ID）',
                        'type' => 'string'
                    ]
                ]
            ]
        ]);

        // 庫存歷史
        register_rest_route($this->namespace, '/products/(?P<id>\d+)/inventory/history', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_inventory_history'],
                'permission_callback' => [$this, 'check_read_permission'],
                'args' => [
                    'limit' => [
                        'description' => '限制筆數',
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 100,
                        'default' => 50
                    ]
                ]
            ]
        ]);

        // 商品搜尋和篩選
        register_rest_route($this->namespace, '/products/search', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'search_products'],
                'permission_callback' => [$this, 'check_read_permission'],
                'args' => [
                    'q' => [
                        'description' => '搜尋關鍵字',
                        'type' => 'string',
                        'required' => true
                    ],
                    'category' => [
                        'description' => '商品分類',
                        'type' => 'string'
                    ],
                    'price_min' => [
                        'description' => '最低價格',
                        'type' => 'number',
                        'minimum' => 0
                    ],
                    'price_max' => [
                        'description' => '最高價格',
                        'type' => 'number',
                        'minimum' => 0
                    ],
                    'stock_status' => [
                        'description' => '庫存狀態',
                        'type' => 'string',
                        'enum' => ['in_stock', 'low_stock', 'out_of_stock']
                    ],
                    'sort_by' => [
                        'description' => '排序方式',
                        'type' => 'string',
                        'enum' => ['name', 'price', 'inventory', 'created_at'],
                        'default' => 'created_at'
                    ],
                    'sort_order' => [
                        'description' => '排序順序',
                        'type' => 'string',
                        'enum' => ['asc', 'desc'],
                        'default' => 'desc'
                    ],
                    'page' => [
                        'description' => '頁碼',
                        'type' => 'integer',
                        'minimum' => 1,
                        'default' => 1
                    ],
                    'per_page' => [
                        'description' => '每頁數量',
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 100,
                        'default' => 20
                    ]
                ]
            ]
        ]);

        // 批量操作
        register_rest_route($this->namespace, '/products/batch', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'batch_operations'],
                'permission_callback' => [$this, 'check_edit_permission'],
                'args' => [
                    'action' => [
                        'description' => '批量操作類型',
                        'type' => 'string',
                        'enum' => ['update_status', 'update_price', 'delete', 'duplicate'],
                        'required' => true
                    ],
                    'product_ids' => [
                        'description' => '商品 ID 陣列',
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'required' => true
                    ],
                    'data' => [
                        'description' => '操作資料',
                        'type' => 'object'
                    ]
                ]
            ]
        ]);

        // 商品統計
        register_rest_route($this->namespace, '/products/stats', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_product_stats'],
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

        // 商品匯出
        register_rest_route($this->namespace, '/products/export', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'export_products'],
                'permission_callback' => [$this, 'check_read_permission'],
                'args' => [
                    'format' => [
                        'description' => '匯出格式',
                        'type' => 'string',
                        'enum' => ['csv', 'json', 'xlsx'],
                        'default' => 'csv'
                    ],
                    'filters' => [
                        'description' => '篩選條件',
                        'type' => 'object'
                    ]
                ]
            ]
        ]);

        // 商品複製
        register_rest_route($this->namespace, '/products/(?P<id>\d+)/duplicate', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'duplicate_product'],
                'permission_callback' => [$this, 'check_edit_permission'],
                'args' => [
                    'name_suffix' => [
                        'description' => '名稱後綴',
                        'type' => 'string',
                        'default' => ' (複製)'
                    ],
                    'copy_inventory' => [
                        'description' => '是否複製庫存',
                        'type' => 'boolean',
                        'default' => false
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
     * 取得商品列表
     */
    public function get_items(WP_REST_Request $request) {
        $startTime = microtime(true);
        
        $this->debugService->log('ProductController', 'API 請求開始', [
            'endpoint' => '/products',
            'params' => $request->get_params()
        ]);

        try {
            $filters = [
                'status' => $request->get_param('status'),
                'search' => $request->get_param('search')
            ];
            
            $viewMode = $request->get_param('view_mode');
            
            $products = $this->productService->getProductsWithOrderCount($filters, $viewMode);
            
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $response = [
                'success' => true,
                'data' => $products,
                'meta' => [
                    'total' => count($products),
                    'view_mode' => $viewMode,
                    'filters' => $filters
                ]
            ];

            $this->debugService->logApiRequest(
                '/products',
                $request->get_params(),
                ['count' => count($products)],
                $responseTime,
                'success'
            );

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $errorResponse = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'PRODUCT_LIST_ERROR'
            ];

            $this->debugService->logApiRequest(
                '/products',
                $request->get_params(),
                $errorResponse,
                $responseTime,
                'error'
            );

            return new WP_REST_Response($errorResponse, 500);
        }
    }

    /**
     * 更新商品庫存
     */
    public function update_inventory(WP_REST_Request $request) {
        $startTime = microtime(true);
        $productId = (int)$request->get_param('id');
        
        $this->debugService->log('ProductController', '庫存更新請求開始', [
            'product_id' => $productId,
            'params' => $request->get_params()
        ]);

        try {
            $action = $request->get_param('action');
            $quantity = (int)$request->get_param('quantity');
            $reason = $request->get_param('reason');
            $referenceId = $request->get_param('reference_id') ?? '';

            // 計算實際變更數量
            $changeQuantity = $quantity;
            if ($action === 'decrease') {
                $changeQuantity = -$quantity;
            }

            $success = $this->productService->updateInventory($productId, $changeQuantity, $reason, $referenceId);
            
            if (!$success) {
                throw new \Exception('庫存更新失敗');
            }

            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $response = [
                'success' => true,
                'message' => '庫存更新成功',
                'data' => [
                    'product_id' => $productId,
                    'action' => $action,
                    'quantity' => $quantity,
                    'reason' => $reason
                ]
            ];

            $this->debugService->logApiRequest(
                "/products/{$productId}/inventory",
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
                'error_code' => 'INVENTORY_UPDATE_ERROR'
            ];

            $this->debugService->logApiRequest(
                "/products/{$productId}/inventory",
                $request->get_params(),
                $errorResponse,
                $responseTime,
                'error'
            );

            return new WP_REST_Response($errorResponse, 400);
        }
    }

    /**
     * 取得庫存歷史
     */
    public function get_inventory_history(WP_REST_Request $request) {
        $startTime = microtime(true);
        $productId = (int)$request->get_param('id');
        
        try {
            $limit = (int)$request->get_param('limit');
            
            $history = $this->productService->getInventoryHistory($productId, $limit);
            
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $response = [
                'success' => true,
                'data' => $history,
                'meta' => [
                    'product_id' => $productId,
                    'count' => count($history)
                ]
            ];

            $this->debugService->logApiRequest(
                "/products/{$productId}/inventory/history",
                $request->get_params(),
                ['count' => count($history)],
                $responseTime,
                'success'
            );

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $errorResponse = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'INVENTORY_HISTORY_ERROR'
            ];

            $this->debugService->logApiRequest(
                "/products/{$productId}/inventory/history",
                $request->get_params(),
                $errorResponse,
                $responseTime,
                'error'
            );

            return new WP_REST_Response($errorResponse, 500);
        }
    }

    /**
     * 搜尋商品
     */
    public function search_products(WP_REST_Request $request) {
        $startTime = microtime(true);
        
        $this->debugService->log('ProductController', '商品搜尋請求', [
            'params' => $request->get_params()
        ]);

        try {
            $searchParams = [
                'q' => $request->get_param('q'),
                'category' => $request->get_param('category'),
                'price_min' => $request->get_param('price_min'),
                'price_max' => $request->get_param('price_max'),
                'stock_status' => $request->get_param('stock_status'),
                'sort_by' => $request->get_param('sort_by'),
                'sort_order' => $request->get_param('sort_order'),
                'page' => $request->get_param('page'),
                'per_page' => $request->get_param('per_page')
            ];

            $results = $this->productService->searchProducts($searchParams);
            
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $response = [
                'success' => true,
                'data' => $results['products'],
                'pagination' => $results['pagination'],
                'meta' => [
                    'search_params' => $searchParams,
                    'total_found' => $results['total']
                ]
            ];

            $this->debugService->logApiRequest(
                '/products/search',
                $searchParams,
                ['total_found' => $results['total']],
                $responseTime,
                'success'
            );

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $errorResponse = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'PRODUCT_SEARCH_ERROR'
            ];

            $this->debugService->logApiRequest(
                '/products/search',
                $request->get_params(),
                $errorResponse,
                $responseTime,
                'error'
            );

            return new WP_REST_Response($errorResponse, 500);
        }
    }

    /**
     * 批量操作
     */
    public function batch_operations(WP_REST_Request $request) {
        $startTime = microtime(true);
        
        $this->debugService->log('ProductController', '批量操作請求', [
            'params' => $request->get_params()
        ]);

        try {
            $action = $request->get_param('action');
            $productIds = $request->get_param('product_ids');
            $data = $request->get_param('data') ?? [];

            if (empty($productIds) || !is_array($productIds)) {
                throw new \Exception('商品 ID 陣列不能為空');
            }

            $results = $this->productService->batchOperations($action, $productIds, $data);
            
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $response = [
                'success' => $results['success_count'] > 0,
                'message' => "批量操作完成：成功 {$results['success_count']} 筆，失敗 {$results['error_count']} 筆",
                'data' => $results['results'],
                'meta' => [
                    'action' => $action,
                    'total_items' => count($productIds),
                    'success_count' => $results['success_count'],
                    'error_count' => $results['error_count']
                ]
            ];

            $this->debugService->logApiRequest(
                '/products/batch',
                ['action' => $action, 'items_count' => count($productIds)],
                $response['meta'],
                $responseTime,
                $results['error_count'] === 0 ? 'success' : 'partial_error'
            );

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $errorResponse = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'BATCH_OPERATION_ERROR'
            ];

            $this->debugService->logApiRequest(
                '/products/batch',
                $request->get_params(),
                $errorResponse,
                $responseTime,
                'error'
            );

            return new WP_REST_Response($errorResponse, 400);
        }
    }

    /**
     * 取得商品統計
     */
    public function get_product_stats(WP_REST_Request $request) {
        $startTime = microtime(true);
        
        try {
            $period = $request->get_param('period');
            
            $stats = $this->productService->getProductStats($period);
            
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $response = [
                'success' => true,
                'data' => $stats,
                'meta' => [
                    'period' => $period,
                    'generated_at' => current_time('mysql')
                ]
            ];

            $this->debugService->logApiRequest(
                '/products/stats',
                ['period' => $period],
                ['stats_items' => count($stats)],
                $responseTime,
                'success'
            );

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $errorResponse = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'PRODUCT_STATS_ERROR'
            ];

            $this->debugService->logApiRequest(
                '/products/stats',
                $request->get_params(),
                $errorResponse,
                $responseTime,
                'error'
            );

            return new WP_REST_Response($errorResponse, 500);
        }
    }

    /**
     * 匯出商品
     */
    public function export_products(WP_REST_Request $request) {
        $startTime = microtime(true);
        
        try {
            $format = $request->get_param('format');
            $filters = $request->get_param('filters') ?? [];
            
            $exportData = $this->productService->exportProducts($format, $filters);
            
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            // 設定適當的 Content-Type
            $contentType = $this->getExportContentType($format);
            $filename = 'products_export_' . date('Y-m-d_H-i-s') . '.' . $format;
            
            $response = new WP_REST_Response($exportData, 200);
            $response->header('Content-Type', $contentType);
            $response->header('Content-Disposition', "attachment; filename=\"{$filename}\"");

            $this->debugService->logApiRequest(
                '/products/export',
                ['format' => $format, 'filters' => $filters],
                ['export_size' => strlen($exportData)],
                $responseTime,
                'success'
            );

            return $response;

        } catch (\Exception $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $errorResponse = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'PRODUCT_EXPORT_ERROR'
            ];

            $this->debugService->logApiRequest(
                '/products/export',
                $request->get_params(),
                $errorResponse,
                $responseTime,
                'error'
            );

            return new WP_REST_Response($errorResponse, 500);
        }
    }

    /**
     * 複製商品
     */
    public function duplicate_product(WP_REST_Request $request) {
        $startTime = microtime(true);
        $productId = (int)$request->get_param('id');
        
        $this->debugService->log('ProductController', '商品複製請求', [
            'product_id' => $productId,
            'params' => $request->get_params()
        ]);

        try {
            $nameSuffix = $request->get_param('name_suffix');
            $copyInventory = $request->get_param('copy_inventory');
            
            $newProductId = $this->productService->duplicateProduct($productId, $nameSuffix, $copyInventory);
            
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $response = [
                'success' => true,
                'message' => '商品複製成功',
                'data' => [
                    'original_product_id' => $productId,
                    'new_product_id' => $newProductId,
                    'name_suffix' => $nameSuffix,
                    'copy_inventory' => $copyInventory
                ]
            ];

            $this->debugService->logApiRequest(
                "/products/{$productId}/duplicate",
                $request->get_params(),
                $response,
                $responseTime,
                'success'
            );

            return new WP_REST_Response($response, 201);

        } catch (\Exception $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
            $errorResponse = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'PRODUCT_DUPLICATE_ERROR'
            ];

            $this->debugService->logApiRequest(
                "/products/{$productId}/duplicate",
                $request->get_params(),
                $errorResponse,
                $responseTime,
                'error'
            );

            return new WP_REST_Response($errorResponse, 400);
        }
    }

    /**
     * 取得匯出檔案的 Content-Type
     */
    private function getExportContentType(string $format): string
    {
        $contentTypes = [
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
        
        return $contentTypes[$format] ?? 'application/octet-stream';
    }
}
