<?php

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

    protected function getDb(): Database
    {
        if ($this->dbInstance === null) {
            $this->dbInstance = new Database($this->config, $this->getLogger());
        }

        return $this->dbInstance;
    }

    // Makes instance of Logger class
    protected function getLogger(): Logger
    {
        if ($this->logger === null) {
            $this->logger = new Logger();
        }

        return $this->logger;
    }
}
