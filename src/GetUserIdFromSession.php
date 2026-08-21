<?php

namespace CSRFModule;

/**
 * Requires the using class to already have `$this->config` initialized - typically via the AddDatabaseAndLogger trait.
 *
 * @property Config $config
 */
trait GetUserIdFromSession
{
    /**
     * Retrieves the user ID from the session.
     *
     * @return int
     * @throws \OutOfRangeException If the user ID is not found or invalid.
     */
    public function getUserIdFromSession(): int
    {
        if (!isset($_SESSION[$this->config->userIdSessionKey]) || !is_int($_SESSION[$this->config->userIdSessionKey]) || $_SESSION[$this->config->userIdSessionKey] < 1) {
            throw new \OutOfRangeException("User ID is not found in session or is not valid.");
        }

        return $_SESSION[$this->config->userIdSessionKey];
    }
}
