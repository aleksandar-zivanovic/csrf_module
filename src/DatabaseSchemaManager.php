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

    /**
     * Closes the database connection by setting the instance to null.
     */
    private function closeConnection(): void
    {
        $this->dbInstance = null;
    }

    /**
     * Closes the database connection and returns the specified boolean value.
     * 
     * This method is useful for chaining connection closure with a success 
     * or failure indication.
     * 
     * @param bool $return The boolean value to be returned after closing the connection.
     * 
     * @return bool The same boolean value passed as the $return parameter.
     */
    private function closeAndReturn(bool $return): bool
    {
        $this->closeConnection();
        return $return;
    }

    /**
     * Creates the `csrf_tokens` table in the database.
     * 
     * This method checks if the table already exists before attempting to create it.
     * If the table exists, no action is taken, and the method returns `false`.
     * If the table does not exist, it creates the table with the required structure 
     * and optional columns or indexes based on the configuration constants.
     * 
     * @return bool Returns `true` if the table was successfully created, 
     * or `false` if it already exists or the creation failed.
     */
    public function createTable(): bool
    {
        if ($this->checkIfTableExists()) {
            $this->getDb()->errorLog("createTable: Table already exists in database.");
            return $this->closeAndReturn(false);
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
            $this->getDb()->errorLog("createTable method success: Table created");
            return $this->closeAndReturn(true);
        } catch (PDOException $e) {
            $this->getDb()->errorLog("createTable metod error: Creating table failed.", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            return $this->closeAndReturn(false);
        }
    }

    /**
     * Deletes the `csrf_tokens` table from the database.
     * 
     * This method first checks if the table exists in the database. If the table 
     * does not exist, the method returns `false` without performing any action.
     * If the table exists, it attempts to delete the table using a `DROP TABLE` query.
     * 
     * @return bool Returns `true` if the table was successfully deleted, 
     * or `false` if the table does not exist or the deletion failed.
     */
    public function deleteTable(): bool
    {
        if ($this->checkIfTableExists() == false) {
            $this->getDb()->errorLog("deleteTable metod error: Table doesn't exist.");
            return $this->closeAndReturn(false);
        }

        $sql = "DROP TABLE csrf_tokens";

        try {
            $this->getDb()->getDbh()->exec($sql);
            $this->getDb()->errorLog("deleteTable method success: Table deleted.");
            return $this->closeAndReturn(true);
        } catch (PDOException $e) {
            $this->getDb()->errorLog("deleteTable metod error: Deleting table failed.", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            return $this->closeAndReturn(false);
        }
    }

    /**
     * Checks if `csrf_tokens` table exists in database. 
     * If the table exists returns `true`, else `false` 
     */
    public function checkIfTableExists(): bool
    {
        $query = "SHOW TABLES FROM " . DB_NAME . " LIKE 'csrf_tokens'";
        $stmt =  $this->getDb()->getDbh()->prepare($query);
        $stmt->execute();

        return $stmt->rowCount() > 0 ? true : false;
    }
}