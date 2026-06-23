<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * All methods here are mounted behind RoleMiddleware(['admin']),
 * so $request->getAttribute('role') is guaranteed to be 'admin'
 * by the time these run.
 */
class AdminController
{
    /**
     * GET /api/admin/users
     */
    public function listUsers(Request $request, Response $response): Response
    {
        $db = Database::getConnection();
        $stmt = $db->query(
            'SELECT user_id, name, email, role, faculty, is_verified, wallet_balance, created_at
             FROM User ORDER BY created_at DESC'
        );
        $users = $stmt->fetchAll();

        foreach ($users as &$u) {
            $u['is_verified'] = (int) $u['is_verified'];
            $u['wallet_balance'] = (float) $u['wallet_balance'];
        }

        return $this->json($response, ['data' => $users], 200);
    }

    /**
     * GET /api/admin/verifications/pending
     */
    public function pendingVerifications(Request $request, Response $response): Response
    {
        $db = Database::getConnection();
        $stmt = $db->query(
            "SELECT user_id, name, email, faculty, created_at
             FROM User WHERE role = 'tutor' AND is_verified = 0
             ORDER BY created_at ASC"
        );
        $pending = $stmt->fetchAll();

        return $this->json($response, ['data' => $pending], 200);
    }

    /**
     * PATCH /api/admin/users/{id}/verify
     */
    public function verifyTutor(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['id'];
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT user_id, role FROM User WHERE user_id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return $this->json($response, ['error' => 'User not found.'], 404);
        }
        if ($user['role'] !== 'tutor') {
            return $this->json($response, ['error' => 'Only tutor accounts can be verified.'], 422);
        }

        $stmt = $db->prepare('UPDATE User SET is_verified = 1 WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);

        return $this->json($response, ['data' => ['user_id' => $userId, 'is_verified' => 1]], 200);
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
