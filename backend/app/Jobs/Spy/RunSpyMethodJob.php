<?php
namespace App\Jobs\Spy;

use App\Jobs\Spy\GenerateContentSuggestionJob;
use App\Models\Competitor;
use App\Services\Spy\Methods\GoogleNewsSpyMethod;
use App\Services\Spy\Methods\HtmlScrapingSpyMethod;
use App\Services\Spy\Methods\KeywordGapSpyMethod;
use App\Services\Spy\Methods\RssFeedSpyService as RssSpyMethod;
use App\Services\Spy\Methods\SitemapSpyService as SitemapSpyMethod;
use App\Services\Spy\Methods\SocialSignalSpyMethod;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunSpyMethodJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int    $competitorId,
        public readonly string $method,
    ) {}

    public function handle(
        RssSpyMethod         $rss,
        SitemapSpyMethod     $sitemap,
        HtmlScrapingSpyMethod $html,
        GoogleNewsSpyMethod  $googleNews,
        SocialSignalSpyMethod $socialSignal,
        KeywordGapSpyMethod  $keywordGap,
    ): void {
        $competitor = Competitor::with('site', 'workspace')->find($this->competitorId);
        if (!$competitor || !$competitor->is_active) return;

        $service = match($this->method) {
            'rss'            => $rss,
            'sitemap'        => $sitemap,
            'html_scraping'  => $html,
            'google_news'    => $googleNews,
            'social_signal'  => $socialSignal,
            'keyword_gap'    => $keywordGap,
            default          => null,
        };

        if (!$service) {
            Log::warning("[RunSpyMethodJob] Unknown method: {$this->method}");
            return;
        }

        try {
            $detections = $service->detect($competitor);

            foreach ($detections as $detection) {
                if ($detection->opportunity_score >= $competitor->confidence_threshold_suggest) {
                    GenerateContentSuggestionJob::dispatch($detection->id)->onQueue('content');
                }
            }

            Log::info("[RunSpyMethodJob] competitor=#{$this->competitorId} method={$this->method} new=" . count($detections));
        } catch (\Throwable $e) {
            Log::error("[RunSpyMethodJob] Failed competitor=#{$this->competitorId} method={$this->method}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function tags(): array
    {
        return ["competitor:{$this->competitorId}", "spy:{$this->method}"];
    }
}
