<?php

namespace BuyGo\Core\Api;

use WP_REST_Request;
use WP_REST_Response;
use BuyGo\Core\Services\OrderService;
use BuyGo\Core\Services\CustomerService;
use BuyGo\Core\Services\DebugService;
use BuyGo\Core\App;

class OrderController extends BaseController {

    private $orderService;
    private $customerService;
    private $debugService;

    public function __construct()
    {
        $this->orderService = new OrderService();
        $this->customerService = new CustomerService();
        $this->debugService = new DebugService();
    }

    public function register_routes() {
        register_rest_route($this->namespace, '/orders', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_read_permission'],
            ]
        ]);
        
        // Get single order details
        register_rest_route($this->namespace, '/orders/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_item'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'update_item'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        ]);

        // Update shipping status
        register_rest_route($this->namespace, '/orders/(?P<id>\d+)/shipping-status', [
            [
                'methods' => 'PUT',
                'callback' => [$this, 'update_shipping_status'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        ]);

        // Consolidate orders
        register_rest_route($this->namespace, '/orders/consolidate', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'consolidate_orders'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        ]);

        // Get consolidation candidates
        register_rest_route($this->namespace, '/orders/consolidation-candidates/(?P<customer_id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_consolidation_candidates'],
                'permission_callback' => [$this, 'check_read_permission'],
            ]
        ]);

        // Customer data endpoints
        register_rest_route($this->namespace, '/customers/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_customer'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'update_customer'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        ]);

        // Task 32: 運送狀態擴充 - 新增 API 端點
        // 取得所有可用的運送狀態
        register_rest_route($this->namespace, '/orders/shipping-statuses', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_shipping_statuses'],
                'permission_callback' => [$this, 'check_read_permission'],
            ]
        ]);

        // 取得當前狀態可變更的狀態列表
        register_rest_route($this->namespace, '/orders/shipping-statuses/available/(?P<current_status>[a-z_]+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_available_shipping_statuses'],
                'permission_callback' => [$this, 'check_read_permission'],
            ]
        ]);

        // 批量更新運送狀態
        register_rest_route($this->namespace, '/orders/batch/shipping-status', [
            [
                'methods' => 'PUT',
                'callback' => [$this, 'batch_update_shipping_status'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        ]);

        // Task 33: 訂單合併功能核心 - 新增 API 端點
        // 取得合併機會分析
        register_rest_route($this->namespace, '/orders/consolidation-opportunities/(?P<customer_id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_consolidation_opportunities'],
                'permission_callback' => [$this, 'check_read_permission'],
            ]
        ]);

        // 執行訂單合併
        register_rest_route($this->namespace, '/orders/execute-consolidation', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'execute_consolidation'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        ]);

        // 取得合併訂單詳情
        register_rest_route($this->namespace, '/orders/consolidated/(?P<id>[a-zA-Z0-9\-]+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_consolidated_order'],
                'permission_callback' => [$this, 'check_read_permission'],
            ]
        ]);

        // 取得運送狀態統計
        register_rest_route($this->namespace, '/orders/shipping-statistics', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_shipping_statistics'],
                'permission_callback' => [$this, 'check_read_permission'],
            ]
        ]);

        // FluentCart Webhook 端點
        register_rest_route($this->namespace, '/fluentcart', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_fluentcart_webhook'],
                'permission_callback' => '__return_true', // 允許外部訪問
            ]
        ]);
    }

    public function check_permission() {
        return current_user_can('manage_options') || current_user_can('buygo_admin');
    }
    
    public function check_read_permission($request) {
        $user = wp_get_current_user();
        
        // Allow admin, buygo_admin, buygo_seller, and buygo_helper
        return in_array('administrator', (array)$user->roles) || 
               in_array('buygo_admin', (array)$user->roles) ||
               in_array('buygo_seller', (array)$user->roles) || 
               in_array('buygo_helper', (array)$user->roles);
    }

    /**
     * Get Orders List (使用新的 OrderService)
     */
    public function get_items(WP_REST_Request $request) {
        $this->debugService->log('OrderController', '開始取得訂單列表', [
            'params' => $request->get_params()
        ]);

        try {
            // 準備篩選條件
            $filters = [];
            
            if ($request->get_param('status')) {
                $filters['status'] = sanitize_text_field($request->get_param('status'));
            }
            
            if ($request->get_param('payment_status')) {
                $filters['payment_status'] = sanitize_text_field($request->get_param('payment_status'));
            }
            
            if ($request->get_param('shipping_status')) {
                $filters['shipping_status'] = sanitize_text_field($request->get_param('shipping_status'));
            }
            
            if ($request->get_param('search')) {
                $filters['search'] = sanitize_text_field($request->get_param('search'));
            }

            // 判斷顯示模式
            $user = wp_get_current_user();
            $isAdmin = in_array('administrator', (array)$user->roles, true) || 
                      in_array('buygo_admin', (array)$user->roles, true);
            $viewMode = $isAdmin ? 'backend' : 'frontend';

            // 使用 OrderService 取得訂單
            $orders = $this->orderService->getOrdersWithCustomerData($filters, $viewMode);

            $this->debugService->log('OrderController', '成功取得訂單列表', [
                'count' => count($orders),
                'viewMode' => $viewMode
            ]);

            return new WP_REST_Response([
                'success' => true,
                'data' => $orders,
                'meta' => [
                    'total' => count($orders),
                    'view_mode' => $viewMode,
                    'filters' => $filters
                ]
            ], 200);

        } catch (\Exception $e) {
            $this->debugService->log('OrderController', '取得訂單列表失敗', [
                'error' => $e->getMessage(),
                'params' => $request->get_params()
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'message' => '取得訂單列表失敗：' . $e->getMessage(),
                'debug_info' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }
    
    public function get_item($request) {
        $order_id = $request->get_param('id');
        
        global $wpdb;
        $table_orders = $wpdb->prefix . 'fct_orders';
        $table_items = $wpdb->prefix . 'fct_order_items';
        $table_posts = $wpdb->posts;
        $table_customers = $wpdb->prefix . 'fct_customers';
        
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT o.*, c.first_name, c.last_name, c.email 
             FROM {$table_orders} o
             LEFT JOIN {$table_customers} c ON o.customer_id = c.id
             WHERE o.id = %d",
            $order_id
        ));
        
        if (!$order) {
            return new WP_REST_Response([
                'success' => false,
                'message' => '訂單不存在'
            ], 404);
        }
        
        // 取得訂單項目
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT oi.*, p.post_title as product_name
             FROM {$table_items} oi
             LEFT JOIN {$table_posts} p ON oi.post_id = p.ID
             WHERE oi.order_id = %d",
            $order_id
        ), ARRAY_A);
        
        // 取得賣家資訊
        $sellers_sql = $wpdb->prepare("
            SELECT DISTINCT p.post_author
            FROM {$table_items} oi
            LEFT JOIN {$table_posts} p ON oi.post_id = p.ID
            WHERE oi.order_id = %d
            AND p.post_type = 'fluent-products'
            AND p.post_author IS NOT NULL
        ", $order_id);
        
        $seller_ids = $wpdb->get_col($sellers_sql);
        $sellers = [];
        foreach ($seller_ids as $seller_id) {
            $seller_user = get_userdata($seller_id);
            if ($seller_user) {
                $sellers[] = [
                    'id' => $seller_user->ID,
                    'name' => $seller_user->display_name ?: $seller_user->user_login
                ];
            }
        }
        
        // 格式化總額
        $total = $order->total_amount ?? 0;
        if (is_numeric($total) && $total > 10000) {
            $total = $total / 100;
        }
        
        $customer_name = 'Guest';
        if (!empty($order->first_name) || !empty($order->last_name)) {
            $customer_name = trim(($order->first_name ?? '') . ' ' . ($order->last_name ?? ''));
        }
        
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'id' => (int)$order->id,
                'order_number' => '#' . $order->id,
                'customer_name' => $customer_name,
                'customer_email' => $order->email ?? '',
                'status' => $order->status ?? 'pending',
                'payment_status' => $order->payment_status ?? 'pending',
                'total' => $total,
                'currency' => $order->currency ?? 'TWD',
                'created_at' => $order->created_at ?? '',
                'item_count' => count($items),
                'items' => $items,
                'sellers' => $sellers
            ]
        ], 200);
    }
    
    /**
     * 更新訂單
     */
    public function update_item(WP_REST_Request $request) {
        $order_id = $request->get_param('id');
        $params = $request->get_json_params();
        
        global $wpdb;
        $table_orders = $wpdb->prefix . 'fct_orders';
        
        // 檢查訂單是否存在
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_orders} WHERE id = %d",
            $order_id
        ));
        
        if (!$order) {
            return new WP_REST_Response([
                'success' => false,
                'message' => '訂單不存在'
            ], 404);
        }
        
        $update_data = [];
        $update_format = [];
        
        // 更新訂單狀態
        if (isset($params['status'])) {
            $valid_statuses = ['pending', 'processing', 'completed', 'cancelled', 'refunded'];
            if (in_array($params['status'], $valid_statuses)) {
                $update_data['status'] = sanitize_text_field($params['status']);
                $update_format[] = '%s';
                
                // 如果狀態改為 completed，設定 completed_at
                if ($params['status'] === 'completed' && empty($order->completed_at)) {
                    $update_data['completed_at'] = current_time('mysql');
                    $update_format[] = '%s';
                }
            }
        }
        
        // 更新付款狀態
        if (isset($params['payment_status'])) {
            $valid_payment_statuses = ['pending', 'paid', 'refunded', 'failed', 'partially_paid', 'partially_refunded'];
            if (in_array($params['payment_status'], $valid_payment_statuses)) {
                $update_data['payment_status'] = sanitize_text_field($params['payment_status']);
                $update_format[] = '%s';
            }
        }
        
        if (empty($update_data)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => '沒有需要更新的資料'
            ], 400);
        }
        
        $update_data['updated_at'] = current_time('mysql');
        $update_format[] = '%s';
        
        $result = $wpdb->update(
            $table_orders,
            $update_data,
            ['id' => $order_id],
            $update_format,
            ['%d']
        );
        
        if ($result === false) {
            return new WP_REST_Response([
                'success' => false,
                'message' => '更新失敗'
            ], 500);
        }
        
        // 觸發 WordPress action
        do_action('buygo_order_updated', $order_id, $update_data, $order);
        
        return new WP_REST_Response([
            'success' => true,
            'message' => '訂單已更新'
        ], 200);
    }

    /**
     * 更新運送狀態
     */
    public function update_shipping_status(WP_REST_Request $request) {
        $orderId = $request->get_param('id');
        $params = $request->get_json_params();

        $this->debugService->log('OrderController', '開始更新運送狀態', [
            'order_id' => $orderId,
            'params' => $params
        ]);

        try {
            if (empty($params['status'])) {
                throw new \Exception('缺少必要參數：status');
            }

            $status = sanitize_text_field($params['status']);
            $reason = isset($params['reason']) ? sanitize_text_field($params['reason']) : '';

            $result = $this->orderService->updateShippingStatus($orderId, $status, $reason);

            if ($result) {
                return new WP_REST_Response([
                    'success' => true,
                    'message' => '運送狀態已更新'
                ], 200);
            } else {
                throw new \Exception('運送狀態更新失敗');
            }

        } catch (\Exception $e) {
            $this->debugService->log('OrderController', '更新運送狀態失敗', [
                'error' => $e->getMessage(),
                'order_id' => $orderId
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
                'debug_info' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * 合併訂單
     */
    public function consolidate_orders(WP_REST_Request $request) {
        $params = $request->get_json_params();

        $this->debugService->log('OrderController', '開始合併訂單', [
            'params' => $params
        ]);

        try {
            if (empty($params['customer_id']) || empty($params['order_items'])) {
                throw new \Exception('缺少必要參數：customer_id 或 order_items');
            }

            $customerId = (int)$params['customer_id'];
            $orderItems = $params['order_items'];

            $consolidatedOrderId = $this->orderService->consolidateOrders($customerId, $orderItems);

            return new WP_REST_Response([
                'success' => true,
                'message' => '訂單合併成功',
                'data' => [
                    'consolidated_order_id' => $consolidatedOrderId
                ]
            ], 200);

        } catch (\Exception $e) {
            $this->debugService->log('OrderController', '合併訂單失敗', [
                'error' => $e->getMessage(),
                'params' => $params
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
                'debug_info' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * 取得合併候選訂單
     */
    public function get_consolidation_candidates(WP_REST_Request $request) {
        $customerId = (int)$request->get_param('customer_id');

        $this->debugService->log('OrderController', '開始取得合併候選', [
            'customer_id' => $customerId
        ]);

        try {
            $candidates = $this->orderService->getConsolidationCandidates($customerId);

            return new WP_REST_Response([
                'success' => true,
                'data' => $candidates
            ], 200);

        } catch (\Exception $e) {
            $this->debugService->log('OrderController', '取得合併候選失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得客戶資料
     */
    public function get_customer(WP_REST_Request $request) {
        $customerId = (int)$request->get_param('id');

        $this->debugService->log('OrderController', '開始取得客戶資料', [
            'customer_id' => $customerId
        ]);

        try {
            // 判斷是否為後台
            $user = wp_get_current_user();
            $isAdmin = in_array('administrator', (array)$user->roles, true) || 
                      in_array('buygo_admin', (array)$user->roles, true);

            $customerData = $this->customerService->getCustomerData($customerId, $isAdmin);

            if (!$customerData) {
                throw new \Exception('客戶不存在');
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => $customerData
            ], 200);

        } catch (\Exception $e) {
            $this->debugService->log('OrderController', '取得客戶資料失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 更新客戶資料
     */
    public function update_customer(WP_REST_Request $request) {
        $customerId = (int)$request->get_param('id');
        $params = $request->get_json_params();

        $this->debugService->log('OrderController', '開始更新客戶資料', [
            'customer_id' => $customerId,
            'params' => $params
        ]);

        try {
            $result = $this->customerService->updateCustomerData($customerId, $params);

            if ($result) {
                return new WP_REST_Response([
                    'success' => true,
                    'message' => '客戶資料已更新'
                ], 200);
            } else {
                throw new \Exception('客戶資料更新失敗');
            }

        } catch (\Exception $e) {
            $this->debugService->log('OrderController', '更新客戶資料失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
                'debug_info' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * Task 32: 運送狀態擴充 - 取得所有運送狀態
     */
    public function get_shipping_statuses(WP_REST_Request $request) {
        $this->debugService->log('OrderController', '開始取得運送狀態列表', [
            'include_metadata' => $request->get_param('include_metadata')
        ]);

        try {
            $includeMetadata = $request->get_param('include_metadata') === 'true';
            $statuses = $this->orderService->getAvailableShippingStatuses();

            return new WP_REST_Response([
                'success' => true,
                'data' => $statuses
            ], 200);

        } catch (\Exception $e) {
            $this->debugService->log('OrderController', '取得運送狀態列表失敗', [
                'error' => $e->getMessage()
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Task 32: 運送狀態擴充 - 取得可變更的狀態列表
     */
    public function get_available_shipping_statuses(WP_REST_Request $request) {
        $currentStatus = $request->get_param('current_status');

        $this->debugService->log('OrderController', '開始取得可變更狀態列表', [
            'current_status' => $currentStatus
        ]);

        try {
            $availableStatuses = $this->orderService->getAvailableShippingStatuses($currentStatus);

            return new WP_REST_Response([
                'success' => true,
                'data' => $availableStatuses,
                'meta' => [
                    'current_status' => $currentStatus
                ]
            ], 200);

        } catch (\Exception $e) {
            $this->debugService->log('OrderController', '取得可變更狀態列表失敗', [
                'error' => $e->getMessage(),
                'current_status' => $currentStatus
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Task 32: 運送狀態擴充 - 批量更新運送狀態
     */
    public function batch_update_shipping_status(WP_REST_Request $request) {
        $params = $request->get_json_params();

        $this->debugService->log('OrderController', '開始批量更新運送狀態', [
            'params' => $params
        ]);

        try {
            if (empty($params['order_ids']) || empty($params['status'])) {
                throw new \Exception('缺少必要參數：order_ids 或 status');
            }

            $orderIds = array_map('intval', $params['order_ids']);
            $status = sanitize_text_field($params['status']);
            $reason = isset($params['reason']) ? sanitize_text_field($params['reason']) : '';

            $results = $this->orderService->batchUpdateShippingStatus($orderIds, $status, $reason);

            return new WP_REST_Response([
                'success' => true,
                'message' => '批量更新完成',
                'data' => $results
            ], 200);

        } catch (\Exception $e) {
            $this->debugService->log('OrderController', '批量更新運送狀態失敗', [
                'error' => $e->getMessage(),
                'params' => $params
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
                'debug_info' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * Task 32: 運送狀態擴充 - 取得運送狀態統計
     */
    public function get_shipping_statistics(WP_REST_Request $request) {
        $this->debugService->log('OrderController', '開始取得運送狀態統計', [
            'params' => $request->get_params()
        ]);

        try {
            // 準備篩選條件
            $filters = [];
            
            if ($request->get_param('date_from')) {
                $filters['date_from'] = sanitize_text_field($request->get_param('date_from'));
            }
            
            if ($request->get_param('date_to')) {
                $filters['date_to'] = sanitize_text_field($request->get_param('date_to'));
            }

            // 使用 ShippingStatusService 取得統計
            $shippingStatusService = new \BuyGo\Core\Services\ShippingStatusService();
            $statistics = $shippingStatusService->getStatusStatistics($filters);

            return new WP_REST_Response([
                'success' => true,
                'data' => $statistics,
                'meta' => [
                    'filters' => $filters
                ]
            ], 200);

        } catch (\Exception $e) {
            $this->debugService->log('OrderController', '取得運送狀態統計失敗', [
                'error' => $e->getMessage(),
                'params' => $request->get_params()
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
                'debug_info' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * Task 33: 訂單合併功能核心 - 取得合併機會分析
     */
    public function get_consolidation_opportunities(WP_REST_Request $request) {
        $customerId = (int)$request->get_param('customer_id');

        $this->debugService->log('OrderController', '開始取得合併機會分析', [
            'customer_id' => $customerId
        ]);

        try {
            // 準備篩選條件
            $filters = [];
            
            if ($request->get_param('date_from')) {
                $filters['date_from'] = sanitize_text_field($request->get_param('date_from'));
            }
            
            if ($request->get_param('date_to')) {
                $filters['date_to'] = sanitize_text_field($request->get_param('date_to'));
            }

            $opportunities = $this->orderService->getConsolidationCandidates($customerId);

            return new WP_REST_Response([
                'success' => true,
                'data' => $opportunities,
                'meta' => [
                    'customer_id' => $customerId,
                    'filters' => $filters
                ]
            ], 200);

        } catch (\Exception $e) {
            $this->debugService->log('OrderController', '取得合併機會分析失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
                'debug_info' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * Task 33: 訂單合併功能核心 - 執行訂單合併
     */
    public function execute_consolidation(WP_REST_Request $request) {
        $params = $request->get_json_params();

        $this->debugService->log('OrderController', '開始執行訂單合併', [
            'params' => $params
        ]);

        try {
            if (empty($params['customer_id']) || empty($params['consolidation_plan'])) {
                throw new \Exception('缺少必要參數：customer_id 或 consolidation_plan');
            }

            $customerId = (int)$params['customer_id'];
            $consolidationPlan = $params['consolidation_plan'];

            $consolidatedOrderId = $this->orderService->executeOrderConsolidation($customerId, $consolidationPlan);

            return new WP_REST_Response([
                'success' => true,
                'message' => '訂單合併執行成功',
                'data' => [
                    'consolidated_order_id' => $consolidatedOrderId,
                    'customer_id' => $customerId,
                    'merged_orders_count' => count($consolidationPlan['orders'] ?? [])
                ]
            ], 200);

        } catch (\Exception $e) {
            $this->debugService->log('OrderController', '執行訂單合併失敗', [
                'error' => $e->getMessage(),
                'params' => $params
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
                'debug_info' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * Task 33: 訂單合併功能核心 - 取得合併訂單詳情
     */
    public function get_consolidated_order(WP_REST_Request $request) {
        $consolidatedOrderId = $request->get_param('id');

        $this->debugService->log('OrderController', '開始取得合併訂單詳情', [
            'consolidated_order_id' => $consolidatedOrderId
        ]);

        try {
            $orderDetails = $this->orderService->getConsolidatedOrderDetails($consolidatedOrderId);

            if (!$orderDetails) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => '合併訂單不存在'
                ], 404);
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => $orderDetails
            ], 200);

        } catch (\Exception $e) {
            $this->debugService->log('OrderController', '取得合併訂單詳情失敗', [
                'error' => $e->getMessage(),
                'consolidated_order_id' => $consolidatedOrderId
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
                'debug_info' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * 處理 FluentCart Webhook
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_fluentcart_webhook(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $this->debugService->log('FluentCart_Webhook_API', '收到 Webhook 請求', [
                'method' => $request->get_method(),
                'headers' => $request->get_headers(),
                'params' => $request->get_params()
            ]);

            // 取得請求資料
            $data = $request->get_json_params();
            
            if (empty($data)) {
                $this->debugService->log('FluentCart_Webhook_API', '空的請求資料', [], 'warning');
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Empty request data'
                ], 400);
            }

            // 載入 ContactDataService
            $contactDataService = new \BuyGo\Core\Services\ContactDataService();
            
            $processedCount = 0;
            $errors = [];

            // 處理訂單資料
            if (isset($data['customer_email']) || isset($data['email'])) {
                // 單一訂單資料
                $result = $this->processFluentCartOrderData($data, $contactDataService);
                if ($result['success']) {
                    $processedCount++;
                } else {
                    $errors[] = $result['error'];
                }
            } elseif (is_array($data) && isset($data[0])) {
                // 多個訂單資料
                foreach ($data as $order) {
                    if (isset($order['customer_email']) || isset($order['email'])) {
                        $result = $this->processFluentCartOrderData($order, $contactDataService);
                        if ($result['success']) {
                            $processedCount++;
                        } else {
                            $errors[] = $result['error'];
                        }
                    }
                }
            } else {
                $this->debugService->log('FluentCart_Webhook_API', '未知的資料格式', $data, 'warning');
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Unknown data format'
                ], 400);
            }

            // 回應結果
            $response = [
                'success' => true,
                'processed' => $processedCount,
                'errors' => $errors,
                'timestamp' => current_time('mysql')
            ];

            $this->debugService->log('FluentCart_Webhook_API', 'Webhook 處理完成', $response);

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            $this->debugService->log('FluentCart_Webhook_API', 'Webhook 處理錯誤', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            return new WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 處理單一 FluentCart 訂單資料
     * 
     * @param array $order 訂單資料
     * @param \BuyGo\Core\Services\ContactDataService $contactDataService
     * @return array 處理結果
     */
    private function processFluentCartOrderData($order, $contactDataService): array
    {
        try {
            // 取得客戶 email
            $email = $order['customer_email'] ?? $order['email'] ?? '';
            
            if (empty($email)) {
                return ['success' => false, 'error' => 'Missing customer email'];
            }

            $this->debugService->log('FluentCart_Webhook_API', '處理訂單資料', [
                'email' => $email,
                'order_id' => $order['id'] ?? 'unknown'
            ]);

            // 尋找對應的 FluentCart 客戶
            global $wpdb;
            $customer = $wpdb->get_row($wpdb->prepare("
                SELECT id, email FROM {$wpdb->prefix}fct_customers 
                WHERE email = %s
            ", $email), ARRAY_A);

            if (!$customer) {
                $this->debugService->log('FluentCart_Webhook_API', '找不到對應的 FluentCart 客戶', ['email' => $email]);
                return ['success' => false, 'error' => "No FluentCart customer found for email: $email"];
            }

            $customerId = $customer['id'];
            $updated = false;

            // 處理帳單地址中的電話
            if (isset($order['billing_phone']) && !empty($order['billing_phone'])) {
                $phone = $order['billing_phone'];
                
                // 清理電話號碼格式
                $phone = preg_replace('/[^\d+\-\(\)\s]/', '', $phone);
                
                if (!empty($phone)) {
                    $result = $contactDataService->updateCustomerPhone(
                        $customerId, 
                        $email, 
                        $phone, 
                        'fluentcart_webhook'
                    );
                    
                    if ($result) {
                        $updated = true;
                        $this->debugService->log('FluentCart_Webhook_API', '帳單電話更新成功', [
                            'customer_id' => $customerId,
                            'email' => $email,
                            'phone' => $phone
                        ]);
                    }
                }
            }

            // 處理運送地址中的電話
            if (isset($order['shipping_phone']) && !empty($order['shipping_phone'])) {
                $phone = $order['shipping_phone'];
                
                // 清理電話號碼格式
                $phone = preg_replace('/[^\d+\-\(\)\s]/', '', $phone);
                
                if (!empty($phone)) {
                    $result = $contactDataService->updateCustomerPhone(
                        $customerId, 
                        $email, 
                        $phone, 
                        'fluentcart_webhook'
                    );
                    
                    if ($result) {
                        $updated = true;
                        $this->debugService->log('FluentCart_Webhook_API', '運送電話更新成功', [
                            'customer_id' => $customerId,
                            'email' => $email,
                            'phone' => $phone
                        ]);
                    }
                }
            }

            // 處理帳單地址
            $billingAddress = $this->extractAddressDataFromOrder($order, 'billing');
            if (!empty($billingAddress)) {
                $result = $contactDataService->updateCustomerAddress(
                    $customerId,
                    $email,
                    $billingAddress,
                    'billing',
                    'fluentcart_webhook'
                );

                if ($result) {
                    $updated = true;
                    $this->debugService->log('FluentCart_Webhook_API', '帳單地址更新成功', [
                        'customer_id' => $customerId,
                        'email' => $email,
                        'address_data' => $billingAddress
                    ]);
                }
            }

            // 處理運送地址
            $shippingAddress = $this->extractAddressDataFromOrder($order, 'shipping');
            if (!empty($shippingAddress)) {
                $result = $contactDataService->updateCustomerAddress(
                    $customerId,
                    $email,
                    $shippingAddress,
                    'shipping',
                    'fluentcart_webhook'
                );

                if ($result) {
                    $updated = true;
                    $this->debugService->log('FluentCart_Webhook_API', '運送地址更新成功', [
                        'customer_id' => $customerId,
                        'email' => $email,
                        'address_data' => $shippingAddress
                    ]);
                }
            }

            if ($updated) {
                return ['success' => true, 'message' => "Updated contact data for $email"];
            } else {
                return ['success' => true, 'message' => "No updates needed for $email"];
            }

        } catch (\Exception $e) {
            $this->debugService->log('FluentCart_Webhook_API', '處理訂單資料錯誤', [
                'email' => $order['customer_email'] ?? $order['email'] ?? 'unknown',
                'error' => $e->getMessage()
            ], 'error');

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 從訂單資料中提取地址資訊
     * 
     * @param array $order 訂單資料
     * @param string $type 地址類型 (billing 或 shipping)
     * @return array 地址資料
     */
    private function extractAddressDataFromOrder($order, $type): array
    {
        $addressData = [];
        
        // 地址欄位對應
        $fields = [
            'first_name' => $type . '_first_name',
            'last_name' => $type . '_last_name',
            'company' => $type . '_company',
            'address_line_1' => $type . '_address_1',
            'address_line_2' => $type . '_address_2',
            'city' => $type . '_city',
            'state' => $type . '_state',
            'postcode' => $type . '_postcode',
            'country' => $type . '_country'
        ];

        foreach ($fields as $standard => $orderField) {
            if (isset($order[$orderField]) && !empty($order[$orderField])) {
                $addressData[$standard] = $order[$orderField];
            }
        }

        return $addressData;
    }
}