<?php
namespace App\Jobs\Publishing;

use App\Models\Article;
use App\Models\SocialAccount;
use App\Services\Social\SocialPublisherOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishToSocialJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly int $articleId,
        private readonly int $socialAccountId,
    ) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(SocialPublisherOrchestrator $orchestrator): void
    {
        $article = Article::find($this->articleId);
        $account = SocialAccount::find($this->socialAccountId);

        if (! $article || ! $account) {
            Log::warning("[PublishToSocialJob] Article or account not found", [
                'article_id'  => $this->articleId,
                'account_id'  => $this->socialAccountId,
            ]);
            return;
        }

        if (! $account->is_active) {
            Log::info("[PublishToSocialJob] Skipping inactive account #{$this->socialAccountId}");
            return;
        }

        $result = $orchestrator->publishToAccount($article, $account);

        Log::info("[PublishToSocialJob] Result for article #{$this->articleId} on {$account->platform}", $result);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[PublishToSocialJob] Failed after all retries", [
            'article_id' => $this->articleId,
            'account_id' => $this->socialAccountId,
            'error'      => $e->getMessage(),
        ]);
    }
}
