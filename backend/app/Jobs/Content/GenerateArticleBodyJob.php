<?php
namespace App\Jobs\Content;

use App\Models\Article;
use App\Services\Content\ContentPipelineOrchestrator;
use App\Services\Content\Steps\ArticleBodyGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateArticleBodyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 600; // Long articles can take time

    public function __construct(
        private readonly int    $articleId,
        private readonly string $reservationToken,
        private readonly int    $workspaceId,
    ) {}

    public function backoff(): array
    {
        return [60, 120];
    }

    public function handle(ArticleBodyGeneratorService $service, ContentPipelineOrchestrator $orchestrator): void
    {
        $article = Article::findOrFail($this->articleId);

        if ($article->generation_status !== 'outline') {
            return;
        }

        $article->advancePipelineStep('writing');
        $content   = $service->generate($article);
        $wordCount = str_word_count(strip_tags($content));

        $article->update([
            'content'    => $content,
            'word_count' => $wordCount,
        ]);

        SeoOptimizationJob::dispatch($this->articleId, $this->reservationToken, $this->workspaceId)
            ->onQueue('content_generation');
    }

    public function failed(\Throwable $e): void
    {
        $article = Article::find($this->articleId);
        if ($article) {
            app(ContentPipelineOrchestrator::class)->handleFailure($article, $this->reservationToken, $e);
        }
    }
}
