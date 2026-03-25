<?php
namespace App\Services\Spy\Contracts;

use App\Models\Competitor;

interface SpyMethodInterface
{
    /** Method key string (rss, sitemap, html_scraping, etc.) */
    public function key(): string;

    /** Credit cost from config */
    public function creditCost(): int;

    /**
     * Run the spy scan for a competitor.
     * Returns array of newly created SpyDetection instances.
     */
    public function detect(Competitor $competitor): array;
}
