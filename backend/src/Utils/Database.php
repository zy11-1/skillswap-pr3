<?php
declare(strict_types=1);

namespace App\Utils;

use PDO;
use PDOException;

/**
 * Thin wrapper around PDO using the Singleton pattern so the whole
 * app shares one connection. Always use prepared statements
 * (never string-concatenate user input into SQL) to prevent
 * SQL injection, per the PR1 non-functional requirement.
 */
class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../../config/database.php';

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $config['host'],
                $config['port'],
                $config['database']
            );

            try {
                self::$instance = new PDO($dsn, $config['username'], $config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false, // use real prepared statements
                ]);
            } catch (PDOException $e) {
                // Don't leak DB credentials/connection details to the client
                error_log('Database connection failed: ' . $e->getMessage());
                throw new PDOException('Could not connect to the database.');
            }
        }

        return self::$instance;
    }
}
