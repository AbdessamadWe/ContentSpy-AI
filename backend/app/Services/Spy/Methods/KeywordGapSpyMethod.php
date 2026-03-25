<?php
namespace App\Services\Spy\Methods;

use App\Models\Competitor;
use App\Models\SpyDetection;
use App\Models\SpyJobLog;
use App\Models\Workspace;
use App\Services\Credits\CreditService;
use App\Services\Spy\Contracts\SpyMethodInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KeywordGapSpyMethod implements SpyMethodInterface
{
    public function __construct(private CreditService $credits) {}

    public function key(): string { return 'keyword_gap'; }
    public function creditCost(): int { return config('credits.actions.keyword_gap_pull', 5); }

    public function detect(Competitor $competitor): array
    {
        $workspace = Workspace::find($competitor->workspace_id);
        $site = $competitor->site;

        // API key stored encrypted in site.settings
        $semrushKey = $site->settings['semrush_api_key'] ?? null;
        if (!$semrushKey) {
            throw new \RuntimeException("SEMrush API key not configured for site #{$site->id}. Add it in site settings.");
        }

        $cost  = $this->creditCost();
        $token = $this->credits->reserve($workspace, $cost, 'keyword_gap_pull');
        $startTime = microtime(true);

        $log = SpyJobLog::create([
            'competitor_id' => $competitor->id,
            'workspace_id'  => $competitor->workspace_id,
            'method'        => 'keyword_gap',
            'status'        => 'started',
        ]);

        try {
            $keywords = $this->fetchSemrushKeywordGap(
                $semrushKey,
                parse_url($site->url, PHP_URL_HOST),
                $competitor->semrush_domain ?? $competitor->domain,
            );

            $detections = [];
            foreach (array_slice($keywords, 0, 20) as $kw) {
                $url = "https://{$competitor->domain}/?keyword=" . urlencode($kw['keyword']);
                $hash = SpyDetection::generateHash($url, $kw['keyword']);

                if (SpyDetection::where('content_hash', $hash)->exists()) continue;

                $detections[] = SpyDetection::create([
                    'competitor_id'    => $competitor->id,
                    'site_id'          => $competitor->site_id,
                    'workspace_id'     => $competitor->workspace_id,
                    'method'           => 'keyword_gap',
                    'source_url'       => $url,
                    'title'            => "Keyword opportunity: {$kw['keyword']}",
                    'content_hash'     => $hash,
                    'keyword_difficulty' => $kw['difficulty'] ?? null,
                    'estimated_traffic'  => $kw['volume'] ?? null,
                    'opportunity_score'  => $this->scoreKeyword($kw),
                    'credits_consumed'   => $cost,
                    'status'             => 'new',
                    'raw_data'           => $kw,
                ]);
            }

            $ms = (int) ((microtime(true) - $startTime) * 1000);
            $log->update(['status' => 'completed', 'new_detections' => count($detections), 'credits_consumed' => $cost, 'duration_ms' => $ms]);
            $this->credits->confirm($workspace, $token, actionId: (string) $competitor->id);

            return $detections;
        } catch (\Throwable $e) {
            $this->credits->refund($workspace, $token, $e->getMessage());
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::error("[KeywordGapSpy] #{$competitor->id}: {$e->getMessage()}");
            throw $e;
        }
    }

    private function fetchSemrushKeywordGap(string $apiKey, string $domain1, string $domain2): array
    {
        $response = Http::timeout(30)->get('https://api.semrush.com/', [
            'type'         => 'phrase_kdi',
            'key'          => $apiKey,
            'phrase'       => '*',
            'export_columns' => 'Ph,Nq,Kd,Co',
            'domain'       => $domain2,
            'display_limit' => 50,
        ]);

        if (!$response->successful()) return [];

        $lines = explode("\n", trim($response->body()));
        array_shift($lines); // remove header

        return array_map(function ($line) {
            $parts = str_getcsv($line, ';');
            return [
                'keyword'    => $parts[0] ?? '',
                'volume'     => (int) ($parts[1] ?? 0),
                'difficulty' => (int) ($parts[2] ?? 0),
                'cpc'        => (float) ($parts[3] ?? 0),
            ];
        }, array_filter($lines));
    }

    private function scoreKeyword(array $kw): int
    {
        $score = 50;
        $volume = $kw['volume'] ?? 0;
        $difficulty = $kw['difficulty'] ?? 100;

        $score += match(true) {
            $volume > 10000 => 30,
            $volume > 1000  => 20,
            $volume > 100   => 10,
            default         => 0,
        };

        $score -= match(true) {
            $difficulty > 80 => 20,
            $difficulty > 60 => 10,
            $difficulty > 40 => 5,
            default          => 0,
        };

        return min(100, max(0, $score));
    }
}
