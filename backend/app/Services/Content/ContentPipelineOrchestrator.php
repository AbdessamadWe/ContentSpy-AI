<?php
namespace App\Services\Content;

use App\Jobs\Content\GenerateOutlineJob;
use App\Models\Article;
use App\Models\ContentSuggestion;
use App\Models\Workspace;
use App\Services\Credits\CreditService;
use App\Services\Credits\InsufficientCreditsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Kicks off and manages the 8-step content generation pipeline.
 * Creates the Article record, reserves credits, and dispatches GenerateOutlineJob.
 */
class ContentPipelineOrchestrator
{
    public function __construct(private readonly CreditService $credits) {}

    /**
     * Start the generation pipeline for a content suggestion.
     *
     * @param ContentSuggestion $suggestion
     * @param array $options {
     *   word_count_target?: int,
     *   tone?: string,
     *   model?: string,
     *   generate_images?: bool,
     *   auto_publish?: bool,
     * }
     * @throws InsufficientCreditsException
     */
    public function start(ContentSuggestion $suggestion, array $options = []): Article
    {
        $wordCount     = $options['word_count_target'] ?? $suggestion->recommended_word_count ?? 1500;
        $totalCredits  = $this->estimateCredits($wordCount, $options);
        $workspace     = Workspace::findOrFail($suggestion->workspace_id);

        // Reserve credits up front — prevents overdraft under concurrent jobs
        $reservationToken = $this->credits->reserve($workspace, $totalCredits, 'article_generation');

        try {
            $article = DB::transaction(function () use ($suggestion, $options, $wordCount, $reservationToken, $totalCredits) {
                $article = Article::create([
                    'workspace_id'         => $suggestion->workspace_id,
                    'site_id'              => $suggestion->site_id,
                    'suggestion_id'        => $suggestion->id,
                    'title'                => $suggestion->suggested_title ?? $suggestion->title,
                    'focus_keyword'        => $suggestion->focus_keyword ?? null,
                    'content_angle'        => $suggestion->content_angle ?? null,
                    'target_keywords'      => $suggestion->target_keywords ?? [],
                    'word_count_target'    => $wordCount,
                    'tone'                 => $options['tone'] ?? 'informative',
                    'ai_model_text'        => $options['model'] ?? config('contentspy.default_model', 'gpt-4o'),
                    'generate_images'      => $options['generate_images'] ?? true,
                    'auto_publish'         => $options['auto_publish'] ?? false,
                    'generation_status'    => 'pending',
                    'publish_status'       => 'draft',
                    'credit_reservation'   => $reservationToken,
                    'credits_reserved'     => $totalCredits,
                ]);

                $suggestion->update(['status' => 'generating']);

                return $article;
            });

            GenerateOutlineJob::dispatch($article->id, $reservationToken, $workspace->id)
                ->onQueue('content_generation');

            return $article;

        } catch (\Throwable $e) {
            $this->credits->refund($workspace, $reservationToken);
            throw $e;
        }
    }

    /**
     * Mark article as failed and refund unused credits.
     * Called from any step job's failed() method.
     */
    public function handleFailure(Article $article, string $reservationToken, \Throwable $reason): void
    {
        try {
            $article->update([
                'generation_status' => 'failed',
                'failure_reason'    => substr($reason->getMessage(), 0, 500),
            ]);

            $workspace = Workspace::find($article->workspace_id);
            if ($workspace) {
                $this->credits->refund($workspace, $reservationToken);
            }
        } catch (\Throwable $e) {
            Log::error("[ContentPipelineOrchestrator] handleFailure itself failed", [
                'article_id' => $article->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Estimate total credits needed for full pipeline run.
     */
    private function estimateCredits(int $wordCount, array $options): int
    {
        $credits  = 3;                                        // outline
        $credits += (int) ceil($wordCount / 1000) * 5;       // body (5 per 1k words)
        $credits += 2;                                        // SEO optimization
        $credits += 1;                                        // duplicate check
        $credits += ($options['generate_images'] ?? true) ? 4 : 0;   // ~2 images
        $credits += ($options['auto_publish']    ?? false) ? 1 : 0;   // publishing
        return $credits;
    }
}
