<?php

declare(strict_types=1);

namespace CSRFModule\Tests;

use CSRFModule\CSRF;
use PHPUnit\Framework\Attributes\DataProvider;

final class CSRFTest extends DatabaseTestCase
{
    public static function statusModes(): array
    {
        return [
            'SAVE_CSRF_STATUS = true' => [true],
            'SAVE_CSRF_STATUS = false' => [false],
        ];
    }

    private function csrf(bool $saveStatus = true, array $overrides = []): CSRF
    {
        return new CSRF(db: $this->db, config: $this->config(array_merge(['saveCsrfStatus' => $saveStatus], $overrides)));
    }

    private function newToken(CSRF $csrf): string
    {
        $csrf->generateAndSaveCsrfToken();

        return $csrf->csrfToken;
    }

    private function setTimestamp(string $token, int $timestamp): void
    {
        $this->db->getDbh()
            ->prepare('UPDATE csrf_tokens SET timestamp = ? WHERE token = ?')
            ->execute([$timestamp, $token]);
    }

    #[DataProvider('statusModes')]
    public function testValidTokenIsAcceptedAndConsumed(bool $saveStatus): void
    {
        $this->prepareTable(['saveCsrfStatus' => $saveStatus]);
        $_SESSION = ['user_id' => 1];
        $csrf = $this->csrf($saveStatus);
        $token = $this->newToken($csrf);

        $this->assertTrue($csrf->tokenValidation($token));
        if ($saveStatus) {
            $this->assertSame('used', $this->row($token)['status']);
        } else {
            $this->assertFalse($this->row($token), 'Used token must be deleted.');
        }
        $this->assertFalse($csrf->tokenValidation($token), 'Same token must not be accepted twice.');
    }

    #[DataProvider('statusModes')]
    public function testForgedAndInvalidTokensAreRejectedWithoutConsumingTheToken(bool $saveStatus): void
    {
        $this->prepareTable(['saveCsrfStatus' => $saveStatus]);
        $_SESSION = ['user_id' => 1];
        $csrf = $this->csrf($saveStatus);
        $token = $this->newToken($csrf);

        $this->assertFalse($csrf->tokenValidation(''), 'Forged request without the form token (the session holds a valid token)');
        $this->assertFalse($csrf->tokenValidation(str_repeat('a', 64)), 'Wrong token');
        $this->assertFalse($csrf->tokenValidation(strtoupper($token)), 'Uppercase variant of the token');
        $this->assertTrue($csrf->tokenValidation($token), 'Rejected attempts must not consume the token.');
    }

    #[DataProvider('statusModes')]
    public function testTokenIsBoundToItsOwner(bool $saveStatus): void
    {
        $this->prepareTable(['saveCsrfStatus' => $saveStatus]);
        $_SESSION = ['user_id' => 1];
        $csrf = $this->csrf($saveStatus);
        $token = $this->newToken($csrf);

        $_SESSION['user_id'] = 2;
        $this->assertFalse($csrf->tokenValidation($token), "Another user's token");
        unset($_SESSION['user_id']);
        $this->assertFalse($csrf->tokenValidation($token), 'No logged-in user');
        $_SESSION['user_id'] = 1;
        $this->assertTrue($csrf->tokenValidation($token), 'Owner');
    }

    #[DataProvider('statusModes')]
    public function testMultipleOpenFormsAreValid(bool $saveStatus): void
    {
        $this->prepareTable(['saveCsrfStatus' => $saveStatus]);
        $_SESSION = ['user_id' => 1];
        $csrf = $this->csrf($saveStatus, ['tokensPerUser' => 5]);
        $first = $this->newToken($csrf);
        $second = $this->newToken($csrf);
        $third = $this->newToken($csrf);

        $this->assertTrue($csrf->tokenValidation($first));
        $this->assertTrue($csrf->tokenValidation($third));
        $this->assertTrue($csrf->tokenValidation($second));
    }

    #[DataProvider('statusModes')]
    public function testOldestTokenIsEvictedWhenTheLimitIsReached(bool $saveStatus): void
    {
        $this->prepareTable(['saveCsrfStatus' => $saveStatus]);
        $_SESSION = ['user_id' => 1];
        $csrf = $this->csrf($saveStatus, ['tokensPerUser' => 2]);
        $first = $this->newToken($csrf);
        $second = $this->newToken($csrf);
        $third = $this->newToken($csrf);

        $this->assertFalse($this->row($first), 'Oldest token must be deleted.');
        $this->assertFalse($csrf->tokenValidation($first));
        $this->assertTrue($csrf->tokenValidation($second));
        $this->assertTrue($csrf->tokenValidation($third));
    }

    #[DataProvider('statusModes')]
    public function testLimitCanBeDisabled(bool $saveStatus): void
    {
        $this->prepareTable(['saveCsrfStatus' => $saveStatus]);
        $_SESSION = ['user_id' => 1];
        $csrf = $this->csrf($saveStatus, ['tokensPerUser' => null]);
        $tokens = [];
        for ($i = 0; $i < 7; $i++) {
            $tokens[] = $this->newToken($csrf);
        }

        $this->assertSame(7, $this->countTokens());
        $this->assertTrue($csrf->tokenValidation($tokens[0]));
    }

    #[DataProvider('statusModes')]
    public function testExpiredTokenIsRejected(bool $saveStatus): void
    {
        $this->prepareTable(['saveCsrfStatus' => $saveStatus]);
        $_SESSION = ['user_id' => 1];
        $csrf = $this->csrf($saveStatus, ['tokenExpirationTime' => 100]);
        $token = $this->newToken($csrf);
        $this->setTimestamp($token, time() - 100);

        $this->assertFalse($csrf->tokenValidation($token));
        if ($saveStatus) {
            $this->assertSame('expired', $this->row($token)['status']);
        } else {
            $this->assertFalse($this->row($token), 'Expired token must be deleted.');
        }
    }

    #[DataProvider('statusModes')]
    public function testTokenWithinItsLifetimeIsAccepted(bool $saveStatus): void
    {
        $this->prepareTable(['saveCsrfStatus' => $saveStatus]);
        $_SESSION = ['user_id' => 1];
        $csrf = $this->csrf($saveStatus, ['tokenExpirationTime' => 100]);
        $token = $this->newToken($csrf);
        $this->setTimestamp($token, time() - 50);

        $this->assertTrue($csrf->tokenValidation($token));
    }

    public static function invalidUserIdSessions(): array
    {
        return [
            'missing user id' => [[]],
            'user id as string' => [['user_id' => '1']],
            'zero user id' => [['user_id' => 0]],
            'negative user id' => [['user_id' => -3]],
        ];
    }

    #[DataProvider('invalidUserIdSessions')]
    public function testGeneratingATokenRequiresAValidUserId(array $session): void
    {
        $this->prepareTable();
        $_SESSION = $session;

        $this->expectException(\OutOfRangeException::class);
        $this->csrf()->generateAndSaveCsrfToken();
    }

    public function testGeneratedTokenIsStoredInTheSessionAndTheDatabase(): void
    {
        $this->prepareTable();
        $_SESSION = ['user_id' => 7];
        $token = $this->newToken($this->csrf());

        $this->assertSame($token, $_SESSION['csrf_token']);
        $row = $this->row($token);
        $this->assertSame(7, $row['user_id']);
        $this->assertSame('valid', $row['status']);
    }

    public function testCustomUserIdSessionKeyIsUsed(): void
    {
        $this->prepareTable();
        $_SESSION = ['uid' => 9];
        $csrf = $this->csrf(true, ['userIdSessionKey' => 'uid']);
        $token = $this->newToken($csrf);

        $this->assertSame(9, $this->row($token)['user_id']);
        $this->assertTrue($csrf->tokenValidation($token));
    }

    public function testGetTokensWithDataReturnsMatchingTokens(): void
    {
        $this->prepareTable();
        $token = $this->insertToken(1);
        $this->insertToken(2);

        $result = $this->csrf()->getTokensWithData([['column' => 'user_id', 'operator' => '=', 'value' => 1]]);

        $this->assertSame([$token], array_column($result, 'token'));
    }

    public function testChangeTokenStatusUpdatesTheStatus(): void
    {
        $this->prepareTable();
        $token = $this->insertToken(1);

        $this->assertTrue($this->csrf()->changeTokenStatus($this->row($token)['id'], 'expired'));
        $this->assertSame('expired', $this->row($token)['status']);
    }

    public function testDeleteTokenDeletesTheUsersSessionToken(): void
    {
        $this->prepareTable();
        $_SESSION = ['user_id' => 1];
        $csrf = $this->csrf();
        $token = $this->newToken($csrf);

        $this->assertTrue($csrf->deleteToken('token', $token));
        $this->assertFalse($this->row($token));
    }

    public function testAllTokensCleanUpDeletesExpiredTokens(): void
    {
        $this->prepareTable();
        $expired = $this->insertToken(1, time() - 7200);
        $_SESSION = ['user_id' => 1, 'role' => 'admin'];

        $this->assertTrue($this->csrf()->allTokensCleanUp());
        $this->assertFalse($this->row($expired));
    }

    public function testAllTokensCleanUpPassesTheInitiatorOn(): void
    {
        $this->prepareTable();
        $expired = $this->insertToken(1, time() - 7200);
        $_SESSION = ['role' => 'admin'];

        $this->assertTrue($this->csrf()->allTokensCleanUp(initiator: 'cron'));
        $this->assertFalse($this->row($expired));
    }

    public function testAllTokensCleanUpRequiresAUserIdInTheSessionOrAnInitiator(): void
    {
        $this->prepareTable();
        $_SESSION = ['role' => 'admin'];

        $this->expectException(\InvalidArgumentException::class);
        $this->csrf()->allTokensCleanUp();
    }

    public function testLogoutTokensCleanupDeletesTheUsersTokens(): void
    {
        $this->prepareTable();
        $this->insertToken(1);
        $_SESSION = ['user_id' => 1];

        $this->assertTrue($this->csrf()->logoutTokensCleanup('delete'));
        $this->assertSame(0, $this->countTokens());
    }
}
