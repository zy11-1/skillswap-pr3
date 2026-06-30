<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Database;
use App\Utils\Jwt;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    private array $appConfig;

    public function __construct()
    {
        $this->appConfig = require __DIR__ . '/../../config/app.php';
    }

    /**
     * POST /api/auth/register
     * Body: { name, email, password, role, faculty, photo_url? }
     */
    public function register(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        // ---- Input validation (server-side; client also validates) ----
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        // Every public account is a unified "student": they can both learn
        // and tutor from the same login (switched via the in-app mode
        // toggle). We never let registration pick tutor/admin — tutor
        // ability is just having skill offerings; admin is created manually.
        $role = 'learner';
        $faculty = trim((string) ($data['faculty'] ?? ''));
        $yearOfStudy = trim((string) ($data['year_of_study'] ?? ''));
        $photoUrl = trim((string) ($data['photo_url'] ?? ''));

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        }
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }
        if ($faculty === '') {
            $errors[] = 'Faculty is required.';
        }

        if (!empty($errors)) {
            return $this->json($response, ['errors' => $errors], 422);
        }

        $db = Database::getConnection();

        // Check for duplicate email
        $stmt = $db->prepare('SELECT user_id FROM User WHERE email = :email');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            return $this->json($response, ['error' => 'Email is already registered.'], 409);
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $db->prepare(
            'INSERT INTO User (name, email, password_hash, role, faculty, year_of_study, photo_url, wallet_balance, is_verified)
             VALUES (:name, :email, :password_hash, :role, :faculty, :year_of_study, :photo_url, 0.00, 0)'
        );
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
            'faculty' => $faculty,
            'year_of_study' => $yearOfStudy ?: null,
            'photo_url' => $photoUrl ?: null,
        ]);

        $userId = (int) $db->lastInsertId();
        $user = $this->fetchUserById($db, $userId);
        $token = $this->issueToken($user);

        return $this->json($response, ['token' => $token, 'user' => $user], 201);
    }

    /**
     * POST /api/auth/login
     * Body: { email, password }
     */
    public function login(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->json($response, ['error' => 'Email and password are required.'], 422);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM User WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        // Generic error message on purpose — don't reveal whether the
        // email exists or the password was wrong (avoids account enumeration)
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return $this->json($response, ['error' => 'Invalid email or password.'], 401);
        }

        if (isset($user['is_active']) && !(bool) $user['is_active']) {
            return $this->json($response, ['error' => 'This account has been suspended. Please contact the administrator.'], 403);
        }

        unset($user['password_hash']);
        $token = $this->issueToken($user);

        return $this->json($response, ['token' => $token, 'user' => $user], 200);
    }

    /**
     * GET /api/auth/me  (requires JWT)
     * Returns the currently authenticated user's profile.
     */
    public function me(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();
        $user = $this->fetchUserById($db, $userId);

        if (!$user) {
            return $this->json($response, ['error' => 'User not found.'], 404);
        }

        return $this->json($response, ['user' => $user], 200);
    }

    private function fetchUserById(\PDO $db, int $userId): ?array
    {
        $stmt = $db->prepare('SELECT user_id, name, email, role, faculty, year_of_study, photo_url, bio, wallet_balance, is_verified, created_at FROM User WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    private function issueToken(array $user): string
    {
        $payload = [
            'user_id' => (int) $user['user_id'],
            'role' => $user['role'],
            'iat' => time(),
            'exp' => time() + $this->appConfig['jwt_ttl'],
        ];
        return Jwt::encode($payload, $this->appConfig['jwt_secret']);
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
