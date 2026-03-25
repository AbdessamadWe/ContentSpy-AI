<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentSuggestion;
use App\Models\Article;
use App\Jobs\Content\GenerateSuggestionJob;
use App\Services\Content\ContentPipelineOrchestrator;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ContentSuggestionController extends Controller
{
    /**
     * GET /api/suggestions
     * List content suggestions
     */
    public function index(Request $request): JsonResponse
    {
        $query = ContentSuggestion::where('workspace_id', $request->user()->currentWorkspace->id)
            ->with(['site:id,name', 'article:id,title']);

        if ($request->has('site_id')) {
            $query->where('site_id', $request->site_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('min_score')) {
            $query->where('opportunity_score', '>=', $request->min_score);
        }

        $suggestions = $query->orderBy('opportunity_score', 'desc')->paginate(20);

        return response()->json([
            'suggestions' => $suggestions->map(fn($s) => [
                'id' => $s->id,
                'site_id' => $s->site_id,
                'site_name' => $s->site->name,
                'suggested_title' => $s->suggested_title,
                'content_angle' => $s->content_angle,
                'target_keywords' => $s->target_keywords,
                'recommended_word_count' => $s->recommended_word_count,
                'tone' => $s->tone,
                'opportunity_score' => $s->opportunity_score,
                'keyword_difficulty' => $s->keyword_difficulty,
                'estimated_traffic' => $s->estimated_traffic,
                'status' => $s->status,
                'scheduled_for' => $s->scheduled_for,
                'article_id' => $s->article_id,
                'article_title' => $s->article?->title,
                'expires_at' => $s->expires_at,
                'created_at' => $s->created_at,
            ]),
            'pagination' => [
                'current_page' => $suggestions->currentPage(),
                'last_page' => $suggestions->lastPage(),
                'per_page' => $suggestions->perPage(),
                'total' => $suggestions->total(),
            ],
        ]);
    }

    /**
     * GET /api/suggestions/{id}
     * Get suggestion details
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $suggestion = ContentSuggestion::where('workspace_id', $request->user()->currentWorkspace->id)
            ->with(['site', 'detection', 'article'])
            ->findOrFail($id);

        return response()->json([
            'suggestion' => [
                'id' => $suggestion->id,
                'site_id' => $suggestion->site_id,
                'site_name' => $suggestion->site->name,
                'detection_id' => $suggestion->detection_id,
                'suggested_title' => $suggestion->suggested_title,
                'content_angle' => $suggestion->content_angle,
                'target_keywords' => $suggestion->target_keywords,
                'recommended_word_count' => $suggestion->recommended_word_count,
                'tone' => $suggestion->tone,
                'h2_structure' => $suggestion->h2_structure,
                'opportunity_score' => $suggestion->opportunity_score,
                'keyword_difficulty' => $suggestion->keyword_difficulty,
                'estimated_traffic' => $suggestion->estimated_traffic,
                'status' => $suggestion->status,
                'scheduled_for' => $suggestion->scheduled_for,
                'rejected_reason' => $suggestion->rejected_reason,
                'article_id' => $suggestion->article_id,
                'article' => $suggestion->article ? [
                    'id' => $suggestion->article->id,
                    'title' => $suggestion->article->title,
                    'generation_status' => $suggestion->article->generation_status,
                    'word_count' => $suggestion->article->word_count,
                ] : null,
                'expires_at' => $suggestion->expires_at,
                'created_at' => $suggestion->created_at,
            ],
        ]);
    }

    /**
     * POST /api/suggestions/{id}/accept
     * Accept suggestion and start article generation
     */
    public function accept(Request $request, int $id, ContentPipelineOrchestrator $orchestrator): JsonResponse
    {
        $suggestion = ContentSuggestion::where('workspace_id', $request->user()->currentWorkspace->id)
            ->findOrFail($id);

        if ($suggestion->status !== 'pending') {
            return response()->json(['error' => 'Suggestion not pending'], 400);
        }

        $validated = $request->validate([
            'ai_model' => 'sometimes|string',
            'schedule_for' => 'sometimes|date',
        ]);

        // Update suggestion status
        $suggestion->update([
            'status' => 'accepted',
            'scheduled_for' => $validated['schedule_for'] ?? null,
        ]);

        // Start content pipeline
        $article = $orchestrator->start($suggestion, [
            'ai_model' => $validated['ai_model'] ?? $suggestion->site->ai_model_text,
        ]);

        // Link article to suggestion
        $suggestion->update(['article_id' => $article->id]);

        return response()->json([
            'message' => 'Article generation started',
            'suggestion_id' => $suggestion->id,
            'article_id' => $article->id,
            'generation_status' => $article->generation_status,
        ]);
    }

    /**
     * POST /api/suggestions/{id}/reject
     * Reject suggestion
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $suggestion = ContentSuggestion::where('workspace_id', $request->user()->currentWorkspace->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $suggestion->update([
            'status' => 'rejected',
            'rejected_reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'Suggestion rejected',
            'suggestion_id' => $suggestion->id,
        ]);
    }

    /**
     * POST /api/suggestions/{id}/schedule
     * Schedule suggestion for later
     */
    public function schedule(Request $request, int $id): JsonResponse
    {
        $suggestion = ContentSuggestion::where('workspace_id', $request->user()->currentWorkspace->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'scheduled_for' => 'required|date|after:now',
        ]);

        $suggestion->update([
            'status' => 'scheduled',
            'scheduled_for' => $validated['scheduled_for'],
        ]);

        return response()->json([
            'message' => 'Suggestion scheduled',
            'suggestion_id' => $suggestion->id,
            'scheduled_for' => $suggestion->scheduled_for,
        ]);
    }

    /**
     * POST /api/suggestions/bulk-action
     * Bulk accept/reject/schedule
     */
    public function bulkAction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'suggestion_ids' => 'required|array|min:1',
            'action' => 'required|in:accept,reject,schedule',
            'reason' => 'required_if:action,reject|string',
            'scheduled_for' => 'required_if:action,schedule|date|after:now',
            'ai_model' => 'sometimes|string',
        ]);

        $suggestions = ContentSuggestion::where('workspace_id', $request->user()->currentWorkspace->id)
            ->whereIn('id', $validated['suggestion_ids'])
            ->where('status', 'pending')
            ->get();

        $results = [];
        $orchestrator = app(ContentPipelineOrchestrator::class);

        foreach ($suggestions as $suggestion) {
            switch ($validated['action']) {
                case 'accept':
                    $suggestion->update(['status' => 'accepted']);
                    $article = $orchestrator->start($suggestion, [
                        'ai_model' => $validated['ai_model'] ?? $suggestion->site->ai_model_text,
                    ]);
                    $suggestion->update(['article_id' => $article->id]);
                    $results[] = ['id' => $suggestion->id, 'status' => 'accepted', 'article_id' => $article->id];
                    break;

                case 'reject':
                    $suggestion->update([
                        'status' => 'rejected',
                        'rejected_reason' => $validated['reason'],
                    ]);
                    $results[] = ['id' => $suggestion->id, 'status' => 'rejected'];
                    break;

                case 'schedule':
                    $suggestion->update([
                        'status' => 'scheduled',
                        'scheduled_for' => $validated['scheduled_for'],
                    ]);
                    $results[] = ['id' => $suggestion->id, 'status' => 'scheduled'];
                    break;
            }
        }

        return response()->json([
            'message' => 'Bulk action completed',
            'results' => $results,
            'processed' => count($results),
        ]);
    }
}