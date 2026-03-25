<?php
namespace App\Http\Controllers;

use App\Jobs\Content\GenerateArticleJob;
use App\Models\Article;
use App\Models\ContentSuggestion;
use App\Models\Workspace;
use App\Services\Credits\CreditService;
use App\Services\Credits\InsufficientCreditsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    public function __construct(private CreditService $credits) {}

    public function index(Request $request, int $workspace): JsonResponse
    {
        $articles = Article::where('workspace_id', $workspace)
            ->when($request->get('site_id'), fn($q, $s) => $q->where('site_id', $s))
            ->when($request->get('status'), fn($q, $s) => $q->where('generation_status', $s))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($articles);
    }

    public function store(Request $request, int $workspace): JsonResponse
    {
        $validated = $request->validate([
            'site_id'        => ['required', 'integer', 'exists:sites,id'],
            'suggestion_id'  => ['nullable', 'integer', 'exists:content_suggestions,id'],
            'title'          => ['required', 'string', 'max:500'],
            'target_keywords' => ['nullable', 'array'],
            'tone'           => ['nullable', 'string'],
            'ai_model_text'  => ['nullable', 'string'],
            'ai_model_image' => ['nullable', 'string'],
        ]);

        $article = Article::create(array_merge($validated, [
            'workspace_id'    => $workspace,
            'generation_status' => 'pending',
        ]));

        if (!empty($validated['suggestion_id'])) {
            $suggestion = ContentSuggestion::find($validated['suggestion_id']);
            if ($suggestion) {
                $article->update([
                    'target_keywords'  => $suggestion->target_keywords,
                    'tone'             => $suggestion->tone,
                    'focus_keyword'    => $suggestion->target_keywords[0] ?? null,
                ]);
                $suggestion->update(['status' => 'generating', 'article_id' => $article->id]);
            }
        }

        return response()->json(['article' => $article], 201);
    }

    public function show(int $workspace, Article $article): JsonResponse
    {
        if ($article->workspace_id !== $workspace) abort(403);
        return response()->json(['article' => $article->load('tokenUsageLogs', 'socialPosts')]);
    }

    public function update(Request $request, int $workspace, Article $article): JsonResponse
    {
        if ($article->workspace_id !== $workspace) abort(403);

        $validated = $request->validate([
            'title'       => ['sometimes', 'string', 'max:500'],
            'content'     => ['sometimes', 'string'],
            'meta_title'  => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'focus_keyword' => ['nullable', 'string'],
            'scheduled_for' => ['nullable', 'date'],
        ]);

        $article->update($validated);
        return response()->json(['article' => $article->fresh()]);
    }

    public function destroy(int $workspace, Article $article): JsonResponse
    {
        if ($article->workspace_id !== $workspace) abort(403);
        $article->delete();
        return response()->json(['message' => 'Article deleted.']);
    }

    /** Queue the AI generation pipeline */
    public function generate(Request $request, int $workspace, Article $article): JsonResponse
    {
        if ($article->workspace_id !== $workspace) abort(403);

        if (!in_array($article->generation_status, ['pending', 'failed'])) {
            return response()->json(['message' => 'Article is already being generated or is complete.'], 422);
        }

        $ws = Workspace::find($workspace);

        // Rough credit estimate: outline(3) + content/1000words(5) + seo(2) + image(3) + dup(1)
        $estimatedWords = $article->suggestion?->recommended_word_count ?? 1500;
        $estimatedCost = 3 + (ceil($estimatedWords / 1000) * 5) + 2 + 3 + 1;

        if (!$this->credits->hasEnough($ws, (int) $estimatedCost)) {
            return response()->json([
                'message' => 'Insufficient credits to generate this article.',
                'required' => $estimatedCost,
                'available' => $ws->available_credits,
            ], 402);
        }

        GenerateArticleJob::dispatch($article->id);

        return response()->json(['message' => 'Article generation queued.', 'article_id' => $article->id]);
    }

    /** Approve article for publishing (human-in-the-loop) */
    public function approve(Request $request, int $workspace, Article $article): JsonResponse
    {
        if ($article->workspace_id !== $workspace) abort(403);

        if ($article->generation_status !== 'review') {
            return response()->json(['message' => 'Article is not in review state.'], 422);
        }

        $article->update(['review_status' => 'approved', 'generation_status' => 'ready']);

        return response()->json(['message' => 'Article approved.', 'article' => $article->fresh()]);
    }

    /** Publish to WordPress */
    public function publish(Request $request, int $workspace, Article $article): JsonResponse
    {
        if ($article->workspace_id !== $workspace) abort(403);

        if ($article->generation_status !== 'ready') {
            return response()->json(['message' => 'Article is not ready to publish.'], 422);
        }

        if (!$article->duplicate_check_passed) {
            return response()->json(['message' => 'Article has not passed duplicate check.'], 422);
        }

        $ws = Workspace::find($workspace);
        $publishCost = config('credits.actions.wordpress_publish', 1);

        try {
            $this->credits->deduct($ws, $publishCost, 'wordpress_publish', $request->user()?->id, (string) $article->id);
        } catch (InsufficientCreditsException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        }

        // Dispatch publish job
        \App\Jobs\Publishing\PublishToWordPressJob::dispatch($article->id);

        return response()->json(['message' => 'Publishing queued.']);
    }
}
