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

$displayErrorDetails = ($_ENV['APP_ENV'] ?? 'production') === 'development';
$app->addErrorMiddleware($displayErrorDetails, true, true);

// CORS is added LAST so it is the outermost middleware. This way it also wraps
// the error handler — error responses (e.g. a 500) still get CORS headers, so the
// browser shows the real error instead of a misleading "blocked by CORS" message.
$app->add(new CorsMiddleware($appConfig['cors_origin']));

$app->run();
