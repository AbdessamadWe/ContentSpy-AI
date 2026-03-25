<?php

namespace App\Console\Commands;

use App\Models\ContentSuggestion;
use App\Models\Article;
use App\Jobs\Content\GenerateArticleJob;
use Illuminate\Console\Command;

class DispatchScheduledContentCommand extends Command
{
    protected $signature = 'contentspy:dispatch-scheduled';
    protected $description = 'Dispatch scheduled content generation and publishing';

    public function handle(): int
    {
        $this->info('Checking for scheduled content...');
        
        $now = now();
        
        // Dispatch scheduled suggestions that are ready
        $suggestions = ContentSuggestion::where('status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', $now)
            ->get();
        
        $this->info("Found {$suggestions->count()} scheduled suggestions to process.");
        
        foreach ($suggestions as $suggestion) {
            $suggestion->update(['status' => 'accepted']);
            
            // Dispatch generation job
            GenerateArticleJob::dispatch($suggestion);
            
            $this->line("Dispatched generation for suggestion #{$suggestion->id}");
        }
        
        // Dispatch scheduled articles for publishing
        $articles = Article::where('publish_status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', $now)
            ->get();
        
        $this->info("Found {$articles->count()} scheduled articles to publish.");
        
        foreach ($articles as $article) {
            // Dispatch WordPress publish job
            \App\Jobs\Publishing\PublishToWordPressJob::dispatch($article);
            
            $this->line("Dispatched publishing for article #{$article->id}");
        }
        
        return Command::SUCCESS;
    }
}