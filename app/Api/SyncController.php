<?php

namespace BuyGo\Core\Api;

use BuyGo\Core\Services\DebugService;
use BuyGo\Core\Services\ContactDataService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * 同步記錄 API 控制器
 */
class SyncController
{
    private $debugService;
    private $contactDataService;

    public function __construct()
    {
        $this->debugService = new DebugService();
        $this->contactDataService = new ContactDataService();
    }

    /**
     * 註冊 REST API 路由
     */
    public function register_routes()
    {
        register_rest_route('buygo/v1', '/sync/recent', [
            'methods' => 'GET',
            'callback' => [$this, 'get_recent_syncs'],
            'permission_callback' => [$this, 'check_permissions']
        ]);

        register_rest_route('buygo/v1', '/sync/reset', [
            'methods' => 'POST',
            'callback' => [$this, 'reset_sync_data'],
            'permission_callback' => [$this, 'check_permissions']
        ]);

        register_rest_route('buygo/v1', '/debug/clear', [
            'methods' => 'POST',
            'callback' => [$this, 'clear_debug_logs'],
            'permission_callback' => [$this, 'check_permissions']
        ]);

        register_rest_route('buygo/v1', '/sync/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_sync_stats'],
            'permission_callback' => [$this, 'check_permissions']
        ]);
    }

    /**
     * 檢查權限
     */
    public function check_permissions()
    {
        return current_user_can('manage_options');
    }

    /**
     * 取得最近的同步記錄
     */
    public function get_recent_syncs(WP_REST_Request $request): WP_REST_Response
    {
        try {
            global $wpdb;
            
            $limit = $request->get_param('limit') ?: 10;
            
            // 從 debug 日誌中取得同步記錄
            $records = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}buygo_debug_logs 
                WHERE context LIKE '%Sync%' OR context LIKE '%Webhook%'
                ORDER BY created_at DESC 
                LIMIT %d
            ", $limit), ARRAY_A);

            $formattedRecords = [];
            foreach ($records as $record) {
                $data = json_decode($record['data'], true);
                $formattedRecords[] = [
                    'timestamp' => $record['created_at'],
                    'source' => $record['context'],
                    'message' => $record['message'],
                    'email' => $data['email'] ?? $data['customer_email'] ?? 'N/A',
                    'level' => $record['level']
                ];
            }

            return new WP_REST_Response([
                'success' => true,
                'records' => $formattedRecords,
                'count' => count($formattedRecords)
            ]);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 重置同步資料
     */
    public function reset_sync_data(WP_REST_Request $request): WP_REST_Response
    {
        try {
            global $wpdb;
            
            // 清空 BuyGo 聯絡資料表
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}buygo_phone");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}buygo_address");
            
            // 清空除錯日誌
            $wpdb->query("DELETE FROM {$wpdb->prefix}buygo_debug_logs WHERE context LIKE '%Sync%' OR context LIKE '%Webhook%'");

            $this->debugService->log('SyncController', '同步資料已重置', [
                'user' => wp_get_current_user()->user_login,
                'timestamp' => current_time('mysql')
            ]);

            return new WP_REST_Response([
                'success' => true,
                'message' => '同步資料已重置'
            ]);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 清除除錯日誌
     */
    public function clear_debug_logs(WP_REST_Request $request): WP_REST_Response
    {
        try {
            global $wpdb;
            
            $days = $request->get_param('days') ?: 0; // 0 = 全部清除
            
            if ($days > 0) {
                $wpdb->query($wpdb->prepare("
                    DELETE FROM {$wpdb->prefix}buygo_debug_logs 
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                ", $days));
                $message = "已清除 {$days} 天前的除錯日誌";
            } else {
                $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}buygo_debug_logs");
                $message = "已清除所有除錯日誌";
            }

            return new WP_REST_Response([
                'success' => true,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得同步統計資料
     */
    public function get_sync_stats(WP_REST_Request $request): WP_REST_Response
    {
        try {
            global $wpdb;
            
            // 統計各種資料
            $stats = [
                'phone_records' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}buygo_phone"),
                'address_records' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}buygo_address"),
                'debug_logs' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}buygo_debug_logs"),
                'sync_logs' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}buygo_debug_logs WHERE context LIKE '%Sync%' OR context LIKE '%Webhook%'"),
                'fluentcart_orders' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders"),
                'fluentcart_customers' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers")
            ];

            // 最近 24 小時的同步活動
            $recent_activity = $wpdb->get_var("
                SELECT COUNT(*) FROM {$wpdb->prefix}buygo_debug_logs 
                WHERE (context LIKE '%Sync%' OR context LIKE '%Webhook%')
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");

            $stats['recent_activity_24h'] = $recent_activity;

            // 資料來源統計
            $sources = $wpdb->get_results("
                SELECT source, COUNT(*) as count 
                FROM {$wpdb->prefix}buygo_phone 
                GROUP BY source
            ", ARRAY_A);

            $stats['phone_sources'] = $sources;

            return new WP_REST_Response([
                'success' => true,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}