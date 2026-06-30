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

    // Dynamic group pricing: per-hour price can't fall below this floor.
    private const MIN_HOURLY = 10.0;

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
                   ta.resources AS slot_resources, ta.outcomes AS slot_outcomes, ta.capacity AS slot_capacity,
                   ta.topics_covered AS slot_topics, ta.base_price AS slot_base_price
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
        $requestedSkillId = (int) ($data['skill_id'] ?? 0);

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // Lock the slot row for the duration of the transaction. This
            // serialises bookings on the slot, so "first student locks the
            // topic" and the seat-based dynamic price are both race-safe.
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

            // ---- Student-initiated topic --------------------------------
            // A fresh group slot has no topic. The FIRST student to book
            // picks a skill from the tutor's profile, which locks the slot's
            // topic for everyone. Later students inherit that locked topic.
            $lockedSkillId = $slot['locked_skill_id'] !== null ? (int) $slot['locked_skill_id'] : 0;
            $isFirstBooker = $lockedSkillId === 0;

            if ($isFirstBooker) {
                if (!$requestedSkillId) {
                    $db->rollBack();
                    return $this->json($response, ['error' => 'Pick a topic to start this group class.'], 422);
                }
                // The topic must be one of the tutor's listed skills.
                $stmt = $db->prepare('SELECT skill_id FROM UserSkill WHERE user_id = :tutor_id AND skill_id = :skill_id');
                $stmt->execute(['tutor_id' => $tutorId, 'skill_id' => $requestedSkillId]);
                if (!$stmt->fetch()) {
                    $db->rollBack();
                    return $this->json($response, ['error' => 'This tutor does not teach that topic.'], 404);
                }
                $skillId = $requestedSkillId;
            } else {
                // Topic is locked. Block joining until the tutor has written
                // the "Topics covered" syllabus (transparency for students 2-N).
                if ((int) $slot['needs_syllabus'] === 1) {
                    $db->rollBack();
                    return $this->json($response, ['error' => 'The tutor is still finalising this session\'s topic details — check back soon.'], 409);
                }
                $skillId = $lockedSkillId; // ignore any skill the client sent
            }

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

            // ---- Dynamic pricing ----------------------------------------
            // Group classes get cheaper as they fill: the per-hour price drops
            // RM1 for every student who has already booked, with a RM10 floor.
            // Charged now (prepay) at this price; everyone is later equalised
            // to the final lowest price when the session completes.
            $hourly = max(self::MIN_HOURLY, (float) $slot['base_price'] - $seatsTaken);
            $totalAmount = round($hourly * $duration, 2);

            // Booking is always prepay: the learner pays now (held, and refunded
            // if the tutor declines/cancels). Needs the funds in their wallet.
            $stmt = $db->prepare('SELECT wallet_balance FROM User WHERE user_id = :id');
            $stmt->execute(['id' => $learnerId]);
            if ((float) $stmt->fetchColumn() < $totalAmount) {
                $db->rollBack();
                return $this->json($response, ['error' => 'You need RM' . number_format($totalAmount, 2) . ' in your wallet to book this session.'], 422);
            }
            $db->prepare('UPDATE User SET wallet_balance = wallet_balance - :amt WHERE user_id = :id')
               ->execute(['amt' => $totalAmount, 'id' => $learnerId]);

            // Every booking now waits as Pending for the tutor's approval.
            $stmt = $db->prepare(
                "INSERT INTO Booking (learner_id, tutor_id, skill_id, booking_date, duration, status, total_amount, availability_id, is_paid)
                 VALUES (:learner_id, :tutor_id, :skill_id, :booking_date, :duration, 'Pending', :total_amount, :availability_id, 1)"
            );
            $stmt->execute([
                'learner_id' => $learnerId,
                'tutor_id' => $tutorId,
                'skill_id' => $skillId,
                'booking_date' => date('Y-m-d H:i:s', strtotime($bookingDate)),
                'duration' => $duration,
                'total_amount' => $totalAmount,
                'availability_id' => $availabilityId,
            ]);
            $bookingId = (int) $db->lastInsertId();

            // Record the prepay debit on the learner's ledger.
            $db->prepare("INSERT INTO WalletTransaction (user_id, amount, type, booking_id) VALUES (:uid, :amt, 'Debit', :bid)")
               ->execute(['uid' => $learnerId, 'amt' => $totalAmount, 'bid' => $bookingId]);

            // First booker locks the topic and flags the tutor for a syllabus.
            if ($isFirstBooker) {
                $db->prepare('UPDATE TutorAvailability SET locked_skill_id = :sid, needs_syllabus = 1 WHERE availability_id = :aid')
                   ->execute(['sid' => $skillId, 'aid' => $availabilityId]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Slot booking failed: ' . $e->getMessage());
            return $this->json($response, ['error' => 'Could not complete the booking.'], 500);
        }

        // Always a request awaiting the tutor's approval.
        \App\Controllers\MessageController::notify(
            $db, $learnerId, $tutorId,
            'New booking request awaiting your approval in My Classes.'
        );

        // The first booker set the topic — prompt the tutor to publish the
        // "Topics covered" so the rest of the class can join.
        if ($isFirstBooker) {
            $skillName = (string) ($db->query('SELECT name FROM Skill WHERE skill_id = ' . (int) $skillId)->fetchColumn() ?: 'a topic');
            \App\Controllers\MessageController::notify(
                $db, $learnerId, $tutorId,
                "A student started your group class on \"$skillName\". Add a 'Topics covered' description so others can join."
            );
        }

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

        $refunded = false;
        $groupResult = null;
        $db->beginTransaction();
        try {
            if ($newStatus === 'Completed' && $booking['availability_id'] !== null) {
                // Group slot: complete & settle the WHOLE class together so
                // everyone is equalised to the final (lowest) price.
                $groupResult = $this->completeGroupSlot($db, (int) $booking['availability_id']);
            } else {
                $db->prepare('UPDATE Booking SET status = :status WHERE booking_id = :id')
                   ->execute(['status' => $newStatus, 'id' => $bookingId]);

                $amount = (float) $booking['total_amount'];
                $updateBalance = $db->prepare('UPDATE User SET wallet_balance = wallet_balance + :amount WHERE user_id = :id');
                $insertTxn = $db->prepare(
                    'INSERT INTO WalletTransaction (user_id, amount, type, booking_id) VALUES (:user_id, :amount, :type, :booking_id)'
                );

                if ($newStatus === 'Completed') {
                    // Non-slot booking: settle on its own amount (platform 10%, tutor 90%).
                    $commission = round($amount * self::COMMISSION_RATE, 2);
                    $tutorNet = round($amount - $commission, 2);
                    if ((int) $booking['is_paid'] !== 1) {
                        $updateBalance->execute(['amount' => -$amount, 'id' => $booking['learner_id']]);
                        $insertTxn->execute(['user_id' => $booking['learner_id'], 'amount' => $amount, 'type' => 'Debit', 'booking_id' => $bookingId]);
                        $db->prepare('UPDATE Booking SET is_paid = 1 WHERE booking_id = :id')->execute(['id' => $bookingId]);
                    }
                    $updateBalance->execute(['amount' => $tutorNet, 'id' => $booking['tutor_id']]);
                    $insertTxn->execute(['user_id' => $booking['tutor_id'], 'amount' => $tutorNet, 'type' => 'Credit', 'booking_id' => $bookingId]);
                    if ($commission > 0) {
                        $adminId = $db->query("SELECT user_id FROM User WHERE role = 'admin' ORDER BY user_id LIMIT 1")->fetchColumn();
                        if ($adminId) {
                            $updateBalance->execute(['amount' => $commission, 'id' => $adminId]);
                            $insertTxn->execute(['user_id' => $adminId, 'amount' => $commission, 'type' => 'Credit', 'booking_id' => $bookingId]);
                        }
                    }
                } elseif ($newStatus === 'Cancelled' && (int) $booking['is_paid'] === 1) {
                    // Refund a prepaid booking that's being declined/cancelled.
                    $updateBalance->execute(['amount' => $amount, 'id' => $booking['learner_id']]);
                    $insertTxn->execute(['user_id' => $booking['learner_id'], 'amount' => $amount, 'type' => 'Credit', 'booking_id' => $bookingId]);
                    $db->prepare('UPDATE Booking SET is_paid = 0 WHERE booking_id = :id')->execute(['id' => $bookingId]);
                    $refunded = true;
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Booking status update failed: ' . $e->getMessage());
            return $this->json($response, ['error' => 'Could not update booking status.'], 500);
        }

        if ($groupResult !== null) {
            // Group completion: tell every attendee their final price + any
            // refund, and close out anyone whose request was never approved.
            $remindAt = date('Y-m-d H:i:s', time() + 86400);
            foreach ($groupResult['completed'] as $c) {
                $msg = 'Your group class is complete. Final price: RM' . number_format($c['final'], 2) . '.';
                if ($c['refund'] > 0) {
                    $msg .= ' As the class filled up, RM' . number_format($c['refund'], 2) . ' was refunded to your wallet.';
                }
                \App\Controllers\MessageController::notify($db, (int) $booking['tutor_id'], $c['learner_id'], $msg);
                \App\Controllers\MessageController::notify(
                    $db, (int) $booking['tutor_id'], $c['learner_id'],
                    'How was your recent session? Leave a quick review in My Classes.',
                    'booking', $remindAt
                );
            }
            foreach ($groupResult['cancelled'] as $c) {
                \App\Controllers\MessageController::notify(
                    $db, (int) $booking['tutor_id'], $c['learner_id'],
                    'The session ended before your request was approved, so it was closed and RM' . number_format($c['refund'], 2) . ' refunded to your wallet.'
                );
            }
        } else {
            // Single booking: notify the learner of the tutor's decision.
            $verb = ['Accepted' => 'accepted', 'Cancelled' => 'declined/cancelled', 'Completed' => 'marked completed'][$newStatus] ?? strtolower($newStatus);
            \App\Controllers\MessageController::notify(
                $db, (int) $booking['tutor_id'], (int) $booking['learner_id'],
                "Your session booking was $verb by the tutor."
                . ($refunded ? ' Your prepayment of RM' . number_format((float) $booking['total_amount'], 2) . ' has been refunded to your wallet.' : '')
            );
            if ($newStatus === 'Completed') {
                $remindAt = date('Y-m-d H:i:s', time() + 86400);
                \App\Controllers\MessageController::notify(
                    $db, (int) $booking['tutor_id'], (int) $booking['learner_id'],
                    'How was your recent session? Leave a quick review in My Classes.',
                    'booking', $remindAt
                );
            }
        }

        $updated = $this->fetchBookingById($db, $bookingId);
        return $this->json($response, ['data' => $updated], 200);
    }

    /**
     * Complete an entire group slot at once. Every Accepted booking is
     * equalised to the final (lowest) price — the price drops RM1 per extra
     * attendee down to the RM10/hr floor — overpayments are refunded, and the
     * tutor is paid 90% / platform 10% on that final price. Any still-Pending
     * requests are closed and fully refunded. Returns per-learner outcomes
     * so the caller can send notifications after the transaction commits.
     */
    private function completeGroupSlot(\PDO $db, int $availabilityId): array
    {
        $stmt = $db->prepare('SELECT tutor_id, base_price, start_time, end_time FROM TutorAvailability WHERE availability_id = :id');
        $stmt->execute(['id' => $availabilityId]);
        $slot = $stmt->fetch();
        $tutorId = (int) $slot['tutor_id'];
        $duration = (int) max(1, round((strtotime($slot['end_time']) - strtotime($slot['start_time'])) / 3600));

        // Final price is based on how many students actually attended (Accepted).
        $stmt = $db->prepare("SELECT booking_id, learner_id, total_amount FROM Booking WHERE availability_id = :id AND status = 'Accepted'");
        $stmt->execute(['id' => $availabilityId]);
        $attendees = $stmt->fetchAll();

        $finalCount = count($attendees);
        $finalHourly = max(self::MIN_HOURLY, (float) $slot['base_price'] - max(0, $finalCount - 1));
        $finalTotal = round($finalHourly * $duration, 2);
        $commission = round($finalTotal * self::COMMISSION_RATE, 2);
        $tutorNet = round($finalTotal - $commission, 2);
        $adminId = $db->query("SELECT user_id FROM User WHERE role = 'admin' ORDER BY user_id LIMIT 1")->fetchColumn();

        $updateBalance = $db->prepare('UPDATE User SET wallet_balance = wallet_balance + :amount WHERE user_id = :id');
        $insertTxn = $db->prepare('INSERT INTO WalletTransaction (user_id, amount, type, booking_id) VALUES (:user_id, :amount, :type, :booking_id)');
        $completeBooking = $db->prepare("UPDATE Booking SET status = 'Completed', total_amount = :amt WHERE booking_id = :id");

        $completed = [];
        foreach ($attendees as $a) {
            $bookingId = (int) $a['booking_id'];
            $learnerId = (int) $a['learner_id'];
            $charged = (float) $a['total_amount'];
            $refund = round($charged - $finalTotal, 2);

            // Refund the overpayment (everyone paid at booking; final is lowest).
            if ($refund > 0.001) {
                $updateBalance->execute(['amount' => $refund, 'id' => $learnerId]);
                $insertTxn->execute(['user_id' => $learnerId, 'amount' => $refund, 'type' => 'Credit', 'booking_id' => $bookingId]);
            }
            // Pay the tutor (90%) and the platform (10%) on the final price.
            $updateBalance->execute(['amount' => $tutorNet, 'id' => $tutorId]);
            $insertTxn->execute(['user_id' => $tutorId, 'amount' => $tutorNet, 'type' => 'Credit', 'booking_id' => $bookingId]);
            if ($commission > 0 && $adminId) {
                $updateBalance->execute(['amount' => $commission, 'id' => $adminId]);
                $insertTxn->execute(['user_id' => $adminId, 'amount' => $commission, 'type' => 'Credit', 'booking_id' => $bookingId]);
            }
            $completeBooking->execute(['amt' => $finalTotal, 'id' => $bookingId]);
            $completed[] = ['learner_id' => $learnerId, 'final' => $finalTotal, 'refund' => max(0.0, $refund)];
        }

        // Close + fully refund any requests that never got approved.
        $stmt = $db->prepare("SELECT booking_id, learner_id, total_amount FROM Booking WHERE availability_id = :id AND status = 'Pending'");
        $stmt->execute(['id' => $availabilityId]);
        $cancelBooking = $db->prepare("UPDATE Booking SET status = 'Cancelled', is_paid = 0 WHERE booking_id = :id");
        $cancelled = [];
        foreach ($stmt->fetchAll() as $p) {
            $bookingId = (int) $p['booking_id'];
            $learnerId = (int) $p['learner_id'];
            $amt = (float) $p['total_amount'];
            $updateBalance->execute(['amount' => $amt, 'id' => $learnerId]);
            $insertTxn->execute(['user_id' => $learnerId, 'amount' => $amt, 'type' => 'Credit', 'booking_id' => $bookingId]);
            $cancelBooking->execute(['id' => $bookingId]);
            $cancelled[] = ['learner_id' => $learnerId, 'refund' => $amt];
        }

        return ['tutor_id' => $tutorId, 'completed' => $completed, 'cancelled' => $cancelled];
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

        $wasEmpty = empty($booking['recording_url']);
        $stmt = $db->prepare('UPDATE Booking SET recording_url = :url WHERE booking_id = :id');
        $stmt->execute(['url' => $url === '' ? null : $url, 'id' => $bookingId]);

        // Notify the learner when a recording link is newly added — it lands
        // in their bell and "Watch recording" appears on the booking.
        if ($url !== '' && $wasEmpty) {
            \App\Controllers\MessageController::notify(
                $db, $userId, (int) $booking['learner_id'],
                'A session recording is now available — watch it from My Classes.'
            );
        }

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
