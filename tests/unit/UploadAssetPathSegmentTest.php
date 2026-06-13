<?php

declare(strict_types=1);

namespace westonhancock\editormcp\tests\unit;

use PHPUnit\Framework\TestCase;
use westonhancock\editormcp\tools\impl\UploadAssetTool;

/**
 * Folder path segments come from untrusted input and become VolumeFolder::path.
 * These tests pin the traversal/separator/control-char guard (no Craft needed).
 */
final class UploadAssetPathSegmentTest extends TestCase
{
    private function isSafe(string $segment): bool
    {
        $tool = new UploadAssetTool();
        $method = new \ReflectionMethod($tool, 'isSafePathSegment');
        return $method->invoke($tool, $segment);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function safeProvider(): array
    {
        return [
            'word' => ['uploads'],
            'year' => ['2026'],
            'dashes and underscores' => ['my-folder_1'],
            'dots in name' => ['v1.2'],
            'max length' => [str_repeat('a', 255)],
        ];
    }

    /**
     * @dataProvider safeProvider
     */
    public function testAcceptsNormalSegments(string $segment): void
    {
        self::assertTrue($this->isSafe($segment));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeProvider(): array
    {
        return [
            'parent traversal' => ['..'],
            'current dir' => ['.'],
            'empty' => [''],
            'forward slash' => ['a/b'],
            'backslash' => ['a\\b'],
            'nul byte' => ["a\x00b"],
            'newline' => ["a\nb"],
            'tab' => ["a\tb"],
            'too long' => [str_repeat('a', 256)],
        ];
    }

    /**
     * @dataProvider unsafeProvider
     */
    public function testRejectsUnsafeSegments(string $segment): void
    {
        self::assertFalse($this->isSafe($segment));
    }
}
