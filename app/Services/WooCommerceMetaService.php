<?php

namespace BuyGo\Core\Services;

/**
 * WooCommerce Meta 資料服務
 * 
 * 讀取 WooCommerce 的用戶 Meta 資料（不需要安裝 WooCommerce）
 * 作為客戶資料的備用來源
 */
class WooCommerceMetaService
{
    private $debugService;

    public function __construct()
    {
        $this->debugService = new DebugService();
    }

    /**
     * 從 WooCommerce Meta 取得客戶資料
     * 
     * @param string $email 客戶 email
     * @return array
     */
    public function getCustomerDataByEmail(string $email): array
    {
        $user = get_user_by('email', $email);
        
        if (!$user) {
            return [
                'found' => false,
                'phone' => null,
                'billing_address' => null,
                'shipping_address' => null
            ];
        }

        return $this->getCustomerDataByUserId($user->ID);
    }

    /**
     * 從 WooCommerce Meta 取得客戶資料
     * 
     * @param int $userId WordPress 用戶 ID
     * @return array
     */
    public function getCustomerDataByUserId(int $userId): array
    {
        $result = [
            'found' => true,
            'user_id' => $userId,
            'phone' => null,
            'billing_address' => null,
            'shipping_address' => null,
            'raw_meta' => []
        ];

        try {
            // 取得所有用戶 Meta
            $allMeta = get_user_meta($userId);
            
            // WooCommerce 標準欄位
            $wooFields = [
                // 帳單資訊
                'billing_first_name',
                'billing_last_name', 
                'billing_company',
                'billing_address_1',
                'billing_address_2',
                'billing_city',
                'billing_postcode',
                'billing_country',
                'billing_state',
                'billing_phone',
                'billing_email',
                
                // 運送資訊
                'shipping_first_name',
                'shipping_last_name',
                'shipping_company', 
                'shipping_address_1',
                'shipping_address_2',
                'shipping_city',
                'shipping_postcode',
                'shipping_country',
                'shipping_state',
                'shipping_phone',
            ];

            // 收集 WooCommerce 相關的 Meta
            foreach ($wooFields as $field) {
                if (isset($allMeta[$field]) && !empty($allMeta[$field][0])) {
                    $result['raw_meta'][$field] = $allMeta[$field][0];
                }
            }

            // 處理電話號碼
            $result['phone'] = $this->extractPhone($result['raw_meta']);

            // 處理帳單地址
            $result['billing_address'] = $this->extractAddress($result['raw_meta'], 'billing');

            // 處理運送地址
            $result['shipping_address'] = $this->extractAddress($result['raw_meta'], 'shipping');

            $this->debugService->log('WooCommerceMetaService', '成功取得客戶資料', [
                'user_id' => $userId,
                'has_phone' => !empty($result['phone']),
                'has_billing' => !empty($result['billing_address']),
                'has_shipping' => !empty($result['shipping_address'])
            ]);

        } catch (\Exception $e) {
            $this->debugService->log('WooCommerceMetaService', '取得客戶資料失敗', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ], 'error');
        }

        return $result;
    }

    /**
     * 提取電話號碼
     * 
     * @param array $meta Meta 資料
     * @return string|null
     */
    private function extractPhone(array $meta): ?string
    {
        // 優先順序：billing_phone > shipping_phone
        $phoneFields = ['billing_phone', 'shipping_phone'];
        
        foreach ($phoneFields as $field) {
            if (!empty($meta[$field])) {
                return $meta[$field];
            }
        }

        return null;
    }

    /**
     * 提取地址資訊
     * 
     * @param array $meta Meta 資料
     * @param string $type 地址類型 (billing 或 shipping)
     * @return array|null
     */
    private function extractAddress(array $meta, string $type): ?array
    {
        $fields = [
            'first_name' => $type . '_first_name',
            'last_name' => $type . '_last_name',
            'company' => $type . '_company',
            'address_line_1' => $type . '_address_1',
            'address_line_2' => $type . '_address_2',
            'city' => $type . '_city',
            'state' => $type . '_state',
            'postcode' => $type . '_postcode',
            'country' => $type . '_country'
        ];

        $address = [];
        $hasData = false;

        foreach ($fields as $standard => $metaKey) {
            if (!empty($meta[$metaKey])) {
                $address[$standard] = $meta[$metaKey];
                $hasData = true;
            }
        }

        if (!$hasData) {
            return null;
        }

        // 建立格式化地址
        $addressParts = array_filter([
            $address['address_line_1'] ?? '',
            $address['address_line_2'] ?? '',
            $address['city'] ?? '',
            $address['state'] ?? '',
            $address['postcode'] ?? '',
            $address['country'] ?? ''
        ]);

        $address['formatted_address'] = implode(', ', $addressParts);

        return $address;
    }

    /**
     * 搜尋所有有 WooCommerce Meta 的用戶
     * 
     * @param int $limit 限制數量
     * @return array
     */
    public function findUsersWithWooCommerceMeta(int $limit = 50): array
    {
        global $wpdb;

        try {
            // 搜尋有 billing_phone 或 billing_address_1 的用戶
            $users = $wpdb->get_results($wpdb->prepare("
                SELECT DISTINCT u.ID, u.user_email, u.display_name
                FROM {$wpdb->users} u
                INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
                WHERE um.meta_key IN ('billing_phone', 'billing_address_1', 'shipping_phone', 'shipping_address_1')
                AND um.meta_value IS NOT NULL 
                AND um.meta_value != ''
                LIMIT %d
            ", $limit), ARRAY_A);

            $result = [];
            foreach ($users as $user) {
                $customerData = $this->getCustomerDataByUserId($user['ID']);
                $result[] = [
                    'user_id' => $user['ID'],
                    'email' => $user['user_email'],
                    'display_name' => $user['display_name'],
                    'phone' => $customerData['phone'],
                    'has_billing_address' => !empty($customerData['billing_address']),
                    'has_shipping_address' => !empty($customerData['shipping_address'])
                ];
            }

            $this->debugService->log('WooCommerceMetaService', '搜尋 WooCommerce Meta 用戶', [
                'found_users' => count($result)
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->debugService->log('WooCommerceMetaService', '搜尋 WooCommerce Meta 用戶失敗', [
                'error' => $e->getMessage()
            ], 'error');

            return [];
        }
    }

    /**
     * 同步 WooCommerce Meta 到 BuyGo 系統
     * 
     * @param string $email 客戶 email
     * @return array 同步結果
     */
    public function syncToBuyGo(string $email): array
    {
        $customerData = $this->getCustomerDataByEmail($email);
        
        if (!$customerData['found']) {
            return [
                'success' => false,
                'message' => '找不到對應的 WordPress 用戶'
            ];
        }

        $result = [
            'success' => true,
            'phone_synced' => false,
            'billing_synced' => false,
            'shipping_synced' => false
        ];

        try {
            // 找到對應的 FluentCart 客戶
            global $wpdb;
            $fluentCustomer = $wpdb->get_row($wpdb->prepare("
                SELECT id FROM {$wpdb->prefix}fct_customers 
                WHERE email = %s OR user_id = %d
                LIMIT 1
            ", $email, $customerData['user_id']), ARRAY_A);

            if (!$fluentCustomer) {
                return [
                    'success' => false,
                    'message' => '找不到對應的 FluentCart 客戶'
                ];
            }

            $customerId = $fluentCustomer['id'];
            $contactDataService = new ContactDataService();

            // 同步電話
            if ($customerData['phone']) {
                $phoneResult = $contactDataService->updateCustomerPhone(
                    $customerId, 
                    $email, 
                    $customerData['phone'], 
                    'woocommerce_meta'
                );
                $result['phone_synced'] = $phoneResult;
            }

            // 同步帳單地址
            if ($customerData['billing_address']) {
                $billingResult = $contactDataService->updateCustomerAddress(
                    $customerId,
                    $email,
                    $customerData['billing_address'],
                    'billing',
                    'woocommerce_meta'
                );
                $result['billing_synced'] = $billingResult;
            }

            // 同步運送地址
            if ($customerData['shipping_address']) {
                $shippingResult = $contactDataService->updateCustomerAddress(
                    $customerId,
                    $email,
                    $customerData['shipping_address'],
                    'shipping',
                    'woocommerce_meta'
                );
                $result['shipping_synced'] = $shippingResult;
            }

            $this->debugService->log('WooCommerceMetaService', 'WooCommerce Meta 同步完成', [
                'email' => $email,
                'result' => $result
            ]);

        } catch (\Exception $e) {
            $this->debugService->log('WooCommerceMetaService', 'WooCommerce Meta 同步失敗', [
                'email' => $email,
                'error' => $e->getMessage()
            ], 'error');

            $result['success'] = false;
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * 批量同步所有 WooCommerce Meta 資料
     * 
     * @param int $limit 每次處理的數量
     * @return array 統計結果
     */
    public function batchSyncAll(int $limit = 50): array
    {
        $users = $this->findUsersWithWooCommerceMeta($limit);
        
        $stats = [
            'processed' => 0,
            'phone_synced' => 0,
            'address_synced' => 0,
            'errors' => 0
        ];

        foreach ($users as $user) {
            try {
                $result = $this->syncToBuyGo($user['email']);
                
                $stats['processed']++;
                if ($result['phone_synced']) $stats['phone_synced']++;
                if ($result['billing_synced'] || $result['shipping_synced']) $stats['address_synced']++;

            } catch (\Exception $e) {
                $stats['errors']++;
                $this->debugService->log('WooCommerceMetaService', '批量同步單一用戶失敗', [
                    'email' => $user['email'],
                    'error' => $e->getMessage()
                ], 'error');
            }
        }

        return $stats;
    }
}