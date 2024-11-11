<?php

class CSRF
{
    public string $csrfToken;

    public function generateCsrfToken(): void  
    {
        $this->csrfToken = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $this->csrfToken;
    }
}