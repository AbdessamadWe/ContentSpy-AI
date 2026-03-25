<?php
namespace App\Jobs\Publishing;

use App\Models\Article;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PublishToWordPressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 3;
    public array $backoff = [5, 15, 30]; // exponential backoff seconds

    public function __construct(public readonly int $articleId) {}

    public function handle(): void
    {
        $article = Article::with('site')->find($this->articleId);
        if (!$article) return;

        $site = $article->site;

        if (!$site->isConnected()) {
            Log::warning("[WordPress] Site #{$site->id} is not connected. Aborting publish for article #{$article->id}.");
            $article->update(['publish_status' => 'failed']);
            return;
        }

        try {
            $result = match($site->connection_type) {
                'plugin'   => $this->publishViaPlugin($article, $site),
                'rest_api' => $this->publishViaRestApi($article, $site),
                default    => throw new \RuntimeException("Unknown connection type: {$site->connection_type}"),
            };

            $article->update([
                'wp_post_id'       => $result['post_id'],
                'wp_post_url'      => $result['post_url'],
                'wp_published_at'  => now(),
                'publish_status'   => 'published',
            ]);

            // Update suggestion status
            $article->suggestion?->update(['status' => 'published']);

            Log::info("[WordPress] Published article #{$article->id} → WP post #{$result['post_id']} on site #{$site->id}");
        } catch (\Throwable $e) {
            Log::error("[WordPress] Publish failed for article #{$article->id}: " . $e->getMessage());

            if ($this->attempts() >= $this->tries) {
                $article->update(['publish_status' => 'failed']);
            }

            throw $e;
        }
    }

    /** Publish via ContentSpy Connect plugin (recommended) */
    private function publishViaPlugin(Article $article, Site $site): array
    {
        $pluginUrl = rtrim($site->url, '/') . '/wp-json/contentspy/v1/publish';
        $body = json_encode($this->buildPayload($article));
        $secret = decrypt($site->plugin_secret);
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $response = Http::withHeaders([
            'X-ContentSpy-Signature' => $signature,
            'Content-Type'           => 'application/json',
        ])->timeout(30)->post($pluginUrl, json_decode($body, true));

        if (!$response->successful()) {
            throw new \RuntimeException("Plugin publish failed: HTTP {$response->status()} — " . $response->body());
        }

        return $response->json();
    }

    /** Publish via WordPress REST API directly */
    private function publishViaRestApi(Article $article, Site $site): array
    {
        $apiUrl = rtrim($site->wp_api_url ?? $site->url, '/') . '/wp-json/wp/v2/posts';
        $password = decrypt($site->wp_app_password);

        $response = Http::withBasicAuth($site->wp_username, $password)
            ->timeout(30)
            ->post($apiUrl, [
                'title'   => $article->title,
                'content' => $article->content,
                'excerpt' => $article->excerpt ?? '',
                'slug'    => $article->slug,
                'status'  => 'publish',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("REST API publish failed: HTTP {$response->status()}");
        }

        return [
            'post_id'  => $response->json('id'),
            'post_url' => $response->json('link'),
        ];
    }

    private function buildPayload(Article $article): array
    {
        return [
            'title'             => $article->title,
            'content'           => $article->content,
            'excerpt'           => $article->excerpt ?? '',
            'slug'              => $article->slug,
            'status'            => 'publish',
            'featured_image_url' => $article->featured_image_url,
            'yoast' => [
                'title'     => $article->meta_title,
                'metadesc'  => $article->meta_description,
                'focuskw'   => $article->focus_keyword,
            ],
            'rankmath' => [
                'title'         => $article->meta_title,
                'description'   => $article->meta_description,
                'focus_keyword' => $article->focus_keyword,
            ],
        ];
    }

    public function tags(): array
    {
        return ["article:{$this->articleId}", 'wordpress-publish'];
    }
}
