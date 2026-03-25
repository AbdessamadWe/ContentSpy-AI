<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryFailedCommand extends Command
{
    protected $signature = 'contentspy:retry-failed';
    protected $description = 'Retry failed social posts and failed articles';

    public function handle(): int
    {
        $this->info('Checking for failed jobs to retry...');
        
        // Retry failed social posts (max 3 retries)
        $failedPosts = DB::table('social_posts')
            ->where('status', 'failed')
            ->where('retry_count', '<', 3)
            ->get();
        
        foreach ($failedPosts as $post) {
            $this->line("Retrying social post #{$post->id}");
            
            // Re-dispatch based on platform
            $jobClass = match($post->platform) {
                'facebook' => \App\Jobs\Social\PublishToFacebookJob::class,
                'instagram' => \App\Jobs\Social\PublishToInstagramJob::class,
                'tiktok' => \App\Jobs\Social\PublishToTikTokJob::class,
                'pinterest' => \App\Jobs\Social\PublishToPinterestJob::class,
                default => null,
            };
            
            if ($jobClass) {
                $article = \App\Models\Article::find($post->article_id);
                $account = \App\Models\SocialAccount::find($post->social_account_id);
                
                if ($article && $account) {
                    dispatch(new $jobClass($article, $account));
                }
            }
        }
        
        $this->info("Processed {$failedPosts->count()} failed posts.");
        
        return Command::SUCCESS;
    }
}