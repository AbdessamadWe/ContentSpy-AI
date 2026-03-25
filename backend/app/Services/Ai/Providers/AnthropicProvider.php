<?php
namespace App\Services\Ai\Providers;

use App\Services\Ai\AiResponse;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\TokenPricingService;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements AiProviderInterface
{
    public function __construct(private TokenPricingService $pricing) {}

    public function name(): string { return 'anthropic'; }

    public function supports(string $model): bool
    {
        return str_starts_with($model, 'claude-');
    }

    public function generate(array $messages, string $model, int $maxTokens): AiResponse
    {
        // Extract system message; Anthropic uses separate system param
        $system = '';
        $userMessages = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system = $msg['content'];
            } else {
                $userMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
        }

        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $userMessages,
        ];
        if ($system) $payload['system'] = $system;

        $response = Http::withHeaders([
            'x-api-key'         => config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', $payload)->throw()->json();

        $promptTokens     = $response['usage']['input_tokens'];
        $completionTokens = $response['usage']['output_tokens'];
        $costUsd = $this->pricing->calculate($model, $promptTokens, $completionTokens);

        return new AiResponse(
            text:             $response['content'][0]['text'],
            model:            $model,
            provider:         'anthropic',
            promptTokens:     $promptTokens,
            completionTokens: $completionTokens,
            costUsd:          $costUsd,
        );
    }
}
