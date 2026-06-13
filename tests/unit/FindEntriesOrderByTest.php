<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tests\unit;

use PHPUnit\Framework\TestCase;
use westonhancock\editormcp\tools\impl\FindEntriesTool;
use westonhancock\editormcp\tools\ToolException;

/**
 * The orderBy allowlist is the SQL-injection guard for find_entries. These tests
 * exercise the pure parsing logic directly (no Craft/DB needed) via reflection.
 */
final class FindEntriesOrderByTest extends TestCase
{
    /**
     * @return array<int|string|bool> the parsed [column => SORT_*] spec
     */
    private function parse(string $raw): array
    {
        $tool = new FindEntriesTool();
        $method = new \ReflectionMethod($tool, 'parseOrderBy');
        return $method->invoke($tool, $raw);
    }

    public function testMapsAllowedFieldsAndDirections(): void
    {
        self::assertSame(['postDate' => SORT_DESC], $this->parse('postDate DESC'));
        self::assertSame(['title' => SORT_ASC], $this->parse('title ASC'));
        self::assertSame(['dateUpdated' => SORT_ASC], $this->parse('dateUpdated asc'));
        self::assertSame(['dateCreated' => SORT_DESC], $this->parse('dateCreated desc'));
        self::assertSame(['elements.id' => SORT_ASC], $this->parse('id ASC'));
    }

    public function testDefaultsToDescendingWhenDirectionMissing(): void
    {
        self::assertSame(['postDate' => SORT_DESC], $this->parse('postDate'));
        self::assertSame(['title' => SORT_DESC], $this->parse('title'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectionProvider(): array
    {
        return [
            'subquery' => ['(SELECT CASE WHEN (1=1) THEN title ELSE slug END) ASC'],
            'function call' => ['SLEEP(5)'],
            'unknown column' => ['slug ASC'],
            'raw sql' => ['1; DROP TABLE entries'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider injectionProvider
     */
    public function testRejectsAnythingOutsideTheAllowlist(string $raw): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Unsupported orderBy field');
        $this->parse($raw);
    }
}
