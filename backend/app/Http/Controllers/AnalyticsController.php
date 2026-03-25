<?php
namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\ContentSuggestion;
use App\Models\SpyDetection;
use App\Models\TokenUsageLog;
use App\Models\Workspace;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function overview(Request $request, int $workspace): JsonResponse
    {
        $ws = Workspace::findOrFail($workspace);
        return response()->json($this->analytics->getDashboardStats($ws));
    }

    public function articles(Request $request, int $workspace): JsonResponse
    {
        $ws     = Workspace::findOrFail($workspace);
        $siteId = $request->integer('site_id') ?: null;
        return response()->json($this->analytics->getArticleMetrics($ws, $siteId));
    }

    public function spy(Request $request, int $workspace): JsonResponse
    {
        $ws     = Workspace::findOrFail($workspace);
        $period = $request->get('period', '30d');
        return response()->json($this->analytics->getSpyMetrics($ws, $period));
    }

    public function overviewLegacy(Request $request, int $workspace): JsonResponse
    {
        $period = $request->get('period', '30'); // days
        $from = now()->subDays((int) $period)->startOfDay();

        return response()->json([
            'detections' => SpyDetection::where('workspace_id', $workspace)
                ->where('created_at', '>=', $from)
                ->count(),
            'suggestions' => ContentSuggestion::where('workspace_id', $workspace)
                ->where('created_at', '>=', $from)
                ->count(),
            'articles_generated' => Article::where('workspace_id', $workspace)
                ->where('created_at', '>=', $from)
                ->whereNotIn('generation_status', ['pending', 'failed'])
                ->count(),
            'articles_published' => Article::where('workspace_id', $workspace)
                ->where('publish_status', 'published')
                ->where('wp_published_at', '>=', $from)
                ->count(),
            'total_cost_usd' => TokenUsageLog::where('workspace_id', $workspace)
                ->where('created_at', '>=', $from)
                ->sum('cost_usd'),
        ]);
    }

    public function tokenUsage(Request $request, int $workspace): JsonResponse
    {
        $period = $request->get('period', '30');
        $from = now()->subDays((int) $period)->startOfDay();

        $byModel = TokenUsageLog::where('workspace_id', $workspace)
            ->where('created_at', '>=', $from)
            ->selectRaw('model, provider, SUM(prompt_tokens) as prompt_tokens, SUM(completion_tokens) as completion_tokens, SUM(total_tokens) as total_tokens, SUM(cost_usd) as cost_usd, COUNT(*) as calls')
            ->groupBy('model', 'provider')
            ->orderByDesc('cost_usd')
            ->get();

        $byDay = TokenUsageLog::where('workspace_id', $workspace)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as date, SUM(cost_usd) as cost_usd, SUM(total_tokens) as total_tokens')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        return response()->json([
            'by_model' => $byModel,
            'by_day'   => $byDay,
            'totals'   => [
                'cost_usd'     => $byModel->sum('cost_usd'),
                'total_tokens' => $byModel->sum('total_tokens'),
                'total_calls'  => $byModel->sum('calls'),
            ],
        ]);
    }
}
