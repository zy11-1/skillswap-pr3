<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Server\MiddlewareInterface;
use Slim\Psr7\Response as SlimResponse;

class CorsMiddleware implements MiddlewareInterface
{
    private string $allowedOrigin;

    public function __construct(string $allowedOrigin = '*')
    {
        $this->allowedOrigin = $allowedOrigin;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        // ====== 关键改动：OPTIONS 请求直接在中间件处理 ======
        if ($request->getMethod() === 'OPTIONS') {
            $response = new SlimResponse();
            return $response
                ->withHeader('Access-Control-Allow-Origin', $this->allowedOrigin)
                ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
                ->withStatus(200);
        }

        // ====== 其他请求正常处理 ======
        $response = $handler->handle($request);

        // 给所有响应添加 CORS 头（包括错误响应）
        return $response
            ->withHeader('Access-Control-Allow-Origin', $this->allowedOrigin)
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    }
}
