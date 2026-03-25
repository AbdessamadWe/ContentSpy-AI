<?php
namespace App\Services\Content\Steps;

use App\Models\Article;

class WordPressFormatterService
{
    /**
     * Format article content for WordPress.
     * Supports Gutenberg blocks or Classic editor HTML, based on site config.
     */
    public function format(Article $article): string
    {
        $editorType = $article->site?->settings['editor_type'] ?? 'gutenberg';
        $content    = $article->content ?? '';

        if ($editorType === 'classic') {
            return $this->formatClassic($content);
        }

        return $this->formatGutenberg($content);
    }

    /**
     * Wrap HTML in Gutenberg block comments.
     */
    private function formatGutenberg(string $html): string
    {
        // Parse HTML into DOM to wrap elements in Gutenberg block comments
        $result = '';
        $lines  = preg_split('/(?=<h[1-6])|(?<=<\/h[1-6]>)|(?=<p>)|(?<=<\/p>)|(?=<ul>)|(?<=<\/ul>)|(?=<ol>)|(?<=<\/ol>)/', $html);

        foreach ($lines as $chunk) {
            $chunk = trim($chunk);
            if (! $chunk) continue;

            if (preg_match('/^<h2[^>]*>/i', $chunk)) {
                $result .= "\n<!-- wp:heading {\"level\":2} -->\n{$chunk}\n<!-- /wp:heading -->\n";
            } elseif (preg_match('/^<h3[^>]*>/i', $chunk)) {
                $result .= "\n<!-- wp:heading {\"level\":3} -->\n{$chunk}\n<!-- /wp:heading -->\n";
            } elseif (preg_match('/^<ul[^>]*>/i', $chunk)) {
                $result .= "\n<!-- wp:list -->\n{$chunk}\n<!-- /wp:list -->\n";
            } elseif (preg_match('/^<ol[^>]*>/i', $chunk)) {
                $result .= "\n<!-- wp:list {\"ordered\":true} -->\n{$chunk}\n<!-- /wp:list -->\n";
            } elseif (preg_match('/^<p[^>]*>/i', $chunk)) {
                $result .= "\n<!-- wp:paragraph -->\n{$chunk}\n<!-- /wp:paragraph -->\n";
            } else {
                // Wrap bare text in paragraph block
                $result .= "\n<!-- wp:paragraph -->\n<p>{$chunk}</p>\n<!-- /wp:paragraph -->\n";
            }
        }

        return trim($result);
    }

    /**
     * Clean HTML for Classic editor — strip unsupported tags, normalize whitespace.
     */
    private function formatClassic(string $html): string
    {
        // Allow standard WordPress Classic editor tags
        $allowedTags = '<p><br><h2><h3><h4><h5><h6><ul><ol><li><strong><em><a><blockquote><img><table><tr><td><th><thead><tbody>';
        $cleaned     = strip_tags($html, $allowedTags);

        // Normalize multiple blank lines
        $cleaned = preg_replace('/(\n\s*){3,}/', "\n\n", $cleaned);

        return trim($cleaned);
    }
}
