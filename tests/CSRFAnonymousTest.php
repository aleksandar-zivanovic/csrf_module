<?php

declare(strict_types=1);

namespace CSRFModule\Tests;

use CSRFModule\Config;
use CSRFModule\CSRFAnonymous;
use PHPUnit\Framework\TestCase;

final class CSRFAnonymousTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    private function newToken(CSRFAnonymous $csrf): array
    {
        $csrf->generateCsrfFormId();
        $csrf->generateToken();
        $csrf->setSession();

        return [$csrf->csrfFormId, $csrf->csrfToken];
    }

    public function testValidTokenIsAcceptedAndConsumed(): void
    {
        $csrf = new CSRFAnonymous(new Config(anonymousTokensLimit: 5));
        [$formId, $token] = $this->newToken($csrf);

        $this->assertTrue($csrf->tokenValidation($formId, $token));
        $this->assertArrayNotHasKey($formId, $_SESSION['csrf_anonymous_tokens']);
        $this->assertFalse($csrf->tokenValidation($formId, $token), 'Same token must not be accepted twice.');
    }

    public function testAnonymousFlowDoesNotWriteTheSharedSessionToken(): void
    {
        $this->newToken(new CSRFAnonymous());

        $this->assertArrayNotHasKey('csrf_token', $_SESSION);
    }

    public function testInvalidInputIsRejectedWithoutConsumingTheToken(): void
    {
        $csrf = new CSRFAnonymous(new Config(anonymousTokensLimit: 5));
        [$formId, $token] = $this->newToken($csrf);

        $this->assertFalse($csrf->tokenValidation($formId, str_repeat('a', 64)), 'Wrong token');
        $this->assertFalse($csrf->tokenValidation($formId, ''), 'Empty token');
        $this->assertFalse($csrf->tokenValidation('unknown', $token), 'Unknown form id');
        $this->assertTrue($csrf->tokenValidation($formId, $token), 'Rejected attempts must not consume the token.');
    }

    public function testTokenFromAnotherFormIsRejected(): void
    {
        $csrf = new CSRFAnonymous(new Config(anonymousTokensLimit: 5));
        [$formA] = $this->newToken($csrf);
        [, $tokenB] = $this->newToken($csrf);

        $this->assertFalse($csrf->tokenValidation($formA, $tokenB));
    }

    public function testExpiredTokenIsRejectedAndRemoved(): void
    {
        $csrf = new CSRFAnonymous(new Config(anonymousTokenExpirationTime: 100, anonymousTokensLimit: 5));
        [$formId, $token] = $this->newToken($csrf);
        $_SESSION['csrf_anonymous_tokens'][$formId]['time'] = time() - 100;

        $this->assertFalse($csrf->tokenValidation($formId, $token));
        $this->assertArrayNotHasKey($formId, $_SESSION['csrf_anonymous_tokens']);
    }

    public function testTokenWithinItsLifetimeIsAccepted(): void
    {
        $csrf = new CSRFAnonymous(new Config(anonymousTokenExpirationTime: 100, anonymousTokensLimit: 5));
        [$formId, $token] = $this->newToken($csrf);
        $_SESSION['csrf_anonymous_tokens'][$formId]['time'] = time() - 50;

        $this->assertTrue($csrf->tokenValidation($formId, $token));
    }

    public function testDefaultLimitKeepsOnlyTheNewestToken(): void
    {
        $csrf = new CSRFAnonymous(new Config(anonymousTokensLimit: 1));
        [$form1, $token1] = $this->newToken($csrf);
        [$form2, $token2] = $this->newToken($csrf);

        $this->assertCount(1, $_SESSION['csrf_anonymous_tokens']);
        $this->assertFalse($csrf->tokenValidation($form1, $token1));
        $this->assertTrue($csrf->tokenValidation($form2, $token2));
    }

    public function testLimitEvictsTheOldestToken(): void
    {
        $csrf = new CSRFAnonymous(new Config(anonymousTokensLimit: 3));
        $tokens = [];
        for ($i = 0; $i < 4; $i++) {
            $tokens[] = $this->newToken($csrf);
        }

        $this->assertCount(3, $_SESSION['csrf_anonymous_tokens']);
        $this->assertFalse($csrf->tokenValidation(...$tokens[0]));
        $this->assertTrue($csrf->tokenValidation(...$tokens[1]));
        $this->assertTrue($csrf->tokenValidation(...$tokens[2]));
        $this->assertTrue($csrf->tokenValidation(...$tokens[3]));
    }

    public function testLoweredLimitTrimsTheSession(): void
    {
        $big = new CSRFAnonymous(new Config(anonymousTokensLimit: 5));
        for ($i = 0; $i < 5; $i++) {
            $this->newToken($big);
        }
        $small = new CSRFAnonymous(new Config(anonymousTokensLimit: 2));
        [$formId, $token] = $this->newToken($small);

        $this->assertCount(2, $_SESSION['csrf_anonymous_tokens']);
        $this->assertTrue($small->tokenValidation($formId, $token));
    }

    public function testLimitCanBeDisabled(): void
    {
        $csrf = new CSRFAnonymous(new Config(anonymousTokensLimit: null));
        for ($i = 0; $i < 10; $i++) {
            $this->newToken($csrf);
        }

        $this->assertCount(10, $_SESSION['csrf_anonymous_tokens']);
    }

    public function testDestroyAllTokensRemovesEveryToken(): void
    {
        $csrf = new CSRFAnonymous(new Config(anonymousTokensLimit: 5));
        [$formId, $token] = $this->newToken($csrf);
        $this->newToken($csrf);
        $csrf->destroyAllTokens();

        $this->assertArrayNotHasKey('csrf_anonymous_tokens', $_SESSION);
        $this->assertFalse($csrf->tokenValidation($formId, $token));
    }
}
