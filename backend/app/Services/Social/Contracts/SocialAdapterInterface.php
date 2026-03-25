<?php

namespace App\Services\Social\Contracts;

use App\Models\Article;

interface SocialAdapterInterface
{
    /**
     * Transform article content for platform-specific format
     */
    public function adapt(Article $article): SocialPostDTO;

    /**
     * Get supported post types for this platform
     */
    public function supportedPostTypes(): array;
}

/**
 * Data Transfer Object for social media posts
 */
class SocialPostDTO
{
    public function __construct(
        public string $platform,
        public string $postType,
        public ?string $caption,
        public ?string $hashtags,
        public array $mediaUrls = [],
        public ?string $videoUrl = null,
        public ?string $scheduledFor = null,
        public array $metadata = [],
    ) {}
}