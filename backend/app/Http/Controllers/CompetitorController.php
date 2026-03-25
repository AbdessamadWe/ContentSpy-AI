<?php
namespace App\Http\Controllers;

use App\Models\Competitor;
use App\Models\Site;
use App\Jobs\Spy\RunAutoSpyJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompetitorController extends Controller
{
    public function index(Request $request, int $workspace, Site $site): JsonResponse
    {
        $this->ensureWorkspaceOwns($site, $workspace);
        return response()->json([
            'competitors' => $site->competitors()->orderBy('name')->paginate(20),
        ]);
    }

    public function store(Request $request, int $workspace, Site $site): JsonResponse
    {
        $this->ensureWorkspaceOwns($site, $workspace);

        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'domain'     => ['required', 'string', 'max:500'],
            'rss_url'    => ['nullable', 'url'],
            'sitemap_url' => ['nullable', 'url'],
            'blog_url'   => ['nullable', 'url'],
            'twitter_handle'   => ['nullable', 'string'],
            'instagram_handle' => ['nullable', 'string'],
            'active_methods'   => ['nullable', 'array'],
            'auto_spy'         => ['nullable', 'boolean'],
            'auto_spy_interval' => ['nullable', 'integer', 'min:15'],
            'confidence_threshold_suggest'  => ['nullable', 'integer', 'min:0', 'max:100'],
            'confidence_threshold_generate' => ['nullable', 'integer', 'min:0', 'max:100'],
            'confidence_threshold_publish'  => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $competitor = $site->competitors()->create(array_merge($validated, [
            'workspace_id' => $workspace,
        ]));

        return response()->json(['competitor' => $competitor], 201);
    }

    public function show(int $workspace, Site $site, Competitor $competitor): JsonResponse
    {
        $this->ensureWorkspaceOwns($site, $workspace);
        return response()->json(['competitor' => $competitor->load('detections')]);
    }

    public function update(Request $request, int $workspace, Site $site, Competitor $competitor): JsonResponse
    {
        $this->ensureWorkspaceOwns($site, $workspace);

        $validated = $request->validate([
            'name'            => ['sometimes', 'string', 'max:255'],
            'rss_url'         => ['nullable', 'url'],
            'sitemap_url'     => ['nullable', 'url'],
            'blog_url'        => ['nullable', 'url'],
            'active_methods'  => ['nullable', 'array'],
            'auto_spy'        => ['nullable', 'boolean'],
            'auto_spy_interval' => ['nullable', 'integer', 'min:15'],
        ]);

        $competitor->update($validated);
        return response()->json(['competitor' => $competitor->fresh()]);
    }

    public function destroy(int $workspace, Site $site, Competitor $competitor): JsonResponse
    {
        $this->ensureWorkspaceOwns($site, $workspace);
        $competitor->delete();
        return response()->json(['message' => 'Competitor deleted.']);
    }

    /** Manually trigger a spy scan */
    public function scan(Request $request, int $workspace, Site $site, Competitor $competitor): JsonResponse
    {
        $this->ensureWorkspaceOwns($site, $workspace);

        $method = $request->input('method', 'rss');
        $allowed = ['rss', 'html_scraping', 'sitemap'];

        if (!in_array($method, $allowed)) {
            return response()->json(['message' => 'Invalid spy method.'], 422);
        }

        if (!$competitor->hasMethod($method)) {
            return response()->json(['message' => "Method [{$method}] not configured for this competitor."], 422);
        }

        RunAutoSpyJob::dispatch($competitor->id, $method);

        return response()->json(['message' => "Spy scan queued for method: {$method}"]);
    }

    private function ensureWorkspaceOwns(Site $site, int $workspaceId): void
    {
        if ($site->workspace_id !== $workspaceId) {
            abort(403, 'Site does not belong to this workspace.');
        }
    }
}
