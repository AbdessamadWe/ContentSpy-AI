<?php
namespace App\Services\Social;

use App\Models\Article;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPublisherInterface;
use Illuminate\Support\Facades\Http;

/**
 * Publishes short-form video to TikTok via Content Posting API v2.
 * Video requirements: H.264, 720p-1080p, 15-600s, AAC audio, max 4GB.
 * Requires approved TikTok app with video.upload and video.publish scopes.
 *
 * Note: TikTok requires a 2-week app review. Plan accordingly.
 */
class TikTokPublisher implements SocialPublisherInterface
{
    const API_BASE = 'https://open.tiktokapis.com/v2';

    public function platform(): string
    {
        return 'tiktok';
    }

    public function publish(Article $article, SocialAccount $account, array $options = []): array
    {
        if ($account->isTokenExpired()) {
            $this->refreshToken($account);
            $account->refresh();
        }

        $token    = decrypt($account->access_token);
        $videoUrl = $options['video_url'] ?? null;

        if (! $videoUrl) {
            throw new \RuntimeException("TikTok publishing requires a video URL (R2 or CDN).");
        }

        $caption     = $options['caption'] ?? strip_tags($article->title ?? '');
        $description = $options['description'] ?? strip_tags($article->excerpt ?? '');

        // Step 1: Initialize upload
        $initResponse = Http::withToken($token)
            ->timeout(30)
            ->post(self::API_BASE . '/post/publish/video/init/', [
                'post_info' => [
                    'title'                => mb_substr($caption, 0, 150),
                    'description'          => mb_substr($description, 0, 2200),
                    'disable_duet'         => false,
                    'disable_comment'      => false,
                    'disable_stitch'       => false,
                    'privacy_level'        => 'PUBLIC_TO_EVERYONE',
                ],
                'source_info' => [
                    'source'        => 'PULL_FROM_URL',
                    'video_url'     => $videoUrl,
                ],
            ])->throw();

        $publishId = $initResponse->json('data.publish_id');

        // Step 2: Poll for publish status (up to 2 minutes)
        for ($i = 0; $i < 24; $i++) {
            sleep(5);
            $statusResponse = Http::withToken($token)
                ->timeout(15)
                ->post(self::API_BASE . '/post/publish/status/fetch/', [
                    'publish_id' => $publishId,
                ]);

            $status = $statusResponse->json('data.status');

            if ($status === 'PUBLISH_COMPLETE') {
                $videoId = $statusResponse->json('data.publicaly_available_post_id.0') ?? $publishId;
                return [
                    'platform_post_id' => $videoId,
                    'post_url'         => "https://tiktok.com/@{$account->handle}/video/{$videoId}",
                ];
            }

            if (in_array($status, ['FAILED', 'CANCELLED'])) {
                throw new \RuntimeException("TikTok publish failed with status: {$status}");
            }
        }

        throw new \RuntimeException("TikTok publish polling timed out after 2 minutes.");
    }

    public function refreshToken(SocialAccount $account): void
    {
        // TikTok access tokens expire in 24h; refresh tokens last 365 days
        $refreshToken = decrypt($account->refresh_token);

        $response = Http::asForm()->timeout(15)->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key'    => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($response->successful()) {
            $account->update([
                'access_token'     => encrypt($response->json('access_token')),
                'refresh_token'    => encrypt($response->json('refresh_token')),
                'token_expires_at' => now()->addSeconds($response->json('expires_in', 86400)),
            ]);
        }
    }
}
