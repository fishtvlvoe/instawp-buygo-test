<?php
/**
 * PhoneExtractor Service
 * 
 * 統一的電話號碼提取邏輯，處理 FluentCart 複雜的巢狀 JSON 結構
 * 
 * 基於文件：FluentCart 電話多層級資料提取策略.md
 * 
 * @package BuyGo
 */

namespace BuyGo\App\Services;

if (!defined('ABSPATH')) {
    exit;
}

class PhoneExtractor
{
    /**
     * 從地址物件提取電話
     * 
     * @param object|null $address 地址物件（來自 fct_order_addresses 表）
     * @return string 電話號碼，如果找不到則回傳空字串
     */
    public static function extractPhoneFromAddress($address): string
    {
        if (!$address) {
            return '';
        }

        // 層級 1：直接從 phone 欄位取得
        if (!empty($address->phone)) {
            return $address->phone;
        }

        // 層級 2-4：從 meta JSON 提取
        if (!empty($address->meta)) {
            return self::extractPhoneFromAddressJson($address->meta);
        }

        return '';
    }

    /**
     * 從地址 JSON 字串提取電話
     * 
     * FluentCart 實際儲存路徑：
     * - billing_address.meta.other_data.phone
     * - shipping_address.meta.other_data.phone
     * 
     * @param string $addressJson 地址 JSON 字串
     * @return string 電話號碼，如果找不到則回傳空字串
     */
    public static function extractPhoneFromAddressJson(string $addressJson): string
    {
        try {
            $addressData = json_decode($addressJson, true);

            if (!$addressData || !is_array($addressData)) {
                return '';
            }

            // 層級 1：直接從第一層取得
            if (!empty($addressData['phone'])) {
                return self::validatePhone($addressData['phone']);
            }

            // 層級 2：從 meta.other_data.phone 取得（FluentCart 實際結構）
            if (isset($addressData['meta']['other_data']['phone']) && !empty($addressData['meta']['other_data']['phone'])) {
                return self::validatePhone($addressData['meta']['other_data']['phone']);
            }

            // 層級 3：從 other_data.phone 取得（可能的變體）
            if (isset($addressData['other_data']['phone']) && !empty($addressData['other_data']['phone'])) {
                return self::validatePhone($addressData['other_data']['phone']);
            }

            // 層級 4：遞迴搜尋所有可能的 phone 欄位
            return self::recursiveSearchPhone($addressData);

        } catch (\Exception $e) {
            error_log('[PhoneExtractor] Error extracting phone from JSON: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 遞迴搜尋陣列中所有可能的 phone 欄位
     * 
     * @param array $data 要搜尋的陣列
     * @return string 找到的電話號碼，如果找不到則回傳空字串
     */
    private static function recursiveSearchPhone(array $data): string
    {
        foreach ($data as $key => $value) {
            // 如果 key 包含 phone 且值不為空
            if (stripos($key, 'phone') !== false && !empty($value) && is_string($value)) {
                // 檢查是否看起來像電話號碼
                if (self::isValidPhone($value)) {
                    return $value;
                }
            }

            // 如果值是陣列，遞迴搜尋
            if (is_array($value)) {
                $phone = self::recursiveSearchPhone($value);
                if ($phone) {
                    return $phone;
                }
            }
        }

        return '';
    }

    /**
     * 驗證電話號碼格式
     * 
     * @param string $phone 待驗證的電話號碼
     * @return string 驗證後的電話號碼，如果無效則回傳空字串
     */
    private static function validatePhone(string $phone): string
    {
        // 移除空白
        $phone = trim($phone);

        // 檢查是否為有效電話號碼
        if (self::isValidPhone($phone)) {
            return $phone;
        }

        return '';
    }

    /**
     * 檢查是否為有效的電話號碼
     * 
     * 規則：至少包含 8 個數字字元
     * 
     * @param string $phone 待檢查的電話號碼
     * @return bool
     */
    private static function isValidPhone(string $phone): bool
    {
        // 檢查是否包含至少 8 個數字（台灣手機號碼格式）
        return preg_match('/[\d\+\-\(\)\s]{8,}/', $phone) === 1;
    }

    /**
     * 從多個地址物件中提取電話（優先順序：shipping > billing）
     * 
     * @param object|null $shippingAddress 運送地址物件
     * @param object|null $billingAddress 帳單地址物件
     * @return string 電話號碼，如果找不到則回傳空字串
     */
    public static function extractPhoneFromAddresses($shippingAddress, $billingAddress): string
    {
        // 優先從運送地址提取
        $phone = self::extractPhoneFromAddress($shippingAddress);
        if ($phone) {
            return $phone;
        }

        // 如果運送地址沒有，從帳單地址提取
        $phone = self::extractPhoneFromAddress($billingAddress);
        if ($phone) {
            return $phone;
        }

        return '';
    }
}
