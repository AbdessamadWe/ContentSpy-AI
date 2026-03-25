<?php
namespace App\Services\Ai\Contracts;

use App\Services\Ai\AiResponse;

interface AiProviderInterface
{
    /**
     * Generate text from a messages array (OpenAI chat format).
     *
     * @param array  $messages   [['role' => 'user', 'content' => '...']]
     * @param string $model      Full model identifier
     * @param int    $maxTokens
     * @return AiResponse
     */
    public function generate(array $messages, string $model, int $maxTokens): AiResponse;

    /** True if this provider supports the given model string */
    public function supports(string $model): bool;

    /** Provider identifier string (openai, anthropic, openrouter) */
    public function name(): string;
}
