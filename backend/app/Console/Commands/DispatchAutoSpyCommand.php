<?php
namespace App\Console\Commands;

use App\Jobs\Spy\RunSpyMethodJob;
use App\Models\Competitor;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchAutoSpyCommand extends Command
{
    protected $signature   = 'contentspy:auto-spy {--dry-run : Show what would be dispatched without actually dispatching}';
    protected $description = 'Dispatch spy jobs for all competitors with auto_spy=true whose scan interval has elapsed';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $dispatched = 0;
        $skipped = 0;

        // Load all auto-spy competitors with their sites and workspaces
        $competitors = Competitor::with('site', 'workspace')
            ->where('auto_spy', true)
            ->where('is_active', true)
            ->get();

        $this->info("Found {$competitors->count()} auto-spy competitors.");

        foreach ($competitors as $competitor) {
            $workspace = $competitor->workspace;
            if (!$workspace || !$workspace->is_active) {
                $skipped++;
                continue;
            }

            // Skip workspaces below minimum credits
            $available = $workspace->credits_balance - $workspace->credits_reserved;
            if ($available < config('credits.min_balance_for_auto_spy', 50)) {
                $this->line("  [SKIP] Competitor #{$competitor->id} — workspace below min credits ({$available})");
                $skipped++;
                continue;
            }

            // Check if scan interval has elapsed
            $intervalMinutes = $competitor->auto_spy_interval ?? 60;
            $nextScanDue = $competitor->last_scanned_at
                ? $competitor->last_scanned_at->addMinutes($intervalMinutes)
                : now()->subMinute();

            if ($nextScanDue->isFuture()) {
                $skipped++;
                continue;
            }

            $methods = $competitor->active_methods ?? [];
            foreach ($methods as $method) {
                if ($dryRun) {
                    $this->line("  [DRY] Would dispatch: competitor=#{$competitor->id} method={$method}");
                } else {
                    RunSpyMethodJob::dispatch($competitor->id, $method)->onQueue('spy');
                    $dispatched++;
                    Log::info("[AutoSpy] Dispatched: competitor=#{$competitor->id} method={$method}");
                }
            }
        }

        $this->info($dryRun
            ? "Dry run complete. Would have dispatched jobs for {$competitors->count()} competitors."
            : "Done. Dispatched: {$dispatched} jobs. Skipped: {$skipped} competitors."
        );

        return self::SUCCESS;
    }
}
