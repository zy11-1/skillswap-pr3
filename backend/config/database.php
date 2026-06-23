<?php
declare(strict_types=1);

// config/database.php
//
// Reads from environment variables so production (Railway) and local
// (Laragon) setups don't need code changes — just different .env values.

return [
    'host'     => $_ENV['DB_HOST'] ?? 'localhost',
    'port'     => $_ENV['DB_PORT'] ?? '3306',
    'database' => $_ENV['DB_NAME'] ?? 'skillswap',
    'username' => $_ENV['DB_USER'] ?? 'root',
    'password' => $_ENV['DB_PASS'] ?? '',
];
