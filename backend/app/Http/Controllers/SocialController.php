<?php
namespace App\Http\Controllers;

use App\Jobs\Publishing\PublishToSocialJob;
use App\Models\Article;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SocialController extends Controller
{
    /**
     * GET /api/workspaces/{workspace}/social/accounts
     * List all connected social accounts for a site.
     */
    public function accounts(Request $request): JsonResponse
    {
        $workspace  = $request->attributes->get('_workspace');
        $siteId     = $request->integer('site_id');

        $query = SocialAccount::where('workspace_id', $workspace->id);
        if ($siteId) {
            $query->where('site_id', $siteId);
        }

        return response()->json($query->get());
    }

    /**
     * DELETE /api/workspaces/{workspace}/social/accounts/{account}
     * Disconnect a social account.
     */
    public function disconnect(Request $request, int $account): JsonResponse
    {
        $workspace   = $request->attributes->get('_workspace');
        $socialAcct  = SocialAccount::where('workspace_id', $workspace->id)->findOrFail($account);

        $socialAcct->update(['is_active' => false, 'access_token' => null]);

        return response()->json(['message' => 'Account disconnected.']);
    }

    /**
     * POST /api/workspaces/{workspace}/articles/{article}/social/publish
     * Dispatch social publishing jobs for an article.
     */
    public function publishArticle(Request $request, int $article): JsonResponse
    {
        $workspace  = $request->attributes->get('_workspace');
        $art        = Article::where('workspace_id', $workspace->id)->findOrFail($article);

        $request->validate([
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|in:facebook,instagram,tiktok,pinterest',
        ]);

        $platforms = $request->input('platforms'); // null = all connected accounts

        $accounts = SocialAccount::where('workspace_id', $workspace->id)
            ->where('site_id', $art->site_id)
            ->where('is_active', true)
            ->when($platforms, fn($q) => $q->whereIn('platform', $platforms))
            ->get();

        if ($accounts->isEmpty()) {
            return response()->json(['message' => 'No connected social accounts found.'], 422);
        }

        foreach ($accounts as $account) {
            PublishToSocialJob::dispatch($art->id, $account->id)
                ->onQueue('publishing');
        }

        return response()->json([
            'message'   => "Queued publishing to {$accounts->count()} platform(s).",
            'platforms' => $accounts->pluck('platform'),
        ]);
    }

    /**
     * GET /api/workspaces/{workspace}/auth/social/{platform}/redirect
     * Redirect to OAuth for the given platform.
     */
    public function oauthRedirect(Request $request, string $platform): \Illuminate\Http\RedirectResponse
    {
        // Store workspace context in session for callback
        session(['social_oauth_workspace_id' => $request->attributes->get('_workspace')->id]);

        return match ($platform) {
            'facebook'  => redirect('https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query([
                'client_id'     => config('services.facebook.client_id'),
                'redirect_uri'  => route('social.callback', ['platform' => 'facebook']),
                'scope'         => 'pages_manage_posts,pages_read_engagement',
            ])),
            'pinterest' => redirect('https://www.pinterest.com/oauth/?' . http_build_query([
                'client_id'     => config('services.pinterest.client_id'),
                'redirect_uri'  => route('social.callback', ['platform' => 'pinterest']),
                'response_type' => 'code',
                'scope'         => 'boards:read,pins:write',
            ])),
            default     => response()->json(['error' => "OAuth not configured for {$platform}"], 400),
        };
    }

    /**
     * GET /api/workspaces/{workspace}/auth/social/{platform}/callback
     * Handle OAuth callback and store tokens.
     */
    public function oauthCallback(Request $request, string $platform): JsonResponse
    {
        // OAuth callback handling is platform-specific
        // This is a placeholder — production would exchange code for tokens
        return response()->json(['message' => "OAuth callback for {$platform} received."]);
    }
}
