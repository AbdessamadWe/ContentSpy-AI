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

class SitemapSpyService
{
    public function __construct(private CreditService $credits) {}

    public function scan(Competitor $competitor): array
    {
        $workspace = Workspace::find($competitor->workspace_id);
        $creditCost = config('credits.actions.sitemap_scan', 1);
        $startTime = microtime(true);

        $token = $this->credits->reserve($workspace, $creditCost, 'sitemap_scan');

        $log = SpyJobLog::create([
            'competitor_id' => $competitor->id,
            'workspace_id'  => $competitor->workspace_id,
            'method'        => 'sitemap',
            'status'        => 'started',
        ]);

        try {
            if (!$competitor->sitemap_url) {
                throw new \RuntimeException("Competitor #{$competitor->id} has no sitemap URL configured.");
            }

            $urls = $this->parseSitemapRecursive($competitor->sitemap_url);
            $newDetections = [];

            foreach ($urls as $urlData) {
                $hash = SpyDetection::generateHash($urlData['url'], '');
                if (SpyDetection::where('content_hash', $hash)->exists()) continue;

                $detection = SpyDetection::create([
                    'competitor_id'    => $competitor->id,
                    'site_id'          => $competitor->site_id,
                    'workspace_id'     => $competitor->workspace_id,
                    'method'           => 'sitemap',
                    'source_url'       => $urlData['url'],
                    'published_at'     => $urlData['lastmod'] ?? null,
                    'content_hash'     => $hash,
                    'opportunity_score' => $this->scoreSitemapUrl($urlData),
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
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::error("[SitemapSpy] Competitor #{$competitor->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /** Parse sitemap or sitemap index recursively */
    private function parseSitemapRecursive(string $url, int $depth = 0): array
    {
        if ($depth > 3) return []; // prevent infinite recursion

        $response = Http::withHeaders(['User-Agent' => 'ContentSpy/1.0 Sitemap Parser'])
            ->timeout(15)
            ->get($url);

        if (!$response->successful()) return [];

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NOCDATA);
        if ($xml === false) return [];

        $tag = strtolower($xml->getName());
        $urls = [];

        // Sitemap index — recurse into each nested sitemap
        if ($tag === 'sitemapindex') {
            foreach ($xml->sitemap as $sitemap) {
                $nestedUrl = (string) $sitemap->loc;
                if ($nestedUrl) {
                    $urls = array_merge($urls, $this->parseSitemapRecursive($nestedUrl, $depth + 1));
                }
            }
        }
        // Regular sitemap
        elseif ($tag === 'urlset') {
            foreach ($xml->url as $urlEl) {
                $urls[] = [
                    'url'        => (string) $urlEl->loc,
                    'lastmod'    => (string) ($urlEl->lastmod ?? null) ?: null,
                    'priority'   => (float) ($urlEl->priority ?? 0.5),
                    'changefreq' => (string) ($urlEl->changefreq ?? ''),
                ];
            }
        }

        return $urls;
    }

    private function scoreSitemapUrl(array $urlData): int
    {
        $score = 50;
        $score += (int) (($urlData['priority'] ?? 0.5) * 30);

        if ($urlData['lastmod'] ?? null) {
            $hoursOld = (time() - strtotime($urlData['lastmod'])) / 3600;
            if ($hoursOld < 24) $score += 20;
            elseif ($hoursOld < 72) $score += 10;
        }

        return min(100, $score);
    }
}
