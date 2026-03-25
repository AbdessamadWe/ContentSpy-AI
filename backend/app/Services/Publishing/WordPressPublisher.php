<?php
namespace App\Services\Publishing;

use App\Models\Article;
use App\Models\Site;
use App\Services\Publishing\Contracts\WordPressClientInterface;

/**
 * Publishes articles to WordPress.
 * Auto-selects the appropriate client (plugin or REST API) based on site.connection_type.
 */
class WordPressPublisher
{
    public function __construct(
        private readonly WordPressPluginClient $pluginClient,
        private readonly WordPressRestClient   $restClient,
    ) {}

    /**
     * Publish an article to the given WordPress site.
     * Updates article record with wp_post_id, wp_post_url, wp_published_at.
     *
     * @throws \RuntimeException on publish failure
     */
    public function publish(Article $article, Site $site): array
    {
        $client = $this->resolveClient($site);
        $result = $client->publish($article, $site);

        $article->update([
            'wp_post_id'      => $result['post_id'],
            'wp_post_url'     => $result['post_url'],
            'wp_published_at' => now(),
            'publish_status'  => 'published',
        ]);

        $article->suggestion?->update(['status' => 'published']);

        return $result;
    }

    /**
     * Test the connection to a WordPress site.
     */
    public function testConnection(Site $site): bool
    {
        return $this->resolveClient($site)->testConnection($site);
    }

    private function resolveClient(Site $site): WordPressClientInterface
    {
        return match ($site->connection_type) {
            'plugin'   => $this->pluginClient,
            'rest_api' => $this->restClient,
            default    => throw new \InvalidArgumentException("Unknown WordPress connection type: {$site->connection_type}"),
        };
    }
}
