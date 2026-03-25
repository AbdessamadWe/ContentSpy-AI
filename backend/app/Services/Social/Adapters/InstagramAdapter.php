<?php

namespace App\Services\Social\Adapters;

use App\Models\Article;
use App\Services\Social\Contracts\SocialAdapterInterface;
use App\Services\Social\Contracts\SocialPostDTO;
use App\Services\Ai\ImageGenerationGateway;
use Illuminate\Support\Str;

class InstagramAdapter implements SocialAdapterInterface
{
    public function __construct(
        private readonly ImageGenerationGateway $imageGateway,
    ) {}

    public function adapt(Article $article): SocialPostDTO
    {
        // Extract content for Instagram
        $content = strip_tags($article->content);
        $excerpt = Str::limit($content, 125); // Leave room for hashtags
        
        // Generate image (1080x1080 for feed)
        $imageUrl = $article->featured_image_url ?? $this->generateImage($article);
        
        // Generate hashtags (up to 30, but 10-15 is optimal)
        $hashtags = $this->generateHashtags($article->target_keywords ?? []);
        
        // Caption (max 2200 chars, optimal 125-150 for engagement + hashtags)
        $caption = $this->generateCaption($article, $excerpt, $hashtags);

        return new SocialPostDTO(
            platform: 'instagram',
            postType: 'image',
            caption: $caption,
            hashtags: $hashtags,
            mediaUrls: $imageUrl ? [$imageUrl] : [],
            scheduledFor: $article->scheduled_for,
            metadata: [
                'article_id' => $article->id,
                'alt_text' => $article->meta_description ?? $article->title,
            ]
        );
    }

    public function supportedPostTypes(): array
    {
        return ['image', 'carousel', 'reel'];
    }

    private function generateCaption(Article $article, string $excerpt, string $hashtags): string
    {
        $hook = $this->generateHook($article);
        return "{$hook}\n\n{$excerpt}\n\n👇 Read more in bio!\n\n{$hashtags}";
    }

    private function generateHook(Article $article): string
    {
        $hooks = [
            "💡 {$article->title}",
            "✨ Did you know?",
            "🔥 {$article->title}",
            "📌 {$article->title}",
            "🎯 {$article->title}",
        ];
        return $hooks[array_rand($hooks)];
    }

    private function generateHashtags(array $keywords): string
    {
        $tags = array_slice($keywords, 0, 12);
        return implode(' ', array_map(fn($tag) => '#' . Str::slug($tag), $tags));
    }

    private function generateImage(Article $article): ?string
    {
        $result = $this->imageGateway->generate(
            prompt: "Instagram post image: {$article->title}",
            size: '1080x1080',
            siteId: $article->site_id,
        );
        return $result['url'] ?? null;
    }
}