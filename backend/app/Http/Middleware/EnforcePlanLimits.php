<?php
namespace App\Http\Middleware;

use App\Models\Site;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforcePlanLimits
{
    public function handle(Request $request, Closure $next, string $resource): Response
    {
        $workspaceId = (int) $request->route('workspace');
        $workspace = Workspace::findOrFail($workspaceId);
        $plan = $workspace->plan;

        $limits = config("contentspy.plans.{$plan}");

        switch ($resource) {
            case 'site':
                if ($request->isMethod('POST')) {
                    $maxSites = $limits['max_sites'] ?? null;
                    if ($maxSites !== null) {
                        $current = Site::where('workspace_id', $workspaceId)->count();
                        if ($current >= $maxSites) {
                            return $this->limitResponse("You've reached the site limit ({$maxSites}) for your {$plan} plan.", $plan);
                        }
                    }
                }
                break;

            case 'competitor':
                if ($request->isMethod('POST')) {
                    $siteId = $request->route('site')?->id ?? (int) $request->route('site');
                    $maxCompetitors = $limits['max_competitors_per_site'] ?? null;
                    if ($maxCompetitors !== null && $siteId) {
                        $current = \App\Models\Competitor::where('site_id', $siteId)->count();
                        if ($current >= $maxCompetitors) {
                            return $this->limitResponse("You've reached the competitor limit ({$maxCompetitors}/site) for your {$plan} plan.", $plan);
                        }
                    }
                }
                break;

            case 'autopilot':
                if (!($limits['full_autopilot'] ?? false)) {
                    return $this->limitResponse('Full autopilot requires a Pro or Agency plan.', $plan);
                }
                break;

            case 'white_label':
                if (!($limits['white_label'] ?? false)) {
                    return $this->limitResponse('White-label features require an Agency plan.', $plan);
                }
                break;
        }

        return $next($request);
    }

    private function limitResponse(string $message, string $currentPlan): Response
    {
        return response()->json([
            'message'      => $message,
            'current_plan' => $currentPlan,
            'upgrade_url'  => config('app.url') . '/billing/upgrade',
        ], 402);
    }
}
