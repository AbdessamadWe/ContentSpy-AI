<?php
namespace App\Services\Spy;

use App\Jobs\Spy\RunSpyMethodJob;
use App\Models\Competitor;
use App\Models\Workspace;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Log;

class SpyOrchestrator
{
    public function __construct(private CreditService $credits) {}

    /**
     * Dispatch all active spy methods for a competitor as async jobs.
     * Each method runs independently on the 'spy' queue.
     */
    public function run(Competitor $competitor): void
    {
        $workspace = Workspace::find($competitor->workspace_id);

        if ($this->credits->shouldPauseAutoSpy($workspace)) {
            Log::info("[SpyOrchestrator] Paused for workspace #{$workspace->id} — below min credit threshold");
            return;
        }

        $methods = $competitor->active_methods ?? [];
        if (empty($methods)) {
            Log::warning("[SpyOrchestrator] Competitor #{$competitor->id} has no active spy methods");
            return;
        }

        foreach ($methods as $method) {
            RunSpyMethodJob::dispatch($competitor->id, $method)
                ->onQueue('spy');
        }

        Log::info("[SpyOrchestrator] Dispatched " . count($methods) . " spy jobs for competitor #{$competitor->id}");
    }

    /**
     * Run a single method synchronously (for manual triggers).
     * Returns new detections.
     */
    public function runMethod(Competitor $competitor, string $method, object $service): array
    {
        return $service->detect($competitor);
    }
}
