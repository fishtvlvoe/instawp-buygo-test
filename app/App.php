<?php

namespace BuyGo\Core;

use Illuminate\Support\Arr;

class App {

    /**
     * @var App|null
     */
    private static $instance = null;

    /**
     * @var array
     */
    private $container = [];

    /**
     * Get the global instance.
     *
     * @return App
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Initialize services here
    }

    /**
     * Run the application.
     */
    public function run() {
        // Register hooks
        $this->register_hooks();
        
        // Initialize services
        $this->boot();
        
        // Initialize Services
        $this->container[Services\SettingsService::class]    = new Services\SettingsService();
        $this->container[Services\RoleManager::class]        = new Services\RoleManager();
        $this->container[Services\LineService::class]        = new Services\LineService();
        $this->container[Services\IntegrationService::class] = new Services\IntegrationService();

        $this->container[Services\NslIntegration::class]     = new Services\NslIntegration();
        $this->container[Services\SellerApplicationService::class] = new Services\SellerApplicationService();
        $this->container[Services\HelperManager::class]            = new Services\HelperManager();
        $this->container[Services\NotificationService::class]      = new Services\NotificationService();
        $this->container[Services\SyncManager::class]              = new Services\SyncManager();
        $this->container[Services\FluentCartIntegrationService::class] = new Services\FluentCartIntegrationService();
        $this->container[Services\RoleSyncService::class]         = new Services\RoleSyncService();

        $this->container['api_settings']     = new Api\SettingsController();
        $this->container['api_line']         = new Api\LineController();

        // Admin
        if (is_admin()) {
            new Admin\AdminMenu();

            // [FIX] 註解掉舊版選單以避免重複 (2025-12-23)
            // Load Legacy PHP Admin Pages (Seller Applications, Helpers)
            // Path: buygo-role-permission/admin/class-admin-menu.php
            // $legacy_menu_path = plugin_dir_path(dirname(__DIR__)) . 'admin/class-admin-menu.php';
            // if (file_exists($legacy_menu_path)) {
            //     require_once $legacy_menu_path;
            //     \BuyGo_RP_Admin_Menu_Final::get_instance();
            // }
        }

        // Frontend
        new Frontend\LineBindingShortcode();
        new Frontend\SellerApplicationShortcode();
        new Frontend\HelperManagementShortcode();
        new Frontend\FluentCartIntegration();
        new Frontend\FluentCartRealtimeSync(); // FluentCart 即時同步
        new Frontend\FluentCartReceiptSync(); // FluentCart 收據頁面同步
        // new Frontend\SellerOrderNotification(); // Disable simple injection as we use Shortcode now
        new Frontend\SellerOrdersShortcode();
    }

    private function register_hooks() {
        add_action('plugins_loaded', [$this, 'on_plugins_loaded'], 0);
        add_action('rest_api_init', [$this, 'register_api_routes']);
        
        // Add Settings Link to Plugin List
        add_filter('plugin_action_links_' . plugin_basename(dirname(__DIR__) . '/buygo-role-permission.php'), [$this, 'add_settings_link']);
        
        // Load WorkflowLogger hooks
        require_once dirname(__DIR__) . '/app/Services/WorkflowLogger.php';
        
        // Initialize CustomerDataSync hooks
        new Hooks\CustomerDataSync();
        
        // Initialize ContactDataMigration
        $this->initializeContactDataMigration();
        
        // AJAX 處理
        add_action('wp_ajax_buygo_sync_woo_user', [$this, 'ajax_sync_woo_user']);
        add_action('wp_ajax_buygo_batch_sync_woo', [$this, 'ajax_batch_sync_woo']);
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=buygo-core">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function register_api_routes() {
        (new Api\SettingsController())->register_routes();
        (new Api\LineController())->register_routes();
        (new Api\IntegrationController())->register_routes();

        (new Api\MemberController())->register_routes();

        (new Api\SellerApplicationController())->register_routes();
        (new Api\OrderNotificationController())->register_routes();
        
        // 確保 OrderController 正確註冊（包含 FluentCart webhook）
        $orderController = new \BuyGo\Core\Api\OrderController();
        $orderController->register_routes();
        
        (new \BuyGo\Core\Api\ProductController())->register_routes();
        (new \BuyGo\Core\Api\ReportController())->register_routes();
        (new \BuyGo\Core\Api\SearchController())->register_routes();
        (new \BuyGo\Core\Api\ExportController())->register_routes();
        (new \BuyGo\Core\Api\DashboardController())->register_routes();
        (new Api\UserSearchController())->register_routes();
        (new Api\HelperController())->register_routes();
        (new Api\NotificationController())->register_routes();
        (new Api\WorkflowController())->register_routes();
        
        // FluentCart 同步相關 API
        (new Api\WooMetaController())->register_routes();
        (new Api\SyncController())->register_routes();
        
        // Phone Test API (除錯用) - 暫時停用，改用獨立腳本 test-phone.php
        // if (defined('WP_DEBUG') && WP_DEBUG) {
        //     \BuyGo\App\Api\PhoneTestController::register_routes();
        // }
    }

    public function on_plugins_loaded() {
        // Fire action for other plugins to hook into
        do_action('buygo_core_loaded', $this);
    }

    private function boot() {
        // Run database migrations
        (new Utils\MigrationRunner())->run();
    }

    /**
     * Get a service from the container.
     * 
     * @param string $key
     * @return mixed
     */
    public function make($key) {
        if (!isset($this->container[$key])) {
            if (class_exists($key)) {
                $this->container[$key] = new $key();
            }
        }
        return $this->container[$key] ?? null;
    }
    
    /**
     * Register a service.
     * 
     * @param string $key
     * @param mixed $instance
     */
    public function bind($key, $instance) {
        $this->container[$key] = $instance;
    }

    /**
     * Initialize Contact Data Migration
     */
    private function initializeContactDataMigration() {
        try {
            $migration = new Utils\ContactDataMigration();
            $migration->run();
            
            // Log successful migration
            if (class_exists('BuyGo\Core\Services\DebugService')) {
                $debugService = new Services\DebugService();
                $debugService->log('App', '聯絡資料遷移完成');
            }
        } catch (\Exception $e) {
            // Log error but don't break the application
            if (class_exists('BuyGo\Core\Services\DebugService')) {
                $debugService = new Services\DebugService();
                $debugService->log('App', '聯絡資料遷移失敗', [
                    'error' => $e->getMessage()
                ], 'error');
            }
        }
    }

    /**
     * AJAX: 同步單一 WooCommerce 用戶
     */
    public function ajax_sync_woo_user() {
        // 檢查權限和 nonce
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'buygo_sync')) {
            wp_die('權限不足');
        }

        $email = sanitize_email($_POST['email']);
        
        try {
            $wooMetaService = new Services\WooCommerceMetaService();
            $result = $wooMetaService->syncToBuyGo($email);
            
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: 批量同步 WooCommerce 用戶
     */
    public function ajax_batch_sync_woo() {
        // 檢查權限和 nonce
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'buygo_sync')) {
            wp_die('權限不足');
        }

        try {
            $wooMetaService = new Services\WooCommerceMetaService();
            $result = $wooMetaService->batchSyncAll(50);
            
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

}
