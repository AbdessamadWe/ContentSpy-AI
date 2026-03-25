<?php
namespace App\Services\Ai\Providers;

use App\Services\Ai\AiResponse;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\TokenPricingService;
use Illuminate\Support\Facades\Http;

class OpenRouterProvider implements AiProviderInterface
{
    public function __construct(private TokenPricingService $pricing) {}

    public function name(): string { return 'openrouter'; }

    public function supports(string $model): bool
    {
        // OpenRouter models use "provider/model" format, or are non-standard
        return str_contains($model, '/') || !str_starts_with($model, 'gpt-') && !str_starts_with($model, 'claude-');
    }

    public function generate(array $messages, string $model, int $maxTokens): AiResponse
    {
        $response = Http::withToken(config('services.openrouter.key'))
            ->withHeaders([
                'HTTP-Referer' => config('app.url'),
                'X-Title'      => 'ContentSpy AI',
            ])
            ->timeout(120)
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model'      => $model,
                'messages'   => $messages,
                'max_tokens' => $maxTokens,
            ])->throw()->json();

        $usage = $response['usage'];
        $promptTokens     = $usage['prompt_tokens'];
        $completionTokens = $usage['completion_tokens'];
        $costUsd = $this->pricing->calculate($model, $promptTokens, $completionTokens);

        return new AiResponse(
            text:             $response['choices'][0]['message']['content'],
            model:            $model,
            provider:         'openrouter',
            promptTokens:     $promptTokens,
            completionTokens: $completionTokens,
            costUsd:          $costUsd,
        );
    }
}
