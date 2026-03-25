<?php
namespace App\Services\Publishing;

use App\Models\Article;
use App\Models\Site;
use App\Services\Publishing\Contracts\WordPressClientInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publishes to WordPress via Application Password (Basic Auth).
 * Uses the native WP REST API at /wp-json/wp/v2/.
 */
class WordPressRestClient implements WordPressClientInterface
{
    public function publish(Article $article, Site $site): array
    {
        $password = decrypt($site->wp_app_password);
        $apiBase  = rtrim($site->wp_api_url ?? $site->url, '/') . '/wp-json/wp/v2';

        // Upload featured image if available
        $featuredMediaId = null;
        if ($article->featured_image_url) {
            try {
                $media           = $this->uploadImage($article->featured_image_url, $article->title, $site);
                $featuredMediaId = $media['media_id'];
            } catch (\Throwable $e) {
                Log::warning("[WordPressRestClient] Featured image upload failed: {$e->getMessage()}");
            }
        }

        // Resolve or create categories/tags
        $categoryIds = $this->resolveCategories($article->categories ?? [], $apiBase, $site->wp_username, $password);
        $tagIds      = $this->resolveTags($article->tags ?? [], $apiBase, $site->wp_username, $password);

        $payload = array_filter([
            'title'          => $article->meta_title ?? $article->title,
            'content'        => $article->formatted_content ?? $article->content,
            'excerpt'        => $article->excerpt ?? '',
            'slug'           => $article->slug,
            'status'         => 'publish',
            'categories'     => $categoryIds ?: null,
            'tags'           => $tagIds ?: null,
            'featured_media' => $featuredMediaId,
            'meta'           => $this->buildMetaFields($article),
        ]);

        $response = Http::withBasicAuth($site->wp_username, $password)
            ->timeout(30)
            ->post("{$apiBase}/posts", $payload)
            ->throw();

        $postId  = $response->json('id');
        $postUrl = $response->json('link');

        // Set Yoast / RankMath meta via REST meta endpoint
        $this->updatePostMeta($postId, $this->buildSeoMeta($article), $site);

        return ['post_id' => $postId, 'post_url' => $postUrl];
    }

    public function uploadImage(string $imageUrl, string $altText, Site $site): array
    {
        $password = decrypt($site->wp_app_password);
        $apiBase  = rtrim($site->wp_api_url ?? $site->url, '/') . '/wp-json/wp/v2';

        $imageContent = Http::timeout(30)->get($imageUrl)->throw()->body();
        $filename     = basename(parse_url($imageUrl, PHP_URL_PATH)) ?: 'image.jpg';

        $response = Http::withBasicAuth($site->wp_username, $password)
            ->withHeaders([
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Content-Type'        => 'image/jpeg',
            ])
            ->timeout(30)
            ->withBody($imageContent, 'image/jpeg')
            ->post("{$apiBase}/media")
            ->throw();

        $mediaId = $response->json('id');

        // Set alt text
        Http::withBasicAuth($site->wp_username, $password)
            ->post("{$apiBase}/media/{$mediaId}", ['alt_text' => $altText]);

        return [
            'media_id' => $mediaId,
            'url'      => $response->json('source_url'),
        ];
    }

    public function updatePostMeta(int $postId, array $meta, Site $site): void
    {
        if (! $meta) return;

        $password = decrypt($site->wp_app_password);
        $apiBase  = rtrim($site->wp_api_url ?? $site->url, '/') . '/wp-json/wp/v2';

        Http::withBasicAuth($site->wp_username, $password)
            ->timeout(15)
            ->post("{$apiBase}/posts/{$postId}", ['meta' => $meta]);
    }

    public function testConnection(Site $site): bool
    {
        try {
            $password = decrypt($site->wp_app_password);
            $apiBase  = rtrim($site->wp_api_url ?? $site->url, '/') . '/wp-json/wp/v2';
            $response = Http::withBasicAuth($site->wp_username, $password)->timeout(10)->get("{$apiBase}/users/me");
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildMetaFields(Article $article): array
    {
        return array_filter([
            // Standard WP meta
            '_thumbnail_id' => null, // set via featured_media parameter
        ]);
    }

    private function buildSeoMeta(Article $article): array
    {
        return array_filter([
            // Yoast SEO
            '_yoast_wpseo_title'       => $article->meta_title,
            '_yoast_wpseo_metadesc'    => $article->meta_description,
            '_yoast_wpseo_focuskw'     => $article->focus_keyword,
            // Rank Math
            'rank_math_title'          => $article->meta_title,
            'rank_math_description'    => $article->meta_description,
            'rank_math_focus_keyword'  => $article->focus_keyword,
        ]);
    }

    private function resolveCategories(array $names, string $apiBase, string $user, string $pass): array
    {
        $ids = [];
        foreach ($names as $name) {
            try {
                // Search first
                $search = Http::withBasicAuth($user, $pass)
                    ->get("{$apiBase}/categories", ['search' => $name, 'per_page' => 1]);
                if ($search->successful() && count($search->json())) {
                    $ids[] = $search->json()[0]['id'];
                } else {
                    // Create
                    $create = Http::withBasicAuth($user, $pass)
                        ->post("{$apiBase}/categories", ['name' => $name]);
                    if ($create->successful()) {
                        $ids[] = $create->json('id');
                    }
                }
            } catch (\Throwable) {
                // Non-fatal
            }
        }
        return $ids;
    }

    private function resolveTags(array $names, string $apiBase, string $user, string $pass): array
    {
        $ids = [];
        foreach ($names as $name) {
            try {
                $search = Http::withBasicAuth($user, $pass)
                    ->get("{$apiBase}/tags", ['search' => $name, 'per_page' => 1]);
                if ($search->successful() && count($search->json())) {
                    $ids[] = $search->json()[0]['id'];
                } else {
                    $create = Http::withBasicAuth($user, $pass)
                        ->post("{$apiBase}/tags", ['name' => $name]);
                    if ($create->successful()) {
                        $ids[] = $create->json('id');
                    }
                }
            } catch (\Throwable) {
                // Non-fatal
            }
        }
        return $ids;
    }
}
