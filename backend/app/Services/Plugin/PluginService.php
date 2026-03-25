<?php
namespace App\Services\Plugin;

use App\Models\Site;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PluginService
{
    /**
     * Verify a plugin API key. Returns site data or throws.
     * API key is stored as SHA-256 hash in sites.plugin_api_key.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \RuntimeException on inactive site
     */
    public function verifyApiKey(string $rawKey): Site
    {
        $hashed = hash('sha256', $rawKey);

        $site = Site::where('plugin_api_key', $hashed)
            ->where('is_active', true)
            ->firstOrFail();

        if (! $site->workspace->is_active) {
            throw new \RuntimeException('Workspace is inactive.');
        }

        return $site;
    }

    /**
     * Generate a new plugin API key for a site.
     * Returns the raw key (shown once) and stores the hash.
     */
    public function generateApiKey(Site $site): string
    {
        $rawKey = bin2hex(random_bytes(16)); // 32 hex chars
        $hashed = hash('sha256', $rawKey);

        $site->update([
            'plugin_api_key'       => $hashed,
            'connection_status'    => 'disconnected',
        ]);

        return $rawKey;
    }

    /**
     * Connect a site after successful plugin verification.
     * Stores the shared secret for HMAC signing.
     */
    public function connect(Site $site, array $pluginInfo = []): string
    {
        // Generate a new shared secret for HMAC
        $rawSecret = bin2hex(random_bytes(32)); // 64 hex chars

        $site->update([
            'plugin_secret'     => encrypt($rawSecret),
            'connection_status' => 'connected',
            'plugin_version'    => $pluginInfo['version']     ?? null,
            'wp_version'        => $pluginInfo['wp_version']  ?? null,
            'php_version'       => $pluginInfo['php_version'] ?? null,
            'last_sync_at'      => now(),
        ]);

        return $rawSecret; // Return once to be stored by plugin
    }

    /**
     * Get a signed download URL for the plugin zip from R2.
     * URL expires in 15 minutes.
     */
    public function getDownloadUrl(): string
    {
        $version = config('contentspy.plugin_version', '1.0.0');
        $key     = "plugins/contentspy-connect-v{$version}.zip";

        // R2 signed URL (15 minutes)
        return Storage::disk('r2')->temporaryUrl($key, now()->addMinutes(15));
    }

    /**
     * Current plugin version info.
     */
    public function getVersionInfo(): array
    {
        $version = config('contentspy.plugin_version', '1.0.0');
        return [
            'current_version' => $version,
            'download_url'    => $this->getDownloadUrl(),
            'changelog'       => config('contentspy.plugin_changelog', []),
            'min_wp_version'  => '5.8',
            'min_php_version' => '8.0',
        ];
    }

    /**
     * Sync WP posts back to ContentSpy (called from plugin cron).
     * Matches existing articles by wp_post_id, updates status.
     */
    public function syncPosts(Site $site, array $posts): int
    {
        $synced = 0;
        foreach ($posts as $post) {
            $updated = \App\Models\Article::where('site_id', $site->id)
                ->where('wp_post_id', $post['id'])
                ->update([
                    'wp_post_url'    => $post['link']   ?? null,
                    'publish_status' => $post['status'] === 'publish' ? 'published' : $post['status'],
                ]);
            if ($updated) $synced++;
        }
        return $synced;
    }
}
