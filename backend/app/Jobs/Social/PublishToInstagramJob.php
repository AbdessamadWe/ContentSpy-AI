<?php

namespace App\Jobs\Social;

use App\Models\Article;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Adapters\InstagramAdapter;
use App\Services\Credits\CreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PublishToInstagramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [300, 900, 2700];

    public function __construct(
        public Article $article,
        public SocialAccount $account,
    ) {}

    public function handle(InstagramAdapter $adapter, CreditService $creditService): void
    {
        $workspace = $this->article->workspace;
        
        // Check rate limit
        if (!$this->checkRateLimit('instagram', $workspace->id)) {
            $this->release(300);
            return;
        }

        // Reserve credits (2 for image post, 5 for reel)
        $creditType = 'instagram_post';
        $credits = config('credits.actions.instagram_post');
        
        try {
            $creditService->reserve($workspace, $creditType, $credits);
        } catch (\App\Services\Credits\InsufficientCreditsException $e) {
            Log::warning('[PublishToInstagramJob] Insufficient credits', ['workspace_id' => $workspace->id]);
            return;
        }

        try {
            $dto = $adapter->adapt($this->article);
            
            $socialPost = SocialPost::create([
                'article_id' => $this->article->id,
                'social_account_id' => $this->account->id,
                'workspace_id' => $workspace->id,
                'platform' => 'instagram',
                'post_type' => $dto->postType,
                'caption' => $dto->caption,
                'hashtags' => $dto->hashtags,
                'media_urls' => $dto->mediaUrls,
                'status' => 'generating',
            ]);

            // Instagram requires two-step publishing: create container, then publish
            $result = $this->publish($dto);

            if ($result['success']) {
                $socialPost->update([
                    'platform_post_id' => $result['post_id'],
                    'status' => 'published',
                    'published_at' => now(),
                    'credits_consumed' => $credits,
                ]);

                $creditService->confirm($workspace, $creditType);
                
                Log::info('[PublishToInstagramJob] Published', [
                    'post_id' => $result['post_id'],
                    'article_id' => $this->article->id,
                ]);
            } else {
                throw new \Exception($result['error'] ?? 'Unknown error');
            }
        } catch (\Throwable $e) {
            $creditService->refund($workspace, $creditType);
            $this->handleFailure($e);
            throw $e;
        }
    }

    private function publish($dto): array
    {
        $igBusinessId = $this->account->platform_account_id;
        $accessToken = decrypt($this->account->access_token);
        
        // Step 1: Create media container
        $createEndpoint = "https://graph.facebook.com/v18.0/{$igBusinessId}/media";
        
        $mediaData = [
            'image_url' => $dto->mediaUrls[0] ?? null,
            'caption' => $dto->caption,
            'access_token' => $accessToken,
        ];

        $createResponse = Http::post($createEndpoint, $mediaData);

        if (!$createResponse->successful()) {
            Log::error('[PublishToInstagramJob] Create media failed', [
                'response' => $createResponse->body(),
            ]);
            return ['success' => false, 'error' => $createResponse->body()];
        }

        $creationId = $createResponse['id'];

        // Step 2: Publish the media
        $publishEndpoint = "https://graph.facebook.com/v18.0/{$igBusinessId}/media_publish";
        
        $publishResponse = Http::post($publishEndpoint, [
            'creation_id' => $creationId,
            'access_token' => $accessToken,
        ]);

        if (!$publishResponse->successful()) {
            Log::error('[PublishToInstagramJob] Publish failed', [
                'response' => $publishResponse->body(),
            ]);
            return ['success' => false, 'error' => $publishResponse->body()];
        }

        return [
            'success' => true,
            'post_id' => $publishResponse['id'] ?? $creationId,
        ];
    }

    private function checkRateLimit(string $platform, int $workspaceId): bool
    {
        $key = "publish_count:{$platform}:{$workspaceId}:" . date('Y-m-d');
        $current = \Illuminate\Support\Facades\Redis::get($key) ?? 0;
        $limit = config('platforms.instagram.max_posts_per_day') ?? 25;

        return $current < $limit;
    }

    private function handleFailure(\Throwable $e): void
    {
        Log::error('[PublishToInstagramJob] Failed', [
            'article_id' => $this->article->id,
            'error' => $e->getMessage(),
        ]);

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