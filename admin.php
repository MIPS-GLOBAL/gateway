<?php
/**
 * Gateway Admin API
 * 
 * Provides admin endpoints for managing the gateway:
 * - View stats
 * - View/manage blocked IPs
 * - Manually block/unblock IPs
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/RateLimiter.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Authenticate admin request
$adminKey = $_SERVER['HTTP_X_ADMIN_KEY'] ?? $_GET['admin_key'] ?? '';
if ($adminKey !== ADMIN_API_KEY) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $db = new Database();
    $rateLimiter = new RateLimiter($db);
    
    $action = $_GET['action'] ?? 'stats';
    
    switch ($action) {
        case 'stats':
            // Get gateway statistics
            $stats = $rateLimiter->getStats();
            echo json_encode([
                'status' => true,
                'data' => $stats
            ]);
            break;
            
        case 'blocked':
            // List all blocked IPs
            $blockedIps = $rateLimiter->getBlockedIps();
            echo json_encode([
                'status' => true,
                'data' => $blockedIps
            ]);
            break;
            
        case 'block':
            // Manually block an IP
            $ip = $_POST['ip'] ?? $_GET['ip'] ?? '';
            $reason = $_POST['reason'] ?? $_GET['reason'] ?? 'manual_block';
            $permanent = ($_POST['permanent'] ?? $_GET['permanent'] ?? '0') === '1';
            
            if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
                http_response_code(400);
                echo json_encode(['status' => false, 'message' => 'Valid IP address required']);
                exit;
            }
            
            $rateLimiter->blockIp($ip, $reason, $permanent);
            echo json_encode([
                'status' => true,
                'message' => "IP {$ip} has been blocked"
            ]);
            break;
            
        case 'unblock':
            // Unblock an IP
            $ip = $_POST['ip'] ?? $_GET['ip'] ?? '';
            
            if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
                http_response_code(400);
                echo json_encode(['status' => false, 'message' => 'Valid IP address required']);
                exit;
            }
            
            $result = $rateLimiter->unblockIp($ip);
            echo json_encode([
                'status' => true,
                'message' => $result ? "IP {$ip} has been unblocked" : "IP {$ip} was not blocked"
            ]);
            break;
            
        case 'check':
            // Check rate limit status for an IP
            $ip = $_GET['ip'] ?? '';
            
            if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
                http_response_code(400);
                echo json_encode(['status' => false, 'message' => 'Valid IP address required']);
                exit;
            }
            
            echo json_encode([
                'status' => true,
                'data' => [
                    'ip' => $ip,
                    'is_blocked' => $rateLimiter->isBlocked($ip),
                    'request_count' => $rateLimiter->getRequestCount($ip),
                    'block_time_remaining' => $rateLimiter->getBlockTimeRemaining($ip),
                    'rate_limit' => RATE_LIMIT_REQUESTS
                ]
            ]);
            break;
            
        case 'logs':
            // Get recent request logs
            $limit = min(100, (int) ($_GET['limit'] ?? 50));
            $logs = $db->getAll(
                "SELECT * FROM `" . TABLE_REQUEST_LOGS . "` ORDER BY created_at DESC LIMIT ?",
                [$limit]
            );
            echo json_encode([
                'status' => true,
                'data' => $logs
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'status' => false,
                'message' => 'Unknown action',
                'available_actions' => ['stats', 'blocked', 'block', 'unblock', 'check', 'logs']
            ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
