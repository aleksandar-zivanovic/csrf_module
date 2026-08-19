<?php

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
     * @throws \Exception If the user does not have administrative privileges.
     * @throws \InvalidArgumentException If the userId is invalid.
     * @return bool Returns true if any token was processed (deleted or updated), false if no tokens were found.
     * @see CSRF::allTokensCleanUp
     */
    public function cleanUpAll(?int $userId = null): bool
    {
        $this->getLogger()->logCleanup("Cleanup started by user with ID: " . $_SESSION[$this->config->userIdSessionKey] . ".");

        // Checks if the user has administrative privileges. Access is denied for non-admin users.
        if (!isset($_SESSION[$this->config->roleName]) || $_SESSION[$this->config->roleName] != $this->config->roleValue) {
            header('HTTP/1.1 403 Forbidden');
            $this->getLogger()->logCleanup("cleanUpAll metod error: Unauthorized access attempt.");
            throw new \Exception("You do not have the required permissions.");
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
        $this->getLogger()->logCleanup("Something unpredicted happened!");
        return false;
    }

    /**
     * Canceling user's token(s) during logout process, by deleting them or changing status to `expired`.
     * @param string $action Action 'delete' or 'update' depending what action you want to perform.
     * @return bool Returns true if action is done or there are no tokens, otherwise false.
     * @see CSRF::logoutTokensCleanup()
     */
    public function logoutCleanUp(string $action): bool
    {
        if (!in_array($action, ['delete', 'update'])) return false;

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
}
