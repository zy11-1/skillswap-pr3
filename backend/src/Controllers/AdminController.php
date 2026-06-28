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
     * GET /api/admin/verifications/requests
     * Document-based verification requests awaiting review.
     */
    public function verificationRequests(Request $request, Response $response): Response
    {
        $db = Database::getConnection();
        $stmt = $db->query(
            "SELECT vr.request_id, vr.user_id, vr.document_url, vr.status, vr.submitted_at,
                    u.name, u.email, u.faculty
             FROM VerificationRequest vr JOIN User u ON u.user_id = vr.user_id
             WHERE vr.status = 'Pending'
             ORDER BY vr.submitted_at ASC"
        );
        return $this->json($response, ['data' => $stmt->fetchAll()], 200);
    }

    /**
     * PATCH /api/admin/verifications/requests/{id}
     * Body: { status: 'Approved' | 'Rejected' }
     * Approving also flips the user's is_verified flag.
     */
    public function reviewVerification(Request $request, Response $response, array $args): Response
    {
        $requestId = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $status = (string) ($data['status'] ?? '');

        if (!in_array($status, ['Approved', 'Rejected'], true)) {
            return $this->json($response, ['error' => "status must be 'Approved' or 'Rejected'."], 422);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT user_id, status FROM VerificationRequest WHERE request_id = :id');
        $stmt->execute(['id' => $requestId]);
        $vr = $stmt->fetch();

        if (!$vr) {
            return $this->json($response, ['error' => 'Verification request not found.'], 404);
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('UPDATE VerificationRequest SET status = :status WHERE request_id = :id');
            $stmt->execute(['status' => $status, 'id' => $requestId]);

            if ($status === 'Approved') {
                $stmt = $db->prepare('UPDATE User SET is_verified = 1 WHERE user_id = :id');
                $stmt->execute(['id' => $vr['user_id']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            return $this->json($response, ['error' => 'Could not update the request.'], 500);
        }

        return $this->json($response, ['data' => ['request_id' => $requestId, 'status' => $status]], 200);
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
