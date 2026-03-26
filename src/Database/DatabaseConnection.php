<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;

final class DatabaseConnection
{
    public static function createFromEnv(array $env): PDO
    {
        $host = $env['DB_HOST'] ?? '127.0.0.1';
        $port = $env['DB_PORT'] ?? '3306';
        $dbName = $env['DB_NAME'] ?? '';
        $charset = $env['DB_CHARSET'] ?? 'utf8mb4';
        $user = $env['DB_USER'] ?? '';
        $password = $env['DB_PASS'] ?? '';

        if ($dbName === '' || $user === '') {
            throw new RuntimeException('La configuración de base de datos es incompleta.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $dbName, $charset);

        try {
            return new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('No se pudo conectar con MySQL: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
