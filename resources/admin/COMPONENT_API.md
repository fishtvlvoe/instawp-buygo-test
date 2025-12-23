# BuyGo 組件 API 文件

> **完整參考**：所有共用組件的 Props、Events、Slots 和使用範例

---

## 📦 ProductCard

### **Props**

| 名稱 | 類型 | 必要 | 預設值 | 說明 |
|------|------|------|--------|------|
| `product` | `Object` | ✅ | - | 商品資料 |
| `viewMode` | `'frontend' \| 'backend'` | ✅ | `'frontend'` | 顯示模式 |
| `showOrderCount` | `Boolean` | ❌ | `false` | 顯示下單數量 |
| `allowEdit` | `Boolean` | ❌ | `false` | 允許編輯 |
| `showDebugInfo` | `Boolean` | ❌ | `false` | 顯示除錯資訊 |

### **Product 資料格式**

```typescript
interface Product {
  id: number;
  post_id: number;
  name: string;
  price: number;
  inventory: number;
  orderCount?: number;
  reservedCount?: number;
  status: 'publish' | 'draft';
  image?: string | null;
  sellerId: number;
  sellerName?: string;
  createdAt: string;
  updatedAt: string;
}
```

### **Events**

| 事件名稱 | 參數 | 說明 |
|---------|------|------|
| `edit` | `product: Product` | 點擊編輯按鈕 |
| `view-details` | `product: Product` | 查看商品詳情 |
| `debug` | `debugData: Object` | 除錯資訊（需啟用 showDebugInfo） |

### **使用範例**

```vue
<template>
  <ProductCard
    :product="product"
    :viewMode="'frontend'"
    :showOrderCount="true"
    :allowEdit="true"
    @edit="handleEdit"
    @view-details="handleViewDetails"
  />
</template>

<script setup>
const product = {
  id: 1,
  post_id: 100,
  name: '測試商品',
  price: 1000,
  inventory: 50,
  orderCount: 10,
  status: 'publish',
  sellerId: 8
};

const handleEdit = (product) => {
  console.log('編輯:', product);
};

const handleViewDetails = (product) => {
  console.log('查看:', product);
};
</script>
```

---

## 📋 OrderList

### **Props**

| 名稱 | 類型 | 必要 | 預設值 | 說明 |
|------|------|------|--------|------|
| `viewMode` | `'frontend' \| 'backend'` | ✅ | `'frontend'` | 顯示模式 |
| `searchQuery` | `String` | ❌ | `''` | 搜尋關鍵字 |
| `statusFilter` | `String` | ❌ | `'all'` | 訂單狀態篩選 |
| `paymentStatusFilter` | `String` | ❌ | `'all'` | 付款狀態篩選 |
| `shippingStatusFilter` | `String` | ❌ | `'all'` | 運送狀態篩選 |

### **Events**

| 事件名稱 | 參數 | 說明 |
|---------|------|------|
| `view-details` | `order: Order` | 查看訂單詳情 |
| `update-status` | `{ orderId, status }` | 更新訂單狀態 |
| `batch-operation` | `{ action, orderIds }` | 批量操作 |

### **使用範例**

```vue
<template>
  <OrderList
    :viewMode="'backend'"
    :searchQuery="searchTerm"
    :statusFilter="statusFilter"
    @view-details="handleViewDetails"
    @update-status="handleUpdateStatus"
  />
</template>

<script setup>
import { ref } from 'vue';

const searchTerm = ref('');
const statusFilter = ref('all');

const handleViewDetails = (order) => {
  router.push(`/orders/${order.id}`);
};

const handleUpdateStatus = ({ orderId, status }) => {
  // 呼叫 API 更新狀態
};
</script>
```

---

## 🐛 DebugPanel

### **Props**

| 名稱 | 類型 | 必要 | 預設值 | 說明 |
|------|------|------|--------|------|
| `visible` | `Boolean` | ✅ | `false` | 是否顯示面板 |
| `apiLogs` | `Array<ApiLog>` | ❌ | `[]` | API 請求記錄 |
| `errorLogs` | `Array<ErrorLog>` | ❌ | `[]` | 錯誤記錄 |
| `systemInfo` | `Object` | ❌ | `{}` | 系統資訊 |
| `showFloatingButton` | `Boolean` | ❌ | `true` | 顯示浮動開啟按鈕 |
| `backendDebugUrl` | `String` | ❌ | `''` | 後台除錯頁面 URL |

### **ApiLog 資料格式**

```typescript
interface ApiLog {
  method: 'GET' | 'POST' | 'PUT' | 'DELETE';
  url: string;
  status: number;
  duration?: number;
  timestamp: number;
  request?: any;
  response?: any;
  error?: string;
}
```

### **ErrorLog 資料格式**

```typescript
interface ErrorLog {
  message: string;
  component?: string;
  timestamp: number;
  stack?: string;
  context?: any;
}
```

### **Events**

| 事件名稱 | 參數 | 說明 |
|---------|------|------|
| `close` | - | 關閉面板 |
| `open` | - | 開啟面板 |
| `clear-logs` | - | 清除所有記錄 |

### **使用範例**

```vue
<template>
  <DebugPanel
    :visible="debugVisible"
    :api-logs="apiLogs"
    :error-logs="errorLogs"
    :system-info="systemInfo"
    @close="debugVisible = false"
    @clear-logs="clearLogs"
  />
</template>

<script setup>
import { ref } from 'vue';

const debugVisible = ref(false);
const apiLogs = ref([]);
const errorLogs = ref([]);
const systemInfo = ref({
  userAgent: navigator.userAgent,
  windowSize: `${window.innerWidth} x ${window.innerHeight}`
});

const clearLogs = () => {
  apiLogs.value = [];
  errorLogs.value = [];
};
</script>
```

---

## 🔒 PermissionDenied

### **Props**

| 名稱 | 類型 | 必要 | 預設值 | 說明 |
|------|------|------|--------|------|
| `show` | `Boolean` | ❌ | `true` | 是否顯示 |
| `variant` | `'info' \| 'warning' \| 'error'` | ❌ | `'warning'` | 樣式變體 |
| `title` | `String` | ❌ | `'權限不足'` | 標題 |
| `message` | `String` | ❌ | `'你沒有權限執行此操作'` | 訊息內容 |
| `reason` | `String` | ❌ | `''` | 失敗原因 |
| `details` | `String` | ❌ | `''` | 詳細資訊 |
| `dismissible` | `Boolean` | ❌ | `false` | 可關閉 |
| `showActions` | `Boolean` | ❌ | `false` | 顯示操作按鈕 |
| `showContactAdmin` | `Boolean` | ❌ | `false` | 顯示聯絡管理員按鈕 |
| `showRetry` | `Boolean` | ❌ | `false` | 顯示重試按鈕 |
| `showGoBack` | `Boolean` | ❌ | `false` | 顯示返回按鈕 |

### **Events**

| 事件名稱 | 參數 | 說明 |
|---------|------|------|
| `dismiss` | - | 關閉訊息 |
| `contact-admin` | - | 點擊聯絡管理員 |
| `retry` | - | 點擊重試 |
| `go-back` | - | 點擊返回 |

### **Slots**

| 名稱 | 說明 |
|------|------|
| `default` | 自訂訊息內容 |

### **使用範例**

```vue
<template>
  <!-- 基本用法 -->
  <PermissionDenied
    variant="warning"
    title="權限不足"
    message="你沒有權限管理此商品"
    reason="你不是此商品的賣家"
  />

  <!-- 帶操作按鈕 -->
  <PermissionDenied
    variant="error"
    :show-actions="true"
    :show-contact-admin="true"
    :show-go-back="true"
    @contact-admin="handleContactAdmin"
    @go-back="router.back()"
  />

  <!-- 使用 slot 自訂內容 -->
  <PermissionDenied>
    <p>你沒有權限執行此操作。</p>
    <p>如需協助，請聯絡 <a href="mailto:admin@example.com">管理員</a></p>
  </PermissionDenied>
</template>
```

---

## 🔧 v-permission 指令

### **用法**

```vue
<!-- 全域權限檢查 -->
<button v-permission="'manage_products'">管理商品</button>

<!-- 資源權限檢查 -->
<div v-permission="{ action: 'edit', resource: product }">
  編輯區塊
</div>

<!-- 隱藏模式（保留在 DOM） -->
<span v-permission:hide="'manage_options'">
  管理員專用
</span>
```

### **參數格式**

```typescript
// 簡單權限檢查
type SimplePermission = string;

// 資源權限檢查
interface ResourcePermission {
  action?: string;
  resource?: any;
  permission?: string;
}
```

### **修飾符**

| 修飾符 | 說明 |
|-------|------|
| `hide` | 隱藏元素（`display: none`）而不是移除 |

### **使用範例**

```vue
<template>
  <!-- 只有管理員能看到 -->
  <button v-permission="'manage_options'">
    系統設定
  </button>

  <!-- 只有賣家或綁定的小幫手能編輯 -->
  <div v-permission="{ action: 'edit', resource: { sellerId: 8 } }">
    <textarea v-model="product.description" />
  </div>

  <!-- 無權限時隱藏（不移除） -->
  <nav v-permission:hide="'manage_buygo_shop'">
    <a href="/products">商品管理</a>
    <a href="/orders">訂單管理</a>
  </nav>
</template>

<script setup>
import { setPermissionChecker } from '@/directives/permission';
import { usePermissions } from '@/composables/usePermissions';

const { can } = usePermissions();

// 設定權限檢查函數
setPermissionChecker(can);
</script>
```

---

## 📚 usePermissions Composable

### **回傳值**

```typescript
interface UsePermissionsReturn {
  // 狀態
  currentUser: Ref<UserData | null>;
  helperBindings: Ref<HelperBinding[]>;
  loading: Ref<boolean>;
  error: Ref<string | null>;

  // 角色檢查
  isAdmin: ComputedRef<boolean>;
  isSeller: ComputedRef<boolean>;
  isHelper: ComputedRef<boolean>;
  isBuyer: ComputedRef<boolean>;
  roleLabels: ComputedRef<string[]>;
  hasRole: (role: string) => boolean;
  hasCap: (capability: string) => boolean;

  // 小幫手相關
  helperCan: (sellerId: number, permission: string) => boolean;
  helperSellerIds: ComputedRef<number[]>;

  // 權限檢查
  canAccessSellerResource: (sellerId: number, permission?: string) => PermissionResult;
  canAccessResource: (resource: Resource, action?: string) => PermissionResult;
  can: (action: string, resource?: Resource) => boolean;

  // 方法
  fetchUserPermissions: () => Promise<void>;
}
```

### **使用範例**

```vue
<script setup>
import { usePermissions } from '@/composables/usePermissions';

const {
  currentUser,
  isAdmin,
  isSeller,
  isHelper,
  roleLabels,
  helperCan,
  canAccessResource,
  can
} = usePermissions();

// 檢查全域權限
if (can('manage_products')) {
  console.log('可以管理商品');
}

// 檢查資源權限
const product = { sellerId: 8 };
const result = canAccessResource(product, 'edit');
if (result.allowed) {
  console.log(`允許編輯：${result.reason}`);
}

// 檢查小幫手權限
if (isHelper.value && helperCan(8, 'can_manage_products')) {
  console.log('小幫手可以管理賣家 8 的商品');
}
</script>
```

---

## 🎨 通用設計規範

### **色彩常數**

```typescript
const STATUS_COLORS = {
  success: 'bg-green-100 text-green-700',
  warning: 'bg-yellow-100 text-yellow-700',
  error: 'bg-red-100 text-red-700',
  info: 'bg-blue-100 text-blue-700',
  helper: 'bg-purple-100 text-purple-700'
};
```

### **斷點**

使用 Tailwind 響應式斷點：
- `sm:` - 640px+
- `md:` - 768px+
- `lg:` - 1024px+
- `xl:` - 1280px+

### **間距**

使用 Tailwind 間距系統：
- `p-4` = 1rem
- `p-6` = 1.5rem
- `p-8` = 2rem

---

**有問題？** 查看 [組件使用指南](./components/README.md) 或 [viewMode 參數文件](./VIEW_MODE_GUIDE.md)
