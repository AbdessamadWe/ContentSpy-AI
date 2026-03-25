<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Model Pricing Table (USD per 1M tokens)
    |--------------------------------------------------------------------------
    | IMPORTANT: OpenRouter pricing is fetched live from their /models endpoint
    | and cached in Redis (1h TTL). Never hardcode OpenRouter prices here.
    |
    | Image pricing is per-image, not per-token.
    | Video/TTS pricing is per character or per second.
    |--------------------------------------------------------------------------
    */

    'providers' => [

        'openai' => [
            'label'   => 'OpenAI',
            'models'  => [
                'gpt-4o' => [
                    'label'           => 'GPT-4o',
                    'input_per_1m'    => 5.00,
                    'output_per_1m'   => 15.00,
                    'max_tokens'      => 128000,
                    'supports_vision' => true,
                ],
                'gpt-4-turbo' => [
                    'label'           => 'GPT-4 Turbo',
                    'input_per_1m'    => 10.00,
                    'output_per_1m'   => 30.00,
                    'max_tokens'      => 128000,
                    'supports_vision' => true,
                ],
                'gpt-3.5-turbo' => [
                    'label'           => 'GPT-3.5 Turbo',
                    'input_per_1m'    => 0.50,
                    'output_per_1m'   => 1.50,
                    'max_tokens'      => 16385,
                    'supports_vision' => false,
                ],
            ],
        ],

        'anthropic' => [
            'label'  => 'Anthropic',
            'models' => [
                'claude-3-5-sonnet' => [
                    'label'           => 'Claude 3.5 Sonnet',
                    'input_per_1m'    => 3.00,
                    'output_per_1m'   => 15.00,
                    'max_tokens'      => 200000,
                    'supports_vision' => true,
                ],
                'claude-3-opus' => [
                    'label'           => 'Claude 3 Opus',
                    'input_per_1m'    => 15.00,
                    'output_per_1m'   => 75.00,
                    'max_tokens'      => 200000,
                    'supports_vision' => true,
                ],
            ],
        ],

        // OpenRouter prices fetched live from https://openrouter.ai/api/v1/models
        // Stored in Redis key: openrouter:model_pricing (TTL 3600s)
        'openrouter' => [
            'label'         => 'OpenRouter',
            'api_base'      => 'https://openrouter.ai/api/v1',
            'pricing_ttl'   => 3600,
            'models'        => [], // populated dynamically from API
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Image Generation Pricing (per image)
    |--------------------------------------------------------------------------
    */
    'image' => [
        'dalle-3' => [
            'label'         => 'DALL-E 3',
            'provider'      => 'openai',
            'price_1024'    => 0.040,   // USD per 1024x1024
            'price_hd'      => 0.080,   // USD per 1024x1792 (HD)
        ],
        'stable-diffusion-xl' => [
            'label'    => 'Stable Diffusion XL',
            'provider' => 'replicate',
            // price fetched from Replicate API response per prediction
        ],
        'midjourney' => [
            'label'    => 'Midjourney',
            'provider' => 'discord',
            // unofficial — no stable API pricing, user provides own token
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Text-to-Speech Pricing
    |--------------------------------------------------------------------------
    */
    'tts' => [
        'elevenlabs' => [
            'label'             => 'ElevenLabs',
            'price_per_1k_chars' => null,  // varies by plan — fetch from API
        ],
        'openai-tts' => [
            'label'              => 'OpenAI TTS',
            'price_per_1k_chars' => 0.015,  // USD (TTS-1)
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Fallback Chain per Content Type
    |--------------------------------------------------------------------------
    */
    'fallback_chains' => [
        'text' => ['gpt-4o', 'claude-3-5-sonnet', 'gpt-3.5-turbo'],
        'image' => ['dalle-3', 'stable-diffusion-xl'],
        'tts'  => ['elevenlabs', 'openai-tts'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Helper: cost_usd calculation
    |--------------------------------------------------------------------------
    | Use App\Services\AI\TokenCostCalculator::calculate($model, $promptTokens, $completionTokens)
    */

];
