<?php
namespace App\Services\AI;

use App\Models\TokenUsageLog;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TokenCostCalculator
{
    /**
     * Calculate cost in USD for a text generation call.
     * NEVER hardcode model prices — always read from config or live API.
     */
    public static function calculate(string $model, int $promptTokens, int $completionTokens): float
    {
        $pricing = self::getPricing($model);

        if (!$pricing) {
            Log::warning("[TokenCost] Unknown model pricing: {$model} — cost recorded as 0");
            return 0.0;
        }

        $inputCost  = ($promptTokens / 1_000_000) * ($pricing['input_per_1m'] ?? 0);
        $outputCost = ($completionTokens / 1_000_000) * ($pricing['output_per_1m'] ?? 0);

        return round($inputCost + $outputCost, 6);
    }

    /**
     * Calculate cost for image generation.
     */
    public static function calculateImageCost(string $provider, string $resolution = '1024x1024'): float
    {
        return match($provider) {
            'dalle-3', 'dalle' => $resolution === '1024x1024' ? 0.040 : 0.080,
            'midjourney'       => 0.0, // user's own token — not tracked as our cost
            default            => 0.0, // Replicate: fetch from API response
        };
    }

    /**
     * Record a token usage log entry. MUST be called for EVERY AI API call.
     */
    public static function record(array $data): TokenUsageLog
    {
        return TokenUsageLog::create([
            'workspace_id'      => $data['workspace_id'],
            'user_id'           => $data['user_id'] ?? null,
            'site_id'           => $data['site_id'] ?? null,
            'action_type'       => $data['action_type'],
            'model'             => $data['model'],
            'provider'          => $data['provider'],
            'prompt_tokens'     => $data['prompt_tokens'] ?? 0,
            'completion_tokens' => $data['completion_tokens'] ?? 0,
            'total_tokens'      => ($data['prompt_tokens'] ?? 0) + ($data['completion_tokens'] ?? 0),
            'images_count'      => $data['images_count'] ?? 0,
            'video_seconds'     => $data['video_seconds'] ?? 0,
            'cost_usd'          => $data['cost_usd'] ?? 0,
            'credits_consumed'  => $data['credits_consumed'] ?? 0,
            'article_id'        => $data['article_id'] ?? null,
            'job_id'            => $data['job_id'] ?? null,
            'request_id'        => $data['request_id'] ?? null,
            'metadata'          => $data['metadata'] ?? null,
        ]);
    }

    /**
     * Get pricing for a model. Tries config first, then OpenRouter live API.
     */
    private static function getPricing(string $model): ?array
    {
        // Check static config first
        foreach (config('ai-models.providers', []) as $provider => $info) {
            if (isset($info['models'][$model])) {
                return $info['models'][$model];
            }
        }

        // Try OpenRouter live pricing
        if (str_contains($model, '/')) { // OpenRouter models use "provider/model" format
            return self::getOpenRouterPricing($model);
        }

        return null;
    }

    /**
     * Fetch live pricing from OpenRouter. Cached for 1 hour.
     * NEVER hardcode OpenRouter prices — they change frequently.
     */
    private static function getOpenRouterPricing(string $model): ?array
    {
        $cacheKey = 'openrouter:model_pricing';

        $allPricing = Cache::remember($cacheKey, 3600, function () {
            $apiKey = config('services.openrouter.key') ?? env('OPENROUTER_API_KEY');
            if (!$apiKey) return [];

            try {
                $response = Http::withToken($apiKey)
                    ->timeout(10)
                    ->get('https://openrouter.ai/api/v1/models');

                if (!$response->successful()) return [];

                $models = [];
                foreach ($response->json('data', []) as $m) {
                    $models[$m['id']] = [
                        'input_per_1m'  => (float) ($m['pricing']['prompt'] ?? 0) * 1_000_000,
                        'output_per_1m' => (float) ($m['pricing']['completion'] ?? 0) * 1_000_000,
                        'max_tokens'    => $m['context_length'] ?? 4096,
                    ];
                }
                return $models;
            } catch (\Throwable $e) {
                Log::warning("[OpenRouter] Failed to fetch model pricing: " . $e->getMessage());
                return [];
            }
        });

        return $allPricing[$model] ?? null;
    }

    /**
     * Determine provider from model string.
     */
    public static function detectProvider(string $model): string
    {
        if (str_starts_with($model, 'gpt-') || str_starts_with($model, 'dall-e')) return 'openai';
        if (str_starts_with($model, 'claude-')) return 'anthropic';
        if (str_starts_with($model, 'stable-diffusion')) return 'stability';
        if ($model === 'midjourney') return 'midjourney';
        if (str_contains($model, '/')) return 'openrouter';
        return 'openrouter';
    }
}
