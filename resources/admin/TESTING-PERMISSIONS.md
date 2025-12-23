# Task 51.6 測試指引

> **給老闆的測試說明**：如何驗證前端權限控制功能是否正常運作

---

## 📋 測試前準備

### 1. 確認後端 API 端點存在

需要以下 API：

```
GET /wp-json/buygo/v1/user/current
GET /wp-json/buygo/v1/user/helper-bindings
```

**如果尚未建立**，需要先建立這兩個 API 端點（見下方「API 實作指引」）。

---

## 🧪 測試項目清單

### ✅ Test 1: usePermissions Composable 基本功能

**測試頁面**：`usePermissions.example.vue`

**如何測試**：
1. 開啟測試頁面（需要先整合到 Router）
2. 檢查是否正確顯示當前使用者資訊
3. 檢查是否正確顯示所有角色標籤（管理員、賣家、小幫手、買家）
4. 如果是小幫手，檢查是否顯示綁定的賣家和權限

**預期結果**：
- ✅ 使用者資訊正確載入
- ✅ 角色標籤正確顯示（多重身份都要顯示）
- ✅ 小幫手綁定資料正確顯示

---

### ✅ Test 2: 權限檢查邏輯

**測試場景 A：管理員身份**
1. 用管理員帳號登入
2. 查看權限測試區塊
3. **預期結果**：所有權限都顯示「✓ 允許」

**測試場景 B：賣家身份**
1. 用賣家帳號登入
2. 查看「自己的商品」權限測試
3. **預期結果**：查看、編輯、管理都顯示「✓ 允許」
4. 查看「其他賣家的商品」權限測試
5. **預期結果**：都顯示「✗ 拒絕」

**測試場景 C：小幫手身份**
1. 用小幫手帳號登入
2. 查看「綁定賣家的商品」權限測試
3. **預期結果**：根據綁定的權限顯示（如果有 can_manage_products，管理才顯示允許）
4. 查看「其他賣家的商品」權限測試
5. **預期結果**：都顯示「✗ 拒絕」

**測試場景 D：多重身份（賣家 + 小幫手）**
1. 用同時有賣家和小幫手角色的帳號登入
2. 查看「自己的商品」權限測試
3. **預期結果**：使用賣家身份，完整權限
4. 查看「綁定賣家的商品」權限測試
5. **預期結果**：使用小幫手身份，受限權限

---

### ✅ Test 3: v-permission 指令

**如何測試**：
在任何 Vue 組件中加入測試程式碼：

```vue
<template>
  <!-- Test 1: 全域權限 -->
  <button v-permission="'manage_products'">
    管理商品（只有管理員和賣家能看到）
  </button>

  <!-- Test 2: 資源權限 -->
  <div v-permission="{ action: 'edit', resource: { sellerId: 8 } }">
    編輯區塊（只有賣家 8 或綁定的小幫手能看到）
  </div>

  <!-- Test 3: 隱藏模式 -->
  <span v-permission:hide="'manage_options'">
    管理員專用（無權限者看不到）
  </span>
</template>
```

**預期結果**：
- ✅ 有權限：元素正常顯示
- ✅ 無權限（預設）：元素被移除（DOM 中不存在）
- ✅ 無權限（:hide）：元素隱藏（display: none）

---

### ✅ Test 4: PermissionDenied 組件

**如何測試**：

```vue
<template>
  <PermissionDenied
    variant="warning"
    title="權限不足"
    message="你沒有權限管理此商品"
    reason="你不是此商品的賣家"
    :show-actions="true"
    :show-contact-admin="true"
    @contact-admin="handleContactAdmin"
  />
</template>
```

**預期結果**：
- ✅ 顯示友善的權限不足訊息
- ✅ 顯示原因說明
- ✅ 按鈕可以點擊並觸發事件

---

## 🔧 API 實作指引

如果後端 API 尚未建立，需要建立以下兩個端點：

### 1. GET /wp-json/buygo/v1/user/current

**回應格式**：
```json
{
  "success": true,
  "data": {
    "id": 13,
    "login": "test_user",
    "email": "test@example.com",
    "displayName": "Test User",
    "roles": ["buygo_seller", "buygo_helper"],
    "capabilities": ["manage_buygo_shop", "read"]
  }
}
```

**PHP 實作範例**：
```php
public function get_current_user() {
    $user = wp_get_current_user();
    
    if (!$user || $user->ID === 0) {
        return new \WP_Error('not_logged_in', '未登入');
    }
    
    return [
        'success' => true,
        'data' => [
            'id' => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'displayName' => $user->display_name,
            'roles' => (array) $user->roles,
            'capabilities' => array_keys(array_filter($user->allcaps))
        ]
    ];
}
```

### 2. GET /wp-json/buygo/v1/user/helper-bindings

**回應格式**：
```json
{
  "success": true,
  "data": [
    {
      "seller_id": 8,
      "permissions": {
        "can_view_orders": true,
        "can_update_orders": false,
        "can_manage_products": false,
        "can_reply_customers": false
      }
    }
  ]
}
```

**PHP 實作範例**：
```php
public function get_helper_bindings() {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new \WP_Error('not_logged_in', '未登入');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'buygo_helpers';
    
    $bindings = $wpdb->get_results($wpdb->prepare(
        "SELECT seller_id, can_view_orders, can_update_orders, can_manage_products, can_reply_customers
         FROM $table_name
         WHERE helper_id = %d AND status = 'active'",
        $user_id
    ));
    
    $result = array_map(function($binding) {
        return [
            'seller_id' => (int) $binding->seller_id,
            'permissions' => [
                'can_view_orders' => (bool) $binding->can_view_orders,
                'can_update_orders' => (bool) $binding->can_update_orders,
                'can_manage_products' => (bool) $binding->can_manage_products,
                'can_reply_customers' => (bool) $binding->can_reply_customers
            ]
        ];
    }, $bindings);
    
    return [
        'success' => true,
        'data' => $result
    ];
}
```

---

## ✅ 完成標準

Task 51.6 完成需要滿足：

1. ✅ usePermissions.ts 正常運作
2. ✅ 多重身份角色正確識別
3. ✅ 權限檢查邏輯正確（最高權限原則）
4. ✅ v-permission 指令正常運作
5. ✅ PermissionDenied 組件正常顯示
6. ✅ 小幫手綁定資料正確載入
7. ✅ 測試場景 A、B、C、D 全部通過

---

## 🐛 常見問題

### Q1: API 回應 404 錯誤
**A**: 需要先建立上述兩個 API 端點

### Q2: 小幫手綁定資料是空的
**A**: 檢查資料庫 `wp_buygo_helpers` 表是否有資料，且 status = 'active'

### Q3: 多重身份只顯示一個角色
**A**: 檢查 WordPress 用戶是否真的有多個角色（用 `$user->roles` 確認）

### Q4: v-permission 指令沒有作用
**A**: 檢查是否有呼叫 `setPermissionChecker()` 設定權限檢查函數

---

**測試愉快！有問題隨時回報！** 🎉
