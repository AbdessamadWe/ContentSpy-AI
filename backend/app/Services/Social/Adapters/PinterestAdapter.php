<?php

namespace App\Services\Social\Adapters;

use App\Models\Article;
use App\Services\Social\Contracts\SocialAdapterInterface;
use App\Services\Social\Contracts\SocialPostDTO;
use App\Services\Ai\ImageGenerationGateway;
use Illuminate\Support\Str;

class PinterestAdapter implements SocialAdapterInterface
{
    public function __construct(
        private readonly ImageGenerationGateway $imageGateway,
    ) {}

    public function adapt(Article $article): SocialPostDTO
    {
        // Pinterest requires vertical images (1000x1500)
        $imageUrl = $this->generateImage($article);
        
        // Title (max 100 chars)
        $title = Str::limit($article->title, 100);
        
        // Description (max 500 chars)
        $description = Str::limit($article->meta_description ?? strip_tags($article->content), 500);
        
        // Link back to WP post
        $link = $article->wp_post_url;

        return new SocialPostDTO(
            platform: 'pinterest',
            postType: 'pin',
            caption: $description,
            hashtags: '',
            mediaUrls: $imageUrl ? [$imageUrl] : [],
            scheduledFor: $article->scheduled_for,
            metadata: [
                'article_id' => $article->id,
                'title' => $title,
                'link' => $link,
                'board_id' => null, // User configures this
            ]
        );
    }

    public function supportedPostTypes(): array
    {
        return ['pin', 'idea_pin'];
    }

    private function generateImage(Article $article): ?string
    {
        // Pinterest vertical format 1000x1500
        $result = $this->imageGateway->generate(
            prompt: "Pinterest pin image: {$article->title}",
            size: '1000x1500',
            siteId: $article->site_id,
        );
        
        return $result['url'] ?? null;
    }
}