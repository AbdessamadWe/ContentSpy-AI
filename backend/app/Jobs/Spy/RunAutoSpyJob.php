<?php
namespace App\Jobs\Spy;

use App\Models\Competitor;
use App\Services\Credits\CreditService;
use App\Services\Spy\RssFeedSpyService;
use App\Services\Spy\HtmlScrapingSpyService;
use App\Services\Spy\SitemapSpyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunAutoSpyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 3;

    public function __construct(
        public readonly int $competitorId,
        public readonly string $method,
    ) {}

    public function handle(
        CreditService $creditService,
        RssFeedSpyService $rssService,
        HtmlScrapingSpyService $htmlService,
        SitemapSpyService $sitemapService,
    ): void {
        $competitor = Competitor::with('site', 'workspace')->find($this->competitorId);
        if (!$competitor || !$competitor->is_active) return;

        $workspace = $competitor->workspace;

        // Pause auto-spy if below minimum credits
        if ($creditService->shouldPauseAutoSpy($workspace)) {
            Log::info("[AutoSpy] Paused for workspace #{$workspace->id} — below min credit threshold.");
            return;
        }

        try {
            $detections = match($this->method) {
                'rss'          => $rssService->scan($competitor),
                'html_scraping' => $htmlService->scan($competitor),
                'sitemap'      => $sitemapService->scan($competitor),
                default        => throw new \InvalidArgumentException("Unknown spy method: {$this->method}"),
            };

            // Dispatch content suggestion jobs for new detections
            foreach ($detections as $detection) {
                if ($detection->opportunity_score >= $competitor->confidence_threshold_suggest) {
                    GenerateContentSuggestionJob::dispatch($detection->id);
                }
            }

            Log::info("[AutoSpy] Competitor #{$this->competitorId} method={$this->method}: " . count($detections) . " new detections");
        } catch (\Throwable $e) {
            Log::error("[AutoSpy] Failed competitor #{$this->competitorId} method={$this->method}: " . $e->getMessage());
            throw $e;
        }
    }

    public function tags(): array
    {
        return ["competitor:{$this->competitorId}", "method:{$this->method}"];
    }
}
