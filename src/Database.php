<?php
namespace CSRFModule;

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'autoload.php';

class Database 
{
    protected $dbh;
    
    public function __construct(
        protected string $user = DB_USER, 
        protected ?string $password = DB_PASS, 
        protected string $host = DB_HOST, 
        protected string $dbName = DB_NAME
    )
    {
        $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->dbName;
        $options = [
            \PDO::ATTR_PERSISTENT => true,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
        ];

        try {
            $this->dbh = new \PDO($dsn, $this->user, $this->password, $options);
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