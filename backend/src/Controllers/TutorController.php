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
            WHERE u.role = 'tutor'
        ";

        $conditions = [];
        $bindings = [];

        if (!empty($params['search'])) {
            $conditions[] = '(u.name LIKE :search OR s.name LIKE :search)';
            $bindings['search'] = '%' . $params['search'] . '%';
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
     * GET /api/tutors/{id}
     * Full tutor profile: user info + all offerings + reviews.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $tutorId = (int) $args['id'];
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'SELECT user_id, name, faculty, photo_url, bio, is_verified
             FROM User WHERE user_id = :id AND role = \'tutor\''
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
     * GET /api/tutors/{id}/availability
     * Returns the tutor's available time slots (future only).
     */
    public function getAvailability(Request $request, Response $response, array $args): Response
    {
        $tutorId = (int) $args['id'];
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'SELECT availability_id, tutor_id, available_date, start_time, end_time
             FROM TutorAvailability
             WHERE tutor_id = :tutor_id AND available_date >= CURDATE()
             ORDER BY available_date, start_time'
        );
        $stmt->execute(['tutor_id' => $tutorId]);
        $slots = $stmt->fetchAll();

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

        if ($date === '' || $start === '' || $end === '') {
            return $this->json($response, ['error' => 'available_date, start_time, end_time are required.'], 422);
        }

        if ($date < date('Y-m-d')) {
            return $this->json($response, ['error' => 'Cannot set availability in the past.'], 422);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO TutorAvailability (tutor_id, available_date, start_time, end_time)
             VALUES (:tutor_id, :date, :start, :end)'
        );
        $stmt->execute([
            'tutor_id' => $userId,
            'date' => $date,
            'start' => $start,
            'end' => $end
        ]);

        $id = (int) $db->lastInsertId();
        return $this->json($response, ['data' => ['availability_id' => $id]], 201);
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}