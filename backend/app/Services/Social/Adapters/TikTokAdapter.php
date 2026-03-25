<?php

namespace App\Services\Social\Adapters;

use App\Models\Article;
use App\Services\Social\Contracts\SocialAdapterInterface;
use App\Services\Social\Contracts\SocialPostDTO;
use App\Services\Ai\TtsService;
use App\Services\Ai\VideoScriptService;
use Illuminate\Support\Str;

class TikTokAdapter implements SocialAdapterInterface
{
    public function __construct(
        private readonly TtsService $ttsService,
        private readonly VideoScriptService $videoScriptService,
    ) {}

    public function adapt(Article $article): SocialPostDTO
    {
        // Extract 3-5 key points for video script
        $script = $this->videoScriptService->fromArticle($article);
        
        // Generate TTS audio
        $audioResult = $this->ttsService->generate(
            text: $script,
            voice: 'en_us_male_1', // Default voice
        );
        
        $audioUrl = $audioResult['audio_url'] ?? null;
        
        // Generate hashtags
        $hashtags = $this->generateHashtags($article->target_keywords ?? []);
        
        // Description (max 2200 chars)
        $description = $this->generateDescription($article, $hashtags);

        return new SocialPostDTO(
            platform: 'tiktok',
            postType: 'video',
            caption: $description,
            hashtags: $hashtags,
            mediaUrls: [],
            videoUrl: null, // Will be assembled by FFmpeg service
            scheduledFor: $article->scheduled_for,
            metadata: [
                'article_id' => $article->id,
                'script' => $script,
                'audio_url' => $audioUrl,
                'duration_seconds' => $audioResult['duration_seconds'] ?? 0,
            ]
        );
    }

    public function supportedPostTypes(): array
    {
        return ['video'];
    }

    private function generateDescription(Article $article, string $hashtags): string
    {
        $title = Str::limit($article->title, 100);
        return "💡 {$title}\n\n{$hashtags}\n\n#fyp #viral";
    }

    private function generateHashtags(array $keywords): string
    {
        $tags = array_slice($keywords, 0, 5);
        return implode(' ', array_map(fn($tag) => '#' . Str::slug($tag), $tags));
    }
}