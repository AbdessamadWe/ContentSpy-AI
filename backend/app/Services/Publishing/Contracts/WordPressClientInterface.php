<?php
namespace App\Services\Publishing\Contracts;

use App\Models\Article;
use App\Models\Site;

interface WordPressClientInterface
{
    /**
     * Publish an article to WordPress.
     * Returns ['post_id' => int, 'post_url' => string].
     */
    public function publish(Article $article, Site $site): array;

    /**
     * Upload an image from URL to the WordPress media library.
     * Returns ['media_id' => int, 'url' => string].
     */
    public function uploadImage(string $imageUrl, string $altText, Site $site): array;

    /**
     * Update post meta fields (Yoast, RankMath, custom).
     */
    public function updatePostMeta(int $postId, array $meta, Site $site): void;

    /**
     * Check if the site connection is active and the API is reachable.
     */
    public function testConnection(Site $site): bool;
}
