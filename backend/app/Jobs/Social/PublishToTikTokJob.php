<?php

namespace App\Jobs\Social;

use App\Models\Article;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Adapters\TikTokAdapter;
use App\Services\Social\Adapters\PinterestAdapter;
use App\Services\Credits\CreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PublishToTikTokJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [300, 900, 2700];

    public function __construct(
        public Article $article,
        public SocialAccount $account,
    ) {}

    public function handle(TikTokAdapter $adapter, CreditService $creditService): void
    {
        $workspace = $this->article->workspace;
        
        if (!$this->checkRateLimit('tiktok', $workspace->id)) {
            $this->release(300);
            return;
        }

        $credits = config('credits.actions.tiktok_video') ?? 5;
        
        try {
            $creditService->reserve($workspace, 'tiktok_video', $credits);
        } catch (\App\Services\Credits\InsufficientCreditsException $e) {
            Log::warning('[PublishToTikTokJob] Insufficient credits', ['workspace_id' => $workspace->id]);
            return;
        }

        try {
            $dto = $adapter->adapt($this->article);
            
            $socialPost = SocialPost::create([
                'article_id' => $this->article->id,
                'social_account_id' => $this->account->id,
                'workspace_id' => $workspace->id,
                'platform' => 'tiktok',
                'post_type' => 'video',
                'caption' => $dto->caption,
                'hashtags' => $dto->hashtags,
                'video_url' => $dto->videoUrl,
                'status' => 'generating',
            ]);

            // TikTok requires: 1) upload video, 2) publish
            $result = $this->publish($dto, $socialPost);

            if ($result['success']) {
                $socialPost->update([
                    'platform_post_id' => $result['post_id'],
                    'status' => 'published',
                    'published_at' => now(),
                    'credits_consumed' => $credits,
                ]);

                $creditService->confirm($workspace, 'tiktok_video');
                
                Log::info('[PublishToTikTokJob] Published', [
                    'post_id' => $result['post_id'],
                    'article_id' => $this->article->id,
                ]);
            } else {
                throw new \Exception($result['error'] ?? 'Unknown error');
            }
        } catch (\Throwable $e) {
            $creditService->refund($workspace, 'tiktok_video');
            $this->handleFailure($e);
            throw $e;
        }
    }

    private function publish($dto, SocialPost $socialPost): array
    {
        $accessToken = decrypt($this->account->access_token);
        $openId = $this->account->platform_account_id;

        // Get video URL from FFmpeg service or social post
        $videoUrl = $dto->videoUrl ?? $socialPost->video_url;
        
        if (!$videoUrl) {
            return ['success' => false, 'error' => 'No video URL available'];
        }

        // TikTok Content Posting API requires file upload
        // Step 1: Initialize upload
        $initResponse = Http::withToken($accessToken)->post('https://open.tiktokapis.com/v2/video/init/', [
            'open_id' => $openId,
            'upload_id' => uniqid(),
        ]);

        if (!$initResponse->successful()) {
            return ['success' => false, 'error' => 'Failed to initialize upload'];
        }

        $uploadUrl = $initResponse->json('upload_url');
        
        // Step 2: Upload video file
        $videoContent = file_get_contents($videoUrl);
        
        $uploadResponse = Http::withHeaders([
            'Content-Type' => 'video/mp4',
        ])->put($uploadUrl, $videoContent);

        if (!$uploadResponse->successful()) {
            return ['success' => false, 'error' => 'Failed to upload video'];
        }

        // Step 3: Create video post
        $createResponse = Http::withToken($accessToken)->post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
            'open_id' => $openId,
            'video_info' => [
                'upload_id' => $initResponse->json('upload_id'),
            ],
            'post_info' => [
                'title' => $dto->caption,
                'description' => $dto->caption,
            ],
        ]);

        if (!$createResponse->successful()) {
            return ['success' => false, 'error' => $createResponse->body()];
        }

        return [
            'success' => true,
            'post_id' => $createResponse->json('video_id'),
        ];
    }

    private function checkRateLimit(string $platform, int $workspaceId): bool
    {
        $key = "publish_count:{$platform}:{$workspaceId}:" . date('Y-m-d');
        $current = \Illuminate\Support\Facades\Redis::get($key) ?? 0;
        $limit = config('platforms.tiktok.max_posts_per_day') ?? 5;

        return $current < $limit;
    }

    private function handleFailure(\Throwable $e): void
    {
        Log::error('[PublishToTikTokJob] Failed', [
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