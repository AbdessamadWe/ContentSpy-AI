<?php
namespace App\Services\Ai;

use App\Models\TokenUsageLog;
use App\Services\Ai\Providers\DalleProvider;
use App\Services\Ai\Providers\MidjourneyProvider;
use App\Services\Ai\Providers\ReplicateProvider;
use Illuminate\Support\Facades\Log;

/**
 * Central image generation service.
 * Fallback order: Midjourney → DALL-E 3 → Replicate (Stable Diffusion XL)
 * All costs logged to token_usage_logs.
 */
class ImageGenerationGateway
{
    public function __construct(
        private readonly MidjourneyProvider $midjourney,
        private readonly DalleProvider      $dalle,
        private readonly ReplicateProvider  $replicate,
    ) {}

    /**
     * Generate an image and return ImageResponse with R2 URL.
     *
     * @param string  $prompt            Text prompt
     * @param int     $workspaceId       For token usage logging
     * @param string  $size              e.g. '1024x1024'
     * @param string  $preferredProvider 'midjourney' | 'dalle' | 'replicate'
     * @param int|null $articleId        For token usage logging
     * @param int|null $siteId           For token usage logging
     */
    public function generate(
        string  $prompt,
        int     $workspaceId,
        string  $size = '1024x1024',
        string  $preferredProvider = 'midjourney',
        ?int    $articleId = null,
        ?int    $siteId = null,
    ): ImageResponse {
        $chain = $this->buildChain($preferredProvider);

        $lastException = null;
        foreach ($chain as $provider) {
            if (! $provider->isAvailable()) {
                continue;
            }
            try {
                $response = $provider->generate($prompt, $size);
                $this->logUsage($workspaceId, $response, $articleId, $siteId);
                return $response;
            } catch (\Throwable $e) {
                Log::warning("[ImageGenerationGateway] Provider {$provider->name()} failed: {$e->getMessage()}");
                $lastException = $e;
            }
        }

        throw new \RuntimeException(
            'All image generation providers failed. Last error: ' . ($lastException?->getMessage() ?? 'unknown'),
            0,
            $lastException
        );
    }

    /** Build provider chain with preferred provider first */
    private function buildChain(string $preferred): array
    {
        $all = [
            'midjourney' => $this->midjourney,
            'dalle'      => $this->dalle,
            'replicate'  => $this->replicate,
        ];

        $chain = [];
        if (isset($all[$preferred])) {
            $chain[] = $all[$preferred];
        }
        foreach ($all as $key => $provider) {
            if ($key !== $preferred) {
                $chain[] = $provider;
            }
        }
        return $chain;
    }

    private function logUsage(int $workspaceId, ImageResponse $response, ?int $articleId, ?int $siteId): void
    {
        try {
            TokenUsageLog::create([
                'workspace_id'      => $workspaceId,
                'site_id'           => $siteId,
                'action_type'       => 'image_generation',
                'model'             => $response->model,
                'provider'          => $response->provider,
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'total_tokens'      => 0,
                'images_count'      => $response->imagesCount,
                'cost_usd'          => $response->costUsd,
                'article_id'        => $articleId,
            ]);
        } catch (\Throwable $e) {
            Log::error("[ImageGenerationGateway] Failed to log usage: {$e->getMessage()}");
        }
    }
}
