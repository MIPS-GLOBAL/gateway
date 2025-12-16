<?php
/**
 * Gateway Configuration
 */

// Target backend URL
define('BACKEND_URL', 'https://usinguse.online/api');

// API Key to inject into all backend requests
define('BACKEND_API_KEY', 'mobix-7p342tybn653wnkh248532');

// Rate limiting settings
define('RATE_LIMIT_REQUESTS', 100);        // Max requests
define('RATE_LIMIT_WINDOW', 60);           // Per X seconds (60 = 1 minute)
define('BLOCK_DURATION_MINUTES', 15);       // Block duration in minutes

// Gateway settings
define('REQUEST_TIMEOUT', 30);              // Request timeout in seconds
define('CONNECT_TIMEOUT', 10);              // Connection timeout in seconds

// Logging
define('ENABLE_LOGGING', true);
define('LOG_PATH', __DIR__ . '/../logs/');

// Whitelisted IPs (bypass rate limiting)
define('WHITELISTED_IPS', [
    '127.0.0.1',
    '::1',
    // Add admin/monitoring IPs here
]);

// API Key for admin operations (optional)
define('ADMIN_API_KEY', 'vaultpay-gateway-admin-2024');
