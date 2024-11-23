<?php
require_once __DIR__ . '/Database.php';

class DatabaseSchemaManager 
{
    private ?Database $dbInstance = null;
    private array $dbIndexes = [
        'status' => INDEX_STATUS,
        'timestamp' => INDEX_TIMESTAMP,
        'status_timestamp' => INDEX_BOTH,
    ];

    private function getDb(): object 
    {
        if ($this->dbInstance === null) {
            $this->dbInstance = new Database();
        }

        return $this->dbInstance;
    }

    public function createTable(): bool
    {
        $query = "SHOW TABLES FROM " . DB_NAME . " LIKE 'csrf_tokens'";
        $stmt =  $this->getDb()->getDbh()->prepare($query);
        $stmt->execute();
        if ($stmt->rowCount() >= 1) {
            $this->getDb()->errorLog("createTable: Table already exists in database.");
            return false;
        }

        $sql = "CREATE TABLE IF NOT EXISTS csrf_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            token VARCHAR(255) UNIQUE NOT NULL, 
            timestamp INT NOT NULL, 
            user_id INT 
            ";

        if (SAVE_CSRF_STATUS === true) {
            $sql .= ", status ENUM('valid', 'used', 'expired') DEFAULT 'valid' ";
        }

        if ($this->dbIndexes['status'] === true) $sql .= ", INDEX idx_status (status)";
        if ($this->dbIndexes['timestamp'] === true) $sql .= ", INDEX idx_timestamp (timestamp)";
        if ($this->dbIndexes['status_timestamp'] === true) $sql .= ", INDEX idx_status_timestamp (status, timestamp)";

        $sql .= ");";
        
        try {
            $this->getDb()->getDbh()->exec($sql);
            $this->getDb()->errorLog("createTable() method success: Table created");
            return true;
        } catch (PDOException $e) {
            $this->getDb()->errorLog("createTable metod error: Creating databsae failed.", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            return false;
        }
    }
}