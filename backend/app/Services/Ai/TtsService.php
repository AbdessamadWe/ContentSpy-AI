<?php
namespace App\Services\Ai;

use App\Models\TokenUsageLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TtsService
{
    /**
     * Generate TTS audio from text. Returns {audio_url, duration_seconds, cost_usd, chars_count}.
     * Fallback: ElevenLabs → OpenAI TTS.
     */
    public function generate(string $text, string $voice = 'alloy', array $context = []): array
    {
        $charsCount = strlen($text);

        try {
            if (config('services.elevenlabs.key')) {
                return $this->generateElevenLabs($text, $voice, $charsCount, $context);
            }
        } catch (\Throwable $e) {
            Log::warning("[TTS] ElevenLabs failed: {$e->getMessage()} — falling back to OpenAI TTS");
        }

        return $this->generateOpenAiTts($text, $voice, $charsCount, $context);
    }

    private function generateElevenLabs(string $text, string $voice, int $charsCount, array $context): array
    {
        // ElevenLabs voice IDs (default to Rachel)
        $voiceId = config("services.elevenlabs.voices.{$voice}", '21m00Tcm4TlvDq8ikWAM');

        $response = Http::withToken(config('services.elevenlabs.key'))
            ->timeout(60)
            ->post("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}", [
                'text'           => $text,
                'model_id'       => 'eleven_monolingual_v1',
                'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.75],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("ElevenLabs TTS failed: HTTP {$response->status()}");
        }

        $r2Key = 'audio/' . Str::uuid() . '.mp3';
        Storage::disk('r2')->put($r2Key, $response->body());
        $audioUrl = Storage::disk('r2')->url($r2Key);

        // ElevenLabs: ~$0.30 per 1000 chars
        $costUsd = round(($charsCount / 1000) * 0.30, 6);
        $this->recordTtsUsage('elevenlabs', $charsCount, $costUsd, $audioUrl, $context);

        return [
            'audio_url'        => $audioUrl,
            'r2_key'           => $r2Key,
            'provider'         => 'elevenlabs',
            'chars_count'      => $charsCount,
            'cost_usd'         => $costUsd,
            'duration_seconds' => null, // ElevenLabs doesn't return duration
        ];
    }

    private function generateOpenAiTts(string $text, string $voice, int $charsCount, array $context): array
    {
        // Truncate to OpenAI TTS limit (4096 chars)
        $text = substr($text, 0, 4096);

        $response = Http::withToken(config('services.openai.key'))
            ->timeout(60)
            ->post('https://api.openai.com/v1/audio/speech', [
                'model'          => 'tts-1',
                'input'          => $text,
                'voice'          => in_array($voice, ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer']) ? $voice : 'alloy',
                'response_format' => 'mp3',
            ])->throw();

        $r2Key = 'audio/' . Str::uuid() . '.mp3';
        Storage::disk('r2')->put($r2Key, $response->body());
        $audioUrl = Storage::disk('r2')->url($r2Key);

        // OpenAI TTS-1: $0.015 per 1000 chars
        $costUsd = round(($charsCount / 1000) * 0.015, 6);
        $this->recordTtsUsage('openai', $charsCount, $costUsd, $audioUrl, $context);

        return [
            'audio_url'        => $audioUrl,
            'r2_key'           => $r2Key,
            'provider'         => 'openai-tts',
            'chars_count'      => $charsCount,
            'cost_usd'         => $costUsd,
            'duration_seconds' => null,
        ];
    }

    private function recordTtsUsage(string $provider, int $chars, float $costUsd, string $audioUrl, array $context): void
    {
        if (empty($context['workspace_id'])) return;
        try {
            TokenUsageLog::create([
                'workspace_id'     => $context['workspace_id'],
                'user_id'          => $context['user_id'] ?? null,
                'site_id'          => $context['site_id'] ?? null,
                'action_type'      => 'tts_per_1000_chars',
                'model'            => $provider === 'elevenlabs' ? 'eleven_monolingual_v1' : 'tts-1',
                'provider'         => $provider === 'elevenlabs' ? 'elevenlabs' : 'openai',
                'cost_usd'         => $costUsd,
                'credits_consumed' => $context['credits_consumed'] ?? 0,
                'article_id'       => $context['article_id'] ?? null,
                'metadata'         => ['chars_count' => $chars, 'audio_url' => $audioUrl],
            ]);
        } catch (\Throwable $e) {
            Log::error("[TTS] Failed to record usage: {$e->getMessage()}");
        }
    }
}
