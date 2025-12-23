<?php

namespace BuyGo\Core\Admin;

use BuyGo\Core\Services\DebugService;

/**
 * Debug Page - 管理後台除錯頁面
 * 
 * 提供完整的除錯資訊查看和管理功能
 * 
 * @package BuyGo\Core\Admin
 * @version 1.0.0
 */
class DebugPage
{
    private $debugService;

    public function __construct()
    {
        $this->debugService = new DebugService();
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_buygo_debug_action', [$this, 'handle_ajax_action']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * 添加管理選單
     */
    public function add_admin_menu(): void
    {
        add_submenu_page(
            'buygo-dashboard',
            'BuyGo 除錯中心',
            '除錯中心',
            'manage_options',
            'buygo-debug',
            [$this, 'render_debug_page']
        );
    }

    /**
     * 載入腳本和樣式
     */
    public function enqueue_scripts($hook): void
    {
        if ($hook !== 'buygo_page_buygo-debug') {
            return;
        }

        wp_enqueue_script(
            'buygo-debug-admin',
            plugin_dir_url(__FILE__) . '../../assets/js/debug-admin.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_enqueue_style(
            'buygo-debug-admin',
            plugin_dir_url(__FILE__) . '../../assets/css/debug-admin.css',
            [],
            '1.0.0'
        );

        wp_localize_script('buygo-debug-admin', 'buygoDebug', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('buygo_debug_nonce')
        ]);
    }

    /**
     * 渲染除錯頁面
     */
    public function render_debug_page(): void
    {
        // 處理表單提交
        if (isset($_POST['action']) && wp_verify_nonce($_POST['_wpnonce'], 'buygo_debug_action')) {
            $this->handle_form_action();
        }

        // 取得篩選參數
        $filters = [
            'module' => $_GET['module'] ?? '',
            'level' => $_GET['level'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'search' => $_GET['search'] ?? ''
        ];

        // 取得日誌資料
        $logs = $this->debugService->getLogs($filters, 100);
        $stats = $this->debugService->getDebugStats(7);

        ?>
        <div class="wrap">
            <h1>BuyGo 除錯中心</h1>
            
            <!-- 統計資訊 -->
            <div class="debug-stats">
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3>總日誌數</h3>
                        <div class="stat-number"><?php echo $stats['total_logs'] ?? 0; ?></div>
                        <div class="stat-period">最近 7 天</div>
                    </div>
                    
                    <div class="stat-card error">
                        <h3>錯誤數</h3>
                        <div class="stat-number">
                            <?php 
                            $errorCount = 0;
                            foreach ($stats['level_stats'] ?? [] as $levelStat) {
                                if ($levelStat['level'] === 'error') {
                                    $errorCount = $levelStat['count'];
                                    break;
                                }
                            }
                            echo $errorCount;
                            ?>
                        </div>
                        <div class="stat-period">最近 7 天</div>
                    </div>
                    
                    <div class="stat-card warning">
                        <h3>警告數</h3>
                        <div class="stat-number">
                            <?php 
                            $warningCount = 0;
                            foreach ($stats['level_stats'] ?? [] as $levelStat) {
                                if ($levelStat['level'] === 'warning') {
                                    $warningCount = $levelStat['count'];
                                    break;
                                }
                            }
                            echo $warningCount;
                            ?>
                        </div>
                        <div class="stat-period">最近 7 天</div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>活躍模組</h3>
                        <div class="stat-number"><?php echo count($stats['module_stats'] ?? []); ?></div>
                        <div class="stat-period">不同模組</div>
                    </div>
                </div>
            </div>

            <!-- 操作按鈕 -->
            <div class="debug-actions">
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('buygo_debug_action'); ?>
                    <input type="hidden" name="action" value="clean_logs">
                    <button type="submit" class="button" onclick="return confirm('確定要清理舊日誌嗎？')">
                        清理舊日誌
                    </button>
                </form>
                
                <button type="button" class="button" onclick="exportLogs('json')">
                    匯出 JSON
                </button>
                
                <button type="button" class="button" onclick="exportLogs('csv')">
                    匯出 CSV
                </button>
                
                <button type="button" class="button button-primary" onclick="refreshLogs()">
                    重新整理
                </button>
            </div>

            <!-- 篩選表單 -->
            <div class="debug-filters">
                <form method="get" class="filters-form">
                    <input type="hidden" name="page" value="buygo-debug">
                    
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="module">模組：</label>
                            <select name="module" id="module">
                                <option value="">全部模組</option>
                                <?php foreach ($stats['module_stats'] ?? [] as $moduleStat): ?>
                                    <option value="<?php echo esc_attr($moduleStat['module']); ?>" 
                                            <?php selected($filters['module'], $moduleStat['module']); ?>>
                                        <?php echo esc_html($moduleStat['module']); ?> (<?php echo $moduleStat['count']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="level">等級：</label>
                            <select name="level" id="level">
                                <option value="">全部等級</option>
                                <option value="debug" <?php selected($filters['level'], 'debug'); ?>>Debug</option>
                                <option value="info" <?php selected($filters['level'], 'info'); ?>>Info</option>
                                <option value="warning" <?php selected($filters['level'], 'warning'); ?>>Warning</option>
                                <option value="error" <?php selected($filters['level'], 'error'); ?>>Error</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="date_from">開始日期：</label>
                            <input type="date" name="date_from" id="date_from" 
                                   value="<?php echo esc_attr($filters['date_from']); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="date_to">結束日期：</label>
                            <input type="date" name="date_to" id="date_to" 
                                   value="<?php echo esc_attr($filters['date_to']); ?>">
                        </div>
                    </div>
                    
                    <div class="filter-row">
                        <div class="filter-group search-group">
                            <label for="search">搜尋：</label>
                            <input type="text" name="search" id="search" 
                                   value="<?php echo esc_attr($filters['search']); ?>"
                                   placeholder="搜尋訊息內容...">
                        </div>
                        
                        <div class="filter-group">
                            <button type="submit" class="button button-primary">篩選</button>
                            <a href="<?php echo admin_url('admin.php?page=buygo-debug'); ?>" class="button">清除</a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- 日誌列表 -->
            <div class="debug-logs">
                <div class="logs-header">
                    <h2>除錯日誌 (<?php echo count($logs); ?> 筆)</h2>
                </div>
                
                <div class="logs-table-container">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th class="column-time">時間</th>
                                <th class="column-level">等級</th>
                                <th class="column-module">模組</th>
                                <th class="column-message">訊息</th>
                                <th class="column-user">用戶</th>
                                <th class="column-actions">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="6" class="no-logs">沒有找到符合條件的日誌</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr class="log-row log-level-<?php echo esc_attr($log['level']); ?>">
                                        <td class="column-time">
                                            <?php echo esc_html(date('m-d H:i:s', strtotime($log['created_at']))); ?>
                                        </td>
                                        <td class="column-level">
                                            <span class="level-badge level-<?php echo esc_attr($log['level']); ?>">
                                                <?php echo strtoupper($log['level']); ?>
                                            </span>
                                        </td>
                                        <td class="column-module">
                                            <?php echo esc_html($log['module']); ?>
                                        </td>
                                        <td class="column-message">
                                            <div class="message-text">
                                                <?php echo esc_html($log['message']); ?>
                                            </div>
                                            <?php if (!empty($log['data'])): ?>
                                                <button type="button" class="button-link toggle-data" 
                                                        onclick="toggleLogData(<?php echo $log['id']; ?>)">
                                                    顯示詳細資料
                                                </button>
                                                <div id="log-data-<?php echo $log['id']; ?>" class="log-data" style="display: none;">
                                                    <pre><?php echo esc_html(json_encode($log['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="column-user">
                                            <?php 
                                            if ($log['user_id']) {
                                                $user = get_userdata($log['user_id']);
                                                echo $user ? esc_html($user->display_name) : '未知用戶';
                                            } else {
                                                echo '系統';
                                            }
                                            ?>
                                        </td>
                                        <td class="column-actions">
                                            <button type="button" class="button-link" 
                                                    onclick="copyLogInfo(<?php echo htmlspecialchars(json_encode($log)); ?>)">
                                                複製
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 即時日誌 -->
            <div class="debug-realtime">
                <div class="realtime-header">
                    <h3>即時日誌</h3>
                    <button type="button" class="button" id="toggle-realtime">開始監控</button>
                </div>
                <div id="realtime-logs" class="realtime-logs" style="display: none;">
                    <div class="realtime-content">
                        <!-- 即時日誌內容將透過 JavaScript 更新 -->
                    </div>
                </div>
            </div>
        </div>

        <script>
        // 切換日誌詳細資料顯示
        function toggleLogData(logId) {
            const dataDiv = document.getElementById('log-data-' + logId);
            const button = dataDiv.previousElementSibling;
            
            if (dataDiv.style.display === 'none') {
                dataDiv.style.display = 'block';
                button.textContent = '隱藏詳細資料';
            } else {
                dataDiv.style.display = 'none';
                button.textContent = '顯示詳細資料';
            }
        }

        // 複製日誌資訊
        function copyLogInfo(logData) {
            const text = JSON.stringify(logData, null, 2);
            navigator.clipboard.writeText(text).then(() => {
                alert('日誌資訊已複製到剪貼簿');
            }).catch(() => {
                // 備援方案
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                alert('日誌資訊已複製到剪貼簿');
            });
        }

        // 匯出日誌
        function exportLogs(format) {
            const params = new URLSearchParams(window.location.search);
            params.set('action', 'export_logs');
            params.set('format', format);
            
            window.location.href = '<?php echo admin_url('admin-ajax.php'); ?>?' + params.toString();
        }

        // 重新整理日誌
        function refreshLogs() {
            window.location.reload();
        }
        </script>
        <?php
    }

    /**
     * 處理表單操作
     */
    private function handle_form_action(): void
    {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'clean_logs':
                $deletedCount = $this->debugService->cleanOldLogs();
                add_settings_error(
                    'buygo_debug',
                    'logs_cleaned',
                    "已清理 {$deletedCount} 筆舊日誌",
                    'updated'
                );
                break;
        }
    }

    /**
     * 處理 AJAX 請求
     */
    public function handle_ajax_action(): void
    {
        if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'buygo_debug_nonce')) {
            wp_die('安全驗證失敗');
        }

        if (!current_user_can('manage_options')) {
            wp_die('權限不足');
        }

        $action = $_REQUEST['action'] ?? '';

        switch ($action) {
            case 'export_logs':
                $this->export_logs();
                break;
                
            case 'get_realtime_logs':
                $this->get_realtime_logs();
                break;
        }

        wp_die();
    }

    /**
     * 匯出日誌
     */
    private function export_logs(): void
    {
        try {
            $format = $_REQUEST['format'] ?? 'json';
            $filters = [
                'module' => $_REQUEST['module'] ?? '',
                'level' => $_REQUEST['level'] ?? '',
                'date_from' => $_REQUEST['date_from'] ?? '',
                'date_to' => $_REQUEST['date_to'] ?? '',
                'search' => $_REQUEST['search'] ?? ''
            ];

            $filepath = $this->debugService->exportLogs($filters, $format);
            
            if (file_exists($filepath)) {
                $filename = basename($filepath);
                
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($filepath));
                
                readfile($filepath);
                unlink($filepath); // 清理臨時檔案
            }

        } catch (\Exception $e) {
            wp_die('匯出失敗：' . $e->getMessage());
        }
    }

    /**
     * 取得即時日誌
     */
    private function get_realtime_logs(): void
    {
        $logs = $this->debugService->getLogs([], 10);
        wp_send_json_success($logs);
    }
}