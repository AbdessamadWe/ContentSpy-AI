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

class SocialSignalSpyMethod implements SpyMethodInterface
{
    public function __construct(
        private CreditService $credits,
        private SpyDeduplicator $deduplicator,
        private OpportunityScorer $scorer,
    ) {}

    public function key(): string { return 'social_signal'; }
    public function creditCost(): int { return config('credits.actions.social_signal_scan', 2); }

    public function detect(Competitor $competitor): array
    {
        $workspace = Workspace::find($competitor->workspace_id);
        $cost  = $this->creditCost();
        $token = $this->credits->reserve($workspace, $cost, 'social_signal_scan');
        $startTime = microtime(true);

        $log = SpyJobLog::create([
            'competitor_id' => $competitor->id,
            'workspace_id'  => $competitor->workspace_id,
            'method'        => 'social_signal',
            'status'        => 'started',
        ]);

        try {
            $detections = [];

            if ($competitor->twitter_handle) {
                $tweets = $this->fetchTwitterTweets($competitor->twitter_handle);
                $newItems = $this->deduplicator->filter($tweets);

                foreach ($newItems as $item) {
                    $detections[] = SpyDetection::create([
                        'competitor_id'    => $competitor->id,
                        'site_id'          => $competitor->site_id,
                        'workspace_id'     => $competitor->workspace_id,
                        'method'           => 'social_signal',
                        'source_url'       => $item['source_url'],
                        'title'            => $item['title'] ?? null,
                        'excerpt'          => $item['excerpt'] ?? null,
                        'published_at'     => $item['published_at'] ?? null,
                        'content_hash'     => $item['content_hash'],
                        'opportunity_score' => $this->scorer->score($item),
                        'credits_consumed' => $cost,
                        'status'           => 'new',
                        'raw_data'         => ['platform' => 'twitter', 'metrics' => $item['metrics'] ?? null],
                    ]);
                }
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
            Log::error("[SocialSignalSpy] #{$competitor->id}: {$e->getMessage()}");
            throw $e;
        }
    }

    private function fetchTwitterTweets(string $handle): array
    {
        $bearerToken = config('services.twitter.bearer_token');
        if (!$bearerToken) return [];

        $handle = ltrim($handle, '@');
        $response = Http::withToken($bearerToken)
            ->timeout(15)
            ->get('https://api.twitter.com/2/tweets/search/recent', [
                'query'       => "from:{$handle} has:links -is:retweet",
                'max_results' => 10,
                'tweet.fields' => 'created_at,public_metrics,entities',
                'expansions'  => 'author_id',
            ]);

        if (!$response->successful()) return [];

        $items = [];
        foreach ($response->json('data', []) as $tweet) {
            $url = $tweet['entities']['urls'][0]['expanded_url'] ?? null;
            if (!$url) continue;

            $items[] = [
                'source_url'   => $url,
                'title'        => substr($tweet['text'], 0, 255),
                'excerpt'      => $tweet['text'],
                'published_at' => (new \DateTime($tweet['created_at']))->format('Y-m-d H:i:s'),
                'categories'   => [],
                'tags'         => [],
                'metrics'      => $tweet['public_metrics'] ?? null,
            ];
        }

        return $items;
    }
}
