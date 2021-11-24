<?php

declare(strict_types=1);

namespace Roave\BetterReflection\Util;

use function preg_match;
use function str_replace;

use const DIRECTORY_SEPARATOR;

class FileHelper
{
    public static function normalizeWindowsPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public static function normalizeSystemPath(string $originalPath): string
    {
        $path = self::normalizeWindowsPath($originalPath);
        preg_match('~^([a-z]+)\\:\\/\\/(.+)~', $path, $matches);
        $scheme = null;
        if ($matches !== []) {
            [, $scheme, $path] = $matches;
        }

        return ($scheme !== null ? $scheme . '://' : '') . str_replace(['/', '\\'], [DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], $path);
    }

    public static function filterMissingDirectoryLeaves(array $list): array
    {
        $recurse = function(array &$list) use (&$recurse) : void {
            foreach($list as &$item) {
                if(is_array($item)) {
                    $recurse($item);
                    continue;
                }

                if(!is_dir($item)) {
                    $item = null;
                }
            }

            $list = array_filter($list);
        };

        $recurse($list);

        return $list;
    }
}
