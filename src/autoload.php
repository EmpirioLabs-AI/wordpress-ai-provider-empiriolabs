<?php

/**
 * PSR-4 autoloader for the AI Provider for EmpirioLabs AI plugin.
 *
 * @since 1.0.0
 *
 * @package EmpirioLabs\AiProvider
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    return;
}

spl_autoload_register(static function (string $className): void {
    $prefix = 'EmpirioLabs\\AiProvider\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);

    if (strncmp($className, $prefix, $len) !== 0) {
        return;
    }

    $relativeClass = substr($className, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
