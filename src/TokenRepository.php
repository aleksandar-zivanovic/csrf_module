<?php

namespace CSRFModule;

class TokenRepository
{
    use AddDatabaseAndLogger;

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
     * @throws \InvalidArgumentException If the $status parameter is invalid.
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
                    $stmt->bindValue(":id{$key}", $value, \PDO::PARAM_INT);
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
            return false;
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
     * @return bool Returns true on success, false on failure.
     * @see CSRF::deleteToken()
     */
    public function delete(string $column, string|int|array $value): bool
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
                $this->getLogger()->logInfo("delete() method error:  rowCount() < 1");
                return false;
            }
            return true;
        } catch (\PDOException $e) {
            $this->getLogger()->logDatabaseError("delete() method error: execution() failed.", ["message" => $e->getMessage(), 'code' => $e->getCode()]);
            return false;
        }
    }
}
