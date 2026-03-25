<?php
namespace App\Console\Commands;

use App\Models\TokenUsageLog;
use Illuminate\Console\Command;

class PurgeLogsCommand extends Command
{
    protected $signature   = 'contentspy:purge-logs {--days=90 : Delete logs older than this many days}';
    protected $description = 'Purge old token usage logs to control database growth';

    public function handle(): int
    {
        $days    = (int) $this->option('days');
        $cutoff  = now()->subDays($days);
        $deleted = TokenUsageLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Purged {$deleted} token usage log entries older than {$days} days.");
        return self::SUCCESS;
    }
}
