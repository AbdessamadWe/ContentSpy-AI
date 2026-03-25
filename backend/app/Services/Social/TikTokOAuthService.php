<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TikTokOAuthService
{
    private const AUTH_URL = 'https://www.tiktok.com/v2/auth/authorize/';
    private const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';
    private const USER_INFO_URL = 'https://open.tiktokapis.com/v2/user/info/';

    public function getAuthUrl(int $workspaceId, int $siteId): string
    {
        $redirectUri = config('app.frontend_url') . '/social/callback/tiktok';
        $state = base64_encode(json_encode([
            'workspace_id' => $workspaceId,
            'site_id' => $siteId,
        ]));

        // TikTok Content Posting API scopes
        $scope = 'user.info.basic,video.upload,video.publish';

        return self::AUTH_URL . '?' . http_build_query([
            'client_key' => config('services.tiktok.client_key'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => $scope,
            'response_type' => 'code',
        ]);
    }

    public function handleCallback(string $code, int $workspaceId, int $siteId): ?array
    {
        try {
            // Exchange code for access token
            $tokenResponse = Http::withBasicAuth(
                config('services.tiktok.client_key'),
                config('services.tiktok.client_secret')
            )->asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('app.frontend_url') . '/social/callback/tiktok',
            ]);

            if (!$tokenResponse->successful()) {
                Log::error('[TikTokOAuth] Token exchange failed', ['response' => $tokenResponse->body()]);
                return null;
            }

            $data = $tokenResponse->json();
            $accessToken = $data['access_token'] ?? null;
            $openId = $data['open_id'] ?? null;
            $expiresIn = $data['expires_in'] ?? null;

            if (!$accessToken || !$openId) {
                Log::error('[TikTokOAuth] Invalid token response', ['data' => $data]);
                return null;
            }

            // Get user info
            $userResponse = Http::withToken($accessToken)->get(self::USER_INFO_URL, [
                'fields' => 'display_name,avatar_url',
            ]);

            $accountName = 'TikTok Account';
            if ($userResponse->successful()) {
                $userData = $userResponse->json('data') ?? [];
                $accountName = $userData['display_name'] ?? 'TikTok Account';
            }

            // Store account
            $expiresAt = $expiresIn ? now()->addSeconds($expiresIn) : null;

            SocialAccount::updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'site_id' => $siteId,
                    'platform' => 'tiktok',
                    'platform_account_id' => $openId,
                ],
                [
                    'account_name' => $accountName,
                    'access_token' => encrypt($accessToken),
                    'refresh_token' => isset($data['refresh_token']) ? encrypt($data['refresh_token']) : null,
                    'token_expires_at' => $expiresAt,
                    'is_active' => true,
                ]
            );

            return [
                'success' => true,
                'accounts_count' => 1,
            ];
        } catch (\Throwable $e) {
            Log::error('[TikTokOAuth] Callback error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function refreshToken(SocialAccount $account): bool
    {
        try {
            $refreshToken = decrypt($account->refresh_token);

            $response = Http::withBasicAuth(
                config('services.tiktok.client_key'),
                config('services.tiktok.client_secret')
            )->asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

            if (!$response->successful()) {
                Log::error('[TikTokOAuth] Token refresh failed', ['response' => $response->body()]);
                return false;
            }

            $data = $response->json();
            
            $account->update([
                'access_token' => encrypt($data['access_token']),
                'refresh_token' => isset($data['refresh_token']) ? encrypt($data['refresh_token']) : null,
                'token_expires_at' => isset($data['expires_in']) ? now()->addSeconds($data['expires_in']) : null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[TikTokOAuth] Token refresh error', ['error' => $e->getMessage()]);
            return false;
        }
    }
}