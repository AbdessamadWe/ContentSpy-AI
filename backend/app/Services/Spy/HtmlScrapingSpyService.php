<?php
namespace App\Services\Spy;

use App\Models\Competitor;
use App\Models\SpyDetection;
use App\Models\SpyJobLog;
use App\Models\Workspace;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HtmlScrapingSpyService
{
    public function __construct(private CreditService $credits) {}

    public function scan(Competitor $competitor): array
    {
        $workspace = Workspace::find($competitor->workspace_id);
        $creditCost = config('credits.actions.html_scraping_scan', 3);
        $startTime = microtime(true);

        $token = $this->credits->reserve($workspace, $creditCost, 'html_scraping_scan');

        $log = SpyJobLog::create([
            'competitor_id' => $competitor->id,
            'workspace_id'  => $competitor->workspace_id,
            'method'        => 'html_scraping',
            'status'        => 'started',
        ]);

        try {
            if (!$competitor->blog_url) {
                throw new \RuntimeException("Competitor #{$competitor->id} has no blog URL configured.");
            }

            // Call Playwright microservice
            $playwrightUrl = config('contentspy.playwright_url');
            $response = Http::timeout(60)->post("{$playwrightUrl}/scrape-links", [
                'url'           => $competitor->blog_url,
                'link_selector' => 'article a, .post a, .entry a, h2 a, h3 a',
                'max_pages'     => 3,
                'timeout_ms'    => 30000,
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException("Playwright scrape failed: " . $response->body());
            }

            $links = $response->json('links', []);
            $newDetections = [];

            foreach ($links as $link) {
                // Only process links from the same domain
                if (!str_contains($link, parse_url($competitor->blog_url, PHP_URL_HOST))) continue;

                $hash = SpyDetection::generateHash($link, '');
                if (SpyDetection::where('content_hash', $hash)->exists()) continue;

                $detection = SpyDetection::create([
                    'competitor_id'    => $competitor->id,
                    'site_id'          => $competitor->site_id,
                    'workspace_id'     => $competitor->workspace_id,
                    'method'           => 'html_scraping',
                    'source_url'       => $link,
                    'content_hash'     => $hash,
                    'opportunity_score' => 60,
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
            Log::error("[HtmlSpy] Competitor #{$competitor->id}: " . $e->getMessage());
            throw $e;
        }
    }
}
