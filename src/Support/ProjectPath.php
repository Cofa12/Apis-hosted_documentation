<?php

namespace Cofa\ApiDocs\Support;

/**
 * Resolves the configured, project relative output paths against the
 * application root – while leaving absolute paths untouched.
 */
class ProjectPath
{
    public static function resolve(string $path, string $basePath = ''): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        return rtrim(self::base($basePath), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    public static function relative(string $path, string $basePath = ''): string
    {
        $base = self::base($basePath);

        return $base !== '' && str_starts_with($path, $base)
            ? ltrim(substr($path, strlen($base)), DIRECTORY_SEPARATOR)
            : $path;
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    protected static function base(string $basePath): string
    {
        if ($basePath !== '') {
            return $basePath;
        }

        return function_exists('base_path') ? base_path() : (getcwd() ?: '');
    }
}
