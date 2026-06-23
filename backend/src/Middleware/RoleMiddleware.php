<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Server\MiddlewareInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Restricts a route to one or more roles. Must run AFTER
 * JwtAuthMiddleware, since it reads the 'role' attribute that
 * middleware attaches to the request.
 *
 * Usage in routes.php:
 *   $app->get('/api/admin/users', [AdminController::class, 'list'])
 *       ->add(new RoleMiddleware(['admin']))
 *       ->add($jwtMiddleware);
 */
class RoleMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $allowedRoles;

    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $role = $request->getAttribute('role');

        if (!in_array($role, $this->allowedRoles, true)) {
            $response = new SlimResponse();
            $response->getBody()->write((string) json_encode([
                'error' => 'You do not have permission to access this resource.'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(403);
        }

        return $handler->handle($request);
    }
}
