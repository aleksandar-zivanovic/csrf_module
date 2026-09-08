<?php

declare(strict_types=1);

namespace CSRFModule;

class TokenGenerator
{
    private string $csrfToken;

    /**
     * Generates a CSRF token.
     * Saves the token to the session if the user is logged in.
     * @param bool $loggedUser Indicates if the user is logged in. Default is true.
     * 
     * @return string The generated CSRF token.
     */
    public function generate(bool $loggedUser = true): string
    {
        $this->csrfToken = bin2hex(random_bytes(32));

        if ($loggedUser) {
            $_SESSION['csrf_token'] = $this->csrfToken;
        }

        return $this->csrfToken;
    }
}
