<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Tutor self-service verification: a user uploads a document (transcript
 * or certificate) as multipart/form-data; an admin later approves it,
 * which sets User.is_verified = 1. (Should-Have §6.2.1)
 */
class VerificationController
{
    private const UPLOAD_DIR = __DIR__ . '/../../public/uploads';
    private const ALLOWED = ['image/jpeg', 'image/png', 'application/pdf'];
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    /**
     * POST /api/verification (requires JWT)
     * multipart/form-data with a "document" file field.
     */
    public function submit(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');

        /** @var UploadedFileInterface[] $files */
        $files = $request->getUploadedFiles();
        $document = $files['document'] ?? null;

        if (!$document instanceof UploadedFileInterface || $document->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => 'A "document" file is required.'], 422);
        }
        if ($document->getSize() > self::MAX_BYTES) {
            return $this->json($response, ['error' => 'File is too large (max 5 MB).'], 422);
        }
        if (!in_array($document->getClientMediaType(), self::ALLOWED, true)) {
            return $this->json($response, ['error' => 'Only JPG, PNG or PDF files are allowed.'], 422);
        }

        $db = Database::getConnection();

        // Don't allow stacking multiple pending requests.
        $stmt = $db->prepare("SELECT request_id FROM VerificationRequest WHERE user_id = :id AND status = 'Pending'");
        $stmt->execute(['id' => $userId]);
        if ($stmt->fetch()) {
            return $this->json($response, ['error' => 'You already have a verification request pending review.'], 409);
        }

        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0775, true);
        }

        // Build a safe, unique filename and keep the original extension.
        $ext = pathinfo($document->getClientFilename() ?? '', PATHINFO_EXTENSION);
        $ext = preg_replace('/[^a-zA-Z0-9]/', '', (string) $ext) ?: 'dat';
        $filename = sprintf('verif_%d_%s.%s', $userId, bin2hex(random_bytes(6)), $ext);
        $document->moveTo(self::UPLOAD_DIR . '/' . $filename);

        $documentUrl = '/uploads/' . $filename;

        $stmt = $db->prepare(
            "INSERT INTO VerificationRequest (user_id, document_url, status) VALUES (:uid, :url, 'Pending')"
        );
        $stmt->execute(['uid' => $userId, 'url' => $documentUrl]);

        return $this->json($response, [
            'data' => ['request_id' => (int) $db->lastInsertId(), 'document_url' => $documentUrl, 'status' => 'Pending'],
        ], 201);
    }

    /**
     * GET /api/verification/me (requires JWT)
     * The user's latest verification request + their verified status.
     */
    public function myStatus(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT is_verified FROM User WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $isVerified = (int) ($stmt->fetchColumn() ?: 0);

        $stmt = $db->prepare(
            'SELECT request_id, document_url, status, submitted_at
             FROM VerificationRequest WHERE user_id = :id
             ORDER BY submitted_at DESC LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $request_row = $stmt->fetch() ?: null;

        return $this->json($response, [
            'data' => ['is_verified' => $isVerified, 'request' => $request_row],
        ], 200);
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
