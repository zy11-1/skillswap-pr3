<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * University Merit transfer — earned through teaching performance, NOT money.
 * A tutor becomes eligible to apply once they clear the thresholds below; an
 * admin reviews the performance snapshot and approves or rejects the transfer.
 */
class MeritController
{
    // A credible-but-reachable teaching record. (Kept demo-friendly rather
    // than the proposal's 100/150/100, which would need 150 seed users.)
    private const MIN_CLASSES = 20;
    private const MIN_STUDENTS = 20;
    private const MIN_RATING = 4.0;
    private const MIN_REVIEWS = 15;

    /** Compute a tutor's live teaching stats from existing data. */
    private function stats(\PDO $db, int $tutorId): array
    {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS classes, COUNT(DISTINCT learner_id) AS students
             FROM Booking WHERE tutor_id = :id AND status = 'Completed'"
        );
        $stmt->execute(['id' => $tutorId]);
        $b = $stmt->fetch();

        $stmt = $db->prepare(
            "SELECT COUNT(*) AS cnt, ROUND(AVG(r.rating), 1) AS avg_rating
             FROM Review r JOIN Booking bk ON bk.booking_id = r.booking_id
             WHERE bk.tutor_id = :id"
        );
        $stmt->execute(['id' => $tutorId]);
        $r = $stmt->fetch();

        $classes = (int) $b['classes'];
        $students = (int) $b['students'];
        $reviewCount = (int) $r['cnt'];
        $avg = $r['avg_rating'] !== null ? (float) $r['avg_rating'] : 0.0;

        return [
            'classes_completed' => $classes,
            'students_helped' => $students,
            'avg_rating' => $avg,
            'review_count' => $reviewCount,
            'thresholds' => [
                'classes' => self::MIN_CLASSES,
                'students' => self::MIN_STUDENTS,
                'rating' => self::MIN_RATING,
                'reviews' => self::MIN_REVIEWS,
            ],
            'eligible' => $classes >= self::MIN_CLASSES
                && $students >= self::MIN_STUDENTS
                && $avg >= self::MIN_RATING
                && $reviewCount >= self::MIN_REVIEWS,
        ];
    }

    /**
     * GET /api/merits/standing (requires JWT)
     * The tutor's live merit standing + their latest application status.
     */
    public function standing(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();

        $standing = $this->stats($db, $userId);

        $stmt = $db->prepare(
            'SELECT merit_request_id, status, classes_completed, students_helped, avg_rating, review_count, created_at
             FROM MeritRequest WHERE user_id = :id ORDER BY created_at DESC LIMIT 5'
        );
        $stmt->execute(['id' => $userId]);
        $standing['requests'] = $stmt->fetchAll();
        $standing['has_pending'] = false;
        foreach ($standing['requests'] as $req) {
            if ($req['status'] === 'Pending') {
                $standing['has_pending'] = true;
            }
        }

        return $this->json($response, ['data' => $standing], 200);
    }

    /**
     * POST /api/merits/apply (requires JWT)
     * Apply for a UTM merit transfer — snapshots the current stats.
     */
    public function apply(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();
        $s = $this->stats($db, $userId);

        if (!$s['eligible']) {
            return $this->json($response, ['error' => 'You do not meet the merit thresholds yet.'], 422);
        }

        $stmt = $db->prepare("SELECT merit_request_id FROM MeritRequest WHERE user_id = :id AND status = 'Pending'");
        $stmt->execute(['id' => $userId]);
        if ($stmt->fetch()) {
            return $this->json($response, ['error' => 'You already have a merit application pending review.'], 409);
        }

        $stmt = $db->prepare(
            "INSERT INTO MeritRequest (user_id, status, classes_completed, students_helped, avg_rating, review_count)
             VALUES (:id, 'Pending', :classes, :students, :rating, :reviews)"
        );
        $stmt->execute([
            'id' => $userId,
            'classes' => $s['classes_completed'],
            'students' => $s['students_helped'],
            'rating' => $s['avg_rating'],
            'reviews' => $s['review_count'],
        ]);

        return $this->json($response, ['data' => ['merit_request_id' => (int) $db->lastInsertId(), 'status' => 'Pending']], 201);
    }

    /**
     * GET /api/admin/merits (admin) — pending applications with the snapshot.
     */
    public function adminList(Request $request, Response $response): Response
    {
        $db = Database::getConnection();
        $stmt = $db->query(
            "SELECT mr.merit_request_id, mr.user_id, mr.classes_completed, mr.students_helped,
                    mr.avg_rating, mr.review_count, mr.created_at, u.name, u.faculty
             FROM MeritRequest mr JOIN User u ON u.user_id = mr.user_id
             WHERE mr.status = 'Pending'
             ORDER BY mr.created_at ASC"
        );
        return $this->json($response, ['data' => $stmt->fetchAll()], 200);
    }

    /**
     * GET /api/admin/merits/{id} (admin) — full detail: user info + reviews + result link.
     */
    public function adminDetail(Request $request, Response $response, array $args): Response
    {
        $requestId = (int) $args['id'];
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'SELECT mr.*, u.name, u.email, u.faculty, u.year_of_study, u.photo_url
             FROM MeritRequest mr
             JOIN User u ON u.user_id = mr.user_id
             WHERE mr.merit_request_id = :id'
        );
        $stmt->execute(['id' => $requestId]);
        $mr = $stmt->fetch();

        if (!$mr) {
            return $this->json($response, ['error' => 'Merit application not found.'], 404);
        }

        $stmt = $db->prepare(
            'SELECT r.review_id, r.rating, r.comment, r.created_at, u.name AS learner_name
             FROM Review r
             JOIN Booking b ON b.booking_id = r.booking_id
             JOIN User u ON u.user_id = b.learner_id
             WHERE b.tutor_id = :tutor_id
             ORDER BY r.created_at DESC
             LIMIT 20'
        );
        $stmt->execute(['tutor_id' => (int) $mr['user_id']]);
        $mr['reviews'] = $stmt->fetchAll();

        return $this->json($response, ['data' => $mr], 200);
    }

    /**
     * PATCH /api/admin/merits/{id} (admin) — Body: { status: Approved|Rejected }
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
        $stmt = $db->prepare("SELECT user_id FROM MeritRequest WHERE merit_request_id = :id AND status = 'Pending'");
        $stmt->execute(['id' => $requestId]);
        $mr = $stmt->fetch();
        if (!$mr) {
            return $this->json($response, ['error' => 'Pending merit application not found.'], 404);
        }

        $db->prepare('UPDATE MeritRequest SET status = :status WHERE merit_request_id = :id')
           ->execute(['status' => $status, 'id' => $requestId]);

        // Approval records one granted UTM merit transfer on the tutor's profile.
        if ($status === 'Approved') {
            $db->prepare('UPDATE User SET merit_points = merit_points + 1 WHERE user_id = :id')
               ->execute(['id' => $mr['user_id']]);
        }

        // Let the tutor know the outcome (sent from the admin who reviewed it).
        $adminId = (int) $request->getAttribute('user_id');
        \App\Controllers\MessageController::notify(
            $db, $adminId, (int) $mr['user_id'],
            "Your university merit transfer application was $status.",
            'system'
        );

        return $this->json($response, ['data' => ['merit_request_id' => $requestId, 'status' => $status]], 200);
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
