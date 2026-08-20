<?php

namespace CSRFModule;

class TokenValidator
{
    use AddDatabaseAndLogger;
    use GetUserIdFromSession;

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
     * Gets token from session. If there is token in session, 
     * function returns string, else returns null
     * @return string|null The CSRF token from the session, or null if not set or invalid.
     * @see CSRF::generateAndSaveCsrfToken()
     */
    private function gettingTokenFromSession(): ?string
    {
        if (isset($_SESSION['csrf_token']) && !empty($_SESSION['csrf_token'])) {
            $token = $_SESSION['csrf_token'];
            return preg_match('/^[a-f0-9]{64}$/', $token) ? $token : null;
        } else {
            return null;
        }
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
        // gettingTokenFromSession() returns null if the token isn't set in session or is in a wrong format 
        $tokenFromSession = $this->gettingTokenFromSession();
        if ($tokenFromSession == null) return false;
        $this->csrfToken = $tokenFromSession;

        // Checks if a token is set in session
        if (empty($this->csrfToken)) return false;

        // Fetches token data from the database
        $conditions = [['column' => 'token', 'operator' => '=', 'value' => $this->csrfToken]];
        $result = $this->repository->fetchTokenWithData($conditions);

        // Checks if a token exists in the database
        if ($result === null) return false;

        // Gets the first and only token record from the result that is a multidimensional array
        $tokenFromDb = $result[0];

        // Compare user's ID from session and from the database
        $this->userId = $this->getUserIdFromSession();
        if ($this->userId != $tokenFromDb['user_id']) return false;

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
                $this->repository->delete('id', $tokenFromDb['id']);
                return false;
            }
        }

        // Token is valid, so true is returned
        return true;
    }
}
