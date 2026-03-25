<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\Publishing\WordPressPublisher;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ArticleController extends Controller
{
    /**
     * GET /api/articles
     * List articles
     */
    public function index(Request $request): JsonResponse
    {
        $query = Article::where('workspace_id', $request->user()->currentWorkspace->id)
            ->with(['site:id,name', 'suggestion:id,suggested_title']);

        if ($request->has('site_id')) {
            $query->where('site_id', $request->site_id);
        }

        if ($request->has('generation_status')) {
            $query->where('generation_status', $request->generation_status);
        }

        if ($request->has('publish_status')) {
            $query->where('publish_status', $request->publish_status);
        }

        $articles = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json([
            'articles' => $articles->map(fn($a) => [
                'id' => $a->id,
                'site_id' => $a->site_id,
                'site_name' => $a->site->name,
                'title' => $a->title,
                'slug' => $a->slug,
                'word_count' => $a->word_count,
                'generation_status' => $a->generation_status,
                'review_status' => $a->review_status,
                'publish_status' => $a->publish_status,
                'wp_post_id' => $a->wp_post_id,
                'wp_post_url' => $a->wp_post_url,
                'scheduled_for' => $a->scheduled_for,
                'total_cost_usd' => $a->total_cost_usd,
                'total_credits_consumed' => $a->total_credits_consumed,
                'created_at' => $a->created_at,
            ]),
            'pagination' => [
                'current_page' => $articles->currentPage(),
                'last_page' => $articles->lastPage(),
                'per_page' => $articles->perPage(),
                'total' => $articles->total(),
            ],
        ]);
    }

    /**
     * GET /api/articles/{id}
     * Get article details
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $article = Article::where('workspace_id', $request->user()->currentWorkspace->id)
            ->with(['site', 'suggestion', 'tokenUsageLogs'])
            ->findOrFail($id);

        return response()->json([
            'article' => [
                'id' => $article->id,
                'site_id' => $article->site_id,
                'site_name' => $article->site->name,
                'suggestion_id' => $article->suggestion_id,
                'title' => $article->title,
                'slug' => $article->slug,
                'content' => $article->content,
                'excerpt' => $article->excerpt,
                'meta_title' => $article->meta_title,
                'meta_description' => $article->meta_description,
                'focus_keyword' => $article->focus_keyword,
                'target_keywords' => $article->target_keywords,
                'word_count' => $article->word_count,
                'featured_image_url' => $article->featured_image_url,
                'outline' => $article->outline,
                'tone' => $article->tone,
                'ai_model_text' => $article->ai_model_text,
                'ai_model_image' => $article->ai_model_image,
                'total_tokens_used' => $article->total_tokens_used,
                'total_cost_usd' => $article->total_cost_usd,
                'total_credits_consumed' => $article->total_credits_consumed,
                'generation_status' => $article->generation_status,
                'review_status' => $article->review_status,
                'duplicate_check_passed' => $article->duplicate_check_passed,
                'duplicate_score' => $article->duplicate_score,
                'wp_post_id' => $article->wp_post_id,
                'wp_published_at' => $article->wp_published_at,
                'wp_post_url' => $article->wp_post_url,
                'publish_status' => $article->publish_status,
                'scheduled_for' => $article->scheduled_for,
                'created_at' => $article->created_at,
                'token_usage' => $article->tokenUsageLogs->groupBy('model')->map(fn($logs) => [
                    'model' => $logs->first()->model,
                    'total_tokens' => $logs->sum('total_tokens'),
                    'cost_usd' => $logs->sum('cost_usd'),
                ])->values(),
            ],
        ]);
    }

    /**
     * PATCH /api/articles/{id}
     * Update article
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $article = Article::where('workspace_id', $request->user()->currentWorkspace->id)
            ->findOrFail($id);

        // Only allow updates in certain statuses
        if (!in_array($article->generation_status, ['ready', 'failed'])) {
            return response()->json(['error' => 'Article cannot be edited in current status'], 400);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'excerpt' => 'sometimes|string|max:500',
            'meta_title' => 'sometimes|string|max:255',
            'meta_description' => 'sometimes|string|max:500',
            'focus_keyword' => 'sometimes|string|max:255',
            'target_keywords' => 'sometimes|array',
            'featured_image_url' => 'sometimes|url',
        ]);

        $article->update($validated);

        return response()->json([
            'article' => [
                'id' => $article->id,
                'updated_at' => $article->updated_at,
            ],
        ]);
    }

    /**
     * POST /api/articles/{id}/approve
     * Approve article for publishing
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $article = Article::where('workspace_id', $request->user()->currentWorkspace->id)
            ->findOrFail($id);

        if ($article->review_status !== 'pending') {
            return response()->json(['error' => 'Article not pending review'], 400);
        }

        $validated = $request->validate([
            'schedule_for' => 'sometimes|date|after:now',
        ]);

        $article->update([
            'review_status' => 'approved',
            'scheduled_for' => $validated['schedule_for'] ?? null,
            'publish_status' => $validated['schedule_for'] ? 'scheduled' : 'draft',
        ]);

        return response()->json([
            'message' => 'Article approved',
            'article_id' => $article->id,
            'publish_status' => $article->publish_status,
        ]);
    }

    /**
     * POST /api/articles/{id}/reject
     * Reject article
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $article = Article::where('workspace_id', $request->user()->currentWorkspace->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $article->update([
            'review_status' => 'rejected',
        ]);

        // Store rejection reason in metadata or separate field
        // For now, just mark as rejected

        return response()->json([
            'message' => 'Article rejected',
            'article_id' => $article->id,
        ]);
    }

    /**
     * POST /api/articles/{id}/publish
     * Publish article to WordPress
     */
    public function publish(Request $request, int $id, WordPressPublisher $publisher): JsonResponse
    {
        $article = Article::where('workspace_id', $request->user()->currentWorkspace->id)
            ->with('site')
            ->findOrFail($id);

        if ($article->generation_status !== 'ready') {
            return response()->json(['error' => 'Article not ready for publishing'], 400);
        }

        if ($article->wp_post_id) {
            return response()->json(['error' => 'Article already published'], 400);
        }

        $result = $publisher->publish($article, $article->site);

        if ($result['success']) {
            $article->update([
                'wp_post_id' => $result['post_id'],
                'wp_post_url' => $result['post_url'],
                'wp_published_at' => now(),
                'publish_status' => 'published',
            ]);

            return response()->json([
                'message' => 'Article published',
                'article_id' => $article->id,
                'wp_post_id' => $result['post_id'],
                'wp_post_url' => $result['post_url'],
            ]);
        }

        return response()->json([
            'error' => 'Failed to publish',
            'message' => $result['error'] ?? 'Unknown error',
        ], 500);
    }

    /**
     * GET /api/articles/{id}/token-usage
     * Get token usage breakdown
     */
    public function tokenUsage(Request $request, int $id): JsonResponse
    {
        $article = Article::where('workspace_id', $request->user()->currentWorkspace->id)
            ->findOrFail($id);

        $usage = $article->tokenUsageLogs()
            ->selectRaw('model, provider, SUM(prompt_tokens) as prompt_tokens, SUM(completion_tokens) as completion_tokens, SUM(total_tokens) as total_tokens, SUM(cost_usd) as cost_usd, SUM(credits_consumed) as credits_consumed')
            ->groupBy('model', 'provider')
            ->get();

        return response()->json([
            'article_id' => $article->id,
            'total_tokens' => $article->total_tokens_used,
            'total_cost_usd' => $article->total_cost_usd,
            'total_credits_consumed' => $article->total_credits_consumed,
            'breakdown' => $usage->map(fn($u) => [
                'model' => $u->model,
                'provider' => $u->provider,
                'prompt_tokens' => $u->prompt_tokens,
                'completion_tokens' => $u->completion_tokens,
                'total_tokens' => $u->total_tokens,
                'cost_usd' => $u->cost_usd,
                'credits_consumed' => $u->credits_consumed,
            ]),
        ]);
    }

    /**
     * DELETE /api/articles/{id}
     * Delete article
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $article = Article::where('workspace_id', $request->user()->currentWorkspace->id)
            ->findOrFail($id);

        // Only allow deletion if not published
        if ($article->wp_post_id) {
            return response()->json(['error' => 'Cannot delete published article'], 400);
        }

        $article->delete();

        return response()->json(['message' => 'Article deleted']);
    }
}