<?php

namespace BuyGo\Core\Api;

use BuyGo\Core\Services\WooCommerceMetaService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * WooCommerce Meta API 控制器
 */
class WooMetaController
{
    private $wooMetaService;

    public function __construct()
    {
        $this->wooMetaService = new WooCommerceMetaService();
    }

    /**
     * 註冊 REST API 路由
     */
    public function register_routes()
    {
        register_rest_route('buygo/v1', '/woo-meta/users', [
            'methods' => 'GET',
            'callback' => [$this, 'get_users'],
            'permission_callback' => [$this, 'check_permissions']
        ]);

        register_rest_route('buygo/v1', '/woo-meta/sync', [
            'methods' => 'POST',
            'callback' => [$this, 'sync_user'],
            'permission_callback' => [$this, 'check_permissions']
        ]);

        register_rest_route('buygo/v1', '/woo-meta/batch-sync', [
            'methods' => 'POST',
            'callback' => [$this, 'batch_sync'],
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
     * 取得有 WooCommerce Meta 的用戶清單
     */
    public function get_users(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $limit = $request->get_param('limit') ?: 50;
            $users = $this->wooMetaService->findUsersWithWooCommerceMeta($limit);

            return new WP_REST_Response([
                'success' => true,
                'users' => $users,
                'count' => count($users)
            ]);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 同步單一用戶的 WooCommerce Meta
     */
    public function sync_user(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $email = $request->get_param('email');
            
            if (empty($email)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => '缺少 email 參數'
                ], 400);
            }

            $result = $this->wooMetaService->syncToBuyGo($email);

            return new WP_REST_Response([
                'success' => $result['success'],
                'result' => $result,
                'message' => $result['message'] ?? '同步完成'
            ]);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 批量同步 WooCommerce Meta
     */
    public function batch_sync(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $limit = $request->get_param('limit') ?: 50;
            $stats = $this->wooMetaService->batchSyncAll($limit);

            return new WP_REST_Response([
                'success' => true,
                'stats' => $stats,
                'message' => "批量同步完成，處理了 {$stats['processed']} 筆資料"
            ]);

        } catch (\Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}