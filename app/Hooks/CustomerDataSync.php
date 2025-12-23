<?php

namespace BuyGo\Core\Hooks;

use BuyGo\Core\Services\CustomerService;
use BuyGo\Core\Services\DebugService;

/**
 * Customer Data Sync Hooks - 客戶資料同步鉤子
 * 
 * 監聽 FluentCart 訂單事件，自動同步客戶電話等資料
 * 
 * @package BuyGo\Core\Hooks
 * @version 1.0.0
 */
class CustomerDataSync
{
    private $customerService;
    private $debugService;

    public function __construct()
    {
        $this->customerService = new CustomerService();
        $this->debugService = new DebugService();
        
        $this->initHooks();
    }

    /**
     * 初始化 WordPress 鉤子
     */
    private function initHooks(): void
    {
        // FluentCart 訂單建立後的鉤子
        add_action('fluentcart/order_created', [$this, 'syncCustomerDataOnOrderCreated'], 10, 2);
        
        // FluentCart 客戶建立後的鉤子
        add_action('fluentcart/customer_created', [$this, 'syncCustomerDataOnCustomerCreated'], 10, 1);
        
        // WordPress 用戶註冊後的鉤子
        add_action('user_register', [$this, 'syncCustomerDataOnUserRegister'], 10, 1);
        
        // FluentCRM 聯絡人更新後的鉤子
        add_action('fluentcrm/contact_updated', [$this, 'syncPhoneFromFluentCrm'], 10, 1);
    }

    /**
     * 訂單建立時同步客戶資料
     * 
     * @param object $order FluentCart 訂單物件
     * @param array $orderData 訂單資料
     */
    public function syncCustomerDataOnOrderCreated($order, $orderData = []): void
    {
        $this->debugService->log('CustomerDataSync', '訂單建立事件觸發', [
            'order_id' => $order->id ?? 'unknown',
            'customer_id' => $order->customer_id ?? 'unknown'
        ]);

        try {
            // 從訂單地址中提取電話
            $phone = $this->extractPhoneFromOrderData($order, $orderData);
            
            if ($phone && !empty($order->customer_id)) {
                // 取得客戶 email
                global $wpdb;
                $customer = $wpdb->get_row($wpdb->prepare("
                    SELECT * FROM {$wpdb->prefix}fct_customers WHERE id = %d
                ", $order->customer_id), ARRAY_A);
                
                if ($customer && !empty($customer['email'])) {
                    $this->customerService->syncCustomerPhone($customer['email'], $phone);
                }
            }
            
        } catch (\Exception $e) {
            $this->debugService->log('CustomerDataSync', '訂單建立同步失敗', [
                'error' => $e->getMessage(),
                'order_id' => $order->id ?? 'unknown'
            ], 'error');
        }
    }

    /**
     * 客戶建立時同步資料
     * 
     * @param object $customer FluentCart 客戶物件
     */
    public function syncCustomerDataOnCustomerCreated($customer): void
    {
        $this->debugService->log('CustomerDataSync', '客戶建立事件觸發', [
            'customer_id' => $customer->id ?? 'unknown',
            'email' => $customer->email ?? 'unknown'
        ]);

        try {
            // 如果客戶有 email，嘗試從 FluentCRM 取得電話
            if (!empty($customer->email)) {
                $phoneFromCrm = $this->getPhoneFromFluentCrm($customer->email);
                
                if ($phoneFromCrm && empty($customer->phone)) {
                    $customer->phone = $phoneFromCrm;
                    $customer->save();
                    
                    $this->debugService->log('CustomerDataSync', '從 FluentCRM 同步電話成功', [
                        'customer_id' => $customer->id,
                        'phone' => $phoneFromCrm
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            $this->debugService->log('CustomerDataSync', '客戶建立同步失敗', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id ?? 'unknown'
            ], 'error');
        }
    }

    /**
     * WordPress 用戶註冊時同步資料
     * 
     * @param int $userId WordPress 用戶 ID
     */
    public function syncCustomerDataOnUserRegister(int $userId): void
    {
        try {
            $user = get_userdata($userId);
            
            if ($user && !empty($user->user_email)) {
                // 嘗試從 FluentCRM 取得電話
                $phoneFromCrm = $this->getPhoneFromFluentCrm($user->user_email);
                
                if ($phoneFromCrm) {
                    update_user_meta($userId, '_mygo_phone', $phoneFromCrm);
                    update_user_meta($userId, 'billing_phone', $phoneFromCrm);
                    
                    $this->debugService->log('CustomerDataSync', 'WordPress 用戶電話同步成功', [
                        'user_id' => $userId,
                        'phone' => $phoneFromCrm
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            $this->debugService->log('CustomerDataSync', 'WordPress 用戶同步失敗', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ], 'error');
        }
    }

    /**
     * FluentCRM 聯絡人更新時同步電話到 FluentCart
     * 
     * @param object $contact FluentCRM 聯絡人物件
     */
    public function syncPhoneFromFluentCrm($contact): void
    {
        try {
            if (!empty($contact->email) && !empty($contact->phone)) {
                // 更新 FluentCart 客戶資料
                $customer = \FluentCart\App\Models\Customer::where('email', $contact->email)->first();
                
                if ($customer && empty($customer->phone)) {
                    $customer->phone = $contact->phone;
                    $customer->save();
                    
                    $this->debugService->log('CustomerDataSync', 'FluentCRM 電話同步到 FluentCart 成功', [
                        'email' => $contact->email,
                        'phone' => $contact->phone
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            $this->debugService->log('CustomerDataSync', 'FluentCRM 同步失敗', [
                'error' => $e->getMessage()
            ], 'error');
        }
    }

    /**
     * 從訂單資料中提取電話號碼
     * 
     * @param object $order 訂單物件
     * @param array $orderData 訂單資料
     * @return string|null
     */
    private function extractPhoneFromOrderData($order, array $orderData): ?string
    {
        // 1. 從訂單的 billing_address 提取
        if (!empty($order->billing_address)) {
            $billingData = json_decode($order->billing_address, true);
            if ($billingData && !empty($billingData['phone'])) {
                return $billingData['phone'];
            }
        }

        // 2. 從訂單的 shipping_address 提取
        if (!empty($order->shipping_address)) {
            $shippingData = json_decode($order->shipping_address, true);
            if ($shippingData && !empty($shippingData['phone'])) {
                return $shippingData['phone'];
            }
        }

        // 3. 從 orderData 參數提取
        if (!empty($orderData['customer_phone'])) {
            return $orderData['customer_phone'];
        }

        // 4. 從 orderData 的地址資料提取
        if (!empty($orderData['billing_address']['phone'])) {
            return $orderData['billing_address']['phone'];
        }

        if (!empty($orderData['shipping_address']['phone'])) {
            return $orderData['shipping_address']['phone'];
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
}