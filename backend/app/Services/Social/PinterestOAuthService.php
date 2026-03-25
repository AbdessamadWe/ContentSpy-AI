<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PinterestOAuthService
{
    private const AUTH_URL = 'https://api.pinterest.com/v5/oauth/token';
    private const USER_URL = 'https://api.pinterest.com/v5/user';

    public function getAuthUrl(int $workspaceId, int $siteId): string
    {
        // Pinterest uses a different flow - redirect to their OAuth page
        $redirectUri = config('app.frontend_url') . '/social/callback/pinterest';
        
        // Build state
        $state = base64_encode(json_encode([
            'workspace_id' => $workspaceId,
            'site_id' => $siteId,
        ]));

        // Pinterest authorization URL
        $authUrl = 'https://www.pinterest.com/oauth/?' . http_build_query([
            'client_id' => config('services.pinterest.client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => 'boards:read pins:read pins:write',
            'state' => $state,
            'response_type' => 'code',
        ]);

        return $authUrl;
    }

    public function handleCallback(string $code, int $workspaceId, int $siteId): ?array
    {
        try {
            // Exchange code for access token
            $tokenResponse = Http::withBasicAuth(
                config('services.pinterest.client_id'),
                config('services.pinterest.client_secret')
            )->asForm()->post(self::AUTH_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('app.frontend_url') . '/social/callback/pinterest',
            ]);

            if (!$tokenResponse->successful()) {
                Log::error('[PinterestOAuth] Token exchange failed', ['response' => $tokenResponse->body()]);
                return null;
            }

            $data = $tokenResponse->json();
            $accessToken = $data['access_token'] ?? null;

            if (!$accessToken) {
                Log::error('[PinterestOAuth] Invalid token response', ['data' => $data]);
                return null;
            }

            // Get user info
            $userResponse = Http::withToken($accessToken)->get(self::USER_URL);

            $accountName = 'Pinterest Account';
            if ($userResponse->successful()) {
                $userData = $userResponse->json();
                $accountName = $userData['first_name'] . ' ' . $userData['last_name'] ?? 'Pinterest Account';
            }

            // Pinterest tokens don't expire
            SocialAccount::updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'site_id' => $siteId,
                    'platform' => 'pinterest',
                    'platform_account_id' => $userResponse->json('id') ?? Str::random(16),
                ],
                [
                    'account_name' => $accountName,
                    'access_token' => encrypt($accessToken),
                    'is_active' => true,
                ]
            );

            return [
                'success' => true,
                'accounts_count' => 1,
            ];
        } catch (\Throwable $e) {
            Log::error('[PinterestOAuth] Callback error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function refreshToken(SocialAccount $account): bool
    {
        // Pinterest tokens don't expire (until revoked)
        // This method exists for interface compatibility
        return true;
    }
}