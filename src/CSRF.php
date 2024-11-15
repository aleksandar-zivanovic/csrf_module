<?php
require_once __DIR__ . '/Database.php';

class CSRF
{
    public string $csrfToken;
    public int $timestamp;
    public int $userId;

    // Connects to the database
    protected function getDb(): object
    {
        $db = new Database();
        return $db->getDbh();
    }

    // Generates CSRF token and adds data to the database
    public function generateAndSaveCsrfToken(): void  
    {
        $this->csrfToken = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $this->csrfToken;

        // Sets timestamp value
        $this->timestamp = time();

        // Sets $this->userId value from session 
        $this->userId = $this->getUserIdFromSession();
        if ($this->userId == 0) {
            echo "Error: User ID session key is missing or invalid.";
            die();
        }

        $db = $this->getDb();

        $query = "INSERT INTO csrf_tokens (token, timestamp, status, user_id) VALUES (:tk, :ts, :st, :ui)";
        $stmt =  $db->prepare($query);
        $stmt->bindValue(":tk", $this->csrfToken, PDO::PARAM_STR);
        $stmt->bindValue(":ts", $this->timestamp, PDO::PARAM_INT);
        $stmt->bindValue(":st", "valid", PDO::PARAM_STR);
        $stmt->bindValue(":ui", $this->userId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->rowCount() >= 1 ? 'success' : 'fail';
        if ($result == 'fail') {
            $db->errorLog("generateAndSaveCsrfToken() method error: execution() failed");
        }

    }

    /**
     * Getting user's ID from session and setting userId property 
     * if the session value exists and is integer type
     */
    public function getUserIdFromSession(): int
    {
        if (!isset($_SESSION[USER_ID_SESSION_KEY]) || !is_int($_SESSION[USER_ID_SESSION_KEY])) {
            return 0;
        }
        
        return htmlspecialchars(trim($_SESSION[USER_ID_SESSION_KEY]));
    }

    /**
     * Function checks if the token is valid for use. It checks if: 
     * - token from session is in valid format, 
     * - token from session exists in database, 
     * - token in database has status 'valid', 
     * - token token is expired.
     * Funtion returns true if the token is valid and false if is invalid
     */
    public function tokenValidation(): bool
    {
        // Gets value of the token from session. 
        // gettingTokenFromSession() returns null if the token isn't set in session or is in a wrong format 
        $tokenFromSession = $this->gettingTokenFromSession();
        if ($tokenFromSession == null) return false;
        $this->csrfToken = $tokenFromSession;

        // Checks if a token is set in session
        if (empty($this->csrfToken)) return false;
        
        // Checks if a token exists in the database
        $tokenFromDb = $this->getTokenData();
        if ($tokenFromDb === false) return false;

        // Compare user's ID from session and from the database
        $this->userId = $this->getUserIdFromSession();
        if ($this->userId != $tokenFromDb['user_id']) return false;

        // Checks token status is valid (this is only if saving status is turned on)
        if (SAVE_CSRF_STATUS === true) {
            if ($tokenFromDb['status'] !== 'valid') return false;
        }
        
        // Check if token is timed out
        if ($this->isTokenTimedOut($tokenFromDb['timestamp'])) return false;

        // Token is valid, so true is returned
        return true;
    }

    /**
     * Gets token from session. If there is token in session, 
     * function returns string, else returns null
     */
    public function gettingTokenFromSession(): ?string
    {
        if (isset($_SESSION['csrf_token']) && !empty($_SESSION['csrf_token'])) {
            $token = $_SESSION['csrf_token'];
            return preg_match('/^[a-f0-9]{64}$/', $token) ? $token : null;
        } else {
            return null;
        }
    }

    // Checks if the token is timed out. Returns true if expired and false if not.
    private function isTokenTimedOut(int $timestamp): bool 
    {
        return $timestamp + TOKEN_EXPIRATION_TIME <= time();
    }

    /**
     * Retrives token data from database.
     * Returns an associative array if the token exists or false if it doesn't.
     */
    public function getTokenData(): array|false
    {  
        $db = $this->getDb();
        $query = "SELECT * from csrf_tokens where token = :tk";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':tk', $this->csrfToken, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: false ;
    }
}