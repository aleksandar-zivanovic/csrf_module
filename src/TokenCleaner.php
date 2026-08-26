<?php

declare(strict_types=1);

namespace CSRFModule;

class TokenCleaner
{
    use AddDatabaseAndLogger;
    use GetUserIdFromSession;

    private ?TokenRepository $repository = null;

    public function __construct(?Database $db = null, ?Logger $logger = null, ?Config $config = null)
    {
        $this->initDatabaseIndexAndLogger($db, $logger, $config);

        $this->repository = new TokenRepository($db, $logger, $this->config);
    }

    /**
     * Deletes time-outed CSRF tokens based on timestamp or timestamp and user ID. Only users with administrative privileges can perform this action.
     * 
     * @param int|null $userId If provided, tokens belonging to the specified user will be processed.
     * 
     * @throws \LogicException If the user does not have administrative privileges.
     * @throws \InvalidArgumentException If the userId is invalid.
     * @throws \RuntimeException If an unexpected error occurs during the cleanup process.
     * @return bool Returns true if any token was deleted or false if no tokens were found.
     * @see CSRF::allTokensCleanUp()
     * @uses TokenRepository::delete()
     */
    public function cleanUpAll(?int $userId = null): bool
    {
        $this->getLogger()->logCleanup("Cleanup started by user with ID: " . $_SESSION[$this->config->userIdSessionKey] . ".");

        // Checks if the user has administrative privileges. Access is denied for non-admin users.
        if (!isset($_SESSION[$this->config->roleName]) || $_SESSION[$this->config->roleName] != $this->config->roleValue) {
            header('HTTP/1.1 403 Forbidden');
            $this->getLogger()->logCleanup("cleanUpAll metod error: Unauthorized access attempt.");
            throw new \LogicException("You do not have the required permissions.");
        }

        // Conditions used for filtering tokens for cleanup
        $arrayConditions = [];

        $timeLimit = time() - $this->config->tokenExpirationTime;
        $arrayConditions[] = ['column' => 'timestamp', 'operator' => '<=', 'value' => $timeLimit];

        // Cleans up by user's ID
        if (isset($userId)) {
            if (is_int($userId) && $userId >= 1) {
                $arrayConditions[] = ['column' => 'user_id', 'operator' => '=', 'value' => $userId];
            } else {
                $this->getLogger()->logInfo("cleanUpAll metod error: \$userId is not set or not valid.");
                throw new \InvalidArgumentException("Invalid argument value.");
            }
        }

        $result = $this->repository->fetchTokenWithData($arrayConditions);
        if ($result === null) {
            $this->getLogger()->logInfo("cleanUpAll method error: No tokens found.");
            return false;
        }

        // Gets ID's of timed out tokens
        $expiredTokens = [];
        foreach ($result as $token) {
            $expiredTokens[] = $token['id'];
        }

        // Deletes all time outed tokens
        if ($this->repository->delete('id', $expiredTokens)) {
            $this->getLogger()->logCleanup("Deleted 'expired' tokens.");
            return true;
        }

        // Something unpredicted happened
        $this->getLogger()->logCleanup("TokenCleaner::cleanUpAll(). Something unpredicted happened!");
        throw new \RuntimeException("TokenCleaner::cleanUpAll(). Something unpredicted happened!");
    }

    /**
     * Canceling user's token(s) during logout process, by deleting them or changing status to `expired`.
     * 
     * @param string $action Action 'delete' or 'update' depending what action you want to perform.
     * @throws \InvalidArgumentException If the action is not valid.
     * @throws \LogicException If saving status is not allowed.
     * @throws \OutOfRangeException If the user ID is not found in the session.
     * @throws \RuntimeException If a database operation fails.
     * @throws \LengthException If updating the token status is called with an empty ID.
     * @return bool Returns true if action is done or there are no tokens, otherwise false.
     * @uses TokenRepository::delete()
     * @uses TokenRepository::fetchTokenWithData()
     * @uses TokenRepository::changeStatus()
     * @see CSRF::logoutTokensCleanup()
     */
    public function logoutCleanUp(string $action): bool
    {
        if (!in_array($action, ['delete', 'update'])) {
            throw new \InvalidArgumentException("Invalid action. Allowed values are 'delete' or 'update'.");
        }

        if ($action === 'delete') {
            return $this->repository->delete('user_id', $this->getUserIdFromSession());
        }

        if ($action === 'update') {
            if ($this->config->saveCsrfStatus !== true) {
                throw new \LogicException("Saving status is not allowed! Read installation for enabling this feature");
            }

            $conditions = [
                [
                    'column' => 'user_id',
                    'operator' => '=',
                    'value' => $this->getUserIdFromSession()
                ]
            ];
            $usersAllTokens = $this->repository->fetchTokenWithData($conditions);

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

            return $this->repository->changeStatus($ids, 'expired');
        }
    }

    /**
     * Enforces a limit on the number of active tokens per user.
     * Deletes excess tokens if the limit is exceeded.
     *
     * @param int $userId The ID of the user to enforce the token limit for.
     * @throws \RuntimeException If deleting the excess tokens fails.
     * @throws \InvalidArgumentException If fetching the user's tokens fails validation.
     * @return void
     * @uses TokenRepository::fetchTokenWithData()
     * @uses TokenRepository::deleteUnsafe()
     * @see CSRF::enforceTokenLimit()
     */
    public function enforceLimit(int $userId): void
    {
        $conditions = [
            [
                'column' => 'user_id',
                'operator' => '=',
                'value' => $userId
            ]
        ];

        $usersTokens = $this->repository->fetchTokenWithData($conditions);

        if ($usersTokens !== null) {
            $totalTokens = count($usersTokens);
            if ($totalTokens >= $this->config->tokensPerUser) {
                $excessTokensCount = $totalTokens - $this->config->tokensPerUser;
                $expiredTokens = array_slice($usersTokens, 0, $excessTokensCount + 1);
                $tokensForRemoval = array_column($expiredTokens, 'token');

                if (!$this->repository->deleteUnsafe('token', $tokensForRemoval)) {
                    throw new \RuntimeException("Failed to delete excess tokens for user ID: $userId.");
                }
            }
        }
    }
}
