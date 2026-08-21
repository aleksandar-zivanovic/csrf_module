<?php

namespace CSRFModule;

class TokenRepository
{
    use AddDatabaseAndLogger;
    use IsUserAdmin;

    private array $allowedStatuses = ['valid', 'expired', 'used'];

    public function __construct(?Database $db = null, ?Logger $logger = null, ?Config $config = null)
    {
        $this->initDatabaseIndexAndLogger($db, $logger, $config);
    }

    /**
     * Saves a new CSRF token to the database.
     */
    public function save(string $csrfToken, string $timestamp, int $userId): void
    {
        $query = "INSERT INTO csrf_tokens (token, timestamp, status, user_id) VALUES (:tk, :ts, :st, :ui)";

        try {
            $stmt =  $this->getDb()->getDbh()->prepare($query);
            $stmt->bindValue(":tk", $csrfToken, \PDO::PARAM_STR);
            $stmt->bindValue(":ts", $timestamp, \PDO::PARAM_INT);
            $stmt->bindValue(":st", "valid", \PDO::PARAM_STR);
            $stmt->bindValue(":ui", $userId, \PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            $this->getLogger()->logDatabaseError("save() error: INSERT query failed!", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            throw new \RuntimeException("Saving token to database failed. Try again later.");
        }

        $result = $stmt->rowCount() >= 1 ? 'success' : 'fail';

        if ($result == 'fail') {
            $this->getLogger()->logInfo("save() method error: execution() failed");
        }
    }

    /**
     * Builds and executes a SELECT query against the `csrf_tokens` table, optionally filtered by conditions.
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
     * @return array|null Returns matching rows as an associative array, or null if none found.
     * @throws \InvalidArgumentException If a condition's empty, column, operator, or value is not allowed, or is null.
     * @throws \TypeError If $conditions is not an array of condition-arrays (e.g. a single flat associative array is passed directly).
     * @see CSRF::getTokensWithData() Public entry point that calls this method.
     */
    public function fetchTokenWithData(?array $conditions = null): array|null
    {
        // Throws exception if $conditions is an empty array
        if ($conditions !== null && empty($conditions)) {
            throw new \InvalidArgumentException("Conditions must not be empty array");
        }

        $query = "SELECT * FROM csrf_tokens";

        // Adds conditions to the query if they are provided
        if ($conditions !== null) {
            $allowedColumns = ['id', 'token', 'timestamp', 'user_id', 'status'];
            $allowedOperators = ["=", "<=", ">=", "<", ">"];
            $whereClause = [];
            foreach ($conditions as $condition) {
                // Checks if the condition is a multidimensional array
                if (!is_array($condition)) {
                    throw new \InvalidArgumentException("Conditions must be a multidimensional array — you provided a single-level array.");
                }
                // Checks if the column is not allowed
                if (!in_array($condition['column'], $allowedColumns)) {
                    throw new \InvalidArgumentException("Column value in the array is not allowed");
                }

                // Checks if the operator is not allowed
                if (!in_array($condition['operator'], $allowedOperators)) {
                    throw new \InvalidArgumentException("Operator value in the array is not allowed");
                }

                // Checks if value type is not allowed
                if (!is_string($condition['value']) && !is_int($condition['value'])) {
                    throw new \InvalidArgumentException("Value of value in the array is not allowed type");
                }

                // Checks if $conditions['column'] or $conditions['value'] are null
                if ($condition['column'] === null || $condition['value'] === null) {
                    throw new \InvalidArgumentException("Elements in array must not be null");
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
                    $PDOBind = in_array($condition['column'], ['token', 'status']) ? \PDO::PARAM_STR : \PDO::PARAM_INT;
                    $stmt->bindValue(":{$condition['column']}", $condition['value'], $PDOBind);
                }
            }

            $stmt->execute();
        } catch (\PDOException $e) {
            $this->getLogger()->logDatabaseError("fetchTokenWithData error: SELECT query failed!", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            throw new \RuntimeException("fetchTokenWithData method query execution failed");
        }

        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return empty($result) ? null : $result;
    }

    /**
     * Changes the status of a CSRF token.
     * This method updates the status of a token in the database.
     *
     * @param string|array $id The ID(s) of the token(s) to update.
     * @param string $status The new status to set.
     * @throws \LengthException If the $id parameter is empty.
     * @throws \InvalidArgumentException If the $status parameter is invalid or $id is associative array.
     * @throws \RuntimeException If the database query fails.
     * @return bool Returns true on success, false on failure.
     * @see CSRF::changeTokenStatus()
     */
    public function changeStatus(string|array $id, string $status): bool
    {
        if (empty($id)) {
            throw new \LengthException("ID parameter must not be empty.");
        }

        if (!in_array($status, $this->allowedStatuses)) {
            throw new \InvalidArgumentException("Invalid argument value for status parameter.");
        }

        if (is_array($id) && array_keys($id) !== range(0, count($id) - 1)) {
            throw new \InvalidArgumentException("ID array must be a sequential list, not associative.");
        }

        $query = "UPDATE csrf_tokens SET status = :st WHERE ";

        if (is_array($id)) {
            $i = 0;
            $placeholders = [];
            foreach ($id as $value) {
                $placeholders[] = ":id{$i}";
                $i++;
            }

            $query .= " id IN (" . implode(", ", $placeholders) . ")";
        }

        if (is_string($id)) {
            $query .= " id = :id";
        }

        try {
            $stmt = $this->getDb()->getDbh()->prepare($query);

            if (is_array($id)) {
                $i = 0;
                foreach ($id as $value) {
                    $stmt->bindValue(":id{$i}", $value, \PDO::PARAM_INT);
                    $i++;
                }
            }

            if (is_string($id)) {
                $stmt->bindValue(":id", $id, \PDO::PARAM_STR);
            }

            $stmt->bindValue(":st", $status, \PDO::PARAM_STR);
            $stmt->execute();
        } catch (\PDOException $e) {
            $this->getLogger()->logDatabaseError("changeTokenStatus() method error: execution() failed.", [
                "message" => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            throw new \RuntimeException("Updating token status in database failed.");
        }

        // Returns true if token status is changed
        return $stmt->rowCount() > 0;
    }

    /**
     * Deletes a CSRF token from the database.
     *
     * This method removes a token based on the specified column and value.
     *
     * @param string $column The name of a database's column to match against.
     * @param string|int|array $value The value(s) to match for deletion.
     * @throws \LogicException If the user is not an admin.
     * @throws \InvalidArgumentException If the $column is invalid or $value empty.
     * @throws \RuntimeException If the database query fails.
     * @return bool Returns true on success, false on failure.
     * @see CSRF::deleteToken()
     */
    public function delete(string $column, string|int|array $value): bool
    {
        // Check if user is an administrator
        if ($this->isUserAdmin() === false) {
            throw new \LogicException("Access denied: This action is restricted to admin users.");
        }

        // Check for allowed columns
        $allowedColumns = ['id', 'token', 'timestamp', 'user_id', 'status'];
        if (!in_array($column, $allowedColumns)) {
            throw new \InvalidArgumentException("Invalid column name.");
        }

        // Check for empty value
        if (is_string($value) && trim($value) === "" || is_array($value) && empty($value)) {
            throw new \InvalidArgumentException("Value parameter must not be empty.");
        }

        // Check for associative array
        if (is_array($value) && array_keys($value) !== range(0, count($value) - 1)) {
            throw new \InvalidArgumentException("Value array must be a sequential list, not associative.");
        }

        $db = $this->getDb();
        $query = "DELETE FROM csrf_tokens WHERE {$column} ";

        if (is_array($value)) {
            $placeholders = [];
            $i = 0;
            foreach ($value as $_) {
                $placeholders[] = ":{$column}_{$i}";
                $i++;
            }

            $query .= "IN (" . implode(", ", $placeholders) . ")";
        }

        if (is_string($value) || is_int($value)) {
            $query .= "= " . ":{$column}_0";
            $value = [$value];
        }

        try {
            $stmt = $db->getDbh()->prepare($query);

            $i = 0;
            foreach ($value as $singleValue) {
                if ($column === 'token' || $column === 'status') {
                    $stmt->bindValue(":{$column}_{$i}", $singleValue, \PDO::PARAM_STR);
                }

                if ($column === 'id' || $column === 'timestamp' || $column === 'user_id') {
                    $stmt->bindValue(":{$column}_{$i}", $singleValue, \PDO::PARAM_INT);
                }
                $i++;
            }

            $stmt->execute();
        } catch (\PDOException $e) {
            $this->getLogger()->logDatabaseError("delete() method error: execution() failed.", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            throw new \RuntimeException("Deleting token from the database failed.");
        }

        return $stmt->rowCount() > 0;
    }
}
