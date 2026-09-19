<?php

declare(strict_types=1);

namespace CSRFModule\Tests;

use CSRFModule\Config;
use CSRFModule\Database;
use CSRFModule\DatabaseSchemaManager;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected const TEST_DB_NAME = 'csrf_module_phpunit_test';

    private static ?\PDO $server = null;
    protected Database $db;

    public static function setUpBeforeClass(): void
    {
        $config = new Config();
        try {
            self::$server = new \PDO(
                "mysql:host={$config->dbHost}",
                $config->dbUser,
                $config->dbPass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            self::$server->exec('CREATE DATABASE IF NOT EXISTS ' . self::TEST_DB_NAME);
        } catch (\PDOException) {
            self::$server = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->exec('DROP DATABASE IF EXISTS ' . self::TEST_DB_NAME);
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null) {
            $this->markTestSkipped('MySQL is not available.');
        }
        $_SESSION = [];
    }

    // Tests must not depend on the developer's local csrf_config.php values, except the database credentials
    protected function config(array $overrides = []): Config
    {
        $defaults = [
            'saveCsrfStatus' => true,
            'dbName' => self::TEST_DB_NAME,
            'userIdSessionKey' => 'user_id',
            'tokenExpirationTime' => 3600,
            'roleName' => 'role',
            'roleValue' => 'admin',
            'indexTimestamp' => false,
            'indexStatus' => false,
            'indexBoth' => false,
            'indexUserId' => false,
            'dbPersistent' => false,
            'tokensPerUser' => 5,
        ];

        return new Config(...array_merge($defaults, $overrides));
    }

    // Connects to the test database and drops the csrf_tokens table
    protected function connect(array $overrides = []): Config
    {
        $config = $this->config($overrides);
        $this->db = new Database($config);
        $this->db->getDbh()->exec('DROP TABLE IF EXISTS csrf_tokens');

        return $config;
    }

    // Recreates the csrf_tokens table through the module's own schema manager
    protected function prepareTable(array $overrides = []): Config
    {
        $config = $this->connect($overrides);

        $_SESSION = ['role' => 'admin'];
        (new DatabaseSchemaManager($this->db, null, $config))->createTable();
        $_SESSION = [];

        return $config;
    }

    protected function asAdmin(): void
    {
        $_SESSION['role'] = 'admin';
    }

    protected function insertToken(int $userId, ?int $timestamp = null, ?string $status = null): string
    {
        $token = bin2hex(random_bytes(32));
        $timestamp ??= time();

        if ($status === null) {
            $this->db->getDbh()
                ->prepare('INSERT INTO csrf_tokens (token, timestamp, user_id) VALUES (?, ?, ?)')
                ->execute([$token, $timestamp, $userId]);
        } else {
            $this->db->getDbh()
                ->prepare('INSERT INTO csrf_tokens (token, timestamp, user_id, status) VALUES (?, ?, ?, ?)')
                ->execute([$token, $timestamp, $userId, $status]);
        }

        return $token;
    }

    protected function row(string $token): array|false
    {
        $stmt = $this->db->getDbh()->prepare('SELECT * FROM csrf_tokens WHERE token = ?');
        $stmt->execute([$token]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    protected function countTokens(): int
    {
        return (int) $this->db->getDbh()->query('SELECT COUNT(*) FROM csrf_tokens')->fetchColumn();
    }
}
