<?php

namespace BuyGo\Core\Services;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Customer;
use BuyGo\Core\Services\DebugService;
use BuyGo\Core\Services\ShippingStatusService;

/**
 * Order Consolidation Service - 訂單合併服務
 * 
 * Task 33: 實作訂單合併功能核心邏輯
 * 支援同一客戶多張訂單的已到貨商品合併
 * 
 * @package BuyGo\Core\Services
 * @version 1.0.0
 */
class OrderConsolidationService
{
    private $debugService;
    private $shippingStatusService;

    /**
     * 到貨狀態定義
     */
    const ARRIVAL_STATUSES = [
        'pending'   => '未到貨',
        'arrived'   => '已到貨',
        'partial'   => '部分到貨',
        'damaged'   => '損壞',
        'missing'   => '遺失'
    ];

    /**
     * 合併訂單狀態
     */
    const CONSOLIDATION_STATUSES = [
        'pending'    => '待合併',
        'processing' => '合併中',
        'completed'  => '已合併',
        'cancelled'  => '已取消'
    ];

    public function __construct()
    {
        $this->debugService = new DebugService();
        $this->shippingStatusService = new ShippingStatusService();
    }

    /**
     * 自動識別可合併的訂單
     * 
     * @param int $customerId 客戶 ID
     * @param array $filters 篩選條件
     * @return array
     */
    public function identifyConsolidationOpportunities(int $customerId, array $filters = []): array
    {
        $this->debugService->log('OrderConsolidationService', '開始識別合併機會', [
            'customer_id' => $customerId,
            'filters' => $filters
        ]);

        try {
            // 查詢客戶的未完成訂單
            $query = Order::where('customer_id', $customerId)
                ->whereIn('status', ['pending', 'processing', 'confirmed'])
                ->whereNotIn('shipping_status', ['completed', 'cancelled'])
                ->with(['order_items.product']);

            // 日期範圍篩選
            if (!empty($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }

            $orders = $query->get();

            if ($orders->isEmpty()) {
                return [
                    'opportunities' => [],
                    'total_orders' => 0,
                    'total_items' => 0,
                    'estimated_savings' => 0
                ];
            }

            // 分析合併機會
            $opportunities = [];
            $totalArrivedItems = 0;
            $estimatedSavings = 0;

            foreach ($orders as $order) {
                $arrivedItems = $this->getArrivedItems($order);
                
                if (count($arrivedItems) > 0) {
                    $totalArrivedItems += count($arrivedItems);
                    
                    $opportunity = [
                        'order_id' => $order->id,
                        'order_number' => '#' . $order->id,
                        'order_date' => $order->created_at,
                        'total_items' => $order->order_items->count(),
                        'arrived_items' => $arrivedItems,
                        'arrived_count' => count($arrivedItems),
                        'total_amount' => $this->calculateItemsTotal($arrivedItems),
                        'shipping_address' => $this->getShippingAddress($order),
                        'consolidation_priority' => $this->calculateConsolidationPriority($order, $arrivedItems)
                    ];

                    $opportunities[] = $opportunity;
                    
                    // 估算節省的運費（假設每個訂單運費 100 元）
                    if (count($arrivedItems) > 1) {
                        $estimatedSavings += 100;
                    }
                }
            }

            // 按優先級排序
            usort($opportunities, function($a, $b) {
                return $b['consolidation_priority'] <=> $a['consolidation_priority'];
            });

            $result = [
                'opportunities' => $opportunities,
                'total_orders' => count($opportunities),
                'total_items' => $totalArrivedItems,
                'estimated_savings' => $estimatedSavings,
                'recommendation' => $this->generateConsolidationRecommendation($opportunities)
            ];

            $this->debugService->log('OrderConsolidationService', '合併機會識別完成', [
                'customer_id' => $customerId,
                'opportunities_count' => count($opportunities),
                'total_items' => $totalArrivedItems
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->debugService->log('OrderConsolidationService', '識別合併機會失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ], 'error');

            throw new \Exception('識別合併機會失敗：' . $e->getMessage());
        }
    }

    /**
     * 執行訂單合併
     * 
     * @param int $customerId 客戶 ID
     * @param array $consolidationPlan 合併計劃
     * @return string 合併訂單 ID
     */
    public function executeConsolidation(int $customerId, array $consolidationPlan): string
    {
        $this->debugService->log('OrderConsolidationService', '開始執行訂單合併', [
            'customer_id' => $customerId,
            'plan' => $consolidationPlan
        ]);

        try {
            global $wpdb;

            // 驗證客戶存在
            $customer = Customer::find($customerId);
            if (!$customer) {
                throw new \Exception("客戶不存在：ID {$customerId}");
            }

            // 驗證合併計劃
            $this->validateConsolidationPlan($consolidationPlan);

            // 開始資料庫事務
            $wpdb->query('START TRANSACTION');

            try {
                // 建立合併訂單記錄
                $consolidatedOrderId = $this->createConsolidatedOrder($customerId, $consolidationPlan);

                // 更新原訂單項目狀態
                $this->updateOriginalOrderItems($consolidationPlan);

                // 計算並更新運送資訊
                $this->calculateShippingInfo($consolidatedOrderId, $consolidationPlan);

                // 觸發合併完成事件
                $this->triggerConsolidationEvents($consolidatedOrderId, $consolidationPlan);

                // 提交事務
                $wpdb->query('COMMIT');

                $this->debugService->log('OrderConsolidationService', '訂單合併執行成功', [
                    'consolidated_order_id' => $consolidatedOrderId,
                    'customer_id' => $customerId,
                    'merged_orders' => array_column($consolidationPlan['orders'], 'order_id')
                ]);

                return $consolidatedOrderId;

            } catch (\Exception $e) {
                // 回滾事務
                $wpdb->query('ROLLBACK');
                throw $e;
            }

        } catch (\Exception $e) {
            $this->debugService->log('OrderConsolidationService', '訂單合併執行失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId,
                'plan' => $consolidationPlan
            ], 'error');

            throw new \Exception('訂單合併執行失敗：' . $e->getMessage());
        }
    }

    /**
     * 取得已到貨的訂單項目
     * 
     * @param Order $order 訂單
     * @return array
     */
    private function getArrivedItems(Order $order): array
    {
        $arrivedItems = [];

        foreach ($order->order_items as $item) {
            // 檢查到貨狀態
            $arrivalStatus = $item->arrival_status ?? 'pending';
            
            if ($arrivalStatus === 'arrived') {
                $arrivedItems[] = [
                    'id' => $item->id,
                    'order_id' => $order->id,
                    'product_id' => $item->post_id,
                    'variation_id' => $item->object_id,
                    'product_name' => $item->post_title,
                    'variation_title' => $item->title,
                    'quantity' => $item->quantity,
                    'price' => $item->item_price,
                    'line_total' => $item->line_total,
                    'arrival_date' => $item->arrival_date ?? $item->updated_at,
                    'seller_id' => $this->getItemSellerId($item)
                ];
            }
        }

        return $arrivedItems;
    }

    /**
     * 計算項目總金額
     * 
     * @param array $items 項目列表
     * @return int
     */
    private function calculateItemsTotal(array $items): int
    {
        $total = 0;
        foreach ($items as $item) {
            $total += $item['line_total'];
        }
        return $total;
    }

    /**
     * 取得運送地址
     * 
     * @param Order $order 訂單
     * @return array
     */
    private function getShippingAddress(Order $order): array
    {
        return [
            'name' => $order->shipping_first_name . ' ' . $order->shipping_last_name,
            'phone' => $order->shipping_phone,
            'address' => trim(implode(' ', [
                $order->shipping_address_1,
                $order->shipping_address_2,
                $order->shipping_city,
                $order->shipping_state,
                $order->shipping_postcode
            ])),
            'country' => $order->shipping_country
        ];
    }

    /**
     * 計算合併優先級
     * 
     * @param Order $order 訂單
     * @param array $arrivedItems 已到貨項目
     * @return int
     */
    private function calculateConsolidationPriority(Order $order, array $arrivedItems): int
    {
        $priority = 0;

        // 已到貨項目數量權重
        $priority += count($arrivedItems) * 10;

        // 訂單金額權重
        $totalAmount = $this->calculateItemsTotal($arrivedItems);
        $priority += min($totalAmount / 1000, 50); // 最多加 50 分

        // 訂單時間權重（越舊優先級越高）
        $daysSinceOrder = (time() - strtotime($order->created_at)) / (24 * 3600);
        $priority += min($daysSinceOrder * 2, 30); // 最多加 30 分

        // 相同賣家項目權重
        $sellerIds = array_unique(array_column($arrivedItems, 'seller_id'));
        if (count($sellerIds) === 1) {
            $priority += 20; // 同一賣家加 20 分
        }

        return (int)$priority;
    }

    /**
     * 生成合併建議
     * 
     * @param array $opportunities 合併機會
     * @return array
     */
    private function generateConsolidationRecommendation(array $opportunities): array
    {
        if (empty($opportunities)) {
            return [
                'action' => 'none',
                'message' => '目前沒有可合併的訂單',
                'suggestions' => []
            ];
        }

        $totalItems = array_sum(array_column($opportunities, 'arrived_count'));
        $totalOrders = count($opportunities);

        if ($totalItems < 2) {
            return [
                'action' => 'wait',
                'message' => '建議等待更多商品到貨後再進行合併',
                'suggestions' => [
                    '目前只有 ' . $totalItems . ' 個已到貨商品',
                    '建議至少有 2 個以上商品再合併以節省運費'
                ]
            ];
        }

        if ($totalOrders >= 3 && $totalItems >= 5) {
            return [
                'action' => 'consolidate_now',
                'message' => '強烈建議立即合併訂單',
                'suggestions' => [
                    "可合併 {$totalOrders} 張訂單的 {$totalItems} 個商品",
                    '預估可節省運費約 ' . (($totalOrders - 1) * 100) . ' 元',
                    '建議優先合併相同賣家的商品'
                ]
            ];
        }

        return [
            'action' => 'consider',
            'message' => '可考慮進行訂單合併',
            'suggestions' => [
                "目前有 {$totalOrders} 張訂單的 {$totalItems} 個商品可合併",
                '合併後可節省部分運費',
                '建議檢查商品是否來自相同賣家'
            ]
        ];
    }

    /**
     * 驗證合併計劃
     * 
     * @param array $plan 合併計劃
     * @return void
     * @throws \Exception
     */
    private function validateConsolidationPlan(array $plan): void
    {
        if (empty($plan['orders']) || !is_array($plan['orders'])) {
            throw new \Exception('合併計劃缺少訂單資訊');
        }

        if (count($plan['orders']) < 2) {
            throw new \Exception('至少需要 2 張訂單才能進行合併');
        }

        foreach ($plan['orders'] as $orderPlan) {
            if (empty($orderPlan['order_id']) || empty($orderPlan['items'])) {
                throw new \Exception('訂單計劃格式錯誤');
            }

            if (!is_array($orderPlan['items']) || empty($orderPlan['items'])) {
                throw new \Exception('訂單必須包含至少一個項目');
            }
        }
    }

    /**
     * 建立合併訂單記錄
     * 
     * @param int $customerId 客戶 ID
     * @param array $plan 合併計劃
     * @return string
     */
    private function createConsolidatedOrder(int $customerId, array $plan): string
    {
        global $wpdb;

        $consolidatedOrderId = 'BUYGO-MERGED-' . time() . '-' . $customerId;
        
        // 計算合併訂單資訊
        $originalOrderIds = array_column($plan['orders'], 'order_id');
        $allItems = [];
        $totalAmount = 0;

        foreach ($plan['orders'] as $orderPlan) {
            foreach ($orderPlan['items'] as $item) {
                $allItems[] = $item;
                $totalAmount += $item['line_total'];
            }
        }

        // 插入合併訂單記錄
        $result = $wpdb->insert($wpdb->prefix . 'buygo_consolidated_orders', [
            'id' => $consolidatedOrderId,
            'customer_id' => $customerId,
            'original_order_ids' => wp_json_encode($originalOrderIds),
            'consolidated_items' => wp_json_encode($allItems),
            'total_amount' => $totalAmount,
            'item_count' => count($allItems),
            'shipping_status' => 'pending',
            'consolidation_status' => 'completed',
            'consolidation_date' => current_time('mysql'),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);

        if ($result === false) {
            throw new \Exception('建立合併訂單記錄失敗：' . $wpdb->last_error);
        }

        return $consolidatedOrderId;
    }

    /**
     * 更新原訂單項目狀態
     * 
     * @param array $plan 合併計劃
     * @return void
     */
    private function updateOriginalOrderItems(array $plan): void
    {
        global $wpdb;

        foreach ($plan['orders'] as $orderPlan) {
            foreach ($orderPlan['items'] as $item) {
                // 更新項目狀態為已合併
                $wpdb->update(
                    $wpdb->prefix . 'fct_order_items',
                    [
                        'consolidation_status' => 'consolidated',
                        'consolidation_date' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $item['id']],
                    ['%s', '%s', '%s'],
                    ['%d']
                );
            }
        }
    }

    /**
     * 計算運送資訊
     * 
     * @param string $consolidatedOrderId 合併訂單 ID
     * @param array $plan 合併計劃
     * @return void
     */
    private function calculateShippingInfo(string $consolidatedOrderId, array $plan): void
    {
        // 取得第一個訂單的運送資訊作為基準
        $firstOrderId = $plan['orders'][0]['order_id'];
        $firstOrder = Order::find($firstOrderId);

        if ($firstOrder) {
            global $wpdb;
            
            // 更新合併訂單的運送資訊
            $wpdb->update(
                $wpdb->prefix . 'buygo_consolidated_orders',
                [
                    'shipping_address' => wp_json_encode([
                        'first_name' => $firstOrder->shipping_first_name,
                        'last_name' => $firstOrder->shipping_last_name,
                        'phone' => $firstOrder->shipping_phone,
                        'address_1' => $firstOrder->shipping_address_1,
                        'address_2' => $firstOrder->shipping_address_2,
                        'city' => $firstOrder->shipping_city,
                        'state' => $firstOrder->shipping_state,
                        'postcode' => $firstOrder->shipping_postcode,
                        'country' => $firstOrder->shipping_country
                    ]),
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $consolidatedOrderId],
                ['%s', '%s'],
                ['%s']
            );
        }
    }

    /**
     * 觸發合併完成事件
     * 
     * @param string $consolidatedOrderId 合併訂單 ID
     * @param array $plan 合併計劃
     * @return void
     */
    private function triggerConsolidationEvents(string $consolidatedOrderId, array $plan): void
    {
        $originalOrderIds = array_column($plan['orders'], 'order_id');
        $totalItems = 0;
        
        foreach ($plan['orders'] as $orderPlan) {
            $totalItems += count($orderPlan['items']);
        }

        // 觸發 WordPress actions
        do_action('buygo_orders_consolidated', $consolidatedOrderId, $originalOrderIds);
        do_action('buygo_consolidation_completed', [
            'consolidated_order_id' => $consolidatedOrderId,
            'original_order_ids' => $originalOrderIds,
            'total_items' => $totalItems,
            'consolidation_date' => current_time('mysql')
        ]);

        $this->debugService->log('OrderConsolidationService', '合併事件已觸發', [
            'consolidated_order_id' => $consolidatedOrderId,
            'original_orders' => $originalOrderIds,
            'total_items' => $totalItems
        ]);
    }

    /**
     * 取得項目賣家 ID
     * 
     * @param OrderItem $item 訂單項目
     * @return int
     */
    private function getItemSellerId(OrderItem $item): int
    {
        if ($item->product && $item->product->post_author) {
            return (int)$item->product->post_author;
        }

        // 如果沒有關聯的商品，嘗試從 posts 表查詢
        global $wpdb;
        $sellerId = $wpdb->get_var($wpdb->prepare(
            "SELECT post_author FROM {$wpdb->posts} WHERE ID = %d",
            $item->post_id
        ));

        return $sellerId ? (int)$sellerId : 0;
    }

    /**
     * 取得合併訂單詳情
     * 
     * @param string $consolidatedOrderId 合併訂單 ID
     * @return array|null
     */
    public function getConsolidatedOrderDetails(string $consolidatedOrderId): ?array
    {
        try {
            global $wpdb;
            
            $table = $wpdb->prefix . 'buygo_consolidated_orders';
            $order = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %s",
                $consolidatedOrderId
            ), ARRAY_A);

            if (!$order) {
                return null;
            }

            // 解析 JSON 資料
            $order['original_order_ids'] = json_decode($order['original_order_ids'], true);
            $order['consolidated_items'] = json_decode($order['consolidated_items'], true);
            $order['shipping_address'] = json_decode($order['shipping_address'], true);

            // 格式化金額
            $order['formatted_total_amount'] = 'NT$ ' . number_format($order['total_amount'] / 100, 2);

            // 取得客戶資訊
            $customer = Customer::find($order['customer_id']);
            if ($customer) {
                $order['customer_name'] = trim($customer->first_name . ' ' . $customer->last_name);
                $order['customer_email'] = $customer->email;
            }

            return $order;

        } catch (\Exception $e) {
            $this->debugService->log('OrderConsolidationService', '取得合併訂單詳情失敗', [
                'error' => $e->getMessage(),
                'consolidated_order_id' => $consolidatedOrderId
            ], 'error');

            return null;
        }
    }
}