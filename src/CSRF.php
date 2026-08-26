<?php

declare(strict_types=1);

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
     * Generates CSRF token and adds data to the database.
     * Before generating a new token, it enforces the token limit for the user.
     * 
     * @throws \OutOfRangeException If the user ID is not found in the session.
     * @throws \RuntimeException If enforcing the token limit or saving the token fails.
     * @throws \InvalidArgumentException If enforcing the token limit fails validation.
     * @throws \UnexpectedValueException If the user ID session value is missing or invalid.
     * @return void
     * @uses TokenCleaner::enforceLimit()
     * @uses TokenGenerator::generate()
     * @uses TokenRepository::save()
     */
    public function generateAndSaveCsrfToken(): void
    {
        $this->userId = $this->getUserIdFromSession();

        // Enforce token limit for the user
        if ($this->config->tokensPerUser !== null) {
            $this->cleaner->enforceLimit($this->userId);
        }

        // Generate CSRF token
        $this->csrfToken = $this->generator->generate();

        // Sets timestamp value
        $this->timestamp = time();

        // Sets $this->userId value from session 
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
     * 
     * @throws \RuntimeException If updating the token status fails.
     * @throws \InvalidArgumentException If token validation fails due to invalid data.
     * @throws \LengthException If updating the token status is called with an empty ID.
     * @throws \LogicException If deleting an expired token is not permitted.
     * @throws \OutOfRangeException If the token used internally is not found in the session.
     * @return bool Returns true if the token is valid, false otherwise.
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
     * @throws \InvalidArgumentException If the conditions parameter is invalid.
     * @throws \RuntimeException If the query execution fails.
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
     * @param int|array $id The ID(s) of the token(s) to update.
     * @param string $status The new status to set.
     * @throws \InvalidArgumentException If $status is not one of the allowed status values.
     * @throws \LengthException If the $id parameter is empty.
     * @throws \RuntimeException If the database query fails.
     * @return bool True on success, false on failure.
     * @uses TokenRepository::changeStatus()
     */
    public function changeTokenStatus(int|array $id, string $status): bool
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
     * @throws \LogicException If the user is not an admin and the action is restricted.
     * @throws \OutOfRangeException If the session token used for verification is missing or invalid.
     * @throws \InvalidArgumentException If the column is invalid or the value is empty.
     * @throws \RuntimeException If the database query fails.
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
     * @throws \LogicException If the user does not have administrative privileges.
     * @throws \InvalidArgumentException If the userId is invalid.
     * @throws \RuntimeException If an unexpected error occurs during the cleanup process.
     * @return bool Returns true if any token was processed (deleted or updated), false if no tokens were found.
     * @uses TokenCleaner::cleanUpAll()
     */
    public function allTokensCleanUp(?int $userId = null): bool
    {
        return $this->cleaner->cleanUpAll($userId);
    }

    /**
     * Canceling user's token(s) during logout process, by deleting them or changing status to `expired`.
     * 
     * @param string $action Action 'delete' or 'update' depending what action you want to perform.
     * @throws \InvalidArgumentException If the action is not valid.
     * @throws \LogicException If saving status is not allowed.
     * @return bool Returns true if action is done or there are no tokens, otherwise false.
     * @uses TokenCleaner::logoutCleanUp()
     */
    public function logoutTokensCleanup(string $action): bool
    {
        return $this->cleaner->logoutCleanUp($action);
    }
}
