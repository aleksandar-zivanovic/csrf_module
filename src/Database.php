<?php
require_once __DIR__ . '/../config/csrf_config.php';

class Database 
{
    protected $dbh;
    
    public function __construct(
        protected string $user = "DB_USER", 
        protected ?string $password = DB_PASS, 
        protected string $host = DB_HOST, 
        protected string $dbName = DB_NAME
    )
    {
        $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->dbName;
        $options = [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ];

        try {
            $this->dbh = new PDO($dsn, $this->user, $this->password, $options);
        } catch(PDOException $e) {
            $this->errorLog("Error: not connected to the database!" . " | ", errorInfo: $e->getMessage());
        }
    }

    // writting down error log
    public function errorLog(string $message, array|string|null $errorInfo = null): void
    {
        // check if the directory exists and create if not
        $logDirectory = __DIR__ . '../../logs';
        if (!file_exists($logDirectory)) {
            mkdir($logDirectory, 0755, true);
        }

        // preparing final message
        if (!empty($errorInfo)) {
            $message .= is_array($errorInfo) ? " | PDO error: " . implode(", ", $errorInfo) : $errorInfo;
        }

        // writting down an error to the log file
        $logFile = $logDirectory . '/errors.log';
        $timestamp = date("Y-m-d H:i:s");
        error_log("[$timestamp]: $message" .  PHP_EOL, 3, $logFile);
    }

    public function getDbh(): object
    {
        return $this->dbh;
    }
}