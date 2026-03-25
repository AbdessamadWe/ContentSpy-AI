<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competitor;
use App\Models\Site;
use App\Jobs\Spy\RunCompetitorSpyJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CompetitorController extends Controller
{
    /**
     * GET /api/competitors
     * List all competitors for current workspace
     */
    public function index(Request $request): JsonResponse
    {
        $query = Competitor::where('workspace_id', $request->user()->currentWorkspace->id)
            ->with(['site:id,name,url'])
            ->withCount('detections');

        if ($request->has('site_id')) {
            $query->where('site_id', $request->site_id);
        }

        if ($request->has('auto_spy')) {
            $query->where('auto_spy', $request->boolean('auto_spy'));
        }

        $competitors = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json([
            'competitors' => $competitors->map(fn($c) => [
                'id' => $c->id,
                'site_id' => $c->site_id,
                'site_name' => $c->site->name,
                'name' => $c->name,
                'domain' => $c->domain,
                'rss_url' => $c->rss_url,
                'sitemap_url' => $c->sitemap_url,
                'blog_url' => $c->blog_url,
                'twitter_handle' => $c->twitter_handle,
                'active_methods' => $c->active_methods,
                'auto_spy' => $c->auto_spy,
                'auto_spy_interval' => $c->auto_spy_interval,
                'confidence_threshold_suggest' => $c->confidence_threshold_suggest,
                'confidence_threshold_generate' => $c->confidence_threshold_generate,
                'confidence_threshold_publish' => $c->confidence_threshold_publish,
                'last_scanned_at' => $c->last_scanned_at,
                'total_articles_detected' => $c->total_articles_detected,
                'detections_count' => $c->detections_count,
                'is_active' => $c->is_active,
            ]),
            'pagination' => [
                'current_page' => $competitors->currentPage(),
                'last_page' => $competitors->lastPage(),
                'per_page' => $competitors->perPage(),
                'total' => $competitors->total(),
            ],
        ]);
    }

    /**
     * POST /api/competitors
     * Create new competitor
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => 'required|integer|exists:sites,id',
            'name' => 'required|string|max:255',
            'domain' => 'required|url|max:500',
            'rss_url' => 'nullable|url|max:500',
            'sitemap_url' => 'nullable|url|max:500',
            'blog_url' => 'nullable|url|max:500',
            'twitter_handle' => 'nullable|string|max:100',
            'instagram_handle' => 'nullable|string|max:100',
            'semrush_domain' => 'nullable|string|max:500',
            'active_methods' => 'sometimes|array',
            'auto_spy' => 'sometimes|boolean',
            'auto_spy_interval' => 'sometimes|integer|min:15',
            'confidence_threshold_suggest' => 'sometimes|integer|min:0|max:100',
            'confidence_threshold_generate' => 'sometimes|integer|min:0|max:100',
            'confidence_threshold_publish' => 'sometimes|integer|min:0|max:100',
        ]);

        $site = $request->user()->currentWorkspace->sites()->findOrFail($validated['site_id']);
        
        // Check plan limits
        $competitorCount = $site->competitors()->count();
        $planLimit = match($request->user()->currentWorkspace->plan) {
            'starter' => 5,
            'pro' => 50,
            'agency' => PHP_INT_MAX,
            default => 5,
        };

        if ($competitorCount >= $planLimit) {
            return response()->json([
                'error' => 'Competitor limit reached for your plan',
                'upgrade_url' => '/billing/upgrade',
            ], 402);
        }

        $competitor = $site->competitors()->create([
            ...$validated,
            'workspace_id' => $request->user()->currentWorkspace->id,
            'active_methods' => $validated['active_methods'] ?? ['rss'],
            'auto_spy' => $validated['auto_spy'] ?? false,
            'auto_spy_interval' => $validated['auto_spy_interval'] ?? 60,
            'confidence_threshold_suggest' => $validated['confidence_threshold_suggest'] ?? 50,
            'confidence_threshold_generate' => $validated['confidence_threshold_generate'] ?? 70,
            'confidence_threshold_publish' => $validated['confidence_threshold_publish'] ?? 85,
        ]);

        return response()->json([
            'competitor' => [
                'id' => $competitor->id,
                'name' => $competitor->name,
                'domain' => $competitor->domain,
            ],
        ], 201);
    }

    /**
     * GET /api/competitors/{id}
     * Get competitor details
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $competitor = Competitor::where('workspace_id', $request->user()->currentWorkspace->id)
            ->with(['site', 'detections' => fn($q) => $q->latest()->limit(10)])
            ->findOrFail($id);

        return response()->json([
            'competitor' => [
                'id' => $competitor->id,
                'site_id' => $competitor->site_id,
                'site_name' => $competitor->site->name,
                'name' => $competitor->name,
                'domain' => $competitor->domain,
                'rss_url' => $competitor->rss_url,
                'sitemap_url' => $competitor->sitemap_url,
                'blog_url' => $competitor->blog_url,
                'twitter_handle' => $competitor->twitter_handle,
                'instagram_handle' => $competitor->instagram_handle,
                'semrush_domain' => $competitor->semrush_domain,
                'active_methods' => $competitor->active_methods,
                'auto_spy' => $competitor->auto_spy,
                'auto_spy_interval' => $competitor->auto_spy_interval,
                'confidence_threshold_suggest' => $competitor->confidence_threshold_suggest,
                'confidence_threshold_generate' => $competitor->confidence_threshold_generate,
                'confidence_threshold_publish' => $competitor->confidence_threshold_publish,
                'last_scanned_at' => $competitor->last_scanned_at,
                'total_articles_detected' => $competitor->total_articles_detected,
                'is_active' => $competitor->is_active,
                'recent_detections' => $competitor->detections->map(fn($d) => [
                    'id' => $d->id,
                    'title' => $d->title,
                    'method' => $d->method,
                    'opportunity_score' => $d->opportunity_score,
                    'created_at' => $d->created_at,
                ]),
            ],
        ]);
    }

    /**
     * PATCH /api/competitors/{id}
     * Update competitor
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $competitor = Competitor::where('workspace_id', $request->user()->currentWorkspace->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'domain' => 'sometimes|url|max:500',
            'rss_url' => 'nullable|url|max:500',
            'sitemap_url' => 'nullable|url|max:500',
            'blog_url' => 'nullable|url|max:500',
            'twitter_handle' => 'nullable|string|max:100',
            'instagram_handle' => 'nullable|string|max:100',
            'semrush_domain' => 'nullable|string|max:500',
            'active_methods' => 'sometimes|array',
            'auto_spy' => 'sometimes|boolean',
            'auto_spy_interval' => 'sometimes|integer|min:15',
            'confidence_threshold_suggest' => 'sometimes|integer|min:0|max:100',
            'confidence_threshold_generate' => 'sometimes|integer|min:0|max:100',
            'confidence_threshold_publish' => 'sometimes|integer|min:0|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        $competitor->update($validated);

        return response()->json([
            'competitor' => [
                'id' => $competitor->id,
                'updated_at' => $competitor->updated_at,
            ],
        ]);
    }

    /**
     * DELETE /api/competitors/{id}
     * Delete competitor
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $competitor = Competitor::where('workspace_id', $request->user()->currentWorkspace->id)
            ->findOrFail($id);

        $competitor->update(['is_active' => false]);

        return response()->json(['message' => 'Competitor deleted']);
    }

    /**
     * POST /api/competitors/{id}/scan
     * Trigger manual spy scan
     */
    public function scan(Request $request, int $id): JsonResponse
    {
        $competitor = Competitor::where('workspace_id', $request->user()->currentWorkspace->id)
            ->with('site')
            ->findOrFail($id);

        // Dispatch async job
        RunCompetitorSpyJob::dispatch($competitor);

        return response()->json([
            'message' => 'Spy scan initiated',
            'competitor_id' => $competitor->id,
            'methods' => $competitor->active_methods,
        ]);
    }

    /**
     * GET /api/competitors/{id}/detections
     * Get competitor detections
     */
    public function detections(Request $request, int $id): JsonResponse
    {
        $competitor = Competitor::where('workspace_id', $request->user()->currentWorkspace->id)
            ->findOrFail($id);

        $query = $competitor->detections();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('method')) {
            $query->where('method', $request->method);
        }

        if ($request->has('min_score')) {
            $query->where('opportunity_score', '>=', $request->min_score);
        }

        $detections = $query->latest()->paginate(20);

        return response()->json([
            'detections' => $detections->map(fn($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'excerpt' => $d->excerpt,
                'method' => $d->method,
                'source_url' => $d->source_url,
                'opportunity_score' => $d->opportunity_score,
                'keyword_difficulty' => $d->keyword_difficulty,
                'status' => $d->status,
                'published_at' => $d->published_at,
                'created_at' => $d->created_at,
            ]),
            'pagination' => [
                'current_page' => $detections->currentPage(),
                'last_page' => $detections->lastPage(),
                'per_page' => $detections->perPage(),
                'total' => $detections->total(),
            ],
        ]);
    }
}