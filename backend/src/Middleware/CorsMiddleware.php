<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Server\MiddlewareInterface;

class CorsMiddleware implements MiddlewareInterface
{
    private string $allowedOrigin;

    public function __construct(string $allowedOrigin = '*')
    {
        $this->allowedOrigin = $allowedOrigin;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        return $response
            ->withHeader('Access-Control-Allow-Origin', $this->allowedOrigin)
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    }
}
