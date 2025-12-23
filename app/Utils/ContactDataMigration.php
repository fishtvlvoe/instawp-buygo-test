<?php

namespace BuyGo\Core\Utils;

use BuyGo\Core\Services\DebugService;

/**
 * Contact Data Migration - 聯絡資料遷移工具
 * 
 * 建立並管理 BuyGo 自有的聯絡資料表
 * 從 FluentCart 和 FluentCRM 同步資料到我們的資料表
 * 
 * @package BuyGo\Core\Utils
 * @version 1.0.0
 */
class ContactDataMigration
{
    private $debugService;

    public function __construct()
    {
        $this->debugService = new DebugService();
    }

    /**
     * 執行資料表建立和資料遷移
     */
    public function run(): void
    {
        $this->debugService->log('ContactDataMigration', '開始聯絡資料遷移');

        try {
            // 1. 建立資料表
            $this->createTables();
            
            // 2. 遷移電話資料
            $this->migratePhoneData();
            
            // 3. 遷移地址資料
            $this->migrateAddressData();
            
            $this->debugService->log('ContactDataMigration', '聯絡資料遷移完成');
            
        } catch (\Exception $e) {
            $this->debugService->log('ContactDataMigration', '遷移失敗', [
                'error' => $e->getMessage()
            ], 'error');
            
            throw $e;
        }
    }

    /**
     * 建立 BuyGo 聯絡資料表
     */
    private function createTables(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // 建立電話資料表
        $phoneTableSql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}buygo_phone (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) unsigned NOT NULL,
            email varchar(255) NOT NULL,
            phone varchar(50) NOT NULL,
            phone_type enum('mobile', 'home', 'work', 'other') DEFAULT 'mobile',
            source varchar(50) NOT NULL COMMENT '資料來源: fluentcart, fluentcrm, wordpress, manual',
            is_primary tinyint(1) DEFAULT 0 COMMENT '是否為主要電話',
            is_verified tinyint(1) DEFAULT 0 COMMENT '是否已驗證',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_customer_phone (customer_id, phone),
            KEY idx_email (email),
            KEY idx_phone (phone),
            KEY idx_customer_id (customer_id)
        ) $charset_collate;";

        // 建立地址資料表
        $addressTableSql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}buygo_address (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) unsigned NOT NULL,
            email varchar(255) NOT NULL,
            address_type enum('billing', 'shipping', 'home', 'work', 'other') DEFAULT 'billing',
            first_name varchar(100) DEFAULT NULL,
            last_name varchar(100) DEFAULT NULL,
            company varchar(200) DEFAULT NULL,
            address_line_1 varchar(255) DEFAULT NULL,
            address_line_2 varchar(255) DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            state varchar(100) DEFAULT NULL,
            postcode varchar(20) DEFAULT NULL,
            country varchar(10) DEFAULT NULL,
            formatted_address text DEFAULT NULL COMMENT '格式化的完整地址',
            source varchar(50) NOT NULL COMMENT '資料來源: fluentcart, fluentcrm, wordpress, manual',
            is_primary tinyint(1) DEFAULT 0 COMMENT '是否為主要地址',
            is_verified tinyint(1) DEFAULT 0 COMMENT '是否已驗證',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_customer_address (customer_id, address_type, address_line_1, city),
            KEY idx_email (email),
            KEY idx_customer_id (customer_id),
            KEY idx_address_type (address_type)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($phoneTableSql);
        dbDelta($addressTableSql);

        $this->debugService->log('ContactDataMigration', '資料表建立完成', [
            'phone_table' => $wpdb->prefix . 'buygo_phone',
            'address_table' => $wpdb->prefix . 'buygo_address'
        ]);
    }

    /**
     * 遷移電話資料
     */
    private function migratePhoneData(): void
    {
        global $wpdb;

        $this->debugService->log('ContactDataMigration', '開始遷移電話資料');

        // 1. 從 FluentCart 客戶表遷移電話
        $this->migrateFluentCartPhones();

        // 2. 從 FluentCRM 聯絡人表遷移電話
        $this->migrateFluentCrmPhones();

        // 3. 從 WordPress 用戶 meta 遷移電話
        $this->migrateWordPressPhones();

        // 4. 從訂單地址遷移電話
        $this->migrateOrderPhones();

        $this->debugService->log('ContactDataMigration', '電話資料遷移完成');
    }

    /**
     * 遷移地址資料
     */
    private function migrateAddressData(): void
    {
        global $wpdb;

        $this->debugService->log('ContactDataMigration', '開始遷移地址資料');

        // 1. 從 FluentCart 客戶表遷移地址
        $this->migrateFluentCartAddresses();

        // 2. 從訂單地址遷移地址
        $this->migrateOrderAddresses();

        // 3. 從 WordPress 用戶 meta 遷移地址
        $this->migrateWordPressAddresses();

        $this->debugService->log('ContactDataMigration', '地址資料遷移完成');
    }

    /**
     * 從 FluentCart 客戶表遷移電話
     */
    private function migrateFluentCartPhones(): void
    {
        global $wpdb;

        // 先檢查 phone 欄位是否存在
        $columns = $wpdb->get_results("DESCRIBE {$wpdb->prefix}fct_customers", ARRAY_A);
        $hasPhoneColumn = false;
        foreach ($columns as $column) {
            if ($column['Field'] === 'phone') {
                $hasPhoneColumn = true;
                break;
            }
        }

        if (!$hasPhoneColumn) {
            $this->debugService->log('ContactDataMigration', 'FluentCart 客戶表沒有 phone 欄位，跳過');
            return;
        }

        $customers = $wpdb->get_results("
            SELECT id, email, phone 
            FROM {$wpdb->prefix}fct_customers 
            WHERE phone IS NOT NULL AND phone != ''
        ", ARRAY_A);

        foreach ($customers as $customer) {
            $this->insertPhone(
                $customer['id'],
                $customer['email'],
                $customer['phone'],
                'mobile',
                'fluentcart',
                true
            );
        }

        $this->debugService->log('ContactDataMigration', 'FluentCart 電話遷移完成', [
            'count' => count($customers)
        ]);
    }

    /**
     * 從 FluentCRM 聯絡人表遷移電話
     */
    private function migrateFluentCrmPhones(): void
    {
        global $wpdb;

        // 檢查 FluentCRM 表是否存在
        $contactTable = $wpdb->prefix . 'fc_contacts';
        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$contactTable}'");

        if (!$tableExists) {
            $this->debugService->log('ContactDataMigration', 'FluentCRM 表不存在，跳過');
            return;
        }

        $contacts = $wpdb->get_results("
            SELECT fc.email, fc.phone, fct.id as customer_id
            FROM {$contactTable} fc
            LEFT JOIN {$wpdb->prefix}fct_customers fct ON fc.email = fct.email
            WHERE fc.phone IS NOT NULL AND fc.phone != ''
        ", ARRAY_A);

        foreach ($contacts as $contact) {
            if ($contact['customer_id']) {
                $this->insertPhone(
                    $contact['customer_id'],
                    $contact['email'],
                    $contact['phone'],
                    'mobile',
                    'fluentcrm',
                    false // FluentCRM 的電話不設為主要，除非 FluentCart 沒有
                );
            }
        }

        $this->debugService->log('ContactDataMigration', 'FluentCRM 電話遷移完成', [
            'count' => count($contacts)
        ]);
    }

    /**
     * 從 WordPress 用戶 meta 遷移電話
     */
    private function migrateWordPressPhones(): void
    {
        global $wpdb;

        $phoneFields = ['_mygo_phone', 'billing_phone', 'shipping_phone', 'phone'];

        foreach ($phoneFields as $field) {
            $userMetas = $wpdb->get_results($wpdb->prepare("
                SELECT um.user_id, um.meta_value as phone, u.user_email as email, fct.id as customer_id
                FROM {$wpdb->prefix}usermeta um
                JOIN {$wpdb->prefix}users u ON um.user_id = u.ID
                LEFT JOIN {$wpdb->prefix}fct_customers fct ON u.user_email = fct.email
                WHERE um.meta_key = %s 
                AND um.meta_value IS NOT NULL 
                AND um.meta_value != ''
                AND fct.id IS NOT NULL
            ", $field), ARRAY_A);

            foreach ($userMetas as $meta) {
                $phoneType = $this->getPhoneTypeFromField($field);
                $this->insertPhone(
                    $meta['customer_id'],
                    $meta['email'],
                    $meta['phone'],
                    $phoneType,
                    'wordpress',
                    false
                );
            }
        }

        $this->debugService->log('ContactDataMigration', 'WordPress 電話遷移完成');
    }

    /**
     * 從訂單地址遷移電話
     */
    private function migrateOrderPhones(): void
    {
        global $wpdb;

        try {
            // 先檢查訂單表結構
            $columns = $wpdb->get_results("DESCRIBE {$wpdb->prefix}fct_orders", ARRAY_A);
            $availableColumns = array_column($columns, 'Field');
            
            $hasBillingAddress = in_array('billing_address', $availableColumns);
            $hasShippingAddress = in_array('shipping_address', $availableColumns);
            
            if (!$hasBillingAddress && !$hasShippingAddress) {
                $this->debugService->log('ContactDataMigration', 'FluentCart 訂單表沒有地址欄位，跳過訂單電話遷移');
                return;
            }

            // 建構查詢
            $selectFields = ['customer_id'];
            if ($hasBillingAddress) $selectFields[] = 'billing_address';
            if ($hasShippingAddress) $selectFields[] = 'shipping_address';
            $selectFields[] = '(SELECT email FROM ' . $wpdb->prefix . 'fct_customers WHERE id = o.customer_id) as email';
            
            $selectClause = implode(', ', $selectFields);
            
            $whereConditions = [];
            if ($hasBillingAddress) $whereConditions[] = 'billing_address IS NOT NULL';
            if ($hasShippingAddress) $whereConditions[] = 'shipping_address IS NOT NULL';
            
            $whereClause = '(' . implode(' OR ', $whereConditions) . ') AND customer_id IS NOT NULL';

            $orders = $wpdb->get_results("
                SELECT {$selectClause}
                FROM {$wpdb->prefix}fct_orders o
                WHERE {$whereClause}
            ", ARRAY_A);

            foreach ($orders as $order) {
                if (!$order['email']) continue;

                // 處理 billing_address
                if ($hasBillingAddress && !empty($order['billing_address'])) {
                    $billingData = json_decode($order['billing_address'], true);
                    if ($billingData && !empty($billingData['phone'])) {
                        $this->insertPhone(
                            $order['customer_id'],
                            $order['email'],
                            $billingData['phone'],
                            'mobile',
                            'fluentcart_order',
                            false
                        );
                    }
                }

                // 處理 shipping_address
                if ($hasShippingAddress && !empty($order['shipping_address'])) {
                    $shippingData = json_decode($order['shipping_address'], true);
                    if ($shippingData && !empty($shippingData['phone'])) {
                        $this->insertPhone(
                            $order['customer_id'],
                            $order['email'],
                            $shippingData['phone'],
                            'mobile',
                            'fluentcart_order',
                            false
                        );
                    }
                }
            }

            $this->debugService->log('ContactDataMigration', '訂單電話遷移完成', [
                'count' => count($orders)
            ]);

        } catch (\Exception $e) {
            $this->debugService->log('ContactDataMigration', '訂單電話遷移失敗', [
                'error' => $e->getMessage()
            ], 'error');
        }
    }

    /**
     * 從 FluentCart 客戶表遷移地址
     */
    private function migrateFluentCartAddresses(): void
    {
        global $wpdb;

        try {
            // 先檢查客戶表結構
            $columns = $wpdb->get_results("DESCRIBE {$wpdb->prefix}fct_customers", ARRAY_A);
            $availableColumns = array_column($columns, 'Field');
            
            $addressFields = ['city', 'state', 'country', 'postcode', 'first_name', 'last_name'];
            $existingFields = array_intersect($addressFields, $availableColumns);
            
            if (empty($existingFields)) {
                $this->debugService->log('ContactDataMigration', 'FluentCart 客戶表沒有地址相關欄位，跳過');
                return;
            }

            // 建構查詢
            $selectFields = ['id', 'email'];
            $selectFields = array_merge($selectFields, $existingFields);
            $selectClause = implode(', ', $selectFields);
            
            // 建構 WHERE 條件
            $whereConditions = [];
            foreach (['city', 'country'] as $field) {
                if (in_array($field, $existingFields)) {
                    $whereConditions[] = "({$field} IS NOT NULL AND {$field} != '')";
                }
            }
            
            if (empty($whereConditions)) {
                $this->debugService->log('ContactDataMigration', 'FluentCart 客戶表沒有可用的地址欄位');
                return;
            }
            
            $whereClause = implode(' OR ', $whereConditions);

            $customers = $wpdb->get_results("
                SELECT {$selectClause}
                FROM {$wpdb->prefix}fct_customers 
                WHERE {$whereClause}
            ", ARRAY_A);

            foreach ($customers as $customer) {
                $addressData = [];
                foreach ($existingFields as $field) {
                    if (isset($customer[$field])) {
                        $addressData[$field] = $customer[$field];
                    }
                }
                
                if (!empty($addressData)) {
                    $this->insertAddress(
                        $customer['id'],
                        $customer['email'],
                        'billing',
                        $addressData,
                        'fluentcart',
                        true
                    );
                }
            }

            $this->debugService->log('ContactDataMigration', 'FluentCart 地址遷移完成', [
                'count' => count($customers),
                'available_fields' => $existingFields
            ]);

        } catch (\Exception $e) {
            $this->debugService->log('ContactDataMigration', 'FluentCart 地址遷移失敗', [
                'error' => $e->getMessage()
            ], 'error');
        }
    }

    /**
     * 從訂單地址遷移地址
     */
    private function migrateOrderAddresses(): void
    {
        global $wpdb;

        try {
            // 先檢查訂單表結構
            $columns = $wpdb->get_results("DESCRIBE {$wpdb->prefix}fct_orders", ARRAY_A);
            $availableColumns = array_column($columns, 'Field');
            
            $hasBillingAddress = in_array('billing_address', $availableColumns);
            $hasShippingAddress = in_array('shipping_address', $availableColumns);
            
            if (!$hasBillingAddress && !$hasShippingAddress) {
                $this->debugService->log('ContactDataMigration', 'FluentCart 訂單表沒有地址欄位，跳過訂單地址遷移');
                return;
            }

            // 建構查詢
            $selectFields = ['customer_id'];
            if ($hasBillingAddress) $selectFields[] = 'billing_address';
            if ($hasShippingAddress) $selectFields[] = 'shipping_address';
            $selectFields[] = '(SELECT email FROM ' . $wpdb->prefix . 'fct_customers WHERE id = o.customer_id) as email';
            
            $selectClause = implode(', ', $selectFields);
            
            $whereConditions = [];
            if ($hasBillingAddress) $whereConditions[] = 'billing_address IS NOT NULL';
            if ($hasShippingAddress) $whereConditions[] = 'shipping_address IS NOT NULL';
            
            $whereClause = '(' . implode(' OR ', $whereConditions) . ') AND customer_id IS NOT NULL';

            $orders = $wpdb->get_results("
                SELECT {$selectClause}
                FROM {$wpdb->prefix}fct_orders o
                WHERE {$whereClause}
            ", ARRAY_A);

            foreach ($orders as $order) {
                if (!$order['email']) continue;

                // 處理 billing_address
                if ($hasBillingAddress && !empty($order['billing_address'])) {
                    $billingData = json_decode($order['billing_address'], true);
                    if ($billingData) {
                        $this->insertAddress(
                            $order['customer_id'],
                            $order['email'],
                            'billing',
                            $billingData,
                            'fluentcart_order',
                            false
                        );
                    }
                }

                // 處理 shipping_address
                if ($hasShippingAddress && !empty($order['shipping_address'])) {
                    $shippingData = json_decode($order['shipping_address'], true);
                    if ($shippingData) {
                        $this->insertAddress(
                            $order['customer_id'],
                            $order['email'],
                            'shipping',
                            $shippingData,
                            'fluentcart_order',
                            false
                        );
                    }
                }
            }

            $this->debugService->log('ContactDataMigration', '訂單地址遷移完成', [
                'count' => count($orders)
            ]);

        } catch (\Exception $e) {
            $this->debugService->log('ContactDataMigration', '訂單地址遷移失敗', [
                'error' => $e->getMessage()
            ], 'error');
        }
    }

    /**
     * 從 WordPress 用戶 meta 遷移地址
     */
    private function migrateWordPressAddresses(): void
    {
        global $wpdb;

        $addressTypes = ['billing', 'shipping'];

        foreach ($addressTypes as $type) {
            $users = $wpdb->get_results($wpdb->prepare("
                SELECT DISTINCT u.ID as user_id, u.user_email as email, fct.id as customer_id
                FROM {$wpdb->prefix}users u
                JOIN {$wpdb->prefix}usermeta um ON u.ID = um.user_id
                LEFT JOIN {$wpdb->prefix}fct_customers fct ON u.user_email = fct.email
                WHERE um.meta_key LIKE %s
                AND fct.id IS NOT NULL
            ", $type . '_%'), ARRAY_A);

            foreach ($users as $user) {
                $addressData = $this->getWordPressUserAddress($user['user_id'], $type);
                
                if (!empty($addressData)) {
                    $this->insertAddress(
                        $user['customer_id'],
                        $user['email'],
                        $type,
                        $addressData,
                        'wordpress',
                        false
                    );
                }
            }
        }

        $this->debugService->log('ContactDataMigration', 'WordPress 地址遷移完成');
    }

    /**
     * 插入電話資料
     */
    private function insertPhone(int $customerId, string $email, string $phone, string $phoneType, string $source, bool $isPrimary): void
    {
        global $wpdb;

        // 清理電話號碼
        $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
        if (empty($cleanPhone)) return;

        // 檢查是否已存在
        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}buygo_phone 
            WHERE customer_id = %d AND phone = %s
        ", $customerId, $cleanPhone));

        if ($exists) return;

        // 如果設為主要電話，先將其他電話設為非主要
        if ($isPrimary) {
            $wpdb->update(
                $wpdb->prefix . 'buygo_phone',
                ['is_primary' => 0],
                ['customer_id' => $customerId]
            );
        }

        $wpdb->insert(
            $wpdb->prefix . 'buygo_phone',
            [
                'customer_id' => $customerId,
                'email' => $email,
                'phone' => $cleanPhone,
                'phone_type' => $phoneType,
                'source' => $source,
                'is_primary' => $isPrimary ? 1 : 0
            ]
        );
    }

    /**
     * 插入地址資料
     */
    private function insertAddress(int $customerId, string $email, string $addressType, array $addressData, string $source, bool $isPrimary): void
    {
        global $wpdb;

        // 檢查必要欄位
        if (empty($addressData['city']) && empty($addressData['address_line_1'])) {
            return;
        }

        // 格式化地址
        $formattedAddress = $this->formatAddress($addressData);

        // 檢查是否已存在相似地址
        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}buygo_address 
            WHERE customer_id = %d 
            AND address_type = %s 
            AND (address_line_1 = %s OR city = %s)
        ", $customerId, $addressType, $addressData['address_line_1'] ?? '', $addressData['city'] ?? ''));

        if ($exists) return;

        // 如果設為主要地址，先將同類型其他地址設為非主要
        if ($isPrimary) {
            $wpdb->update(
                $wpdb->prefix . 'buygo_address',
                ['is_primary' => 0],
                [
                    'customer_id' => $customerId,
                    'address_type' => $addressType
                ]
            );
        }

        $wpdb->insert(
            $wpdb->prefix . 'buygo_address',
            [
                'customer_id' => $customerId,
                'email' => $email,
                'address_type' => $addressType,
                'first_name' => $addressData['first_name'] ?? '',
                'last_name' => $addressData['last_name'] ?? '',
                'company' => $addressData['company'] ?? '',
                'address_line_1' => $addressData['address_line_1'] ?? '',
                'address_line_2' => $addressData['address_line_2'] ?? '',
                'city' => $addressData['city'] ?? '',
                'state' => $addressData['state'] ?? '',
                'postcode' => $addressData['postcode'] ?? $addressData['zip'] ?? '',
                'country' => $addressData['country'] ?? '',
                'formatted_address' => $formattedAddress,
                'source' => $source,
                'is_primary' => $isPrimary ? 1 : 0
            ]
        );
    }

    /**
     * 根據欄位名稱判斷電話類型
     */
    private function getPhoneTypeFromField(string $field): string
    {
        if (strpos($field, 'billing') !== false) return 'mobile';
        if (strpos($field, 'shipping') !== false) return 'mobile';
        if (strpos($field, 'work') !== false) return 'work';
        if (strpos($field, 'home') !== false) return 'home';
        return 'mobile';
    }

    /**
     * 取得 WordPress 用戶地址資料
     */
    private function getWordPressUserAddress(int $userId, string $type): array
    {
        $addressData = [];
        $fields = [
            'first_name', 'last_name', 'company', 'address_1', 'address_2',
            'city', 'state', 'postcode', 'country'
        ];

        foreach ($fields as $field) {
            $metaKey = $type . '_' . $field;
            $value = get_user_meta($userId, $metaKey, true);
            
            if ($field === 'address_1') {
                $addressData['address_line_1'] = $value;
            } elseif ($field === 'address_2') {
                $addressData['address_line_2'] = $value;
            } else {
                $addressData[$field] = $value;
            }
        }

        return array_filter($addressData);
    }

    /**
     * 格式化地址為單一字串
     */
    private function formatAddress(array $addressData): string
    {
        $parts = [];

        if (!empty($addressData['address_line_1'])) {
            $parts[] = $addressData['address_line_1'];
        }
        if (!empty($addressData['address_line_2'])) {
            $parts[] = $addressData['address_line_2'];
        }
        if (!empty($addressData['city'])) {
            $parts[] = $addressData['city'];
        }
        if (!empty($addressData['state'])) {
            $parts[] = $addressData['state'];
        }
        if (!empty($addressData['country'])) {
            $parts[] = $this->getCountryName($addressData['country']);
        }
        if (!empty($addressData['postcode'])) {
            $parts[] = $addressData['postcode'];
        }

        return implode(' ', $parts);
    }

    /**
     * 轉換國家代碼為中文名稱
     */
    private function getCountryName(string $countryCode): string
    {
        $countries = [
            'TW' => '台灣',
            'JP' => '日本',
            'US' => '美國',
            'CN' => '中國',
            'HK' => '香港',
            'SG' => '新加坡',
            'MY' => '馬來西亞',
            'TH' => '泰國',
            'KR' => '韓國',
            'AU' => '澳洲',
            'CA' => '加拿大',
            'GB' => '英國',
            'DE' => '德國',
            'FR' => '法國'
        ];
        
        return $countries[strtoupper($countryCode)] ?? $countryCode;
    }

    /**
     * 取得客戶的主要電話
     */
    public function getCustomerPrimaryPhone(int $customerId): ?string
    {
        global $wpdb;

        $phone = $wpdb->get_var($wpdb->prepare("
            SELECT phone FROM {$wpdb->prefix}buygo_phone 
            WHERE customer_id = %d AND is_primary = 1
            ORDER BY updated_at DESC
            LIMIT 1
        ", $customerId));

        return $phone;
    }

    /**
     * 取得客戶的主要地址
     */
    public function getCustomerPrimaryAddress(int $customerId, string $addressType = 'billing'): ?array
    {
        global $wpdb;

        $address = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}buygo_address 
            WHERE customer_id = %d AND address_type = %s AND is_primary = 1
            ORDER BY updated_at DESC
            LIMIT 1
        ", $customerId, $addressType), ARRAY_A);

        return $address;
    }

    /**
     * 更新客戶電話
     */
    public function updateCustomerPhone(int $customerId, string $email, string $phone, string $source = 'manual'): bool
    {
        try {
            $this->insertPhone($customerId, $email, $phone, 'mobile', $source, true);
            return true;
        } catch (\Exception $e) {
            $this->debugService->log('ContactDataMigration', '更新客戶電話失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ], 'error');
            return false;
        }
    }

    /**
     * 更新客戶地址
     */
    public function updateCustomerAddress(int $customerId, string $email, array $addressData, string $addressType = 'billing', string $source = 'manual'): bool
    {
        try {
            $this->insertAddress($customerId, $email, $addressType, $addressData, $source, true);
            return true;
        } catch (\Exception $e) {
            $this->debugService->log('ContactDataMigration', '更新客戶地址失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ], 'error');
            return false;
        }
    }
}