<?php
namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\ImageProviderInterface;
use App\Services\Ai\ImageResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DalleProvider implements ImageProviderInterface
{
    public function name(): string
    {
        return 'dalle';
    }

    public function isAvailable(): bool
    {
        return !empty(config('services.openai.key'));
    }

    public function generate(string $prompt, string $size, array $options = []): ImageResponse
    {
        $model = $options['model'] ?? 'dall-e-3';
        $size  = $size ?: '1024x1024';

        $response = Http::withToken(config('services.openai.key'))
            ->timeout(60)
            ->post('https://api.openai.com/v1/images/generations', [
                'model'           => $model,
                'prompt'          => $prompt,
                'n'               => 1,
                'size'            => $size,
                'response_format' => 'url',
            ])->throw()->json();

        $originalUrl = $response['data'][0]['url'];

        // Download the image from the provider URL
        $imageContent = Http::timeout(30)->get($originalUrl)->throw()->body();

        // Upload to R2
        $uuid   = (string) Str::uuid();
        $r2Key  = "images/{$uuid}.png";
        Storage::disk('r2')->put($r2Key, $imageContent);
        $r2Url  = Storage::disk('r2')->url($r2Key);

        $costUsd = (float) config('ai-models.image_costs.dalle3', 0.040);

        return new ImageResponse(
            url:         $r2Url,
            r2Key:       $r2Key,
            provider:    'dalle',
            model:       $model,
            imagesCount: 1,
            costUsd:     $costUsd,
            originalUrl: $originalUrl,
        );
    }
}
