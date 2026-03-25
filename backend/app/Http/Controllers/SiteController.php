<?php
namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SiteController extends Controller
{
    public function index(Request $request, int $workspace): JsonResponse
    {
        $sites = Site::where('workspace_id', $workspace)
            ->orderBy('name')
            ->get();

        return response()->json(['sites' => $sites]);
    }

    public function store(Request $request, int $workspace): JsonResponse
    {
        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'url'             => ['required', 'url', 'unique:sites,url'],
            'niche'           => ['nullable', 'string'],
            'language'        => ['nullable', 'string', 'max:10'],
            'connection_type' => ['nullable', 'in:plugin,rest_api'],
            'timezone'        => ['nullable', 'timezone'],
            'ai_model_text'   => ['nullable', 'string'],
            'ai_model_image'  => ['nullable', 'string'],
            'max_posts_per_day' => ['nullable', 'integer', 'min:1'],
            'workflow_template' => ['nullable', 'string'],
        ]);

        // Generate plugin API key and secret for plugin connection
        $pluginApiKey = Str::random(40);
        $pluginSecret = Str::random(64);

        $site = Site::create(array_merge($validated, [
            'workspace_id'  => $workspace,
            'plugin_api_key' => $pluginApiKey,
            'plugin_secret'  => encrypt($pluginSecret), // store encrypted
        ]));

        return response()->json([
            'site'          => $site,
            'plugin_api_key' => $pluginApiKey,
            'plugin_secret'  => $pluginSecret, // return once only, never again
        ], 201);
    }

    public function show(int $workspace, Site $site): JsonResponse
    {
        if ($site->workspace_id !== $workspace) abort(403);

        return response()->json([
            'site' => $site->load('competitors:id,site_id,name,domain,auto_spy'),
        ]);
    }

    public function update(Request $request, int $workspace, Site $site): JsonResponse
    {
        if ($site->workspace_id !== $workspace) abort(403);

        $validated = $request->validate([
            'name'            => ['sometimes', 'string', 'max:255'],
            'niche'           => ['nullable', 'string'],
            'language'        => ['nullable', 'string'],
            'ai_model_text'   => ['nullable', 'string'],
            'ai_model_image'  => ['nullable', 'string'],
            'max_posts_per_day' => ['nullable', 'integer', 'min:1'],
            'workflow_template' => ['nullable', 'string'],
            'timezone'        => ['nullable', 'timezone'],
            'is_active'       => ['nullable', 'boolean'],
            // REST API fields
            'wp_api_url'      => ['nullable', 'url'],
            'wp_username'     => ['nullable', 'string'],
            'wp_app_password' => ['nullable', 'string'],
        ]);

        // Encrypt sensitive fields before saving
        if (isset($validated['wp_app_password'])) {
            $validated['wp_app_password'] = encrypt($validated['wp_app_password']);
        }

        $site->update($validated);

        return response()->json(['site' => $site->fresh()]);
    }

    public function destroy(int $workspace, Site $site): JsonResponse
    {
        if ($site->workspace_id !== $workspace) abort(403);
        $site->delete();
        return response()->json(['message' => 'Site deleted.']);
    }

    /**
     * Verify WordPress connection (plugin or REST API).
     * Calls the status endpoint and updates site metadata.
     */
    public function verifyConnection(Request $request, int $workspace, Site $site): JsonResponse
    {
        if ($site->workspace_id !== $workspace) abort(403);

        try {
            if ($site->connection_type === 'plugin') {
                $statusUrl = rtrim($site->url, '/') . '/wp-json/contentspy/v1/status';
                $secret = decrypt($site->plugin_secret);
                $body = json_encode([]);
                $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

                $response = Http::withHeaders([
                    'X-ContentSpy-Signature' => $signature,
                    'Content-Type'            => 'application/json',
                ])->timeout(15)->get($statusUrl);
            } else {
                $apiUrl = rtrim($site->wp_api_url ?? $site->url, '/') . '/wp-json/wp/v2';
                $password = decrypt($site->wp_app_password);
                $response = Http::withBasicAuth($site->wp_username, $password)
                    ->timeout(15)
                    ->get($apiUrl);
            }

            if ($response->successful()) {
                $data = $response->json();
                $site->update([
                    'connection_status' => 'connected',
                    'wp_version'        => $data['wp_version'] ?? null,
                    'php_version'       => $data['php_version'] ?? null,
                    'plugin_version'    => $data['plugin_version'] ?? null,
                    'last_sync_at'      => now(),
                ]);

                return response()->json([
                    'status'  => 'connected',
                    'message' => 'Connection verified successfully.',
                    'site'    => $site->fresh(),
                ]);
            }

            $site->update(['connection_status' => 'error']);
            return response()->json(['status' => 'error', 'message' => "HTTP {$response->status()}"], 422);
        } catch (\Throwable $e) {
            $site->update(['connection_status' => 'error']);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }
}
