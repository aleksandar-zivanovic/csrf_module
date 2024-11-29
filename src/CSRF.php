<?php
require_once __DIR__ . '/Database.php';

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
 * deleteAllTokensByIdArray(array $ids): bool                     - deletes tokens by criteria
 * allTokensCleanUp(bool $timestamp,string|array|null $status, ?int $userId) - cleans up tokens
 * 
 */

class CSRF
{
    public string $csrfToken;
    public int $timestamp;
    public int $userId;
    private ?Database $dbInstance = null;

    // Connects to the database
    private function getDb(): object
    {
        if ($this->dbInstance === null) {
            $this->dbInstance = new Database();
        }

        return $this->dbInstance;
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
        if ($this->userId == 0) {
            echo "Error: User ID session key is missing or invalid.";
            die();
        }

        $db = $this->getDb()->getDbh();

        $query = "INSERT INTO csrf_tokens (token, timestamp, status, user_id) VALUES (:tk, :ts, :st, :ui)";
        $stmt =  $db->prepare($query);
        $stmt->bindValue(":tk", $this->csrfToken, PDO::PARAM_STR);
        $stmt->bindValue(":ts", $this->timestamp, PDO::PARAM_INT);
        $stmt->bindValue(":st", "valid", PDO::PARAM_STR);
        $stmt->bindValue(":ui", $this->userId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->rowCount() >= 1 ? 'success' : 'fail';
        if ($result == 'fail') {
            $this->getDb()->errorLog("generateAndSaveCsrfToken() method error: execution() failed");
        }

    }

    /**
     * Getting user's ID from session and setting userId property 
     * if the session value exists and is integer type
     */
    public function getUserIdFromSession(): int
    {
        if (!isset($_SESSION[USER_ID_SESSION_KEY]) || !is_int($_SESSION[USER_ID_SESSION_KEY])) {
            return 0;
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
        $tokenFromDb = $this->getTokensWithData($conditions);
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
                    echo "The token is expired and changing its status failed. Genearte new token";
                    die();
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
     * 
     * @return array|null Returns either a single token (associative array) or multiple tokens (multidimensional array). Null if no tokens are found.

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

        $stmt = $this->getDb()->getDbh()->prepare($query);

        // Binds values
        if ($conditions !== null) {
            foreach ($conditions as $condition) {
                $pdoBind = in_array($condition['column'], ['token', 'status']) ? PDO::PARAM_STR : PDO::PARAM_INT;
                $stmt->bindValue(":{$condition['column']}", $condition['value'], $pdoBind);
            }
        }

        try {
            $stmt->execute();
        } catch (PDOException $e) {
            $this->getDb()->errorLog("getTokensWithData error: SELECT query failed!", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
        }

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($result) === 1) {
            return $result[0];
        }

        return empty($result) ? null : $result;
    }


    // Changes token status
    public function changeTokenStatus(string|array $id, string $status): bool 
    {
        $db = $this->getDb()->getDbh();
        $query = "UPDATE csrf_tokens SET status = :st WHERE ";

        if (is_array($id)) {
            $stringOfIds = implode(", ", $id);
            $query .= " id IN (" . $stringOfIds . ")";
        }

        if (is_string($id)) {
            $query .= " id = :id";
        }
        
        $stmt = $db->prepare($query);
        $stmt->bindValue(":st", $status, PDO::PARAM_STR);
        if (is_string($id)) $stmt->bindValue(":id", $id, PDO::PARAM_STR);
        if (!$stmt->execute() || $stmt->rowCount() == 0) { 
            $this->getDb()->errorLog("changeTokenStatus() method error: execution() failed");
            return false;
        } else {
            return true;
        }
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

        $stmt = $db->getDbh()->prepare($query);
        if (!$stmt->execute() || $stmt->rowCount() < 1) {
            $this->dbInstance->errorLog("deleteToken() method error: execution() failed or rowCount() < 1");
            return false;
        } else {
            return true;
        }
    }

    /**
     * Deletes all expired tokens by ID values provided in array
     */
    public function deleteAllTokensByIdArray(array $ids): bool
    {
        return $this->deleteToken('id', $ids) ? true : false;
    }

    /**
     * Cleans up time-outed CSRF tokens based on timestamp, status, or both.
     * 
     * This method allows you to delete time outed tokens or update their status. 
     * It handles:
     * - Deleting tokens that have time outed based on their timestamp if the configuration 
     *   `SAVE_CSRF_STATUS` is set to false.
     * - Changing the status of time outed tokens from 'valid' to 'expired' if the configuration 
     *   `SAVE_CSRF_STATUS` is set to true.
     * 
     * @param bool $timestamp If set to true, tokens will be filtered based on their expiration time (calculated as current time - TOKEN_EXPIRATION_TIME constant).
     * @param string|array|null $status If provided, only tokens with the specified status will be processed.
     * @param int|null $userId If provided, tokens belonging to the specified user will be processed.
     * 
     * @return bool Returns true if any token was processed (deleted or updated), false if no tokens were found.
     * 
     */
    public function allTokensCleanUp(
        bool $timestamp = false, 
        string|array|null $status = null, 
        ?int $userId = null
        ): bool 
    {
        // Checks if the user has administrative privileges. Access is denied for non-admin users.
        // If an unauthorized access attempt is detected, an error is logged, and the method is terminated with an appropriate message.
        if (!isset($_SESSION[ROLE_NAME]) || $_SESSION[ROLE_NAME] != ROLE_VALUE) {
            $this->getDb()->errorLog("allTokensCleanUp metod error: Unauthorized access attempt.");
            die('You do not have the required permissions.');
        }

        // Provides only 'valid' status may be changed
        if (SAVE_CSRF_STATUS === true) {
            $status = 'valid';
        }

        $db = $this->getDb();
        $query = "SELECT * FROM csrf_tokens WHERE ";

        $bindStatus = $bindTimestamp = $bindUser = null;

        // Deletes by timestamp query set up
        // Returns rows with expired timestamps
        if ($timestamp != false) {
            $timeLimit = time() - TOKEN_EXPIRATION_TIME;
            $query .= "timestamp <= :tl";
            if ($status != null) {
                $query .= " AND ";
            }
            $bindTimestamp = true;
        }
        
        // Deletes by status query set up
        if ($status != null) {
            $query .= "status = :st";
            $bindStatus = true;
        }

        // Deletes by user's id query set up
        if (is_int($userId) && $userId >= 1) {
            if ($timestamp == false && $status == null) {
                $query .= "user_id = :ui";
                $bindUser = true;
            } else {
                die ("Invalid method call!");
            }
        }
        
        $stmt = $db->getDbh()->prepare($query);

        // Binds values
        if ($bindTimestamp === true) $stmt->bindValue(":tl", $timeLimit, PDO::PARAM_INT);
        if ($bindStatus === true) $stmt->bindValue(":st", $status, PDO::PARAM_STR);
        if ($bindUser === true) $stmt->bindValue(":ui", $userId, PDO::PARAM_INT);

        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Returns false if there are no expired tokens
        if (empty($result)) {
            return false;
        };

        // Gets ID's of timed out tokens
        $expiredTokens = [];
        foreach ($result as $token) {
            $expiredTokens[] = $token['id'];
        }

        // Deletes all time outed tokens
        if (SAVE_CSRF_STATUS === false) {
            $this->deleteAllTokensByIdArray($expiredTokens);
            return true;
        }
        
        // Changes status to 'expired' to all time outed tokens with current status 'valid'
        if (SAVE_CSRF_STATUS === true) {
            $this->changeTokenStatus($expiredTokens, 'expired');
            return true;
        }
        
        // Something unpredicted happened
        $this->dbInstance->errorLog("allTokensCleanUp() method <strong>error:</strong> something unpredicted happened!");
        return false;
    }
}