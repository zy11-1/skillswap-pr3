<?php
declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\BookingController;
use App\Controllers\FavoriteController;
use App\Controllers\GroupClassController;
use App\Controllers\MeritController;
use App\Controllers\MessageController;
use App\Controllers\ReviewController;
use App\Controllers\TutorController;
use App\Controllers\VerificationController;
use App\Controllers\WalletController;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\RoleMiddleware;
use Slim\App;

return function (App $app) {
    $appConfig = require __DIR__ . '/../config/app.php';
    $jwtMiddleware = new JwtAuthMiddleware($appConfig['jwt_secret']);

    // ---------------------------------------------------------------
    // Health check
    // ---------------------------------------------------------------
    $app->get('/api/health', function ($request, $response) {
        $response->getBody()->write((string) json_encode(['status' => 'ok']));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ---------------------------------------------------------------
    // Public auth routes 
    // ---------------------------------------------------------------
    $app->post('/api/auth/register', [AuthController::class, 'register']);
    $app->post('/api/auth/login', [AuthController::class, 'login']);

    // Authenticated-only auth route
    $app->get('/api/auth/me', [AuthController::class, 'me'])->add($jwtMiddleware);

    // ---------------------------------------------------------------
    // Public marketplace browsing (no login required to browse —
    // booking itself requires auth, handled below)
    // ---------------------------------------------------------------
    $app->get('/api/tutors', [TutorController::class, 'index']);
    $app->get('/api/tutors/recommended', [TutorController::class, 'recommended'])->add($jwtMiddleware);
    $app->get('/api/tutors/{id}', [TutorController::class, 'show']);
    $app->get('/api/skills', [TutorController::class, 'skills']);
    $app->get('/api/skills/trending', [TutorController::class, 'trendingSkills']);
    // Open a private slot via its invite link token (public)
    $app->get('/api/slots/{token}', [TutorController::class, 'slotByToken']);
    // ---------------------------------------------------------------
// Tutor Availability
// ---------------------------------------------------------------
$app->get('/api/tutors/{id}/availability', [TutorController::class, 'getAvailability']);
$app->post('/api/tutor/availability', [TutorController::class, 'addAvailability'])
    ->add($jwtMiddleware);
$app->patch('/api/tutor/availability/{id}', [TutorController::class, 'updateAvailability'])
    ->add($jwtMiddleware);
$app->patch('/api/tutor/availability/{id}/syllabus', [TutorController::class, 'setSyllabus'])
    ->add($jwtMiddleware);
$app->post('/api/tutor/availability/{id}/cancel', [TutorController::class, 'cancelAvailability'])
    ->add($jwtMiddleware);
$app->delete('/api/tutor/availability/{id}', [TutorController::class, 'deleteAvailability'])
    ->add($jwtMiddleware);

    // ---------------------------------------------------------------
    // Tutor skill offerings (manage the skills you teach)
    // ---------------------------------------------------------------
    $app->group('/api/tutor/skills', function ($group) {
        $group->get('', [TutorController::class, 'mySkills']);
        $group->post('', [TutorController::class, 'addSkill']);
        $group->patch('/{id}', [TutorController::class, 'updateSkill']);
        $group->delete('/{id}', [TutorController::class, 'deleteSkill']);
    })->add($jwtMiddleware);

    // ---------------------------------------------------------------
    // Reviews & ratings
    // Reading a tutor's reviews is public; creating/editing/deleting
    // requires being logged in (the controller checks you own it).
    // ---------------------------------------------------------------
    $app->get('/api/tutors/{id}/reviews', [ReviewController::class, 'tutorReviews']);
    $app->group('/api/reviews', function ($group) {
        $group->post('', [ReviewController::class, 'create']);
        $group->patch('/{id}', [ReviewController::class, 'update']);
        $group->delete('/{id}', [ReviewController::class, 'delete']);
    })->add($jwtMiddleware);

    // ---------------------------------------------------------------
    // Favourite tutors (requires JWT)
    // ---------------------------------------------------------------
    $app->group('/api/favorites', function ($group) {
        $group->get('', [FavoriteController::class, 'index']);
        $group->get('/ids', [FavoriteController::class, 'ids']);
        $group->post('/{tutorId}', [FavoriteController::class, 'toggle']);
    })->add($jwtMiddleware);

    // ---------------------------------------------------------------
    // Bookings (requires JWT)
    // ---------------------------------------------------------------
    $app->group('/api/bookings', function ($group) {
        $group->get('', [BookingController::class, 'index']);
        $group->post('', [BookingController::class, 'create']);
        $group->patch('/{id}/status', [BookingController::class, 'updateStatus']);
        $group->patch('/{id}/recording', [BookingController::class, 'setRecording']);
    })->add($jwtMiddleware);

    // ---------------------------------------------------------------
    // Messages (in-app chat, requires JWT)
    // ---------------------------------------------------------------
    $app->group('/api/messages', function ($group) {
        $group->get('', [MessageController::class, 'conversations']);
        $group->post('', [MessageController::class, 'send']);
        $group->get('/{userId}', [MessageController::class, 'thread']);
    })->add($jwtMiddleware);

    // Notification bell (all categories: chat, booking, system, marketplace)
    $app->get('/api/notifications', [MessageController::class, 'notifications'])->add($jwtMiddleware);
    $app->post('/api/notifications/read', [MessageController::class, 'markAllRead'])->add($jwtMiddleware);

    // ---------------------------------------------------------------
    // Verification (tutor uploads a document; admin approves it)
    // ---------------------------------------------------------------
    $app->group('/api/verification', function ($group) {
        $group->post('', [VerificationController::class, 'submit']);
        $group->get('/me', [VerificationController::class, 'myStatus']);
    })->add($jwtMiddleware);

    // ---------------------------------------------------------------
    // Group classes (one tutor, many learners)
    // ---------------------------------------------------------------
    $app->group('/api/group-classes', function ($group) {
        $group->get('', [GroupClassController::class, 'index']);
        $group->post('', [GroupClassController::class, 'create']);
        $group->post('/{id}/enroll', [GroupClassController::class, 'enroll']);
    })->add($jwtMiddleware);

    // ---------------------------------------------------------------
    // Merit conversion (tutor converts credits -> university merits)
    // ---------------------------------------------------------------
    $app->group('/api/merits', function ($group) {
        $group->get('/standing', [MeritController::class, 'standing']);
        $group->post('/apply', [MeritController::class, 'apply']);
    })->add($jwtMiddleware);

    // ---------------------------------------------------------------
    // Wallet (requires JWT)
    // ---------------------------------------------------------------
    $app->group('/api/wallet', function ($group) {
        $group->get('', [WalletController::class, 'balance']);
        $group->get('/transactions', [WalletController::class, 'transactions']);
    })->add($jwtMiddleware);

    // ---------------------------------------------------------------
    // Admin (requires JWT + admin role)
    // ---------------------------------------------------------------
    $app->group('/api/admin', function ($group) {
        $group->get('/users', [AdminController::class, 'listUsers']);
        $group->get('/verifications/pending', [AdminController::class, 'pendingVerifications']);
        $group->get('/verifications/requests', [AdminController::class, 'verificationRequests']);
        $group->patch('/verifications/requests/{id}', [AdminController::class, 'reviewVerification']);
        $group->patch('/users/{id}/verify', [AdminController::class, 'verifyTutor']);
        $group->get('/merits', [MeritController::class, 'adminList']);
        $group->patch('/merits/{id}', [MeritController::class, 'adminReview']);
    })
        ->add(new RoleMiddleware(['admin']))
        ->add($jwtMiddleware);
};
