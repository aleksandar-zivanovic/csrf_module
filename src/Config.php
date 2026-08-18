<?php
namespace CSRFModule;
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'csrf_config.php';

class Config
{
    public function __construct(
        public readonly bool $saveCsrfStatus = SAVE_CSRF_STATUS,
        public readonly string $dbUser = DB_USER,
        public readonly string $dbPass = DB_PASS,
        public readonly string $dbHost = DB_HOST,
        public readonly string $dbName = DB_NAME,
        public readonly string $userIdSessionKey = USER_ID_SESSION_KEY,
        public readonly int $tokenExpirationTime = TOKEN_EXPIRATION_TIME,
        public readonly string $roleName = ROLE_NAME,
        public readonly string $roleValue = ROLE_VALUE,
        public readonly bool $indexTimestamp = INDEX_TIMESTAMP,
        public readonly bool $indexStatus = INDEX_STATUS,
        public readonly bool $indexBoth = INDEX_BOTH,
    ) {}
}
