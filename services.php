<?php

declare(strict_types=1);

/*
 * Loaded by App\Plugins::registerServices() before the DI container is built.
 * AzuraCast's composer autoloader only knows App\, so register our own PSR-4 loader here.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Plugin\\BranchTracker\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

return [];
