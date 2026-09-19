<?php

declare(strict_types=1);

namespace CSRFModule\Tests;

use CSRFModule\DatabaseSchemaManager;
use PHPUnit\Framework\Attributes\DataProvider;

final class DatabaseSchemaManagerTest extends DatabaseTestCase
{
    private function manager(array $overrides = []): DatabaseSchemaManager
    {
        $_SESSION = ['role' => 'admin'];

        return new DatabaseSchemaManager($this->db, null, $this->config($overrides));
    }

    public function testRequiresAnAdminSession(): void
    {
        $this->connect();
        $_SESSION = ['role' => 'user'];

        $this->expectException(\LogicException::class);
        new DatabaseSchemaManager($this->db, null, $this->config());
    }

    public function testUsesTheConfiguredRoleNameAndValue(): void
    {
        $this->connect();
        $_SESSION = ['group' => 'superuser'];

        $manager = new DatabaseSchemaManager($this->db, null, $this->config(['roleName' => 'group', 'roleValue' => 'superuser']));

        $this->assertFalse($manager->checkIfTableExists());
    }

    public function testCreateTableWithTheStatusColumn(): void
    {
        $this->connect();
        $manager = $this->manager(['saveCsrfStatus' => true]);

        $this->assertTrue($manager->createTable());
        $this->assertTrue($manager->checkIfTableExists());
        $this->assertTrue($manager->doesColumnStatusExist());
    }

    public function testCreateTableWithoutTheStatusColumn(): void
    {
        $this->connect();
        $manager = $this->manager(['saveCsrfStatus' => false]);

        $manager->createTable();

        $this->assertFalse($manager->doesColumnStatusExist());
    }

    public function testCreateTableWithoutIndexFlagsAddsNoOptionalIndex(): void
    {
        $this->connect();
        $manager = $this->manager();

        $manager->createTable();

        $this->assertEqualsCanonicalizing(['PRIMARY', 'token'], array_values($manager->filterAllIndexes()));
    }

    public static function indexFlags(): array
    {
        return [
            'INDEX_STATUS' => ['indexStatus', 'status'],
            'INDEX_TIMESTAMP' => ['indexTimestamp', 'timestamp'],
            'INDEX_BOTH' => ['indexBoth', ['status', 'timestamp']],
            'INDEX_USER_ID' => ['indexUserId', 'user_id'],
        ];
    }

    #[DataProvider('indexFlags')]
    public function testCreateTableAddsTheConfiguredIndex(string $flag, string|array $column): void
    {
        $this->connect();
        $manager = $this->manager([$flag => true]);

        $manager->createTable();

        $this->assertTrue($manager->isIndexOnColumn($column));
    }

    public function testCreateTableFailsWhenTheTableExists(): void
    {
        $this->prepareTable();

        $this->expectException(\RuntimeException::class);
        $this->manager()->createTable();
    }

    public function testDeleteTableRemovesTheTable(): void
    {
        $this->prepareTable();
        $manager = $this->manager();

        $this->assertTrue($manager->deleteTable());
        $this->assertFalse($manager->checkIfTableExists());
    }

    public function testDeleteTableFailsWhenTheTableDoesNotExist(): void
    {
        $this->connect();

        $this->expectException(\RuntimeException::class);
        $this->manager()->deleteTable();
    }

    public function testAddStatusColumn(): void
    {
        $this->prepareTable(['saveCsrfStatus' => false]);
        $manager = $this->manager(['saveCsrfStatus' => true]);

        $this->assertTrue($manager->addStatusColumn());
        $this->assertTrue($manager->doesColumnStatusExist());
    }

    public function testAddStatusColumnRequiresSavingStatus(): void
    {
        $this->prepareTable(['saveCsrfStatus' => false]);

        $this->expectException(\LogicException::class);
        $this->manager(['saveCsrfStatus' => false])->addStatusColumn();
    }

    public function testAddStatusColumnFailsWhenTheColumnExists(): void
    {
        $this->prepareTable(['saveCsrfStatus' => true]);

        $this->expectException(\RuntimeException::class);
        $this->manager(['saveCsrfStatus' => true])->addStatusColumn();
    }

    public function testRemoveStatusColumn(): void
    {
        $this->prepareTable(['saveCsrfStatus' => true]);
        $manager = $this->manager(['saveCsrfStatus' => false]);

        $this->assertTrue($manager->removeStatusColumn());
        $this->assertFalse($manager->doesColumnStatusExist());
    }

    public function testRemoveStatusColumnRequiresSavingStatusToBeOff(): void
    {
        $this->prepareTable(['saveCsrfStatus' => true]);

        $this->expectException(\LogicException::class);
        $this->manager(['saveCsrfStatus' => true])->removeStatusColumn();
    }

    public function testRemoveStatusColumnFailsWhenTheColumnDoesNotExist(): void
    {
        $this->prepareTable(['saveCsrfStatus' => false]);

        $this->expectException(\RuntimeException::class);
        $this->manager(['saveCsrfStatus' => false])->removeStatusColumn();
    }

    public static function indexColumns(): array
    {
        return [
            'status' => ['status'],
            'timestamp' => ['timestamp'],
            'user_id' => ['user_id'],
            'status and timestamp' => [['status', 'timestamp']],
            'timestamp and status' => [['timestamp', 'status']],
        ];
    }

    #[DataProvider('indexColumns')]
    public function testAddIndex(string|array $column): void
    {
        $this->prepareTable();
        $manager = $this->manager();

        $this->assertTrue($manager->addIndex($column));
        $this->assertTrue($manager->isIndexOnColumn($column));
    }

    #[DataProvider('indexColumns')]
    public function testAddIndexFailsWhenTheIndexExists(string|array $column): void
    {
        $this->prepareTable();
        $manager = $this->manager();
        $manager->addIndex($column);

        $this->expectException(\RuntimeException::class);
        $manager->addIndex($column);
    }

    #[DataProvider('indexColumns')]
    public function testRemoveIndex(string|array $column): void
    {
        $this->prepareTable();
        $manager = $this->manager();
        $manager->addIndex($column);

        $this->assertTrue($manager->removeIndex($column));
        $this->assertFalse($manager->isIndexOnColumn($column));
    }

    #[DataProvider('indexColumns')]
    public function testRemoveIndexFailsWhenTheIndexDoesNotExist(string|array $column): void
    {
        $this->prepareTable();

        $this->expectException(\RuntimeException::class);
        $this->manager()->removeIndex($column);
    }

    public static function disallowedIndexColumns(): array
    {
        return [
            'token' => ['token'],
            'id' => ['id'],
            'single column array' => [['status']],
            'status and user_id' => [['status', 'user_id']],
        ];
    }

    #[DataProvider('disallowedIndexColumns')]
    public function testAddIndexRejectsDisallowedColumns(string|array $column): void
    {
        $this->prepareTable();

        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->addIndex($column);
    }

    #[DataProvider('disallowedIndexColumns')]
    public function testRemoveIndexRejectsDisallowedColumns(string|array $column): void
    {
        $this->prepareTable();

        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->removeIndex($column);
    }
}
