<?php

namespace BuyGo\Core\Services;

use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\OrderItem;
use BuyGo\Core\Services\DebugService;

/**
 * Product Service - 商品管理服務
 * 
 * 整合 FluentCart 商品功能並添加 BuyGo 特有的業務邏輯
 * 
 * @package BuyGo\Core\Services
 * @version 1.0.0
 */
class ProductService
{
    private $debugService;

    public function __construct()
    {
        $this->debugService = new DebugService();
    }

    /**
     * 取得商品列表（包含下單數量）
     * 
     * @param array $filters 篩選條件
     * @param string $viewMode 顯示模式 frontend|backend
     * @return array
     */
    public function getProductsWithOrderCount(array $filters = [], string $viewMode = 'frontend'): array
    {
        $this->debugService->log('ProductService', '開始取得商品列表', [
            'filters' => $filters,
            'viewMode' => $viewMode
        ]);

        try {
            global $wpdb;
            
            $user = wp_get_current_user();
            $isAdmin = in_array('administrator', (array)$user->roles, true) || 
                      in_array('buygo_admin', (array)$user->roles, true);

            // 建立查詢
            $query = ProductVariation::query()
                ->with(['product', 'product_detail'])
                ->where('item_status', 'active');

            // 權限篩選
            if (!$isAdmin && $viewMode === 'frontend') {
                $query->whereHas('product', function($q) use ($user) {
                    $q->where('post_author', $user->ID);
                });
            }

            // 狀態篩選
            if (isset($filters['status']) && $filters['status'] !== 'all') {
                $query->whereHas('product', function($q) use ($filters) {
                    $q->where('post_status', $filters['status']);
                });
            }

            // 搜尋篩選
            if (!empty($filters['search'])) {
                $search = $filters['search'];
                $query->where(function($q) use ($search) {
                    $q->where('variation_title', 'LIKE', "%{$search}%")
                      ->orWhereHas('product', function($productQuery) use ($search) {
                          $productQuery->where('post_title', 'LIKE', "%{$search}%");
                      });
                });
            }

            $products = $query->get();

            // 計算下單數量
            $productIds = $products->pluck('id')->toArray();
            $orderCounts = $this->calculateOrderCounts($productIds);

            // 格式化資料
            $formattedProducts = [];
            foreach ($products as $product) {
                $productData = [
                    'id' => $product->id,
                    'post_id' => $product->post_id,
                    'name' => $product->product->post_title ?? '',
                    'variation_title' => $product->variation_title,
                    'price' => $product->item_price,
                    'formatted_price' => $this->formatPrice($product->item_price),
                    'inventory' => $product->available ?? 0,
                    'total_stock' => $product->total_stock ?? 0,
                    'committed' => $product->committed ?? 0,
                    'on_hold' => $product->on_hold ?? 0,
                    'order_count' => $orderCounts[$product->id] ?? 0,
                    'stock_status' => $product->stock_status,
                    'status' => $product->product->post_status ?? 'draft',
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at
                ];

                // 後台額外欄位
                if ($viewMode === 'backend') {
                    $productData['seller_id'] = $product->product->post_author ?? 0;
                    $productData['seller_name'] = $this->getSellerName($product->product->post_author ?? 0);
                    $productData['reserved_count'] = ($product->committed ?? 0) + ($product->on_hold ?? 0);
                }

                $formattedProducts[] = $productData;
            }

            $this->debugService->log('ProductService', '成功取得商品列表', [
                'count' => count($formattedProducts),
                'viewMode' => $viewMode
            ]);

            return $formattedProducts;

        } catch (\Exception $e) {
            $this->debugService->log('ProductService', '取得商品列表失敗', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'filters' => $filters
            ], 'error');

            throw new \Exception('無法取得商品列表：' . $e->getMessage());
        }
    }

    /**
     * 更新商品庫存
     * 
     * @param int $productVariationId 商品變化 ID
     * @param int $quantity 數量變更
     * @param string $reason 變更原因
     * @param string $referenceId 參考 ID（如訂單 ID）
     * @return bool
     */
    public function updateInventory(int $productVariationId, int $quantity, string $reason, string $referenceId = ''): bool
    {
        $this->debugService->log('ProductService', '開始更新庫存', [
            'product_variation_id' => $productVariationId,
            'quantity' => $quantity,
            'reason' => $reason,
            'reference_id' => $referenceId
        ]);

        try {
            global $wpdb;

            $variation = ProductVariation::find($productVariationId);
            if (!$variation) {
                throw new \Exception("商品變化不存在：ID {$productVariationId}");
            }

            $oldInventory = $variation->available;
            $changeType = $quantity > 0 ? 'increase' : 'decrease';
            $absQuantity = abs($quantity);

            // 檢查庫存充足性（減少庫存時）
            if ($quantity < 0 && $variation->available < $absQuantity) {
                throw new \Exception("庫存不足，目前庫存：{$variation->available}，需要：{$absQuantity}");
            }

            // 更新 FluentCart 庫存
            if ($reason === 'order_placed') {
                $variation->available -= $absQuantity;
                $variation->committed += $absQuantity;
            } elseif ($reason === 'order_cancelled') {
                $variation->available += $absQuantity;
                $variation->committed = max(0, $variation->committed - $absQuantity);
            } else {
                $variation->available += $quantity;
            }

            $variation->save();

            // 記錄庫存變更
            $this->logInventoryChange($productVariationId, $changeType, $absQuantity, $reason, $referenceId, $oldInventory, $variation->available);

            // 觸發 Webhook（如果有設定）
            $this->triggerInventoryWebhook($productVariationId, [
                'old_inventory' => $oldInventory,
                'new_inventory' => $variation->available,
                'change_quantity' => $quantity,
                'reason' => $reason,
                'reference_id' => $referenceId
            ]);

            $this->debugService->log('ProductService', '庫存更新成功', [
                'product_variation_id' => $productVariationId,
                'old_inventory' => $oldInventory,
                'new_inventory' => $variation->available,
                'change_quantity' => $quantity
            ]);

            return true;

        } catch (\Exception $e) {
            $this->debugService->log('ProductService', '庫存更新失敗', [
                'error' => $e->getMessage(),
                'product_variation_id' => $productVariationId,
                'quantity' => $quantity,
                'reason' => $reason
            ], 'error');

            throw new \Exception('庫存更新失敗：' . $e->getMessage());
        }
    }

    /**
     * 取得庫存變更歷史
     * 
     * @param int $productVariationId 商品變化 ID
     * @param int $limit 限制筆數
     * @return array
     */
    public function getInventoryHistory(int $productVariationId, int $limit = 50): array
    {
        try {
            global $wpdb;
            
            $table = $wpdb->prefix . 'buygo_inventory_logs';
            
            $sql = $wpdb->prepare("
                SELECT * FROM {$table} 
                WHERE product_variation_id = %d 
                ORDER BY created_at DESC 
                LIMIT %d
            ", $productVariationId, $limit);

            $results = $wpdb->get_results($sql, ARRAY_A);

            $this->debugService->log('ProductService', '取得庫存歷史', [
                'product_variation_id' => $productVariationId,
                'count' => count($results)
            ]);

            return $results;

        } catch (\Exception $e) {
            $this->debugService->log('ProductService', '取得庫存歷史失敗', [
                'error' => $e->getMessage(),
                'product_variation_id' => $productVariationId
            ], 'error');

            return [];
        }
    }

    /**
     * 檢查庫存可用性
     * 
     * @param int $productVariationId 商品變化 ID
     * @param int $requestedQuantity 請求數量
     * @return bool
     */
    public function checkInventoryAvailability(int $productVariationId, int $requestedQuantity): bool
    {
        try {
            $variation = ProductVariation::find($productVariationId);
            if (!$variation) {
                return false;
            }

            return $variation->available >= $requestedQuantity;

        } catch (\Exception $e) {
            $this->debugService->log('ProductService', '檢查庫存可用性失敗', [
                'error' => $e->getMessage(),
                'product_variation_id' => $productVariationId,
                'requested_quantity' => $requestedQuantity
            ], 'error');

            return false;
        }
    }

    /**
     * 計算商品的下單數量
     * 
     * @param array $productVariationIds 商品變化 ID 陣列
     * @return array
     */
    private function calculateOrderCounts(array $productVariationIds): array
    {
        if (empty($productVariationIds)) {
            return [];
        }

        try {
            global $wpdb;
            
            $table_items = $wpdb->prefix . 'fct_order_items';
            $table_orders = $wpdb->prefix . 'fct_orders';
            
            $placeholders = implode(',', array_fill(0, count($productVariationIds), '%d'));
            
            $sql = $wpdb->prepare("
                SELECT oi.object_id as product_variation_id, SUM(oi.quantity) as order_count
                FROM {$table_items} oi
                INNER JOIN {$table_orders} o ON oi.order_id = o.id
                WHERE oi.object_id IN ({$placeholders})
                AND o.status NOT IN ('cancelled', 'refunded', 'completed')
                GROUP BY oi.object_id
            ", ...$productVariationIds);

            $results = $wpdb->get_results($sql, ARRAY_A);
            
            $orderCounts = [];
            foreach ($results as $result) {
                $orderCounts[$result['product_variation_id']] = (int)$result['order_count'];
            }

            return $orderCounts;

        } catch (\Exception $e) {
            $this->debugService->log('ProductService', '計算下單數量失敗', [
                'error' => $e->getMessage(),
                'product_variation_ids' => $productVariationIds
            ], 'error');

            return [];
        }
    }

    /**
     * 記錄庫存變更
     */
    private function logInventoryChange(int $productVariationId, string $changeType, int $quantity, string $reason, string $referenceId, int $oldInventory, int $newInventory): void
    {
        try {
            global $wpdb;
            
            $table = $wpdb->prefix . 'buygo_inventory_logs';
            $user = wp_get_current_user();
            
            $wpdb->insert($table, [
                'product_variation_id' => $productVariationId,
                'change_type' => $changeType,
                'quantity' => $quantity,
                'reason' => $reason,
                'reference_id' => $referenceId,
                'old_inventory' => $oldInventory,
                'new_inventory' => $newInventory,
                'operator_id' => $user->ID,
                'created_at' => current_time('mysql')
            ]);

        } catch (\Exception $e) {
            $this->debugService->log('ProductService', '記錄庫存變更失敗', [
                'error' => $e->getMessage()
            ], 'error');
        }
    }

    /**
     * 觸發庫存變更 Webhook
     */
    private function triggerInventoryWebhook(int $productVariationId, array $changeData): void
    {
        // TODO: 實作 Webhook 觸發邏輯
        do_action('buygo_inventory_changed', $productVariationId, $changeData);
    }

    /**
     * 搜尋商品
     * 
     * @param array $searchParams 搜尋參數
     * @return array
     */
    public function searchProducts(array $searchParams): array
    {
        $this->debugService->log('ProductService', '開始搜尋商品', [
            'searchParams' => $searchParams
        ]);

        try {
            global $wpdb;
            
            $user = wp_get_current_user();
            $isAdmin = in_array('administrator', (array)$user->roles, true) || 
                      in_array('buygo_admin', (array)$user->roles, true);

            // 建立基礎查詢
            $query = ProductVariation::query()
                ->with(['product', 'product_detail'])
                ->where('item_status', 'active');

            // 權限篩選
            if (!$isAdmin) {
                $query->whereHas('product', function($q) use ($user) {
                    $q->where('post_author', $user->ID);
                });
            }

            // 關鍵字搜尋
            if (!empty($searchParams['q'])) {
                $searchTerm = $searchParams['q'];
                $query->where(function($q) use ($searchTerm) {
                    $q->where('variation_title', 'LIKE', "%{$searchTerm}%")
                      ->orWhereHas('product', function($productQuery) use ($searchTerm) {
                          $productQuery->where('post_title', 'LIKE', "%{$searchTerm}%")
                                      ->orWhere('post_content', 'LIKE', "%{$searchTerm}%");
                      });
                });
            }

            // 價格範圍篩選
            if (isset($searchParams['price_min'])) {
                $query->where('item_price', '>=', $searchParams['price_min'] * 100); // 轉換為分
            }
            if (isset($searchParams['price_max'])) {
                $query->where('item_price', '<=', $searchParams['price_max'] * 100);
            }

            // 庫存狀態篩選
            if (!empty($searchParams['stock_status'])) {
                switch ($searchParams['stock_status']) {
                    case 'out_of_stock':
                        $query->where('available', '<=', 0);
                        break;
                    case 'low_stock':
                        $query->where('available', '>', 0)->where('available', '<=', 5);
                        break;
                    case 'in_stock':
                        $query->where('available', '>', 5);
                        break;
                }
            }

            // 排序
            $sortBy = $searchParams['sort_by'] ?? 'created_at';
            $sortOrder = $searchParams['sort_order'] ?? 'desc';
            
            switch ($sortBy) {
                case 'name':
                    $query->join('wp_posts', 'wp_posts.ID', '=', 'wp_fct_product_variations.post_id')
                          ->orderBy('wp_posts.post_title', $sortOrder);
                    break;
                case 'price':
                    $query->orderBy('item_price', $sortOrder);
                    break;
                case 'inventory':
                    $query->orderBy('available', $sortOrder);
                    break;
                default:
                    $query->orderBy('created_at', $sortOrder);
            }

            // 分頁
            $page = max(1, $searchParams['page'] ?? 1);
            $perPage = min(100, max(1, $searchParams['per_page'] ?? 20));
            $offset = ($page - 1) * $perPage;

            // 取得總數
            $total = $query->count();

            // 取得分頁資料
            $products = $query->offset($offset)->limit($perPage)->get();

            // 計算下單數量
            $productIds = $products->pluck('id')->toArray();
            $orderCounts = $this->calculateOrderCounts($productIds);

            // 格式化資料
            $formattedProducts = [];
            foreach ($products as $product) {
                $formattedProducts[] = [
                    'id' => $product->id,
                    'post_id' => $product->post_id,
                    'name' => $product->product->post_title ?? '',
                    'variation_title' => $product->variation_title,
                    'price' => $product->item_price,
                    'formatted_price' => $this->formatPrice($product->item_price),
                    'inventory' => $product->available ?? 0,
                    'total_stock' => $product->total_stock ?? 0,
                    'order_count' => $orderCounts[$product->id] ?? 0,
                    'stock_status' => $product->stock_status,
                    'status' => $product->product->post_status ?? 'draft',
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at
                ];
            }

            $result = [
                'products' => $formattedProducts,
                'total' => $total,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => ceil($total / $perPage),
                    'has_next' => $page < ceil($total / $perPage),
                    'has_prev' => $page > 1
                ]
            ];

            $this->debugService->log('ProductService', '商品搜尋完成', [
                'total_found' => $total,
                'page' => $page,
                'per_page' => $perPage
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->debugService->log('ProductService', '商品搜尋失敗', [
                'error' => $e->getMessage(),
                'searchParams' => $searchParams
            ], 'error');

            throw new \Exception('商品搜尋失敗：' . $e->getMessage());
        }
    }

    /**
     * 批量操作
     * 
     * @param string $action 操作類型
     * @param array $productIds 商品 ID 陣列
     * @param array $data 操作資料
     * @return array
     */
    public function batchOperations(string $action, array $productIds, array $data = []): array
    {
        $this->debugService->log('ProductService', '開始批量操作', [
            'action' => $action,
            'product_count' => count($productIds),
            'data' => $data
        ]);

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($productIds as $productId) {
            try {
                $result = $this->performSingleOperation($action, $productId, $data);
                
                if ($result['success']) {
                    $results[] = [
                        'product_id' => $productId,
                        'success' => true,
                        'message' => $result['message'],
                        'data' => $result['data'] ?? null
                    ];
                    $successCount++;
                } else {
                    $results[] = [
                        'product_id' => $productId,
                        'success' => false,
                        'message' => $result['message']
                    ];
                    $errorCount++;
                }

            } catch (\Exception $e) {
                $results[] = [
                    'product_id' => $productId,
                    'success' => false,
                    'message' => $e->getMessage()
                ];
                $errorCount++;
            }
        }

        $this->debugService->log('ProductService', '批量操作完成', [
            'action' => $action,
            'success_count' => $successCount,
            'error_count' => $errorCount
        ]);

        return [
            'results' => $results,
            'success_count' => $successCount,
            'error_count' => $errorCount
        ];
    }

    /**
     * 執行單一操作
     * 
     * @param string $action 操作類型
     * @param int $productId 商品 ID
     * @param array $data 操作資料
     * @return array
     */
    private function performSingleOperation(string $action, int $productId, array $data): array
    {
        switch ($action) {
            case 'update_status':
                return $this->updateProductStatus($productId, $data['status'] ?? 'draft');
                
            case 'update_price':
                return $this->updateProductPrice($productId, $data['price'] ?? 0);
                
            case 'delete':
                return $this->deleteProduct($productId);
                
            case 'duplicate':
                $newProductId = $this->duplicateProduct($productId, $data['name_suffix'] ?? ' (複製)', $data['copy_inventory'] ?? false);
                return [
                    'success' => true,
                    'message' => '商品複製成功',
                    'data' => ['new_product_id' => $newProductId]
                ];
                
            default:
                throw new \Exception("不支援的操作類型：{$action}");
        }
    }

    /**
     * 更新商品狀態
     */
    private function updateProductStatus(int $productId, string $status): array
    {
        $variation = ProductVariation::find($productId);
        if (!$variation || !$variation->product) {
            throw new \Exception("商品不存在：ID {$productId}");
        }

        $variation->product->post_status = $status;
        $variation->product->save();

        return [
            'success' => true,
            'message' => '狀態更新成功'
        ];
    }

    /**
     * 更新商品價格
     */
    private function updateProductPrice(int $productId, float $price): array
    {
        $variation = ProductVariation::find($productId);
        if (!$variation) {
            throw new \Exception("商品不存在：ID {$productId}");
        }

        $variation->item_price = $price * 100; // 轉換為分
        $variation->save();

        return [
            'success' => true,
            'message' => '價格更新成功'
        ];
    }

    /**
     * 刪除商品
     */
    private function deleteProduct(int $productId): array
    {
        $variation = ProductVariation::find($productId);
        if (!$variation) {
            throw new \Exception("商品不存在：ID {$productId}");
        }

        $variation->item_status = 'inactive';
        $variation->save();

        return [
            'success' => true,
            'message' => '商品刪除成功'
        ];
    }

    /**
     * 複製商品
     * 
     * @param int $productId 原商品 ID
     * @param string $nameSuffix 名稱後綴
     * @param bool $copyInventory 是否複製庫存
     * @return int 新商品 ID
     */
    public function duplicateProduct(int $productId, string $nameSuffix = ' (複製)', bool $copyInventory = false): int
    {
        $originalVariation = ProductVariation::find($productId);
        if (!$originalVariation || !$originalVariation->product) {
            throw new \Exception("原商品不存在：ID {$productId}");
        }

        try {
            // 複製商品主體
            $newPost = wp_insert_post([
                'post_title' => $originalVariation->product->post_title . $nameSuffix,
                'post_content' => $originalVariation->product->post_content,
                'post_status' => 'draft',
                'post_type' => 'fluent-products',
                'post_author' => $originalVariation->product->post_author
            ]);

            if (is_wp_error($newPost)) {
                throw new \Exception('建立新商品失敗：' . $newPost->get_error_message());
            }

            // 複製商品變化
            $newVariation = new ProductVariation();
            $newVariation->post_id = $newPost;
            $newVariation->variation_title = $originalVariation->variation_title . $nameSuffix;
            $newVariation->item_price = $originalVariation->item_price;
            $newVariation->item_cost = $originalVariation->item_cost;
            $newVariation->compare_price = $originalVariation->compare_price;
            $newVariation->available = $copyInventory ? $originalVariation->available : 0;
            $newVariation->total_stock = $copyInventory ? $originalVariation->total_stock : 0;
            $newVariation->committed = 0;
            $newVariation->on_hold = 0;
            $newVariation->stock_status = $copyInventory ? $originalVariation->stock_status : 'out_of_stock';
            $newVariation->item_status = 'active';
            $newVariation->save();

            $this->debugService->log('ProductService', '商品複製成功', [
                'original_id' => $productId,
                'new_id' => $newVariation->id,
                'copy_inventory' => $copyInventory
            ]);

            return $newVariation->id;

        } catch (\Exception $e) {
            $this->debugService->log('ProductService', '商品複製失敗', [
                'error' => $e->getMessage(),
                'product_id' => $productId
            ], 'error');

            throw new \Exception('商品複製失敗：' . $e->getMessage());
        }
    }

    /**
     * 取得商品統計
     * 
     * @param string $period 統計期間
     * @return array
     */
    public function getProductStats(string $period = 'month'): array
    {
        try {
            global $wpdb;
            
            $dateRange = $this->calculateDateRange($period);
            
            // 基本統計
            $totalProducts = ProductVariation::where('item_status', 'active')->count();
            $lowStockProducts = ProductVariation::where('item_status', 'active')
                                              ->where('available', '>', 0)
                                              ->where('available', '<=', 5)
                                              ->count();
            $outOfStockProducts = ProductVariation::where('item_status', 'active')
                                                ->where('available', '<=', 0)
                                                ->count();

            // 期間內新增商品
            $newProducts = ProductVariation::where('item_status', 'active')
                                         ->whereBetween('created_at', [$dateRange['start_date'], $dateRange['end_date']])
                                         ->count();

            // 最熱銷商品（基於下單數量）
            $table_items = $wpdb->prefix . 'fct_order_items';
            $table_orders = $wpdb->prefix . 'fct_orders';
            
            $sql = $wpdb->prepare("
                SELECT oi.object_id as product_variation_id, SUM(oi.quantity) as total_ordered
                FROM {$table_items} oi
                INNER JOIN {$table_orders} o ON oi.order_id = o.id
                WHERE o.created_at BETWEEN %s AND %s
                AND o.status NOT IN ('cancelled', 'refunded')
                GROUP BY oi.object_id
                ORDER BY total_ordered DESC
                LIMIT 10
            ", $dateRange['start_date'], $dateRange['end_date']);

            $topProducts = $wpdb->get_results($sql, ARRAY_A);

            // 價格分布
            $priceRanges = [
                '0-100' => ProductVariation::where('item_status', 'active')->where('item_price', '<=', 10000)->count(),
                '101-500' => ProductVariation::where('item_status', 'active')->whereBetween('item_price', [10001, 50000])->count(),
                '501-1000' => ProductVariation::where('item_status', 'active')->whereBetween('item_price', [50001, 100000])->count(),
                '1000+' => ProductVariation::where('item_status', 'active')->where('item_price', '>', 100000)->count(),
            ];

            return [
                'summary' => [
                    'total_products' => $totalProducts,
                    'low_stock_products' => $lowStockProducts,
                    'out_of_stock_products' => $outOfStockProducts,
                    'new_products_this_period' => $newProducts,
                    'in_stock_products' => $totalProducts - $outOfStockProducts
                ],
                'top_products' => $topProducts,
                'price_distribution' => $priceRanges,
                'period' => $period,
                'date_range' => $dateRange
            ];

        } catch (\Exception $e) {
            $this->debugService->log('ProductService', '取得商品統計失敗', [
                'error' => $e->getMessage(),
                'period' => $period
            ], 'error');

            throw new \Exception('取得商品統計失敗：' . $e->getMessage());
        }
    }

    /**
     * 匯出商品
     * 
     * @param string $format 匯出格式
     * @param array $filters 篩選條件
     * @return string
     */
    public function exportProducts(string $format = 'csv', array $filters = []): string
    {
        try {
            // 取得商品資料
            $products = $this->getProductsWithOrderCount($filters, 'backend');
            
            switch ($format) {
                case 'csv':
                    return $this->exportToCsv($products);
                case 'json':
                    return $this->exportToJson($products);
                case 'xlsx':
                    return $this->exportToXlsx($products);
                default:
                    throw new \Exception("不支援的匯出格式：{$format}");
            }

        } catch (\Exception $e) {
            $this->debugService->log('ProductService', '商品匯出失敗', [
                'error' => $e->getMessage(),
                'format' => $format
            ], 'error');

            throw new \Exception('商品匯出失敗：' . $e->getMessage());
        }
    }

    /**
     * 匯出為 CSV
     */
    private function exportToCsv(array $products): string
    {
        $output = fopen('php://temp', 'r+');
        
        // CSV 標題
        $headers = [
            'ID', '商品名稱', '變化名稱', '價格', '庫存', '下單數量', 
            '總庫存', '狀態', '賣家', '建立時間', '更新時間'
        ];
        fputcsv($output, $headers);
        
        // 資料行
        foreach ($products as $product) {
            $row = [
                $product['id'],
                $product['name'],
                $product['variation_title'] ?? '',
                $product['formatted_price'],
                $product['inventory'],
                $product['order_count'],
                $product['total_stock'] ?? 0,
                $product['status'],
                $product['seller_name'] ?? '',
                $product['created_at'],
                $product['updated_at']
            ];
            fputcsv($output, $row);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }

    /**
     * 匯出為 JSON
     */
    private function exportToJson(array $products): string
    {
        return json_encode([
            'export_date' => current_time('mysql'),
            'total_products' => count($products),
            'products' => $products
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 匯出為 XLSX（簡化版本，實際應使用 PhpSpreadsheet）
     */
    private function exportToXlsx(array $products): string
    {
        // 簡化實作，實際應使用 PhpSpreadsheet 庫
        // 這裡返回 CSV 格式作為替代
        return $this->exportToCsv($products);
    }

    /**
     * 計算日期範圍
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
     * 格式化價格顯示
     */
    private function formatPrice(int $priceInCents): string
    {
        return 'NT$ ' . number_format($priceInCents / 100, 2);
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
}