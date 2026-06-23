<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Utils\Jwt;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Server\MiddlewareInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Verifies the "Authorization: Bearer <token>" header on protected
 * routes. On success, the decoded JWT claims (user_id, role) are
 * attached to the request so controllers can read them.
 */
class JwtAuthMiddleware implements MiddlewareInterface
{
    private string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorized('Missing or malformed Authorization header');
        }

        $token = substr($authHeader, 7);

        try {
            $claims = Jwt::decode($token, $this->secret);
        } catch (\Exception $e) {
            return $this->unauthorized('Invalid or expired token');
        }

        // Make the authenticated user's claims available to route handlers
        $request = $request->withAttribute('user_id', $claims['user_id'] ?? null);
        $request = $request->withAttribute('role', $claims['role'] ?? null);

        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write((string) json_encode([
            'error' => $message
        ]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }
}
