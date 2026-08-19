<?php

namespace CSRFModule;

class TokenGenerator
{
    private string $csrfToken;

    /**
     * Generates a CSRF token and saves it to the session.
     * @return string The generated CSRF token.
     */
    public function generate(): string
    {
        $this->csrfToken = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $this->csrfToken;
        return $this->csrfToken;
    }
}
