# VaultPay API Gateway

A lightweight PHP API gateway that proxies requests from vaultpay-website to the VaultPay backend (usinguse.online/api) with rate limiting, IP blocking, and automatic API key injection.

## Features

- **Rate Limiting**: 100 requests per minute per IP address
- **IP Blocking**: Automatically blocks IPs that exceed rate limits (15 min block)
- **API Key Injection**: Automatically adds `api-key` header to all backend requests
- **Request Forwarding**: Proxies all HTTP methods (GET, POST, PUT, DELETE, PATCH)
- **Header Preservation**: Forwards relevant headers to backend
- **File Upload Support**: Handles multipart form data
- **Request Logging**: Optional logging of all requests
- **Admin API**: Manage blocked IPs and view statistics
- **CORS Support**: Pre-configured for cross-origin requests

## Requirements

- PHP 7.4+
- MySQL 5.7+ or MariaDB 10.3+
- Apache with mod_rewrite enabled
- cURL extension

## Quick Deployment

```bash
# 1. Clone to server
git clone <your-repo-url> /var/www/gateway

# 2. Configure database
cp config/database.php.example config/database.php
nano config/database.php  # Update credentials

# 3. Set permissions
chmod 755 logs/
chown -R www-data:www-data /var/www/gateway

# 4. Configure Apache virtual host (see below)
```

## Configuration

### `config/config.php`

```php
// Target backend URL
define('BACKEND_URL', 'https://usinguse.online/api');

// API Key injected into all backend requests
define('BACKEND_API_KEY', 'your-api-key-here');

// Rate limiting
define('RATE_LIMIT_REQUESTS', 100);     // Max requests per window
define('RATE_LIMIT_WINDOW', 60);        // Window in seconds (60 = 1 min)
define('BLOCK_DURATION_MINUTES', 15);   // Block duration

// Whitelisted IPs (bypass rate limiting)
define('WHITELISTED_IPS', ['127.0.0.1', '::1']);
```

### `config/database.php`

Copy from `database.php.example` and configure your MySQL credentials.

## Apache Virtual Host Example

```apache
<VirtualHost *:80>
    ServerName sandbox.vaultpay.org
    DocumentRoot /home/sandbox/public_html/gateway
    
    <Directory /home/sandbox/public_html/gateway>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/gateway_error.log
    CustomLog ${APACHE_LOG_DIR}/gateway_access.log combined
</VirtualHost>
```

## Usage

All requests to the gateway are forwarded to the backend with the API key automatically injected.

### Example:
```
GET https://sandbox.vaultpay.org/users
→ Forwarded to: https://usinguse.online/api/users
→ With header: api-key: <configured-key>
```

## Admin API

### Authentication
```
X-Admin-Key: vaultpay-gateway-admin-2024
```

### Endpoints

| Action | Method | Endpoint |
|--------|--------|----------|
| Stats | GET | `/admin.php?action=stats` |
| List Blocked | GET | `/admin.php?action=blocked` |
| Block IP | POST | `/admin.php?action=block` |
| Unblock IP | POST | `/admin.php?action=unblock` |

## Rate Limit Response

```json
{
    "status": false,
    "message": "Rate limit exceeded. Maximum 100 requests per minute allowed.",
    "error_code": "RATE_LIMIT_EXCEEDED",
    "retry_after": 900
}
```

## Security

1. **Never commit** `config/database.php` (it's gitignored)
2. Change `ADMIN_API_KEY` in production
3. Use HTTPS in production
4. The `.htaccess` protects config/core/logs directories
