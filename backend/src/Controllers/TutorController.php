<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TutorController
{
    /**
     * GET /api/tutors
     * Optional query params: search, skill_id, category, max_price
     * Returns the marketplace listing (UserSkill joined with User + Skill).
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = Database::getConnection();

        $sql = "
            SELECT
                us.userskill_id, us.user_id, us.skill_id, us.hourly_rate, us.level, us.description,
                u.name AS tutor_name, u.photo_url AS tutor_photo, u.faculty AS tutor_faculty, u.is_verified,
                s.name AS skill_name, s.category AS skill_category,
                ROUND(AVG(r.rating), 1) AS avg_rating
            FROM UserSkill us
            JOIN User u ON u.user_id = us.user_id
            JOIN Skill s ON s.skill_id = us.skill_id
            LEFT JOIN Booking b ON b.tutor_id = us.user_id AND b.skill_id = us.skill_id
            LEFT JOIN Review r ON r.booking_id = b.booking_id
            WHERE u.role <> 'admin'
        ";

        $conditions = [];
        $bindings = [];

        if (!empty($params['search'])) {
            // Distinct placeholders: this PDO connection disables emulated
            // prepares, so a named placeholder can't be reused in one query.
            $conditions[] = '(u.name LIKE :search_name OR s.name LIKE :search_skill)';
            $bindings['search_name'] = '%' . $params['search'] . '%';
            $bindings['search_skill'] = '%' . $params['search'] . '%';
        }
        if (!empty($params['skill_id'])) {
            $conditions[] = 'us.skill_id = :skill_id';
            $bindings['skill_id'] = (int) $params['skill_id'];
        }
        if (!empty($params['category'])) {
            $conditions[] = 's.category = :category';
            $bindings['category'] = $params['category'];
        }
        if (!empty($params['max_price'])) {
            $conditions[] = 'us.hourly_rate <= :max_price';
            $bindings['max_price'] = (float) $params['max_price'];
        }

        if (!empty($conditions)) {
            $sql .= ' AND ' . implode(' AND ', $conditions);
        }

        $sql .= ' GROUP BY us.userskill_id ORDER BY u.is_verified DESC, avg_rating DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($bindings);
        $tutors = $stmt->fetchAll();

        // Normalize numeric types (PDO returns strings for DECIMAL columns)
        foreach ($tutors as &$t) {
            $t['hourly_rate'] = (float) $t['hourly_rate'];
            $t['is_verified'] = (int) $t['is_verified'];
        }

        return $this->json($response, ['data' => $tutors], 200);
    }

    /**
     * GET /api/tutors/recommended (requires JWT)
     * Suggests tutors in the same faculty as the logged-in user, ranked
     * by rating — a simple faculty-based recommendation (Stretch §6.3.3).
     */
    public function recommended(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT faculty FROM User WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $faculty = (string) ($stmt->fetchColumn() ?: '');

        if ($faculty === '') {
            return $this->json($response, ['data' => []], 200);
        }

        $stmt = $db->prepare(
            "SELECT us.userskill_id, us.user_id, us.skill_id, us.hourly_rate, us.level, us.description,
                    u.name AS tutor_name, u.photo_url AS tutor_photo, u.faculty AS tutor_faculty, u.is_verified,
                    s.name AS skill_name, s.category AS skill_category,
                    ROUND(AVG(r.rating), 1) AS avg_rating
             FROM UserSkill us
             JOIN User u ON u.user_id = us.user_id
             JOIN Skill s ON s.skill_id = us.skill_id
             LEFT JOIN Booking b ON b.tutor_id = us.user_id AND b.skill_id = us.skill_id
             LEFT JOIN Review r ON r.booking_id = b.booking_id
             WHERE u.role <> 'admin' AND u.faculty = :faculty AND u.user_id != :me
             GROUP BY us.userskill_id
             ORDER BY u.is_verified DESC, avg_rating DESC
             LIMIT 6"
        );
        $stmt->execute(['faculty' => $faculty, 'me' => $userId]);
        $tutors = $stmt->fetchAll();
        foreach ($tutors as &$t) {
            $t['hourly_rate'] = (float) $t['hourly_rate'];
            $t['is_verified'] = (int) $t['is_verified'];
        }

        return $this->json($response, ['data' => $tutors], 200);
    }

    /**
     * GET /api/tutors/{id}
     * Full tutor profile: user info + all offerings + reviews.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $tutorId = (int) $args['id'];
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'SELECT user_id, name, faculty, photo_url, bio, is_verified
             FROM User WHERE user_id = :id AND role <> \'admin\''
        );
        $stmt->execute(['id' => $tutorId]);
        $tutor = $stmt->fetch();

        if (!$tutor) {
            return $this->json($response, ['error' => 'Tutor not found.'], 404);
        }

        $stmt = $db->prepare(
            'SELECT us.userskill_id, us.skill_id, us.hourly_rate, us.level, us.description, s.name AS skill_name
             FROM UserSkill us JOIN Skill s ON s.skill_id = us.skill_id
             WHERE us.user_id = :id'
        );
        $stmt->execute(['id' => $tutorId]);
        $offerings = $stmt->fetchAll();
        foreach ($offerings as &$o) {
            $o['hourly_rate'] = (float) $o['hourly_rate'];
        }

        $stmt = $db->prepare(
            'SELECT r.review_id, r.rating, r.comment, r.created_at
             FROM Review r JOIN Booking b ON b.booking_id = r.booking_id
             WHERE b.tutor_id = :id
             ORDER BY r.created_at DESC'
        );
        $stmt->execute(['id' => $tutorId]);
        $reviews = $stmt->fetchAll();

        $tutor['offerings'] = $offerings;
        $tutor['reviews'] = $reviews;
        $tutor['is_verified'] = (int) $tutor['is_verified'];

        return $this->json($response, ['data' => $tutor], 200);
    }

    /**
     * GET /api/skills
     * Used to populate the filter dropdown in the marketplace UI.
     */
    public function skills(Request $request, Response $response): Response
    {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT skill_id, name, category FROM Skill ORDER BY name');
        $skills = $stmt->fetchAll();

        return $this->json($response, ['data' => $skills], 200);
    }

    /**
     * GET /api/skills/trending
     * Most in-demand skills, ranked by how often they've been booked
     * (a stand-in for search-trend data). Drives the "Trending" chips.
     */
    public function trendingSkills(Request $request, Response $response): Response
    {
        $db = Database::getConnection();
        $stmt = $db->query(
            'SELECT s.skill_id, s.name, s.category, COUNT(b.booking_id) AS booking_count
             FROM Skill s
             LEFT JOIN Booking b ON b.skill_id = s.skill_id
             GROUP BY s.skill_id, s.name, s.category
             ORDER BY booking_count DESC, s.name ASC
             LIMIT 6'
        );
        $skills = $stmt->fetchAll();
        foreach ($skills as &$s) {
            $s['booking_count'] = (int) $s['booking_count'];
        }

        return $this->json($response, ['data' => $skills], 200);
    }

    /**
     * GET /api/tutors/{id}/availability
     * Returns the tutor's available time slots (future only).
     */
    public function getAvailability(Request $request, Response $response, array $args): Response
    {
        $tutorId = (int) $args['id'];
        $db = Database::getConnection();

        // Per slot: active bookings (seats_taken) and seats currently held
        // for priority students whose 12h window hasn't expired (reserved).
        $stmt = $db->prepare(
            "SELECT ta.availability_id, ta.tutor_id, ta.available_date, ta.start_time, ta.end_time, ta.capacity,
                    ta.mode, ta.meeting_link, ta.location, ta.resources, ta.outcomes, ta.status,
                    (SELECT COUNT(*) FROM Booking b
                     WHERE b.availability_id = ta.availability_id AND b.status <> 'Cancelled') AS seats_taken,
                    (SELECT COUNT(*) FROM SlotPriority sp
                     WHERE sp.new_slot_id = ta.availability_id AND sp.status = 'Offered'
                       AND sp.expires_at > NOW()) AS reserved
             FROM TutorAvailability ta
             WHERE ta.tutor_id = :tutor_id AND ta.available_date >= CURDATE() AND ta.status = 'Active'
             ORDER BY ta.available_date, ta.start_time"
        );
        $stmt->execute(['tutor_id' => $tutorId]);
        $slots = $stmt->fetchAll();

        foreach ($slots as &$slot) {
            $slot['capacity'] = (int) $slot['capacity'];
            $slot['seats_taken'] = (int) $slot['seats_taken'];
            $slot['reserved'] = (int) $slot['reserved'];
            // Seats a non-priority student can grab = capacity minus those
            // already booked minus seats still reserved for priority holders.
            $slot['seats_left'] = max(0, $slot['capacity'] - $slot['seats_taken'] - $slot['reserved']);
            $slot['type'] = $slot['capacity'] > 1 ? 'Group' : 'Solo';
            $slot['is_full'] = $slot['seats_left'] <= 0;
        }
        unset($slot);

        return $this->json($response, ['data' => $slots], 200);
    }

    /**
     * POST /api/tutor/availability
     * Body: { available_date, start_time, end_time }
     */
    public function addAvailability(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();

        $date = (string) ($data['available_date'] ?? '');
        $start = (string) ($data['start_time'] ?? '');
        $end = (string) ($data['end_time'] ?? '');
        // Capacity: 1 = Solo session, >1 = Group session. Defaults to 1
        // so older clients that don't send it still create a Solo slot.
        $capacity = (int) ($data['capacity'] ?? 1);
        $mode = ($data['mode'] ?? 'Physical') === 'Online' ? 'Online' : 'Physical';
        $meetingLink = trim((string) ($data['meeting_link'] ?? ''));
        $location = trim((string) ($data['location'] ?? ''));
        $resources = trim((string) ($data['resources'] ?? ''));
        $outcomes = trim((string) ($data['outcomes'] ?? ''));

        if ($date === '' || $start === '' || $end === '') {
            return $this->json($response, ['error' => 'available_date, start_time, end_time are required.'], 422);
        }

        if ($date < date('Y-m-d')) {
            return $this->json($response, ['error' => 'Cannot set availability in the past.'], 422);
        }

        if ($end <= $start) {
            return $this->json($response, ['error' => 'end_time must be after start_time.'], 422);
        }

        if ($capacity < 1) {
            return $this->json($response, ['error' => 'capacity must be at least 1.'], 422);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO TutorAvailability (tutor_id, available_date, start_time, end_time, capacity, mode, meeting_link, location, resources, outcomes)
             VALUES (:tutor_id, :date, :start, :end, :capacity, :mode, :meeting_link, :location, :resources, :outcomes)'
        );
        $stmt->execute([
            'tutor_id' => $userId,
            'date' => $date,
            'start' => $start,
            'end' => $end,
            'capacity' => $capacity,
            'mode' => $mode,
            'meeting_link' => $meetingLink === '' ? null : $meetingLink,
            'location' => $location === '' ? null : $location,
            'resources' => $resources === '' ? null : $resources,
            'outcomes' => $outcomes === '' ? null : $outcomes,
        ]);

        $id = (int) $db->lastInsertId();

        // If this tutor has students waiting from a cancelled slot, offer
        // them this new slot first (12h priority window).
        $this->offerPriorityForNewSlot($db, $userId, $id);

        return $this->json($response, ['data' => ['availability_id' => $id, 'capacity' => $capacity]], 201);
    }

    /**
     * Promote this tutor's "Waiting" priority holders to an offer on the
     * newly created slot, give them a 12-hour window, and message them.
     */
    private function offerPriorityForNewSlot(\PDO $db, int $tutorId, int $newSlotId): void
    {
        $stmt = $db->prepare("SELECT priority_id, learner_id FROM SlotPriority WHERE tutor_id = :tid AND status = 'Waiting'");
        $stmt->execute(['tid' => $tutorId]);
        $waiting = $stmt->fetchAll();
        if (!$waiting) {
            return;
        }

        $slotStmt = $db->prepare('SELECT available_date, start_time FROM TutorAvailability WHERE availability_id = :id');
        $slotStmt->execute(['id' => $newSlotId]);
        $slot = $slotStmt->fetch();
        $when = $slot ? ($slot['available_date'] . ' ' . substr($slot['start_time'], 0, 5)) : 'a new slot';

        $update = $db->prepare(
            "UPDATE SlotPriority SET status = 'Offered', new_slot_id = :sid,
                    expires_at = DATE_ADD(NOW(), INTERVAL 12 HOUR)
             WHERE priority_id = :pid"
        );
        $msg = $db->prepare('INSERT INTO Message (sender_id, receiver_id, body) VALUES (:tutor, :learner, :body)');

        foreach ($waiting as $w) {
            $update->execute(['sid' => $newSlotId, 'pid' => $w['priority_id']]);
            $body = "Priority offer: your tutor opened a new session on $when. You have 12 hours to grab your seat before it opens to everyone else.";
            $msg->execute(['tutor' => $tutorId, 'learner' => $w['learner_id'], 'body' => $body]);
        }
    }

    /**
     * PATCH /api/tutor/availability/{id} (requires JWT, owner only)
     * Edit a slot's capacity, mode and details — but NOT its time. To move
     * a session to a different time the tutor cancels and posts a new slot.
     * Body: { capacity?, mode?, meeting_link?, location?, resources?, outcomes? }
     */
    public function updateAvailability(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $availabilityId = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM TutorAvailability WHERE availability_id = :id');
        $stmt->execute(['id' => $availabilityId]);
        $slot = $stmt->fetch();

        if (!$slot) {
            return $this->json($response, ['error' => 'Slot not found.'], 404);
        }
        if ((int) $slot['tutor_id'] !== $userId) {
            return $this->json($response, ['error' => 'You can only edit your own availability.'], 403);
        }

        $capacity = array_key_exists('capacity', $data) ? (int) $data['capacity'] : (int) $slot['capacity'];
        $mode = array_key_exists('mode', $data) ? (($data['mode'] === 'Online') ? 'Online' : 'Physical') : $slot['mode'];
        $meetingLink = array_key_exists('meeting_link', $data) ? trim((string) $data['meeting_link']) : (string) ($slot['meeting_link'] ?? '');
        $location = array_key_exists('location', $data) ? trim((string) $data['location']) : (string) ($slot['location'] ?? '');
        $resources = array_key_exists('resources', $data) ? trim((string) $data['resources']) : (string) ($slot['resources'] ?? '');
        $outcomes = array_key_exists('outcomes', $data) ? trim((string) $data['outcomes']) : (string) ($slot['outcomes'] ?? '');

        if ($capacity < 1) {
            return $this->json($response, ['error' => 'capacity must be at least 1.'], 422);
        }

        // Can't shrink capacity below the seats already booked.
        $stmt = $db->prepare("SELECT COUNT(*) FROM Booking WHERE availability_id = :id AND status <> 'Cancelled'");
        $stmt->execute(['id' => $availabilityId]);
        $seatsTaken = (int) $stmt->fetchColumn();
        if ($capacity < $seatsTaken) {
            return $this->json($response, ['error' => "Capacity can't be lower than the $seatsTaken seat(s) already booked."], 422);
        }

        $stmt = $db->prepare(
            'UPDATE TutorAvailability
             SET capacity = :capacity, mode = :mode, meeting_link = :meeting_link,
                 location = :location, resources = :resources, outcomes = :outcomes
             WHERE availability_id = :id'
        );
        $stmt->execute([
            'capacity' => $capacity,
            'mode' => $mode,
            'meeting_link' => $meetingLink === '' ? null : $meetingLink,
            'location' => $location === '' ? null : $location,
            'resources' => $resources === '' ? null : $resources,
            'outcomes' => $outcomes === '' ? null : $outcomes,
            'id' => $availabilityId,
        ]);

        return $this->json($response, ['data' => ['availability_id' => $availabilityId, 'capacity' => $capacity, 'mode' => $mode]], 200);
    }

    /**
     * POST /api/tutor/availability/{id}/cancel (requires JWT, owner only)
     * Body: { priority?: bool }
     * Cancels the slot and its bookings, messages every enrolled learner,
     * and — if priority is set — records them as "Waiting" so the tutor's
     * next slot is offered to them first (12h) before opening to all.
     */
    public function cancelAvailability(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $availabilityId = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $givePriority = !empty($data['priority']);

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM TutorAvailability WHERE availability_id = :id');
        $stmt->execute(['id' => $availabilityId]);
        $slot = $stmt->fetch();

        if (!$slot) {
            return $this->json($response, ['error' => 'Slot not found.'], 404);
        }
        if ((int) $slot['tutor_id'] !== $userId) {
            return $this->json($response, ['error' => 'You can only cancel your own availability.'], 403);
        }
        if ($slot['status'] === 'Cancelled') {
            return $this->json($response, ['error' => 'This slot is already cancelled.'], 422);
        }

        $when = $slot['available_date'] . ' ' . substr($slot['start_time'], 0, 5);

        // Learners with an active booking on this slot.
        $stmt = $db->prepare("SELECT learner_id FROM Booking WHERE availability_id = :id AND status <> 'Cancelled'");
        $stmt->execute(['id' => $availabilityId]);
        $learnerIds = array_map('intval', array_column($stmt->fetchAll(), 'learner_id'));

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE TutorAvailability SET status = 'Cancelled' WHERE availability_id = :id")
               ->execute(['id' => $availabilityId]);
            $db->prepare("UPDATE Booking SET status = 'Cancelled' WHERE availability_id = :id AND status <> 'Cancelled'")
               ->execute(['id' => $availabilityId]);

            $msg = $db->prepare('INSERT INTO Message (sender_id, receiver_id, body) VALUES (:tutor, :learner, :body)');
            $prio = $db->prepare(
                "INSERT INTO SlotPriority (tutor_id, learner_id, origin_slot_id, status) VALUES (:tutor, :learner, :origin, 'Waiting')"
            );

            foreach ($learnerIds as $lid) {
                $body = "Your session on $when was cancelled by the tutor."
                    . ($givePriority
                        ? ' You have priority on their next slot — watch for an offer (12 hours to grab your seat).'
                        : '');
                $msg->execute(['tutor' => $userId, 'learner' => $lid, 'body' => $body]);
                if ($givePriority) {
                    $prio->execute(['tutor' => $userId, 'learner' => $lid, 'origin' => $availabilityId]);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Slot cancel failed: ' . $e->getMessage());
            return $this->json($response, ['error' => 'Could not cancel the slot.'], 500);
        }

        return $this->json($response, [
            'data' => ['cancelled' => true, 'students_notified' => count($learnerIds), 'priority' => $givePriority],
        ], 200);
    }

    /**
     * DELETE /api/tutor/availability/{id} (requires JWT, owner only)
     * Removes a slot. Blocked if learners have already booked it, so we
     * don't silently drop active sessions.
     */
    public function deleteAvailability(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $availabilityId = (int) $args['id'];

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT tutor_id FROM TutorAvailability WHERE availability_id = :id');
        $stmt->execute(['id' => $availabilityId]);
        $slot = $stmt->fetch();

        if (!$slot) {
            return $this->json($response, ['error' => 'Slot not found.'], 404);
        }
        if ((int) $slot['tutor_id'] !== $userId) {
            return $this->json($response, ['error' => 'You can only remove your own availability.'], 403);
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM Booking WHERE availability_id = :id AND status <> 'Cancelled'");
        $stmt->execute(['id' => $availabilityId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return $this->json($response, ['error' => 'This slot has active bookings and cannot be removed.'], 409);
        }

        $stmt = $db->prepare('DELETE FROM TutorAvailability WHERE availability_id = :id');
        $stmt->execute(['id' => $availabilityId]);

        return $this->json($response, ['data' => ['deleted' => true]], 200);
    }

    /**
     * GET /api/tutor/skills (requires JWT)
     * The logged-in user's own skill offerings, for the Tutor dashboard.
     */
    public function mySkills(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'SELECT us.userskill_id, us.skill_id, us.hourly_rate, us.level, us.description,
                    s.name AS skill_name, s.category AS skill_category
             FROM UserSkill us JOIN Skill s ON s.skill_id = us.skill_id
             WHERE us.user_id = :id
             ORDER BY s.name'
        );
        $stmt->execute(['id' => $userId]);
        $skills = $stmt->fetchAll();
        foreach ($skills as &$s) {
            $s['hourly_rate'] = (float) $s['hourly_rate'];
        }

        return $this->json($response, ['data' => $skills], 200);
    }

    /**
     * POST /api/tutor/skills (requires JWT)
     * Body: { skill_id, hourly_rate, level, description }
     * Adds an offering for the logged-in user (i.e. makes them a tutor
     * for that skill). One offering per skill per user.
     */
    public function addSkill(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();

        $skillId = (int) ($data['skill_id'] ?? 0);
        $hourlyRate = (float) ($data['hourly_rate'] ?? 0);
        $level = trim((string) ($data['level'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        if (!$skillId || $hourlyRate <= 0 || $level === '' || $description === '') {
            return $this->json($response, ['error' => 'skill_id, hourly_rate, level and description are required.'], 422);
        }

        $db = Database::getConnection();

        // Skill must exist
        $stmt = $db->prepare('SELECT skill_id FROM Skill WHERE skill_id = :id');
        $stmt->execute(['id' => $skillId]);
        if (!$stmt->fetch()) {
            return $this->json($response, ['error' => 'That skill does not exist.'], 404);
        }

        // Prevent duplicate offering of the same skill by the same user
        $stmt = $db->prepare('SELECT userskill_id FROM UserSkill WHERE user_id = :uid AND skill_id = :sid');
        $stmt->execute(['uid' => $userId, 'sid' => $skillId]);
        if ($stmt->fetch()) {
            return $this->json($response, ['error' => 'You already offer this skill.'], 409);
        }

        $stmt = $db->prepare(
            'INSERT INTO UserSkill (user_id, skill_id, hourly_rate, level, description)
             VALUES (:uid, :sid, :rate, :level, :description)'
        );
        $stmt->execute([
            'uid' => $userId, 'sid' => $skillId, 'rate' => $hourlyRate,
            'level' => $level, 'description' => $description,
        ]);

        return $this->json($response, ['data' => ['userskill_id' => (int) $db->lastInsertId()]], 201);
    }

    /**
     * PATCH /api/tutor/skills/{id} (requires JWT, owner only)
     * Body: { hourly_rate?, level?, description? }
     */
    public function updateSkill(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $userSkillId = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM UserSkill WHERE userskill_id = :id');
        $stmt->execute(['id' => $userSkillId]);
        $offering = $stmt->fetch();

        if (!$offering) {
            return $this->json($response, ['error' => 'Offering not found.'], 404);
        }
        if ((int) $offering['user_id'] !== $userId) {
            return $this->json($response, ['error' => 'You can only edit your own offerings.'], 403);
        }

        $hourlyRate = array_key_exists('hourly_rate', $data) ? (float) $data['hourly_rate'] : (float) $offering['hourly_rate'];
        $level = array_key_exists('level', $data) ? trim((string) $data['level']) : (string) $offering['level'];
        $description = array_key_exists('description', $data) ? trim((string) $data['description']) : (string) $offering['description'];

        if ($hourlyRate <= 0 || $level === '' || $description === '') {
            return $this->json($response, ['error' => 'hourly_rate, level and description cannot be empty.'], 422);
        }

        $stmt = $db->prepare('UPDATE UserSkill SET hourly_rate = :rate, level = :level, description = :description WHERE userskill_id = :id');
        $stmt->execute(['rate' => $hourlyRate, 'level' => $level, 'description' => $description, 'id' => $userSkillId]);

        return $this->json($response, ['data' => ['userskill_id' => $userSkillId]], 200);
    }

    /**
     * DELETE /api/tutor/skills/{id} (requires JWT, owner only)
     */
    public function deleteSkill(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $userSkillId = (int) $args['id'];

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT user_id FROM UserSkill WHERE userskill_id = :id');
        $stmt->execute(['id' => $userSkillId]);
        $offering = $stmt->fetch();

        if (!$offering) {
            return $this->json($response, ['error' => 'Offering not found.'], 404);
        }
        if ((int) $offering['user_id'] !== $userId) {
            return $this->json($response, ['error' => 'You can only remove your own offerings.'], 403);
        }

        $stmt = $db->prepare('DELETE FROM UserSkill WHERE userskill_id = :id');
        $stmt->execute(['id' => $userSkillId]);

        return $this->json($response, ['data' => ['deleted' => true]], 200);
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}