<?php
namespace App\Jobs\Content;

use App\Models\Article;
use App\Services\Ai\ImageGenerationGateway;
use App\Services\Content\ContentPipelineOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 300;

    public function __construct(
        private readonly int    $articleId,
        private readonly string $reservationToken,
        private readonly int    $workspaceId,
    ) {}

    public function backoff(): array
    {
        return [60, 120];
    }

    public function handle(ImageGenerationGateway $gateway, ContentPipelineOrchestrator $orchestrator): void
    {
        $article = Article::findOrFail($this->articleId);

        if ($article->generation_status !== 'images') {
            return;
        }

        $preferredProvider = $article->site?->settings['image_provider'] ?? 'dalle';

        try {
            // Generate featured image
            $featuredPrompt = "Professional featured image for blog article: {$article->title}. High quality, editorial style.";
            $featuredImage  = $gateway->generate(
                prompt:            $featuredPrompt,
                workspaceId:       $this->workspaceId,
                size:              '1792x1024',
                preferredProvider: $preferredProvider,
                articleId:         $this->articleId,
                siteId:            $article->site_id,
            );

            $article->update([
                'featured_image_url'   => $featuredImage->url,
                'featured_image_r2_key' => $featuredImage->r2Key,
            ]);

        } catch (\Throwable $e) {
            // Image failure is non-fatal — log and continue to formatting
            Log::warning("[GenerateImagesJob] Image generation failed for article #{$this->articleId}: {$e->getMessage()}");
        }

        // Always proceed to formatting even if images failed
        FormatForWordPressJob::dispatch($this->articleId, $this->reservationToken, $this->workspaceId)
            ->onQueue('content_generation');
    }

    public function failed(\Throwable $e): void
    {
        // Image failure is non-fatal — dispatch formatting anyway
        Log::warning("[GenerateImagesJob] Job itself failed for article #{$this->articleId}: {$e->getMessage()}");

        $article = Article::find($this->articleId);
        if ($article && $article->generation_status === 'images') {
            FormatForWordPressJob::dispatch($this->articleId, $this->reservationToken, $this->workspaceId)
                ->onQueue('content_generation');
        }
    }
}
