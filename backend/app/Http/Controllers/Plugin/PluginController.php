<?php
namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Services\Plugin\PluginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PluginController extends Controller
{
    public function __construct(private readonly PluginService $plugin) {}

    /**
     * GET /api/plugin/download
     * Returns signed R2 URL for the plugin zip.
     */
    public function download(): JsonResponse
    {
        return response()->json([
            'url'     => $this->plugin->getDownloadUrl(),
            'expires' => now()->addMinutes(15)->toIso8601String(),
        ]);
    }

    /**
     * POST /api/plugin/verify
     * Validates plugin API key, returns site info + shared secret.
     * Called by the WP plugin on first connection.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate(['api_key' => 'required|string']);

        try {
            $site = $this->plugin->verifyApiKey($request->api_key);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['connected' => false, 'error' => 'Invalid API key.'], 401);
        } catch (\RuntimeException $e) {
            return response()->json(['connected' => false, 'error' => $e->getMessage()], 403);
        }

        $pluginInfo = $request->only(['version', 'wp_version', 'php_version']);
        $secret     = $this->plugin->connect($site, $pluginInfo);

        return response()->json([
            'connected' => true,
            'site_id'   => $site->id,
            'secret'    => $secret,
            'site_name' => $site->name,
        ]);
    }

    /**
     * GET /api/plugin/version
     * Returns current plugin version and download URL.
     */
    public function version(): JsonResponse
    {
        return response()->json($this->plugin->getVersionInfo());
    }

    /**
     * POST /api/plugin/sync
     * Receives post list from WP, syncs article statuses back to SaaS.
     */
    public function sync(Request $request): JsonResponse
    {
        $request->validate([
            'api_key' => 'required|string',
            'posts'   => 'required|array',
        ]);

        try {
            $site = $this->plugin->verifyApiKey($request->api_key);
        } catch (\Throwable) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        $synced = $this->plugin->syncPosts($site, $request->posts);

        return response()->json(['synced' => $synced]);
    }

    /**
     * POST /api/sites/{site}/plugin/generate-key
     * Generate a new plugin API key for a site (authenticated, workspace-scoped).
     */
    public function generateKey(Request $request, int $siteId): JsonResponse
    {
        $workspace = $request->attributes->get('_workspace');
        $site      = \App\Models\Site::where('workspace_id', $workspace->id)->findOrFail($siteId);

        $this->authorize('update', $site);

        $rawKey = $this->plugin->generateApiKey($site);

        return response()->json([
            'api_key' => $rawKey,
            'note'    => 'This key is shown once. Copy it to your WordPress plugin settings.',
        ]);
    }
}
