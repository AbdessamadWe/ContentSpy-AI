<?php
namespace App\Services\Ai;

readonly class ImageResponse
{
    public function __construct(
        public string  $url,          // Public URL of the image (R2 or provider URL)
        public string  $r2Key,        // R2 object key for future reference
        public string  $provider,     // 'dalle', 'replicate', 'midjourney'
        public string  $model,        // e.g. 'dall-e-3', 'stable-diffusion-xl'
        public int     $imagesCount,  // Always 1 for now
        public float   $costUsd,      // Calculated cost in USD
        public ?string $originalUrl = null, // Provider's original URL before R2 upload
    ) {}
}
