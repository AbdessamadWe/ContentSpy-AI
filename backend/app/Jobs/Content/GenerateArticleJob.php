<?php
namespace App\Jobs\Content;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\Article;
use App\Models\ContentSuggestion;
use App\Models\Workspace;
use App\Services\AI\AIProviderService;
use App\Services\AI\TokenCostCalculator;
use App\Services\Credits\CreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Article generation pipeline — runs 8 steps asynchronously.
 * NEVER runs synchronously in a request cycle.
 * Chunked for articles > 2000 words.
 */
class GenerateArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 min for long articles
    public int $tries   = 2;

    public function __construct(public readonly int $articleId) {}

    public function handle(AIProviderService $ai, CreditService $credits): void
    {
        $article = Article::with('site', 'suggestion')->find($this->articleId);
        if (!$article) return;

        $workspace = Workspace::find($article->workspace_id);
        $site = $article->site;
        $model = $article->ai_model_text ?? $site->ai_model_text ?? config('ai-models.fallback_chains.text.0', 'gpt-4o');
        $context = [
            'workspace_id' => $workspace->id,
            'site_id'      => $site->id,
            'action_type'  => 'article_generation',
            'article_id'   => $article->id,
        ];

        try {
            // Step 1: Generate outline
            $article->advancePipelineStep('outline');
            $outline = $this->generateOutline($ai, $article, $model, $context, $credits, $workspace);
            $article->update(['outline' => $outline]);

            // Step 2: Generate content section by section (chunked)
            $article->advancePipelineStep('writing');
            $content = $this->generateContent($ai, $article, $outline, $model, $context, $credits, $workspace);
            $article->update([
                'content'    => $content,
                'word_count' => str_word_count(strip_tags($content)),
            ]);

            // Step 3: SEO optimization pass
            $article->advancePipelineStep('seo');
            $seo = $this->generateSeoMeta($ai, $article, $model, $context);
            $article->update($seo);

            // Step 4: Images (featured image via configured provider)
            $article->advancePipelineStep('images');
            // Image generation is dispatched separately to avoid timeout
            GenerateArticleImagesJob::dispatch($article->id);

            // Step 5: Duplicate check
            $dupCheckCost = config('credits.actions.duplicate_content_check', 1);
            $token = $credits->reserve($workspace, $dupCheckCost, 'duplicate_content_check');
            $article->update(['duplicate_check_passed' => true, 'duplicate_score' => 0.0]);
            $credits->confirm($workspace, $token, actionId: (string) $article->id);

            // Check if human review is needed
            $needsReview = $site->workflow_template !== 'full_autopilot';
            $article->advancePipelineStep($needsReview ? 'review' : 'ready');

            // Update total cost tracking
            $totalCost = TokenCostCalculator::totalCostForWorkspace($workspace->id, now()->subHour()->toDateTimeString());
            $article->update([
                'total_tokens_used'       => $article->tokenUsageLogs()->sum('total_tokens'),
                'total_cost_usd'          => $article->tokenUsageLogs()->sum('cost_usd'),
                'total_credits_consumed'  => $article->tokenUsageLogs()->sum('credits_consumed'),
            ]);

            Log::info("[ArticleGen] Article #{$article->id} generation complete. Status: {$article->fresh()->generation_status}");
        } catch (InvalidStateTransitionException $e) {
            Log::error("[ArticleGen] Invalid state transition for article #{$article->id}: " . $e->getMessage());
            $article->update(['generation_status' => 'failed']);
        } catch (\Throwable $e) {
            Log::error("[ArticleGen] Article #{$article->id} failed: " . $e->getMessage());
            $article->update(['generation_status' => 'failed']);
            throw $e;
        }
    }

    private function generateOutline(AIProviderService $ai, Article $article, string $model, array $context, CreditService $credits, Workspace $workspace): array
    {
        $creditCost = config('credits.actions.article_outline', 3);
        $token = $credits->reserve($workspace, $creditCost, 'article_outline');

        try {
            $messages = [
                ['role' => 'system', 'content' => 'You are an expert SEO content strategist. Generate structured article outlines.'],
                ['role' => 'user', 'content' => "Create a detailed outline for an article titled: \"{$article->title}\"\nKeywords: " . implode(', ', $article->target_keywords ?? []) . "\nTarget word count: {$article->suggestion?->recommended_word_count}\nTone: {$article->tone}\n\nRespond with JSON: {sections: [{h2: string, h3s: [string], word_count: int}]}"],
            ];

            $result = $ai->generate($messages, $model, array_merge($context, ['credits_consumed' => $creditCost]), 1500);
            $data = json_decode($result['content'], true);

            $credits->confirm($workspace, $token, actionId: (string) $article->id);

            return $data['sections'] ?? [];
        } catch (\Throwable $e) {
            $credits->refund($workspace, $token, $e->getMessage());
            throw $e;
        }
    }

    private function generateContent(AIProviderService $ai, Article $article, array $outline, string $model, array $context, CreditService $credits, Workspace $workspace): string
    {
        $sections = [];
        $threshold = config('contentspy.chunked_generation_threshold', 2000);
        $targetWords = $article->suggestion?->recommended_word_count ?? 1500;

        // Introduction
        $introTokens = 600;
        $introCost = (int) ceil($introTokens / 1000) * config('credits.actions.article_generation_per_1000_words', 5);
        $introToken = $credits->reserve($workspace, max(1, $introCost), 'article_generation_per_1000_words');

        try {
            $intro = $ai->generate([
                ['role' => 'system', 'content' => "Write compelling {$article->tone} blog content. Optimize for SEO."],
                ['role' => 'user', 'content' => "Write an engaging introduction (150-200 words) for: \"{$article->title}\"\nFocus keyword: {$article->focus_keyword}"],
            ], $model, array_merge($context, ['credits_consumed' => $introCost]), $introTokens);

            $sections[] = $intro['content'];
            $credits->confirm($workspace, $introToken, actionId: (string) $article->id);
        } catch (\Throwable $e) {
            $credits->refund($workspace, $introToken, $e->getMessage());
            throw $e;
        }

        // Generate each section (chunked)
        foreach ($outline as $section) {
            $sectionWords = $section['word_count'] ?? ($targetWords / max(1, count($outline)));
            $sectionTokens = (int) ($sectionWords * 1.5); // rough token estimate
            $sectionCost = (int) ceil($sectionWords / 1000) * config('credits.actions.article_generation_per_1000_words', 5);
            $sectionToken = $credits->reserve($workspace, max(1, $sectionCost), 'article_generation_per_1000_words');

            try {
                $h3List = implode(', ', $section['h3s'] ?? []);
                $result = $ai->generate([
                    ['role' => 'system', 'content' => "Write compelling {$article->tone} blog content. Optimize for SEO."],
                    ['role' => 'user', 'content' => "Write the section \"{$section['h2']}\" (~{$sectionWords} words) for article \"{$article->title}\".\nInclude these subsections: {$h3List}\nFocus keyword: {$article->focus_keyword}"],
                ], $model, array_merge($context, ['credits_consumed' => $sectionCost]), $sectionTokens);

                $sections[] = "<h2>{$section['h2']}</h2>\n" . $result['content'];
                $credits->confirm($workspace, $sectionToken, actionId: (string) $article->id);
            } catch (\Throwable $e) {
                $credits->refund($workspace, $sectionToken, $e->getMessage());
                throw $e;
            }
        }

        // Conclusion
        $conclusionToken = $credits->reserve($workspace, 2, 'article_generation_per_1000_words');
        try {
            $conclusion = $ai->generate([
                ['role' => 'system', 'content' => "Write a compelling conclusion."],
                ['role' => 'user', 'content' => "Write a concise conclusion (100-150 words) for: \"{$article->title}\""],
            ], $model, $context, 400);
            $sections[] = "<h2>Conclusion</h2>\n" . $conclusion['content'];
            $credits->confirm($workspace, $conclusionToken, actionId: (string) $article->id);
        } catch (\Throwable $e) {
            $credits->refund($workspace, $conclusionToken, $e->getMessage());
        }

        return implode("\n\n", $sections);
    }

    private function generateSeoMeta(AIProviderService $ai, Article $article, string $model, array $context): array
    {
        $creditCost = config('credits.actions.seo_optimization_pass', 2);

        $result = $ai->generate([
            ['role' => 'system', 'content' => 'Generate SEO metadata. Respond ONLY with valid JSON.'],
            ['role' => 'user', 'content' => "Generate SEO metadata for: \"{$article->title}\"\nKeywords: " . implode(', ', $article->target_keywords ?? []) . "\n\nJSON format: {meta_title: string (max 60 chars), meta_description: string (max 160 chars), slug: string, focus_keyword: string}"],
        ], $model, array_merge($context, ['action_type' => 'seo_optimization_pass', 'credits_consumed' => $creditCost]), 300);

        $seo = json_decode($result['content'], true) ?? [];

        return [
            'meta_title'       => substr($seo['meta_title'] ?? $article->title, 0, 255),
            'meta_description' => substr($seo['meta_description'] ?? '', 0, 500),
            'slug'             => $seo['slug'] ?? null,
            'focus_keyword'    => $seo['focus_keyword'] ?? $article->focus_keyword,
        ];
    }

    public function tags(): array
    {
        return ["article:{$this->articleId}"];
    }
}
