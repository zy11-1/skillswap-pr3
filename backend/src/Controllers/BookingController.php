<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BookingController
{
    private const VALID_TRANSITIONS = [
        'Pending'   => ['Accepted', 'Cancelled'],
        'Accepted'  => ['Completed', 'Cancelled'],
        'Completed' => [],
        'Cancelled' => [],
    ];

    /**
     * GET /api/bookings (requires JWT)
     * Returns the authenticated user's bookings — as learner or as tutor,
     * scoped strictly to their own user_id (never another user's data).
     */
    public function index(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');

        // A single account can act as both learner and tutor, so which
        // bookings we return depends on the *mode* the client is in,
        // passed as ?as=learner|tutor (defaults to learner).
        $mode = (string) ($request->getQueryParams()['as'] ?? 'learner');
        $column = $mode === 'tutor' ? 'b.tutor_id' : 'b.learner_id';

        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT b.*, learner.name AS learner_name, tutor.name AS tutor_name, s.name AS skill_name,
                   r.review_id, r.rating AS review_rating, r.comment AS review_comment
            FROM Booking b
            JOIN User learner ON learner.user_id = b.learner_id
            JOIN User tutor ON tutor.user_id = b.tutor_id
            JOIN Skill s ON s.skill_id = b.skill_id
            LEFT JOIN Review r ON r.booking_id = b.booking_id
            WHERE $column = :user_id
            ORDER BY b.booking_date DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        $bookings = $stmt->fetchAll();

        foreach ($bookings as &$b) {
            $b['total_amount'] = (float) $b['total_amount'];
            $b['duration'] = (int) $b['duration'];
            $b['review_id'] = $b['review_id'] !== null ? (int) $b['review_id'] : null;
            $b['review_rating'] = $b['review_rating'] !== null ? (int) $b['review_rating'] : null;
        }

        return $this->json($response, ['data' => $bookings], 200);
    }

    /**
     * POST /api/bookings (requires JWT, learner only)
     * Body: { tutor_id, skill_id, booking_date, duration }
     * total_amount is calculated server-side from the tutor's hourly
     * rate — never trust a price sent by the client.
     */
    public function create(Request $request, Response $response): Response
    {
        $learnerId = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();

        $tutorId = (int) ($data['tutor_id'] ?? 0);
        $skillId = (int) ($data['skill_id'] ?? 0);
        $bookingDate = (string) ($data['booking_date'] ?? '');
        $duration = (int) ($data['duration'] ?? 0);

        if (!$tutorId || !$skillId || !$bookingDate || $duration < 1) {
            return $this->json($response, ['error' => 'tutor_id, skill_id, booking_date, and duration are required.'], 422);
        }

        $bookingTimestamp = strtotime($bookingDate);
        if ($bookingTimestamp === false || $bookingTimestamp < time()) {
            return $this->json($response, ['error' => 'booking_date must be a valid future date/time.'], 422);
        }

        $db = Database::getConnection();

        // Look up the tutor's rate for this skill server-side
        $stmt = $db->prepare(
            'SELECT hourly_rate FROM UserSkill WHERE user_id = :tutor_id AND skill_id = :skill_id'
        );
        $stmt->execute(['tutor_id' => $tutorId, 'skill_id' => $skillId]);
        $offering = $stmt->fetch();

        if (!$offering) {
            return $this->json($response, ['error' => 'This tutor does not offer that skill.'], 404);
        }

        $totalAmount = (float) $offering['hourly_rate'] * $duration;

        $stmt = $db->prepare(
            'INSERT INTO Booking (learner_id, tutor_id, skill_id, booking_date, duration, status, total_amount)
             VALUES (:learner_id, :tutor_id, :skill_id, :booking_date, :duration, \'Pending\', :total_amount)'
        );
        $stmt->execute([
            'learner_id' => $learnerId,
            'tutor_id' => $tutorId,
            'skill_id' => $skillId,
            'booking_date' => date('Y-m-d H:i:s', $bookingTimestamp),
            'duration' => $duration,
            'total_amount' => $totalAmount,
        ]);

        $bookingId = (int) $db->lastInsertId();
        $booking = $this->fetchBookingById($db, $bookingId);

        return $this->json($response, ['data' => $booking], 201);
    }

    /**
     * PATCH /api/bookings/{id}/status (requires JWT, tutor only)
     * Body: { status: 'Accepted' | 'Cancelled' | 'Completed' }
     * Enforces the booking state machine and ownership (a tutor can
     * only update bookings where they are the tutor).
     */
    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $bookingId = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();
        $newStatus = (string) ($data['status'] ?? '');

        $db = Database::getConnection();
        $booking = $this->fetchBookingById($db, $bookingId);

        if (!$booking) {
            return $this->json($response, ['error' => 'Booking not found.'], 404);
        }

        if ((int) $booking['tutor_id'] !== $userId) {
            return $this->json($response, ['error' => 'You can only update your own bookings.'], 403);
        }

        $currentStatus = $booking['status'];
        $allowedNext = self::VALID_TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowedNext, true)) {
            return $this->json($response, [
                'error' => "Cannot move booking from '$currentStatus' to '$newStatus'."
            ], 422);
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('UPDATE Booking SET status = :status WHERE booking_id = :id');
            $stmt->execute(['status' => $newStatus, 'id' => $bookingId]);

            // When a session is marked Completed, settle the wallet:
            // credit the tutor, debit the learner. This keeps the
            // WalletTransaction ledger and User.wallet_balance in sync.
            if ($newStatus === 'Completed') {
                $amount = (float) $booking['total_amount'];

                $stmt = $db->prepare('UPDATE User SET wallet_balance = wallet_balance + :amount WHERE user_id = :id');
                $stmt->execute(['amount' => $amount, 'id' => $booking['tutor_id']]);

                $stmt = $db->prepare('UPDATE User SET wallet_balance = wallet_balance - :amount WHERE user_id = :id');
                $stmt->execute(['amount' => $amount, 'id' => $booking['learner_id']]);

                $stmt = $db->prepare(
                    'INSERT INTO WalletTransaction (user_id, amount, type, booking_id) VALUES (:user_id, :amount, :type, :booking_id)'
                );
                $stmt->execute([
                    'user_id' => $booking['tutor_id'], 'amount' => $amount, 'type' => 'Credit', 'booking_id' => $bookingId
                ]);
                $stmt->execute([
                    'user_id' => $booking['learner_id'], 'amount' => $amount, 'type' => 'Debit', 'booking_id' => $bookingId
                ]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Booking status update failed: ' . $e->getMessage());
            return $this->json($response, ['error' => 'Could not update booking status.'], 500);
        }

        $updated = $this->fetchBookingById($db, $bookingId);
        return $this->json($response, ['data' => $updated], 200);
    }

    private function fetchBookingById(\PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM Booking WHERE booking_id = :id');
        $stmt->execute(['id' => $id]);
        $booking = $stmt->fetch();
        if (!$booking) {
            return null;
        }
        $booking['total_amount'] = (float) $booking['total_amount'];
        $booking['duration'] = (int) $booking['duration'];
        return $booking;
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
