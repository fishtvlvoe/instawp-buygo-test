<?php

namespace BuyGo\Core\Services;

/**
 * Debug Service - 統一除錯服務
 * 
 * 根據強制性除錯規範實作的統一除錯和日誌系統
 * 
 * @package BuyGo\Core\Services
 * @version 1.0.0
 */
class DebugService
{
    private $logTable;
    private $logFile;
    private $maxLogAge = 30; // 保留 30 天的日誌

    public function __construct()
    {
        global $wpdb;
        $this->logTable = $wpdb->prefix . 'buygo_debug_logs';
        $this->logFile = WP_CONTENT_DIR . '/uploads/buygo-debug.log';
        
        // 確保日誌目錄存在
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            wp_mkdir_p($logDir);
        }
    }

    /**
     * 記錄除錯資訊
     * 
     * @param string $module 模組名稱
     * @param string $message 訊息
     * @param array $data 額外資料
     * @param string $level 日誌等級 info|warning|error|debug
     */
    public function log(string $module, string $message, array $data = [], string $level = 'info'): void
    {
        $logEntry = [
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'module' => $module,
            'message' => $message,
            'data' => $data,
            'user_id' => get_current_user_id(),
            'ip_address' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? ''
        ];

        // 雙重記錄：檔案 + 資料庫
        $this->logToFile($logEntry);
        $this->logToDatabase($logEntry);
    }

    /**
     * 記錄 API 請求
     * 
     * @param string $endpoint API 端點
     * @param array $request 請求資料
     * @param array $response 回應資料
     * @param int $responseTime 回應時間（毫秒）
     * @param string $status 狀態 success|error
     */
    public function logApiRequest(string $endpoint, array $request, array $response, int $responseTime, string $status = 'success'): void
    {
        $this->log('API', "API 請求: {$endpoint}", [
            'endpoint' => $endpoint,
            'request' => $request,
            'response' => $response,
            'response_time_ms' => $responseTime,
            'status' => $status
        ], $status === 'success' ? 'info' : 'error');
    }

    /**
     * 記錄錯誤
     * 
     * @param string $module 模組名稱
     * @param \Exception $exception 例外物件
     * @param array $context 上下文資料
     */
    public function logError(string $module, \Exception $exception, array $context = []): void
    {
        $this->log($module, $exception->getMessage(), [
            'exception_class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'context' => $context
        ], 'error');
    }

    /**
     * 取得除錯日誌
     * 
     * @param array $filters 篩選條件
     * @param int $limit 限制筆數
     * @return array
     */
    public function getLogs(array $filters = [], int $limit = 100): array
    {
        try {
            global $wpdb;

            $where = ['1=1'];
            $params = [];

            // 模組篩選
            if (!empty($filters['module'])) {
                $where[] = 'module = %s';
                $params[] = $filters['module'];
            }

            // 等級篩選
            if (!empty($filters['level'])) {
                $where[] = 'level = %s';
                $params[] = $filters['level'];
            }

            // 時間範圍篩選
            if (!empty($filters['date_from'])) {
                $where[] = 'created_at >= %s';
                $params[] = $filters['date_from'];
            }

            if (!empty($filters['date_to'])) {
                $where[] = 'created_at <= %s';
                $params[] = $filters['date_to'];
            }

            // 搜尋篩選
            if (!empty($filters['search'])) {
                $where[] = '(message LIKE %s OR data LIKE %s)';
                $searchTerm = '%' . $wpdb->esc_like($filters['search']) . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            $whereClause = implode(' AND ', $where);
            
            $sql = "SELECT * FROM {$this->logTable} WHERE {$whereClause} ORDER BY created_at DESC LIMIT %d";
            $params[] = $limit;

            if (!empty($params)) {
                $sql = $wpdb->prepare($sql, ...$params);
            }

            $results = $wpdb->get_results($sql, ARRAY_A);

            // 解析 JSON 資料
            foreach ($results as &$result) {
                if (!empty($result['data'])) {
                    $result['data'] = json_decode($result['data'], true);
                }
            }

            return $results;

        } catch (\Exception $e) {
            error_log('DebugService::getLogs 失敗: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 清理舊日誌
     * 
     * @param int $days 保留天數
     * @return int 清理的筆數
     */
    public function cleanOldLogs(int $days = null): int
    {
        if ($days === null) {
            $days = $this->maxLogAge;
        }

        try {
            global $wpdb;

            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            
            $deletedCount = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->logTable} WHERE created_at < %s",
                $cutoffDate
            ));

            // 清理檔案日誌（保留最近的部分）
            $this->rotateLogFile();

            $this->log('DebugService', "清理舊日誌完成", [
                'deleted_count' => $deletedCount,
                'cutoff_date' => $cutoffDate,
                'retention_days' => $days
            ]);

            return $deletedCount;

        } catch (\Exception $e) {
            error_log('DebugService::cleanOldLogs 失敗: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 取得除錯統計
     * 
     * @param int $days 統計天數
     * @return array
     */
    public function getDebugStats(int $days = 7): array
    {
        try {
            global $wpdb;

            $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            // 按等級統計
            $levelStats = $wpdb->get_results($wpdb->prepare("
                SELECT level, COUNT(*) as count 
                FROM {$this->logTable} 
                WHERE created_at >= %s 
                GROUP BY level
            ", $startDate), ARRAY_A);

            // 按模組統計
            $moduleStats = $wpdb->get_results($wpdb->prepare("
                SELECT module, COUNT(*) as count 
                FROM {$this->logTable} 
                WHERE created_at >= %s 
                GROUP BY module 
                ORDER BY count DESC 
                LIMIT 10
            ", $startDate), ARRAY_A);

            // 按日期統計
            $dailyStats = $wpdb->get_results($wpdb->prepare("
                SELECT DATE(created_at) as date, COUNT(*) as count 
                FROM {$this->logTable} 
                WHERE created_at >= %s 
                GROUP BY DATE(created_at) 
                ORDER BY date DESC
            ", $startDate), ARRAY_A);

            return [
                'level_stats' => $levelStats,
                'module_stats' => $moduleStats,
                'daily_stats' => $dailyStats,
                'total_logs' => array_sum(array_column($levelStats, 'count')),
                'period_days' => $days
            ];

        } catch (\Exception $e) {
            error_log('DebugService::getDebugStats 失敗: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 匯出除錯日誌
     * 
     * @param array $filters 篩選條件
     * @param string $format 格式 json|csv
     * @return string 檔案路徑
     */
    public function exportLogs(array $filters = [], string $format = 'json'): string
    {
        try {
            $logs = $this->getLogs($filters, 10000); // 最多匯出 10000 筆

            $filename = 'buygo-debug-export-' . date('Y-m-d-H-i-s') . '.' . $format;
            $filepath = WP_CONTENT_DIR . '/uploads/' . $filename;

            if ($format === 'json') {
                file_put_contents($filepath, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } elseif ($format === 'csv') {
                $this->exportToCsv($logs, $filepath);
            }

            return $filepath;

        } catch (\Exception $e) {
            error_log('DebugService::exportLogs 失敗: ' . $e->getMessage());
            throw new \Exception('匯出日誌失敗：' . $e->getMessage());
        }
    }

    /**
     * 記錄到檔案
     */
    private function logToFile(array $logEntry): void
    {
        try {
            $logLine = sprintf(
                "[%s] %s.%s: %s %s\n",
                $logEntry['timestamp'],
                strtoupper($logEntry['level']),
                $logEntry['module'],
                $logEntry['message'],
                !empty($logEntry['data']) ? json_encode($logEntry['data'], JSON_UNESCAPED_UNICODE) : ''
            );

            file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);

        } catch (\Exception $e) {
            error_log('DebugService::logToFile 失敗: ' . $e->getMessage());
        }
    }

    /**
     * 記錄到資料庫
     */
    private function logToDatabase(array $logEntry): void
    {
        try {
            global $wpdb;

            $wpdb->insert($this->logTable, [
                'level' => $logEntry['level'],
                'module' => $logEntry['module'],
                'message' => $logEntry['message'],
                'data' => !empty($logEntry['data']) ? wp_json_encode($logEntry['data']) : null,
                'user_id' => $logEntry['user_id'],
                'ip_address' => $logEntry['ip_address'],
                'user_agent' => $logEntry['user_agent'],
                'request_uri' => $logEntry['request_uri'],
                'request_method' => $logEntry['request_method'],
                'created_at' => $logEntry['timestamp']
            ]);

        } catch (\Exception $e) {
            error_log('DebugService::logToDatabase 失敗: ' . $e->getMessage());
        }
    }

    /**
     * 取得客戶端 IP
     */
    private function getClientIP(): string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * 輪轉日誌檔案
     */
    private function rotateLogFile(): void
    {
        try {
            if (file_exists($this->logFile) && filesize($this->logFile) > 10 * 1024 * 1024) { // 10MB
                $rotatedFile = $this->logFile . '.' . date('Y-m-d-H-i-s');
                rename($this->logFile, $rotatedFile);
                
                // 壓縮舊檔案
                if (function_exists('gzopen')) {
                    $this->compressLogFile($rotatedFile);
                }
            }
        } catch (\Exception $e) {
            error_log('DebugService::rotateLogFile 失敗: ' . $e->getMessage());
        }
    }

    /**
     * 壓縮日誌檔案
     */
    private function compressLogFile(string $filepath): void
    {
        try {
            $gzFile = $filepath . '.gz';
            $file = fopen($filepath, 'rb');
            $gz = gzopen($gzFile, 'wb9');
            
            while (!feof($file)) {
                gzwrite($gz, fread($file, 8192));
            }
            
            fclose($file);
            gzclose($gz);
            unlink($filepath);
            
        } catch (\Exception $e) {
            error_log('DebugService::compressLogFile 失敗: ' . $e->getMessage());
        }
    }

    /**
     * 匯出為 CSV
     */
    private function exportToCsv(array $logs, string $filepath): void
    {
        $file = fopen($filepath, 'w');
        
        // 寫入 BOM 以支援中文
        fwrite($file, "\xEF\xBB\xBF");
        
        // 寫入標題行
        fputcsv($file, ['時間', '等級', '模組', '訊息', '資料', '用戶ID', 'IP地址']);
        
        // 寫入資料
        foreach ($logs as $log) {
            fputcsv($file, [
                $log['created_at'],
                $log['level'],
                $log['module'],
                $log['message'],
                is_array($log['data']) ? json_encode($log['data'], JSON_UNESCAPED_UNICODE) : $log['data'],
                $log['user_id'],
                $log['ip_address']
            ]);
        }
        
        fclose($file);
    }
}