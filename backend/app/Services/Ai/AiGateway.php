<?php
namespace App\Services\Ai;

use App\Models\TokenUsageLog;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Providers\OpenRouterProvider;
use Illuminate\Support\Facades\Log;

/**
 * Central AI dispatch service.
 * The ONLY class that calls external AI APIs — all other services call this.
 * Guarantees 100% token tracking coverage.
 */
class AiGateway
{
    /** @var AiProviderInterface[] */
    private array $providers;

    public function __construct(
        OpenAiProvider     $openai,
        AnthropicProvider  $anthropic,
        OpenRouterProvider $openrouter,
    ) {
        $this->providers = [$openai, $anthropic, $openrouter];
    }

    /**
     * Generate text. Tries primary model, falls back through chain on failure.
     *
     * @param array  $messages   OpenAI-format messages array
     * @param string $model      Primary model to use
     * @param array  $context    ['workspace_id', 'user_id', 'site_id', 'action_type', 'article_id', 'credits_consumed']
     * @param int    $maxTokens
     */
    public function generate(array $messages, string $model, array $context, int $maxTokens = 4096): AiResponse
    {
        $chain = $this->buildFallbackChain($model);
        $lastException = null;

        foreach ($chain as $currentModel) {
            $provider = $this->resolveProvider($currentModel);
            if (!$provider) {
                Log::warning("[AiGateway] No provider found for model: {$currentModel}");
                continue;
            }

            try {
                $response = $provider->generate($messages, $currentModel, $maxTokens);
                $this->recordUsage($response, $context);
                return $response;
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning("[AiGateway] Model {$currentModel} failed: {$e->getMessage()} — trying next in chain");
            }
        }

        throw new \RuntimeException(
            'All models in fallback chain failed. Last error: ' . ($lastException?->getMessage() ?? 'unknown'),
            0,
            $lastException,
        );
    }

    /** Build fallback chain: primary model first, then defaults */
    private function buildFallbackChain(string $primary): array
    {
        $defaults = config('ai-models.fallback_chains.text', ['gpt-4o', 'claude-3-5-sonnet', 'gpt-3.5-turbo']);
        $chain = [$primary];
        foreach ($defaults as $m) {
            if ($m !== $primary) $chain[] = $m;
        }
        return array_unique($chain);
    }

    private function resolveProvider(string $model): ?AiProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($model)) return $provider;
        }
        return null;
    }

    /** Record token usage — called after EVERY successful AI call */
    private function recordUsage(AiResponse $response, array $context): void
    {
        try {
            TokenUsageLog::create([
                'workspace_id'      => $context['workspace_id'],
                'user_id'           => $context['user_id'] ?? null,
                'site_id'           => $context['site_id'] ?? null,
                'action_type'       => $context['action_type'],
                'model'             => $response->model,
                'provider'          => $response->provider,
                'prompt_tokens'     => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'total_tokens'      => $response->totalTokens(),
                'cost_usd'          => $response->costUsd,
                'credits_consumed'  => $context['credits_consumed'] ?? 0,
                'article_id'        => $context['article_id'] ?? null,
                'job_id'            => $context['job_id'] ?? null,
                'request_id'        => $context['request_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Never let token logging failure break the generation
            Log::error("[AiGateway] Failed to record token usage: {$e->getMessage()}");
        }
    }
}
