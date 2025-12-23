# viewMode 參數使用方式

> **重要**：`viewMode` 是所有共用組件最重要的參數之一

---

## 📖 什麼是 viewMode？

`viewMode` 是一個字串參數，用於控制組件在**前台**和**後台**的顯示差異。

### **兩種模式：**

```typescript
type ViewMode = 'frontend' | 'backend';
```

- **frontend**：前台模式（賣家、客戶使用）
- **backend**：後台模式（管理員使用）

---

## 🎯 為什麼需要 viewMode？

### **問題：**
同一個組件要在前台和後台都能用，但顯示的內容不同：

- **前台**：賣家只需要看到自己的商品，簡化的操作
- **後台**：管理員需要看到所有商品，完整的管理功能

### **解決方案：**
用一個參數控制顯示內容，而不是建立兩個組件。

---

## 📋 前台 vs 後台差異

### **ProductCard 組件範例**

| 功能 | 前台 (frontend) | 後台 (backend) |
|------|----------------|---------------|
| 商品基本資訊 | ✅ 顯示 | ✅ 顯示 |
| 庫存數量 | ✅ 顯示 | ✅ 顯示 |
| 下單數量 | ✅ 顯示 | ✅ 顯示 |
| 賣家名稱 | ❌ 不顯示 | ✅ 顯示 |
| 編輯按鈕 | ✅ 自己的商品 | ✅ 所有商品 |
| 刪除按鈕 | ❌ 不顯示 | ✅ 顯示 |
| 除錯資訊 | ❌ 不顯示 | ✅ 顯示（如果啟用） |

### **OrderList 組件範例**

| 功能 | 前台 (frontend) | 後台 (backend) |
|------|----------------|---------------|
| 訂單列表 | ✅ 自己的訂單 | ✅ 所有訂單 |
| 搜尋功能 | ✅ 限制範圍 | ✅ 全域搜尋 |
| 批量操作 | ❌ 不顯示 | ✅ 顯示 |
| 賣家篩選 | ❌ 不顯示 | ✅ 顯示 |
| 統計資訊 | ✅ 簡化版本 | ✅ 完整版本 |

---

## 💡 使用方式

### **基本用法**

```vue
<template>
  <!-- 前台使用 -->
  <ProductCard :viewMode="'frontend'" :product="product" />

  <!-- 後台使用 -->
  <ProductCard :viewMode="'backend'" :product="product" />
</template>
```

### **動態切換**

根據使用者角色自動選擇：

```vue
<script setup>
import { ref, computed } from 'vue';
import { usePermissions } from '@/composables/usePermissions';

const { isAdmin } = usePermissions();

// 管理員用後台模式，其他人用前台模式
const viewMode = computed(() => isAdmin.value ? 'backend' : 'frontend');
</script>

<template>
  <ProductCard :viewMode="viewMode" :product="product" />
</template>
```

### **根據頁面位置切換**

```vue
<script setup>
import { useRoute } from 'vue-router';
import { computed } from 'vue';

const route = useRoute();

// 後台路由用後台模式
const viewMode = computed(() => {
  return route.path.startsWith('/admin') ? 'backend' : 'frontend';
});
</script>

<template>
  <ProductCard :viewMode="viewMode" :product="product" />
</template>
```

---

## 🛠️ 開發組件時如何實作？

### **Template 中使用 v-if**

```vue
<template>
  <div>
    <!-- 基本資訊（前後台都顯示） -->
    <div>{{ product.name }}</div>
    <div>{{ product.price }}</div>

    <!-- 賣家名稱（只在後台顯示） -->
    <div v-if="viewMode === 'backend'">
      賣家：{{ product.sellerName }}
    </div>

    <!-- 批量選擇（只在後台顯示） -->
    <input
      v-if="viewMode === 'backend'"
      type="checkbox"
      :value="product.id"
    />
  </div>
</template>

<script setup>
defineProps({
  viewMode: {
    type: String,
    required: true,
    validator: (value) => ['frontend', 'backend'].includes(value)
  },
  product: Object
});
</script>
```

### **Script 中使用 computed**

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
  viewMode: String,
  orders: Array
});

// 根據 viewMode 過濾訂單
const displayOrders = computed(() => {
  if (props.viewMode === 'frontend') {
    // 前台：只顯示當前使用者的訂單
    return props.orders.filter(order => order.userId === currentUserId.value);
  } else {
    // 後台：顯示所有訂單
    return props.orders;
  }
});
</script>
```

### **Class Binding**

```vue
<template>
  <div
    :class="[
      'product-card',
      viewMode === 'backend' ? 'admin-mode' : 'user-mode'
    ]"
  >
    <!-- 內容 -->
  </div>
</template>

<style scoped>
.product-card {
  padding: 1rem;
}

/* 後台模式：更大的內距 */
.admin-mode {
  padding: 1.5rem;
  border: 2px solid #3b82f6;
}

/* 前台模式：簡潔樣式 */
.user-mode {
  border: 1px solid #e5e7eb;
}
</style>
```

---

## ✅ 最佳實踐

### 1. **預設值設定**

```vue
<script setup>
const props = defineProps({
  viewMode: {
    type: String,
    default: 'frontend',  // 預設前台模式
    validator: (value) => ['frontend', 'backend'].includes(value)
  }
});
</script>
```

### 2. **類型檢查**

使用 TypeScript：

```typescript
interface Props {
  viewMode: 'frontend' | 'backend';
  product: Product;
}

const props = defineProps<Props>();
```

### 3. **一致性原則**

所有共用組件都應該：
- 支援 `viewMode` 參數
- 預設值為 `'frontend'`
- 使用相同的判斷邏輯

### 4. **文件說明**

每個組件都應該在文件中說明：
- 前台模式顯示什麼
- 後台模式顯示什麼
- 差異是什麼

---

## 🐛 常見問題

### Q1: 忘記傳遞 viewMode 會怎樣？
**A**: 會使用預設值（通常是 `'frontend'`），組件會以前台模式顯示

### Q2: 可以有第三種模式嗎（例如：'mobile'）？
**A**: 不建議。響應式設計用 Tailwind 的斷點處理，不要用 viewMode

### Q3: viewMode 可以動態改變嗎？
**A**: 可以，使用 `ref` 或 `computed` 即可

### Q4: 前台模式可以看到所有資料嗎？
**A**: 不行！前台模式要搭配權限控制，只顯示使用者有權限的資料

---

## 📝 範例總結

### **正確用法** ✅

```vue
<!-- 明確指定 viewMode -->
<ProductCard :viewMode="'frontend'" />

<!-- 動態切換 -->
<ProductCard :viewMode="isAdmin ? 'backend' : 'frontend'" />

<!-- 使用 computed -->
<ProductCard :viewMode="viewMode" />
```

### **錯誤用法** ❌

```vue
<!-- 未指定 viewMode（雖然會用預設值，但不明確） -->
<ProductCard />

<!-- 拼寫錯誤 -->
<ProductCard :viewMode="'admin'" />

<!-- 使用錯誤的判斷方式 -->
<ProductCard :viewMode="isMobile ? 'mobile' : 'desktop'" />
```

---

**記住：viewMode 控制「功能顯示」，不控制「響應式佈局」！**

響應式用 Tailwind 斷點：
```vue
<div class="p-4 sm:p-6 md:p-8">  <!-- 響應式 padding -->
  <ProductCard :viewMode="viewMode" />  <!-- viewMode 控制功能 -->
</div>
```
