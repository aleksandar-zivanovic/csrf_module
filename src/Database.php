<?php

namespace CSRFModule;

class Database
{
    protected \PDO $dbh;
    protected Config $config;
    private Logger $logger;

    /**
     * Database constructor.
     * Initializes the database connection and logger.
     * @param Config|null $config
     * @param Logger|null $logger
     * 
     * @throws \RuntimeException if the database connection fails.
     */
    public function __construct(?Config $config = null, ?Logger $logger = null)
    {
        $this->config = $config ?? new Config();
        $this->logger = $logger ?? new Logger();

        $dsn = "mysql:host=" . $this->config->dbHost . ";dbname=" . $this->config->dbName;
        $options = [
            \PDO::ATTR_PERSISTENT => false,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
        ];

        try {
            $this->dbh = new \PDO($dsn, $this->config->dbUser, $this->config->dbPass, $options);
        } catch (\PDOException $e) {
            $this->logger->logDatabaseError("Error: not connected to the database!" . " | ", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            throw new \RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Returns the PDO database connection handler.
     * @return \PDO
     */
    public function getDbh(): \PDO
    {
        return $this->dbh;
    }
}
