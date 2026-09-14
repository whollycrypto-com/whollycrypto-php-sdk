<?php

declare(strict_types=1);

// For installations without Composer: require_once this file, keeping src/ beside it.
spl_autoload_register(static function (string $class): void {
    $prefix = 'WhollyCrypto\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    foreach (explode('\\', $relative) as $part) {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $part)) {
            return;
        }
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
