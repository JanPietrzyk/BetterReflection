<?php

declare(strict_types=1);

namespace Roave\BetterReflectionTest\Util;

use PHPUnit\Framework\TestCase;
use Roave\BetterReflection\Util\FileHelper;

use function strtr;

use const DIRECTORY_SEPARATOR;

/**
 * @covers \Roave\BetterReflection\Util\FileHelper
 */
class FileHelperTest extends TestCase
{
    public function testNormalizeWindowsPath(): void
    {
        self::assertSame('directory/foo/boo/file.php', FileHelper::normalizeWindowsPath('directory\\foo/boo\\file.php'));
        self::assertSame('directory/foo/boo/file.php', FileHelper::normalizeWindowsPath('directory/foo/boo/file.php'));
    }

    public function testSystemWindowsPath(): void
    {
        $path = 'directory\\foo/boo\\foo/file.php';

        self::assertSame(strtr($path, '\\/', DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR), FileHelper::normalizeSystemPath($path));
    }

    public function testSystemWindowsPathWithProtocol(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Test runs only on Windows');
        }

        $path = 'phar://C:/Users/ondrej/phpstan.phar/src/TrinaryLogic.php';
        self::assertSame(
            'phar://C:\Users\ondrej\phpstan.phar\src\TrinaryLogic.php',
            FileHelper::normalizeSystemPath($path),
        );
    }

    public function providePathData(): array {
        return [
            'one-dimension-all-paths' =>  [
                [__DIR__, __DIR__, __DIR__],
                [__DIR__, __DIR__, __DIR__],
            ],
            'one-dimension-no-paths' =>  [
                [__DIR__ . 'abcdefg', __DIR__. 'abcdefg', __DIR__. 'abcdefg'],
                [],
            ],
            'one-dimension-mixed-paths' =>  [
                [__DIR__ . 'abcdefg', __DIR__, __DIR__. 'abcdefg', __DIR__],
                [1 => __DIR__, 3 => __DIR__],
            ],
            'two-dimension-all-paths' =>  [
                [[__DIR__, __DIR__], [__DIR__]],
                [[__DIR__, __DIR__], [__DIR__]],
            ],
            'two-dimension-no-paths' =>  [
                [[__DIR__ . 'abcdefg', __DIR__. 'abcdefg'], [__DIR__. 'abcdefg']],
                [],
            ],
            'two-dimension-mixed-paths' =>  [
                [[__DIR__ . 'abcdefg', __DIR__, __DIR__. 'abcdefg'], [__DIR__ . '/abcdef'], [__DIR__], [__DIR__ . '/abcdef']],
                [[1 => __DIR__], 2 => [__DIR__]],
            ],
            'mixed-dimension-all-paths' =>  [
                [[__DIR__, __DIR__], [__DIR__], __DIR__],
                [[__DIR__, __DIR__], [__DIR__], __DIR__],
            ],
            'mixed-dimension-no-paths' =>  [
                [[__DIR__ . 'abcdefg', __DIR__. 'abcdefg'], [__DIR__. 'abcdefg'], __DIR__ . 'abcdefg'],
                [],
            ],
            'mixed-dimension-mixed-paths' =>  [
                [[__DIR__ . 'abcdefg', __DIR__, __DIR__. 'abcdefg'], [__DIR__ . '/abcdef'], [__DIR__], [__DIR__ . '/abcdef'], __DIR__ . '/abcdef', __DIR__, __DIR__ . '/abcdef'],
                [[1 => __DIR__], 2 => [__DIR__], 5 => __DIR__],
            ],
        ];
    }

    /**
     * @dataProvider providePathData
     */
    public function testPathData(array $paths, array $expected)
    {
        self::assertSame(
            $expected,
            FileHelper::filterMissingDirectoryLeaves($paths)
        );

    }
}
