<?php
declare(strict_types=1);

// public/index.php
//
// This is the single entry point for the whole API. All requests are
// routed through here (your web server / .htaccess should point
// every request at this file - see public/.htaccess).

use App\Middleware\CorsMiddleware;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables from .env (DB credentials, JWT secret, etc.)
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$appConfig = require __DIR__ . '/../config/app.php';

$app = AppFactory::create();

// ---------------------------------------------------------------
// 1. 先注册 OPTIONS 路由（在中间件之前）
// ---------------------------------------------------------------
$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});

// ---------------------------------------------------------------
// 2. 再添加中间件（顺序：后加先执行）
// ---------------------------------------------------------------
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();
$app->add(new CorsMiddleware($appConfig['cors_origin']));

// Show detailed errors only in development — never in production
$displayErrorDetails = true;
$app->addErrorMiddleware($displayErrorDetails, true, true);

// ---------------------------------------------------------------
// Routes
// ---------------------------------------------------------------
$routes = require __DIR__ . '/../src/routes.php';
$routes($app);

$app->run();
