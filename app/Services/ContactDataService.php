<?php

namespace BuyGo\Core\Services;

use BuyGo\Core\Utils\ContactDataMigration;

/**
 * Contact Data Service - 聯絡資料服務
 * 
 * 統一管理客戶的電話和地址資料
 * 提供統一的 API 來存取和更新聯絡資訊
 * 
 * @package BuyGo\Core\Services
 * @version 1.0.0
 */
class ContactDataService
{
    private $debugService;
    private $migration;

    public function __construct()
    {
        $this->debugService = new DebugService();
        $this->migration = new ContactDataMigration();
    }

    /**
     * 取得客戶完整聯絡資料
     * 
     * @param int $customerId 客戶 ID
     * @return array
     */
    public function getCustomerContactData(int $customerId): array
    {
        global $wpdb;

        try {
            // 取得所有電話
            $phones = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}buygo_phone 
                WHERE customer_id = %d 
                ORDER BY is_primary DESC, updated_at DESC
            ", $customerId), ARRAY_A);

            // 取得所有地址
            $addresses = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}buygo_address 
                WHERE customer_id = %d 
                ORDER BY address_type, is_primary DESC, updated_at DESC
            ", $customerId), ARRAY_A);

            // 取得主要聯絡資訊
            $primaryPhone = $this->getPrimaryPhone($customerId);
            $primaryBillingAddress = $this->getPrimaryAddress($customerId, 'billing');
            $primaryShippingAddress = $this->getPrimaryAddress($customerId, 'shipping');

            return [
                'customer_id' => $customerId,
                'primary_phone' => $primaryPhone,
                'primary_billing_address' => $primaryBillingAddress,
                'primary_shipping_address' => $primaryShippingAddress,
                'all_phones' => $phones,
                'all_addresses' => $addresses,
                'data_complete' => $this->isDataComplete($primaryPhone, $primaryBillingAddress),
                'missing_fields' => $this->getMissingFields($primaryPhone, $primaryBillingAddress)
            ];

        } catch (\Exception $e) {
            $this->debugService->log('ContactDataService', '取得客戶聯絡資料失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ], 'error');

            return [
                'customer_id' => $customerId,
                'primary_phone' => null,
                'primary_billing_address' => null,
                'primary_shipping_address' => null,
                'all_phones' => [],
                'all_addresses' => [],
                'data_complete' => false,
                'missing_fields' => ['phone', 'address']
            ];
        }
    }

    /**
     * 取得客戶主要電話
     * 
     * @param int $customerId 客戶 ID
     * @return string|null
     */
    public function getPrimaryPhone(int $customerId): ?string
    {
        return $this->migration->getCustomerPrimaryPhone($customerId);
    }

    /**
     * 取得客戶主要地址
     * 
     * @param int $customerId 客戶 ID
     * @param string $addressType 地址類型
     * @return array|null
     */
    public function getPrimaryAddress(int $customerId, string $addressType = 'billing'): ?array
    {
        return $this->migration->getCustomerPrimaryAddress($customerId, $addressType);
    }

    /**
     * 更新客戶電話
     * 
     * @param int $customerId 客戶 ID
     * @param string $email 客戶 email
     * @param string $phone 電話號碼
     * @param string $source 資料來源
     * @return bool
     */
    public function updateCustomerPhone(int $customerId, string $email, string $phone, string $source = 'manual'): bool
    {
        $result = $this->migration->updateCustomerPhone($customerId, $email, $phone, $source);
        
        if ($result) {
            $this->debugService->log('ContactDataService', '客戶電話更新成功', [
                'customer_id' => $customerId,
                'phone' => $phone,
                'source' => $source
            ]);
        }

        return $result;
    }

    /**
     * 更新客戶地址
     * 
     * @param int $customerId 客戶 ID
     * @param string $email 客戶 email
     * @param array $addressData 地址資料
     * @param string $addressType 地址類型
     * @param string $source 資料來源
     * @return bool
     */
    public function updateCustomerAddress(int $customerId, string $email, array $addressData, string $addressType = 'billing', string $source = 'manual'): bool
    {
        $result = $this->migration->updateCustomerAddress($customerId, $email, $addressData, $addressType, $source);
        
        if ($result) {
            $this->debugService->log('ContactDataService', '客戶地址更新成功', [
                'customer_id' => $customerId,
                'address_type' => $addressType,
                'source' => $source
            ]);
        }

        return $result;
    }

    /**
     * 從外部來源同步聯絡資料
     * 
     * @param int $customerId 客戶 ID
     * @param string $email 客戶 email
     * @return array 同步結果
     */
    public function syncFromExternalSources(int $customerId, string $email): array
    {
        $results = [
            'phone_synced' => false,
            'address_synced' => false,
            'sources' => []
        ];

        try {
            // 1. 從 FluentCRM 同步電話
            $crmPhone = $this->getPhoneFromFluentCrm($email);
            if ($crmPhone) {
                $this->updateCustomerPhone($customerId, $email, $crmPhone, 'fluentcrm');
                $results['phone_synced'] = true;
                $results['sources'][] = 'fluentcrm_phone';
            }

            // 2. 從 FluentCart 訂單同步地址和電話
            $orderData = $this->getDataFromFluentCartOrders($customerId);
            if ($orderData['phone']) {
                $this->updateCustomerPhone($customerId, $email, $orderData['phone'], 'fluentcart_order');
                $results['phone_synced'] = true;
                $results['sources'][] = 'fluentcart_order_phone';
            }
            if ($orderData['billing_address']) {
                $this->updateCustomerAddress($customerId, $email, $orderData['billing_address'], 'billing', 'fluentcart_order');
                $results['address_synced'] = true;
                $results['sources'][] = 'fluentcart_order_billing';
            }
            if ($orderData['shipping_address']) {
                $this->updateCustomerAddress($customerId, $email, $orderData['shipping_address'], 'shipping', 'fluentcart_order');
                $results['address_synced'] = true;
                $results['sources'][] = 'fluentcart_order_shipping';
            }

            // 3. 從 WordPress 用戶 meta 同步
            $wpUserData = $this->getDataFromWordPressUser($email);
            if ($wpUserData['phone']) {
                $this->updateCustomerPhone($customerId, $email, $wpUserData['phone'], 'wordpress');
                $results['phone_synced'] = true;
                $results['sources'][] = 'wordpress_phone';
            }
            if ($wpUserData['billing_address']) {
                $this->updateCustomerAddress($customerId, $email, $wpUserData['billing_address'], 'billing', 'wordpress');
                $results['address_synced'] = true;
                $results['sources'][] = 'wordpress_billing';
            }

            $this->debugService->log('ContactDataService', '外部來源同步完成', [
                'customer_id' => $customerId,
                'results' => $results
            ]);

        } catch (\Exception $e) {
            $this->debugService->log('ContactDataService', '外部來源同步失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ], 'error');
        }

        return $results;
    }

    /**
     * 檢查資料完整性
     * 
     * @param string|null $phone 電話
     * @param array|null $address 地址
     * @return bool
     */
    private function isDataComplete(?string $phone, ?array $address): bool
    {
        return !empty($phone) && !empty($address) && !empty($address['formatted_address']);
    }

    /**
     * 取得缺失欄位
     * 
     * @param string|null $phone 電話
     * @param array|null $address 地址
     * @return array
     */
    private function getMissingFields(?string $phone, ?array $address): array
    {
        $missing = [];

        if (empty($phone)) {
            $missing[] = 'phone';
        }

        if (empty($address) || empty($address['formatted_address'])) {
            $missing[] = 'address';
        }

        return $missing;
    }

    /**
     * 從 FluentCRM 取得電話
     * 
     * @param string $email 客戶 email
     * @return string|null
     */
    private function getPhoneFromFluentCrm(string $email): ?string
    {
        global $wpdb;

        try {
            $contactTable = $wpdb->prefix . 'fc_contacts';
            $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$contactTable}'");

            if (!$tableExists) {
                return null;
            }

            $contact = $wpdb->get_row($wpdb->prepare("
                SELECT phone FROM {$contactTable}
                WHERE email = %s AND phone IS NOT NULL AND phone != ''
                LIMIT 1
            ", $email), ARRAY_A);

            return $contact ? $contact['phone'] : null;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 從 FluentCart 訂單取得資料
     * 
     * @param int $customerId 客戶 ID
     * @return array
     */
    private function getDataFromFluentCartOrders(int $customerId): array
    {
        global $wpdb;

        $result = [
            'phone' => null,
            'billing_address' => null,
            'shipping_address' => null
        ];

        try {
            // 先檢查訂單表結構
            $columns = $wpdb->get_results("DESCRIBE {$wpdb->prefix}fct_orders", ARRAY_A);
            $availableColumns = array_column($columns, 'Field');
            
            $hasBillingAddress = in_array('billing_address', $availableColumns);
            $hasShippingAddress = in_array('shipping_address', $availableColumns);
            
            if (!$hasBillingAddress && !$hasShippingAddress) {
                // 如果沒有地址欄位，嘗試從其他欄位取得資料
                $this->debugService->log('ContactDataService', 'FluentCart 訂單表沒有地址欄位');
                return $result;
            }

            // 建構查詢
            $selectFields = ['id'];
            if ($hasBillingAddress) $selectFields[] = 'billing_address';
            if ($hasShippingAddress) $selectFields[] = 'shipping_address';
            
            $selectClause = implode(', ', $selectFields);
            
            $order = $wpdb->get_row($wpdb->prepare("
                SELECT {$selectClause}
                FROM {$wpdb->prefix}fct_orders
                WHERE customer_id = %d
                ORDER BY id DESC
                LIMIT 1
            ", $customerId), ARRAY_A);

            if ($order) {
                // 處理 billing_address
                if ($hasBillingAddress && $order['billing_address']) {
                    $billingData = json_decode($order['billing_address'], true);
                    if ($billingData) {
                        if (!empty($billingData['phone'])) {
                            $result['phone'] = $billingData['phone'];
                        }
                        $result['billing_address'] = $billingData;
                    }
                }

                // 處理 shipping_address
                if ($hasShippingAddress && $order['shipping_address']) {
                    $shippingData = json_decode($order['shipping_address'], true);
                    if ($shippingData) {
                        if (!$result['phone'] && !empty($shippingData['phone'])) {
                            $result['phone'] = $shippingData['phone'];
                        }
                        $result['shipping_address'] = $shippingData;
                    }
                }
            }

        } catch (\Exception $e) {
            $this->debugService->log('ContactDataService', 'FluentCart 訂單查詢失敗', [
                'error' => $e->getMessage()
            ], 'error');
        }

        return $result;
    }

    /**
     * 從 WordPress 用戶取得資料
     * 
     * @param string $email 客戶 email
     * @return array
     */
    private function getDataFromWordPressUser(string $email): array
    {
        $result = [
            'phone' => null,
            'billing_address' => null,
            'shipping_address' => null
        ];

        try {
            $user = get_user_by('email', $email);
            if (!$user) {
                return $result;
            }

            // 取得電話
            $phoneFields = ['_mygo_phone', 'billing_phone', 'shipping_phone', 'phone'];
            foreach ($phoneFields as $field) {
                $phone = get_user_meta($user->ID, $field, true);
                if (!empty($phone)) {
                    $result['phone'] = $phone;
                    break;
                }
            }

            // 取得地址
            $addressTypes = ['billing', 'shipping'];
            foreach ($addressTypes as $type) {
                $address = $this->getWordPressUserAddress($user->ID, $type);
                if (!empty($address)) {
                    $result[$type . '_address'] = $address;
                }
            }

        } catch (\Exception $e) {
            // 忽略錯誤，返回空結果
        }

        return $result;
    }

    /**
     * 取得 WordPress 用戶地址
     * 
     * @param int $userId 用戶 ID
     * @param string $type 地址類型
     * @return array
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
     * 批量同步所有客戶的聯絡資料
     * 
     * @param int $limit 每次處理的客戶數量
     * @return array 同步結果統計
     */
    public function batchSyncAllCustomers(int $limit = 50): array
    {
        global $wpdb;

        $stats = [
            'processed' => 0,
            'phone_synced' => 0,
            'address_synced' => 0,
            'errors' => 0
        ];

        try {
            $customers = $wpdb->get_results($wpdb->prepare("
                SELECT id, email FROM {$wpdb->prefix}fct_customers
                WHERE email IS NOT NULL AND email != ''
                LIMIT %d
            ", $limit), ARRAY_A);

            foreach ($customers as $customer) {
                try {
                    $results = $this->syncFromExternalSources($customer['id'], $customer['email']);
                    
                    $stats['processed']++;
                    if ($results['phone_synced']) $stats['phone_synced']++;
                    if ($results['address_synced']) $stats['address_synced']++;

                } catch (\Exception $e) {
                    $stats['errors']++;
                    $this->debugService->log('ContactDataService', '批量同步單一客戶失敗', [
                        'customer_id' => $customer['id'],
                        'error' => $e->getMessage()
                    ], 'error');
                }
            }

            $this->debugService->log('ContactDataService', '批量同步完成', $stats);

        } catch (\Exception $e) {
            $this->debugService->log('ContactDataService', '批量同步失敗', [
                'error' => $e->getMessage()
            ], 'error');
        }

        return $stats;
    }

    /**
     * 取得聯絡資料統計
     * 
     * @return array
     */
    public function getContactDataStats(): array
    {
        global $wpdb;

        try {
            $phoneStats = $wpdb->get_row("
                SELECT 
                    COUNT(DISTINCT customer_id) as customers_with_phone,
                    COUNT(*) as total_phones,
                    SUM(is_primary) as primary_phones
                FROM {$wpdb->prefix}buygo_phone
            ", ARRAY_A);

            $addressStats = $wpdb->get_row("
                SELECT 
                    COUNT(DISTINCT customer_id) as customers_with_address,
                    COUNT(*) as total_addresses,
                    SUM(is_primary) as primary_addresses
                FROM {$wpdb->prefix}buygo_address
            ", ARRAY_A);

            $totalCustomers = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers");

            return [
                'total_customers' => (int)$totalCustomers,
                'customers_with_phone' => (int)($phoneStats['customers_with_phone'] ?? 0),
                'customers_with_address' => (int)($addressStats['customers_with_address'] ?? 0),
                'total_phones' => (int)($phoneStats['total_phones'] ?? 0),
                'total_addresses' => (int)($addressStats['total_addresses'] ?? 0),
                'customers_without_phone' => (int)$totalCustomers - (int)($phoneStats['customers_with_phone'] ?? 0),
                'customers_without_address' => (int)$totalCustomers - (int)($addressStats['customers_with_address'] ?? 0)
            ];

        } catch (\Exception $e) {
            $this->debugService->log('ContactDataService', '取得統計資料失敗', [
                'error' => $e->getMessage()
            ], 'error');

            return [
                'total_customers' => 0,
                'customers_with_phone' => 0,
                'customers_with_address' => 0,
                'total_phones' => 0,
                'total_addresses' => 0,
                'customers_without_phone' => 0,
                'customers_without_address' => 0
            ];
        }
    }
}