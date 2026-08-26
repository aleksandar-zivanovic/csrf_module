<?php

declare(strict_types=1);

namespace CSRFModule;

trait AddDatabaseAndLogger
{
    protected Config $config;
    protected ?Database $dbInstance = null;
    protected ?Logger $logger = null;

    protected function initDatabaseIndexAndLogger(?Database $db = null, ?Logger $logger = null, ?Config $config = null): void
    {
        $this->dbInstance = $db;
        $this->logger = $logger;
        $this->config = $config ?? new Config();
    }

    /**
     * Lazy-loads the database instance.
     *
     * @throws \RuntimeException If the database connection fails.
     * @return Database
     */
    protected function getDb(): Database
    {
        if ($this->dbInstance === null) {
            $this->dbInstance = new Database($this->config, $this->getLogger());
        }

        return $this->dbInstance;
    }

    /**
     * Lazy-loads the logger instance.
     *
     * @return Logger
     */
    protected function getLogger(): Logger
    {
        if ($this->logger === null) {
            $this->logger = new Logger();
        }

        return $this->logger;
    }
}
