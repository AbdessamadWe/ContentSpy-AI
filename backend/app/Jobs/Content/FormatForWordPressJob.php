<?php
namespace App\Jobs\Content;

use App\Jobs\Publishing\PublishToWordPressJob;
use App\Models\Article;
use App\Models\Workspace;
use App\Services\Content\ContentPipelineOrchestrator;
use App\Services\Content\Steps\WordPressFormatterService;
use App\Services\Credits\CreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FormatForWordPressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(
        private readonly int    $articleId,
        private readonly string $reservationToken,
        private readonly int    $workspaceId,
    ) {}

    public function backoff(): array
    {
        return [15, 30];
    }

    public function handle(WordPressFormatterService $formatter, CreditService $credits, ContentPipelineOrchestrator $orchestrator): void
    {
        $article = Article::findOrFail($this->articleId);

        // Accept both 'images' and 'review' states (images may be skipped)
        if (! in_array($article->generation_status, ['images', 'review'])) {
            return;
        }

        $article->advancePipelineStep('review');

        $formattedContent = $formatter->format($article);
        $article->update([
            'formatted_content' => $formattedContent,
            'generation_status' => 'ready',
            'publish_status'    => 'ready',
        ]);

        // Confirm credit reservation — pipeline complete
        $workspace = Workspace::findOrFail($this->workspaceId);
        try {
            $credits->confirm($workspace, $this->reservationToken, actionId: (string) $this->articleId);
        } catch (\Throwable $e) {
            // Log but don't fail — article is generated
            \Illuminate\Support\Facades\Log::warning("[FormatForWordPressJob] Credit confirm failed: {$e->getMessage()}");
        }

        // Auto-publish if enabled
        if ($article->auto_publish) {
            PublishToWordPressJob::dispatch($article->id)
                ->onQueue('publishing');
        }
    }

    public function failed(\Throwable $e): void
    {
        $article = Article::find($this->articleId);
        if ($article) {
            app(ContentPipelineOrchestrator::class)->handleFailure($article, $this->reservationToken, $e);
        }
    }
}
