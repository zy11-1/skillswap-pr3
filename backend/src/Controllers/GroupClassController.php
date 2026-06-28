<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Group classes (Stretch §6.3.4): one tutor teaches several learners at a
 * discounted per-seat price. Enrolling settles payment immediately using
 * the same 90/10 commission split as one-to-one bookings.
 */
class GroupClassController
{
    private const COMMISSION_RATE = 0.10;

    /**
     * GET /api/group-classes (requires JWT)
     * Open, upcoming classes with seats taken + this user's relationship.
     */
    public function index(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT gc.group_class_id, gc.tutor_id, gc.skill_id, gc.title, gc.class_date,
                    gc.duration, gc.capacity, gc.price_per_seat, gc.status,
                    u.name AS tutor_name, s.name AS skill_name,
                    (SELECT COUNT(*) FROM GroupEnrollment ge WHERE ge.group_class_id = gc.group_class_id) AS seats_taken,
                    (SELECT COUNT(*) FROM GroupEnrollment ge2 WHERE ge2.group_class_id = gc.group_class_id AND ge2.learner_id = :me) AS is_enrolled
             FROM GroupClass gc
             JOIN User u ON u.user_id = gc.tutor_id
             JOIN Skill s ON s.skill_id = gc.skill_id
             WHERE gc.status = 'Open'
             ORDER BY gc.class_date ASC"
        );
        $stmt->execute(['me' => $userId]);
        $classes = $stmt->fetchAll();

        foreach ($classes as &$c) {
            $c['price_per_seat'] = (float) $c['price_per_seat'];
            $c['capacity'] = (int) $c['capacity'];
            $c['duration'] = (int) $c['duration'];
            $c['seats_taken'] = (int) $c['seats_taken'];
            $c['is_enrolled'] = ((int) $c['is_enrolled']) > 0;
            $c['is_mine'] = ((int) $c['tutor_id']) === $userId;
        }

        return $this->json($response, ['data' => $classes], 200);
    }

    /**
     * POST /api/group-classes (requires JWT)
     * Body: { skill_id, title, class_date, duration, capacity, price_per_seat }
     */
    public function create(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();

        $skillId = (int) ($data['skill_id'] ?? 0);
        $title = trim((string) ($data['title'] ?? ''));
        $classDate = (string) ($data['class_date'] ?? '');
        $duration = (int) ($data['duration'] ?? 0);
        $capacity = (int) ($data['capacity'] ?? 0);
        $price = (float) ($data['price_per_seat'] ?? 0);

        if (!$skillId || $title === '' || $classDate === '' || $duration < 1 || $capacity < 2 || $price <= 0) {
            return $this->json($response, ['error' => 'All fields are required; capacity must be at least 2.'], 422);
        }

        $ts = strtotime($classDate);
        if ($ts === false || $ts < time()) {
            return $this->json($response, ['error' => 'class_date must be a valid future date/time.'], 422);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT skill_id FROM Skill WHERE skill_id = :id');
        $stmt->execute(['id' => $skillId]);
        if (!$stmt->fetch()) {
            return $this->json($response, ['error' => 'That skill does not exist.'], 404);
        }

        $stmt = $db->prepare(
            "INSERT INTO GroupClass (tutor_id, skill_id, title, class_date, duration, capacity, price_per_seat)
             VALUES (:tutor, :skill, :title, :date, :duration, :capacity, :price)"
        );
        $stmt->execute([
            'tutor' => $userId, 'skill' => $skillId, 'title' => $title,
            'date' => date('Y-m-d H:i:s', $ts), 'duration' => $duration,
            'capacity' => $capacity, 'price' => $price,
        ]);

        return $this->json($response, ['data' => ['group_class_id' => (int) $db->lastInsertId()]], 201);
    }

    /**
     * POST /api/group-classes/{id}/enroll (requires JWT)
     * Learner takes a seat; payment is settled immediately.
     */
    public function enroll(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $classId = (int) $args['id'];
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT * FROM GroupClass WHERE group_class_id = :id');
        $stmt->execute(['id' => $classId]);
        $class = $stmt->fetch();

        if (!$class || $class['status'] !== 'Open') {
            return $this->json($response, ['error' => 'This class is not available.'], 404);
        }
        if ((int) $class['tutor_id'] === $userId) {
            return $this->json($response, ['error' => 'You cannot enrol in your own class.'], 422);
        }

        // Seats remaining?
        $stmt = $db->prepare('SELECT COUNT(*) FROM GroupEnrollment WHERE group_class_id = :id');
        $stmt->execute(['id' => $classId]);
        if ((int) $stmt->fetchColumn() >= (int) $class['capacity']) {
            return $this->json($response, ['error' => 'This class is full.'], 409);
        }

        // Already enrolled?
        $stmt = $db->prepare('SELECT enrollment_id FROM GroupEnrollment WHERE group_class_id = :cid AND learner_id = :lid');
        $stmt->execute(['cid' => $classId, 'lid' => $userId]);
        if ($stmt->fetch()) {
            return $this->json($response, ['error' => 'You are already enrolled in this class.'], 409);
        }

        $price = (float) $class['price_per_seat'];

        // Enough credits?
        $stmt = $db->prepare('SELECT wallet_balance FROM User WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        if ((float) $stmt->fetchColumn() < $price) {
            return $this->json($response, ['error' => 'Insufficient credits to enrol.'], 422);
        }

        $commission = round($price * self::COMMISSION_RATE, 2);
        $tutorNet = round($price - $commission, 2);

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('INSERT INTO GroupEnrollment (group_class_id, learner_id) VALUES (:cid, :lid)');
            $stmt->execute(['cid' => $classId, 'lid' => $userId]);

            $updateBalance = $db->prepare('UPDATE User SET wallet_balance = wallet_balance + :amount WHERE user_id = :id');
            $insertTxn = $db->prepare("INSERT INTO WalletTransaction (user_id, amount, type) VALUES (:user_id, :amount, :type)");

            $updateBalance->execute(['amount' => -$price, 'id' => $userId]);
            $insertTxn->execute(['user_id' => $userId, 'amount' => $price, 'type' => 'Debit']);

            $updateBalance->execute(['amount' => $tutorNet, 'id' => $class['tutor_id']]);
            $insertTxn->execute(['user_id' => $class['tutor_id'], 'amount' => $tutorNet, 'type' => 'Credit']);

            if ($commission > 0) {
                $adminId = $db->query("SELECT user_id FROM User WHERE role = 'admin' ORDER BY user_id LIMIT 1")->fetchColumn();
                if ($adminId) {
                    $updateBalance->execute(['amount' => $commission, 'id' => $adminId]);
                    $insertTxn->execute(['user_id' => $adminId, 'amount' => $commission, 'type' => 'Credit']);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            return $this->json($response, ['error' => 'Could not complete enrolment.'], 500);
        }

        return $this->json($response, ['data' => ['enrolled' => true]], 201);
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
