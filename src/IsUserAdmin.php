<?php

namespace CSRFModule;

/**
 * Requires the using class to already have `$this->config` initialized - typically via the AddDatabaseAndLogger trait.
 *
 * @property Config $config
 */
trait IsUserAdmin
{
    /**
     * Checks if the current user is admin by taking by comparing
     * values of $_SESSION[ROLE_NAME] and ROLE_VALUE.
     * @return bool Returns true if user is an Admin, otherwise false
     */
    protected function isUserAdmin(): bool
    {
        return ($_SESSION[$this->config->roleName] ?? null) === $this->config->roleValue;
    }
}
