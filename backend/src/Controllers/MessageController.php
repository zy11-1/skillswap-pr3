<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Asynchronous in-app chat between two users (Should-Have §6.2.2).
 * Simple polling model — no WebSockets. Messages are stored in the
 * Message table (sender_id, receiver_id, body, sent_at).
 */
class MessageController
{
    /**
     * GET /api/messages (requires JWT)
     * One entry per person the user has chatted with, newest first,
     * with a preview of the last message.
     */
    public function conversations(Request $request, Response $response): Response
    {
        $me = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();

        // Pull every message involving me, newest first, then fold into
        // one row per counterpart in PHP (keeps the SQL simple and avoids
        // reusing named placeholders under EMULATE_PREPARES=false).
        // Only human chat belongs in the conversation list; booking/system
        // notifications live in the notification panel, not the chat threads.
        $stmt = $db->prepare(
            "SELECT sender_id, receiver_id, body, sent_at
             FROM Message
             WHERE (sender_id = :me1 OR receiver_id = :me2) AND category = 'chat'
             ORDER BY sent_at DESC"
        );
        $stmt->execute(['me1' => $me, 'me2' => $me]);
        $rows = $stmt->fetchAll();

        $conversations = [];
        foreach ($rows as $row) {
            $otherId = (int) $row['sender_id'] === $me ? (int) $row['receiver_id'] : (int) $row['sender_id'];
            if (!isset($conversations[$otherId])) {
                $conversations[$otherId] = [
                    'user_id' => $otherId,
                    'last_body' => $row['body'],
                    'sent_at' => $row['sent_at'],
                ];
            }
        }

        // Attach names/photos for the counterparts.
        if ($conversations) {
            $ids = array_keys($conversations);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT user_id, name, photo_url FROM User WHERE user_id IN ($placeholders)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $u) {
                $conversations[(int) $u['user_id']]['name'] = $u['name'];
                $conversations[(int) $u['user_id']]['photo_url'] = $u['photo_url'];
            }
        }

        return $this->json($response, ['data' => array_values($conversations)], 200);
    }

    /**
     * GET /api/messages/{userId} (requires JWT)
     * Full thread between me and {userId}, oldest first.
     */
    public function thread(Request $request, Response $response, array $args): Response
    {
        $me = (int) $request->getAttribute('user_id');
        $other = (int) $args['userId'];
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT message_id, sender_id, receiver_id, body, sent_at
             FROM Message
             WHERE ((sender_id = :me1 AND receiver_id = :other1)
                OR (sender_id = :other2 AND receiver_id = :me2)) AND category = 'chat'
             ORDER BY sent_at ASC"
        );
        $stmt->execute(['me1' => $me, 'other1' => $other, 'other2' => $other, 'me2' => $me]);
        $messages = $stmt->fetchAll();

        // Opening a thread marks the other person's chat messages to me as read.
        $mark = $db->prepare("UPDATE Message SET is_read = 1 WHERE receiver_id = :me AND sender_id = :other AND category = 'chat' AND is_read = 0");
        $mark->execute(['me' => $me, 'other' => $other]);

        return $this->json($response, ['data' => $messages], 200);
    }

    /**
     * GET /api/notifications (requires JWT)
     * Unread count + the most recent messages received, for the bell panel.
     */
    public function notifications(Request $request, Response $response): Response
    {
        $me = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT COUNT(*) FROM Message WHERE receiver_id = :me AND is_read = 0');
        $stmt->execute(['me' => $me]);
        $unread = (int) $stmt->fetchColumn();

        $stmt = $db->prepare(
            'SELECT m.message_id, m.sender_id, m.body, m.is_read, m.category, m.sent_at, u.name AS sender_name
             FROM Message m JOIN User u ON u.user_id = m.sender_id
             WHERE m.receiver_id = :me
             ORDER BY m.sent_at DESC LIMIT 30'
        );
        $stmt->execute(['me' => $me]);
        $items = $stmt->fetchAll();
        foreach ($items as &$i) {
            $i['is_read'] = (int) $i['is_read'];
        }
        unset($i);

        return $this->json($response, ['data' => ['unread_count' => $unread, 'items' => $items]], 200);
    }

    /**
     * POST /api/notifications/read (requires JWT)
     * Marks all of my notifications as read (clears the bell).
     */
    public function markAllRead(Request $request, Response $response): Response
    {
        $me = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE Message SET is_read = 1 WHERE receiver_id = :me AND is_read = 0');
        $stmt->execute(['me' => $me]);
        return $this->json($response, ['data' => ['ok' => true]], 200);
    }

    /**
     * Helper other controllers use to drop a categorised notification into a
     * user's bell (booking events, material updates, etc.).
     */
    public static function notify(\PDO $db, int $senderId, int $receiverId, string $body, string $category = 'booking'): void
    {
        $stmt = $db->prepare(
            'INSERT INTO Message (sender_id, receiver_id, body, category) VALUES (:s, :r, :b, :c)'
        );
        $stmt->execute(['s' => $senderId, 'r' => $receiverId, 'b' => $body, 'c' => $category]);
    }

    /**
     * POST /api/messages (requires JWT)
     * Body: { receiver_id, body }
     */
    public function send(Request $request, Response $response): Response
    {
        $me = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();

        $receiverId = (int) ($data['receiver_id'] ?? 0);
        $body = trim((string) ($data['body'] ?? ''));

        if (!$receiverId || $body === '') {
            return $this->json($response, ['error' => 'receiver_id and a non-empty body are required.'], 422);
        }
        if ($receiverId === $me) {
            return $this->json($response, ['error' => 'You cannot message yourself.'], 422);
        }

        $db = Database::getConnection();

        // Receiver must exist.
        $stmt = $db->prepare('SELECT user_id FROM User WHERE user_id = :id');
        $stmt->execute(['id' => $receiverId]);
        if (!$stmt->fetch()) {
            return $this->json($response, ['error' => 'Recipient not found.'], 404);
        }

        $stmt = $db->prepare(
            'INSERT INTO Message (sender_id, receiver_id, body) VALUES (:sender, :receiver, :body)'
        );
        $stmt->execute(['sender' => $me, 'receiver' => $receiverId, 'body' => $body]);

        $id = (int) $db->lastInsertId();
        $stmt = $db->prepare('SELECT message_id, sender_id, receiver_id, body, sent_at FROM Message WHERE message_id = :id');
        $stmt->execute(['id' => $id]);

        return $this->json($response, ['data' => $stmt->fetch()], 201);
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
