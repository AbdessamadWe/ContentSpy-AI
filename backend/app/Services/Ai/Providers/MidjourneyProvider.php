<?php
namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\ImageProviderInterface;
use App\Services\Ai\ImageResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MidjourneyProvider implements ImageProviderInterface
{
    private const DISCORD_API_BASE          = 'https://discord.com/api/v10';
    private const UNAVAILABLE_REDIS_KEY     = 'midjourney:temporarily_unavailable';
    private const UNAVAILABLE_TTL_SECONDS   = 1800; // 30 minutes
    private const POLL_INTERVAL_SECONDS     = 3;
    private const POLL_TIMEOUT_SECONDS      = 60;
    private const MODEL                     = 'midjourney';

    public function name(): string
    {
        return 'midjourney';
    }

    public function isAvailable(): bool
    {
        if (empty(config('services.midjourney.discord_token'))) {
            return false;
        }

        return !Redis::exists(self::UNAVAILABLE_REDIS_KEY);
    }

    public function generate(string $prompt, string $size, array $options = []): ImageResponse
    {
        $channelId = config('services.midjourney.channel_id');
        $token     = config('services.midjourney.discord_token');

        // Step 1: Post /imagine command to Discord channel
        $this->postImagineCommand($channelId, $token, $prompt);

        // Step 2: Poll Discord channel messages until Midjourney bot replies with an attachment
        try {
            $originalUrl = $this->pollForAttachment($channelId, $token);
        } catch (\RuntimeException $e) {
            $this->markTemporarilyUnavailable();
            throw new \RuntimeException('Midjourney timeout', 0, $e);
        }

        // Step 3: Download the image and upload to R2
        $imageContent = Http::timeout(30)->get($originalUrl)->throw()->body();

        $uuid  = (string) Str::uuid();
        $r2Key = "images/{$uuid}.png";
        Storage::disk('r2')->put($r2Key, $imageContent);
        $r2Url = Storage::disk('r2')->url($r2Key);

        $costUsd = (float) config('ai-models.image_costs.midjourney', 0.020);

        return new ImageResponse(
            url:         $r2Url,
            r2Key:       $r2Key,
            provider:    'midjourney',
            model:       self::MODEL,
            imagesCount: 1,
            costUsd:     $costUsd,
            originalUrl: $originalUrl,
        );
    }

    /**
     * Post an /imagine prompt message to the configured Discord channel.
     */
    private function postImagineCommand(string $channelId, string $token, string $prompt): void
    {
        Http::withHeaders([
                'Authorization' => $token,
                'Content-Type'  => 'application/json',
            ])
            ->timeout(15)
            ->post(self::DISCORD_API_BASE . "/channels/{$channelId}/messages", [
                'content' => "/imagine prompt: {$prompt}",
            ])->throw();
    }

    /**
     * Poll the Discord channel messages for a reply from the Midjourney bot
     * that contains an image attachment. Returns the attachment URL on success.
     *
     * Throws RuntimeException on timeout.
     */
    private function pollForAttachment(string $channelId, string $token): string
    {
        $startTime  = time();
        $midjourneyBotId = config('services.midjourney.bot_id', '936929561302675456');

        while ((time() - $startTime) < self::POLL_TIMEOUT_SECONDS) {
            sleep(self::POLL_INTERVAL_SECONDS);

            $messages = Http::withHeaders([
                    'Authorization' => $token,
                ])
                ->timeout(15)
                ->get(self::DISCORD_API_BASE . "/channels/{$channelId}/messages", [
                    'limit' => 10,
                ])->throw()->json();

            foreach ($messages as $message) {
                // Look for Midjourney bot messages with image attachments
                $authorId   = $message['author']['id'] ?? null;
                $attachments = $message['attachments'] ?? [];

                if ($authorId === $midjourneyBotId && !empty($attachments)) {
                    $attachmentUrl = $attachments[0]['url'] ?? null;
                    if ($attachmentUrl) {
                        return $attachmentUrl;
                    }
                }
            }
        }

        throw new \RuntimeException(
            'Midjourney Discord poll timed out after ' . self::POLL_TIMEOUT_SECONDS . ' seconds.'
        );
    }

    /**
     * Set a Redis flag that marks Midjourney as temporarily unavailable.
     * The gateway will skip this provider for the next 30 minutes.
     */
    private function markTemporarilyUnavailable(): void
    {
        Redis::setex(self::UNAVAILABLE_REDIS_KEY, self::UNAVAILABLE_TTL_SECONDS, 1);
    }
}
