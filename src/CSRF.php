<?php

namespace CSRFModule;

class CSRF
{
    use GetUserIdFromSession;

    private ?Config $config = null;
    private ?TokenRepository $repository = null;
    private ?TokenCleaner $cleaner = null;
    private ?TokenValidator $validator = null;
    private ?TokenGenerator $generator = null;
    public string $csrfToken;
    public int $timestamp;
    public int $userId;

    public function __construct(?Database $db = null, ?Logger $logger = null, ?Config $config = null)
    {
        $this->config = $config ?? new Config();
        $this->repository = new TokenRepository($db, $logger, $this->config);
        $this->cleaner = new TokenCleaner($db, $logger, $this->config);
        $this->validator = new TokenValidator($db, $logger, $this->config);
        $this->generator = new TokenGenerator();
    }

    /**
     * Generates CSRF token and adds data to the database
     * @return void
     * @uses TokenGenerator::generate()
     * @uses TokenRepository::save()
     */
    public function generateAndSaveCsrfToken(): void
    {
        $this->csrfToken = $this->generator->generate();

        // Sets timestamp value
        $this->timestamp = time();

        // Sets $this->userId value from session 
        $this->userId = $this->getUserIdFromSession();
        if (!is_int($this->userId) || $this->userId <= 0) {
            throw new \UnexpectedValueException("User ID session value is missing or invalid.");
        }

        // Saves token data to the database
        $this->repository->save($this->csrfToken, $this->timestamp, $this->userId);
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
        return $this->validator->validation();
    }

    /**
     * Fetches one or more tokens and their data based on the provided conditions.
     * Throws an \InvalidArgumentException if the conditions parameter is invalid.
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
     * @uses TokenRepository::fetchTokenWithData()
     */
    public function getTokensWithData(?array $conditions = null): array|null
    {
        return $this->repository->fetchTokenWithData($conditions);
    }


    /**
     * Changes the status of a CSRF token.
     * 
     * This method updates the status of a token in the database.
     * Called only internally by other methods, which always provide a valid, database-sourced ID — no additional input validation is performed here.
     *
     * @param string|array $id The ID(s) of the token(s) to update.
     * @param string $status The new status to set.
     * @throws \InvalidArgumentException If $status is not one of the allowed status values.
     * @return bool True on success, false on failure.
     * @uses TokenRepository::changeStatus()
     */
    public function changeTokenStatus(string|array $id, string $status): bool
    {
        return $this->repository->changeStatus($id, $status);
    }

    /**
     * Deletes a CSRF token from the database.
     *
     * This method removes a token based on the specified column and value.
     *
     * @param string $column The name of a database's column to match against.
     * @param string|int|array $value The value(s) to match for deletion.
     * @return bool Returns true on success, false on failure.
     */
    public function deleteToken(string $column, string|int|array $value): bool
    {
        return $this->repository->delete($column, $value);
    }

    /**
     * Deletes time-outed CSRF tokens based on timestamp or timestamp and user ID. Only users with administrative privileges can perform this action.
     * 
     * @param int|null $userId If provided, tokens belonging to the specified user will be processed.
     * 
     * @throws \Exception If the user does not have administrative privileges.
     * @throws \InvalidArgumentException If the userId is invalid.
     * @return bool Returns true if any token was processed (deleted or updated), false if no tokens were found.
     * @uses TokenCleaner::cleanUpAll()
     */
    public function allTokensCleanUp(?int $userId = null): bool
    {
        return $this->cleaner->cleanUpAll($userId);
    }

    /**
     * Canceling user's token(s) during logout process, by deleting them or changing status to `expired`.
     * @param string $action Action 'delete' or 'update' depending what action you want to perform.
     * @return bool Returns true if action is done or there are no tokens, otherwise false.
     * @uses TokenCleaner::logoutCleanUp()
     */
    public function logoutTokensCleanup(string $action): bool
    {
        return $this->cleaner->logoutCleanUp($action);
    }
}
