<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FacebookOAuthService
{
    private const GRAPH_API_VERSION = 'v18.0';
    private const AUTH_URL = 'https://www.facebook.com/' . self::GRAPH_API_VERSION . '/dialog/oauth';
    private const TOKEN_URL = 'https://graph.facebook.com/' . self::GRAPH_API_VERSION . '/oauth/access_token';
    private const ME_URL = 'https://graph.facebook.com/' . self::GRAPH_API_VERSION . '/me';
    private const ACCOUNTS_URL = 'https://graph.facebook.com/' . self::GRAPH_API_VERSION . '/me/accounts';

    public function getAuthUrl(int $workspaceId, int $siteId): string
    {
        $redirectUri = config('app.frontend_url') . '/social/callback/facebook';
        $state = base64_encode(json_encode([
            'workspace_id' => $workspaceId,
            'site_id' => $siteId,
        ]));

        $scope = 'pages_manage_posts,pages_read_engagement,instagram_basic,instagram_content_publish,pages_manage_metadata';

        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => config('services.facebook.client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => $scope,
            'response_type' => 'code',
        ]);
    }

    public function handleCallback(string $code, int $workspaceId, int $siteId): ?array
    {
        try {
            $redirectUri = config('app.frontend_url') . '/social/callback/facebook';

            // Exchange code for access token
            $tokenResponse = Http::get(self::TOKEN_URL, [
                'client_id' => config('services.facebook.client_id'),
                'client_secret' => config('services.facebook.client_secret'),
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);

            if (!$tokenResponse->successful()) {
                Log::error('[FacebookOAuth] Token exchange failed', ['response' => $tokenResponse->body()]);
                return null;
            }

            $accessToken = $tokenResponse['access_token'];
            $expiresIn = $tokenResponse['expires_in'] ?? null;

            // Get user info
            $meResponse = Http::withToken($accessToken)->get(self::ME_URL, [
                'fields' => 'id,name',
            ]);

            if (!$meResponse->successful()) {
                Log::error('[FacebookOAuth] Get user failed', ['response' => $meResponse->body()]);
                return null;
            }

            $facebookUserId = $meResponse['id'];

            // Get pages (Facebook pages)
            $pagesResponse = Http::withToken($accessToken)->get(self::ACCOUNTS_URL, [
                'fields' => 'id,name,access_token,instagram_business_account',
            ]);

            $accounts = [];

            if ($pagesResponse->successful()) {
                foreach ($pagesResponse['data'] ?? [] as $page) {
                    $accounts[] = [
                        'platform' => 'facebook',
                        'platform_account_id' => $page['id'],
                        'account_name' => $page['name'],
                        'access_token' => $page['access_token'],
                        'page_id' => $page['id'],
                    ];

                    // If page has Instagram business account, create Instagram account too
                    if (isset($page['instagram_business_account'])) {
                        $accounts[] = [
                            'platform' => 'instagram',
                            'platform_account_id' => $page['instagram_business_account']['id'],
                            'account_name' => $page['name'] . ' (Instagram)',
                            'access_token' => $accessToken, // Use Facebook token for Instagram
                            'page_id' => $page['id'],
                        ];
                    }
                }
            }

            // Store accounts
            foreach ($accounts as $accountData) {
                $expiresAt = $expiresIn ? now()->addSeconds($expiresIn) : null;

                SocialAccount::updateOrCreate(
                    [
                        'workspace_id' => $workspaceId,
                        'site_id' => $siteId,
                        'platform' => $accountData['platform'],
                        'platform_account_id' => $accountData['platform_account_id'],
                    ],
                    [
                        'account_name' => $accountData['account_name'],
                        'access_token' => encrypt($accountData['access_token']),
                        'token_expires_at' => $expiresAt,
                        'page_id' => $accountData['page_id'] ?? null,
                        'is_active' => true,
                    ]
                );
            }

            return [
                'success' => true,
                'accounts_count' => count($accounts),
            ];
        } catch (\Throwable $e) {
            Log::error('[FacebookOAuth] Callback error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function refreshToken(string $accessToken): ?array
    {
        try {
            // Facebook long-lived tokens don't need refresh in the same way
            // This would be for short-lived token exchange
            $response = Http::get(self::TOKEN_URL, [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('services.facebook.client_id'),
                'client_secret' => config('services.facebook.client_secret'),
                'fb_exchange_token' => $accessToken,
            ]);

            if (!$response->successful()) {
                return null;
            }

            return [
                'access_token' => $response['access_token'],
                'expires_in' => $response['expires_in'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('[FacebookOAuth] Token refresh failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}