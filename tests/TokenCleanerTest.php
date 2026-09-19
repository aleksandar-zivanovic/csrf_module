<?php

declare(strict_types=1);

namespace CSRFModule\Tests;

use CSRFModule\TokenCleaner;
use PHPUnit\Framework\Attributes\DataProvider;

final class TokenCleanerTest extends DatabaseTestCase
{
    private function cleaner(array $overrides = []): TokenCleaner
    {
        return new TokenCleaner($this->db, null, $this->config($overrides));
    }

    private function captureWarnings(callable $action): array
    {
        $warnings = [];
        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });

        try {
            $action();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    public function testCleanUpRequiresAnAdminSession(): void
    {
        $this->prepareTable();
        $_SESSION = ['user_id' => 1];

        $this->expectException(\LogicException::class);
        $this->cleaner()->cleanUpAll();
    }

    public function testCleanUpDoesNotTreatANonMatchingRoleValueAsAdmin(): void
    {
        $this->prepareTable();
        $_SESSION = ['user_id' => 1, 'role' => true];

        $this->expectException(\LogicException::class);
        $this->cleaner()->cleanUpAll();
    }

    public function testCleanUpDeletesOnlyExpiredTokens(): void
    {
        $this->prepareTable();
        $atBoundary = $this->insertToken(1, time() - 3600);
        $older = $this->insertToken(2, time() - 7200);
        $fresh = $this->insertToken(1);
        $_SESSION = ['user_id' => 1, 'role' => 'admin'];

        $this->assertTrue($this->cleaner()->cleanUpAll());
        $this->assertFalse($this->row($atBoundary));
        $this->assertFalse($this->row($older));
        $this->assertNotFalse($this->row($fresh));
    }

    public function testCleanUpCanBeLimitedToOneUser(): void
    {
        $this->prepareTable();
        $ownExpired = $this->insertToken(1, time() - 7200);
        $otherExpired = $this->insertToken(2, time() - 7200);
        $_SESSION = ['user_id' => 1, 'role' => 'admin'];

        $this->assertTrue($this->cleaner()->cleanUpAll(1));
        $this->assertFalse($this->row($ownExpired));
        $this->assertNotFalse($this->row($otherExpired));
    }

    public function testCleanUpReturnsFalseWhenNoTokenIsExpired(): void
    {
        $this->prepareTable();
        $this->insertToken(1);
        $_SESSION = ['user_id' => 1, 'role' => 'admin'];

        $this->assertFalse($this->cleaner()->cleanUpAll());
    }

    public static function invalidUserIds(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
        ];
    }

    #[DataProvider('invalidUserIds')]
    public function testCleanUpRejectsAnInvalidUserId(int $userId): void
    {
        $this->prepareTable();
        $_SESSION = ['user_id' => 1, 'role' => 'admin'];

        $this->expectException(\InvalidArgumentException::class);
        $this->cleaner()->cleanUpAll($userId);
    }

    public function testCleanUpRequiresAUserIdInTheSessionOrAnInitiator(): void
    {
        $this->prepareTable();
        $_SESSION = ['role' => 'admin'];

        $this->expectException(\InvalidArgumentException::class);
        $this->cleaner()->cleanUpAll();
    }

    public function testCleanUpRunsForAnExternalScriptThatPassesAnInitiator(): void
    {
        $this->prepareTable();
        $expired = $this->insertToken(1, time() - 7200);
        $_SESSION = ['role' => 'admin'];

        $warnings = $this->captureWarnings(fn () => $this->cleaner()->cleanUpAll(initiator: 'cron'));

        $this->assertSame([], $warnings, 'Cleanup must not trigger PHP warnings.');
        $this->assertFalse($this->row($expired));
    }

    public function testLogoutRejectsAnInvalidAction(): void
    {
        $this->prepareTable();
        $_SESSION = ['user_id' => 1];

        $this->expectException(\InvalidArgumentException::class);
        $this->cleaner()->logoutCleanUp('remove');
    }

    public function testLogoutDeleteRemovesOnlyTheUsersTokens(): void
    {
        $this->prepareTable();
        $this->insertToken(1);
        $this->insertToken(1);
        $other = $this->insertToken(2);
        $_SESSION = ['user_id' => 1];

        $this->assertTrue($this->cleaner()->logoutCleanUp('delete'));
        $this->assertSame(1, $this->countTokens());
        $this->assertNotFalse($this->row($other));
    }

    public function testLogoutDeleteReturnsTrueWhenTheUserHasNoTokens(): void
    {
        $this->prepareTable();
        $_SESSION = ['user_id' => 1];

        $this->assertTrue($this->cleaner()->logoutCleanUp('delete'), 'Docblock: returns true if there are no tokens.');
    }

    public function testLogoutUpdateExpiresOnlyTheUsersValidTokens(): void
    {
        $this->prepareTable();
        $valid = $this->insertToken(1);
        $used = $this->insertToken(1, null, 'used');
        $other = $this->insertToken(2);
        $_SESSION = ['user_id' => 1];

        $this->assertTrue($this->cleaner()->logoutCleanUp('update'));
        $this->assertSame('expired', $this->row($valid)['status']);
        $this->assertSame('used', $this->row($used)['status']);
        $this->assertSame('valid', $this->row($other)['status']);
    }

    public function testLogoutUpdateReturnsTrueWhenThereIsNothingToExpire(): void
    {
        $this->prepareTable();
        $this->insertToken(1, null, 'used');
        $_SESSION = ['user_id' => 1];

        $this->assertTrue($this->cleaner()->logoutCleanUp('update'));
    }

    public function testLogoutUpdateRequiresSavingStatus(): void
    {
        $this->prepareTable(['saveCsrfStatus' => false]);
        $_SESSION = ['user_id' => 1];

        $this->expectException(\LogicException::class);
        $this->cleaner(['saveCsrfStatus' => false])->logoutCleanUp('update');
    }

    public static function logoutActions(): array
    {
        return [
            'delete' => ['delete'],
            'update' => ['update'],
        ];
    }

    #[DataProvider('logoutActions')]
    public function testLogoutRequiresALoggedInUser(string $action): void
    {
        $this->prepareTable();

        $this->expectException(\OutOfRangeException::class);
        $this->cleaner()->logoutCleanUp($action);
    }

    public function testEnforceLimitDeletesTheOldestTokenAtTheLimit(): void
    {
        $this->prepareTable();
        $oldest = $this->insertToken(1);
        $newest = $this->insertToken(1);
        $other = $this->insertToken(2);

        $this->cleaner(['tokensPerUser' => 2])->enforceLimit(1);

        $this->assertFalse($this->row($oldest));
        $this->assertNotFalse($this->row($newest));
        $this->assertNotFalse($this->row($other));
    }

    public function testEnforceLimitKeepsTokensBelowTheLimit(): void
    {
        $this->prepareTable();
        $token = $this->insertToken(1);

        $this->cleaner(['tokensPerUser' => 2])->enforceLimit(1);

        $this->assertNotFalse($this->row($token));
    }
}
