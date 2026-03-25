<?php
namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives heartbeat pings from the ContentSpy Connect WordPress plugin.
 * Updates site connection status and metadata.
 */
class HeartbeatController extends Controller
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

        $data = $request->all();

        $site->update([
            'connection_status' => 'connected',
            'plugin_version'    => $data['plugin_version'] ?? $site->plugin_version,
            'wp_version'        => $data['wp_version'] ?? $site->wp_version,
            'php_version'       => $data['php_version'] ?? $site->php_version,
            'last_sync_at'      => now(),
        ]);

        return response()->json(['status' => 'ok', 'received_at' => now()->toISOString()]);
    }
}
