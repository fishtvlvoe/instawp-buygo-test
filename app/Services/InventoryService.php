<?php

namespace BuyGo\Core\Services;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\ProductVariation;
use BuyGo\Core\Services\DebugService;

/**
 * Inventory Service - 庫存管理服務
 * 
 * 整合 FluentCart 訂單系統，實作庫存自動扣減機制
 * 
 * @package BuyGo\Core\Services
 * @version 1.0.0
 */
class InventoryService
{
    private $debugService;
    private $productService;

    public function __construct()
    {
        $this->debugService = new DebugService();
        $this->productService = new ProductService();
        
        // 註冊 FluentCart 訂單 Hook
        $this->registerFluentCartHooks();
    }

    /**
     * 註冊 FluentCart 訂單相關 Hook
     */
    private function registerFluentCartHooks(): void
    {
        // 訂單建立時扣減庫存
        add_action('fluent_cart/order_created', [$this, 'handleOrderCreated'], 10, 1);
        
        // 訂單狀態變更時處理庫存
        add_action('fluent_cart/order_status_changed', [$this, 'handleOrderStatusChanged'], 10, 3);
        
        // 訂單項目新增時扣減庫存
        add_action('fluent_cart/order_item_added', [$this, 'handleOrderItemAdded'], 10, 2);
        
        // 訂單項目移除時回復庫存
        add_action('fluent_cart/order_item_removed', [$this, 'handleOrderItemRemoved'], 10, 2);
        
        // 訂單項目數量變更時調整庫存
        add_action('fluent_cart/order_item_quantity_changed', [$this, 'handleOrderItemQuantityChanged'], 10, 4);
    }

    /**
     * 處理訂單建立事件
     * 
     * @param Order $order FluentCart 訂單物件
     */
    public function handleOrderCreated($order): void
    {
        $this->debugService->log('InventoryService', '處理訂單建立事件', [
            'order_id' => $order->id,
            'status' => $order->status,
            'customer_id' => $order->customer_id
        ]);

        try {
            // 只有在訂單狀態為 pending 或 processing 時才扣減庫存
            if (in_array($order->status, ['pending', 'processing'])) {
                $this->processOrderInventoryDeduction($order);
            }

        } catch (\Exception $e) {
            $this->debugService->log('InventoryService', '處理訂單建立失敗', [
                'error' => $e->getMessage(),
                'order_id' => $order->id
            ], 'error');
        }
    }

    /**
     * 處理訂單狀態變更事件
     * 
     * @param string $orderId 訂單 ID
     * @param string $newStatus 新狀態
     * @param string $oldStatus 舊狀態
     */
    public function handleOrderStatusChanged($orderId, $newStatus, $oldStatus): void
    {
        $this->debugService->log('InventoryService', '處理訂單狀態變更', [
            'order_id' => $orderId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]);

        try {
            $order = Order::find($orderId);
            if (!$order) {
                throw new \Exception("訂單不存在：{$orderId}");
            }

            // 訂單取消或退款時回復庫存
            if (in_array($newStatus, ['cancelled', 'refunded']) && 
                in_array($oldStatus, ['pending', 'processing', 'completed'])) {
                
                $this->processOrderInventoryRestoration($order, $oldStatus);
            }
            
            // 訂單從取消狀態恢復時扣減庫存
            elseif (in_array($newStatus, ['pending', 'processing']) && 
                    in_array($oldStatus, ['cancelled', 'refunded'])) {
                
                $this->processOrderInventoryDeduction($order);
            }

        } catch (\Exception $e) {
            $this->debugService->log('InventoryService', '處理訂單狀態變更失敗', [
                'error' => $e->getMessage(),
                'order_id' => $orderId
            ], 'error');
        }
    }

    /**
     * 處理訂單項目新增事件
     * 
     * @param OrderItem $orderItem 訂單項目
     * @param Order $order 訂單
     */
    public function handleOrderItemAdded($orderItem, $order): void
    {
        $this->debugService->log('InventoryService', '處理訂單項目新增', [
            'order_id' => $order->id,
            'product_variation_id' => $orderItem->object_id,
            'quantity' => $orderItem->quantity
        ]);

        try {
            // 只有在訂單狀態為 pending 或 processing 時才扣減庫存
            if (in_array($order->status, ['pending', 'processing'])) {
                $this->deductInventoryForOrderItem($orderItem, $order->id);
            }

        } catch (\Exception $e) {
            $this->debugService->log('InventoryService', '處理訂單項目新增失敗', [
                'error' => $e->getMessage(),
                'order_item_id' => $orderItem->id
            ], 'error');
        }
    }

    /**
     * 處理訂單項目移除事件
     * 
     * @param OrderItem $orderItem 訂單項目
     * @param Order $order 訂單
     */
    public function handleOrderItemRemoved($orderItem, $order): void
    {
        $this->debugService->log('InventoryService', '處理訂單項目移除', [
            'order_id' => $order->id,
            'product_variation_id' => $orderItem->object_id,
            'quantity' => $orderItem->quantity
        ]);

        try {
            // 回復庫存
            $this->restoreInventoryForOrderItem($orderItem, $order->id);

        } catch (\Exception $e) {
            $this->debugService->log('InventoryService', '處理訂單項目移除失敗', [
                'error' => $e->getMessage(),
                'order_item_id' => $orderItem->id
            ], 'error');
        }
    }

    /**
     * 處理訂單項目數量變更事件
     * 
     * @param OrderItem $orderItem 訂單項目
     * @param int $oldQuantity 舊數量
     * @param int $newQuantity 新數量
     * @param Order $order 訂單
     */
    public function handleOrderItemQuantityChanged($orderItem, $oldQuantity, $newQuantity, $order): void
    {
        $this->debugService->log('InventoryService', '處理訂單項目數量變更', [
            'order_id' => $order->id,
            'product_variation_id' => $orderItem->object_id,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity
        ]);

        try {
            $quantityDiff = $newQuantity - $oldQuantity;
            
            if ($quantityDiff > 0) {
                // 數量增加，扣減庫存
                $this->productService->updateInventory(
                    $orderItem->object_id,
                    -$quantityDiff,
                    'order_quantity_increased',
                    $order->id
                );
            } elseif ($quantityDiff < 0) {
                // 數量減少，回復庫存
                $this->productService->updateInventory(
                    $orderItem->object_id,
                    abs($quantityDiff),
                    'order_quantity_decreased',
                    $order->id
                );
            }

        } catch (\Exception $e) {
            $this->debugService->log('InventoryService', '處理訂單項目數量變更失敗', [
                'error' => $e->getMessage(),
                'order_item_id' => $orderItem->id
            ], 'error');
        }
    }

    /**
     * 處理訂單庫存扣減
     * 
     * @param Order $order 訂單
     */
    private function processOrderInventoryDeduction($order): void
    {
        $orderItems = $order->order_items;
        
        foreach ($orderItems as $item) {
            $this->deductInventoryForOrderItem($item, $order->id);
        }
    }

    /**
     * 處理訂單庫存回復
     * 
     * @param Order $order 訂單
     * @param string $fromStatus 原狀態
     */
    private function processOrderInventoryRestoration($order, $fromStatus): void
    {
        $orderItems = $order->order_items;
        
        foreach ($orderItems as $item) {
            $this->restoreInventoryForOrderItem($item, $order->id, $fromStatus);
        }
    }

    /**
     * 扣減單一訂單項目的庫存
     * 
     * @param OrderItem $orderItem 訂單項目
     * @param string $orderId 訂單 ID
     */
    private function deductInventoryForOrderItem($orderItem, $orderId): void
    {
        $productVariationId = $orderItem->object_id;
        $quantity = $orderItem->quantity;

        // 檢查庫存充足性
        if (!$this->productService->checkInventoryAvailability($productVariationId, $quantity)) {
            throw new \Exception("商品庫存不足：商品 ID {$productVariationId}，需要 {$quantity}");
        }

        // 扣減庫存
        $this->productService->updateInventory(
            $productVariationId,
            -$quantity,
            'order_placed',
            $orderId
        );

        $this->debugService->log('InventoryService', '庫存扣減成功', [
            'product_variation_id' => $productVariationId,
            'quantity' => $quantity,
            'order_id' => $orderId
        ]);
    }

    /**
     * 回復單一訂單項目的庫存
     * 
     * @param OrderItem $orderItem 訂單項目
     * @param string $orderId 訂單 ID
     * @param string $reason 回復原因
     */
    private function restoreInventoryForOrderItem($orderItem, $orderId, $reason = 'order_cancelled'): void
    {
        $productVariationId = $orderItem->object_id;
        $quantity = $orderItem->quantity;

        // 回復庫存
        $this->productService->updateInventory(
            $productVariationId,
            $quantity,
            $reason,
            $orderId
        );

        $this->debugService->log('InventoryService', '庫存回復成功', [
            'product_variation_id' => $productVariationId,
            'quantity' => $quantity,
            'order_id' => $orderId,
            'reason' => $reason
        ]);
    }

    /**
     * 批量檢查庫存可用性
     * 
     * @param array $items 商品項目陣列 [['product_variation_id' => int, 'quantity' => int]]
     * @return array 檢查結果
     */
    public function batchCheckInventoryAvailability(array $items): array
    {
        $results = [];
        
        foreach ($items as $item) {
            $productVariationId = $item['product_variation_id'];
            $quantity = $item['quantity'];
            
            $available = $this->productService->checkInventoryAvailability($productVariationId, $quantity);
            
            $results[] = [
                'product_variation_id' => $productVariationId,
                'requested_quantity' => $quantity,
                'available' => $available,
                'current_inventory' => $this->getCurrentInventory($productVariationId)
            ];
        }
        
        return $results;
    }

    /**
     * 取得目前庫存數量
     * 
     * @param int $productVariationId 商品變化 ID
     * @return int
     */
    private function getCurrentInventory(int $productVariationId): int
    {
        try {
            $variation = ProductVariation::find($productVariationId);
            return $variation ? $variation->available : 0;
        } catch (\Exception $e) {
            $this->debugService->log('InventoryService', '取得庫存失敗', [
                'error' => $e->getMessage(),
                'product_variation_id' => $productVariationId
            ], 'error');
            return 0;
        }
    }

    /**
     * 手動調整庫存
     * 
     * @param int $productVariationId 商品變化 ID
     * @param int $quantity 調整數量（正數增加，負數減少）
     * @param string $reason 調整原因
     * @param string $referenceId 參考 ID
     * @return bool
     */
    public function adjustInventory(int $productVariationId, int $quantity, string $reason = 'manual_adjustment', string $referenceId = ''): bool
    {
        try {
            return $this->productService->updateInventory($productVariationId, $quantity, $reason, $referenceId);
        } catch (\Exception $e) {
            $this->debugService->log('InventoryService', '手動調整庫存失敗', [
                'error' => $e->getMessage(),
                'product_variation_id' => $productVariationId,
                'quantity' => $quantity
            ], 'error');
            return false;
        }
    }

    /**
     * 取得庫存警告清單
     * 
     * @param int $threshold 警告閾值
     * @return array
     */
    public function getLowStockProducts(int $threshold = 5): array
    {
        try {
            $lowStockProducts = ProductVariation::where('available', '<=', $threshold)
                ->where('available', '>', 0)
                ->where('item_status', 'active')
                ->with(['product'])
                ->get();

            $results = [];
            foreach ($lowStockProducts as $variation) {
                $results[] = [
                    'id' => $variation->id,
                    'name' => $variation->product->post_title ?? '',
                    'variation_title' => $variation->variation_title,
                    'current_inventory' => $variation->available,
                    'threshold' => $threshold,
                    'status' => 'low_stock'
                ];
            }

            return $results;

        } catch (\Exception $e) {
            $this->debugService->log('InventoryService', '取得低庫存商品失敗', [
                'error' => $e->getMessage(),
                'threshold' => $threshold
            ], 'error');
            return [];
        }
    }

    /**
     * 取得缺貨商品清單
     * 
     * @return array
     */
    public function getOutOfStockProducts(): array
    {
        try {
            $outOfStockProducts = ProductVariation::where('available', '<=', 0)
                ->where('item_status', 'active')
                ->with(['product'])
                ->get();

            $results = [];
            foreach ($outOfStockProducts as $variation) {
                $results[] = [
                    'id' => $variation->id,
                    'name' => $variation->product->post_title ?? '',
                    'variation_title' => $variation->variation_title,
                    'current_inventory' => $variation->available,
                    'status' => 'out_of_stock'
                ];
            }

            return $results;

        } catch (\Exception $e) {
            $this->debugService->log('InventoryService', '取得缺貨商品失敗', [
                'error' => $e->getMessage()
            ], 'error');
            return [];
        }
    }
}