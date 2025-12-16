<?php
/**
 * Database Connection Class
 * Similar to CodeIgniter's database handling
 */

class Database
{
    private $pdo;
    private static $instance = null;
    
    public function __construct()
    {
        $this->connect();
        $this->ensureTables();
    }
    
    /**
     * Connect to database
     */
    private function connect(): void
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            
        } catch (PDOException $e) {
            // Try to create database if it doesn't exist
            $this->createDatabase();
        }
    }
    
    /**
     * Create database if not exists
     */
    private function createDatabase(): void
    {
        try {
            $dsn = sprintf('mysql:host=%s;charset=%s', DB_HOST, DB_CHARSET);
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            // Reconnect to the new database
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ]);
            
        } catch (PDOException $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Ensure required tables exist
     */
    private function ensureTables(): void
    {
        // Rate limits table - tracks requests per IP
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `" . TABLE_RATE_LIMITS . "` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `ip_address` VARCHAR(45) NOT NULL,
                `request_count` INT UNSIGNED DEFAULT 1,
                `window_start` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_ip` (`ip_address`),
                INDEX `idx_window` (`window_start`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Blocked IPs table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `" . TABLE_BLOCKED_IPS . "` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `ip_address` VARCHAR(45) NOT NULL,
                `reason` VARCHAR(255) DEFAULT 'rate_limit_exceeded',
                `blocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `expires_at` TIMESTAMP NOT NULL,
                `is_permanent` TINYINT(1) DEFAULT 0,
                UNIQUE KEY `unique_ip` (`ip_address`),
                INDEX `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Request logs table (optional, for debugging)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `" . TABLE_REQUEST_LOGS . "` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `ip_address` VARCHAR(45) NOT NULL,
                `method` VARCHAR(10) NOT NULL,
                `uri` VARCHAR(2048) NOT NULL,
                `response_code` INT UNSIGNED,
                `response_time_ms` INT UNSIGNED,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_ip` (`ip_address`),
                INDEX `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * Get PDO instance
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
    
    /**
     * Execute query with parameters
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Get single row
     */
    public function getRow(string $sql, array $params = []): ?object
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    /**
     * Get all rows
     */
    public function getAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Insert row
     */
    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, array_values($data));
        
        return (int) $this->pdo->lastInsertId();
    }
    
    /**
     * Update rows
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $sql = "UPDATE `{$table}` SET {$set} WHERE {$where}";
        
        $stmt = $this->query($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }
    
    /**
     * Delete rows
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = "DELETE FROM `{$table}` WHERE {$where}";
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
}
