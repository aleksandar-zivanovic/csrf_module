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

    /**
     * Creates an index on a specific column(s) of the `csrf_tokens` table.
     * If an index already exists for the column(s), returns false.
     * If the index is successfully created, returns true.
     * @param string|array $column The column(s) on which the index should be created.
     * @return bool Returns true if the index was successfully created, false if it already exists or on error.
     */
    public function addIndex(string|array $column): bool  
    {
        // Checks if the column(s) is already indexed
        if ($this->isIndexOnColumn($column) === true) {
            $this->getDb()->errorLog("addIndex error: Index already exists.");
            return false;
        }

        $sql = "CREATE INDEX idx_";
        if (is_array($column)) {
            if (in_array('status', $column) && in_array('timestamp', $column)) {
                $sql .= "status_timestamp ON csrf_tokens (status, timestamp)";
                $errorMsgColumns = 's status & timestamp';
            }
        } else {
            $errorMsgColumns = " $column";
        }

        if ($column === 'status') {
            $sql .= "status ON csrf_tokens (status)";
        }

        if ($column === 'timestamp') {
            $sql .= "timestamp ON csrf_tokens (status)";
        }

        try {
            $this->getDb()->getDbh()->exec($sql);
            $this->getDb()->errorLog("addIndex success: Index added.");
            return true;
        } catch (PDOException $e) {
            $this->getDb()->errorLog("addIndex error: Adding index for column" . $errorMsgColumns . " failed", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            return false;
        }
    }

    /**
     * Removes index from csrf_token table.
     * If index doesn't exists returns false.
     * Logs result of removing index and return true on success and false on failure
     * @param string|array $column Column(s) for adding index on
     * @return bool Returns true for success, otherwise false
     */
    public function removeIndex(string|array $column): bool 
    {
        // Checks if parameter $column is allowed value
        if ($this->checkAllowedColumnsForIndex($column) === false) {
            return false;
        }
        
        // Makes qualified name of the new index
        $indexName = is_array($column) ? "idx_status_timestamp" : "idx_" . $column;

        // Gets all avaiable index names
        $allIndexes = $this->filterAllIndexes();

        // Checks if the index name already exists and returns false if exists
        if (!in_array($indexName, $allIndexes)) return false;

        $sql = "DROP INDEX {$indexName} ON csrf_tokens";
        
        try {
            $stmt = $this->getDb()->getDbh()->prepare($sql);
            $stmt->execute();
            $this->getDb()->errorLog("removeIndex success: Index $indexName is removed.");
            return true;
        } catch (PDOException $e) {
            $this->getDb()->errorLog("removeIndex error: Removing index " . $indexName . " failed", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            return false;
        }
    }

    /**
     * Fetches and returns all index data from the 'csrf_tokens' table 
     * as a multidimensional associative array.
     */
    public function findAllIndexes(): array
    {
        $sql = "SHOW INDEXES FROM csrf_tokens";
        $stmt = $this->getDb()->getDbh()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieves and filters unique index names from the 'Key_name' column.
     * Removes duplicate values and returns an array of unique index names.
     * @return array Returns an array of unique values for index 'Key_name' column.
     */
    public function filterAllIndexes(): array 
    {
        $indexesDetails = $this->findAllIndexes();
        return array_unique(array_column($indexesDetails, 'Key_name'));
    }

    /**
     * Checks if column/columns parameter has allowed value.
     * Alowed values: 'status', 'timestamp', ['status', 'timestamp'] and ['timestamp', 'status']
     * @param string|array $column Value that should be checked
     * @return bool Returns true if $column has allowed value, otherwise returns false
     */
    public function checkAllowedColumnsForIndex(string|array $column): bool 
    {
        $allowedArrays = [['status', 'timestamp'], ['timestamp', 'status']];
        $allowedStrings = ['status', 'timestamp'];
        if (is_array($column)) {
            return in_array($column, $allowedArrays, true);
        }

        return in_array($column, $allowedStrings, true);
    }

    /**
     * Checks if there is an index set for a certain column or columns.
     * Implements `checkAllowedColumnsForIndex` method to check if $column value is in allowed range
     * @param string|array $column Column or columns to check for existence inside index.
     * @return bool Returns true if an index exists for the column(s), otherwise false.
     * @throws Exception If the column value is not allowed.
     */
    public function isIndexOnColumn(string|array $column): bool 
    {
        $indexKeyNameValues = $this->filterAllIndexes();

        // Checks if the $column value is allowed value
        if ($this->checkAllowedColumnsForIndex($column) === false) {
            throw new Exception("The value for \$column parameter is not allowed.");
        }

        foreach ($indexKeyNameValues as $value) {
            // Format the column name for comparison
            if (is_array($column)) {
                $formattedColumn = implode("_", $column);

                // Adjust specific column names to match index naming convention
                if ($formattedColumn === "timestamp_status") {
                    $formattedColumn = "status_timestamp";
                }
            } else {
                $formattedColumn = "idx_" . $column . "";
            }

            if (str_contains(haystack: $value, needle: $formattedColumn)) return true;
        }

        return false;
    }
}