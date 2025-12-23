/**
 * FluentCart 即時資料同步
 *
 * 監聽 FluentCart 結帳表單的變化，即時同步客戶資料到 BuyGo 系統
 */

(function () {
  "use strict";

  // 配置（從 WordPress 傳入或使用預設值）
  const config = {
    syncEndpoint:
      window.buygoFluentCartConfig?.webhookUrl ||
      "/buygo-fluentcart-webhook.php",
    restEndpoint: window.buygoFluentCartConfig?.restUrl || "/wp-json/buygo/v1/",
    debounceDelay: 1000, // 防抖延遲（毫秒）
    debug: window.buygoFluentCartConfig?.debug || true,
    nonce: window.buygoFluentCartConfig?.nonce || "",
  };

  // 除錯日誌
  function log(message, data = null) {
    if (config.debug) {
      console.log("[BuyGo FluentCart Sync]", message, data);
    }
  }

  // 全域物件，供外部呼叫
  window.buygoFluentCartSync = {
    reinitialize: function () {
      log("重新初始化同步系統");
      setupFormListeners();
    },
    manualSync: function () {
      log("手動觸發同步");
      const data = collectFormData();
      syncData(data);
    },
    getCollectedData: function () {
      return collectFormData();
    },
  };

  // 除錯日誌
  function log(message, data = null) {
    if (config.debug) {
      console.log("[BuyGo FluentCart Sync]", message, data);
    }
  }

  // 防抖函數
  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  // 取得客戶 email（從 FluentCart 的隱藏欄位或其他地方）
  function getCustomerEmail() {
    // 嘗試從各種可能的地方取得 email
    const emailSources = [
      'input[name="customer_email"]',
      'input[name="email"]',
      'input[type="email"]',
      "#customer_email",
      "#email",
      // FluentCart 特定選擇器
      '.fct-checkout-form input[type="email"]',
      '.fluent-cart-checkout input[type="email"]',
      // 嘗試從表單資料中找
      'form input[type="email"]',
    ];

    for (const selector of emailSources) {
      const element = document.querySelector(selector);
      if (element && element.value && element.value.includes("@")) {
        log(`找到 email: ${element.value} (來源: ${selector})`);
        return element.value;
      }
    }

    // 嘗試從 FluentCart 的 JavaScript 變數取得
    if (window.fluentCartApp && window.fluentCartApp.customer_email) {
      log(
        `從 FluentCart App 取得 email: ${window.fluentCartApp.customer_email}`
      );
      return window.fluentCartApp.customer_email;
    }

    // 嘗試從 WordPress 用戶資料取得
    if (window.wp && window.wp.data) {
      const currentUser = window.wp.data.select("core").getCurrentUser();
      if (currentUser && currentUser.email) {
        log(`從 WordPress 用戶取得 email: ${currentUser.email}`);
        return currentUser.email;
      }
    }

    // 嘗試從 localStorage 或 sessionStorage 取得
    const storageEmail =
      localStorage.getItem("customer_email") ||
      sessionStorage.getItem("customer_email");
    if (storageEmail && storageEmail.includes("@")) {
      log(`從 Storage 取得 email: ${storageEmail}`);
      return storageEmail;
    }

    log("無法找到客戶 email");
    return null;
  }

  // 收集表單資料
  function collectFormData() {
    const formData = {
      // 基本資訊
      customer_email: getCustomerEmail(),

      // 帳單資訊
      billing_full_name: getValue("billing_full_name"),
      billing_phone: getValue("billing_phone"),
      billing_address_1: getValue("billing_address_1"),
      billing_address_2: getValue("billing_address_2"),
      billing_city: getValue("billing_city"),
      billing_state: getValue("billing_state"),
      billing_postcode: getValue("billing_postcode"),
      billing_country: getValue("billing_country"),

      // 運送資訊（如果有的話）
      shipping_full_name: getValue("shipping_full_name"),
      shipping_phone: getValue("shipping_phone"),
      shipping_address_1: getValue("shipping_address_1"),
      shipping_address_2: getValue("shipping_address_2"),
      shipping_city: getValue("shipping_city"),
      shipping_state: getValue("shipping_state"),
      shipping_postcode: getValue("shipping_postcode"),
      shipping_country: getValue("shipping_country"),

      // 標記為即時同步
      sync_type: "realtime",
      timestamp: new Date().toISOString(),
    };

    // 移除空值
    Object.keys(formData).forEach((key) => {
      if (!formData[key] || formData[key].trim() === "") {
        delete formData[key];
      }
    });

    return formData;
  }

  // 取得欄位值
  function getValue(fieldName) {
    const element = document.querySelector(`[name="${fieldName}"]`);
    return element ? element.value.trim() : "";
  }

  // 檢查是否有足夠的資料進行同步
  function hasMinimumData(data) {
    // 至少需要 email 和 (電話 或 地址)
    if (!data.customer_email) {
      return false;
    }

    const hasPhone = data.billing_phone || data.shipping_phone;
    const hasAddress = data.billing_address_1 || data.shipping_address_1;

    return hasPhone || hasAddress;
  }

  // 同步資料到後端
  async function syncData(data) {
    if (!hasMinimumData(data)) {
      log("資料不足，跳過同步", data);
      return;
    }

    log("開始同步資料", data);

    // 觸發同步開始事件
    document.dispatchEvent(
      new CustomEvent("buygo:sync:start", {
        detail: data,
      })
    );

    try {
      const response = await fetch(config.syncEndpoint, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify(data),
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const result = await response.json();
      log("同步成功", result);

      // 觸發同步成功事件
      document.dispatchEvent(
        new CustomEvent("buygo:sync:success", {
          detail: result,
        })
      );

      // 可以在這裡顯示成功訊息給用戶
      if (result.success && result.processed > 0) {
        showSyncStatus("success", "資料已同步");
      }
    } catch (error) {
      log("同步失敗", error);

      // 觸發同步失敗事件
      document.dispatchEvent(
        new CustomEvent("buygo:sync:error", {
          detail: { error: error.message },
        })
      );

      showSyncStatus("error", "同步失敗");
    }
  }

  // 顯示同步狀態
  function showSyncStatus(type, message) {
    // 建立或更新狀態指示器
    let statusElement = document.getElementById("buygo-sync-status");

    if (!statusElement) {
      statusElement = document.createElement("div");
      statusElement.id = "buygo-sync-status";
      statusElement.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 10px 15px;
                border-radius: 4px;
                font-size: 14px;
                z-index: 9999;
                transition: opacity 0.3s ease;
            `;
      document.body.appendChild(statusElement);
    }

    // 設定樣式和內容
    if (type === "success") {
      statusElement.style.backgroundColor = "#d4edda";
      statusElement.style.color = "#155724";
      statusElement.style.border = "1px solid #c3e6cb";
      statusElement.innerHTML = "✅ " + message;
    } else if (type === "error") {
      statusElement.style.backgroundColor = "#f8d7da";
      statusElement.style.color = "#721c24";
      statusElement.style.border = "1px solid #f5c6cb";
      statusElement.innerHTML = "❌ " + message;
    }

    statusElement.style.opacity = "1";

    // 3秒後淡出
    setTimeout(() => {
      statusElement.style.opacity = "0";
    }, 3000);
  }

  // 防抖的同步函數
  const debouncedSync = debounce(() => {
    const data = collectFormData();
    syncData(data);
  }, config.debounceDelay);

  // 監聽表單欄位變化
  function setupFormListeners() {
    const fieldsToWatch = [
      // 基本欄位
      "email",
      "customer_email",
      // 帳單資訊
      "billing_full_name",
      "billing_first_name",
      "billing_last_name",
      "billing_phone",
      "billing_address_1",
      "billing_address_2",
      "billing_city",
      "billing_state",
      "billing_postcode",
      "billing_country",
      // 運送資訊
      "shipping_full_name",
      "shipping_first_name",
      "shipping_last_name",
      "shipping_phone",
      "shipping_address_1",
      "shipping_address_2",
      "shipping_city",
      "shipping_state",
      "shipping_postcode",
      "shipping_country",
    ];

    let listenersAdded = 0;

    fieldsToWatch.forEach((fieldName) => {
      // 嘗試多種選擇器
      const selectors = [
        `[name="${fieldName}"]`,
        `#${fieldName}`,
        `.${fieldName}`,
        `input[name="${fieldName}"]`,
        `select[name="${fieldName}"]`,
        `textarea[name="${fieldName}"]`,
      ];

      for (const selector of selectors) {
        const elements = document.querySelectorAll(selector);
        elements.forEach((element) => {
          if (!element.dataset.buygoListenerAdded) {
            // 監聽多種事件
            ["input", "change", "blur", "keyup"].forEach((eventType) => {
              element.addEventListener(eventType, debouncedSync);
            });

            // 特別處理 email 欄位，儲存到 storage
            if (fieldName.includes("email")) {
              element.addEventListener("blur", (e) => {
                if (e.target.value && e.target.value.includes("@")) {
                  sessionStorage.setItem("customer_email", e.target.value);
                  log(`儲存客戶 email: ${e.target.value}`);
                }
              });
            }

            element.dataset.buygoListenerAdded = "true";
            listenersAdded++;
            log(`已設定監聽器: ${fieldName} (${selector})`);
          }
        });
      }
    });

    // 特別處理下拉選單（FluentCart 使用自定義選單）
    const selectElements = document.querySelectorAll(
      ".fct-nice-select, .el-select, .select2"
    );
    selectElements.forEach((select, index) => {
      if (!select.dataset.buygoListenerAdded) {
        select.addEventListener("click", () => {
          // 延遲一點再同步，等待選單值更新
          setTimeout(debouncedSync, 200);
        });
        select.dataset.buygoListenerAdded = "true";
        listenersAdded++;
        log(`已設定下拉選單監聽器: ${index}`);
      }
    });

    // 監聽整個表單的變化（作為備用）
    const forms = document.querySelectorAll("form");
    forms.forEach((form, index) => {
      if (!form.dataset.buygoListenerAdded) {
        form.addEventListener("change", debouncedSync);
        form.dataset.buygoListenerAdded = "true";
        log(`已設定表單監聽器: ${index}`);
      }
    });

    log(`總共設定了 ${listenersAdded} 個監聽器`);
  }

  // 初始化
  function init() {
    log("初始化 FluentCart 即時同步");

    // 等待 DOM 完全載入
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", () => {
        setTimeout(setupFormListeners, 1000); // 延遲 1 秒確保 FluentCart 完全載入
      });
    } else {
      setTimeout(setupFormListeners, 1000);
    }

    // 也監聽動態載入的內容
    const observer = new MutationObserver((mutations) => {
      let shouldResetup = false;

      mutations.forEach((mutation) => {
        if (mutation.type === "childList") {
          mutation.addedNodes.forEach((node) => {
            if (node.nodeType === 1) {
              // Element node
              // 檢查是否有新的表單欄位
              const newFields = node.querySelectorAll
                ? node.querySelectorAll(
                    'input[name*="billing_"], input[name*="shipping_"], input[type="email"], form'
                  )
                : [];

              if (newFields.length > 0) {
                shouldResetup = true;
              }
            }
          });
        }
      });

      if (shouldResetup) {
        log("偵測到新的表單欄位，重新設定監聽器");
        setTimeout(setupFormListeners, 500);
      }
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true,
    });

    // 定期檢查並重新設定監聽器（防止動態載入的表單沒有被監聽到）
    setInterval(() => {
      const unlistenedInputs = document.querySelectorAll(
        "input:not([data-buygo-listener-added]), select:not([data-buygo-listener-added])"
      );
      if (unlistenedInputs.length > 0) {
        log(`發現 ${unlistenedInputs.length} 個未監聽的欄位，重新設定監聽器`);
        setupFormListeners();
      }
    }, 5000); // 每 5 秒檢查一次
  }

  // 啟動
  init();
})();
