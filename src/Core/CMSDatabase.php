<?php

declare(strict_types=1);

namespace PII\Core;

/**
 * CMSDatabase — Read-only PDO connection to the CMS MSSQL database.
 *
 * Mirrors the SDS system's CMS connection pattern: tries the sqlsrv
 * driver first (Windows), falls back to dblib (Linux/FreeTDS).
 * All queries against this connection should be read-only.
 */
class CMSDatabase
{
    private static ?self $instance = null;
    private \PDO $pdo;

    public function __construct(array $config)
    {
        $host = $config['host'];
        $port = $config['port'] ?? 1433;
        $name = $config['name'];

        if (in_array('sqlsrv', \PDO::getAvailableDrivers(), true)) {
            $dsn = sprintf('sqlsrv:Server=%s,%d;Database=%s', $host, $port, $name);
        } elseif (in_array('dblib', \PDO::getAvailableDrivers(), true)) {
            $dsn = sprintf('dblib:host=%s:%d;dbname=%s', $host, $port, $name);
        } else {
            throw new \RuntimeException(
                'No SQL Server PDO driver available. Install php_pdo_sqlsrv (Windows) or php_pdo_dblib (Linux).'
            );
        }

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        $this->pdo = new \PDO($dsn, $config['user'], $config['password'], $options);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $config = App::config('cms_db');
            if (!$config) {
                throw new \RuntimeException(
                    'CMS database not configured. Add a cms_db section to config/config.php.'
                );
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public static function isConfigured(): bool
    {
        $config = App::config('cms_db');
        return $config !== null
            && !empty($config['host'])
            && ($config['password'] ?? 'CHANGE_ME') !== 'CHANGE_ME';
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
