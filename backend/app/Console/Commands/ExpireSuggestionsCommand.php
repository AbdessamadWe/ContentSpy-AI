<?php
namespace App\Console\Commands;

use App\Models\ContentSuggestion;
use Illuminate\Console\Command;

class ExpireSuggestionsCommand extends Command
{
    protected $signature   = 'contentspy:expire-suggestions';
    protected $description = 'Expire content suggestions that have passed their expires_at date';

    public function handle(): int
    {
        $expired = ContentSuggestion::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        $this->info("Expired {$expired} suggestions.");
        return self::SUCCESS;
    }
}
