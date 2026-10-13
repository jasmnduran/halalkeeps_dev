<?php
/**
 * Minimal .env file loader.
 *
 * Reads KEY=VALUE pairs from the project root .env file and populates
 * both $_ENV and putenv() so getenv() works everywhere in the app.
 *
 * Rules:
 *  - Lines starting with # are comments and are skipped.
 *  - Blank lines are skipped.
 *  - Values may be optionally wrapped in single or double quotes; the
 *    quotes are stripped automatically.
 *  - Existing OS/server environment variables are NOT overwritten, so
 *    production hosting control-panel variables always take precedence.
 *  - Call loadEnv() exactly once, before any getenv() calls (i.e. at
 *    the top of config/database.php or config/constants.php).
 */

function loadEnv(string $path = ''): void
{
    if ($path === '') {
        // Default: project root (one level above /config)
        $path = dirname(__DIR__) . '/.env';
    }

    if (!is_file($path) || !is_readable($path)) {
        // No .env present — rely entirely on server/OS environment vars.
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and lines without an '='
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        // Split on the FIRST '=' only (values may contain '=' themselves)
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip surrounding quotes (single or double)
        if (
            strlen($value) >= 2
            && (
                ($value[0] === '"'  && $value[-1] === '"')
                || ($value[0] === "'" && $value[-1] === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        // Do NOT overwrite values already set by the server environment
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// Auto-load on include so callers don't have to remember to call loadEnv()
loadEnv();

// =============================================================================
// PHP 8.0 polyfills
// These functions are built-in from PHP 8.0. The polyfills below make the app
// run on PHP 7.4 hosts without changing any other code.
// =============================================================================
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || (
            strlen($haystack) >= strlen($needle) &&
            substr_compare($haystack, $needle, -strlen($needle)) === 0
        );
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
