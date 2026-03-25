<?php
namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\ImageProviderInterface;
use App\Services\Ai\ImageResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReplicateProvider implements ImageProviderInterface
{
    private const POLL_INTERVAL_SECONDS = 3;
    private const POLL_TIMEOUT_SECONDS  = 60;
    private const MODEL                 = 'stable-diffusion-xl';

    public function name(): string
    {
        return 'replicate';
    }

    public function isAvailable(): bool
    {
        return !empty(config('services.replicate.key'));
    }

    public function generate(string $prompt, string $size, array $options = []): ImageResponse
    {
        // Start the prediction
        $prediction = Http::withToken(config('services.replicate.key'))
            ->timeout(30)
            ->post('https://api.replicate.com/v1/models/stability-ai/sdxl/predictions', [
                'input' => [
                    'prompt' => $prompt,
                    'width'  => $this->parseDimension($size, 'width'),
                    'height' => $this->parseDimension($size, 'height'),
                ],
            ])->throw()->json();

        $predictionId = $prediction['id'];

        // Poll until succeeded or timeout
        $originalUrl  = $this->pollUntilComplete($predictionId);

        // Download the image from the provider URL
        $imageContent = Http::timeout(30)->get($originalUrl)->throw()->body();

        // Upload to R2
        $uuid  = (string) Str::uuid();
        $r2Key = "images/{$uuid}.png";
        Storage::disk('r2')->put($r2Key, $imageContent);
        $r2Url = Storage::disk('r2')->url($r2Key);

        $costUsd = (float) config('ai-models.image_costs.sdxl', 0.0046);

        return new ImageResponse(
            url:         $r2Url,
            r2Key:       $r2Key,
            provider:    'replicate',
            model:       self::MODEL,
            imagesCount: 1,
            costUsd:     $costUsd,
            originalUrl: $originalUrl,
        );
    }

    /**
     * Poll the Replicate prediction endpoint until the status is 'succeeded'.
     * Throws RuntimeException on timeout or terminal failure.
     */
    private function pollUntilComplete(string $predictionId): string
    {
        $elapsed = 0;

        while ($elapsed < self::POLL_TIMEOUT_SECONDS) {
            sleep(self::POLL_INTERVAL_SECONDS);
            $elapsed += self::POLL_INTERVAL_SECONDS;

            $result = Http::withToken(config('services.replicate.key'))
                ->timeout(15)
                ->get("https://api.replicate.com/v1/predictions/{$predictionId}")
                ->throw()
                ->json();

            $status = $result['status'] ?? 'unknown';

            if ($status === 'succeeded') {
                $outputUrl = $result['output'][0] ?? null;
                if (!$outputUrl) {
                    throw new \RuntimeException('Replicate prediction succeeded but output[0] is empty.');
                }
                return $outputUrl;
            }

            if (in_array($status, ['failed', 'canceled'], true)) {
                $error = $result['error'] ?? 'unknown error';
                throw new \RuntimeException("Replicate prediction {$status}: {$error}");
            }

            // status is 'starting' or 'processing' — keep polling
        }

        throw new \RuntimeException(
            "Replicate prediction timed out after " . self::POLL_TIMEOUT_SECONDS . " seconds (id: {$predictionId})"
        );
    }

    /** Parse width or height from a size string like '1024x1024'. */
    private function parseDimension(string $size, string $dimension): int
    {
        $parts = explode('x', strtolower($size));
        if (count($parts) === 2) {
            return $dimension === 'width' ? (int) $parts[0] : (int) $parts[1];
        }
        return 1024;
    }
}
