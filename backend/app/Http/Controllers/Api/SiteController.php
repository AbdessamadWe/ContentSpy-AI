<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Publishing\WordPressPublisher;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SiteController extends Controller
{
    /**
     * GET /api/sites
     * List all sites for current workspace
     */
    public function index(Request $request): JsonResponse
    {
        $sites = $request->user()
            ->currentWorkspace
            ->sites()
            ->with(['competitors', 'socialAccounts'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'sites' => $sites->map(fn($site) => [
                'id' => $site->id,
                'name' => $site->name,
                'url' => $site->url,
                'niche' => $site->niche,
                'language' => $site->language,
                'connection_status' => $site->connection_status,
                'last_sync_at' => $site->last_sync_at,
                'last_post_at' => $site->last_post_at,
                'ai_model_text' => $site->ai_model_text,
                'workflow_template' => $site->workflow_template,
                'stats' => [
                    'competitors_count' => $site->competitors->count(),
                    'social_accounts_count' => $site->socialAccounts->count(),
                ],
            ]),
            'pagination' => [
                'current_page' => $sites->currentPage(),
                'last_page' => $sites->lastPage(),
                'per_page' => $sites->perPage(),
                'total' => $sites->total(),
            ],
        ]);
    }

    /**
     * POST /api/sites
     * Create new site
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:500',
            'niche' => 'sometimes|string|max:255',
            'language' => 'sometimes|string|max:10',
            'connection_type' => 'sometimes|in:plugin,rest_api',
            'wp_api_url' => 'required_if:connection_type,rest_api|url',
            'wp_username' => 'required_if:connection_type,rest_api|string',
            'wp_app_password' => 'required_if:connection_type,rest_api|string',
            'timezone' => 'sometimes|string|max:100',
            'max_posts_per_day' => 'sometimes|integer|min:1|max:100',
            'workflow_template' => 'sometimes|in:full_autopilot,human_in_the_loop,spy_only,generate_only,social_only',
            'ai_model_text' => 'sometimes|string',
            'ai_model_image' => 'sometimes|string',
        ]);

        $workspace = $request->user()->currentWorkspace;
        
        // Check plan limits
        $siteCount = $workspace->sites()->count();
        $planLimit = match($workspace->plan) {
            'starter' => 3,
            'pro' => 15,
            'agency' => PHP_INT_MAX,
            default => 1,
        };

        if ($siteCount >= $planLimit) {
            return response()->json([
                'error' => 'Site limit reached for your plan',
                'upgrade_url' => '/billing/upgrade',
            ], 402);
        }

        $site = $workspace->sites()->create($validated);

        return response()->json([
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'url' => $site->url,
                'connection_type' => $site->connection_type,
            ],
        ], 201);
    }

    /**
     * GET /api/sites/{id}
     * Get site details
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $site = $request->user()
            ->currentWorkspace
            ->sites()
            ->with(['competitors', 'socialAccounts', 'articles'])
            ->findOrFail($id);

        return response()->json([
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'url' => $site->url,
                'niche' => $site->niche,
                'language' => $site->language,
                'connection_type' => $site->connection_type,
                'connection_status' => $site->connection_status,
                'wp_version' => $site->wp_version,
                'php_version' => $site->php_version,
                'plugin_version' => $site->plugin_version,
                'last_sync_at' => $site->last_sync_at,
                'last_post_at' => $site->last_post_at,
                'timezone' => $site->timezone,
                'max_posts_per_day' => $site->max_posts_per_day,
                'workflow_template' => $site->workflow_template,
                'ai_model_text' => $site->ai_model_text,
                'ai_model_image' => $site->ai_model_image,
                'settings' => $site->settings,
                'stats' => [
                    'competitors_count' => $site->competitors->count(),
                    'social_accounts_count' => $site->socialAccounts->count(),
                    'articles_count' => $site->articles->count(),
                    'published_articles_count' => $site->articles->where('publish_status', 'published')->count(),
                ],
            ],
        ]);
    }

    /**
     * PATCH /api/sites/{id}
     * Update site
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $site = $request->user()
            ->currentWorkspace
            ->sites()
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'niche' => 'sometimes|string|max:255',
            'language' => 'sometimes|string|max:10',
            'timezone' => 'sometimes|string|max:100',
            'max_posts_per_day' => 'sometimes|integer|min:1|max:100',
            'workflow_template' => 'sometimes|in:full_autopilot,human_in_the_loop,spy_only,generate_only,social_only',
            'ai_model_text' => 'sometimes|string',
            'ai_model_image' => 'sometimes|string',
            'settings' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $site->update($validated);

        return response()->json([
            'site' => [
                'id' => $site->id,
                'updated_at' => $site->updated_at,
            ],
        ]);
    }

    /**
     * DELETE /api/sites/{id}
     * Delete site
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $site = $request->user()
            ->currentWorkspace
            ->sites()
            ->findOrFail($id);

        // Soft delete
        $site->update(['is_active' => false]);

        return response()->json(['message' => 'Site deleted']);
    }

    /**
     * POST /api/sites/{id}/verify-connection
     * Verify WordPress connection
     */
    public function verifyConnection(Request $request, int $id, WordPressPublisher $publisher): JsonResponse
    {
        $site = $request->user()
            ->currentWorkspace
            ->sites()
            ->findOrFail($id);

        $result = $publisher->verifyConnection($site);

        $site->update([
            'connection_status' => $result['connected'] ? 'connected' : 'error',
            'wp_version' => $result['wp_version'] ?? null,
            'php_version' => $result['php_version'] ?? null,
            'plugin_version' => $result['plugin_version'] ?? null,
            'last_sync_at' => now(),
        ]);

        return response()->json([
            'connected' => $result['connected'],
            'wp_version' => $result['wp_version'] ?? null,
            'php_version' => $result['php_version'] ?? null,
            'plugin_version' => $result['plugin_version'] ?? null,
            'available_categories' => $result['categories'] ?? [],
            'available_tags' => $result['tags'] ?? [],
            'available_authors' => $result['authors'] ?? [],
            'error' => $result['error'] ?? null,
        ]);
    }

    /**
     * GET /api/sites/{id}/competitors
     * Get site competitors
     */
    public function competitors(Request $request, int $id): JsonResponse
    {
        $site = $request->user()
            ->currentWorkspace
            ->sites()
            ->findOrFail($id);

        $competitors = $site->competitors()
            ->withCount('detections')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'competitors' => $competitors->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'domain' => $c->domain,
                'rss_url' => $c->rss_url,
                'sitemap_url' => $c->sitemap_url,
                'active_methods' => $c->active_methods,
                'auto_spy' => $c->auto_spy,
                'auto_spy_interval' => $c->auto_spy_interval,
                'last_scanned_at' => $c->last_scanned_at,
                'total_articles_detected' => $c->total_articles_detected,
                'detections_count' => $c->detections_count,
                'is_active' => $c->is_active,
            ]),
        ]);
    }

    /**
     * GET /api/sites/{id}/articles
     * Get site articles
     */
    public function articles(Request $request, int $id): JsonResponse
    {
        $site = $request->user()
            ->currentWorkspace
            ->sites()
            ->findOrFail($id);

        $articles = $site->articles()
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'articles' => $articles->map(fn($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'slug' => $a->slug,
                'word_count' => $a->word_count,
                'generation_status' => $a->generation_status,
                'publish_status' => $a->publish_status,
                'wp_post_id' => $a->wp_post_id,
                'wp_post_url' => $a->wp_post_url,
                'total_cost_usd' => $a->total_cost_usd,
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
}