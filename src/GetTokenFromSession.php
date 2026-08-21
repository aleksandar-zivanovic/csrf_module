<?php

namespace CSRFModule;

trait GetTokenFromSession
{
    /**
     * Gets token from session.
     * 
     * If there is token in session function returns token.
     * If token is not set or invalid, throws an exception.
     * 
     * @throws \OutOfRangeException If CSRF token is not set in the session or has an invalid format.
     * @return string The CSRF token from the session.
     * @see CSRF::generateAndSaveCsrfToken()
     */
    protected function getTokenFromSession(): string
    {
        if (isset($_SESSION['csrf_token']) && !empty($_SESSION['csrf_token'])) {
            $token = trim($_SESSION['csrf_token']);
            if (preg_match('/^[a-f0-9]{64}$/', $token)) {
                return $token;
            } else {
                throw new \OutOfRangeException("CSRF token in session is not in a valid format.");
            }
        } else {
            throw new \OutOfRangeException("CSRF token is not set in the session.");
        }
    }
}
