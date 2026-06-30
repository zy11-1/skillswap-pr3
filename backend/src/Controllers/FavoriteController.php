<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Favourite tutors — a learner pins tutors to a quick-access panel.
 */
class FavoriteController
{
    /**
     * GET /api/favorites (requires JWT)
     * The learner's favourited tutors, with one offering each so the
     * marketplace can render a tutor card.
     */
    public function index(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();

        // One representative offering per favourited tutor (cheapest first),
        // shaped like the marketplace cards.
        $stmt = $db->prepare(
            "SELECT us.userskill_id, us.user_id, us.skill_id, us.hourly_rate, us.level, us.description,
                    u.name AS tutor_name, u.photo_url AS tutor_photo, u.faculty AS tutor_faculty, u.is_verified,
                    s.name AS skill_name, s.category AS skill_category,
                    (SELECT ROUND(AVG(r.rating),1) FROM Review r JOIN Booking b ON b.booking_id = r.booking_id WHERE b.tutor_id = u.user_id) AS avg_rating
             FROM Favorite f
             JOIN User u ON u.user_id = f.tutor_id
             JOIN UserSkill us ON us.user_id = u.user_id
             JOIN Skill s ON s.skill_id = us.skill_id
             WHERE f.user_id = :uid
               AND us.userskill_id = (SELECT MIN(us2.userskill_id) FROM UserSkill us2 WHERE us2.user_id = u.user_id)
             ORDER BY f.created_at DESC"
        );
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['hourly_rate'] = (float) $r['hourly_rate'];
            $r['is_verified'] = (int) $r['is_verified'];
        }

        return $this->json($response, ['data' => $rows], 200);
    }

    /**
     * GET /api/favorites/ids (requires JWT)
     * Just the favourited tutor ids, so the UI can show filled/empty hearts.
     */
    public function ids(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT tutor_id FROM Favorite WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'tutor_id'));
        return $this->json($response, ['data' => $ids], 200);
    }

    /**
     * POST /api/favorites/{tutorId} — toggle a tutor as favourite.
     */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $tutorId = (int) $args['tutorId'];

        if ($tutorId === $userId) {
            return $this->json($response, ['error' => 'You cannot favourite yourself.'], 422);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT favorite_id FROM Favorite WHERE user_id = :uid AND tutor_id = :tid');
        $stmt->execute(['uid' => $userId, 'tid' => $tutorId]);

        if ($stmt->fetch()) {
            $db->prepare('DELETE FROM Favorite WHERE user_id = :uid AND tutor_id = :tid')
               ->execute(['uid' => $userId, 'tid' => $tutorId]);
            return $this->json($response, ['data' => ['favorited' => false]], 200);
        }

        // Tutor must exist and not be an admin.
        $stmt = $db->prepare("SELECT user_id FROM User WHERE user_id = :tid AND role <> 'admin'");
        $stmt->execute(['tid' => $tutorId]);
        if (!$stmt->fetch()) {
            return $this->json($response, ['error' => 'Tutor not found.'], 404);
        }

        $db->prepare('INSERT INTO Favorite (user_id, tutor_id) VALUES (:uid, :tid)')
           ->execute(['uid' => $userId, 'tid' => $tutorId]);
        return $this->json($response, ['data' => ['favorited' => true]], 201);
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
