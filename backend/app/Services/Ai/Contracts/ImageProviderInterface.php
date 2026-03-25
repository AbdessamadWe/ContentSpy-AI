<?php
namespace App\Services\Ai\Contracts;

use App\Services\Ai\ImageResponse;

interface ImageProviderInterface
{
    /**
     * Generate an image from a text prompt.
     * Implementations must upload to R2 and return R2 URL.
     *
     * @param string $prompt  Text description of the image to generate
     * @param string $size    Image dimensions, e.g. '1024x1024'
     * @param array  $options Optional provider-specific overrides
     */
    public function generate(string $prompt, string $size, array $options = []): ImageResponse;

    /** Returns true if this provider is configured and should be tried */
    public function isAvailable(): bool;

    /** Provider name for logging */
    public function name(): string;
}
