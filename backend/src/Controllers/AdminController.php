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
            'SELECT user_id, name, email, role, faculty, year_of_study, is_verified, is_active, wallet_balance, created_at
             FROM User ORDER BY created_at DESC'
        );
        $users = $stmt->fetchAll();

        foreach ($users as &$u) {
            $u['is_verified'] = (int) $u['is_verified'];
            $u['is_active']   = (int) $u['is_active'];
            $u['wallet_balance'] = (float) $u['wallet_balance'];
        }

        return $this->json($response, ['data' => $users], 200);
    }

    /**
     * PATCH /api/admin/users/{id}
     * Body: { role?: 'learner'|'tutor', is_active?: 0|1 }
     * Lets admin suspend/reactivate an account or change its role.
     * Admin accounts are protected from both actions.
     */
    public function updateUser(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['id'];
        $data   = (array) $request->getParsedBody();

        $db   = Database::getConnection();
        $stmt = $db->prepare('SELECT user_id, role FROM User WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return $this->json($response, ['error' => 'User not found.'], 404);
        }
        if ($user['role'] === 'admin') {
            return $this->json($response, ['error' => 'Admin accounts cannot be modified via this endpoint.'], 422);
        }

        $fields = [];
        $params = ['id' => $userId];

        if (array_key_exists('role', $data)) {
            if (!in_array($data['role'], ['learner', 'tutor'], true)) {
                return $this->json($response, ['error' => "role must be 'learner' or 'tutor'."], 422);
            }
            $fields[]      = 'role = :role';
            $params['role'] = $data['role'];
        }

        if (array_key_exists('is_active', $data)) {
            $fields[]           = 'is_active = :is_active';
            $params['is_active'] = (int) (bool) $data['is_active'];
        }

        if (empty($fields)) {
            return $this->json($response, ['error' => 'Nothing to update.'], 422);
        }

        $stmt = $db->prepare('UPDATE User SET ' . implode(', ', $fields) . ' WHERE user_id = :id');
        $stmt->execute($params);

        return $this->json($response, ['data' => ['user_id' => $userId, 'updated' => true]], 200);
    }

    /**
     * DELETE /api/admin/users/{id}
     * Permanently removes a user and all their data (FK cascades handle
     * bookings, reviews, messages, etc.). Admin accounts are protected.
     */
    public function deleteUser(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['id'];
        $db     = Database::getConnection();

        $stmt = $db->prepare('SELECT user_id, role FROM User WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return $this->json($response, ['error' => 'User not found.'], 404);
        }
        if ($user['role'] === 'admin') {
            return $this->json($response, ['error' => 'Admin accounts cannot be deleted.'], 422);
        }

        $stmt = $db->prepare('DELETE FROM User WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);

        return $this->json($response, ['data' => ['user_id' => $userId, 'deleted' => true]], 200);
    }

    /**
     * GET /api/admin/verifications/pending
     */
    public function pendingVerifications(Request $request, Response $response): Response
    {
        $db   = Database::getConnection();
        $stmt = $db->query(
            "SELECT DISTINCT u.user_id, u.name, u.email, u.faculty, u.created_at
             FROM User u JOIN UserSkill us ON us.user_id = u.user_id
             WHERE u.is_verified = 0 AND u.role <> 'admin'
             ORDER BY u.created_at ASC"
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
        $db   = Database::getConnection();
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
     */
    public function reviewVerification(Request $request, Response $response, array $args): Response
    {
        $requestId = (int) $args['id'];
        $data      = (array) $request->getParsedBody();
        $status    = (string) ($data['status'] ?? '');

        if (!in_array($status, ['Approved', 'Rejected'], true)) {
            return $this->json($response, ['error' => "status must be 'Approved' or 'Rejected'."], 422);
        }

        $db   = Database::getConnection();
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
        $db     = Database::getConnection();

        $stmt = $db->prepare("SELECT user_id, role FROM User WHERE user_id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return $this->json($response, ['error' => 'User not found.'], 404);
        }
        if ($user['role'] === 'admin') {
            return $this->json($response, ['error' => 'Admin accounts cannot be verified.'], 422);
        }

        $stmt = $db->prepare('UPDATE User SET is_verified = 1 WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);

        return $this->json($response, ['data' => ['user_id' => $userId, 'is_verified' => 1]], 200);
    }

    /**
     * GET /api/admin/reviews
     * All reviews on the platform, with tutor and learner names, so the
     * admin can spot abusive content.
     */
    public function listReviews(Request $request, Response $response): Response
    {
        $db   = Database::getConnection();
        $stmt = $db->query(
            "SELECT r.review_id, r.rating, r.comment, r.created_at,
                    learner.name AS learner_name, learner.email AS learner_email,
                    tutor.name  AS tutor_name,   tutor.user_id AS tutor_id,
                    s.name AS skill_name
             FROM Review r
             JOIN Booking  b      ON b.booking_id   = r.booking_id
             JOIN User     learner ON learner.user_id = b.learner_id
             JOIN User     tutor   ON tutor.user_id   = b.tutor_id
             JOIN Skill    s       ON s.skill_id       = b.skill_id
             ORDER BY r.created_at DESC"
        );
        $reviews = $stmt->fetchAll();

        foreach ($reviews as &$r) {
            $r['rating'] = (int) $r['rating'];
        }

        return $this->json($response, ['data' => $reviews], 200);
    }

    /**
     * DELETE /api/admin/reviews/{id}
     * Removes an abusive or fake review. The rating is gone from the
     * tutor's average automatically since it's computed from the table.
     */
    public function deleteReview(Request $request, Response $response, array $args): Response
    {
        $reviewId = (int) $args['id'];
        $db       = Database::getConnection();

        $stmt = $db->prepare('SELECT review_id FROM Review WHERE review_id = :id');
        $stmt->execute(['id' => $reviewId]);
        if (!$stmt->fetch()) {
            return $this->json($response, ['error' => 'Review not found.'], 404);
        }

        $stmt = $db->prepare('DELETE FROM Review WHERE review_id = :id');
        $stmt->execute(['id' => $reviewId]);

        return $this->json($response, ['data' => ['deleted' => true]], 200);
    }

    /**
     * GET /api/admin/disputes
     * Returns all bookings that users have flagged as disputed.
     */
    public function listDisputes(Request $request, Response $response): Response
    {
        $db   = Database::getConnection();
        $stmt = $db->query(
            "SELECT b.booking_id, b.dispute_reason, b.dispute_status,
                    b.status AS booking_status, b.total_amount, b.booking_date,
                    b.is_paid, b.payment_timing,
                    learner.user_id AS learner_id, learner.name AS learner_name, learner.email AS learner_email,
                    tutor.user_id   AS tutor_id,   tutor.name   AS tutor_name,
                    s.name AS skill_name
             FROM Booking b
             JOIN User  learner ON learner.user_id = b.learner_id
             JOIN User  tutor   ON tutor.user_id   = b.tutor_id
             JOIN Skill s       ON s.skill_id       = b.skill_id
             WHERE b.dispute_status <> 'none'
             ORDER BY b.booking_date DESC"
        );
        $disputes = $stmt->fetchAll();

        foreach ($disputes as &$d) {
            $d['total_amount'] = (float) $d['total_amount'];
        }

        return $this->json($response, ['data' => $disputes], 200);
    }

    /**
     * PATCH /api/admin/disputes/{id}
     * Body: { resolution: 'refund' | 'close' }
     *   refund — cancels the booking and refunds the learner if they paid.
     *   close  — dismisses the dispute, booking stays as-is.
     */
    public function resolveDispute(Request $request, Response $response, array $args): Response
    {
        $bookingId  = (int) $args['id'];
        $data       = (array) $request->getParsedBody();
        $resolution = (string) ($data['resolution'] ?? '');

        if (!in_array($resolution, ['refund', 'close'], true)) {
            return $this->json($response, ['error' => "resolution must be 'refund' or 'close'."], 422);
        }

        $db   = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM Booking WHERE booking_id = :id');
        $stmt->execute(['id' => $bookingId]);
        $booking = $stmt->fetch();

        if (!$booking || $booking['dispute_status'] === 'none') {
            return $this->json($response, ['error' => 'Disputed booking not found.'], 404);
        }
        if ($booking['dispute_status'] !== 'open') {
            return $this->json($response, ['error' => 'This dispute is already resolved.'], 422);
        }

        $db->beginTransaction();
        try {
            if ($resolution === 'refund') {
                // Cancel the booking and refund any payment already made.
                $db->prepare("UPDATE Booking SET status = 'Cancelled', dispute_status = 'resolved_refund' WHERE booking_id = :id")
                   ->execute(['id' => $bookingId]);

                if ((int) $booking['is_paid'] === 1) {
                    $amount = (float) $booking['total_amount'];
                    $db->prepare('UPDATE User SET wallet_balance = wallet_balance + :amt WHERE user_id = :id')
                       ->execute(['amt' => $amount, 'id' => $booking['learner_id']]);
                    $db->prepare("INSERT INTO WalletTransaction (user_id, amount, type, booking_id) VALUES (:uid, :amt, 'Credit', :bid)")
                       ->execute(['uid' => $booking['learner_id'], 'amt' => $amount, 'bid' => $bookingId]);
                    $db->prepare('UPDATE Booking SET is_paid = 0 WHERE booking_id = :id')
                       ->execute(['id' => $bookingId]);
                }

                MessageController::notify(
                    $db, (int) $booking['tutor_id'], (int) $booking['learner_id'],
                    'An admin has resolved your dispute. Your session was cancelled'
                    . ((int) $booking['is_paid'] === 1
                        ? ' and your payment of RM' . number_format((float) $booking['total_amount'], 2) . ' has been refunded.'
                        : '.')
                );
            } else {
                $db->prepare("UPDATE Booking SET dispute_status = 'resolved_closed' WHERE booking_id = :id")
                   ->execute(['id' => $bookingId]);

                MessageController::notify(
                    $db, (int) $booking['tutor_id'], (int) $booking['learner_id'],
                    'An admin has reviewed your dispute and closed it. No changes were made to the session.'
                );
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Dispute resolution failed: ' . $e->getMessage());
            return $this->json($response, ['error' => 'Could not resolve dispute.'], 500);
        }

        return $this->json($response, ['data' => ['booking_id' => $bookingId, 'resolved' => true, 'resolution' => $resolution]], 200);
    }

    /**
     * GET /api/admin/stats
     * Platform-wide summary numbers for the admin dashboard.
     */
    public function getStats(Request $request, Response $response): Response
    {
        $db = Database::getConnection();

        $totalUsers    = (int) $db->query("SELECT COUNT(*) FROM User WHERE role <> 'admin'")->fetchColumn();
        $totalTutors   = (int) $db->query("SELECT COUNT(DISTINCT user_id) FROM UserSkill")->fetchColumn();
        $totalBookings = (int) $db->query("SELECT COUNT(*) FROM Booking")->fetchColumn();
        $completedBookings = (int) $db->query("SELECT COUNT(*) FROM Booking WHERE status = 'Completed'")->fetchColumn();
        $openDisputes  = (int) $db->query("SELECT COUNT(*) FROM Booking WHERE dispute_status = 'open'")->fetchColumn();
        $pendingVerif  = (int) $db->query(
            "SELECT COUNT(DISTINCT u.user_id) FROM User u JOIN UserSkill us ON us.user_id = u.user_id WHERE u.is_verified = 0 AND u.role <> 'admin'"
        )->fetchColumn();

        // Total commission the platform has earned (sum of admin wallet credits from bookings)
        $adminId = $db->query("SELECT user_id FROM User WHERE role = 'admin' ORDER BY user_id LIMIT 1")->fetchColumn();
        $commission = 0.0;
        if ($adminId) {
            $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM WalletTransaction WHERE user_id = :id AND type = 'Credit'");
            $stmt->execute(['id' => $adminId]);
            $commission = (float) $stmt->fetchColumn();
        }

        $topSkills = $db->query(
            "SELECT s.name, COUNT(b.booking_id) AS bookings
             FROM Booking b JOIN Skill s ON s.skill_id = b.skill_id
             WHERE b.status = 'Completed'
             GROUP BY s.skill_id ORDER BY bookings DESC LIMIT 5"
        )->fetchAll();

        return $this->json($response, ['data' => [
            'total_users'        => $totalUsers,
            'total_tutors'       => $totalTutors,
            'total_bookings'     => $totalBookings,
            'completed_bookings' => $completedBookings,
            'open_disputes'      => $openDisputes,
            'pending_verif'      => $pendingVerif,
            'platform_commission' => $commission,
            'top_skills'         => $topSkills,
        ]], 200);
    }

    /**
     * GET /api/admin/bookings
     * Platform-wide booking list so the admin can see all activity and
     * provide context when mediating disputes.
     */
    public function listAllBookings(Request $request, Response $response): Response
    {
        $db   = Database::getConnection();
        $stmt = $db->query(
            "SELECT b.booking_id, b.status, b.booking_date, b.total_amount,
                    b.payment_timing, b.is_paid, b.dispute_status, b.dispute_reason,
                    learner.name AS learner_name, tutor.name AS tutor_name, s.name AS skill_name
             FROM Booking b
             JOIN User  learner ON learner.user_id = b.learner_id
             JOIN User  tutor   ON tutor.user_id   = b.tutor_id
             JOIN Skill s       ON s.skill_id       = b.skill_id
             ORDER BY b.created_at DESC
             LIMIT 200"
        );
        $bookings = $stmt->fetchAll();

        foreach ($bookings as &$b) {
            $b['total_amount'] = (float) $b['total_amount'];
            $b['is_paid']      = (int)   $b['is_paid'];
        }

        return $this->json($response, ['data' => $bookings], 200);
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
