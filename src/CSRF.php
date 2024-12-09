<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Logger.php';

/**
 * All methods:
 * 
 * getDb(): object                                                - connects to Database
 * generateAndSaveCsrfToken(): void                               - creates and saves token to Database
 * getUserIdFromSession(): int                                    - gets user ID from session
 * gettingTokenFromSession(): ?string                             - gets token from session
 * tokenValidation(): bool                                        - validates token
 * isTokenTimedOut(int $timestamp): bool                          - checks if token is time outed
 * getTokensWithData(?array $conditions = null): array|null       - retrives token(s) with data by criteria
 * changeTokenStatus(string|array $id, string $status): bool      - changes token status
 * deleteToken(string $column, string|int|array $value): bool     - deletes a token by criteria
 * allTokensCleanUp(bool $timestamp,string|array|null $status, ?int $userId) - cleans up tokens
 * 
 */

class CSRF
{
    public string $csrfToken;
    public int $timestamp;
    public int $userId;
    private array $allowedStatuses = ['valid', 'expired', 'used'];
    private ?Database $dbInstance = null;
    private ?Logger $logger = null;

    // Connects to the database
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

    // Generates CSRF token and adds data to the database
    public function generateAndSaveCsrfToken(): void  
    {
        $this->csrfToken = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $this->csrfToken;

        // Sets timestamp value
        $this->timestamp = time();

        // Sets $this->userId value from session 
        $this->userId = $this->getUserIdFromSession();
        if (!is_int($this->userId) || $this->userId <= 0) {
            throw new UnexpectedValueException("User ID session value is missing or invalid.");
        }

        $query = "INSERT INTO csrf_tokens (token, timestamp, status, user_id) VALUES (:tk, :ts, :st, :ui)";

        try {
            $stmt =  $this->getDb()->getDbh()->prepare($query);
            $stmt->bindValue(":tk", $this->csrfToken, PDO::PARAM_STR);
            $stmt->bindValue(":ts", $this->timestamp, PDO::PARAM_INT);
            $stmt->bindValue(":st", "valid", PDO::PARAM_STR);
            $stmt->bindValue(":ui", $this->userId, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            $this->getLogger()->logDatabaseError("generateAndSaveCsrfToken error: INSERT query failed!", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            throw new RuntimeException("generateAndSaveCsrfToken method query execution failed");
        }

        $result = $stmt->rowCount() >= 1 ? 'success' : 'fail';

        if ($result == 'fail') {
            $this->getLogger()->logInfo("generateAndSaveCsrfToken() method error: execution() failed");
        }
    }

    /**
     * Getting user's ID from session and setting userId property 
     * if the session value exists and is a postive integer
     */
    public function getUserIdFromSession(): int
    {
        if (!isset($_SESSION[USER_ID_SESSION_KEY]) || !is_int($_SESSION[USER_ID_SESSION_KEY]) || $_SESSION[USER_ID_SESSION_KEY] < 1) {
            throw new OutOfRangeException("User ID is not found in session or is not valid.");
        }
        
        return htmlspecialchars(trim($_SESSION[USER_ID_SESSION_KEY]));
    }

    /**
     * Function checks if the token is valid for use. It checks if: 
     * - token from session is in valid format, 
     * - token from session exists in database, 
     * - token in database has status 'valid', 
     * - token token is expired.
     * Funtion returns true if the token is valid and false if is invalid
     */
    public function tokenValidation(): bool
    {
        // Gets value of the token from session. 
        // gettingTokenFromSession() returns null if the token isn't set in session or is in a wrong format 
        $tokenFromSession = $this->gettingTokenFromSession();
        if ($tokenFromSession == null) return false;
        $this->csrfToken = $tokenFromSession;

        // Checks if a token is set in session
        if (empty($this->csrfToken)) return false;
        
        // Checks if a token exists in the database
        $conditions = [['column' => 'token', 'operator' => '=', 'value' => $this->csrfToken]];
        $tokenFromDb = $this->getTokensWithData($conditions)[0];
        if ($tokenFromDb === null) return false;

        // Compare user's ID from session and from the database
        $this->userId = $this->getUserIdFromSession();
        if ($this->userId != $tokenFromDb['user_id']) return false;

        // Checks token status is valid (this is only if saving status is turned on)
        if (SAVE_CSRF_STATUS === true) {
            if ($tokenFromDb['status'] !== 'valid') return false;
        }
        
        // Check if token is timed out
        if ($this->isTokenTimedOut($tokenFromDb['timestamp'])) {
            if (SAVE_CSRF_STATUS === true) {
                if ($this->changeTokenStatus($tokenFromDb['id'], 'expired') === false) {
                    throw new RuntimeException("Failed to update the token status to 'expired'.");
                };
                return false;
            }

            if (SAVE_CSRF_STATUS === false) {
                $this->deleteToken('id', $tokenFromDb['id']);
                return false;
            }
        }

        // Token is valid, so true is returned
        return true;
    }

    /**
     * Gets token from session. If there is token in session, 
     * function returns string, else returns null
     */
    public function gettingTokenFromSession(): ?string
    {
        if (isset($_SESSION['csrf_token']) && !empty($_SESSION['csrf_token'])) {
            $token = $_SESSION['csrf_token'];
            return preg_match('/^[a-f0-9]{64}$/', $token) ? $token : null;
        } else {
            return null;
        }
    }

    // Checks if the token is timed out. Returns true if expired and false if not.
    private function isTokenTimedOut(int $timestamp): bool 
    {
        return $timestamp + TOKEN_EXPIRATION_TIME <= time();
    }

    /**
     * Fetches one or more tokens and their data based on the provided conditions.
     * Throws an InvalidArgumentException if the conditions parameter is invalid.
     * 
     * @param array|null $conditions Default is null. 
     * Each condition must be an associative array with the keys:
     * - 'column' (string): The name of the column.
     * - 'operator' (string): The comparison operator (allowed: '=', '<=', '>=', '<', '>').
     * - 'value' (mixed): The value to compare against.
     * 
     * Example: 
     * [
     *  ['column' => 'status', 'operator' => '=', 'value' => 'valid'], 
     *  ['column' => 'user_id', 'operator' => '>=', 'value' => 123]
     * ]
     * 
     * @return array|null Returns multidimensional array if record(s) are found, otherwise null.
     */
    public function getTokensWithData(?array $conditions = null): array|null
    {
        // Throws exception if $conditions is an empty array
        if ($conditions !== null && empty($conditions)) {
            throw new InvalidArgumentException("Conditions must not be empty array");
        }

        $query = "SELECT * FROM csrf_tokens";

        if ($conditions !== null) {
            $allowedColumns = ['id', 'token', 'timestamp', 'user_id', 'status'];
            $allowedOperators = ["=", "<=", ">=", "<", ">"];
            $whereClause = [];
            foreach ($conditions as $condition) {
                // Checks if the column is not allowed
                if (!in_array($condition['column'], $allowedColumns)) {
                    throw new InvalidArgumentException("Column value in the array is not allowed");
                }

                // Checks if the operator is not allowed
                if (!in_array($condition['operator'], $allowedOperators)) {
                    throw new InvalidArgumentException("Operator value in the array is not allowed");
                }

                // Checks if value type is not allowed
                if (!is_string($condition['value']) && !is_int($condition['value'])) {
                    throw new InvalidArgumentException("Value of value in the array is not allowed type");
                }

                // Checks if $conditions['column'] or $conditions['value'] are null
                if ($condition['column'] === null || $condition['value'] === null) {
                    throw new InvalidArgumentException("Elements in array must not be null");
                }

                // Generates where clause
                $whereClause[] = "{$condition['column']} {$condition['operator']} :{$condition['column']}";
            }

            $query .= " WHERE " . implode(" AND ", $whereClause);
        }

        try {
            $stmt = $this->getDb()->getDbh()->prepare($query);

            // Binds values
            if ($conditions !== null) {
                foreach ($conditions as $condition) {
                    $pdoBind = in_array($condition['column'], ['token', 'status']) ? PDO::PARAM_STR : PDO::PARAM_INT;
                    $stmt->bindValue(":{$condition['column']}", $condition['value'], $pdoBind);
                }
            }

            $stmt->execute();
        } catch (PDOException $e) {
            $this->getLogger()->logDatabaseError("getTokensWithData error: SELECT query failed!", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            throw new RuntimeException("getTokensWithData method query execution failed");
        }

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return empty($result) ? null : $result;
    }


    // Changes token status
    public function changeTokenStatus(string|array $id, string $status): bool 
    {
        if (!in_array($status, $this->allowedStatuses)) {
            throw new InvalidArgumentException("Invalid argument value for status parameter.");
        }

        $query = "UPDATE csrf_tokens SET status = :st WHERE ";

        if (is_array($id)) {
            $placeholders = [];
            foreach ($id as $key => $value) {
                $placeholders[] = ":id{$key}";
            }

            $query .= " id IN (" . implode(", ", $placeholders) . ")";
        }

        if (is_string($id)) {
            $query .= " id = :id";
        }

        try {
            $stmt = $this->getDb()->getDbh()->prepare($query);

            if (is_array($id)) {
                foreach ($id as $key => $value) {
                    $stmt->bindValue(":id{$key}", $value, PDO::PARAM_INT);
                }
            }
            
            if (is_string($id)) {
                $stmt->bindValue(":id", $id, PDO::PARAM_STR);
            }

            $stmt->bindValue(":st", $status, PDO::PARAM_STR);
            $stmt->execute(); 
        } catch (PDOException $e) {
            $this->getLogger()->logDatabaseError("changeTokenStatus() method error: execution() failed.", [
                "message" => $e->getMessage(), 
                'code' => $e->getCode()
            ]);
            return false;
        }

        // Returns true if token status is changed
        if ($stmt->rowCount() > 0) {
            return true;
        }

        return false;
    }

    // Delete token or tokens by column name and value
    public function deleteToken(string $column, string|int|array $value): bool 
    {
        $db = $this->getDb();
        $query = "DELETE FROM csrf_tokens WHERE {$column} ";

        if (is_array($value)) {
            $stringOfValues = implode(", ", $value);
            $query .= "IN ($stringOfValues)";
        }

        if (is_string($value) || is_int($value)) {
            $query .= "= " . $value;
        }

        try {
            $stmt = $db->getDbh()->prepare($query);
            $stmt->execute();
            if ($stmt->rowCount() < 1) {
                $this->getLogger()->logInfo("deleteToken() method error:  rowCount() < 1");
                return false;
            }
            return true;
        } catch (PDOException $e) {
            $this->getLogger()->logDatabaseError("deleteToken() method error: execution() failed.", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            return false; 
        }
    }

    /**
     * Cleans up time-outed CSRF tokens based on timestamp, status, or both.
     * 
     * This method allows you to delete time outed tokens or update their status 'expired'. 
     * It handles:
     * - Deleting tokens that have time outed based on their timestamp if the configuration 'SAVE_CSRF_STATUS' is set to false.
     * - Changing the status of time outed tokens from 'valid' to 'expired' if the configuration 'SAVE_CSRF_STATUS' is set to true.
     * - Deletes outdated tokens or changes their status from 'valid' to 'expired' for tokens associated with a specific 'user_id'.
     * 
     * @param bool $timestamp If set to true, tokens will be filtered based on their expiration time (calculated as current time - TOKEN_EXPIRATION_TIME constant). By default is set to true.
     * @param int|null $userId If provided, tokens belonging to the specified user will be processed.
     * 
     * @return bool Returns true if any token was processed (deleted or updated), false if no tokens were found.
     */
    public function allTokensCleanUp(bool $timestamp = true, ?int $userId = null): bool 
    {
        $this->getLogger()->logCleanup("Cleanup started by user with ID: " . $_SESSION[USER_ID_SESSION_KEY] . ".");

        // Checks if the user has administrative privileges. Access is denied for non-admin users.
        if (!isset($_SESSION[ROLE_NAME]) || $_SESSION[ROLE_NAME] != ROLE_VALUE) {
            header('HTTP/1.1 403 Forbidden');
            $this->getLogger()->logCleanup("allTokensCleanUp metod error: Unauthorized access attempt.");
            throw new Exception("You do not have the required permissions.");
        }

        // Ensures token cleanup is performed based on either 'timestamp' or 'userId' or both. 
        // The combination 'timestamp = false' and 'userId = null' is invalid because it 
        // leaves no criteria for selecting tokens to clean. 
        if ($timestamp === false && $userId === null) {
            $this->getLogger()->logInfo("allTokensCleanUp metod error: The combination of 'timestamp = false' and 'userId = null' is not allowed.");
            throw new InvalidArgumentException("The combination of 'timestamp = false' and 'userId = null' is not allowed.");
        }

        $query = "SELECT * FROM csrf_tokens WHERE ";

        $bindStatus = $bindTimestamp = $bindUser = null;

        // Cleans up by timestamp
        if ($timestamp === true) {
            $timeLimit = time() - TOKEN_EXPIRATION_TIME;
            $query .= "timestamp <= :tl AND ";
            $bindTimestamp = true;
        }
        
        // Cleans up by status (saving status must be enabled)
        if (SAVE_CSRF_STATUS === true) {
            $query .= "status = :st AND ";
            $bindStatus = true;
        }

        // Cleans up by user's ID
        if (isset($userId)) {
            if (is_int($userId) && $userId >= 1) {
                $query .= "user_id = :ui";
                $bindUser = true;
            } else {
                $this->getLogger()->logInfo("allTokensCleanUp metod error: \$userId is not set or not valid.");
                throw new InvalidArgumentException("Invalid argument value.");
            }
        }

        // Removes string " AND " from the end of the query if it exists
        if (str_ends_with($query, " AND ")) {
            $query = rtrim($query, " AND ");
        }

        try {
            $stmt = $this->getDb()->getDbh()->prepare($query);

            // Binds values
            if ($bindTimestamp === true) $stmt->bindValue(":tl", $timeLimit, PDO::PARAM_INT);
            if ($bindStatus === true) $stmt->bindValue(":st", "valid", PDO::PARAM_STR);
            if ($bindUser === true) $stmt->bindValue(":ui", $userId, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            $this->getLogger()->logDatabaseError("allTokensCleanUp error: query execution failed!", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            throw new RuntimeException("allTokensCleanUp method query execution failed");
        }

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Returns false if there are no expired tokens
        if (empty($result)) {
            $this->getLogger()->logCleanup("Nothing to clean.");
            return false;
        };

        // Gets ID's of timed out tokens
        $expiredTokens = [];
        foreach ($result as $token) {
            $expiredTokens[] = $token['id'];
        }

        // Deletes all time outed tokens
        if (SAVE_CSRF_STATUS === false) {
            $this->deleteToken('id', $expiredTokens);
            $this->getLogger()->logCleanup("Deleted 'expired' tokens.");
            return true;
        }
        
        // Changes status to 'expired' to all time outed tokens with current status 'valid'
        if (SAVE_CSRF_STATUS === true) {
            $this->changeTokenStatus($expiredTokens, 'expired');
            $this->getLogger()->logCleanup("Changed status from 'valid' to 'expired' to all time outed tokens.");
            return true;
        }
        
        // Something unpredicted happened
        $this->getLogger()->logCleanup("Something unpredicted happened!");
        return false;
    }

    /**
     * Canceling user's token(s) during logout process, by deleting them or changing status to `expired`.
     * @param string $action Action 'delete' or 'update' depending what action you want to perform.
     * @return bool Returns true if action is done or there are no tokens, otherwise false.
     */
    public function logoutTokensCleanup(string $action): bool
    {
        if (!in_array($action, ['delete', 'update'])) return false;

        if ($action === 'delete') {
            return $this->deleteToken('user_id', $this->getUserIdFromSession());
        }

        if ($action === 'update') {
            if (SAVE_CSRF_STATUS !== true) {
                throw new LogicException("Saving status is not allowed! Read installation for enabling this feature");
            }

            $conditions = [
                [
                    'column' => 'user_id', 
                    'operator' => '=', 
                    'value' => $this->getUserIdFromSession()
                ]
            ];
            $usersAllTokens = $this->getTokensWithData($conditions);

            if ($usersAllTokens === null) {
                return true;
            }

            $ids = [];
            foreach ($usersAllTokens as $token) {
                if ($token['status'] === 'valid') {
                    $ids[] = $token['id'];
                }
            }

            if (empty($ids)) return true;

            return $this->changeTokenStatus($ids, 'expired');
        }
    }
}