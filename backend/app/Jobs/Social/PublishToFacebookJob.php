<?php

namespace App\Jobs\Social;

use App\Models\Article;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Adapters\FacebookAdapter;
use App\Services\Credits\CreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PublishToFacebookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [300, 900, 2700]; // 5min, 15min, 45min

    public function __construct(
        public Article $article,
        public SocialAccount $account,
    ) {}

    public function handle(FacebookAdapter $adapter, CreditService $creditService): void
    {
        $workspace = $this->article->workspace;
        
        // Check rate limit
        if (!$this->checkRateLimit('facebook', $workspace->id)) {
            $this->release(300);
            return;
        }

        // Reserve credits
        try {
            $creditService->reserve($workspace, 'facebook_post', config('credits.actions.facebook_post'));
        } catch (\App\Services\Credits\InsufficientCreditsException $e) {
            Log::warning('[PublishToFacebookJob] Insufficient credits', ['workspace_id' => $workspace->id]);
            return;
        }

        try {
            // Adapt article to Facebook format
            $dto = $adapter->adapt($this->article);
            
            // Create social post record
            $socialPost = SocialPost::create([
                'article_id' => $this->article->id,
                'social_account_id' => $this->account->id,
                'workspace_id' => $workspace->id,
                'platform' => 'facebook',
                'post_type' => $dto->postType,
                'caption' => $dto->caption,
                'hashtags' => $dto->hashtags,
                'media_urls' => $dto->mediaUrls,
                'status' => 'generating',
            ]);

            // Publish to Facebook Graph API
            $result = $this->publish($dto);

            if ($result['success']) {
                $socialPost->update([
                    'platform_post_id' => $result['post_id'],
                    'status' => 'published',
                    'published_at' => now(),
                    'credits_consumed' => config('credits.actions.facebook_post'),
                ]);

                $creditService->confirm($workspace, 'facebook_post');
                
                Log::info('[PublishToFacebookJob] Published', [
                    'post_id' => $result['post_id'],
                    'article_id' => $this->article->id,
                ]);
            } else {
                throw new \Exception($result['error'] ?? 'Unknown error');
            }
        } catch (\Throwable $e) {
            $creditService->refund($workspace, 'facebook_post');
            $this->handleFailure($e);
            throw $e;
        }
    }

    private function publish($dto): array
    {
        $pageId = $this->account->page_id;
        $accessToken = decrypt($this->account->access_token);
        
        $endpoint = "https://graph.facebook.com/v18.0/{$pageId}/feed";
        
        $data = [
            'message' => $dto->caption,
            'access_token' => $accessToken,
        ];

        // Add media if available
        if (!empty($dto->mediaUrls)) {
            // For photo posts, use /photos endpoint
            $photoEndpoint = "https://graph.facebook.com/v18.0/{$pageId}/photos";
            $data['url'] = $dto->mediaUrls[0];
            $data['caption'] = $dto->caption;
            
            $response = Http::post($photoEndpoint, $data);
        } else {
            $response = Http::post($endpoint, $data);
        }

        if (!$response->successful()) {
            Log::error('[PublishToFacebookJob] API error', [
                'response' => $response->body(),
            ]);
            return ['success' => false, 'error' => $response->body()];
        }

        $postId = $response['id'] ?? null;
        
        return [
            'success' => (bool) $postId,
            'post_id' => $postId,
        ];
    }

    private function checkRateLimit(string $platform, int $workspaceId): bool
    {
        $key = "publish_count:{$platform}:{$workspaceId}:" . date('Y-m-d');
        $current = \Illuminate\Support\Facades\Redis::get($key) ?? 0;
        $limit = config('platforms.facebook.max_posts_per_day') ?? 25;

        return $current < $limit;
    }

    private function handleFailure(\Throwable $e): void
    {
        Log::error('[PublishToFacebookJob] Failed', [
            'article_id' => $this->article->id,
            'error' => $e->getMessage(),
        ]);

        // Update retry count
        $socialPost = SocialPost::where('article_id', $this->article->id)
            ->where('social_account_id', $this->account->id)
            ->first();

        if ($socialPost) {
            $socialPost->increment('retry_count');
            $socialPost->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}