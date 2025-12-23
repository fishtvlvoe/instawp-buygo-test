<?php

namespace BuyGo\Core\Services;

use FluentCart\App\Models\Customer;
use BuyGo\Core\Services\DebugService;

/**
 * Customer Service - 客戶資料管理服務
 * 
 * 整合 FluentCart 客戶資料並提供完整的客戶資訊管理
 * 解決客戶資料顯示問題，特別是電話號碼等聯絡資訊
 * 
 * @package BuyGo\Core\Services
 * @version 1.0.0
 */
class CustomerService
{
    private $debugService;

    public function __construct()
    {
        $this->debugService = new DebugService();
    }

    /**
     * 取得完整的客戶資料
     * 
     * @param int $customerId 客戶 ID
     * @param bool $includeStatistics 是否包含統計資料（後台使用）
     * @return array|null
     */
    public function getCustomerData(int $customerId, bool $includeStatistics = false): ?array
    {
        $this->debugService->log('CustomerService', '開始取得客戶資料', [
            'customer_id' => $customerId,
            'include_statistics' => $includeStatistics
        ]);

        try {
            // 1. 優先從 FluentCart 客戶表取得資料
            $customer = Customer::find($customerId);
            
            if (!$customer) {
                $this->debugService->log('CustomerService', 'FluentCart 客戶不存在', [
                    'customer_id' => $customerId
                ], 'warning');
                return null;
            }

            // 2. 基本客戶資料
            $customerData = [
                'id' => $customer->id,
                'name' => $this->getCustomerName($customer),
                'email' => $customer->email ?? '',
                'phone' => $this->getCustomerPhone($customer),
                'address' => $this->getCustomerAddress($customer),
                'created_at' => $customer->created_at,
                'updated_at' => $customer->updated_at
            ];

            // 3. 資料完整性檢查
            $missingFields = $this->checkDataCompleteness($customerData);
            if (!empty($missingFields)) {
                $customerData['missing_fields'] = $missingFields;
                $customerData['data_complete'] = false;
                
                $this->debugService->log('CustomerService', '客戶資料不完整', [
                    'customer_id' => $customerId,
                    'missing_fields' => $missingFields
                ], 'warning');
            } else {
                $customerData['data_complete'] = true;
            }

            // 4. 後台統計資料
            if ($includeStatistics) {
                $statistics = $this->getCustomerStatistics($customerId);
                $customerData = array_merge($customerData, $statistics);
            }

            // 5. 嘗試從其他來源補充缺失資料
            if (!$customerData['data_complete']) {
                $customerData = $this->supplementMissingData($customerData, $customer);
            }

            $this->debugService->log('CustomerService', '成功取得客戶資料', [
                'customer_id' => $customerId,
                'data_complete' => $customerData['data_complete']
            ]);

            return $customerData;

        } catch (\Exception $e) {
            $this->debugService->log('CustomerService', '取得客戶資料失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ], 'error');

            return null;
        }
    }

    /**
     * 取得客戶姓名（多來源整合）
     * 
     * @param object $customer FluentCart 客戶物件
     * @return string
     */
    private function getCustomerName($customer): string
    {
        // 1. 優先使用 FluentCart 的姓名欄位
        $firstName = $customer->first_name ?? '';
        $lastName = $customer->last_name ?? '';
        
        if ($firstName || $lastName) {
            return trim($firstName . ' ' . $lastName);
        }

        // 2. 備援：使用 WordPress 用戶資料
        if ($customer->contact_id) {
            $user = get_userdata($customer->contact_id);
            if ($user) {
                // 嘗試從用戶 meta 取得完整姓名
                $displayName = $user->display_name;
                if ($displayName && $displayName !== $user->user_login) {
                    return $displayName;
                }
                
                // 嘗試從 meta 取得姓名
                $firstName = get_user_meta($user->ID, 'first_name', true);
                $lastName = get_user_meta($user->ID, 'last_name', true);
                if ($firstName || $lastName) {
                    return trim($firstName . ' ' . $lastName);
                }
                
                return $user->user_login;
            }
        }

        // 3. 最後備援：從訂單快照資料取得
        $nameFromOrder = $this->getNameFromOrderSnapshot($customer->id);
        if ($nameFromOrder) {
            return $nameFromOrder;
        }

        return 'Guest';
    }

    /**
     * 取得客戶電話（多來源整合）
     * 
     * @param object $customer FluentCart 客戶物件
     * @return string
     */
    private function getCustomerPhone($customer): string
    {
        // 1. 優先從 FluentCart 客戶表取得
        if (isset($customer->phone) && !empty($customer->phone)) {
            return $this->formatPhoneNumber($customer->phone);
        }

        // 2. 從最近的訂單地址中取得電話（新增：正確的路徑）
        if ($customer->id) {
            $phoneFromOrders = $this->getPhoneFromOrderAddresses($customer->id);
            if ($phoneFromOrders) {
                return $this->formatPhoneNumber($phoneFromOrders);
            }
        }

        // 3. 從 FluentCRM 聯絡人表取得
        if (!empty($customer->email)) {
            $phoneFromCrm = $this->getPhoneFromFluentCrm($customer->email);
            if ($phoneFromCrm) {
                return $this->formatPhoneNumber($phoneFromCrm);
            }
        }

        // 4. 從客戶 meta 資料取得
        if ($customer->id) {
            global $wpdb;
            $phone = $wpdb->get_var($wpdb->prepare("
                SELECT meta_value 
                FROM {$wpdb->prefix}fct_customer_meta 
                WHERE customer_id = %d AND meta_key = 'phone'
            ", $customer->id));
            
            if ($phone) {
                return $this->formatPhoneNumber($phone);
            }
        }

        // 5. 從 WordPress 用戶 meta 取得
        if ($customer->contact_id) {
            $phone = get_user_meta($customer->contact_id, 'phone', true);
            if (!$phone) {
                $phone = get_user_meta($customer->contact_id, 'billing_phone', true);
            }
            if (!$phone) {
                $phone = get_user_meta($customer->contact_id, 'shipping_phone', true);
            }
            if (!$phone) {
                $phone = get_user_meta($customer->contact_id, '_mygo_phone', true);
            }
            
            if ($phone) {
                return $this->formatPhoneNumber($phone);
            }
        }

        // 5. 從訂單地址資料取得
        $phoneFromOrder = $this->getPhoneFromOrderAddress($customer->id);
        if ($phoneFromOrder) {
            return $this->formatPhoneNumber($phoneFromOrder);
        }

        return '';
    }

    /**
     * 取得客戶地址（多來源整合）
     * 
     * @param object $customer FluentCart 客戶物件
     * @return string
     */
    private function getCustomerAddress($customer): string
    {
        // 1. 優先從客戶表的基本欄位組合地址
        $addressParts = [];
        
        if (!empty($customer->city)) {
            $addressParts[] = $customer->city;
        }
        
        if (!empty($customer->state)) {
            $addressParts[] = $customer->state;
        }
        
        if (!empty($customer->country)) {
            // 轉換國家代碼為中文
            $countryName = $this->getCountryName($customer->country);
            $addressParts[] = $countryName;
        }
        
        if (!empty($customer->postcode)) {
            $addressParts[] = $customer->postcode;
        }
        
        if (!empty($addressParts)) {
            return implode(' ', $addressParts);
        }

        // 2. 從最近的訂單取得地址
        $addressFromOrder = $this->getAddressFromRecentOrder($customer->id);
        if ($addressFromOrder) {
            return $addressFromOrder;
        }

        // 3. 從 WordPress 用戶 meta 取得
        if ($customer->contact_id) {
            $address = $this->getAddressFromUserMeta($customer->contact_id);
            if ($address) {
                return $address;
            }
        }

        // 4. 從客戶 meta 資料取得
        if ($customer->id) {
            $address = $this->getAddressFromCustomerMeta($customer->id);
            if ($address) {
                return $address;
            }
        }

        return '';
    }

    /**
     * 檢查資料完整性
     * 
     * @param array $customerData 客戶資料
     * @return array 缺失的欄位
     */
    private function checkDataCompleteness(array $customerData): array
    {
        $requiredFields = ['name', 'phone', 'address'];
        $missingFields = [];

        foreach ($requiredFields as $field) {
            if (empty($customerData[$field]) || $customerData[$field] === 'Guest') {
                $missingFields[] = $field;
            }
        }

        return $missingFields;
    }

    /**
     * 取得客戶統計資料（後台使用）
     * 
     * @param int $customerId 客戶 ID
     * @return array
     */
    private function getCustomerStatistics(int $customerId): array
    {
        try {
            global $wpdb;

            // 訂單統計
            $orderStats = $wpdb->get_row($wpdb->prepare("
                SELECT 
                    COUNT(*) as order_count,
                    SUM(total_amount) as total_spent,
                    MAX(created_at) as last_order_date,
                    MIN(created_at) as first_order_date
                FROM {$wpdb->prefix}fct_orders 
                WHERE customer_id = %d
            ", $customerId), ARRAY_A);

            return [
                'order_count' => (int)($orderStats['order_count'] ?? 0),
                'total_spent' => (float)($orderStats['total_spent'] ?? 0) / 100, // 轉換為元
                'formatted_total_spent' => $this->formatPrice((int)($orderStats['total_spent'] ?? 0)),
                'last_order_date' => $orderStats['last_order_date'] ?? null,
                'first_order_date' => $orderStats['first_order_date'] ?? null,
                'customer_since' => $orderStats['first_order_date'] ? 
                    human_time_diff(strtotime($orderStats['first_order_date'])) . ' ago' : 'N/A'
            ];

        } catch (\Exception $e) {
            $this->debugService->log('CustomerService', '取得客戶統計失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ], 'error');

            return [
                'order_count' => 0,
                'total_spent' => 0,
                'formatted_total_spent' => 'NT$ 0.00',
                'last_order_date' => null,
                'first_order_date' => null,
                'customer_since' => 'N/A'
            ];
        }
    }

    /**
     * 補充缺失資料
     * 
     * @param array $customerData 現有客戶資料
     * @param object $customer FluentCart 客戶物件
     * @return array
     */
    private function supplementMissingData(array $customerData, $customer): array
    {
        $missingFields = $customerData['missing_fields'] ?? [];

        foreach ($missingFields as $field) {
            switch ($field) {
                case 'phone':
                    // 嘗試更多來源取得電話
                    $phone = $this->getPhoneFromAlternateSources($customer);
                    if ($phone) {
                        $customerData['phone'] = $phone;
                    }
                    break;

                case 'address':
                    // 嘗試更多來源取得地址
                    $address = $this->getAddressFromAlternateSources($customer);
                    if ($address) {
                        $customerData['address'] = $address;
                    }
                    break;

                case 'name':
                    // 嘗試從 email 推測姓名
                    if ($customerData['email']) {
                        $nameFromEmail = $this->guessNameFromEmail($customerData['email']);
                        if ($nameFromEmail) {
                            $customerData['name'] = $nameFromEmail;
                        }
                    }
                    break;
            }
        }

        // 重新檢查完整性
        $customerData['missing_fields'] = $this->checkDataCompleteness($customerData);
        $customerData['data_complete'] = empty($customerData['missing_fields']);

        return $customerData;
    }

    /**
     * 從訂單快照取得姓名
     */
    private function getNameFromOrderSnapshot(int $customerId): ?string
    {
        try {
            global $wpdb;
            
            $name = $wpdb->get_var($wpdb->prepare("
                SELECT JSON_UNQUOTE(JSON_EXTRACT(billing_address, '$.first_name')) as first_name,
                       JSON_UNQUOTE(JSON_EXTRACT(billing_address, '$.last_name')) as last_name
                FROM {$wpdb->prefix}fct_orders 
                WHERE customer_id = %d 
                AND billing_address IS NOT NULL 
                ORDER BY id DESC 
                LIMIT 1
            ", $customerId));

            return $name;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 從訂單地址取得電話
     */
    private function getPhoneFromOrderAddress(int $customerId): ?string
    {
        try {
            global $wpdb;
            
            $phone = $wpdb->get_var($wpdb->prepare("
                SELECT JSON_UNQUOTE(JSON_EXTRACT(billing_address, '$.phone')) as phone
                FROM {$wpdb->prefix}fct_orders 
                WHERE customer_id = %d 
                AND billing_address IS NOT NULL 
                AND JSON_UNQUOTE(JSON_EXTRACT(billing_address, '$.phone')) IS NOT NULL
                ORDER BY id DESC 
                LIMIT 1
            ", $customerId));

            return $phone;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 從最近訂單取得地址
     */
    private function getAddressFromRecentOrder(int $customerId): ?string
    {
        try {
            global $wpdb;
            
            $address = $wpdb->get_row($wpdb->prepare("
                SELECT 
                    JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.address_line_1')) as line1,
                    JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.address_line_2')) as line2,
                    JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.city')) as city,
                    JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.state')) as state,
                    JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.zip')) as zip
                FROM {$wpdb->prefix}fct_orders 
                WHERE customer_id = %d 
                AND shipping_address IS NOT NULL 
                ORDER BY id DESC 
                LIMIT 1
            ", $customerId), ARRAY_A);

            if ($address) {
                $parts = array_filter([
                    $address['line1'],
                    $address['line2'],
                    $address['city'],
                    $address['state'],
                    $address['zip']
                ]);
                return implode(' ', $parts);
            }

            return null;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 從用戶 meta 取得地址
     */
    private function getAddressFromUserMeta(int $userId): ?string
    {
        $address1 = get_user_meta($userId, 'billing_address_1', true);
        $address2 = get_user_meta($userId, 'billing_address_2', true);
        $city = get_user_meta($userId, 'billing_city', true);
        $state = get_user_meta($userId, 'billing_state', true);
        $postcode = get_user_meta($userId, 'billing_postcode', true);

        $parts = array_filter([$address1, $address2, $city, $state, $postcode]);
        return !empty($parts) ? implode(' ', $parts) : null;
    }

    /**
     * 從客戶 meta 取得地址
     */
    private function getAddressFromCustomerMeta(int $customerId): ?string
    {
        try {
            global $wpdb;
            
            $address = $wpdb->get_var($wpdb->prepare("
                SELECT meta_value 
                FROM {$wpdb->prefix}fct_customer_meta 
                WHERE customer_id = %d AND meta_key = 'address'
            ", $customerId));

            return $address;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 從其他來源取得電話
     */
    private function getPhoneFromAlternateSources($customer): ?string
    {
        // 可以添加更多電話來源，如第三方整合等
        return null;
    }

    /**
     * 從其他來源取得地址
     */
    private function getAddressFromAlternateSources($customer): ?string
    {
        // 可以添加更多地址來源，如第三方整合等
        return null;
    }

    /**
     * 從 email 推測姓名
     */
    private function guessNameFromEmail(string $email): ?string
    {
        $parts = explode('@', $email);
        if (count($parts) === 2) {
            $username = $parts[0];
            // 移除數字和特殊字符，嘗試提取姓名
            $name = preg_replace('/[^a-zA-Z\s]/', ' ', $username);
            $name = trim(preg_replace('/\s+/', ' ', $name));
            
            if (strlen($name) > 2) {
                return ucwords($name);
            }
        }
        
        return null;
    }

    /**
     * 從 FluentCRM 取得電話號碼
     * 
     * @param string $email 客戶 email
     * @return string|null
     */
    private function getPhoneFromFluentCrm(string $email): ?string
    {
        try {
            global $wpdb;
            
            // 檢查 FluentCRM 聯絡人表是否存在
            $contactTable = $wpdb->prefix . 'fc_contacts';
            $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$contactTable}'");
            
            if (!$tableExists) {
                return null;
            }
            
            // 從 FluentCRM 聯絡人表取得電話
            $contact = $wpdb->get_row($wpdb->prepare("
                SELECT id, phone FROM {$contactTable}
                WHERE email = %s
                LIMIT 1
            ", $email), ARRAY_A);
            
            if ($contact && !empty($contact['phone'])) {
                $this->debugService->log('CustomerService', '從 FluentCRM 找到電話', [
                    'email' => $email,
                    'phone' => $contact['phone']
                ]);
                
                return $contact['phone'];
            }
            
            // 如果聯絡人表沒有電話，檢查 meta 表
            if ($contact) {
                $metaTable = $wpdb->prefix . 'fc_contact_meta';
                $metaExists = $wpdb->get_var("SHOW TABLES LIKE '{$metaTable}'");
                
                if ($metaExists) {
                    $phoneMeta = $wpdb->get_var($wpdb->prepare("
                        SELECT meta_value FROM {$metaTable}
                        WHERE contact_id = %d 
                        AND (meta_key = 'phone' OR meta_key = 'mobile' OR meta_key = 'telephone')
                        AND meta_value IS NOT NULL
                        AND meta_value != ''
                        LIMIT 1
                    ", $contact['id']));
                    
                    if ($phoneMeta) {
                        $this->debugService->log('CustomerService', '從 FluentCRM Meta 找到電話', [
                            'email' => $email,
                            'phone' => $phoneMeta
                        ]);
                        
                        return $phoneMeta;
                    }
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            $this->debugService->log('CustomerService', 'FluentCRM 電話取得失敗', [
                'error' => $e->getMessage(),
                'email' => $email
            ], 'error');
            
            return null;
        }
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
     * 格式化電話號碼
     */
    private function formatPhoneNumber(string $phone): string
    {
        // 移除非數字字符
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // 台灣手機號碼格式化
        if (strlen($phone) === 10 && substr($phone, 0, 2) === '09') {
            return substr($phone, 0, 4) . '-' . substr($phone, 4, 3) . '-' . substr($phone, 7);
        }
        
        // 其他格式保持原樣
        return $phone;
    }

    /**
     * 格式化價格
     */
    private function formatPrice(int $priceInCents): string
    {
        return 'NT$ ' . number_format($priceInCents / 100, 2);
    }

    /**
     * 驗證客戶資料
     * 
     * @param array $customerData 客戶資料
     * @return array 驗證結果
     */
    public function validateCustomerData(array $customerData): array
    {
        $errors = [];

        // 驗證姓名
        if (empty($customerData['name']) || $customerData['name'] === 'Guest') {
            $errors['name'] = '客戶姓名不能為空';
        }

        // 驗證電話
        if (empty($customerData['phone'])) {
            $errors['phone'] = '客戶電話不能為空';
        } elseif (!preg_match('/^[0-9\-\+\(\)\s]+$/', $customerData['phone'])) {
            $errors['phone'] = '電話號碼格式不正確';
        }

        // 驗證地址
        if (empty($customerData['address'])) {
            $errors['address'] = '客戶地址不能為空';
        }

        // 驗證 email
        if (!empty($customerData['email']) && !filter_var($customerData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email 格式不正確';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * 同步客戶電話資料到 FluentCart
     * 
     * @param string $email 客戶 email
     * @param string $phone 電話號碼
     * @return bool
     */
    public function syncCustomerPhone(string $email, string $phone): bool
    {
        $this->debugService->log('CustomerService', '開始同步客戶電話資料', [
            'email' => $email,
            'phone' => $phone
        ]);

        try {
            // 1. 更新 FluentCart 客戶表
            global $wpdb;
            $customerTable = $wpdb->prefix . 'fct_customers';
            
            // 檢查客戶是否存在
            $customer = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$customerTable} WHERE email = %s
            ", $email), ARRAY_A);
            
            if ($customer) {
                // 如果客戶表沒有電話，更新它
                if (empty($customer['phone'])) {
                    $wpdb->update($customerTable, 
                        ['phone' => $phone],
                        ['email' => $email]
                    );
                    
                    $this->debugService->log('CustomerService', 'FluentCart 客戶電話更新成功', [
                        'customer_id' => $customer['id'],
                        'phone' => $phone
                    ]);
                }
                
                // 2. 同步到 FluentCRM
                $this->syncPhoneToFluentCrm($email, $phone);
                
                // 3. 同步到 WordPress 用戶 meta
                if ($customer['user_id']) {
                    $existingPhone = get_user_meta($customer['user_id'], '_mygo_phone', true);
                    if (empty($existingPhone)) {
                        update_user_meta($customer['user_id'], '_mygo_phone', $phone);
                        update_user_meta($customer['user_id'], 'billing_phone', $phone);
                        
                        $this->debugService->log('CustomerService', 'WordPress 用戶電話更新成功', [
                            'user_id' => $customer['user_id'],
                            'phone' => $phone
                        ]);
                    }
                }
                
                return true;
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->debugService->log('CustomerService', '同步客戶電話失敗', [
                'error' => $e->getMessage(),
                'email' => $email,
                'phone' => $phone
            ], 'error');
            
            return false;
        }
    }

    /**
     * 同步電話到 FluentCRM
     * 
     * @param string $email 客戶 email
     * @param string $phone 電話號碼
     * @return bool
     */
    private function syncPhoneToFluentCrm(string $email, string $phone): bool
    {
        try {
            global $wpdb;
            
            $contactTable = $wpdb->prefix . 'fc_contacts';
            $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$contactTable}'");
            
            if (!$tableExists) {
                return false;
            }
            
            // 檢查 FluentCRM 中是否有該聯絡人
            $contact = $wpdb->get_row($wpdb->prepare("
                SELECT id, phone FROM {$contactTable}
                WHERE email = %s
            ", $email), ARRAY_A);
            
            if ($contact) {
                // 如果聯絡人存在但沒有電話，更新它
                if (empty($contact['phone'])) {
                    $wpdb->update($contactTable, 
                        ['phone' => $phone],
                        ['id' => $contact['id']]
                    );
                    
                    $this->debugService->log('CustomerService', 'FluentCRM 聯絡人電話更新成功', [
                        'contact_id' => $contact['id'],
                        'phone' => $phone
                    ]);
                }
            } else {
                // 如果聯絡人不存在，建立新的聯絡人
                $wpdb->insert($contactTable, [
                    'email' => $email,
                    'phone' => $phone,
                    'status' => 'subscribed',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ]);
                
                $this->debugService->log('CustomerService', 'FluentCRM 新聯絡人建立成功', [
                    'email' => $email,
                    'phone' => $phone
                ]);
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->debugService->log('CustomerService', 'FluentCRM 電話同步失敗', [
                'error' => $e->getMessage(),
                'email' => $email
            ], 'error');
            
            return false;
        }
    }

    /**
     * 更新客戶資料
     * 
     * @param int $customerId 客戶 ID
     * @param array $data 要更新的資料
     * @return bool
     */
    public function updateCustomerData(int $customerId, array $data): bool
    {
        $this->debugService->log('CustomerService', '開始更新客戶資料', [
            'customer_id' => $customerId,
            'data' => $data
        ]);

        try {
            // 驗證資料
            $validation = $this->validateCustomerData($data);
            if (!$validation['valid']) {
                throw new \Exception('資料驗證失敗：' . implode(', ', $validation['errors']));
            }

            $customer = Customer::find($customerId);
            if (!$customer) {
                throw new \Exception("客戶不存在：ID {$customerId}");
            }

            // 更新基本資料
            if (isset($data['email'])) {
                $customer->email = $data['email'];
            }

            if (isset($data['name'])) {
                $nameParts = explode(' ', $data['name'], 2);
                $customer->first_name = $nameParts[0];
                $customer->last_name = $nameParts[1] ?? '';
            }

            $customer->save();

            // 更新 meta 資料
            if (isset($data['phone'])) {
                $this->updateCustomerMeta($customerId, 'phone', $data['phone']);
            }

            if (isset($data['address'])) {
                $this->updateCustomerMeta($customerId, 'address', $data['address']);
            }

            $this->debugService->log('CustomerService', '客戶資料更新成功', [
                'customer_id' => $customerId
            ]);

            return true;

        } catch (\Exception $e) {
            $this->debugService->log('CustomerService', '客戶資料更新失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId
            ], 'error');

            throw new \Exception('客戶資料更新失敗：' . $e->getMessage());
        }
    }

    /**
     * 更新客戶 meta 資料
     */
    private function updateCustomerMeta(int $customerId, string $key, string $value): void
    {
        try {
            global $wpdb;
            
            $table = $wpdb->prefix . 'fct_customer_meta';
            
            // 檢查是否已存在
            $exists = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$table} 
                WHERE customer_id = %d AND meta_key = %s
            ", $customerId, $key));

            if ($exists) {
                $wpdb->update($table, 
                    ['meta_value' => $value],
                    ['customer_id' => $customerId, 'meta_key' => $key]
                );
            } else {
                $wpdb->insert($table, [
                    'customer_id' => $customerId,
                    'meta_key' => $key,
                    'meta_value' => $value
                ]);
            }

        } catch (\Exception $e) {
            $this->debugService->log('CustomerService', '更新客戶 meta 失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customerId,
                'key' => $key
            ], 'error');
        }
    }

    /**
     * 從訂單地址中取得電話號碼（正確解析 FluentCart 結構）
     * 
     * @param int $customerId 客戶 ID
     * @return string|null
     */
    private function getPhoneFromOrderAddresses(int $customerId): ?string
    {
        try {
            global $wpdb;
            
            // 查詢該客戶最近的訂單
            $orders = $wpdb->get_results($wpdb->prepare("
                SELECT billing_address, shipping_address 
                FROM {$wpdb->prefix}fct_orders 
                WHERE customer_id = %d 
                ORDER BY created_at DESC 
                LIMIT 5
            ", $customerId), ARRAY_A);

            foreach ($orders as $order) {
                // 檢查帳單地址
                if (!empty($order['billing_address'])) {
                    $phone = $this->extractPhoneFromAddressJson($order['billing_address']);
                    if ($phone) {
                        $this->debugService->log('CustomerService', '從帳單地址找到電話', [
                            'customer_id' => $customerId,
                            'phone' => $phone
                        ]);
                        return $phone;
                    }
                }
                
                // 檢查運送地址
                if (!empty($order['shipping_address'])) {
                    $phone = $this->extractPhoneFromAddressJson($order['shipping_address']);
                    if ($phone) {
                        $this->debugService->log('CustomerService', '從運送地址找到電話', [
                            'customer_id' => $customerId,
                            'phone' => $phone
                        ]);
                        return $phone;
                    }
                }
            }

            return null;

        } catch (\Exception $e) {
            $this->debugService->log('CustomerService', '從訂單地址取得電話失敗', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ], 'error');

            return null;
        }
    }

    /**
     * 從地址 JSON 中提取電話號碼
     * 
     * @param string $addressJson 地址 JSON 字串
     * @return string|null
     */
    private function extractPhoneFromAddressJson(string $addressJson): ?string
    {
        try {
            $addressData = json_decode($addressJson, true);
            
            if (!$addressData) {
                return null;
            }

            // 方法 1: 直接從第一層取得 phone
            if (!empty($addressData['phone'])) {
                return $addressData['phone'];
            }

            // 方法 2: 從 meta.other_data.phone 取得（FluentCart 的實際結構）
            if (isset($addressData['meta']['other_data']['phone']) && !empty($addressData['meta']['other_data']['phone'])) {
                return $addressData['meta']['other_data']['phone'];
            }

            // 方法 3: 從 other_data.phone 取得（可能的變體）
            if (isset($addressData['other_data']['phone']) && !empty($addressData['other_data']['phone'])) {
                return $addressData['other_data']['phone'];
            }

            // 方法 4: 遞迴搜尋所有可能的 phone 欄位
            return $this->recursiveSearchPhone($addressData);

        } catch (\Exception $e) {
            $this->debugService->log('CustomerService', '解析地址 JSON 失敗', [
                'error' => $e->getMessage(),
                'json' => substr($addressJson, 0, 200)
            ], 'error');

            return null;
        }
    }

    /**
     * 遞迴搜尋陣列中的電話號碼
     * 
     * @param array $data 要搜尋的陣列
     * @return string|null
     */
    private function recursiveSearchPhone(array $data): ?string
    {
        foreach ($data as $key => $value) {
            // 如果 key 包含 phone 且值不為空
            if (stripos($key, 'phone') !== false && !empty($value) && is_string($value)) {
                // 檢查是否看起來像電話號碼
                if (preg_match('/[\d\+\-\(\)\s]{8,}/', $value)) {
                    return $value;
                }
            }
            
            // 如果值是陣列，遞迴搜尋
            if (is_array($value)) {
                $phone = $this->recursiveSearchPhone($value);
                if ($phone) {
                    return $phone;
                }
            }
        }

        return null;
    }
}