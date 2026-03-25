<?php
namespace App\Services\Ai;

use App\Models\Article;

class VideoScriptService
{
    public function __construct(private AiGateway $ai) {}

    /**
     * Extract 3-5 key points from an article as a TikTok/Reels video script.
     * Returns array of {hook, points[], cta}.
     */
    public function fromArticle(Article $article, array $context = []): array
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are a social media video script writer. Create punchy, engaging scripts. Respond ONLY with valid JSON.'],
            ['role' => 'user', 'content' => "Create a 30-60 second TikTok/Reels video script from this article:\nTitle: {$article->title}\n\nContent excerpt: " . substr(strip_tags($article->content ?? ''), 0, 1000) . "\n\nReturn JSON: {hook: string (5-10 words), points: [string] (3-5 key points, each max 15 words), cta: string (call to action, max 10 words)}"],
        ];

        $model = $article->site->ai_model_text ?? config('ai-models.fallback_chains.text.0', 'gpt-4o');
        $response = $this->ai->generate($messages, $model, array_merge($context, ['action_type' => 'video_script']), 500);

        $script = json_decode($response->text, true) ?? [
            'hook'   => $article->title,
            'points' => [substr(strip_tags($article->content ?? ''), 0, 100)],
            'cta'    => 'Read the full article!',
        ];

        return $script;
    }

    /** Build narration text from script for TTS */
    public function buildNarration(array $script): string
    {
        $parts = [];
        if (!empty($script['hook'])) $parts[] = $script['hook'] . '.';
        foreach ($script['points'] ?? [] as $point) {
            $parts[] = $point . '.';
        }
        if (!empty($script['cta'])) $parts[] = $script['cta'];
        return implode(' ', $parts);
    }
}
