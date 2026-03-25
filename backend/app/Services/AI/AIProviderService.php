<?php
namespace App\Services\AI;

use App\Models\TokenUsageLog;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Unified AI text generation service with automatic fallback chain.
 * Records token usage for EVERY call.
 */
class AIProviderService
{
    public function __construct(
        private TokenCostCalculator $calculator
    ) {}

    /**
     * Generate text with fallback chain.
     * Tries primary model first, then secondary, then tertiary.
     *
     * @param array $messages   OpenAI-format messages array
     * @param string $model     Primary model to use
     * @param array $context    ['workspace_id', 'user_id', 'site_id', 'action_type', 'article_id']
     * @param int $maxTokens
     * @return array ['content' => string, 'model_used' => string, 'usage' => array]
     */
    public function generate(array $messages, string $model, array $context, int $maxTokens = 4096): array
    {
        $chain = $this->buildFallbackChain($model);

        foreach ($chain as $currentModel) {
            try {
                $result = $this->callModel($currentModel, $messages, $maxTokens);
                $this->recordUsage($currentModel, $result['usage'], $context, $result['cost_usd']);
                return [
                    'content'    => $result['content'],
                    'model_used' => $currentModel,
                    'usage'      => $result['usage'],
                    'cost_usd'   => $result['cost_usd'],
                ];
            } catch (\Throwable $e) {
                Log::warning("[AI] Model {$currentModel} failed: " . $e->getMessage() . " — trying next in chain");
            }
        }

        throw new \RuntimeException("All models in fallback chain failed.");
    }

    private function buildFallbackChain(string $primaryModel): array
    {
        $defaultChain = config('ai-models.fallback_chains.text', ['gpt-4o', 'claude-3-5-sonnet', 'gpt-3.5-turbo']);
        // Put primary first, then add others from default chain that aren't already in list
        $chain = [$primaryModel];
        foreach ($defaultChain as $m) {
            if ($m !== $primaryModel) $chain[] = $m;
        }
        return array_unique($chain);
    }

    private function callModel(string $model, array $messages, int $maxTokens): array
    {
        $provider = TokenCostCalculator::detectProvider($model);

        return match($provider) {
            'openai'    => $this->callOpenAI($model, $messages, $maxTokens),
            'anthropic' => $this->callAnthropic($model, $messages, $maxTokens),
            default     => $this->callOpenRouter($model, $messages, $maxTokens),
        };
    }

    private function callOpenAI(string $model, array $messages, int $maxTokens): array
    {
        $response = Http::withToken(config('services.openai.key'))
            ->timeout(120)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'      => $model,
                'messages'   => $messages,
                'max_tokens' => $maxTokens,
            ])->throw()->json();

        $usage = $response['usage'];
        $costUsd = TokenCostCalculator::calculate($model, $usage['prompt_tokens'], $usage['completion_tokens']);

        return [
            'content'  => $response['choices'][0]['message']['content'],
            'usage'    => ['prompt_tokens' => $usage['prompt_tokens'], 'completion_tokens' => $usage['completion_tokens']],
            'cost_usd' => $costUsd,
        ];
    }

    private function callAnthropic(string $model, array $messages, int $maxTokens): array
    {
        // Convert OpenAI message format to Anthropic format
        $system = collect($messages)->where('role', 'system')->pluck('content')->first() ?? '';
        $userMessages = collect($messages)->where('role', '!=', 'system')
            ->map(fn($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->values()
            ->toArray();

        $response = Http::withHeaders([
            'x-api-key'         => config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(120)
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => $maxTokens,
                'system'     => $system,
                'messages'   => $userMessages,
            ])->throw()->json();

        $inputTokens  = $response['usage']['input_tokens'];
        $outputTokens = $response['usage']['output_tokens'];
        $costUsd = TokenCostCalculator::calculate($model, $inputTokens, $outputTokens);

        return [
            'content'  => $response['content'][0]['text'],
            'usage'    => ['prompt_tokens' => $inputTokens, 'completion_tokens' => $outputTokens],
            'cost_usd' => $costUsd,
        ];
    }

    private function callOpenRouter(string $model, array $messages, int $maxTokens): array
    {
        $response = Http::withToken(config('services.openrouter.key'))
            ->withHeaders(['HTTP-Referer' => config('app.url')])
            ->timeout(120)
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model'      => $model,
                'messages'   => $messages,
                'max_tokens' => $maxTokens,
            ])->throw()->json();

        $usage = $response['usage'];
        $costUsd = TokenCostCalculator::calculate($model, $usage['prompt_tokens'], $usage['completion_tokens']);

        return [
            'content'  => $response['choices'][0]['message']['content'],
            'usage'    => ['prompt_tokens' => $usage['prompt_tokens'], 'completion_tokens' => $usage['completion_tokens']],
            'cost_usd' => $costUsd,
        ];
    }

    private function recordUsage(string $model, array $usage, array $context, float $costUsd): void
    {
        TokenCostCalculator::record([
            'workspace_id'      => $context['workspace_id'],
            'user_id'           => $context['user_id'] ?? null,
            'site_id'           => $context['site_id'] ?? null,
            'action_type'       => $context['action_type'],
            'model'             => $model,
            'provider'          => TokenCostCalculator::detectProvider($model),
            'prompt_tokens'     => $usage['prompt_tokens'],
            'completion_tokens' => $usage['completion_tokens'],
            'cost_usd'          => $costUsd,
            'credits_consumed'  => $context['credits_consumed'] ?? 0,
            'article_id'        => $context['article_id'] ?? null,
            'job_id'            => $context['job_id'] ?? null,
        ]);
    }
}
