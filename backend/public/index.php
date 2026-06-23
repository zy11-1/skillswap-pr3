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

// Slim runs from a subdirectory in some local setups (e.g. Laragon
// virtual hosts) — this keeps route matching correct either way.
// $app->setBasePath('/skillswap-pr3/public');

// ---------------------------------------------------------------
// Global middleware
// ---------------------------------------------------------------
$app->add(new CorsMiddleware($appConfig['cors_origin']));
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// Handle CORS preflight (OPTIONS) requests for every route
$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});

// Show detailed errors only in development — never in production
$displayErrorDetails = ($_ENV['APP_ENV'] ?? 'development') !== 'production';
$app->addErrorMiddleware($displayErrorDetails, true, true);

// ---------------------------------------------------------------
// Routes
// ---------------------------------------------------------------
$routes = require __DIR__ . '/../src/routes.php';
$routes($app);

$app->run();
