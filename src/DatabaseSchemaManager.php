<?php
namespace CSRFModule;

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'autoload.php';

use CSRFModule\Database;
use CSRFModule\Logger;

// require_once __DIR__ . '/Database.php';
// require_once __DIR__ . '/Logger.php';

/**
 * All methods:
 * 
 * __construct()
 * getDb(): object                              - connects to Database
 * closeConnection(): void                      - closes connection to Database
 * closeAndReturn(bool $return)                 - closes connection to Database and returns $return
 * createTable(): bool                          - creates csrf_tokens table in Database
 * deleteTable(): bool                          - removes csrf_tokens table from Database
 * checkIfTableExists(): bool                   - checks if csrf_tokens table exists
 * doesColumnStatusExist():bool                 - checks if status column exists in csrf_tokens table
 * addStatusColumn(): bool                      - creates status column to csrf_tokens table
 * removeStatusColumn(): bool                   - removes status column from csrf_tokens table
 * addIndex(string|array $column): bool         - creates index(es) on csrf_tokens table
 * removeIndex(string|array $column): bool      - removes index(es) from csrf_tokens table
 * findAllIndexes(): array                      - shows existing indexes for csrf_tokens table
 * filterAllIndexes(): array                    - filters indexes and returns unique index names
 * checkAllowedColumnsForIndex(string|array $column): bool   - checks if parameter for removeIndex is allowed
 * isIndexOnColumn(string|array $column): bool  - checks if there is an index on a column
 * 
 */

class DatabaseSchemaManager 
{
    private ?Database $dbInstance = null;
    private ?Logger $logger = null;
    private array $dbIndexes = [
        'status' => INDEX_STATUS,
        'timestamp' => INDEX_TIMESTAMP,
        'status_timestamp' => INDEX_BOTH,
    ];

    public function __construct()
    {
        if (!$this->isUserAdmin()) {
            throw new \LogicException("Access denied: This class is restricted to admin users.");
        }
    }

    private function getDb(): object 
    {
        if ($this->dbInstance === null) {
            $this->dbInstance = new Database();
        }

        return $this->dbInstance;
    }

    // Makes instance of Logger class
    private function getLogger(): object
    {
        if ($this->logger === null) {
            $this->logger = new Logger();
        }

        return $this->logger;
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
            $this->getLogger()->logInfo("createTable: Table already exists in database.");
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
            $this->getLogger()->logInfo("createTable method success: Table created");
            return $this->closeAndReturn(true);
        } catch (\PDOException $e) {
            $this->getLogger()->logDatabaseError("createTable metod error: Creating table failed.", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
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
            $this->getLogger()->logInfo("deleteTable metod error: Table doesn't exist.");
            throw new \RuntimeException("'csrf_tokens' table doesn't exist.");
            return $this->closeAndReturn(false);
        }

        $sql = "DROP TABLE csrf_tokens";

        try {
            $this->getDb()->getDbh()->exec($sql);
            $this->getLogger()->logInfo("deleteTable method success: Table deleted.");
            return $this->closeAndReturn(true);
        } catch (\PDOException $e) {
            $this->getLogger()->logDatabaseError("deleteTable metod error: Deleting table failed.", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
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

        try {
            $stmt =  $this->getDb()->getDbh()->prepare($query);
            $stmt->execute();
        } catch (\PDOException $e) {
            $this->getLogger()->logDatabaseError("checkIfTableExists error: failed!", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            throw new \RuntimeException("Failed to check if csrf_tokens table exists in the database.");
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Create status column to csrf_tokens table
     * SAVE_CSRF_STATUS must be set to `true` in csrf_config.php
     * Checks if the column exists and SAVE_CSRF_STATUS is set for saving status
     * @return bool Returns true status column is added, otherwise false.
     */
    public function addStatusColumn(): bool 
    {
        if (SAVE_CSRF_STATUS === false) {
            throw new \Exception("SAVE_CSRF_STATUS is set to false.");
        }

        // Checks if the column already exists
        if ($this->doesColumnStatusExist() === true) {
            throw new \Exception("`status` column already exists.");
        }

        $query = "ALTER TABLE csrf_tokens ADD COLUMN status ENUM('valid', 'used', 'expired') DEFAULT 'valid'";

        try {
            $this->getDb()->getDbh()->query($query);
            $this->getLogger()->logInfo("addStatusColumn success: Status column added to the table");
            return true;
        } catch (\PDOException $e) {
            $this->getLogger()->logDatabaseError("addStatusColumn error: Status column didn't to the table", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
        }

        return false;
    }

    /**
     * Removes status column to csrf_tokens table.
     * SAVE_CSRF_STATUS must be set to `false` in csrf_config.php
     * Checks if the column exists and SAVE_CSRF_STATUS is set for saving status.
     * @return bool Returns true status column is removed, otherwise false.
     */
    public function removeStatusColumn(): bool 
    {
        if (SAVE_CSRF_STATUS === true) {
            throw new \Exception("SAVE_CSRF_STATUS is set to true.");
        }

        // Checks if the column already exists
        if ($this->doesColumnStatusExist() === false) {
            throw new \Exception("`status` column doesn't exist.");
        }

        $query = "ALTER TABLE csrf_tokens DROP COLUMN status";

        try {
            $this->getDb()->getDbh()->query($query);
            $this->getLogger()->logInfo("removeStatusColumn success: Status column is removed.");
            return true;
        } catch (\PDOException $e) {
            $this->getLogger()->logDatabaseError("removeStatusColumn error: Status column isn't removed.", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
        }

        return false;
    }

    /**
     * Checks if status column exists in csrf_tokens table.
     * @return bool Returns true if status column exists, otherwise false.
     */
    public function doesColumnStatusExist(): bool 
    {
        $query = "DESCRIBE " . DB_NAME . ".csrf_tokens";
        $stmt = $this->getDb()->getDbh()->query($query);
        $table = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($table as $column) {
            if ($column['Field'] === 'status') {
                return true;
            }
        }
        return false;
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
            $this->getLogger()->logInfo("addIndex error: Index already exists.");
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
            $this->getLogger()->logInfo("addIndex success: Index added.");
            return true;
        } catch (\PDOException $e) {
            $this->getLogger()->logDatabaseError("addIndex error: Adding index for column" . $errorMsgColumns . " failed", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
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
        $this->checkAllowedColumnsForIndex($column);
        
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
            $this->getLogger()->logInfo("removeIndex success: Index $indexName is removed.");
            return true;
        } catch (\PDOException $e) {
            $this->getLogger()->logDatabaseError("removeIndex error: Removing index " . $indexName . " failed", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
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

        try {
            $stmt = $this->getDb()->getDbh()->prepare($sql);
            $stmt->execute();
        } catch (\PDOException $e) {
            $this->getLogger()->logDatabaseError("findAllIndexes error", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            throw new \RuntimeException("findAllIndexes method query execution failed");
        }

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
     * Alowed values: 'status', 'timestamp', ['status', 'timestamp'] and ['timestamp', 'status'].
     * @param string|array $column Value that should be checked.
     * @throws InvalidArgumentException If $column has a disallowed value.
     * @return void
     */
    public function checkAllowedColumnsForIndex(string|array $column): void 
    {
        $allowedArrays = [['status', 'timestamp'], ['timestamp', 'status']];
        $allowedStrings = ['status', 'timestamp'];
        if (is_array($column)) {
            if (!in_array($column, $allowedArrays, true)) {
                throw new \InvalidArgumentException("Invalid column array. Allowed values are ['status', 'timestamp'] or ['timestamp', 'status'].");
            }
        }elseif (!in_array($column, $allowedStrings, true)) {
            throw new \InvalidArgumentException("Invalid column value. Allowed values are 'status' and 'timestamp'.");
        }
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
            throw new \Exception("The value for \$column parameter is not allowed.");
        }

        foreach ($indexKeyNameValues as $value) {
            // Format the column name for comparison
            if (is_array($column)) {
                $formattedColumn = "idx_" . implode("_", $column);

                // Adjust specific column names to match index naming convention
                if ($formattedColumn === "idx_timestamp_status") {
                    $formattedColumn = "idx_status_timestamp";
                }
            } else {
                $formattedColumn = "idx_" . $column . "";
            }

            if ($formattedColumn === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the current user is admin by taking by comparing
     * values of $_SESSION[ROLE_NAME] and ROLE_VALUE.
     * @return bool Returns true if user is an Admin, otherwise false
     */
    private function isUserAdmin(): bool 
    {
        return $_SESSION[ROLE_NAME] ?? null === ROLE_VALUE;
    }
}