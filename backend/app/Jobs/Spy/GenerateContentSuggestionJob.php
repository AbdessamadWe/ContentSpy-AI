<?php
namespace App\Jobs\Spy;

use App\Models\ContentSuggestion;
use App\Models\SpyDetection;
use App\Models\Workspace;
use App\Services\AI\AIProviderService;
use App\Services\Credits\CreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateContentSuggestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 3;

    public function __construct(public readonly int $detectionId) {}

    public function handle(AIProviderService $ai, CreditService $credits): void
    {
        $detection = SpyDetection::with('competitor.site')->find($this->detectionId);
        if (!$detection || $detection->status !== 'new') return;

        $workspace = Workspace::find($detection->workspace_id);
        $site = $detection->competitor->site;
        $creditCost = config('credits.actions.content_suggestion_card', 2);
        $token = $credits->reserve($workspace, $creditCost, 'content_suggestion_card');

        try {
            $model = $site->ai_model_text ?? config('ai-models.fallback_chains.text.0', 'gpt-4o');

            $messages = [
                ['role' => 'system', 'content' => 'You are an expert content strategist. Generate a JSON content brief.'],
                ['role' => 'user', 'content' => "A competitor published: Title: \"{$detection->title}\"\nURL: {$detection->source_url}\n\nGenerate a content brief in JSON with keys: suggested_title, content_angle, target_keywords (array of 5), recommended_word_count, tone, h2_structure (array of H2 headings). Respond ONLY with valid JSON."],
            ];

            $result = $ai->generate($messages, $model, [
                'workspace_id' => $workspace->id,
                'site_id'      => $site->id,
                'action_type'  => 'content_suggestion_card',
                'credits_consumed' => $creditCost,
            ], 1000);

            $brief = json_decode($result['content'], true);
            if (!$brief) throw new \RuntimeException("Failed to parse AI response as JSON.");

            $suggestion = ContentSuggestion::create([
                'workspace_id'          => $workspace->id,
                'site_id'               => $site->id,
                'detection_id'          => $detection->id,
                'suggested_title'       => $brief['suggested_title'] ?? $detection->title,
                'content_angle'         => $brief['content_angle'] ?? null,
                'target_keywords'       => $brief['target_keywords'] ?? null,
                'recommended_word_count' => $brief['recommended_word_count'] ?? 1500,
                'tone'                  => $brief['tone'] ?? 'informative',
                'h2_structure'          => $brief['h2_structure'] ?? null,
                'opportunity_score'     => $detection->opportunity_score,
                'status'                => 'pending',
            ]);

            $detection->update(['status' => 'suggested', 'suggestion_id' => $suggestion->id]);
            $credits->confirm($workspace, $token, actionId: (string) $suggestion->id);

            Log::info("[ContentSuggestion] Created suggestion #{$suggestion->id} from detection #{$detection->id}");
        } catch (\Throwable $e) {
            $credits->refund($workspace, $token, $e->getMessage());
            Log::error("[ContentSuggestion] Failed detection #{$this->detectionId}: " . $e->getMessage());
            throw $e;
        }
    }

    public function tags(): array
    {
        return ["detection:{$this->detectionId}"];
    }
}
