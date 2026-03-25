<?php
namespace App\Services\Content\Steps;

use App\Models\Article;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Str;

class SeoOptimizerService
{
    public function __construct(private readonly AiGateway $ai) {}

    /**
     * Generate SEO meta fields and update the article.
     * Produces: meta_title, meta_description, slug, focus_keyword.
     */
    public function optimize(Article $article): void
    {
        $response = $this->ai->generate(
            messages: [
                ['role' => 'system', 'content' => 'You are an SEO expert. Return ONLY valid JSON, no markdown.'],
                ['role' => 'user',   'content' => $this->buildPrompt($article)],
            ],
            model:   $article->ai_model_text ?? config('contentspy.default_model', 'gpt-4o'),
            context: [
                'workspace_id'    => $article->workspace_id,
                'article_id'      => $article->id,
                'action_type'     => 'seo_optimization',
                'credits_consumed' => 2,
            ],
            maxTokens: 500,
        );

        $data = json_decode($response->text, true);

        if (! $data) {
            throw new \RuntimeException("Failed to parse SEO JSON for article #{$article->id}");
        }

        // Enforce character limits
        $metaTitle       = Str::limit($data['meta_title']       ?? $article->title, 60, '');
        $metaDescription = Str::limit($data['meta_description'] ?? '', 160, '');
        $slug            = Str::slug($data['slug'] ?? $article->title);
        $focusKeyword    = $data['focus_keyword'] ?? $article->focus_keyword;

        $article->update([
            'meta_title'       => $metaTitle,
            'meta_description' => $metaDescription,
            'slug'             => $slug,
            'focus_keyword'    => $focusKeyword,
        ]);
    }

    private function buildPrompt(Article $article): string
    {
        $excerpt = Str::limit(strip_tags($article->content ?? ''), 500);
        return <<<PROMPT
Generate SEO metadata for this article:

Title: {$article->title}
Focus Keyword: {$article->focus_keyword}
Excerpt: {$excerpt}

Requirements:
- meta_title: max 60 characters, include focus keyword
- meta_description: max 160 characters, compelling, include focus keyword
- slug: URL-safe, lowercase, hyphens only
- focus_keyword: confirm or improve the focus keyword

Return ONLY this JSON (no markdown):
{"meta_title":"...","meta_description":"...","slug":"...","focus_keyword":"..."}
PROMPT;
    }
}
