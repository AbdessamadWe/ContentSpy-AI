<?php
namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives notifications from the WordPress plugin when a post is published manually.
 * Enables two-way sync: detect manually published posts → mark in SaaS dashboard.
 */
class PostPublishedController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $apiKey = $request->header('X-ContentSpy-API-Key')
            ?? $request->input('api_key');

        if (!$apiKey) {
            return response()->json(['error' => 'API key required.'], 401);
        }

        $site = Site::where('plugin_api_key', $apiKey)->first();
        if (!$site) {
            return response()->json(['error' => 'Unknown API key.'], 401);
        }

        $wpPostId = (int) $request->input('post_id');
        $wpPostUrl = $request->input('post_url');
        $publishedAt = $request->input('published_at');

        // Check if we have an article tracked for this wp_post_id
        $article = Article::where('site_id', $site->id)
            ->where('wp_post_id', $wpPostId)
            ->first();

        if ($article) {
            $article->update([
                'publish_status'  => 'published',
                'wp_post_url'     => $wpPostUrl,
                'wp_published_at' => $publishedAt ?? now(),
            ]);
        }

        $site->update(['last_post_at' => $publishedAt ?? now()]);

        return response()->json(['status' => 'ok']);
    }
}
