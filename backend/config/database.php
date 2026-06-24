<?php
declare(strict_types=1);

// config/database.php
//
// Reads from environment variables so production (Railway) and local
// (Laragon) setups don't need code changes — just different .env values.

return [
    'host'     => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? getenv('DB_HOST') ?: 'mysql.railway.internal',
    'port'     => $_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? getenv('DB_PORT') ?: '3306',
    'database' => $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? getenv('DB_NAME') ?: 'railway',
    'username' => $_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? getenv('DB_USER') ?: 'root',
    'password' => $_ENV['DB_PASS'] ?? $_SERVER['DB_PASS'] ?? getenv('DB_PASS') ?: '',
];
