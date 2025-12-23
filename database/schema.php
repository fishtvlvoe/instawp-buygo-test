<?php
/**
 * 資料庫結構定義
 */

// 防止直接存取
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 建立資料表
 */
function buygo_rp_create_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    
    // 賣家申請資料表
    $table_applications = $wpdb->prefix . 'buygo_seller_applications';
    $sql_applications = "CREATE TABLE $table_applications (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        real_name VARCHAR(100) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        line_id VARCHAR(100) NOT NULL,
        reason TEXT,
        product_types TEXT,
        submitted_at DATETIME NOT NULL,
        reviewed_at DATETIME,
        reviewed_by BIGINT(20) UNSIGNED,
        review_note TEXT,
        PRIMARY KEY (id),
        KEY idx_user_id (user_id),
        KEY idx_status (status),
        KEY idx_submitted_at (submitted_at)
    ) $charset_collate;";
    
    dbDelta( $sql_applications );
    
    // 小幫手關係資料表
    $table_helpers = $wpdb->prefix . 'buygo_helpers';
    $sql_helpers = "CREATE TABLE $table_helpers (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        seller_id BIGINT(20) UNSIGNED NOT NULL,
        helper_id BIGINT(20) UNSIGNED NOT NULL,
        can_view_orders TINYINT(1) NOT NULL DEFAULT 0,
        can_update_orders TINYINT(1) NOT NULL DEFAULT 0,
        can_manage_products TINYINT(1) NOT NULL DEFAULT 0,
        can_reply_customers TINYINT(1) NOT NULL DEFAULT 0,
        assigned_at DATETIME NOT NULL,
        assigned_by BIGINT(20) UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_seller_helper (seller_id, helper_id),
        KEY idx_seller_id (seller_id),
        KEY idx_helper_id (helper_id)
    ) $charset_collate;";
    
    dbDelta( $sql_helpers );
    
    // LINE 綁定碼資料表
    $table_bindings = $wpdb->prefix . 'buygo_line_bindings';
    $sql_bindings = "CREATE TABLE $table_bindings (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        binding_code VARCHAR(6) NOT NULL,
        line_uid VARCHAR(100),
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        completed_at DATETIME,
        PRIMARY KEY (id),
        UNIQUE KEY unique_binding_code (binding_code),
        KEY idx_user_id (user_id),
        KEY idx_line_uid (line_uid),
        KEY idx_status (status)
    ) $charset_collate;";
    
    dbDelta( $sql_bindings );
    
    // 庫存變更記錄資料表
    $table_inventory_logs = $wpdb->prefix . 'buygo_inventory_logs';
    $sql_inventory_logs = "CREATE TABLE $table_inventory_logs (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        product_variation_id BIGINT(20) UNSIGNED NOT NULL,
        change_type ENUM('increase', 'decrease') NOT NULL,
        quantity INT NOT NULL,
        reason VARCHAR(50) NOT NULL,
        reference_id VARCHAR(50),
        old_inventory INT NOT NULL DEFAULT 0,
        new_inventory INT NOT NULL DEFAULT 0,
        operator_id BIGINT(20) UNSIGNED,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_product_variation (product_variation_id),
        KEY idx_created_at (created_at),
        KEY idx_reason (reason)
    ) $charset_collate;";
    
    dbDelta( $sql_inventory_logs );
    
    // 訂單狀態歷史資料表
    $table_order_status_history = $wpdb->prefix . 'buygo_order_status_history';
    $sql_order_status_history = "CREATE TABLE $table_order_status_history (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id VARCHAR(20) NOT NULL,
        old_status VARCHAR(20),
        new_status VARCHAR(20) NOT NULL,
        reason TEXT,
        operator_id BIGINT(20) UNSIGNED,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_order_id (order_id),
        KEY idx_created_at (created_at),
        KEY idx_new_status (new_status)
    ) $charset_collate;";
    
    dbDelta( $sql_order_status_history );
    
    // 合併訂單資料表
    $table_consolidated_orders = $wpdb->prefix . 'buygo_consolidated_orders';
    $sql_consolidated_orders = "CREATE TABLE $table_consolidated_orders (
        id VARCHAR(20) NOT NULL,
        customer_id BIGINT(20) UNSIGNED NOT NULL,
        original_order_ids JSON NOT NULL,
        consolidated_items JSON NOT NULL,
        total_amount BIGINT(20) NOT NULL DEFAULT 0,
        shipping_status VARCHAR(20) NOT NULL DEFAULT '未出貨',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_customer_id (customer_id),
        KEY idx_shipping_status (shipping_status),
        KEY idx_created_at (created_at)
    ) $charset_collate;";
    
    dbDelta( $sql_consolidated_orders );
    
    // Webhook 設定資料表
    $table_webhooks = $wpdb->prefix . 'buygo_webhooks';
    $sql_webhooks = "CREATE TABLE $table_webhooks (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        url VARCHAR(500) NOT NULL,
        events JSON NOT NULL,
        secret VARCHAR(100) NOT NULL,
        active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_active (active),
        KEY idx_created_at (created_at)
    ) $charset_collate;";
    
    dbDelta( $sql_webhooks );
    
    // Webhook 傳送記錄資料表
    $table_webhook_logs = $wpdb->prefix . 'buygo_webhook_logs';
    $sql_webhook_logs = "CREATE TABLE $table_webhook_logs (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        webhook_id BIGINT(20) UNSIGNED NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        payload JSON NOT NULL,
        response_status INT,
        response_body TEXT,
        success BOOLEAN NOT NULL DEFAULT FALSE,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_webhook_id (webhook_id),
        KEY idx_event_type (event_type),
        KEY idx_success (success),
        KEY idx_created_at (created_at),
        FOREIGN KEY (webhook_id) REFERENCES $table_webhooks(id) ON DELETE CASCADE
    ) $charset_collate;";
    
    dbDelta( $sql_webhook_logs );
    
    // 除錯日誌資料表
    $table_debug_logs = $wpdb->prefix . 'buygo_debug_logs';
    $sql_debug_logs = "CREATE TABLE $table_debug_logs (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        level ENUM('debug', 'info', 'warning', 'error') NOT NULL DEFAULT 'info',
        module VARCHAR(50) NOT NULL,
        message TEXT NOT NULL,
        data JSON,
        user_id BIGINT(20) UNSIGNED,
        ip_address VARCHAR(45),
        user_agent TEXT,
        request_uri TEXT,
        request_method VARCHAR(10),
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_level (level),
        KEY idx_module (module),
        KEY idx_user_id (user_id),
        KEY idx_created_at (created_at)
    ) $charset_collate;";
    
    dbDelta( $sql_debug_logs );
}
