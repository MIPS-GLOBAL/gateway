<?php
/**
 * VaultPay API Gateway
 * 
 * A lightweight rate-limiting proxy gateway to usinguse.online
 * - Rate limit: 100 requests per minute per IP
 * - Blocks IPs that exceed the limit
 * - Forwards all requests to the main backend
 */

// Error reporting for development (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Load configuration
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/RateLimiter.php';
require_once __DIR__ . '/core/Gateway.php';

// CORS Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Key, Accept');
header('Access-Control-Max-Age: 86400');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Initialize database connection
    $db = new Database();
    
    // Initialize rate limiter
    $rateLimiter = new RateLimiter($db);
    
    // Get client IP
    $clientIp = getClientIp();
    
    // Check if IP is blocked
    if ($rateLimiter->isBlocked($clientIp)) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => false,
            'message' => 'Your IP has been temporarily blocked due to excessive requests. Please try again later.',
            'error_code' => 'RATE_LIMIT_EXCEEDED',
            'retry_after' => $rateLimiter->getBlockTimeRemaining($clientIp)
        ]);
        exit;
    }
    
    // Check rate limit
    if (!$rateLimiter->checkLimit($clientIp)) {
        // Block the IP
        $rateLimiter->blockIp($clientIp);
        
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => false,
            'message' => 'Rate limit exceeded. Maximum 100 requests per minute allowed.',
            'error_code' => 'RATE_LIMIT_EXCEEDED',
            'retry_after' => BLOCK_DURATION_MINUTES * 60
        ]);
        exit;
    }
    
    // Record the request
    $rateLimiter->recordRequest($clientIp);
    
    // Initialize gateway and forward request
    $gateway = new Gateway();
    $response = $gateway->forward();
    
    // Output response
    http_response_code($response['http_code']);
    
    // Forward response headers (excluding some that shouldn't be proxied)
    $excludeHeaders = ['transfer-encoding', 'content-encoding', 'connection'];
    foreach ($response['headers'] as $header) {
        $headerLower = strtolower($header);
        $shouldExclude = false;
        foreach ($excludeHeaders as $exclude) {
            if (strpos($headerLower, $exclude) === 0) {
                $shouldExclude = true;
                break;
            }
        }
        if (!$shouldExclude) {
            header($header);
        }
    }
    
    echo $response['body'];
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => false,
        'message' => 'Gateway error: ' . $e->getMessage(),
        'error_code' => 'GATEWAY_ERROR'
    ]);
}

/**
 * Get real client IP address
 */
function getClientIp(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_X_FORWARDED_FOR',      // Proxies
        'HTTP_X_REAL_IP',            // Nginx
        'HTTP_CLIENT_IP',            // Other proxies
        'REMOTE_ADDR'                // Direct connection
    ];
    
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            // Handle comma-separated IPs (X-Forwarded-For)
            if (strpos($ip, ',') !== false) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }
            // Validate IP
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    
    return '0.0.0.0';
}
