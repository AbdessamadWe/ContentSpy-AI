<?php
namespace App\Services\Social;

use App\Models\Article;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPublisherInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publishes to Instagram via Instagram Graph API.
 * Supports: Feed posts (image), Reels (video).
 * Requires: instagram_basic, instagram_content_publish permissions.
 */
class InstagramPublisher implements SocialPublisherInterface
{
    const GRAPH_API = 'https://graph.facebook.com/v18.0';

    public function platform(): string
    {
        return 'instagram';
    }

    public function publish(Article $article, SocialAccount $account, array $options = []): array
    {
        if ($account->isTokenExpired()) {
            $this->refreshToken($account);
            $account->refresh();
        }

        $token       = decrypt($account->access_token);
        $igAccountId = $account->platform_user_id;
        $mediaType   = $options['media_type'] ?? 'IMAGE'; // IMAGE | REELS

        if ($mediaType === 'REELS') {
            return $this->publishReel($article, $igAccountId, $token, $options);
        }

        return $this->publishImage($article, $igAccountId, $token, $options);
    }

    private function publishImage(Article $article, string $igId, string $token, array $options): array
    {
        $imageUrl = $options['image_url'] ?? $article->featured_image_url;
        $caption  = $options['caption']   ?? $this->buildCaption($article);

        if (! $imageUrl) {
            throw new \RuntimeException("Instagram image post requires an image URL.");
        }

        // Step 1: Create media container
        $container = Http::timeout(30)->post(self::GRAPH_API . "/{$igId}/media", [
            'image_url'    => $imageUrl,
            'caption'      => $caption,
            'access_token' => $token,
        ])->throw()->json('id');

        // Step 2: Publish the container
        $result = Http::timeout(30)->post(self::GRAPH_API . "/{$igId}/media_publish", [
            'creation_id'  => $container,
            'access_token' => $token,
        ])->throw();

        $postId = $result->json('id');

        return [
            'platform_post_id' => $postId,
            'post_url'         => "https://instagram.com/p/{$postId}",
        ];
    }

    private function publishReel(Article $article, string $igId, string $token, array $options): array
    {
        $videoUrl = $options['video_url'] ?? null;
        $caption  = $options['caption']   ?? $this->buildCaption($article);

        if (! $videoUrl) {
            throw new \RuntimeException("Instagram Reels requires a video URL.");
        }

        // Step 1: Create Reels container
        $container = Http::timeout(30)->post(self::GRAPH_API . "/{$igId}/media", [
            'media_type'   => 'REELS',
            'video_url'    => $videoUrl,
            'caption'      => $caption,
            'share_to_feed' => true,
            'access_token' => $token,
        ])->throw()->json('id');

        // Step 2: Poll until ready (up to 60s)
        $statusUrl = self::GRAPH_API . "/{$container}?fields=status_code&access_token={$token}";
        $ready     = false;
        for ($i = 0; $i < 12; $i++) {
            sleep(5);
            $status = Http::get($statusUrl)->json('status_code');
            if ($status === 'FINISHED') { $ready = true; break; }
            if ($status === 'ERROR')    { throw new \RuntimeException("Instagram Reels processing failed."); }
        }

        if (! $ready) {
            throw new \RuntimeException("Instagram Reels processing timed out after 60s.");
        }

        // Step 3: Publish
        $result = Http::timeout(30)->post(self::GRAPH_API . "/{$igId}/media_publish", [
            'creation_id'  => $container,
            'access_token' => $token,
        ])->throw();

        $postId = $result->json('id');

        return [
            'platform_post_id' => $postId,
            'post_url'         => "https://instagram.com/reel/{$postId}",
        ];
    }

    public function refreshToken(SocialAccount $account): void
    {
        // Instagram uses same long-lived token exchange as Facebook
        $token = decrypt($account->access_token);

        $response = Http::get('https://graph.instagram.com/refresh_access_token', [
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $token,
        ]);

        if ($response->successful() && $response->json('access_token')) {
            $account->update([
                'access_token'     => encrypt($response->json('access_token')),
                'token_expires_at' => now()->addDays(60),
            ]);
        }
    }

    private function buildCaption(Article $article): string
    {
        $base     = strip_tags($article->excerpt ?? $article->title ?? '');
        $keywords = is_array($article->target_keywords) ? $article->target_keywords : [];
        $hashtags = implode(' ', array_map(fn($k) => '#' . str_replace(' ', '', $k), array_slice($keywords, 0, 10)));

        return trim("{$base}\n\n{$hashtags}");
    }
}
