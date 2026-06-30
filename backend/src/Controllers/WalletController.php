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

    /**
     * POST /api/wallet/topup (requires JWT)
     * Body: { amount, card_last4? }
     * Demo "refill from a linked bank card": credits the wallet and records a
     * Credit on the ledger. This is the scaffolding a real card gateway would
     * plug into — no actual money moves, the card is taken on trust here.
     */
    public function topUp(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            return $this->json($response, ['error' => 'Enter an amount greater than RM0.'], 422);
        }
        if ($amount > 10000) {
            return $this->json($response, ['error' => 'Top-ups are capped at RM10,000 at a time.'], 422);
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE User SET wallet_balance = wallet_balance + :amt WHERE user_id = :id')
               ->execute(['amt' => $amount, 'id' => $userId]);
            $db->prepare("INSERT INTO WalletTransaction (user_id, amount, type, booking_id) VALUES (:id, :amt, 'Credit', NULL)")
               ->execute(['id' => $userId, 'amt' => $amount]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Wallet top-up failed: ' . $e->getMessage());
            return $this->json($response, ['error' => 'Could not complete the top-up.'], 500);
        }

        return $this->json($response, ['data' => ['balance' => $this->currentBalance($db, $userId)]], 200);
    }

    /**
     * POST /api/wallet/withdraw (requires JWT)
     * Body: { amount, card_last4? }
     * Demo "cash out earnings to a linked card": debits the wallet (if the
     * funds are there) and records a Debit. Again scaffolding only — a real
     * payout provider would settle the transfer.
     */
    public function withdraw(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $data = (array) $request->getParsedBody();
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            return $this->json($response, ['error' => 'Enter an amount greater than RM0.'], 422);
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT wallet_balance FROM User WHERE user_id = :id FOR UPDATE');
            $stmt->execute(['id' => $userId]);
            $balance = (float) $stmt->fetchColumn();
            if ($balance < $amount) {
                $db->rollBack();
                return $this->json($response, ['error' => 'You only have RM' . number_format($balance, 2) . ' to withdraw.'], 422);
            }
            $db->prepare('UPDATE User SET wallet_balance = wallet_balance - :amt WHERE user_id = :id')
               ->execute(['amt' => $amount, 'id' => $userId]);
            $db->prepare("INSERT INTO WalletTransaction (user_id, amount, type, booking_id) VALUES (:id, :amt, 'Debit', NULL)")
               ->execute(['id' => $userId, 'amt' => $amount]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Wallet withdrawal failed: ' . $e->getMessage());
            return $this->json($response, ['error' => 'Could not complete the withdrawal.'], 500);
        }

        return $this->json($response, ['data' => ['balance' => $this->currentBalance($db, $userId)]], 200);
    }

    private function currentBalance(\PDO $db, int $userId): float
    {
        $stmt = $db->prepare('SELECT wallet_balance FROM User WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        return (float) $stmt->fetchColumn();
    }

    private function json(Response $response, array $data, int $status): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
