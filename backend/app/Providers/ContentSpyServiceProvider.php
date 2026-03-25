<?php
namespace App\Providers;

use App\Services\Ai\AiGateway;
use App\Services\Ai\ImageGenerationGateway;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\DalleProvider;
use App\Services\Ai\Providers\MidjourneyProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Providers\OpenRouterProvider;
use App\Services\Ai\Providers\ReplicateProvider;
use App\Services\Ai\TokenPricingService;
use App\Services\Ai\TtsService;
use App\Services\Ai\VideoScriptService;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\GoogleSearchConsoleService;
use App\Services\Billing\StripeService;
use App\Services\Content\ContentPipelineOrchestrator;
use App\Services\Content\SuggestionGeneratorService;
use App\Services\Credits\CreditService;
use App\Services\Notifications\NotificationService;
use App\Services\Plugin\PluginService;
use App\Services\Publishing\WordPressPluginClient;
use App\Services\Publishing\WordPressPublisher;
use App\Services\Publishing\WordPressRestClient;
use App\Services\Social\FacebookPublisher;
use App\Services\Social\InstagramPublisher;
use App\Services\Social\PinterestPublisher;
use App\Services\Social\SocialPublisherOrchestrator;
use App\Services\Social\TikTokPublisher;
use App\Services\Spy\SpyOrchestrator;
use Illuminate\Support\ServiceProvider;

class ContentSpyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Credits ──────────────────────────────────────────────────────────
        $this->app->singleton(CreditService::class);

        // ── AI Layer ─────────────────────────────────────────────────────────
        $this->app->singleton(TokenPricingService::class);
        $this->app->singleton(OpenAiProvider::class);
        $this->app->singleton(AnthropicProvider::class);
        $this->app->singleton(OpenRouterProvider::class);

        $this->app->singleton(AiGateway::class, function ($app) {
            return new AiGateway(
                $app->make(OpenAiProvider::class),
                $app->make(AnthropicProvider::class),
                $app->make(OpenRouterProvider::class),
            );
        });

        // Image generation
        $this->app->singleton(DalleProvider::class);
        $this->app->singleton(ReplicateProvider::class);
        $this->app->singleton(MidjourneyProvider::class);

        $this->app->singleton(ImageGenerationGateway::class, function ($app) {
            return new ImageGenerationGateway(
                $app->make(MidjourneyProvider::class),
                $app->make(DalleProvider::class),
                $app->make(ReplicateProvider::class),
            );
        });

        $this->app->singleton(TtsService::class);
        $this->app->singleton(VideoScriptService::class);

        // ── Content Pipeline ──────────────────────────────────────────────────
        $this->app->singleton(ContentPipelineOrchestrator::class);
        $this->app->singleton(SuggestionGeneratorService::class);

        // ── Publishing ────────────────────────────────────────────────────────
        $this->app->singleton(WordPressPluginClient::class);
        $this->app->singleton(WordPressRestClient::class);
        $this->app->singleton(WordPressPublisher::class, function ($app) {
            return new WordPressPublisher(
                $app->make(WordPressPluginClient::class),
                $app->make(WordPressRestClient::class),
            );
        });

        // ── Social ────────────────────────────────────────────────────────────
        $this->app->singleton(FacebookPublisher::class);
        $this->app->singleton(InstagramPublisher::class);
        $this->app->singleton(TikTokPublisher::class);
        $this->app->singleton(PinterestPublisher::class);
        $this->app->singleton(SocialPublisherOrchestrator::class, function ($app) {
            return new SocialPublisherOrchestrator(
                $app->make(FacebookPublisher::class),
                $app->make(InstagramPublisher::class),
                $app->make(TikTokPublisher::class),
                $app->make(PinterestPublisher::class),
            );
        });

        // ── Analytics ─────────────────────────────────────────────────────────
        $this->app->singleton(AnalyticsService::class);
        $this->app->singleton(GoogleSearchConsoleService::class);

        // ── Billing ───────────────────────────────────────────────────────────
        $this->app->singleton(StripeService::class);

        // ── Notifications ─────────────────────────────────────────────────────
        $this->app->singleton(NotificationService::class);

        // ── Plugin ────────────────────────────────────────────────────────────
        $this->app->singleton(PluginService::class);

        // ── Spy ───────────────────────────────────────────────────────────────
        $this->app->singleton(SpyOrchestrator::class);
    }

    public function boot(): void
    {
        // Register Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\DispatchAutoSpyCommand::class,
                \App\Console\Commands\ExpireSuggestionsCommand::class,
                \App\Console\Commands\PurgeLogsCommand::class,
            ]);
        }
    }
}
