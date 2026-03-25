<?php

namespace App\Jobs\Social;

use App\Models\Article;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Adapters\PinterestAdapter;
use App\Services\Credits\CreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PublishToPinterestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [300, 900, 2700];

    public function __construct(
        public Article $article,
        public SocialAccount $account,
    ) {}

    public function handle(PinterestAdapter $adapter, CreditService $creditService): void
    {
        $workspace = $this->article->workspace;
        
        if (!$this->checkRateLimit('pinterest', $workspace->id)) {
            $this->release(300);
            return;
        }

        $credits = config('credits.actions.pinterest_pin') ?? 1;
        
        try {
            $creditService->reserve($workspace, 'pinterest_pin', $credits);
        } catch (\App\Services\Credits\InsufficientCreditsException $e) {
            Log::warning('[PublishToPinterestJob] Insufficient credits', ['workspace_id' => $workspace->id]);
            return;
        }

        try {
            $dto = $adapter->adapt($this->article);
            
            $socialPost = SocialPost::create([
                'article_id' => $this->article->id,
                'social_account_id' => $this->account->id,
                'workspace_id' => $workspace->id,
                'platform' => 'pinterest',
                'post_type' => 'pin',
                'caption' => $dto->caption,
                'media_urls' => $dto->mediaUrls,
                'status' => 'generating',
            ]);

            $result = $this->publish($dto);

            if ($result['success']) {
                $socialPost->update([
                    'platform_post_id' => $result['post_id'],
                    'status' => 'published',
                    'published_at' => now(),
                    'credits_consumed' => $credits,
                ]);

                $creditService->confirm($workspace, 'pinterest_pin');
                
                Log::info('[PublishToPinterestJob] Published', [
                    'post_id' => $result['post_id'],
                    'article_id' => $this->article->id,
                ]);
            } else {
                throw new \Exception($result['error'] ?? 'Unknown error');
            }
        } catch (\Throwable $e) {
            $creditService->refund($workspace, 'pinterest_pin');
            $this->handleFailure($e);
            throw $e;
        }
    }

    private function publish($dto): array
    {
        $accessToken = decrypt($this->account->access_token);
        
        $response = Http::withToken($accessToken)->post('https://api.pinterest.com/v5/pins', [
            'board_id' => $dto->metadata['board_id'] ?? null,
            'media' => [
                'source' => $dto->mediaUrls[0] ?? null,
            ],
            'title' => $dto->metadata['title'] ?? 'Pin',
            'description' => $dto->caption,
            'link' => $dto->metadata['link'] ?? '',
        ]);

        if (!$response->successful()) {
            Log::error('[PublishToPinterestJob] API error', [
                'response' => $response->body(),
            ]);
            return ['success' => false, 'error' => $response->body()];
        }

        return [
            'success' => true,
            'post_id' => $response->json('id'),
        ];
    }

    private function checkRateLimit(string $platform, int $workspaceId): bool
    {
        $key = "publish_count:{$platform}:{$workspaceId}:" . date('Y-m-d');
        $current = \Illuminate\Support\Facades\Redis::get($key) ?? 0;
        $limit = config('platforms.pinterest.max_posts_per_day') ?? 150;

        return $current < $limit;
    }

    private function handleFailure(\Throwable $e): void
    {
        Log::error('[PublishToPinterestJob] Failed', [
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