<?php
declare(strict_types=1);

use App\Middleware\CorsMiddleware;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$appConfig = require __DIR__ . '/../config/app.php';

$app = AppFactory::create();

// ============================================================
// 1. Register routes first
// ============================================================
$routes = require __DIR__ . '/../src/routes.php';
$routes($app);

// ============================================================
// 2. Then add middleware (added last, executed first)
// ============================================================
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();
$app->add(new CorsMiddleware($appConfig['cors_origin']));

$displayErrorDetails = ($_ENV['APP_ENV'] ?? 'production') === 'development';
$app->addErrorMiddleware($displayErrorDetails, true, true);

$app->run();
