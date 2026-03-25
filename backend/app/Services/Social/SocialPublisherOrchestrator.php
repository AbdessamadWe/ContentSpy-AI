<?php
namespace App\Services\Social;

use App\Models\Article;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Contracts\SocialPublisherInterface;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates publishing to multiple social platforms.
 * Creates SocialPost records and delegates to platform-specific publishers.
 */
class SocialPublisherOrchestrator
{
    /** @var SocialPublisherInterface[] */
    private array $publishers;

    public function __construct(
        FacebookPublisher  $facebook,
        InstagramPublisher $instagram,
        TikTokPublisher    $tiktok,
        PinterestPublisher $pinterest,
    ) {
        $this->publishers = [
            'facebook'  => $facebook,
            'instagram' => $instagram,
            'tiktok'    => $tiktok,
            'pinterest' => $pinterest,
        ];
    }

    /**
     * Publish an article to all connected social accounts for the site.
     * Creates a SocialPost per account and records results.
     */
    public function publishToAll(Article $article, array $options = []): array
    {
        $accounts = SocialAccount::where('site_id', $article->site_id)
            ->where('is_active', true)
            ->get();

        $results = [];
        foreach ($accounts as $account) {
            $results[] = $this->publishToAccount($article, $account, $options[$account->platform] ?? []);
        }

        return $results;
    }

    /**
     * Publish to a single social account.
     * Creates/updates SocialPost record.
     */
    public function publishToAccount(Article $article, SocialAccount $account, array $options = []): array
    {
        $post = SocialPost::firstOrCreate([
            'article_id'   => $article->id,
            'workspace_id' => $article->workspace_id,
            'platform'     => $account->platform,
        ], [
            'status'      => 'pending',
            'retry_count' => 0,
        ]);

        if ($post->status === 'published') {
            return ['skipped' => true, 'platform' => $account->platform];
        }

        $publisher = $this->publishers[$account->platform] ?? null;

        if (! $publisher) {
            Log::warning("[SocialOrchestrator] No publisher for platform: {$account->platform}");
            return ['error' => "Unsupported platform: {$account->platform}"];
        }

        try {
            $result = $publisher->publish($article, $account, $options);

            $post->update([
                'status'           => 'published',
                'platform_post_id' => $result['platform_post_id'],
                'post_url'         => $result['post_url'],
                'published_at'     => now(),
            ]);

            return array_merge($result, ['platform' => $account->platform, 'success' => true]);

        } catch (\Throwable $e) {
            $post->increment('retry_count');
            $post->update([
                'status'       => $post->retry_count >= 3 ? 'failed' : 'pending',
                'last_error'   => $e->getMessage(),
            ]);

            Log::error("[SocialOrchestrator] {$account->platform} publish failed for article #{$article->id}: {$e->getMessage()}");

            return [
                'platform' => $account->platform,
                'success'  => false,
                'error'    => $e->getMessage(),
            ];
        }
    }
}
