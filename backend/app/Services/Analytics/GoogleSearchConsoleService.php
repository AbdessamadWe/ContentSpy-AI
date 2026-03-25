<?php
namespace App\Services\Analytics;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches search performance data from Google Search Console API.
 * Uses OAuth2 service account for server-to-server auth.
 * Requires: webmaster tools read permission on the verified site.
 */
class GoogleSearchConsoleService
{
    const API_BASE = 'https://searchconsole.googleapis.com/webmasters/v3';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Get search performance for a site over the last N days.
     * Returns impressions, clicks, CTR, average position per date.
     *
     * Cached for 1 hour.
     */
    public function getSearchPerformance(Site $site, int $days = 30): array
    {
        $cacheKey = "gsc:performance:{$site->id}:{$days}";

        return Cache::remember($cacheKey, 3600, function () use ($site, $days) {
            $token    = $this->getAccessToken($site);
            $siteUrl  = $site->url;
            $endDate  = now()->subDays(3)->format('Y-m-d'); // GSC has 3-day delay
            $startDate = now()->subDays($days + 3)->format('Y-m-d');

            $response = Http::withToken($token)
                ->timeout(30)
                ->post(self::API_BASE . "/sites/{$siteUrl}/searchAnalytics/query", [
                    'startDate'  => $startDate,
                    'endDate'    => $endDate,
                    'dimensions' => ['date'],
                    'rowLimit'   => $days,
                ]);

            if (! $response->successful()) {
                Log::warning("[GSC] API request failed for site #{$site->id}: " . $response->body());
                return [];
            }

            return $response->json('rows', []);
        });
    }

    /**
     * Get top performing queries for a site.
     */
    public function getTopQueries(Site $site, int $days = 30, int $limit = 20): array
    {
        $token     = $this->getAccessToken($site);
        $siteUrl   = $site->url;
        $endDate   = now()->subDays(3)->format('Y-m-d');
        $startDate = now()->subDays($days + 3)->format('Y-m-d');

        $response = Http::withToken($token)
            ->timeout(30)
            ->post(self::API_BASE . "/sites/{$siteUrl}/searchAnalytics/query", [
                'startDate'  => $startDate,
                'endDate'    => $endDate,
                'dimensions' => ['query'],
                'rowLimit'   => $limit,
                'orderBy'    => [['fieldName' => 'impressions', 'sortOrder' => 'DESCENDING']],
            ]);

        if (! $response->successful()) {
            return [];
        }

        return array_map(function ($row) {
            return [
                'query'       => $row['keys'][0],
                'clicks'      => $row['clicks'],
                'impressions' => $row['impressions'],
                'ctr'         => round($row['ctr'] * 100, 2),
                'position'    => round($row['position'], 1),
            ];
        }, $response->json('rows', []));
    }

    /**
     * Get page-level performance data (top URLs by impressions).
     */
    public function getTopPages(Site $site, int $days = 30, int $limit = 20): array
    {
        $token     = $this->getAccessToken($site);
        $siteUrl   = $site->url;
        $endDate   = now()->subDays(3)->format('Y-m-d');
        $startDate = now()->subDays($days + 3)->format('Y-m-d');

        $response = Http::withToken($token)
            ->timeout(30)
            ->post(self::API_BASE . "/sites/{$siteUrl}/searchAnalytics/query", [
                'startDate'  => $startDate,
                'endDate'    => $endDate,
                'dimensions' => ['page'],
                'rowLimit'   => $limit,
                'orderBy'    => [['fieldName' => 'clicks', 'sortOrder' => 'DESCENDING']],
            ]);

        if (! $response->successful()) {
            return [];
        }

        return array_map(function ($row) {
            return [
                'url'         => $row['keys'][0],
                'clicks'      => $row['clicks'],
                'impressions' => $row['impressions'],
                'ctr'         => round($row['ctr'] * 100, 2),
                'position'    => round($row['position'], 1),
            ];
        }, $response->json('rows', []));
    }

    /**
     * Get OAuth2 access token for a site.
     * Site must have google_oauth_token stored in settings.
     */
    private function getAccessToken(Site $site): string
    {
        $settings = $site->settings ?? [];

        // Check for service account credentials
        $serviceAccountKey = $settings['gsc_service_account_key'] ?? config('services.google.service_account_key');

        if ($serviceAccountKey) {
            return $this->getServiceAccountToken($serviceAccountKey);
        }

        // Fall back to OAuth2 refresh token stored per-site
        $refreshToken = $settings['gsc_refresh_token'] ?? null;
        if ($refreshToken) {
            return $this->refreshOAuthToken($refreshToken);
        }

        throw new \RuntimeException("No Google Search Console credentials configured for site #{$site->id}");
    }

    private function getServiceAccountToken(string $keyJson): string
    {
        $cacheKey = 'gsc:service_account_token';
        return Cache::remember($cacheKey, 3500, function () use ($keyJson) {
            $key = is_string($keyJson) ? json_decode($keyJson, true) : $keyJson;

            $jwt = $this->buildJwt($key);

            $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException("GSC service account auth failed: " . $response->body());
            }

            return $response->json('access_token');
        });
    }

    private function refreshOAuthToken(string $refreshToken): string
    {
        $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException("GSC OAuth refresh failed: " . $response->body());
        }

        return $response->json('access_token');
    }

    private function buildJwt(array $key): string
    {
        $now   = time();
        $claim = [
            'iss'   => $key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'aud'   => self::TOKEN_URL,
            'exp'   => $now + 3600,
            'iat'   => $now,
        ];

        $header  = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode($claim));
        $input   = "{$header}.{$payload}";

        openssl_sign($input, $signature, $key['private_key'], 'SHA256');

        return $input . '.' . base64_encode($signature);
    }
}
