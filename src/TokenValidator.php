<?php

declare(strict_types=1);

namespace CSRFModule;

class TokenValidator
{
    use AddDatabaseAndLogger;
    use GetUserIdFromSession;

    private ?TokenRepository $repository = null;
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
     * Function checks if the token is valid for use and change status upon use.
     * It checks if: 
     * - token submitted with the form exists in database,
     * - the token belongs to the current user,
     * - token in database has status 'valid',
     * - token token is expired.
     * The token is consumed after a successful validation, so it can be used only once.
     * Function returns true if the token is valid and false if is invalid
     * @param string $tokenFromForm The CSRF token submitted with the form.
     * @throws \RuntimeException If updating the token status or deleting the token fails.
     * @throws \InvalidArgumentException If fetching or updating token data fails validation.
     * @throws \LengthException If updating the token status is called with an empty ID.
     * @return bool Returns true if the token is valid, false otherwise.
     * @see CSRF::tokenValidation()
     */
    public function validation(string $tokenFromForm): bool
    {
        // Fetches token data from the database by the token submitted with the form
        $conditions = [['column' => 'token', 'operator' => '=', 'value' => $tokenFromForm]];
        $result = $this->repository->fetchTokenWithData($conditions);

        // Checks if a token exists in the database
        if ($result === null) return false;

        // Gets the first and only token record from the result that is a multidimensional array
        $tokenFromDb = $result[0];

        // Exact timing-safe comparison, since the database comparison can be case-insensitive
        if (!hash_equals($tokenFromDb['token'], $tokenFromForm)) return false;

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
                $this->repository->deleteUnsafe('token', [$tokenFromDb['token']]);
                return false;
            }
        }

        // Changes token status to 'used', after using it, if saving status is turned on
        if ($this->config->saveCsrfStatus === true) {
            if ($this->repository->changeStatus($tokenFromDb['id'], 'used') === false) {
                throw new \RuntimeException("Failed to update the token status to 'used'.");
            }
        }

        // Deletes the token after using it, if saving status is turned off. False means another request already used it
        if ($this->config->saveCsrfStatus === false) {
            if ($this->repository->deleteUnsafe('token', [$tokenFromDb['token']]) === false) return false;
        }

        // Token is valid, so true is returned
        return true;
    }
}
