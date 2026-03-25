<?php
namespace App\Services\Ai;

readonly class AiResponse
{
    public function __construct(
        public string $text,
        public string $model,
        public string $provider,
        public int    $promptTokens,
        public int    $completionTokens,
        public float  $costUsd,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function toArray(): array
    {
        return [
            'text'              => $this->text,
            'model'             => $this->model,
            'provider'          => $this->provider,
            'prompt_tokens'     => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens'      => $this->totalTokens(),
            'cost_usd'          => $this->costUsd,
        ];
    }
}
