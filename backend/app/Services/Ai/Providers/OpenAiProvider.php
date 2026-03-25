<?php
namespace App\Services\Ai\Providers;

use App\Services\Ai\AiResponse;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\TokenPricingService;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements AiProviderInterface
{
    public function __construct(private TokenPricingService $pricing) {}

    public function name(): string { return 'openai'; }

    public function supports(string $model): bool
    {
        return str_starts_with($model, 'gpt-') || str_starts_with($model, 'o1');
    }

    public function generate(array $messages, string $model, int $maxTokens): AiResponse
    {
        $response = Http::withToken(config('services.openai.key'))
            ->timeout(120)
            ->post('https://api.openai.com/v1/chat/completions', [
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
            provider:         'openai',
            promptTokens:     $promptTokens,
            completionTokens: $completionTokens,
            costUsd:          $costUsd,
        );
    }
}
