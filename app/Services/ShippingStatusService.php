<?php

namespace BuyGo\Core\Services;

use BuyGo\Core\Services\DebugService;

/**
 * Shipping Status Service - 運送狀態管理服務
 * 
 * 管理訂單運送狀態，包含狀態定義、驗證、變更記錄和通知
 * 
 * @package BuyGo\Core\Services
 * @version 1.0.0
 */
class ShippingStatusService
{
    private $debugService;

    /**
     * 運送狀態定義（6 個狀態）
     */
    const SHIPPING_STATUSES = [
        'pending'      => '未出貨',
        'preparing'    => '備貨中',
        'processing'   => '處理中',
        'shipped'      => '已出貨',
        'completed'    => '交易完成',
        'out_of_stock' => '斷貨'
    ];

    /**
     * 狀態流程順序（用於判斷異常變更）
     */
    const STATUS_ORDER = [
        'pending'      => 1,
        'preparing'    => 2,
        'processing'   => 3,
        'shipped'      => 4,
        'completed'    => 5,
        'out_of_stock' => 0  // 特殊狀態，可以從任何狀態變更
    ];

    /**
     * 狀態顏色（用於前端顯示）
     */
    const STATUS_COLORS = [
        'pending'      => 'gray',
        'preparing'    => 'blue',
        'processing'   => 'yellow',
        'shipped'      => 'green',
        'completed'    => 'purple',
        'out_of_stock' => 'red'
    ];

    /**
     * 狀態圖示（用於前端顯示）
     */
    const STATUS_ICONS = [
        'pending'      => 'clock',
        'preparing'    => 'box',
        'processing'   => 'truck-loading',
        'shipped'      => 'truck',
        'completed'    => 'check-circle',
        'out_of_stock' => 'exclamation-triangle'
    ];

    public function __construct()
    {
        $this->debugService = new DebugService();
    }

    /**
     * 取得所有運送狀態
     * 
     * @param bool $includeMetadata 是否包含元資料（顏色、圖示等）
     * @return array
     */
    public function getAllStatuses(bool $includeMetadata = false): array
    {
        if (!$includeMetadata) {
            return self::SHIPPING_STATUSES;
        }

        $statuses = [];
        foreach (self::SHIPPING_STATUSES as $key => $label) {
            $statuses[] = [
                'key' => $key,
                'label' => $label,
                'color' => self::STATUS_COLORS[$key],
                'icon' => self::STATUS_ICONS[$key],
                'order' => self::STATUS_ORDER[$key]
            ];
        }

        return $statuses;
    }

    /**
     * 驗證狀態是否有效
     * 
     * @param string $status 狀態 key
     * @return bool
     */
    public function isValidStatus(string $status): bool
    {
        return array_key_exists($status, self::SHIPPING_STATUSES);
    }

    /**
     * 取得狀態標籤
     * 
     * @param string $status 狀態 key
     * @return string
     */
    public function getStatusLabel(string $status): string
    {
        return self::SHIPPING_STATUSES[$status] ?? $status;
    }

    /**
     * 檢查狀態變更是否異常
     * 
     * 異常變更定義：
     * 1. 從已出貨變回未出貨、備貨中、處理中
     * 2. 從交易完成變回任何其他狀態
     * 3. 狀態順序倒退（除了斷貨）
     * 
     * @param string $oldStatus 舊狀態
     * @param string $newStatus 新狀態
     * @return bool
     */
    public function isAbnormalStatusChange(string $oldStatus, string $newStatus): bool
    {
        // 斷貨狀態可以從任何狀態變更
        if ($newStatus === 'out_of_stock') {
            return false;
        }

        // 從斷貨變更到其他狀態不算異常
        if ($oldStatus === 'out_of_stock') {
            return false;
        }

        // 從交易完成變回其他狀態是異常
        if ($oldStatus === 'completed' && $newStatus !== 'completed') {
            return true;
        }

        // 從已出貨變回未出貨、備貨中、處理中是異常
        if ($oldStatus === 'shipped' && in_array($newStatus, ['pending', 'preparing', 'processing'], true)) {
            return true;
        }

        // 檢查狀態順序是否倒退
        $oldOrder = self::STATUS_ORDER[$oldStatus] ?? 0;
        $newOrder = self::STATUS_ORDER[$newStatus] ?? 0;

        if ($oldOrder > 0 && $newOrder > 0 && $newOrder < $oldOrder) {
            return true;
        }

        return false;
    }

    /**
     * 取得可變更的狀態列表
     * 
     * @param string $currentStatus 當前狀態
     * @return array
     */
    public function getAvailableStatuses(string $currentStatus): array
    {
        $available = [];

        foreach (self::SHIPPING_STATUSES as $key => $label) {
            // 當前狀態不顯示
            if ($key === $currentStatus) {
                continue;
            }

            $isAbnormal = $this->isAbnormalStatusChange($currentStatus, $key);

            $available[] = [
                'key' => $key,
                'label' => $label,
                'is_abnormal' => $isAbnormal,
                'warning' => $isAbnormal ? '此變更可能不正常，請確認' : null
            ];
        }

        return $available;
    }

    /**
     * 記錄狀態變更
     * 
     * @param string $orderId 訂單 ID
     * @param string $oldStatus 舊狀態
     * @param string $newStatus 新狀態
     * @param string $reason 變更原因
     * @param int|null $operatorId 操作者 ID
     * @return bool
     */
    public function logStatusChange(
        string $orderId, 
        string $oldStatus, 
        string $newStatus, 
        string $reason = '', 
        ?int $operatorId = null
    ): bool {
        $this->debugService->log('ShippingStatusService', '記錄狀態變更', [
            'order_id' => $orderId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'reason' => $reason
        ]);

        try {
            global $wpdb;
            
            $table = $wpdb->prefix . 'buygo_order_status_history';
            
            // 如果沒有提供操作者，使用當前用戶
            if ($operatorId === null) {
                $user = wp_get_current_user();
                $operatorId = $user->ID;
            }

            $result = $wpdb->insert($table, [
                'order_id' => $orderId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'reason' => $reason,
                'operator_id' => $operatorId,
                'is_abnormal' => $this->isAbnormalStatusChange($oldStatus, $newStatus) ? 1 : 0,
                'created_at' => current_time('mysql')
            ]);

            if ($result === false) {
                throw new \Exception('資料庫寫入失敗：' . $wpdb->last_error);
            }

            $this->debugService->log('ShippingStatusService', '狀態變更記錄成功', [
                'order_id' => $orderId,
                'log_id' => $wpdb->insert_id
            ]);

            return true;

        } catch (\Exception $e) {
            $this->debugService->log('ShippingStatusService', '記錄狀態變更失敗', [
                'error' => $e->getMessage(),
                'order_id' => $orderId
            ], 'error');

            return false;
        }
    }

    /**
     * 取得狀態變更歷史
     * 
     * @param string $orderId 訂單 ID
     * @param int $limit 限制筆數
     * @return array
     */
    public function getStatusHistory(string $orderId, int $limit = 50): array
    {
        try {
            global $wpdb;
            
            $table = $wpdb->prefix . 'buygo_order_status_history';
            
            $sql = $wpdb->prepare("
                SELECT * FROM {$table} 
                WHERE order_id = %s 
                ORDER BY created_at DESC
                LIMIT %d
            ", $orderId, $limit);

            $results = $wpdb->get_results($sql, ARRAY_A);

            // 格式化結果
            foreach ($results as &$result) {
                // 添加狀態標籤
                $result['old_status_label'] = $this->getStatusLabel($result['old_status']);
                $result['new_status_label'] = $this->getStatusLabel($result['new_status']);

                // 添加操作者資訊
                if ($result['operator_id']) {
                    $user = get_userdata($result['operator_id']);
                    $result['operator_name'] = $user ? $user->display_name : '未知用戶';
                    $result['operator_email'] = $user ? $user->user_email : '';
                } else {
                    $result['operator_name'] = '系統';
                    $result['operator_email'] = '';
                }

                // 格式化時間
                $result['formatted_time'] = human_time_diff(strtotime($result['created_at'])) . ' ago';
            }

            return $results;

        } catch (\Exception $e) {
            $this->debugService->log('ShippingStatusService', '取得狀態歷史失敗', [
                'error' => $e->getMessage(),
                'order_id' => $orderId
            ], 'error');

            return [];
        }
    }

    /**
     * 批量更新運送狀態
     * 
     * @param array $orderIds 訂單 ID 陣列
     * @param string $status 新狀態
     * @param string $reason 變更原因
     * @return array 結果統計
     */
    public function batchUpdateStatus(array $orderIds, string $status, string $reason = ''): array
    {
        $this->debugService->log('ShippingStatusService', '開始批量更新狀態', [
            'order_count' => count($orderIds),
            'new_status' => $status
        ]);

        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        // 驗證狀態
        if (!$this->isValidStatus($status)) {
            $results['errors'][] = '無效的運送狀態：' . $status;
            return $results;
        }

        global $wpdb;
        $orderTable = $wpdb->prefix . 'fct_orders';

        foreach ($orderIds as $orderId) {
            try {
                // 取得當前狀態
                $currentStatus = $wpdb->get_var($wpdb->prepare(
                    "SELECT shipping_status FROM {$orderTable} WHERE id = %s",
                    $orderId
                ));

                if ($currentStatus === null) {
                    $results['failed']++;
                    $results['errors'][] = "訂單 {$orderId} 不存在";
                    continue;
                }

                // 更新狀態
                $updated = $wpdb->update(
                    $orderTable,
                    [
                        'shipping_status' => $status,
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $orderId],
                    ['%s', '%s'],
                    ['%s']
                );

                if ($updated === false) {
                    $results['failed']++;
                    $results['errors'][] = "訂單 {$orderId} 更新失敗";
                    continue;
                }

                // 記錄狀態變更
                $this->logStatusChange($orderId, $currentStatus, $status, $reason);

                // 檢查異常變更
                if ($this->isAbnormalStatusChange($currentStatus, $status)) {
                    $this->sendAbnormalStatusWarning($orderId, $currentStatus, $status);
                }

                // 觸發狀態變更通知
                $this->triggerStatusChangeNotification($orderId, $currentStatus, $status);

                $results['success']++;

            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "訂單 {$orderId}: " . $e->getMessage();
                
                $this->debugService->log('ShippingStatusService', '批量更新單筆失敗', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage()
                ], 'error');
            }
        }

        $this->debugService->log('ShippingStatusService', '批量更新完成', $results);

        return $results;
    }

    /**
     * 發送異常狀態變更警告
     * 
     * @param string $orderId 訂單 ID
     * @param string $oldStatus 舊狀態
     * @param string $newStatus 新狀態
     * @return void
     */
    private function sendAbnormalStatusWarning(string $orderId, string $oldStatus, string $newStatus): void
    {
        $this->debugService->log('ShippingStatusService', '異常狀態變更警告', [
            'order_id' => $orderId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ], 'warning');

        // 觸發 WordPress action，讓其他模組可以處理警告
        do_action('buygo_abnormal_status_change', $orderId, $oldStatus, $newStatus);

        // 發送通知給管理員
        $this->sendAdminNotification([
            'type' => 'abnormal_status_change',
            'order_id' => $orderId,
            'old_status' => $this->getStatusLabel($oldStatus),
            'new_status' => $this->getStatusLabel($newStatus),
            'message' => "訂單 #{$orderId} 發生異常狀態變更：從「{$this->getStatusLabel($oldStatus)}」變更為「{$this->getStatusLabel($newStatus)}」"
        ]);
    }

    /**
     * 觸發狀態變更通知
     * 
     * @param string $orderId 訂單 ID
     * @param string $oldStatus 舊狀態
     * @param string $newStatus 新狀態
     * @return void
     */
    private function triggerStatusChangeNotification(string $orderId, string $oldStatus, string $newStatus): void
    {
        // 觸發 WordPress action
        do_action('buygo_shipping_status_changed', $orderId, $oldStatus, $newStatus);

        // 特定狀態的通知
        switch ($newStatus) {
            case 'shipped':
                do_action('buygo_order_shipped', $orderId);
                break;
            case 'completed':
                do_action('buygo_order_completed', $orderId);
                break;
            case 'out_of_stock':
                do_action('buygo_order_out_of_stock', $orderId);
                break;
        }

        $this->debugService->log('ShippingStatusService', '狀態變更通知已觸發', [
            'order_id' => $orderId,
            'new_status' => $newStatus
        ]);
    }

    /**
     * 發送管理員通知
     * 
     * @param array $data 通知資料
     * @return void
     */
    private function sendAdminNotification(array $data): void
    {
        // 觸發通知 action，讓 NotificationService 處理
        do_action('buygo_send_admin_notification', $data);
    }

    /**
     * 取得狀態統計
     * 
     * @param array $filters 篩選條件
     * @return array
     */
    public function getStatusStatistics(array $filters = []): array
    {
        try {
            global $wpdb;
            $orderTable = $wpdb->prefix . 'fct_orders';

            $where = ['1=1'];
            
            // 日期篩選
            if (!empty($filters['date_from'])) {
                $where[] = $wpdb->prepare("created_at >= %s", $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $where[] = $wpdb->prepare("created_at <= %s", $filters['date_to']);
            }

            $whereClause = implode(' AND ', $where);

            $sql = "
                SELECT 
                    shipping_status,
                    COUNT(*) as count,
                    SUM(total_amount) as total_amount
                FROM {$orderTable}
                WHERE {$whereClause}
                GROUP BY shipping_status
            ";

            $results = $wpdb->get_results($sql, ARRAY_A);

            $statistics = [];
            $totalOrders = 0;
            $totalAmount = 0;

            foreach ($results as $row) {
                $status = $row['shipping_status'] ?: 'pending';
                $count = (int)$row['count'];
                $amount = (float)$row['total_amount'];

                $statistics[$status] = [
                    'key' => $status,
                    'label' => $this->getStatusLabel($status),
                    'count' => $count,
                    'total_amount' => $amount / 100, // 轉換為元
                    'formatted_amount' => 'NT$ ' . number_format($amount / 100, 2),
                    'color' => self::STATUS_COLORS[$status] ?? 'gray'
                ];

                $totalOrders += $count;
                $totalAmount += $amount;
            }

            // 計算百分比
            foreach ($statistics as &$stat) {
                $stat['percentage'] = $totalOrders > 0 ? round(($stat['count'] / $totalOrders) * 100, 2) : 0;
            }

            return [
                'statistics' => $statistics,
                'total_orders' => $totalOrders,
                'total_amount' => $totalAmount / 100,
                'formatted_total_amount' => 'NT$ ' . number_format($totalAmount / 100, 2)
            ];

        } catch (\Exception $e) {
            $this->debugService->log('ShippingStatusService', '取得狀態統計失敗', [
                'error' => $e->getMessage()
            ], 'error');

            return [
                'statistics' => [],
                'total_orders' => 0,
                'total_amount' => 0,
                'formatted_total_amount' => 'NT$ 0.00'
            ];
        }
    }
}
