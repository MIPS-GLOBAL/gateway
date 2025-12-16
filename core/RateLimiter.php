<?php
/**
 * Rate Limiter Class
 * 
 * Implements sliding window rate limiting with IP blocking
 * - 100 requests per minute per IP
 * - Blocks IPs that exceed the limit
 */

class RateLimiter
{
    private Database $db;
    
    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->cleanupExpired();
    }
    
    /**
     * Check if IP is within rate limit
     */
    public function checkLimit(string $ip): bool
    {
        // Whitelisted IPs bypass rate limiting
        if (in_array($ip, WHITELISTED_IPS)) {
            return true;
        }
        
        $windowStart = date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW);
        
        // Get current request count for this IP
        $record = $this->db->getRow(
            "SELECT * FROM `" . TABLE_RATE_LIMITS . "` WHERE ip_address = ?",
            [$ip]
        );
        
        if (!$record) {
            // No record yet, allow
            return true;
        }
        
        // Check if window has expired
        if (strtotime($record->window_start) < strtotime($windowStart)) {
            // Window expired, reset counter
            $this->db->update(
                TABLE_RATE_LIMITS,
                [
                    'request_count' => 0,
                    'window_start' => date('Y-m-d H:i:s')
                ],
                'ip_address = ?',
                [$ip]
            );
            return true;
        }
        
        // Check if under limit
        return $record->request_count < RATE_LIMIT_REQUESTS;
    }
    
    /**
     * Record a request for an IP
     */
    public function recordRequest(string $ip): void
    {
        $record = $this->db->getRow(
            "SELECT * FROM `" . TABLE_RATE_LIMITS . "` WHERE ip_address = ?",
            [$ip]
        );
        
        if (!$record) {
            // Insert new record
            $this->db->insert(TABLE_RATE_LIMITS, [
                'ip_address' => $ip,
                'request_count' => 1,
                'window_start' => date('Y-m-d H:i:s')
            ]);
        } else {
            // Increment counter
            $this->db->query(
                "UPDATE `" . TABLE_RATE_LIMITS . "` SET request_count = request_count + 1 WHERE ip_address = ?",
                [$ip]
            );
        }
    }
    
    /**
     * Check if IP is blocked
     */
    public function isBlocked(string $ip): bool
    {
        // Whitelisted IPs are never blocked
        if (in_array($ip, WHITELISTED_IPS)) {
            return false;
        }
        
        $record = $this->db->getRow(
            "SELECT * FROM `" . TABLE_BLOCKED_IPS . "` WHERE ip_address = ? AND (expires_at > NOW() OR is_permanent = 1)",
            [$ip]
        );
        
        return $record !== null;
    }
    
    /**
     * Block an IP address
     */
    public function blockIp(string $ip, string $reason = 'rate_limit_exceeded', bool $permanent = false): void
    {
        $expiresAt = date('Y-m-d H:i:s', time() + (BLOCK_DURATION_MINUTES * 60));
        
        // Use INSERT ... ON DUPLICATE KEY UPDATE for upsert
        $this->db->query(
            "INSERT INTO `" . TABLE_BLOCKED_IPS . "` (ip_address, reason, blocked_at, expires_at, is_permanent) 
             VALUES (?, ?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), blocked_at = NOW(), expires_at = VALUES(expires_at), is_permanent = VALUES(is_permanent)",
            [$ip, $reason, $expiresAt, $permanent ? 1 : 0]
        );
        
        // Log the block
        if (ENABLE_LOGGING) {
            $this->logBlock($ip, $reason);
        }
    }
    
    /**
     * Unblock an IP address
     */
    public function unblockIp(string $ip): bool
    {
        $deleted = $this->db->delete(TABLE_BLOCKED_IPS, 'ip_address = ?', [$ip]);
        return $deleted > 0;
    }
    
    /**
     * Get remaining block time in seconds
     */
    public function getBlockTimeRemaining(string $ip): int
    {
        $record = $this->db->getRow(
            "SELECT expires_at, is_permanent FROM `" . TABLE_BLOCKED_IPS . "` WHERE ip_address = ?",
            [$ip]
        );
        
        if (!$record) {
            return 0;
        }
        
        if ($record->is_permanent) {
            return -1; // Permanent block
        }
        
        $remaining = strtotime($record->expires_at) - time();
        return max(0, $remaining);
    }
    
    /**
     * Get current request count for IP
     */
    public function getRequestCount(string $ip): int
    {
        $record = $this->db->getRow(
            "SELECT request_count FROM `" . TABLE_RATE_LIMITS . "` WHERE ip_address = ?",
            [$ip]
        );
        
        return $record ? (int) $record->request_count : 0;
    }
    
    /**
     * Cleanup expired blocks and old rate limit records
     */
    private function cleanupExpired(): void
    {
        // Remove expired blocks
        $this->db->delete(TABLE_BLOCKED_IPS, 'expires_at < NOW() AND is_permanent = 0', []);
        
        // Remove old rate limit records (older than 1 hour)
        $this->db->delete(
            TABLE_RATE_LIMITS, 
            'window_start < ?', 
            [date('Y-m-d H:i:s', time() - 3600)]
        );
    }
    
    /**
     * Log IP block event
     */
    private function logBlock(string $ip, string $reason): void
    {
        $logDir = LOG_PATH;
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . 'blocks_' . date('Y-m-d') . '.log';
        $logEntry = sprintf(
            "[%s] IP Blocked: %s | Reason: %s\n",
            date('Y-m-d H:i:s'),
            $ip,
            $reason
        );
        
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Get all currently blocked IPs (admin function)
     */
    public function getBlockedIps(): array
    {
        return $this->db->getAll(
            "SELECT * FROM `" . TABLE_BLOCKED_IPS . "` WHERE expires_at > NOW() OR is_permanent = 1 ORDER BY blocked_at DESC"
        );
    }
    
    /**
     * Get rate limit stats (admin function)
     */
    public function getStats(): array
    {
        $activeRequests = $this->db->getRow(
            "SELECT COUNT(*) as count, SUM(request_count) as total_requests FROM `" . TABLE_RATE_LIMITS . "` WHERE window_start > ?",
            [date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW)]
        );
        
        $blockedCount = $this->db->getRow(
            "SELECT COUNT(*) as count FROM `" . TABLE_BLOCKED_IPS . "` WHERE expires_at > NOW() OR is_permanent = 1"
        );
        
        return [
            'active_ips' => $activeRequests->count ?? 0,
            'total_requests_last_minute' => $activeRequests->total_requests ?? 0,
            'blocked_ips' => $blockedCount->count ?? 0,
            'rate_limit' => RATE_LIMIT_REQUESTS,
            'window_seconds' => RATE_LIMIT_WINDOW,
            'block_duration_minutes' => BLOCK_DURATION_MINUTES
        ];
    }
}
