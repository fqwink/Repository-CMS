<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

final class Bootstrap
{
    public static function run(string $publicRoot): void
    {
        $runtime = Runtime::create($publicRoot);
        (new App($runtime))->handle();
    }
}
