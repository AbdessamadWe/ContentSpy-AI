<?php
namespace App\Services\Spy\Methods;

use App\Models\Competitor;
use App\Models\SpyDetection;
use App\Models\SpyJobLog;
use App\Models\Workspace;
use App\Services\Credits\CreditService;
use App\Services\Spy\Contracts\SpyMethodInterface;
use App\Services\Spy\OpportunityScorer;
use App\Services\Spy\SpyDeduplicator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class GoogleNewsSpyMethod implements SpyMethodInterface
{
    public function __construct(
        private CreditService $credits,
        private SpyDeduplicator $deduplicator,
        private OpportunityScorer $scorer,
    ) {}

    public function key(): string { return 'google_news'; }
    public function creditCost(): int { return config('credits.actions.google_news_scan', 2); }

    public function detect(Competitor $competitor): array
    {
        $workspace = Workspace::find($competitor->workspace_id);
        $cost  = $this->creditCost();
        $token = $this->credits->reserve($workspace, $cost, 'google_news_scan');
        $startTime = microtime(true);

        $log = SpyJobLog::create([
            'competitor_id' => $competitor->id,
            'workspace_id'  => $competitor->workspace_id,
            'method'        => 'google_news',
            'status'        => 'started',
        ]);

        try {
            $domain   = $competitor->domain;
            $feedUrl  = "https://news.google.com/rss/search?q=site:{$domain}&hl=en-US&gl=US&ceid=US:en";

            $response = Http::withHeaders(['User-Agent' => 'ContentSpy/1.0 News Monitor'])
                ->timeout(15)->get($feedUrl);

            if (!$response->successful()) {
                throw new \RuntimeException("Google News fetch failed: HTTP {$response->status()}");
            }

            $items = $this->parseGoogleNewsRss($response->body());
            $newItems = $this->deduplicator->filter($items);
            $detections = [];

            foreach ($newItems as $item) {
                $detection = SpyDetection::create([
                    'competitor_id'    => $competitor->id,
                    'site_id'          => $competitor->site_id,
                    'workspace_id'     => $competitor->workspace_id,
                    'method'           => 'google_news',
                    'source_url'       => $item['source_url'],
                    'title'            => $item['title'] ?? null,
                    'excerpt'          => $item['excerpt'] ?? null,
                    'published_at'     => $item['published_at'] ?? null,
                    'content_hash'     => $item['content_hash'],
                    'opportunity_score' => $this->scorer->score($item),
                    'credits_consumed' => $cost,
                    'status'           => 'new',
                ]);
                $detections[] = $detection;
            }

            $competitor->increment('total_articles_detected', count($detections));
            $competitor->update(['last_scanned_at' => now()]);

            $ms = (int) ((microtime(true) - $startTime) * 1000);
            $log->update(['status' => 'completed', 'new_detections' => count($detections), 'credits_consumed' => $cost, 'duration_ms' => $ms]);
            $this->credits->confirm($workspace, $token, actionId: (string) $competitor->id);

            return $detections;
        } catch (\Throwable $e) {
            $this->credits->refund($workspace, $token, $e->getMessage());
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::error("[GoogleNewsSpy] #{$competitor->id}: {$e->getMessage()}");
            throw $e;
        }
    }

    private function parseGoogleNewsRss(string $xml): array
    {
        libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);
        if (!$feed) return [];

        $items = [];
        foreach ($feed->channel->item ?? [] as $item) {
            $items[] = [
                'source_url'   => (string) ($item->link ?? ''),
                'title'        => (string) ($item->title ?? ''),
                'excerpt'      => strip_tags((string) ($item->description ?? '')),
                'published_at' => $this->parseDate((string) ($item->pubDate ?? '')),
                'categories'   => [],
                'tags'         => [],
            ];
        }
        return $items;
    }

    private function parseDate(string $d): ?string
    {
        if (empty($d)) return null;
        try { return (new \DateTime($d))->format('Y-m-d H:i:s'); } catch (\Throwable) { return null; }
    }
}
