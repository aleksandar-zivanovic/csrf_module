<?php

namespace CSRFModule;

class TokenValidator
{
    use AddDatabaseAndLogger;
    use GetUserIdFromSession;
    use GetTokenFromSession;

    private ?TokenRepository $repository = null;
    private string $csrfToken;
    private int $userId;

    public function __construct(?Database $db = null, ?Logger $logger = null, ?Config $config = null)
    {
        $this->initDatabaseIndexAndLogger($db, $logger, $config);

        $this->repository = new TokenRepository($db, $logger, $this->config);
    }

    /** 
     * Checks if the token is timed out. Returns true if expired and false if not. 
     * @param int $timestamp The timestamp to check.
     * @return bool True if the token is timed out, false otherwise.
     */
    private function isTokenTimedOut(int $timestamp): bool
    {
        return $timestamp + $this->config->tokenExpirationTime <= time();
    }

    /**
     * Function checks if the token is valid for use. It checks if: 
     * - token from session is in valid format, 
     * - token from session exists in database, 
     * - the token belongs to the current user,
     * - token in database has status 'valid', 
     * - token token is expired.
     * Function returns true if the token is valid and false if is invalid
     * @throws \RuntimeException If updating the token status to 'expired' fails.
     * @see CSRF::tokenValidation()
     */
    public function validation(): bool
    {
        // Gets value of the token from session.
        try {
            $this->csrfToken = $this->getTokenFromSession();
        } catch (\OutOfRangeException $th) {
            return false;
        }

        // Fetches token data from the database
        $conditions = [['column' => 'token', 'operator' => '=', 'value' => $this->csrfToken]];
        $result = $this->repository->fetchTokenWithData($conditions);

        // Checks if a token exists in the database
        if ($result === null) return false;

        // Gets the first and only token record from the result that is a multidimensional array
        $tokenFromDb = $result[0];

        try {
            $this->userId = $this->getUserIdFromSession();
        } catch (\OutOfRangeException $th) {
            return false;
        }
        // Compare user's ID from session and from the database
        if ($this->userId !== $tokenFromDb['user_id']) return false;

        // Checks token status is valid (this is only if saving status is turned on)
        if ($this->config->saveCsrfStatus === true) {
            if ($tokenFromDb['status'] !== 'valid') return false;
        }

        // Check if token is timed out
        if ($this->isTokenTimedOut($tokenFromDb['timestamp'])) {
            if ($this->config->saveCsrfStatus === true) {
                if ($this->repository->changeStatus($tokenFromDb['id'], 'expired') === false) {
                    throw new \RuntimeException("Failed to update the token status to 'expired'.");
                };
                return false;
            }

            if ($this->config->saveCsrfStatus === false) {
                $this->repository->delete('token', $tokenFromDb['token']);
                return false;
            }
        }

        // Token is valid, so true is returned
        return true;
    }
}
