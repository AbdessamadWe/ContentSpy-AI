<?php
namespace App\Services\Credits;

use App\Models\CreditTransaction;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

class CreditService
{
    /**
     * Reserve credits before starting an action.
     * Uses Redis atomic DECR with floor check to prevent race conditions.
     * Returns reservation token.
     *
     * @throws InsufficientCreditsException
     */
    public function reserve(Workspace $workspace, int $amount, string $actionType): string
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException("Credit amount must be positive.");
        }

        $reserved = DB::transaction(function () use ($workspace, $amount) {
            // Lock the workspace row for update
            $ws = Workspace::lockForUpdate()->find($workspace->id);

            $available = $ws->credits_balance - $ws->credits_reserved;
            if ($available < $amount) {
                throw new InsufficientCreditsException(
                    "Insufficient credits. Available: {$available}, required: {$amount}."
                );
            }

            $ws->increment('credits_reserved', $amount);
            return true;
        });

        $token = uniqid('res_', true);

        // Store reservation in Redis with TTL
        $ttl = config('credits.reservation_ttl_seconds', 300);
        Redis::setex(
            "credit_reservation:{$workspace->id}:{$token}",
            $ttl,
            json_encode(['amount' => $amount, 'action_type' => $actionType, 'workspace_id' => $workspace->id])
        );

        return $token;
    }

    /**
     * Confirm a reservation — deduct from balance, clear reservation.
     * Call this on successful action completion.
     */
    public function confirm(Workspace $workspace, string $token, ?int $userId = null, ?string $actionId = null): CreditTransaction
    {
        $raw = Redis::get("credit_reservation:{$workspace->id}:{$token}");
        if (!$raw) {
            throw new RuntimeException("Reservation token not found or expired: {$token}");
        }

        $reservation = json_decode($raw, true);
        $amount = $reservation['amount'];
        $actionType = $reservation['action_type'];

        $transaction = DB::transaction(function () use ($workspace, $amount, $userId, $actionType, $actionId) {
            $ws = Workspace::lockForUpdate()->find($workspace->id);

            // Deduct from balance and release from reserved
            $newBalance = $ws->credits_balance - $amount;
            $ws->update([
                'credits_balance'  => $newBalance,
                'credits_reserved' => max(0, $ws->credits_reserved - $amount),
            ]);

            return CreditTransaction::create([
                'workspace_id'  => $workspace->id,
                'user_id'       => $userId,
                'type'          => 'debit',
                'amount'        => -$amount,
                'balance_after' => $newBalance,
                'action_type'   => $actionType,
                'action_id'     => $actionId,
                'description'   => "Debit for {$actionType}",
            ]);
        });

        Redis::del("credit_reservation:{$workspace->id}:{$token}");
        return $transaction;
    }

    /**
     * Refund a reservation — release reserved credits back (no deduction).
     * Call this on action failure.
     */
    public function refund(Workspace $workspace, string $token, string $reason = ''): void
    {
        $raw = Redis::get("credit_reservation:{$workspace->id}:{$token}");
        if (!$raw) return; // Already expired or confirmed — no-op

        $reservation = json_decode($raw, true);
        $amount = $reservation['amount'];

        DB::transaction(function () use ($workspace, $amount) {
            Workspace::lockForUpdate()->find($workspace->id)
                ->decrement('credits_reserved', $amount);
        });

        Redis::del("credit_reservation:{$workspace->id}:{$token}");
    }

    /**
     * Directly add credits (for purchases, plan grants, adjustments).
     */
    public function addCredits(
        Workspace $workspace,
        int $amount,
        string $type,
        ?int $userId = null,
        string $description = '',
        ?array $metadata = null
    ): CreditTransaction {
        return DB::transaction(function () use ($workspace, $amount, $type, $userId, $description, $metadata) {
            $ws = Workspace::lockForUpdate()->find($workspace->id);
            $newBalance = $ws->credits_balance + $amount;
            $ws->update(['credits_balance' => $newBalance]);

            return CreditTransaction::create([
                'workspace_id'  => $workspace->id,
                'user_id'       => $userId,
                'type'          => $type,
                'amount'        => $amount,
                'balance_after' => $newBalance,
                'description'   => $description,
                'metadata'      => $metadata,
            ]);
        });
    }

    /**
     * Get current credit balance for workspace.
     * Uses latest balance_after from transactions for O(1) lookup.
     */
    public function getBalance(Workspace $workspace): array
    {
        $workspace->refresh();
        return [
            'balance'   => $workspace->credits_balance,
            'reserved'  => $workspace->credits_reserved,
            'available' => $workspace->available_credits,
        ];
    }

    /**
     * Simple one-shot deduct (reserve + confirm atomically).
     * Use this for synchronous actions that complete immediately.
     */
    public function deduct(
        Workspace $workspace,
        int $amount,
        string $actionType,
        ?int $userId = null,
        ?string $actionId = null
    ): CreditTransaction {
        return DB::transaction(function () use ($workspace, $amount, $actionType, $userId, $actionId) {
            $ws = Workspace::lockForUpdate()->find($workspace->id);
            $available = $ws->credits_balance - $ws->credits_reserved;

            if ($available < $amount) {
                throw new InsufficientCreditsException(
                    "Insufficient credits. Available: {$available}, required: {$amount}."
                );
            }

            $newBalance = $ws->credits_balance - $amount;
            $ws->update(['credits_balance' => $newBalance]);

            return CreditTransaction::create([
                'workspace_id'  => $workspace->id,
                'user_id'       => $userId,
                'type'          => 'debit',
                'amount'        => -$amount,
                'balance_after' => $newBalance,
                'action_type'   => $actionType,
                'action_id'     => $actionId,
                'description'   => "Debit for {$actionType}",
            ]);
        });
    }

    /**
     * Check if workspace has enough available credits.
     */
    public function hasEnough(Workspace $workspace, int $amount): bool
    {
        $workspace->refresh();
        return $workspace->available_credits >= $amount;
    }

    /**
     * Check if auto-spy should pause (below minimum threshold).
     */
    public function shouldPauseAutoSpy(Workspace $workspace): bool
    {
        $workspace->refresh();
        return $workspace->available_credits < config('credits.min_balance_for_auto_spy', 50);
    }
}
