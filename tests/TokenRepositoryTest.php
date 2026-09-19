<?php

declare(strict_types=1);

namespace CSRFModule\Tests;

use CSRFModule\TokenRepository;
use PHPUnit\Framework\Attributes\DataProvider;

final class TokenRepositoryTest extends DatabaseTestCase
{
    private function repository(array $overrides = []): TokenRepository
    {
        return new TokenRepository($this->db, null, $this->config($overrides));
    }

    public function testSaveStoresAValidTokenWhenStatusIsSaved(): void
    {
        $this->prepareTable(['saveCsrfStatus' => true]);
        $token = bin2hex(random_bytes(32));

        $this->repository(['saveCsrfStatus' => true])->save($token, 1_700_000_000, 7);

        $row = $this->row($token);
        $this->assertSame(7, $row['user_id']);
        $this->assertSame(1_700_000_000, $row['timestamp']);
        $this->assertSame('valid', $row['status']);
    }

    public function testSaveStoresATokenWithoutTheStatusColumn(): void
    {
        $this->prepareTable(['saveCsrfStatus' => false]);
        $token = bin2hex(random_bytes(32));

        $this->repository(['saveCsrfStatus' => false])->save($token, time(), 7);

        $row = $this->row($token);
        $this->assertNotFalse($row);
        $this->assertArrayNotHasKey('status', $row);
    }

    public function testFetchReturnsNullWhenNothingMatches(): void
    {
        $this->prepareTable();

        $this->assertNull($this->repository()->fetchTokenWithData([['column' => 'user_id', 'operator' => '=', 'value' => 1]]));
    }

    public function testFetchWithoutConditionsReturnsAllTokens(): void
    {
        $this->prepareTable();
        $this->insertToken(1);
        $this->insertToken(2);

        $this->assertCount(2, $this->repository()->fetchTokenWithData());
    }

    public function testFetchFiltersByConditions(): void
    {
        $this->prepareTable();
        $this->insertToken(1, time() - 1000);
        $recent = $this->insertToken(1);
        $this->insertToken(2);

        $result = $this->repository()->fetchTokenWithData([
            ['column' => 'user_id', 'operator' => '=', 'value' => 1],
            ['column' => 'timestamp', 'operator' => '>', 'value' => time() - 500],
        ]);

        $this->assertSame([$recent], array_column($result, 'token'));
    }

    public function testFetchCombinesConditionsOnTheSameColumn(): void
    {
        $this->prepareTable();
        $this->insertToken(1, 100);
        $inRange = $this->insertToken(1, 150);
        $this->insertToken(1, 250);

        $result = $this->repository()->fetchTokenWithData([
            ['column' => 'timestamp', 'operator' => '>=', 'value' => 101],
            ['column' => 'timestamp', 'operator' => '<=', 'value' => 200],
        ]);

        $this->assertNotNull($result, 'A range on one column must return the token inside the range.');
        $this->assertSame([$inRange], array_column($result, 'token'));
    }

    public function testFetchSortsById(): void
    {
        $this->prepareTable();
        $first = $this->insertToken(1);
        $second = $this->insertToken(1);
        $repository = $this->repository();

        $this->assertSame([$first, $second], array_column($repository->fetchTokenWithData(null, 'ASC'), 'token'));
        $this->assertSame([$second, $first], array_column($repository->fetchTokenWithData(null, 'DESC'), 'token'));
    }

    public static function invalidConditions(): array
    {
        return [
            'empty array' => [[]],
            'single-level array' => [['column' => 'user_id', 'operator' => '=', 'value' => 1]],
            'disallowed column' => [[['column' => 'password', 'operator' => '=', 'value' => 1]]],
            'disallowed operator' => [[['column' => 'user_id', 'operator' => 'LIKE', 'value' => 1]]],
            'float value' => [[['column' => 'user_id', 'operator' => '=', 'value' => 1.5]]],
            'boolean value' => [[['column' => 'user_id', 'operator' => '=', 'value' => true]]],
            'null value' => [[['column' => 'user_id', 'operator' => '=', 'value' => null]]],
        ];
    }

    #[DataProvider('invalidConditions')]
    public function testFetchRejectsInvalidConditions(array $conditions): void
    {
        $this->prepareTable();

        $this->expectException(\InvalidArgumentException::class);
        $this->repository()->fetchTokenWithData($conditions);
    }

    public function testFetchRejectsAnInvalidSortDirection(): void
    {
        $this->prepareTable();

        $this->expectException(\InvalidArgumentException::class);
        $this->repository()->fetchTokenWithData(null, 'RANDOM');
    }

    public function testChangeStatusUpdatesASingleToken(): void
    {
        $this->prepareTable();
        $token = $this->insertToken(1);

        $this->assertTrue($this->repository()->changeStatus($this->row($token)['id'], 'used'));
        $this->assertSame('used', $this->row($token)['status']);
    }

    public function testChangeStatusUpdatesSeveralTokens(): void
    {
        $this->prepareTable();
        $first = $this->insertToken(1);
        $second = $this->insertToken(1);

        $this->assertTrue($this->repository()->changeStatus([$this->row($first)['id'], $this->row($second)['id']], 'expired'));
        $this->assertSame('expired', $this->row($first)['status']);
        $this->assertSame('expired', $this->row($second)['status']);
    }

    public function testChangeStatusReturnsFalseWhenNothingIsUpdated(): void
    {
        $this->prepareTable();

        $this->assertFalse($this->repository()->changeStatus(999, 'used'));
    }

    public function testChangeStatusRejectsAnEmptyId(): void
    {
        $this->prepareTable();

        $this->expectException(\LengthException::class);
        $this->repository()->changeStatus([], 'used');
    }

    public function testChangeStatusRejectsAnInvalidStatus(): void
    {
        $this->prepareTable();

        $this->expectException(\InvalidArgumentException::class);
        $this->repository()->changeStatus(1, 'deleted');
    }

    public function testChangeStatusRejectsAnAssociativeArray(): void
    {
        $this->prepareTable();

        $this->expectException(\InvalidArgumentException::class);
        $this->repository()->changeStatus(['a' => 1], 'used');
    }

    public function testAdminCanDeleteTokensById(): void
    {
        $this->prepareTable();
        $first = $this->insertToken(1);
        $second = $this->insertToken(2);
        $this->asAdmin();

        $this->assertTrue($this->repository()->delete('id', [$this->row($first)['id'], $this->row($second)['id']]));
        $this->assertSame(0, $this->countTokens());
    }

    public function testDeleteReturnsFalseWhenNothingIsDeleted(): void
    {
        $this->prepareTable();
        $this->asAdmin();

        $this->assertFalse($this->repository()->delete('id', 999));
    }

    public static function invalidAdminDeletes(): array
    {
        return [
            'disallowed column' => ['password', 'x'],
            'empty string' => ['token', '   '],
            'empty array' => ['id', []],
            'associative array' => ['id', ['a' => 1]],
        ];
    }

    #[DataProvider('invalidAdminDeletes')]
    public function testDeleteRejectsInvalidArguments(string $column, string|int|array $value): void
    {
        $this->prepareTable();
        $this->asAdmin();

        $this->expectException(\InvalidArgumentException::class);
        $this->repository()->delete($column, $value);
    }

    public function testUserCanDeleteTheirOwnTokensByUserId(): void
    {
        $this->prepareTable();
        $this->insertToken(1);
        $other = $this->insertToken(2);
        $_SESSION = ['user_id' => 1];

        $this->assertTrue($this->repository()->delete('user_id', 1));
        $this->assertSame(1, $this->countTokens());
        $this->assertNotFalse($this->row($other));
    }

    public function testUserCanDeleteTheirSessionToken(): void
    {
        $this->prepareTable();
        $token = $this->insertToken(1);
        $_SESSION = ['user_id' => 1, 'csrf_token' => $token];

        $this->assertTrue($this->repository()->delete('token', $token));
        $this->assertFalse($this->row($token));
        $this->assertArrayNotHasKey('csrf_token', $_SESSION, 'A successful delete must clear the session token.');
    }

    public static function forbiddenUserDeletes(): array
    {
        return [
            'array value' => ['id', [1]],
            'column other than token or user_id' => ['id', 1],
            "another user's id" => ['user_id', 2],
            'token that is not the session token' => ['token', str_repeat('b', 64)],
        ];
    }

    #[DataProvider('forbiddenUserDeletes')]
    public function testUserCannotDeleteOtherData(string $column, string|int|array $value): void
    {
        $this->prepareTable();
        $token = $this->insertToken(1);
        $_SESSION = ['user_id' => 1, 'csrf_token' => $token];

        $this->expectException(\LogicException::class);
        $this->repository()->delete($column, $value);
    }

    public function testUserTokenDeleteRequiresAValidSessionToken(): void
    {
        $this->prepareTable();
        $token = $this->insertToken(1);
        $_SESSION = ['user_id' => 1, 'csrf_token' => 'not-a-token'];

        $this->expectException(\OutOfRangeException::class);
        $this->repository()->delete('token', $token);
    }

    public function testDeleteUnsafeDeletesTheGivenTokens(): void
    {
        $this->prepareTable();
        $first = $this->insertToken(1);
        $second = $this->insertToken(2);
        $kept = $this->insertToken(3);

        $this->assertTrue($this->repository()->deleteUnsafe('token', [$first, $second]));
        $this->assertSame(1, $this->countTokens());
        $this->assertNotFalse($this->row($kept));
    }

    public function testDeleteUnsafeReturnsFalseWhenNothingIsDeleted(): void
    {
        $this->prepareTable();

        $this->assertFalse($this->repository()->deleteUnsafe('token', [str_repeat('c', 64)]));
    }
}
