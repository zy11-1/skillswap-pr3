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

    // Platform commission charged on every completed session (CLO3).
    private const COMMISSION_RATE = 0.10;

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
                   r.review_id, r.rating AS review_rating, r.comment AS review_comment,
                   ta.mode AS slot_mode, ta.meeting_link, ta.location AS slot_location,
                   ta.resources AS slot_resources, ta.outcomes AS slot_outcomes, ta.capacity AS slot_capacity
            FROM Booking b
            JOIN User learner ON learner.user_id = b.learner_id
            JOIN User tutor ON tutor.user_id = b.tutor_id
            JOIN Skill s ON s.skill_id = b.skill_id
            LEFT JOIN Review r ON r.booking_id = b.booking_id
            LEFT JOIN TutorAvailability ta ON ta.availability_id = b.availability_id AND ta.status <> 'Cancelled'
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
        $data = (array) $request->getParsedBody();

        // New slot-based flow: when the learner picks a pre-set availability
        // slot, we handle capacity + auto-accept separately. Falls through to
        // the original free-time flow when no availability_id is given, so
        // the teammate's existing booking path keeps working unchanged.
        if (!empty($data['availability_id'])) {
            return $this->createFromSlot($request, $response, $data);
        }

        $learnerId = (int) $request->getAttribute('user_id');

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
     * Slot-based booking with capacity + auto-accept.
     * Body: { availability_id, skill_id }
     * The session time and length come from the chosen slot. The booking
     * is auto-accepted when the slot still has free seats; otherwise the
     * slot is full and we reject. Row-locks the slot so two learners
     * can't take the last seat at the same time.
     */
    private function createFromSlot(Request $request, Response $response, array $data): Response
    {
        $learnerId = (int) $request->getAttribute('user_id');
        $availabilityId = (int) $data['availability_id'];
        $skillId = (int) ($data['skill_id'] ?? 0);

        if (!$skillId) {
            return $this->json($response, ['error' => 'skill_id is required.'], 422);
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // Lock the slot row for the duration of the transaction.
            $stmt = $db->prepare('SELECT * FROM TutorAvailability WHERE availability_id = :id FOR UPDATE');
            $stmt->execute(['id' => $availabilityId]);
            $slot = $stmt->fetch();

            if (!$slot) {
                $db->rollBack();
                return $this->json($response, ['error' => 'That availability slot no longer exists.'], 404);
            }

            $tutorId = (int) $slot['tutor_id'];

            // Private slots can only be booked by someone with the invite
            // link (the matching share_token must be sent with the booking).
            if (($slot['visibility'] ?? 'Public') === 'Private') {
                $token = (string) ($data['share_token'] ?? '');
                if ($token === '' || !hash_equals((string) $slot['share_token'], $token)) {
                    $db->rollBack();
                    return $this->json($response, ['error' => 'This is a private session — you need a valid invite link to book it.'], 403);
                }
            }

            $bookingDate = $slot['available_date'] . ' ' . $slot['start_time'];
            if (strtotime($bookingDate) < time()) {
                $db->rollBack();
                return $this->json($response, ['error' => 'That slot is in the past.'], 422);
            }

            // Duration comes from the slot length (whole hours, min 1).
            $duration = (int) max(1, round((strtotime($slot['end_time']) - strtotime($slot['start_time'])) / 3600));

            // Price the session from the tutor's rate for this skill.
            $stmt = $db->prepare('SELECT hourly_rate FROM UserSkill WHERE user_id = :tutor_id AND skill_id = :skill_id');
            $stmt->execute(['tutor_id' => $tutorId, 'skill_id' => $skillId]);
            $offering = $stmt->fetch();
            if (!$offering) {
                $db->rollBack();
                return $this->json($response, ['error' => 'This tutor does not offer that skill.'], 404);
            }
            $totalAmount = (float) $offering['hourly_rate'] * $duration;

            // Already booked this slot?
            $stmt = $db->prepare("SELECT booking_id FROM Booking WHERE availability_id = :aid AND learner_id = :lid AND status <> 'Cancelled'");
            $stmt->execute(['aid' => $availabilityId, 'lid' => $learnerId]);
            if ($stmt->fetch()) {
                $db->rollBack();
                return $this->json($response, ['error' => 'You have already booked this slot.'], 409);
            }

            // Does THIS learner hold an active (unexpired) priority offer
            // for this slot? If so they can always claim their reserved seat.
            $stmt = $db->prepare(
                "SELECT priority_id FROM SlotPriority
                 WHERE new_slot_id = :aid AND learner_id = :lid AND status = 'Offered' AND expires_at > NOW()"
            );
            $stmt->execute(['aid' => $availabilityId, 'lid' => $learnerId]);
            $myPriorityId = $stmt->fetchColumn();

            $seatsTaken = (int) $db->query("SELECT COUNT(*) FROM Booking WHERE availability_id = " . (int) $availabilityId . " AND status <> 'Cancelled'")->fetchColumn();

            // Seats still held for OTHER priority holders (unexpired offers).
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM SlotPriority
                 WHERE new_slot_id = :aid AND status = 'Offered' AND expires_at > NOW() AND learner_id <> :lid"
            );
            $stmt->execute(['aid' => $availabilityId, 'lid' => $learnerId]);
            $reservedForOthers = (int) $stmt->fetchColumn();

            $capacity = (int) $slot['capacity'];
            if ($myPriorityId) {
                // Priority holder: only blocked if genuinely no seats at all.
                if ($seatsTaken >= $capacity) {
                    $db->rollBack();
                    return $this->json($response, ['error' => 'This slot is already full.'], 409);
                }
            } else {
                // Regular student: must leave the reserved seats untouched.
                if ($seatsTaken + $reservedForOthers >= $capacity) {
                    $db->rollBack();
                    return $this->json($response, ['error' => 'This slot is full or its seats are reserved for priority students.'], 409);
                }
            }

            // Consume this learner's priority offer, if any.
            if ($myPriorityId) {
                $db->prepare("UPDATE SlotPriority SET status = 'Used' WHERE priority_id = :pid")
                   ->execute(['pid' => $myPriorityId]);
            }

            // Auto-accept slots confirm instantly; otherwise the booking waits
            // as Pending for the tutor to accept or decline.
            $autoAccept = (int) ($slot['auto_accept'] ?? 1) === 1;
            $status = $autoAccept ? 'Accepted' : 'Pending';

            $stmt = $db->prepare(
                "INSERT INTO Booking (learner_id, tutor_id, skill_id, booking_date, duration, status, total_amount, availability_id)
                 VALUES (:learner_id, :tutor_id, :skill_id, :booking_date, :duration, :status, :total_amount, :availability_id)"
            );
            $stmt->execute([
                'learner_id' => $learnerId,
                'tutor_id' => $tutorId,
                'skill_id' => $skillId,
                'booking_date' => date('Y-m-d H:i:s', strtotime($bookingDate)),
                'duration' => $duration,
                'status' => $status,
                'total_amount' => $totalAmount,
                'availability_id' => $availabilityId,
            ]);
            $bookingId = (int) $db->lastInsertId();

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Slot booking failed: ' . $e->getMessage());
            return $this->json($response, ['error' => 'Could not complete the booking.'], 500);
        }

        // Notify the tutor: instant booking vs a request awaiting approval.
        \App\Controllers\MessageController::notify(
            $db, $learnerId, $tutorId,
            $autoAccept
                ? 'A student booked one of your sessions.'
                : 'New booking request awaiting your approval in My Classes.'
        );

        $booking = $this->fetchBookingById($db, $bookingId);
        $booking['auto_accepted'] = $autoAccept;
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

            // When a session is marked Completed, settle the wallet.
            // The learner pays the full amount; the platform takes a 10%
            // commission (CLO3) and the tutor receives the remaining 90%.
            // Crediting the platform/admin account keeps the closed-loop
            // ledger balanced (total credits == total debits).
            if ($newStatus === 'Completed') {
                $amount = (float) $booking['total_amount'];
                $commission = round($amount * self::COMMISSION_RATE, 2);
                $tutorNet = round($amount - $commission, 2);

                $updateBalance = $db->prepare('UPDATE User SET wallet_balance = wallet_balance + :amount WHERE user_id = :id');
                $insertTxn = $db->prepare(
                    'INSERT INTO WalletTransaction (user_id, amount, type, booking_id) VALUES (:user_id, :amount, :type, :booking_id)'
                );

                // Learner pays the full amount.
                $updateBalance->execute(['amount' => -$amount, 'id' => $booking['learner_id']]);
                $insertTxn->execute([
                    'user_id' => $booking['learner_id'], 'amount' => $amount, 'type' => 'Debit', 'booking_id' => $bookingId
                ]);

                // Tutor receives 90%.
                $updateBalance->execute(['amount' => $tutorNet, 'id' => $booking['tutor_id']]);
                $insertTxn->execute([
                    'user_id' => $booking['tutor_id'], 'amount' => $tutorNet, 'type' => 'Credit', 'booking_id' => $bookingId
                ]);

                // Platform keeps the 10% commission (credited to the admin
                // account, if one exists, so revenue is visible in the ledger).
                if ($commission > 0) {
                    $adminStmt = $db->query("SELECT user_id FROM User WHERE role = 'admin' ORDER BY user_id LIMIT 1");
                    $adminId = $adminStmt->fetchColumn();
                    if ($adminId) {
                        $updateBalance->execute(['amount' => $commission, 'id' => $adminId]);
                        $insertTxn->execute([
                            'user_id' => $adminId, 'amount' => $commission, 'type' => 'Credit', 'booking_id' => $bookingId
                        ]);
                    }
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Booking status update failed: ' . $e->getMessage());
            return $this->json($response, ['error' => 'Could not update booking status.'], 500);
        }

        // Notify the learner about the tutor's decision (booking notification).
        $verb = ['Accepted' => 'accepted', 'Cancelled' => 'declined/cancelled', 'Completed' => 'marked completed'][$newStatus] ?? strtolower($newStatus);
        \App\Controllers\MessageController::notify(
            $db, (int) $booking['tutor_id'], (int) $booking['learner_id'],
            "Your session booking was $verb by the tutor."
        );

        $updated = $this->fetchBookingById($db, $bookingId);
        return $this->json($response, ['data' => $updated], 200);
    }

    /**
     * PATCH /api/bookings/{id}/recording (requires JWT, tutor of the booking)
     * Body: { recording_url }
     * Stores an unlisted Zoom/Meet recording link in the booking so the
     * learner can rewatch the session (Stretch §6.3.1, Recording Vault).
     */
    public function setRecording(Request $request, Response $response, array $args): Response
    {
        $bookingId = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();
        $url = trim((string) ($data['recording_url'] ?? ''));

        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->json($response, ['error' => 'recording_url must be a valid URL.'], 422);
        }

        $db = Database::getConnection();
        $booking = $this->fetchBookingById($db, $bookingId);

        if (!$booking) {
            return $this->json($response, ['error' => 'Booking not found.'], 404);
        }
        if ((int) $booking['tutor_id'] !== $userId) {
            return $this->json($response, ['error' => 'Only the tutor for this session can add a recording.'], 403);
        }
        if ($booking['status'] !== 'Completed') {
            return $this->json($response, ['error' => 'You can only attach a recording to a Completed session.'], 422);
        }

        $stmt = $db->prepare('UPDATE Booking SET recording_url = :url WHERE booking_id = :id');
        $stmt->execute(['url' => $url === '' ? null : $url, 'id' => $bookingId]);

        return $this->json($response, ['data' => $this->fetchBookingById($db, $bookingId)], 200);
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
