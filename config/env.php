<?php

if (!function_exists('appLoadEnv')) {
    function appLoadEnv(string $path): void
    {
        static $loaded = [];

        if (isset($loaded[$path])) {
            return;
        }
        $loaded[$path] = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $name = trim($name);
            if ($name === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name)) {
                continue;
            }

            $value = trim($value);
            $quote = $value[0] ?? '';
            if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
                $value = substr($value, 1, -1);
            } elseif (($commentPos = strpos($value, ' #')) !== false) {
                $value = rtrim(substr($value, 0, $commentPos));
            }

            if (getenv($name) !== false || array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER)) {
                continue;
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

if (!function_exists('appEnv')) {
    function appEnv(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return $value === false ? $default : $value;
    }
}

appLoadEnv(dirname(__DIR__) . '/.env');
