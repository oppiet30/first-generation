<?php
declare(strict_types=1);
namespace FirstGeneration\Infrastructure;

use PDO;

final class Database
{
    public static function connect(): PDO
    {
        $config = [];
        $path = dirname(__DIR__, 2) . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) { continue; }
                [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
                $config[trim($key)] = trim($value);
            }
        }
        $get = static fn(string $key, string $default = ''): string =>
            (string)(getenv($key) !== false ? getenv($key) : ($config[$key] ?? $default));
        $host = $get('DB_HOST', '127.0.0.1');
        $port = $get('DB_PORT', '3306');
        $name = $get('DB_NAME', 'first_generation');
        return new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $get('DB_USER', 'first_generation'),
            $get('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
}
