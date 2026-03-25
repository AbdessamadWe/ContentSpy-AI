<?php
namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TokenPricingService
{
    /**
     * Calculate cost_usd for a text generation call.
     * NEVER hardcode prices — read from config or live OpenRouter API.
     */
    public function calculate(string $model, int $promptTokens, int $completionTokens): float
    {
        $pricing = $this->getPricing($model);
        if (!$pricing) {
            Log::warning("[TokenPricing] No pricing found for model: {$model}");
            return 0.0;
        }

        $inputCost  = ($promptTokens / 1_000_000) * ($pricing['input_per_1m'] ?? 0);
        $outputCost = ($completionTokens / 1_000_000) * ($pricing['output_per_1m'] ?? 0);
        return round($inputCost + $outputCost, 6);
    }

    public function calculateImage(string $provider, string $size = '1024x1024'): float
    {
        return match(true) {
            $provider === 'dalle-3' && $size === '1024x1024' => 0.040,
            $provider === 'dalle-3' => 0.080,
            default => 0.0,
        };
    }

    private function getPricing(string $model): ?array
    {
        // Static config lookup first
        foreach (config('ai-models.providers', []) as $info) {
            if (isset($info['models'][$model])) {
                return $info['models'][$model];
            }
        }
        // OpenRouter live pricing (model IDs contain /)
        if (str_contains($model, '/')) {
            $all = $this->fetchOpenRouterPricing();
            return $all[$model] ?? null;
        }
        return null;
    }

    /** Cached 1h — NEVER hardcode OpenRouter prices */
    public function fetchOpenRouterPricing(): array
    {
        return Cache::remember('openrouter:model_pricing', 3600, function () {
            $key = config('services.openrouter.key') ?? env('OPENROUTER_API_KEY');
            if (!$key) return [];
            try {
                $data = Http::withToken($key)->timeout(10)
                    ->get('https://openrouter.ai/api/v1/models')
                    ->throw()->json('data', []);
                $result = [];
                foreach ($data as $m) {
                    $result[$m['id']] = [
                        'input_per_1m'  => (float) ($m['pricing']['prompt'] ?? 0) * 1_000_000,
                        'output_per_1m' => (float) ($m['pricing']['completion'] ?? 0) * 1_000_000,
                        'max_tokens'    => $m['context_length'] ?? 4096,
                    ];
                }
                return $result;
            } catch (\Throwable $e) {
                Log::warning("[OpenRouter pricing] fetch failed: {$e->getMessage()}");
                return [];
            }
        });
    }
}
