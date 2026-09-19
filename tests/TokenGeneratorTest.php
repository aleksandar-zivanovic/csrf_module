<?php

declare(strict_types=1);

namespace CSRFModule\Tests;

use CSRFModule\TokenGenerator;
use PHPUnit\Framework\TestCase;

final class TokenGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testGenerateReturns64LowercaseHexCharacters(): void
    {
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (new TokenGenerator())->generate());
    }

    public function testGenerateWritesTheTokenToTheSession(): void
    {
        $token = (new TokenGenerator())->generate();

        $this->assertSame($token, $_SESSION['csrf_token']);
    }

    public function testGenerateForAnonymousUserDoesNotWriteToTheSession(): void
    {
        (new TokenGenerator())->generate(loggedUser: false);

        $this->assertArrayNotHasKey('csrf_token', $_SESSION);
    }
}
