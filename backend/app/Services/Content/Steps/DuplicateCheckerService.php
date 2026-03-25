<?php
namespace App\Services\Content\Steps;

use App\Models\Article;

class DuplicateCheckerService
{
    /**
     * Check if article content is too similar to existing published articles.
     * Uses Meilisearch full-text search via Laravel Scout.
     *
     * @return float Similarity score 0-100 (100 = exact duplicate)
     */
    public function check(Article $article): float
    {
        $searchQuery = trim(($article->title ?? '') . ' ' . ($article->focus_keyword ?? ''));

        if (! $searchQuery) {
            return 0.0;
        }

        try {
            $results = Article::search($searchQuery)
                ->where('workspace_id', $article->workspace_id)
                ->where('site_id', $article->site_id)
                ->where('publish_status', 'published')
                ->get()
                ->filter(fn($a) => $a->id !== $article->id);

            if ($results->isEmpty()) {
                return 0.0;
            }

            // Meilisearch returns results in ranked order — first result is most similar.
            // Estimate similarity based on title word overlap.
            $titleWords     = array_filter(explode(' ', strtolower($article->title ?? '')));
            $highestScore   = 0.0;

            foreach ($results->take(5) as $similar) {
                $similarWords = array_filter(explode(' ', strtolower($similar->title ?? '')));
                $intersection = array_intersect($titleWords, $similarWords);

                $union = array_unique(array_merge($titleWords, $similarWords));
                if (count($union) === 0) continue;

                $jaccardScore = count($intersection) / count($union) * 100;
                $highestScore = max($highestScore, $jaccardScore);
            }

            return round($highestScore, 2);

        } catch (\Throwable $e) {
            // If Meilisearch is unavailable, log and pass check rather than blocking generation
            \Illuminate\Support\Facades\Log::warning("[DuplicateCheckerService] Search failed: {$e->getMessage()}");
            return 0.0;
        }
    }
}
