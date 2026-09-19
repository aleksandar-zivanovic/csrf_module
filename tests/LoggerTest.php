<?php

declare(strict_types=1);

namespace CSRFModule\Tests;

use CSRFModule\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private const LOG_DIRECTORY = __DIR__ . '/../logs/';

    private function lastLine(string $file): string
    {
        $lines = file(self::LOG_DIRECTORY . $file);

        return rtrim(end($lines), "\r\n");
    }

    public static function logMethods(): array
    {
        return [
            'logDatabaseError' => ['logDatabaseError', 'db_errors.log'],
            'logCleanup' => ['logCleanup', 'token_cleanup.log'],
            'logInfo' => ['logInfo', 'general.log'],
        ];
    }

    #[DataProvider('logMethods')]
    public function testWritesATimestampedMessageToItsLogFile(string $method, string $file): void
    {
        $message = 'phpunit ' . bin2hex(random_bytes(8));

        (new Logger())->$method($message);

        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]: ' . preg_quote($message, '/') . '$/',
            $this->lastLine($file)
        );
    }

    #[DataProvider('logMethods')]
    public function testAppendsArrayErrorInfoToTheMessage(string $method, string $file): void
    {
        $message = 'phpunit ' . bin2hex(random_bytes(8));

        (new Logger())->$method($message, ['message' => 'first', 'code' => 42]);

        $line = $this->lastLine($file);
        $this->assertStringContainsString($message, $line);
        $this->assertStringEndsWith('first, 42', $line);
    }
}
