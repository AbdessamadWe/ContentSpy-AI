<?php
namespace App\Services\Spy;

use App\Models\Competitor;
use App\Models\SpyDetection;
use App\Models\SpyJobLog;
use App\Models\Workspace;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class RssFeedSpyService
{
    public function __construct(private CreditService $credits) {}

    /**
     * Scan a competitor's RSS feed and return new detections.
     * Deduplicates via content_hash.
     */
    public function scan(Competitor $competitor): array
    {
        $workspace = Workspace::find($competitor->workspace_id);
        $creditCost = config('credits.actions.rss_feed_scan', 1);
        $startTime = microtime(true);
        $log = null;

        // Reserve credits before starting
        $token = $this->credits->reserve($workspace, $creditCost, 'rss_feed_scan');

        $log = SpyJobLog::create([
            'competitor_id' => $competitor->id,
            'workspace_id'  => $competitor->workspace_id,
            'method'        => 'rss',
            'status'        => 'started',
        ]);

        try {
            if (!$competitor->rss_url) {
                throw new \RuntimeException("Competitor #{$competitor->id} has no RSS URL configured.");
            }

            $xml = $this->fetchFeed($competitor->rss_url);
            $items = $this->parseFeed($xml);
            $newDetections = [];

            foreach ($items as $item) {
                $hash = SpyDetection::generateHash($item['source_url'], $item['title'] ?? '');
                $existing = SpyDetection::where('content_hash', $hash)->exists();
                if ($existing) continue;

                $detection = SpyDetection::create([
                    'competitor_id'    => $competitor->id,
                    'site_id'          => $competitor->site_id,
                    'workspace_id'     => $competitor->workspace_id,
                    'method'           => 'rss',
                    'source_url'       => $item['source_url'],
                    'title'            => $item['title'] ?? null,
                    'excerpt'          => $item['excerpt'] ?? null,
                    'author'           => $item['author'] ?? null,
                    'published_at'     => $item['published_at'] ?? null,
                    'categories'       => $item['categories'] ?? null,
                    'tags'             => $item['tags'] ?? null,
                    'content_hash'     => $hash,
                    'opportunity_score' => $this->scoreArticle($item),
                    'credits_consumed' => $creditCost,
                    'status'           => 'new',
                ]);

                $newDetections[] = $detection;
            }

            $competitor->increment('total_articles_detected', count($newDetections));
            $competitor->update(['last_scanned_at' => now()]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $log->update(['status' => 'completed', 'new_detections' => count($newDetections), 'credits_consumed' => $creditCost, 'duration_ms' => $durationMs]);

            $this->credits->confirm($workspace, $token, actionId: (string) $competitor->id);

            return $newDetections;
        } catch (\Throwable $e) {
            $this->credits->refund($workspace, $token, $e->getMessage());
            $log?->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::error("[RssSpy] Competitor #{$competitor->id}: " . $e->getMessage());
            throw $e;
        }
    }

    private function fetchFeed(string $url): string
    {
        $response = Http::withHeaders([
            'User-Agent' => 'ContentSpy/1.0 RSS Reader',
            'Accept'     => 'application/rss+xml, application/atom+xml, application/xml, text/xml',
        ])->timeout(15)->get($url);

        if (!$response->successful()) {
            throw new \RuntimeException("RSS fetch failed: HTTP {$response->status()} for {$url}");
        }

        return $response->body();
    }

    private function parseFeed(string $xml): array
    {
        libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);

        if ($feed === false) {
            throw new \RuntimeException("Failed to parse XML feed.");
        }

        $items = [];

        // RSS 2.0
        if (isset($feed->channel->item)) {
            foreach ($feed->channel->item as $item) {
                $items[] = $this->parseRssItem($item);
            }
        }
        // Atom
        elseif (isset($feed->entry)) {
            foreach ($feed->entry as $entry) {
                $items[] = $this->parseAtomEntry($entry);
            }
        }

        return $items;
    }

    private function parseRssItem(SimpleXMLElement $item): array
    {
        $url = (string) ($item->link ?? $item->guid ?? '');
        $categories = [];
        foreach ($item->category ?? [] as $cat) {
            $categories[] = (string) $cat;
        }

        return [
            'source_url'   => trim($url),
            'title'        => (string) ($item->title ?? ''),
            'excerpt'      => strip_tags((string) ($item->description ?? '')),
            'author'       => (string) ($item->author ?? $item->children('dc', true)->creator ?? ''),
            'published_at' => $this->parseDate((string) ($item->pubDate ?? '')),
            'categories'   => $categories,
            'tags'         => [],
        ];
    }

    private function parseAtomEntry(SimpleXMLElement $entry): array
    {
        $url = '';
        foreach ($entry->link ?? [] as $link) {
            if ((string) $link['rel'] === 'alternate' || empty((string) $link['rel'])) {
                $url = (string) $link['href'];
                break;
            }
        }

        return [
            'source_url'   => trim($url),
            'title'        => (string) ($entry->title ?? ''),
            'excerpt'      => strip_tags((string) ($entry->summary ?? $entry->content ?? '')),
            'author'       => (string) ($entry->author->name ?? ''),
            'published_at' => $this->parseDate((string) ($entry->published ?? $entry->updated ?? '')),
            'categories'   => [],
            'tags'         => [],
        ];
    }

    private function parseDate(string $date): ?string
    {
        if (empty($date)) return null;
        try {
            return (new \DateTime($date))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Simple scoring: newer = higher score; longer excerpt = higher score */
    private function scoreArticle(array $item): int
    {
        $score = 50; // base score

        // Freshness bonus (articles < 24h old get +20)
        if (!empty($item['published_at'])) {
            $hoursOld = (time() - strtotime($item['published_at'])) / 3600;
            if ($hoursOld < 24) $score += 20;
            elseif ($hoursOld < 72) $score += 10;
        }

        // Content length signal
        $excerptLen = strlen($item['excerpt'] ?? '');
        if ($excerptLen > 500) $score += 10;
        if ($excerptLen > 200) $score += 5;

        // Category/tag richness
        $score += min(10, count($item['categories'] ?? []) * 2);

        return min(100, $score);
    }
}
