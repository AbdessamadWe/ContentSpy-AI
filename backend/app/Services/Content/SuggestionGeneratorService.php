<?php
namespace App\Services\Content;

use App\Models\ContentSuggestion;
use App\Models\SpyDetection;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Facades\Log;

class SuggestionGeneratorService
{
    public function __construct(private readonly AiGateway $ai) {}

    /**
     * Turn a SpyDetection into a ContentSuggestion.
     * Returns null if a duplicate suggestion exists within 7 days.
     */
    public function fromDetection(SpyDetection $detection): ?ContentSuggestion
    {
        // Dedup: same source URL or title within 7 days for same site
        $exists = ContentSuggestion::where('site_id', $detection->site_id)
            ->where('workspace_id', $detection->workspace_id)
            ->where('created_at', '>=', now()->subDays(7))
            ->where(fn($q) => $q->where('source_url', $detection->source_url)
                               ->orWhere('title', $detection->title))
            ->exists();

        if ($exists) {
            return null;
        }

        $prompt = <<<PROMPT
Given this competitor article:
Title: {$detection->title}
Excerpt: {$detection->excerpt}
Source: {$detection->source_url}

Suggest a better competing content angle for our blog. Return ONLY this JSON (no markdown):
{"suggested_title":"...","content_angle":"...","target_keywords":["kw1","kw2","kw3"],"recommended_word_count":1500,"tone":"informative"}
PROMPT;

        try {
            $response = $this->ai->generate(
                messages: [
                    ['role' => 'system', 'content' => 'You are an expert content strategist. Return ONLY valid JSON.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
                model:   config('contentspy.default_model', 'gpt-4o-mini'),
                context: [
                    'workspace_id'    => $detection->workspace_id,
                    'action_type'     => 'suggestion_generation',
                    'credits_consumed' => 2,
                ],
                maxTokens: 500,
            );

            $data = json_decode($response->text, true);
        } catch (\Throwable $e) {
            Log::error("[SuggestionGeneratorService] AI call failed: {$e->getMessage()}");
            $data = null;
        }

        return ContentSuggestion::create([
            'workspace_id'          => $detection->workspace_id,
            'site_id'               => $detection->site_id,
            'spy_detection_id'      => $detection->id,
            'competitor_id'         => $detection->competitor_id,
            'suggested_title'       => $data['suggested_title']       ?? $detection->title,
            'content_angle'         => $data['content_angle']         ?? '',
            'target_keywords'       => $data['target_keywords']       ?? [],
            'recommended_word_count' => $data['recommended_word_count'] ?? 1500,
            'tone'                  => $data['tone']                  ?? 'informative',
            'opportunity_score'     => $detection->opportunity_score,
            'source_url'            => $detection->source_url,
            'title'                 => $detection->title,
            'status'                => 'pending',
        ]);
    }
}
