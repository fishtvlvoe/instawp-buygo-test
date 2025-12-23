<?php

namespace BuyGo\Core\Services;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Customer;
use BuyGo\Core\Services\DebugService;
use BuyGo\Core\Services\CustomerService;
use BuyGo\Core\Services\ShippingStatusService;
use BuyGo\Core\Services\OrderConsolidationService;

/**
 * Order Service - 訂單管理服務
 * 
 * 整合 FluentCart 訂單功能並添加 BuyGo 特有的業務邏輯
 * 
 * @package BuyGo\Core\Services
 * @version 1.0.0
 */
class OrderService
{
    private $debugService;
    private $customerService;
    private $shippingStatusService;
    private $consolidationService;

    public function __construct()
    {
        $this->debugService = new DebugService();
        $this->customerService = new CustomerService();
        $this->shippingStatusService = new ShippingStatusService();
        $this->consolidationService = new OrderConsolidationService();
    }

    /**
     * 取得訂單列表（包含客戶資料）
     * 
     * @param array $filters 篩選條件
     * @param string $viewMode 顯示模式 frontend|backend
     * @return array
     */
    public function getOrdersWithCustomerData(array $filters = [], string $viewMode = 'frontend'): array
    {
        $this->debugService->log('OrderService', '開始取得訂單列表', [
            'filters' => $filters,
            'viewMode' => $viewMode
        ]);

        try {
            $user = wp_get_current_user();
            $isAdmin = in_array('administrator', (array)$user->roles, true) || 
                      in_array('buygo_admin', (array)$user->roles, true);

            // 建立查詢
            $query = Order::query()
                ->with(['customer', 'order_items.product'])
                ->orderBy('id', 'desc');

            // 權限篩選
            if (!$isAdmin && $viewMode === 'frontend') {
                $query->whereHas('order_items', function($q) use ($user) {
                    $q->whereHas('product', function($productQuery) use ($user) {
                        $productQuery->where('post_author', $user->ID);
                    });
                });
            }

            // 狀態篩選
            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $query->where('status', $filters['status']);
            }

            // 付款狀態篩選
            if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
                $query->where('payment_status', $filters['payment_status']);
            }

            // 運送狀態篩選
            if (!empty($filters['shipping_status']) && $filters['shipping_status'] !== 'all') {
                $query->where('shipping_status', $filters['shipping_status']);
            }

            // 搜尋篩選
            if (!empty($filters['search'])) {
                $search = $filters['search'];
                if (is_numeric($search)) {
                    $query->where('id', $search);
                } else {
                    $query->whereHas('customer', function($q) use ($search) {
                        $q->where('first_name', 'LIKE', "%{$search}%")
                          ->orWhere('last_name', 'LIKE', "%{$search}%")
                          ->orWhere('email', 'LIKE', "%{$search}%");
                    });
                }
            }

            $orders = $query->limit(100)->get();

            // 格式化資料
            $formattedOrders = [];
            foreach ($orders as $order) {
                // 使用新的 CustomerService 取得完整客戶資料
                $customerData = $this->customerService->getCustomerData(
                    $order->customer_id, 
                    $viewMode === 'backend'
                );
                
                if (!$customerData) {
                    $this->debugService->log('OrderService', '無法取得客戶資料', [
                        'order_id' => $order->id,
                        'customer_id' => $order->customer_id
                    ], 'warning');
                    
                    // 使用預設值
                    $customerData = [
                        'name' => 'Guest',
                        'email' => '',
                        'phone' => '',
                        'address' => '',
                        'data_complete' => false,
                        'missing_fields' => ['name', 'phone', 'address']
                    ];
                }
                
                $orderData = [
                    'id' => $order->id,
                    'order_number' => '#' . $order->id,
                    'invoice_no' => $order->invoice_no,
                    'customer_id' => $order->customer_id,
                    'customer_name' => $customerData['name'],
                    'customer_email' => $customerData['email'],
                    'customer_phone' => $customerData['phone'],
                    'customer_address' => $customerData['address'],
                    'customer_data_complete' => $customerData['data_complete'],
                    'customer_missing_fields' => $customerData['missing_fields'] ?? [],
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'shipping_status' => $order->shipping_status ?? '未出貨',
                    'total_amount' => $order->total_amount / 100, // 轉換為元
                    'formatted_total' => $this->formatPrice($order->total_amount),
                    'currency' => $order->currency ?? 'TWD',
                    'item_count' => $order->order_items->count(),
                    'items' => $this->formatOrderItems($order->order_items, $viewMode),
                    'sellers' => $this->getOrderSellers($order->order_items),
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at
                ];

                // 後台額外欄位
                if ($viewMode === 'backend') {
                    $orderData['status_history'] = $this->getStatusHistory($order->id);
                    $orderData['payment_method'] = $order->payment_method;
                    $orderData['ip_address'] = $order->ip_address;
                    $orderData['note'] = $order->note;
                    
                    // 添加客戶統計資料
                    if (isset($customerData['order_count'])) {
                        $orderData['customer_order_count'] = $customerData['order_count'];
                        $orderData['customer_total_spent'] = $customerData['total_spent'];
                        $orderData['customer_formatted_total_spent'] = $customerData['formatted_total_spent'];
                        $orderData['customer_since'] = $customerData['customer_since'];
                    }
                }

                $formattedOrders[] = $orderData;
            }

            $this->debugService->log('OrderService', '成功取得訂單列表', [
                'count' => count($formattedOrders),
                'viewMode' => $viewMode
            ]);

            return $formattedOrders;

        } catch (\Exception $e) {
            $this->debugService->log('OrderService', '取得訂單列表失敗', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'filters' => $filters
            ], 'error');

            throw new \Exception('無法取得訂單列表：' . $e->getMessage());
        }
    }

    /**
     * 更新運送狀態（使用新的 ShippingStatusService）
     * 
     * @param string $orderId 訂單 ID
     * @param string $status 新狀態
     * @param string $reason 變更原因
     * @return bool
     */
    public function updateShippingStatus(string $orderId, string $status, string $reason = ''): bool
    {
        $this->debugService->log('OrderService', '開始更新運送狀態', [
            'order_id' => $orderId,
            'new_status' => $status,
            'reason' => $reason
        ]);

        try {
            // 驗證狀態有效性
            if (!$this->shippingStatusService->isValidStatus($status)) {
                throw new \Exception("無效的運送狀態：{$status}");
            }

            $order = Order::find($orderId);
            if (!$order) {
                throw new \Exception("訂單不存在：ID {$orderId}");
            }

            $oldStatus = $order->shipping_status ?? 'pending';
            
            // 檢查異常狀態變更
            if ($this->shippingStatusService->isAbnormalStatusChange($oldStatus, $status)) {
                $this->debugService->log('OrderService', '異常狀態變更警告', [
                    'order_id' => $orderId,
                    'old_status' => $oldStatus,
                    'new_status' => $status
                ], 'warning');
            }

            // 更新狀態
            $order->shipping_status = $status;
            if ($status === 'completed' && !$order->completed_at) {
                $order->completed_at = current_time('mysql');
            }
            $order->save();

            // 記錄狀態變更歷史
            $this->shippingStatusService->logStatusChange($orderId, $oldStatus, $status, $reason);

            $this->debugService->log('OrderService', '運送狀態更新成功', [
                'order_id' => $orderId,
                'old_status' => $oldStatus,
                'new_status' => $status
            ]);

            return true;

        } catch (\Exception $e) {
            $this->debugService->log('OrderService', '運送狀態更新失敗', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
                'status' => $status
            ], 'error');

            throw new \Exception('運送狀態更新失敗：' . $e->getMessage());
        }
    }

    /**
     * 批量更新運送狀態
     * 
     * @param array $orderIds 訂單 ID 陣列
     * @param string $status 新狀態
     * @param string $reason 變更原因
     * @return array
     */
    public function batchUpdateShippingStatus(array $orderIds, string $status, string $reason = ''): array
    {
        $this->debugService->log('OrderService', '開始批量更新運送狀態', [
            'order_count' => count($orderIds),
            'new_status' => $status
        ]);

        try {
            $results = $this->shippingStatusService->batchUpdateStatus($orderIds, $status, $reason);

            $this->debugService->log('OrderService', '批量更新運送狀態完成', $results);

            return $results;

        } catch (\Exception $e) {
            $this->debugService->log('OrderService', '批量更新運送狀態失敗', [
                'error' => $e->getMessage()
            ], 'error');

            throw new \Exception('批量更新運送狀態失敗：' . $e->getMessage());
        }
    }

    /**
     * 取得可用的運送狀態
     * 
     * @param string $currentStatus 當前狀態
     * @return array
     */
    public function getAvailableShippingStatuses(string $currentStatus = ''): array
    {
        if ($currentStatus) {
            return $this->shippingStatusService->getAvailableStatuses($currentStatus);
        }

        return $this->shippingStatusService->getAllStatuses(true);
    }

    /**
     * 合併訂單
     * 
     * @param int $customerId 客戶 ID
     * @param array $orderItems 要合併的訂單項目
     * @return string 合併訂單 ID
     */
    public function consolidateOrders(int $customerId, array $orderItems): string
    {
        $this->debugService->log('OrderService', '開始合併訂單', [
            'customer_id' => $customerId,
            'order_items' => $orderItems
        ]);

        try {
            global $wpdb;

            // 驗證客戶存在
            $customer = Customer::find($customerId);
            if (!$customer) {
                throw new \Exception("客戶不存在：ID {$customerId}");
            }

            // 取得要合併的訂單項目
            $itemIds = array_column($orderItems, 'order_item_id');
            $items = OrderItem::whereIn('id', $itemIds)
                ->with(['order'])
                ->get();

            if ($items->isEmpty()) {
                throw new \Exception('沒有找到要合併的訂單項目');
            }

            // 驗證所有項目都屬於同一客戶
            $orderIds = $items->pluck('order_id')->unique();
            $orders = Order::whereIn('id', $orderIds)
                ->where('customer_id', $customerId)
                ->get();

            if ($orders->count() !== $orderIds->count()) {
                throw new \Exception('部分訂單不屬於指定客戶');
            }

            // 計算合併訂單總額
            $totalAmount = 0;
            $consolidatedItems = [];
            
            foreach ($orderItems as $itemData) {
                $item = $items->firstWhere('id', $itemData['order_item_id']);
                if ($item && isset($itemData['quantity']) && $itemData['quantity'] > 0) {
                    $consolidatedItems[] = [
                        'original_order_id' => $item->order_id,
                        'order_item_id' => $item->id,
                        'product_id' => $item->post_id,
                        'variation_id' => $item->object_id,
                        'quantity' => $itemData['quantity'],
                        'price' => $item->item_price,
                        'line_total' => $item->item_price * $itemData['quantity']
                    ];
                    $totalAmount += $item->item_price * $itemData['quantity'];
                }
            }

            if (empty($consolidatedItems)) {
                throw new \Exception('沒有有效的合併項目');
            }

            // 建立合併訂單記錄
            $consolidatedOrderId = 'FC-MERGED-' . time();
            
            $wpdb->insert($wpdb->prefix . 'buygo_consolidated_orders', [
                'id' => $consolidatedOrderId,
                'customer_id' => $customerId,
                'original_order_ids' => wp_json_encode($orderIds->toArray()),
                'consolidated_items' => wp_json_encode($consolidatedItems),
                'total_amount' => $totalAmount,
                'shipping_status' => '未出貨',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);

            // 觸發合併訂單 Webhook
            $this->triggerOrderConsolidationWebhook($consolidatedOrderId, [
                'customer_id' => $customerId,
                'original_order_ids' => $orderIds->toArray(),
                'total_items' => count($consolidatedItems),
                'total_amount' => $totalAmount
            ]);

            $this->debugService->log('OrderService', '訂單合併成功', [
                'consolidated_order_id' => $consolidatedOrderId,
                'customer_id' => $customerId,
                'original_orders' => $orderIds->toArray(),
                'items_count' => count($consolidatedItems)
            ]);

            return $consolidatedOrderId;

        } catch (\Exception $e) {
            $this->debugService->log('OrderService', '訂單合併失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId,
                'order_items' => $orderItems
            ], 'error');

            throw new \Exception('訂單合併失敗：' . $e->getMessage());
        }
    }

    /**
     * 取得可合併的訂單候選
     * 
     * @param int $customerId 客戶 ID
     * @return array
     */
    public function getConsolidationCandidates(int $customerId): array
    {
        try {
            // 使用新的 OrderConsolidationService
            return $this->consolidationService->identifyConsolidationOpportunities($customerId);

        } catch (\Exception $e) {
            $this->debugService->log('OrderService', '取得合併候選失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ], 'error');

            return [];
        }
    }

    /**
     * 執行訂單合併（使用新的 OrderConsolidationService）
     * 
     * @param int $customerId 客戶 ID
     * @param array $consolidationPlan 合併計劃
     * @return string 合併訂單 ID
     */
    public function executeOrderConsolidation(int $customerId, array $consolidationPlan): string
    {
        return $this->consolidationService->executeConsolidation($customerId, $consolidationPlan);
    }

    /**
     * 取得合併訂單詳情
     * 
     * @param string $consolidatedOrderId 合併訂單 ID
     * @return array|null
     */
    public function getConsolidatedOrderDetails(string $consolidatedOrderId): ?array
    {
        return $this->consolidationService->getConsolidatedOrderDetails($consolidatedOrderId);
    }

    /**
     * 取得狀態變更歷史（使用新的 ShippingStatusService）
     * 
     * @param string $orderId 訂單 ID
     * @return array
     */
    public function getStatusHistory(string $orderId): array
    {
        return $this->shippingStatusService->getStatusHistory($orderId);
    }

    /**
     * 格式化訂單項目
     */
    private function formatOrderItems($orderItems, string $viewMode): array
    {
        $items = [];
        foreach ($orderItems as $item) {
            $itemData = [
                'id' => $item->id,
                'product_id' => $item->post_id,
                'variation_id' => $item->object_id,
                'product_name' => $item->post_title,
                'variation_title' => $item->title,
                'quantity' => $item->quantity,
                'price' => $item->item_price / 100,
                'line_total' => $item->line_total / 100,
                'arrival_status' => $item->arrival_status ?? 'pending'
            ];

            if ($viewMode === 'backend') {
                $itemData['seller_id'] = $item->product->post_author ?? 0;
                $itemData['seller_name'] = $this->getSellerName($item->product->post_author ?? 0);
            }

            $items[] = $itemData;
        }
        return $items;
    }

    /**
     * 取得訂單賣家
     */
    private function getOrderSellers($orderItems): array
    {
        $sellers = [];
        $sellerIds = [];

        foreach ($orderItems as $item) {
            $sellerId = $item->product->post_author ?? 0;
            if ($sellerId && !in_array($sellerId, $sellerIds, true)) {
                $sellerIds[] = $sellerId;
                $sellers[] = [
                    'id' => $sellerId,
                    'name' => $this->getSellerName($sellerId)
                ];
            }
        }

        return $sellers;
    }

    /**
     * 取得賣家名稱
     */
    private function getSellerName(int $userId): string
    {
        if (!$userId) {
            return '';
        }

        $user = get_userdata($userId);
        return $user ? ($user->display_name ?: $user->user_login) : '';
    }



    /**
     * 觸發訂單合併 Webhook
     */
    private function triggerOrderConsolidationWebhook(string $consolidatedOrderId, array $orderData): void
    {
        // TODO: 實作 Webhook 觸發邏輯
        do_action('buygo_order_consolidated_webhook', $consolidatedOrderId, $orderData);
    }

    /**
     * 格式化價格顯示
     */
    private function formatPrice(int $priceInCents): string
    {
        return 'NT$ ' . number_format($priceInCents / 100, 2);
    }
}