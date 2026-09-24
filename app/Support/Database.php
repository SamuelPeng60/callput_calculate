<?php

namespace App\Support;

use PDO;

/**
 * PDO 單例連線。透過 .env 的 DB_CONNECTION 切換 sqlite / mysql，
 * 應用程式其他地方完全不用理會底層是哪種資料庫。
 */
class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $driver = Env::get('DB_CONNECTION', 'sqlite');

        if ($driver === 'mysql') {
            $host = Env::get('DB_HOST', '127.0.0.1');
            $port = Env::get('DB_PORT', '3306');
            $db   = Env::get('DB_DATABASE', 'warrant_analyzer');
            $user = Env::get('DB_USERNAME', 'root');
            $pass = Env::get('DB_PASSWORD', '');
            $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } else {
            $path = Env::get('DB_DATABASE', 'database/warrant.sqlite');
            if (!str_starts_with($path, '/')) {
                $path = dirname(__DIR__, 2) . '/' . $path;
            }
            self::$instance = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$instance->exec('PRAGMA foreign_keys = ON');
        }

        return self::$instance;
    }

    public static function migrate(): void
    {
        $pdo = self::connection();
        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        $pdo->exec($schema);
    }
}
