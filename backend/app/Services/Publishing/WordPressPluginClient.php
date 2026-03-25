<?php
namespace App\Services\Publishing;

use App\Models\Article;
use App\Models\Site;
use App\Services\Publishing\Contracts\WordPressClientInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publishes to WordPress via the ContentSpy Connect plugin.
 * Requests are signed with HMAC-SHA256 using the shared plugin secret.
 * Endpoint: /wp-json/contentspy/v1/publish
 */
class WordPressPluginClient implements WordPressClientInterface
{
    public function publish(Article $article, Site $site): array
    {
        $payload   = $this->buildPublishPayload($article);
        $body      = json_encode($payload);
        $signature = $this->sign($body, $site);

        $response = Http::withHeaders([
            'X-ContentSpy-Signature' => $signature,
            'Content-Type'           => 'application/json',
        ])
        ->timeout(30)
        ->post($this->endpoint($site, 'publish'), $payload)
        ->throw();

        return [
            'post_id'  => $response->json('post_id'),
            'post_url' => $response->json('post_url'),
        ];
    }

    public function uploadImage(string $imageUrl, string $altText, Site $site): array
    {
        $payload   = ['image_url' => $imageUrl, 'alt_text' => $altText];
        $body      = json_encode($payload);
        $signature = $this->sign($body, $site);

        $response = Http::withHeaders([
            'X-ContentSpy-Signature' => $signature,
            'Content-Type'           => 'application/json',
        ])
        ->timeout(30)
        ->post($this->endpoint($site, 'upload-image'), $payload)
        ->throw();

        return [
            'media_id' => $response->json('media_id'),
            'url'      => $response->json('url'),
        ];
    }

    public function updatePostMeta(int $postId, array $meta, Site $site): void
    {
        $payload   = ['post_id' => $postId, 'meta' => $meta];
        $body      = json_encode($payload);
        $signature = $this->sign($body, $site);

        Http::withHeaders([
            'X-ContentSpy-Signature' => $signature,
            'Content-Type'           => 'application/json',
        ])
        ->timeout(15)
        ->post($this->endpoint($site, 'update-meta'), $payload);
    }

    public function testConnection(Site $site): bool
    {
        try {
            $payload   = ['ping' => true];
            $body      = json_encode($payload);
            $signature = $this->sign($body, $site);

            $response = Http::withHeaders([
                'X-ContentSpy-Signature' => $signature,
                'Content-Type'           => 'application/json',
            ])
            ->timeout(10)
            ->get($this->endpoint($site, 'status'));

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildPublishPayload(Article $article): array
    {
        return array_filter([
            'title'              => $article->meta_title ?? $article->title,
            'content'            => $article->formatted_content ?? $article->content,
            'excerpt'            => $article->excerpt ?? '',
            'slug'               => $article->slug,
            'status'             => 'publish',
            'featured_image_url' => $article->featured_image_url,
            'categories'         => $article->categories ?? [],
            'tags'               => $article->tags ?? [],
            'yoast' => array_filter([
                'title'    => $article->meta_title,
                'metadesc' => $article->meta_description,
                'focuskw'  => $article->focus_keyword,
            ]),
            'rankmath' => array_filter([
                'title'         => $article->meta_title,
                'description'   => $article->meta_description,
                'focus_keyword' => $article->focus_keyword,
            ]),
        ]);
    }

    /** Build HMAC-SHA256 signature: 'sha256={hash}' */
    private function sign(string $body, Site $site): string
    {
        $secret = decrypt($site->plugin_secret);
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    private function endpoint(Site $site, string $path): string
    {
        return rtrim($site->url, '/') . "/wp-json/contentspy/v1/{$path}";
    }
}
