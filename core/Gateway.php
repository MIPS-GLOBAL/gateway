<?php
/**
 * Gateway Class
 * 
 * Forwards requests to the backend (usinguse.online)
 * Handles all HTTP methods and preserves headers/body
 */

class Gateway
{
    private string $backendUrl;
    
    public function __construct()
    {
        $this->backendUrl = rtrim(BACKEND_URL, '/');
    }
    
    /**
     * Forward the current request to the backend
     */
    public function forward(): array
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = $_SERVER['REQUEST_URI'];
        $targetUrl = $this->backendUrl . $uri;
        
        // Initialize cURL
        $ch = curl_init();
        
        // Set URL
        curl_setopt($ch, CURLOPT_URL, $targetUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, REQUEST_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        // Set HTTP method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        // Forward headers
        $headers = $this->getForwardHeaders();
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // Forward body for POST, PUT, PATCH
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $body = $this->getRequestBody();
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        
        // Execute request
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $endTime = microtime(true);
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        // Handle cURL errors
        if ($response === false) {
            return [
                'http_code' => 502,
                'headers' => ['Content-Type: application/json'],
                'body' => json_encode([
                    'status' => false,
                    'message' => 'Backend connection failed: ' . $error,
                    'error_code' => 'BACKEND_UNAVAILABLE'
                ])
            ];
        }
        
        // Parse response
        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);
        
        // Parse headers into array
        $headerLines = explode("\r\n", trim($responseHeaders));
        $parsedHeaders = [];
        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false) {
                $parsedHeaders[] = $line;
            }
        }
        
        // Log request (optional)
        if (ENABLE_LOGGING) {
            $this->logRequest($method, $uri, $httpCode, ($endTime - $startTime) * 1000);
        }
        
        return [
            'http_code' => $httpCode,
            'headers' => $parsedHeaders,
            'body' => $responseBody
        ];
    }
    
    /**
     * Get headers to forward to backend
     */
    private function getForwardHeaders(): array
    {
        $headers = [];
        $forwardHeaders = [
            'Content-Type',
            'Accept',
            'Accept-Language',
            'Authorization',
            'X-API-Key',
            'X-Requested-With',
            'User-Agent',
            'Cookie'
        ];
        
        foreach ($forwardHeaders as $header) {
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
            
            // Special case for Content-Type
            if ($header === 'Content-Type' && isset($_SERVER['CONTENT_TYPE'])) {
                $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
            } elseif (isset($_SERVER[$serverKey])) {
                $headers[] = $header . ': ' . $_SERVER[$serverKey];
            }
        }
        
        // Add X-Forwarded headers
        $clientIp = getClientIp();
        $headers[] = 'X-Forwarded-For: ' . $clientIp;
        $headers[] = 'X-Forwarded-Proto: ' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
        $headers[] = 'X-Real-IP: ' . $clientIp;
        
        // ALWAYS inject the backend API key
        $headers[] = 'api-key: ' . BACKEND_API_KEY;
        
        return $headers;
    }
    
    /**
     * Get request body
     * @return string|array - string for JSON/form-urlencoded, array for multipart with files
     */
    private function getRequestBody(): string|array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        // Handle multipart form data
        if (strpos($contentType, 'multipart/form-data') !== false) {
            // Check if there are any files
            $hasFiles = !empty($_FILES) && $this->hasValidFiles();
            
            if ($hasFiles) {
                // For multipart with files, use array format for CURLFile support
                return $this->buildMultipartBody();
            } else {
                // No files - convert to URL-encoded (backend expects this format)
                // If $_POST is empty, try to parse from raw input
                if (empty($_POST)) {
                    $postData = $this->parseMultipartFormData();
                    return http_build_query($postData);
                }
                return http_build_query($_POST);
            }
        }
        
        // Handle URL-encoded form data
        if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            return http_build_query($_POST);
        }
        
        // For JSON and other content types, return raw input
        return file_get_contents('php://input');
    }
    
    /**
     * Parse multipart form data from raw input when $_POST is empty
     */
    private function parseMultipartFormData(): array
    {
        $data = [];
        $rawInput = file_get_contents('php://input');
        
        if (empty($rawInput)) {
            return $data;
        }
        
        // Get boundary from content type
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (!preg_match('/boundary=(.*)$/i', $contentType, $matches)) {
            return $data;
        }
        
        $boundary = trim($matches[1], '"');
        
        // Split by boundary
        $parts = preg_split('/-+' . preg_quote($boundary) . '/', $rawInput);
        
        foreach ($parts as $part) {
            if (empty(trim($part)) || $part === '--') {
                continue;
            }
            
            // Split headers from content
            $segments = preg_split('/\r\n\r\n/', $part, 2);
            if (count($segments) !== 2) {
                continue;
            }
            
            $headers = $segments[0];
            $content = rtrim($segments[1], "\r\n");
            
            // Get field name
            if (preg_match('/name="([^"]+)"/', $headers, $nameMatch)) {
                $fieldName = $nameMatch[1];
                
                // Skip if it's a file (has filename)
                if (strpos($headers, 'filename=') === false) {
                    $data[$fieldName] = $content;
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Check if there are valid uploaded files
     */
    private function hasValidFiles(): bool
    {
        foreach ($_FILES as $file) {
            if (is_array($file['error'])) {
                foreach ($file['error'] as $error) {
                    if ($error === UPLOAD_ERR_OK) {
                        return true;
                    }
                }
            } else {
                if ($file['error'] === UPLOAD_ERR_OK) {
                    return true;
                }
            }
        }
        return false;
    }
    
    /**
     * Build multipart form body (for file uploads)
     * @return array - array format required for CURLFile support
     */
    private function buildMultipartBody(): array
    {
        // For multipart requests with files, use CURLFile
        $postData = [];
        
        // Add POST fields
        foreach ($_POST as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $postData[$key . '[' . $k . ']'] = $v;
                }
            } else {
                $postData[$key] = $value;
            }
        }
        
        // Add files
        foreach ($_FILES as $key => $file) {
            if (is_array($file['name'])) {
                // Multiple files
                foreach ($file['name'] as $i => $name) {
                    if ($file['error'][$i] === UPLOAD_ERR_OK) {
                        $postData[$key . '[' . $i . ']'] = new CURLFile(
                            $file['tmp_name'][$i],
                            $file['type'][$i],
                            $name
                        );
                    }
                }
            } else {
                // Single file
                if ($file['error'] === UPLOAD_ERR_OK) {
                    $postData[$key] = new CURLFile(
                        $file['tmp_name'],
                        $file['type'],
                        $file['name']
                    );
                }
            }
        }
        
        return $postData;
    }
    
    /**
     * Log request for debugging
     */
    private function logRequest(string $method, string $uri, int $httpCode, float $responseTimeMs): void
    {
        global $db;
        
        try {
            if (isset($db) && $db instanceof Database) {
                $db->insert(TABLE_REQUEST_LOGS, [
                    'ip_address' => getClientIp(),
                    'method' => $method,
                    'uri' => substr($uri, 0, 2048),
                    'response_code' => $httpCode,
                    'response_time_ms' => (int) $responseTimeMs
                ]);
            }
        } catch (Exception $e) {
            // Silently fail - logging shouldn't break the gateway
        }
    }
}
