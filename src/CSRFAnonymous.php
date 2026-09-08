<?php

declare(strict_types=1);

namespace CSRFModule;

class CSRFAnonymous
{
    private ?Config $config = null;
    private ?TokenGenerator $generator = null;
    public string $csrfToken;
    public string $csrfFormId;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
        $this->generator = new TokenGenerator();
    }

    /**
     * Generates a CSRF token for anonymous users.
     * The generated token is stored in the csrfToken property.
     *
     * @return void
     */
    public function generateToken(): void
    {
        $this->csrfToken = $this->generator->generate(loggedUser: false);
    }

    /**
     * Generates a CSRF form identifier for hidden input fields in html forms.
     * The CSRF form identifier is a unique identifier for each form to prevent CSRF attacks.
     * The generated token is stored in the csrfFormId property.
     *
     * @return void
     */
    public function generateCsrfFormId(): void
    {
        $this->csrfFormId = bin2hex(random_bytes(16));
    }

    /**
     * Sets the CSRF token and CSRF form identifier in the session for anonymous users.
     *
     * @return void
     */
    public function setSession(): void
    {
        // Enforces the limit on the number of CSRF tokens for anonymous users.
        $this->enforceLimit();

        $_SESSION['csrf_anonymous_tokens'][$this->csrfFormId] = [
            'token' => $this->csrfToken,
            'time'  => time(),
        ];
    }

    /**
     * Retrieves the CSRF token from the session for anonymous users. 
     * @param string $csrfFormId The CSRF form identifier associated with the token.
     *
     * @return string|null The CSRF token if it exists, null otherwise.
     */
    private function getTokenFromSession(string $csrfFormId): ?string
    {
        return $_SESSION['csrf_anonymous_tokens'][$csrfFormId]['token'] ?? null;
    }

    /**
     * Destroys the CSRF token from the session for anonymous users.
     * @param string $csrfFormId The CSRF form identifier associated with the token.
     *
     * @return void
     */
    private function destroyTokenFromSession(string $csrfFormId): void
    {
        unset($_SESSION['csrf_anonymous_tokens'][$csrfFormId]);
    }

    /**
     * Validates the CSRF token for anonymous users.
     * This method checks if the provided CSRF token is valid for the given form.
     *
     * @param string $csrfFormId The CSRF form identifier associated with the token.
     * @param string $tokenFromForm The CSRF token submitted with the form.
     *
     * @return bool True if the token is valid, false otherwise.
     */
    public function tokenValidation(string $csrfFormId, string $tokenFromForm): bool
    {
        $tokenFormSession = $this->getTokenFromSession($csrfFormId);

        // Checks if the csrf form identifier exists in the session and is not empty
        if (
            !isset($_SESSION['csrf_anonymous_tokens'][$csrfFormId])
            || empty($_SESSION['csrf_anonymous_tokens'][$csrfFormId])
        ) {
            return false;
        }

        // Checks if the csrf token is time valid
        if ($_SESSION['csrf_anonymous_tokens'][$csrfFormId]['time'] + $this->config->anonymousTokenExpirationTime <= time()) {
            $this->destroyTokenFromSession($csrfFormId);
            return false;
        }

        // Compares the token from the session with the token from the form
        if (!hash_equals($tokenFormSession, $tokenFromForm)) {
            return false;
        }

        // Removes the token from the session
        $this->destroyTokenFromSession($csrfFormId);

        return true;
    }

    /**
     * Enforces the limit on the number of CSRF tokens for anonymous users.
     *
     * @return void
     */
    private function enforceLimit(): void
    {
        if ($this->config->anonymousTokensLimit === null) {
            return;
        }

        if (!isset($_SESSION['csrf_anonymous_tokens'])) {
            return;
        }

        $anonymousTokensCount = count($_SESSION['csrf_anonymous_tokens']);
        if ($anonymousTokensCount >= $this->config->anonymousTokensLimit) {
            $excessTokensCount = $anonymousTokensCount - $this->config->anonymousTokensLimit;

            // Removes old tokens
            for ($i = 0; $i <= $excessTokensCount; $i++) {
                $oldestFormId = array_key_first($_SESSION['csrf_anonymous_tokens']);
                unset($_SESSION['csrf_anonymous_tokens'][$oldestFormId]);
            }
        }
    }

    /**
     * Destroys all CSRF tokens from the session for anonymous users.
     *
     * @return void
     */
    public function destroyAllTokens(): void
    {
        unset($_SESSION['csrf_anonymous_tokens']);
    }
}
