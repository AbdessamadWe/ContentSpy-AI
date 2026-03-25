<?php

namespace App\Services\Social\Adapters;

use App\Models\Article;
use App\Services\Social\Contracts\SocialAdapterInterface;
use App\Services\Social\Contracts\SocialPostDTO;
use App\Services\Ai\ImageGenerationGateway;
use Illuminate\Support\Str;

class FacebookAdapter implements SocialAdapterInterface
{
    public function __construct(
        private readonly ImageGenerationGateway $imageGateway,
    ) {}

    public function adapt(Article $article): SocialPostDTO
    {
        // Extract key insight from article content
        $content = strip_tags($article->content);
        $excerpt = Str::limit($content, 280);
        
        // Generate hashtags from target keywords
        $hashtags = $this->generateHashtags($article->target_keywords ?? []);
        
        // Generate image for post
        $imageUrl = null;
        if ($article->featured_image_url) {
            $imageUrl = $article->featured_image_url;
        } else {
            // Generate a featured image
            $imageResult = $this->imageGateway->generate(
                prompt: "Social media post image for: {$article->title}",
                size: '1080x1080',
                siteId: $article->site_id,
            );
            $imageUrl = $imageResult['url'] ?? null;
        }

        // Caption (300-500 chars for Facebook)
        $caption = $this->generateCaption($article, $excerpt, $hashtags);

        return new SocialPostDTO(
            platform: 'facebook',
            postType: 'link',
            caption: $caption,
            hashtags: $hashtags,
            mediaUrls: $imageUrl ? [$imageUrl] : [],
            scheduledFor: $article->scheduled_for,
            metadata: [
                'link' => $article->wp_post_url,
                'article_id' => $article->id,
            ]
        );
    }

    public function supportedPostTypes(): array
    {
        return ['link', 'photo', 'carousel'];
    }

    private function generateCaption(Article $article, string $excerpt, string $hashtags): string
    {
        // Facebook ideal caption length: 300-500 characters
        $title = $article->title;
        $body = Str::limit($excerpt, 200);
        
        return "📝 {$title}\n\n{$body}\n\n{$hashtags}";
    }

    private function generateHashtags(array $keywords): string
    {
        if (empty($keywords)) {
            return '';
        }
        
        $tags = array_slice($keywords, 0, 5);
        return implode(' ', array_map(fn($tag) => '#' . Str::slug($tag), $tags));
    }
}