<?php

declare(strict_types=1);

namespace CSRFModule\Tests;

use CSRFModule\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsComeFromTheConfigurationConstants(): void
    {
        $config = new Config();

        $this->assertSame(SAVE_CSRF_STATUS, $config->saveCsrfStatus);
        $this->assertSame(DB_USER, $config->dbUser);
        $this->assertSame(DB_PASS, $config->dbPass);
        $this->assertSame(DB_HOST, $config->dbHost);
        $this->assertSame(DB_NAME, $config->dbName);
        $this->assertSame(USER_ID_SESSION_KEY, $config->userIdSessionKey);
        $this->assertSame(TOKEN_EXPIRATION_TIME, $config->tokenExpirationTime);
        $this->assertSame(ANONYMOUS_TOKEN_EXPIRATION_TIME, $config->anonymousTokenExpirationTime);
        $this->assertSame(ROLE_NAME, $config->roleName);
        $this->assertSame(ROLE_VALUE, $config->roleValue);
        $this->assertSame(INDEX_TIMESTAMP, $config->indexTimestamp);
        $this->assertSame(INDEX_STATUS, $config->indexStatus);
        $this->assertSame(INDEX_BOTH, $config->indexBoth);
        $this->assertSame(INDEX_USER_ID, $config->indexUserId);
        $this->assertSame(DB_PERSISTENT, $config->dbPersistent);
        $this->assertSame(TOKENS_PER_USER, $config->tokensPerUser);
        $this->assertSame(ANONYMOUS_TOKENS_LIMIT, $config->anonymousTokensLimit);
    }

    public function testNamedArgumentsOverrideOnlyTheGivenSettings(): void
    {
        $config = new Config(tokenExpirationTime: 42, dbName: 'other_database');

        $this->assertSame(42, $config->tokenExpirationTime);
        $this->assertSame('other_database', $config->dbName);
        $this->assertSame(DB_HOST, $config->dbHost);
    }

    public function testLimitsCanBeDisabledWithNull(): void
    {
        $config = new Config(tokensPerUser: null, anonymousTokensLimit: null);

        $this->assertNull($config->tokensPerUser);
        $this->assertNull($config->anonymousTokensLimit);
    }

    public function testExampleConfigDefinesEveryConstantTheConfigClassUses(): void
    {
        $example = file_get_contents(__DIR__ . '/../config/csrf_config.example.php');
        preg_match_all('/^const\s+([A-Z_]+)\s*=/m', $example, $matches);

        $required = [];
        foreach ((new \ReflectionMethod(Config::class, '__construct'))->getParameters() as $parameter) {
            $name = $parameter->getDefaultValueConstantName();
            $required[] = substr($name, strrpos($name, '\\') === false ? 0 : strrpos($name, '\\') + 1);
        }

        $this->assertSame([], array_values(array_diff($required, $matches[1])), 'Constants missing from csrf_config.example.php');
    }
}
