<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Merit conversion (Stretch §6.3.2): a tutor requests to convert earned
 * platform credits into official university merit points. An admin
 * reviews and approves, which deducts the credits and awards the merits.
 */
class MeritController
{
    // 10 platform credits convert to 1 university merit point.
    private const CREDITS_PER_MERIT = 10;
    private const MIN_CREDITS = 10;

    /**
     * POST /api/merits (requires JWT)
     * Body: { credits }
     */
    public function request(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();
        $credits = (float) ($data['credits'] ?? 0);

        if ($credits < self::MIN_CREDITS) {
            return $this->json($response, ['error' => 'You must convert at least ' . self::MIN_CREDITS . ' credits.'], 422);
        }

        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT wallet_balance FROM User WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $balance = (float) ($stmt->fetchColumn() ?: 0);

        if ($credits > $balance) {
            return $this->json($response, ['error' => 'You do not have enough credits for this conversion.'], 422);
        }

        // Block stacking multiple pending requests.
        $stmt = $db->prepare("SELECT merit_request_id FROM MeritRequest WHERE user_id = :id AND status = 'Pending'");
        $stmt->execute(['id' => $userId]);
        if ($stmt->fetch()) {
            return $this->json($response, ['error' => 'You already have a merit request pending review.'], 409);
        }

        $meritPoints = (int) floor($credits / self::CREDITS_PER_MERIT);

        $stmt = $db->prepare(
            "INSERT INTO MeritRequest (user_id, credits_amount, merit_points, status)
             VALUES (:uid, :credits, :merits, 'Pending')"
        );
        $stmt->execute(['uid' => $userId, 'credits' => $credits, 'merits' => $meritPoints]);

        return $this->json($response, [
            'data' => ['merit_request_id' => (int) $db->lastInsertId(), 'credits_amount' => $credits, 'merit_points' => $meritPoints, 'status' => 'Pending'],
        ], 201);
    }

    /**
     * GET /api/merits/me (requires JWT)
     * The user's merit balance and their conversion requests.
     */
    public function myMerits(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT merit_points, wallet_balance FROM User WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch() ?: ['merit_points' => 0, 'wallet_balance' => 0];

        $stmt = $db->prepare(
            'SELECT merit_request_id, credits_amount, merit_points, status, created_at
             FROM MeritRequest WHERE user_id = :id ORDER BY created_at DESC'
        );
        $stmt->execute(['id' => $userId]);

        return $this->json($response, [
            'data' => [
                'merit_points' => (int) $user['merit_points'],
                'wallet_balance' => (float) $user['wallet_balance'],
                'rate' => self::CREDITS_PER_MERIT,
                'requests' => $stmt->fetchAll(),
            ],
        ], 200);
    }

    /**
     * GET /api/admin/merits (admin)
     */
    public function adminList(Request $request, Response $response): Response
    {
        $db = Database::getConnection();
        $stmt = $db->query(
            "SELECT mr.merit_request_id, mr.user_id, mr.credits_amount, mr.merit_points, mr.created_at,
                    u.name, u.faculty
             FROM MeritRequest mr JOIN User u ON u.user_id = mr.user_id
             WHERE mr.status = 'Pending'
             ORDER BY mr.created_at ASC"
        );
        return $this->json($response, ['data' => $stmt->fetchAll()], 200);
    }

    /**
     * PATCH /api/admin/merits/{id} (admin)
     * Body: { status: 'Approved' | 'Rejected' }
     * Approving deducts the credits and awards the merit points.
     */
    public function adminReview(Request $request, Response $response, array $args): Response
    {
        $requestId = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $status = (string) ($data['status'] ?? '');

        if (!in_array($status, ['Approved', 'Rejected'], true)) {
            return $this->json($response, ['error' => "status must be 'Approved' or 'Rejected'."], 422);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM MeritRequest WHERE merit_request_id = :id AND status = 'Pending'");
        $stmt->execute(['id' => $requestId]);
        $mr = $stmt->fetch();

        if (!$mr) {
            return $this->json($response, ['error' => 'Pending merit request not found.'], 404);
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('UPDATE MeritRequest SET status = :status WHERE merit_request_id = :id');
            $stmt->execute(['status' => $status, 'id' => $requestId]);

            if ($status === 'Approved') {
                $credits = (float) $mr['credits_amount'];

                // Re-check balance at approval time.
                $stmt = $db->prepare('SELECT wallet_balance FROM User WHERE user_id = :id');
                $stmt->execute(['id' => $mr['user_id']]);
                if ((float) $stmt->fetchColumn() < $credits) {
                    $db->rollBack();
                    return $this->json($response, ['error' => 'User no longer has enough credits.'], 422);
                }

                $stmt = $db->prepare('UPDATE User SET wallet_balance = wallet_balance - :credits, merit_points = merit_points + :merits WHERE user_id = :id');
                $stmt->execute(['credits' => $credits, 'merits' => (int) $mr['merit_points'], 'id' => $mr['user_id']]);

                $stmt = $db->prepare(
                    "INSERT INTO WalletTransaction (user_id, amount, type) VALUES (:uid, :amount, 'Debit')"
                );
                $stmt->execute(['uid' => $mr['user_id'], 'amount' => $credits]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            return $this->json($response, ['error' => 'Could not process the merit request.'], 500);
        }

        return $this->json($response, ['data' => ['merit_request_id' => $requestId, 'status' => $status]], 200);
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
