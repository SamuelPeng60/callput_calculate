<?php

/**
 * 極簡 PSR-4 風格 autoloader：App\Foo\Bar -> app/Foo/Bar.php
 * (因沙盒網路政策無法連線 Packagist/GitHub 安裝 Composer 套件，
 *  故不依賴 composer autoload；日後搬到有網路的環境可直接改用
 *  composer 的 psr-4 autoload，class 完全不用改)
 */
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require_once __DIR__ . '/app/Support/Env.php';
require_once __DIR__ . '/app/Support/Database.php';

use App\Support\Env;

Env::load(__DIR__ . '/.env');

date_default_timezone_set('Asia/Taipei');
