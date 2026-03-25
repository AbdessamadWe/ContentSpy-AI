<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TokenRefreshService
{
    public function __construct(
        private readonly FacebookOAuthService $facebookService,
        private readonly TikTokOAuthService $tiktokService,
        private readonly PinterestOAuthService $pinterestService,
    ) {}

    /**
     * Refresh all tokens expiring within the next 7 days
     * Called via scheduled command
     */
    public function refreshAll(): int
    {
        $expiringAccounts = SocialAccount::where('is_active', true)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addDays(7))
            ->get();

        $refreshed = 0;

        foreach ($expiringAccounts as $account) {
            if ($this->refresh($account)) {
                $refreshed++;
            }
        }

        Log::info('[TokenRefreshService] Refreshed tokens', [
            'total_expiring' => $expiringAccounts->count(),
            'refreshed' => $refreshed,
        ]);

        return $refreshed;
    }

    /**
     * Refresh a single account's token
     */
    public function refresh(SocialAccount $account): bool
    {
        return match($account->platform) {
            'facebook', 'instagram' => $this->refreshFacebook($account),
            'tiktok' => $this->tiktokService->refreshToken($account),
            'pinterest' => $this->pinterestService->refreshToken($account),
            default => false,
        };
    }

    private function refreshFacebook(SocialAccount $account): bool
    {
        try {
            $accessToken = decrypt($account->access_token);
            $result = $this->facebookService->refreshToken($accessToken);

            if (!$result) {
                return false;
            }

            $account->update([
                'access_token' => encrypt($result['access_token']),
                'token_expires_at' => isset($result['expires_in']) 
                    ? now()->addSeconds($result['expires_in']) 
                    : null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[TokenRefreshService] Facebook refresh failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}