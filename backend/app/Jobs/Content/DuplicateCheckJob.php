<?php
namespace App\Jobs\Content;

use App\Models\Article;
use App\Services\Content\ContentPipelineOrchestrator;
use App\Services\Content\Steps\DuplicateCheckerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DuplicateCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 30;

    /** Duplicate score above this threshold blocks publishing */
    const DUPLICATE_THRESHOLD = 85.0;

    public function __construct(
        private readonly int    $articleId,
        private readonly string $reservationToken,
        private readonly int    $workspaceId,
    ) {}

    public function backoff(): array
    {
        return [15, 30];
    }

    public function handle(DuplicateCheckerService $service, ContentPipelineOrchestrator $orchestrator): void
    {
        $article = Article::findOrFail($this->articleId);

        if ($article->generation_status !== 'seo') {
            return;
        }

        // Transition to images step — duplicate check happens concurrently
        $article->advancePipelineStep('images');

        $score  = $service->check($article);
        $passed = $score < self::DUPLICATE_THRESHOLD;

        $article->update([
            'duplicate_score'       => $score,
            'duplicate_check_passed' => $passed,
        ]);

        if (! $passed) {
            $article->advancePipelineStep('failed');
            $article->update(['failure_reason' => "Duplicate score {$score}% exceeds threshold " . self::DUPLICATE_THRESHOLD . '%']);
            app(ContentPipelineOrchestrator::class)->handleFailure(
                $article,
                $this->reservationToken,
                new \RuntimeException("Duplicate content detected: score={$score}%")
            );
            return;
        }

        // Proceed to images or skip straight to formatting
        $generateImages = $article->generate_images ?? true;

        if ($generateImages) {
            GenerateImagesJob::dispatch($this->articleId, $this->reservationToken, $this->workspaceId)
                ->onQueue('content_generation');
        } else {
            FormatForWordPressJob::dispatch($this->articleId, $this->reservationToken, $this->workspaceId)
                ->onQueue('content_generation');
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
