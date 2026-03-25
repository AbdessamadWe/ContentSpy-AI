<?php
namespace App\Services\Analytics;

use App\Models\Article;
use App\Models\ContentSuggestion;
use App\Models\SpyDetection;
use App\Models\TokenUsageLog;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /**
     * Dashboard stats for a workspace.
     * Cached for 5 minutes.
     */
    public function getDashboardStats(Workspace $workspace): array
    {
        return Cache::remember(
            "analytics:dashboard:{$workspace->id}",
            300,
            fn() => $this->computeDashboardStats($workspace)
        );
    }

    private function computeDashboardStats(Workspace $workspace): array
    {
        $wid = $workspace->id;

        return [
            'articles' => [
                'total'      => Article::where('workspace_id', $wid)->count(),
                'published'  => Article::where('workspace_id', $wid)->where('publish_status', 'published')->count(),
                'in_pipeline' => Article::where('workspace_id', $wid)
                    ->whereNotIn('generation_status', ['ready', 'failed'])
                    ->where('generation_status', '!=', 'pending')
                    ->count(),
                'failed'     => Article::where('workspace_id', $wid)->where('generation_status', 'failed')->count(),
            ],
            'suggestions' => [
                'pending'  => ContentSuggestion::where('workspace_id', $wid)->where('status', 'pending')->count(),
                'accepted' => ContentSuggestion::where('workspace_id', $wid)->where('status', 'accepted')->count(),
                'this_week' => ContentSuggestion::where('workspace_id', $wid)
                    ->where('created_at', '>=', now()->subWeek())
                    ->count(),
            ],
            'spy' => [
                'detections_this_week' => SpyDetection::where('workspace_id', $wid)
                    ->where('created_at', '>=', now()->subWeek())
                    ->count(),
                'detections_today' => SpyDetection::where('workspace_id', $wid)
                    ->where('created_at', '>=', now()->startOfDay())
                    ->count(),
            ],
            'credits' => [
                'balance'   => $workspace->credits_balance,
                'reserved'  => $workspace->credits_reserved,
                'available' => $workspace->available_credits,
            ],
            'ai_costs' => [
                'this_month_usd' => TokenUsageLog::where('workspace_id', $wid)
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->sum('cost_usd'),
                'this_month_tokens' => TokenUsageLog::where('workspace_id', $wid)
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->sum('total_tokens'),
            ],
        ];
    }

    /**
     * Token usage breakdown by model and provider.
     * For the admin billing dashboard.
     */
    public function getTokenUsageBreakdown(Workspace $workspace, string $period = '30d'): array
    {
        $since = match ($period) {
            '7d'  => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => now()->subDays(30),
        };

        $byProvider = TokenUsageLog::where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $since)
            ->groupBy('provider')
            ->select('provider', DB::raw('SUM(cost_usd) as total_cost'), DB::raw('SUM(total_tokens) as total_tokens'), DB::raw('COUNT(*) as calls'))
            ->get();

        $byModel = TokenUsageLog::where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $since)
            ->groupBy('model')
            ->select('model', 'provider', DB::raw('SUM(cost_usd) as total_cost'), DB::raw('SUM(total_tokens) as total_tokens'))
            ->orderByDesc('total_cost')
            ->limit(10)
            ->get();

        $daily = TokenUsageLog::where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $since)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(cost_usd) as cost'), DB::raw('SUM(total_tokens) as tokens'))
            ->orderBy('date')
            ->get();

        return compact('byProvider', 'byModel', 'daily');
    }

    /**
     * Article performance metrics.
     */
    public function getArticleMetrics(Workspace $workspace, ?int $siteId = null): array
    {
        $query = Article::where('workspace_id', $workspace->id);
        if ($siteId) $query->where('site_id', $siteId);

        $avgWordCount = $query->clone()->where('publish_status', 'published')->avg('word_count');
        $avgCost      = $query->clone()->where('publish_status', 'published')->avg('total_cost_usd');

        $byStatus = $query->clone()
            ->groupBy('generation_status')
            ->select('generation_status', DB::raw('COUNT(*) as count'))
            ->pluck('count', 'generation_status');

        return [
            'avg_word_count'        => round($avgWordCount ?? 0),
            'avg_cost_usd'          => round($avgCost ?? 0, 4),
            'by_generation_status'  => $byStatus,
        ];
    }

    /**
     * Spy engine performance metrics.
     */
    public function getSpyMetrics(Workspace $workspace, string $period = '30d'): array
    {
        $since = now()->subDays((int) $period);

        $detectionsByMethod = SpyDetection::where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $since)
            ->groupBy('detection_method')
            ->select('detection_method', DB::raw('COUNT(*) as count'))
            ->pluck('count', 'detection_method');

        $topCompetitors = SpyDetection::where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $since)
            ->groupBy('competitor_id')
            ->select('competitor_id', DB::raw('COUNT(*) as count'))
            ->orderByDesc('count')
            ->limit(5)
            ->with('competitor:id,name,domain')
            ->get();

        return [
            'detections_by_method' => $detectionsByMethod,
            'top_competitors'      => $topCompetitors,
        ];
    }
}
