<?php
namespace App\Services\Content\Steps;

use App\Models\Article;
use App\Services\Ai\AiGateway;

class ArticleBodyGeneratorService
{
    public function __construct(private readonly AiGateway $ai) {}

    /**
     * Generate article body section-by-section to handle long articles without timeouts.
     * Each H2 section is a separate AiGateway call.
     * Returns the assembled full article content as HTML.
     */
    public function generate(Article $article): string
    {
        $outline    = $article->outline;
        $sections   = $outline['sections'] ?? [];
        $fullContent = '';
        $prevSummary = '';

        foreach ($sections as $index => $section) {
            $sectionContent = $this->generateSection(
                article:     $article,
                section:     $section,
                sectionIndex: $index,
                prevSummary: $prevSummary,
            );

            $fullContent .= $sectionContent . "\n\n";

            // Pass last ~200 words as context to next section for coherence
            $words = explode(' ', strip_tags($sectionContent));
            $prevSummary = implode(' ', array_slice($words, -200));
        }

        return trim($fullContent);
    }

    private function generateSection(Article $article, array $section, int $sectionIndex, string $prevSummary): string
    {
        $h2       = $section['h2'] ?? 'Section';
        $h3s      = $section['h3s'] ?? [];
        $wordTarget = $section['word_count_target'] ?? 300;
        $keywords = is_array($article->target_keywords) ? implode(', ', $article->target_keywords) : '';

        $subsectionsList = $h3s ? "Subsections to cover:\n" . implode("\n", array_map(fn($h) => "- {$h}", $h3s)) : '';
        $prevContext     = $prevSummary ? "\nPrevious section ended with: \"{$prevSummary}\"" : '';

        $response = $this->ai->generate(
            messages: [
                ['role' => 'system', 'content' => "You are an expert content writer. Write in {$article->tone} tone. Use HTML formatting (h2, h3, p, ul, ol tags). Do NOT include the full article — only this section."],
                ['role' => 'user',   'content' => <<<PROMPT
Write the "{$h2}" section of an article titled: "{$article->title}"
Focus Keyword: {$article->focus_keyword}
Target Keywords: {$keywords}
Target word count for this section: {$wordTarget} words
{$subsectionsList}
{$prevContext}

Write ONLY this section with proper HTML formatting. Include the <h2> tag.
PROMPT],
            ],
            model:   $article->ai_model_text ?? config('contentspy.default_model', 'gpt-4o'),
            context: [
                'workspace_id'    => $article->workspace_id,
                'article_id'      => $article->id,
                'action_type'     => 'body_generation_section_' . ($sectionIndex + 1),
                'credits_consumed' => 0, // tracked in bulk at end of job
            ],
            maxTokens: max(1024, (int) ($wordTarget * 2)),
        );

        return $response->text;
    }
}
