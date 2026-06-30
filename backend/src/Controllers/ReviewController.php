<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Reviews & ratings — the learner's side of a Completed booking.
 *
 * Full CRUD:
 *   POST   /api/reviews            create a review (learner, Completed booking, one per booking)
 *   GET    /api/tutors/{id}/reviews  list a tutor's reviews + average rating (public)
 *   PATCH  /api/reviews/{id}       edit your own review
 *   DELETE /api/reviews/{id}       delete your own review
 *
 * A review belongs to a Booking (Review.booking_id is UNIQUE, so there
 * can only ever be one review per booking). The "author" of a review is
 * the learner on that booking — that's who we check ownership against.
 */
class ReviewController
{
    /**
     * POST /api/reviews (requires JWT, learner only)
     * Body: { booking_id, rating (1-5), comment? }
     */
    public function create(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();

        $bookingId = (int) ($data['booking_id'] ?? 0);
        $rating = (int) ($data['rating'] ?? 0);
        $comment = trim((string) ($data['comment'] ?? ''));

        if (!$bookingId) {
            return $this->json($response, ['error' => 'booking_id is required.'], 422);
        }
        if ($rating < 1 || $rating > 5) {
            return $this->json($response, ['error' => 'rating must be a whole number from 1 to 5.'], 422);
        }

        $db = Database::getConnection();

        // The booking must exist, belong to this learner, and the session
        // must already be over (you can review once the class time has ended).
        $stmt = $db->prepare('SELECT learner_id, status, booking_date, duration FROM Booking WHERE booking_id = :id');
        $stmt->execute(['id' => $bookingId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            return $this->json($response, ['error' => 'Booking not found.'], 404);
        }
        if ((int) $booking['learner_id'] !== $userId) {
            return $this->json($response, ['error' => 'You can only review your own bookings.'], 403);
        }
        if (in_array($booking['status'], ['Pending', 'Cancelled'], true)) {
            return $this->json($response, ['error' => 'This session was not held, so it cannot be reviewed.'], 422);
        }
        $endTs = strtotime($booking['booking_date']) + ((int) $booking['duration'] * 3600);
        if ($endTs > time()) {
            return $this->json($response, ['error' => 'You can review once the session has ended.'], 422);
        }

        // One review per booking (DB also enforces this via the UNIQUE
        // constraint — we check first to give a friendlier message).
        $stmt = $db->prepare('SELECT review_id FROM Review WHERE booking_id = :id');
        $stmt->execute(['id' => $bookingId]);
        if ($stmt->fetch()) {
            return $this->json($response, ['error' => 'This booking has already been reviewed.'], 409);
        }

        $stmt = $db->prepare(
            'INSERT INTO Review (booking_id, rating, comment) VALUES (:booking_id, :rating, :comment)'
        );
        $stmt->execute([
            'booking_id' => $bookingId,
            'rating' => $rating,
            'comment' => $comment === '' ? null : $comment,
        ]);

        $reviewId = (int) $db->lastInsertId();
        return $this->json($response, ['data' => $this->fetchReviewById($db, $reviewId)], 201);
    }

    /**
     * GET /api/tutors/{id}/reviews (public)
     * Returns the tutor's reviews plus the average rating and count.
     */
    public function tutorReviews(Request $request, Response $response, array $args): Response
    {
        $tutorId = (int) $args['id'];
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'SELECT r.review_id, r.booking_id, r.rating, r.comment, r.created_at, learner.name AS learner_name
             FROM Review r
             JOIN Booking b ON b.booking_id = r.booking_id
             JOIN User learner ON learner.user_id = b.learner_id
             WHERE b.tutor_id = :id
             ORDER BY r.created_at DESC'
        );
        $stmt->execute(['id' => $tutorId]);
        $reviews = $stmt->fetchAll();

        foreach ($reviews as &$r) {
            $r['rating'] = (int) $r['rating'];
        }
        unset($r);

        $count = count($reviews);
        $average = $count > 0
            ? round(array_sum(array_column($reviews, 'rating')) / $count, 1)
            : 0;

        return $this->json($response, [
            'data' => [
                'average' => $average,
                'count' => $count,
                'reviews' => $reviews,
            ],
        ], 200);
    }

    /**
     * PATCH /api/reviews/{id} (requires JWT, author only)
     * Body: { rating? (1-5), comment? }
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $reviewId = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();

        $db = Database::getConnection();
        $review = $this->fetchReviewWithOwner($db, $reviewId);

        if (!$review) {
            return $this->json($response, ['error' => 'Review not found.'], 404);
        }
        if ((int) $review['learner_id'] !== $userId) {
            return $this->json($response, ['error' => 'You can only edit your own reviews.'], 403);
        }

        // Start from the existing values, override only what was sent.
        $rating = array_key_exists('rating', $data) ? (int) $data['rating'] : (int) $review['rating'];
        $comment = array_key_exists('comment', $data) ? trim((string) $data['comment']) : (string) $review['comment'];

        if ($rating < 1 || $rating > 5) {
            return $this->json($response, ['error' => 'rating must be a whole number from 1 to 5.'], 422);
        }

        $stmt = $db->prepare('UPDATE Review SET rating = :rating, comment = :comment WHERE review_id = :id');
        $stmt->execute([
            'rating' => $rating,
            'comment' => $comment === '' ? null : $comment,
            'id' => $reviewId,
        ]);

        return $this->json($response, ['data' => $this->fetchReviewById($db, $reviewId)], 200);
    }

    /**
     * DELETE /api/reviews/{id} (requires JWT, author only)
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $reviewId = (int) $args['id'];
        $userId = (int) $request->getAttribute('user_id');

        $db = Database::getConnection();
        $review = $this->fetchReviewWithOwner($db, $reviewId);

        if (!$review) {
            return $this->json($response, ['error' => 'Review not found.'], 404);
        }
        if ((int) $review['learner_id'] !== $userId) {
            return $this->json($response, ['error' => 'You can only delete your own reviews.'], 403);
        }

        $stmt = $db->prepare('DELETE FROM Review WHERE review_id = :id');
        $stmt->execute(['id' => $reviewId]);

        return $this->json($response, ['data' => ['deleted' => true]], 200);
    }

    private function fetchReviewById(\PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT review_id, booking_id, rating, comment, created_at FROM Review WHERE review_id = :id');
        $stmt->execute(['id' => $id]);
        $review = $stmt->fetch();
        if (!$review) {
            return null;
        }
        $review['rating'] = (int) $review['rating'];
        return $review;
    }

    /**
     * Fetch a review together with the learner_id of its booking, so we
     * can check who is allowed to edit/delete it.
     */
    private function fetchReviewWithOwner(\PDO $db, int $id): ?array
    {
        $stmt = $db->prepare(
            'SELECT r.review_id, r.rating, r.comment, b.learner_id
             FROM Review r JOIN Booking b ON b.booking_id = r.booking_id
             WHERE r.review_id = :id'
        );
        $stmt->execute(['id' => $id]);
        $review = $stmt->fetch();
        return $review ?: null;
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
