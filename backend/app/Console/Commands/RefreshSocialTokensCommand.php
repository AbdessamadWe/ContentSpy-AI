<?php

namespace App\Console\Commands;

use App\Services\Social\TokenRefreshService;
use Illuminate\Console\Command;

class RefreshSocialTokensCommand extends Command
{
    protected $signature = 'contentspy:refresh-social-tokens';
    protected $description = 'Refresh expiring social media tokens daily at 03:00';

    public function __construct(
        private readonly TokenRefreshService $tokenRefreshService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Refreshing social media tokens...');
        
        $refreshed = $this->tokenRefreshService->refreshAll();
        
        $this->info("Refreshed {$refreshed} accounts.");
        
        return Command::SUCCESS;
    }
}