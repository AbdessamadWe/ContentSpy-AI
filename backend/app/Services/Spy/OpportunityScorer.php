<?php
namespace App\Services\Spy;

class OpportunityScorer
{
    /**
     * Score a detected article 0-100 based on multiple signals.
     * Higher = more valuable content opportunity.
     */
    public function score(array $data): int
    {
        $score = 40; // base

        // Freshness (published recency)
        if (!empty($data['published_at'])) {
            $hoursOld = (time() - strtotime($data['published_at'])) / 3600;
            $score += match(true) {
                $hoursOld < 6   => 25,
                $hoursOld < 24  => 20,
                $hoursOld < 72  => 10,
                $hoursOld < 168 => 5,
                default         => 0,
            };
        }

        // Title length (indicates depth)
        $titleLen = strlen($data['title'] ?? '');
        $score += match(true) {
            $titleLen > 60 => 5,
            $titleLen > 40 => 3,
            default        => 0,
        };

        // Excerpt/content richness
        $excerptLen = strlen($data['excerpt'] ?? '');
        $score += match(true) {
            $excerptLen > 500 => 10,
            $excerptLen > 200 => 7,
            $excerptLen > 50  => 3,
            default           => 0,
        };

        // Category/tag count (indicates SEO effort)
        $tags = count($data['categories'] ?? []) + count($data['tags'] ?? []);
        $score += min(10, $tags * 2);

        // Sitemap priority signal
        if (!empty($data['sitemap_priority'])) {
            $score += (int) ($data['sitemap_priority'] * 10);
        }

        return min(100, max(0, $score));
    }
}
