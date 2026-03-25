<?php
namespace App\Services\Social;

use App\Models\Article;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialPublisherInterface;
use Illuminate\Support\Facades\Http;

/**
 * Publishes Pins to Pinterest via Pinterest API v5.
 * Optimal image: 1000x1500px (2:3 ratio), JPEG/PNG, max 32MB.
 * Pinterest tokens don't expire unless revoked — no refresh needed.
 */
class PinterestPublisher implements SocialPublisherInterface
{
    const API_BASE = 'https://api.pinterest.com/v5';

    public function platform(): string
    {
        return 'pinterest';
    }

    public function publish(Article $article, SocialAccount $account, array $options = []): array
    {
        $token    = decrypt($account->access_token);
        $boardId  = $options['board_id'] ?? $account->settings['default_board_id'] ?? null;

        if (! $boardId) {
            throw new \RuntimeException("Pinterest publishing requires a board_id.");
        }

        $imageUrl   = $options['image_url'] ?? $article->featured_image_url;
        $title      = $options['title']     ?? $article->meta_title ?? $article->title;
        $description = $options['description'] ?? strip_tags($article->meta_description ?? $article->excerpt ?? '');
        $link       = $options['link']       ?? $article->wp_post_url;

        if (! $imageUrl) {
            throw new \RuntimeException("Pinterest Pin requires an image URL.");
        }

        $payload = array_filter([
            'board_id'  => $boardId,
            'title'     => mb_substr($title, 0, 100),
            'description' => mb_substr($description, 0, 500),
            'link'      => $link,
            'media_source' => [
                'source_type' => 'image_url',
                'url'         => $imageUrl,
            ],
        ]);

        $response = Http::withToken($token)
            ->timeout(30)
            ->post(self::API_BASE . '/pins', $payload)
            ->throw();

        $pinId = $response->json('id');

        return [
            'platform_post_id' => $pinId,
            'post_url'         => "https://pinterest.com/pin/{$pinId}",
        ];
    }

    public function refreshToken(SocialAccount $account): void
    {
        // Pinterest tokens don't expire — no refresh needed
        // If token is revoked, user must re-authorize
    }
}
