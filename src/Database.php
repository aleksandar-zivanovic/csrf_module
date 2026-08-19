<?php
namespace CSRFModule;

class Database
{
    protected $dbh;
    protected Config $config;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();

        $dsn = "mysql:host=" . $this->config->dbHost . ";dbname=" . $this->config->dbName;
        $options = [
            \PDO::ATTR_PERSISTENT => true,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
        ];

        try {
            $this->dbh = new \PDO($dsn, $this->config->dbUser, $this->config->dbPass, $options);
        } catch(\PDOException $e) {
            require_once __DIR__ . '/Logger.php';
            $logger = new Logger();
            $logger ->logDatabaseError("Error: not connected to the database!" . " | ", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
        }
    }

    public function getDbh(): object
    {
        return $this->dbh;
    }
}