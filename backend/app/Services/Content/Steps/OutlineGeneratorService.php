<?php
namespace App\Services\Content\Steps;

use App\Models\Article;
use App\Services\Ai\AiGateway;

class OutlineGeneratorService
{
    public function __construct(private readonly AiGateway $ai) {}

    /**
     * Generate article outline as JSON array.
     * Returns ['sections' => [['h2' => '...', 'h3s' => [...], 'word_count_target' => 300]]]
     *
     * @throws \RuntimeException on JSON parse failure after retry
     */
    public function generate(Article $article): array
    {
        $context = [
            'workspace_id'    => $article->workspace_id,
            'article_id'      => $article->id,
            'action_type'     => 'outline_generation',
            'credits_consumed' => 3,
        ];

        $response = $this->ai->generate(
            messages: [
                ['role' => 'system', 'content' => 'You are an expert content strategist. Return ONLY valid JSON, no markdown code blocks, no explanation.'],
                ['role' => 'user',   'content' => $this->buildPrompt($article)],
            ],
            model:     $article->ai_model_text ?? config('contentspy.default_model', 'gpt-4o'),
            context:   $context,
            maxTokens: 2000,
        );

        $outline = json_decode($response->text, true);

        if (! $outline || ! isset($outline['sections'])) {
            // Retry with stricter prompt
            $retryResponse = $this->ai->generate(
                messages: [
                    ['role' => 'system', 'content' => 'Return ONLY a JSON object matching exactly this schema: {"sections":[{"h2":"string","h3s":["string"],"word_count_target":300}]}. No markdown. No text outside the JSON.'],
                    ['role' => 'user',   'content' => "Generate outline for article titled: {$article->title}"],
                ],
                model:   $article->ai_model_text ?? config('contentspy.default_model', 'gpt-4o'),
                context: array_merge($context, ['action_type' => 'outline_generation_retry']),
                maxTokens: 2000,
            );
            $outline = json_decode($retryResponse->text, true);
        }

        if (! $outline || ! isset($outline['sections'])) {
            throw new \RuntimeException("Failed to generate valid outline JSON after retry for article #{$article->id}");
        }

        return $outline;
    }

    private function buildPrompt(Article $article): string
    {
        $sectionCount = max(4, min(8, (int) ($article->word_count_target / 400)));
        $keywords     = is_array($article->target_keywords)
            ? implode(', ', $article->target_keywords)
            : ($article->target_keywords ?? '');

        return <<<PROMPT
Generate a detailed article outline for this blog post:

Title: {$article->title}
Focus Keyword: {$article->focus_keyword}
Target Keywords: {$keywords}
Word Count Target: {$article->word_count_target} words
Tone: {$article->tone}
Content Angle: {$article->content_angle}

Requirements:
- Exactly {$sectionCount} H2 sections
- 2-3 H3 subsections per H2
- word_count_target per section should sum to approximately {$article->word_count_target}

Return ONLY this JSON structure (no markdown, no other text):
{"sections":[{"h2":"Section title","h3s":["Subsection 1","Subsection 2"],"word_count_target":300}]}
PROMPT;
    }
}
