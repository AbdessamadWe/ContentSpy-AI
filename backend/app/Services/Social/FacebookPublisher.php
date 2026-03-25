<?php
namespace App\Services\Social;

use App\Models\Article;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPublisherInterface;
use Illuminate\Support\Facades\Http;

/**
 * Publishes to Facebook Pages via Graph API v18.
 * Requires: pages_manage_posts, pages_read_engagement permissions.
 */
class FacebookPublisher implements SocialPublisherInterface
{
    const GRAPH_API = 'https://graph.facebook.com/v18.0';

    public function platform(): string
    {
        return 'facebook';
    }

    public function publish(Article $article, SocialAccount $account, array $options = []): array
    {
        if ($account->isTokenExpired()) {
            $this->refreshToken($account);
            $account->refresh();
        }

        $token    = decrypt($account->access_token);
        $pageId   = $account->platform_user_id;
        $excerpt  = $options['caption'] ?? strip_tags($article->excerpt ?? '');
        $link     = $options['link'] ?? $article->wp_post_url;

        $payload = array_filter([
            'message'      => $excerpt,
            'link'         => $link,
            'access_token' => $token,
        ]);

        $response = Http::timeout(30)
            ->post(self::GRAPH_API . "/{$pageId}/feed", $payload)
            ->throw();

        $postId = $response->json('id');

        return [
            'platform_post_id' => $postId,
            'post_url'         => "https://facebook.com/{$postId}",
        ];
    }

    public function refreshToken(SocialAccount $account): void
    {
        // Facebook long-lived tokens (60 days) — exchange for another 60-day token
        $shortToken = decrypt($account->access_token);

        $response = Http::get(self::GRAPH_API . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('services.facebook.client_id'),
            'client_secret'     => config('services.facebook.client_secret'),
            'fb_exchange_token' => $shortToken,
        ]);

        if ($response->successful() && $response->json('access_token')) {
            $account->update([
                'access_token' => encrypt($response->json('access_token')),
                'token_expires_at' => now()->addDays(60),
            ]);
        }
    }
}
