<?php

declare(strict_types=1);

namespace CSRFModule\Tests;

use CSRFModule\Database;

final class DatabaseTest extends DatabaseTestCase
{
    public function testConnectsWithAValidConfiguration(): void
    {
        $dbh = (new Database($this->config()))->getDbh();

        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $dbh->getAttribute(\PDO::ATTR_ERRMODE));
    }

    public function testFailedConnectionThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database connection failed');

        new Database($this->config(['dbName' => 'csrf_module_missing_database']));
    }

    public function testPersistentConnectionOption(): void
    {
        $dbh = (new Database($this->config(['dbPersistent' => true])))->getDbh();

        $this->assertTrue($dbh->getAttribute(\PDO::ATTR_PERSISTENT));
    }
}
