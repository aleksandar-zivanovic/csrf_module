<?php
require_once __DIR__ . '/Database.php';

class CSRF
{
    public string $csrfToken;
    public int $timestamp;
    public int $userId;

    // connecting to the database
    protected function getDb(): object
    {
        $db = new Database();
        return $db->getDbh();
    }

    // generating CSRF token and adding data to the database
    public function generateCsrfToken(): void  
    {
        $this->csrfToken = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $this->csrfToken;

        // setting timestamp value
        $this->timestamp = time();

        // setting $this->userId value from session 
        $this->getUserId();

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
            $db->errorLog("generateCsrfToken() method error: execution() failed");
        }

    }

    /**
     * Getting user's ID from session and setting userId property 
     * if the session value exists and is integer type
     */
    public function getUserId(): void
    {
        if (!isset($_SESSION[USER_ID_SESSION_KEY]) || !is_int($_SESSION[USER_ID_SESSION_KEY])) {
            echo "Error: User ID session key is missing or invalid.";
            die();
        }
        
        $this->userId = htmlspecialchars(trim($_SESSION[USER_ID_SESSION_KEY]));
    }
}