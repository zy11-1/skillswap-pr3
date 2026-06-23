<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class WalletController
{
    /**
     * GET /api/wallet (requires JWT)
     * Returns the authenticated user's current balance.
     */
    public function balance(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT wallet_balance FROM User WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return $this->json($response, ['error' => 'User not found.'], 404);
        }

        return $this->json($response, ['data' => ['balance' => (float) $row['wallet_balance']]], 200);
    }

    /**
     * GET /api/wallet/transactions (requires JWT)
     * Returns the authenticated user's transaction ledger, scoped
     * strictly to their own user_id.
     */
    public function transactions(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'SELECT transaction_id, amount, type, booking_id, created_at
             FROM WalletTransaction
             WHERE user_id = :id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['id' => $userId]);
        $transactions = $stmt->fetchAll();

        foreach ($transactions as &$t) {
            $t['amount'] = (float) $t['amount'];
        }

        return $this->json($response, ['data' => $transactions], 200);
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
