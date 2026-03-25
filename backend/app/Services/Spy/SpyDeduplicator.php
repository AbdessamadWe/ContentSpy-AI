<?php
namespace App\Services\Spy;

use App\Models\SpyDetection;

class SpyDeduplicator
{
    /**
     * Filter an array of raw detection data, removing any that already exist in DB.
     * Returns only items with content_hash not yet seen.
     *
     * @param array[] $items Each item must have 'source_url' and optionally 'title'
     * @return array[] Filtered items with 'content_hash' added to each
     */
    public function filter(array $items): array
    {
        if (empty($items)) return [];

        // Generate hashes for all items
        $withHashes = array_map(function ($item) {
            $item['content_hash'] = SpyDetection::generateHash(
                $item['source_url'] ?? '',
                $item['title'] ?? '',
            );
            return $item;
        }, $items);

        // Bulk check existing hashes
        $hashes = array_column($withHashes, 'content_hash');
        $existing = SpyDetection::whereIn('content_hash', $hashes)
            ->pluck('content_hash')
            ->flip()
            ->toArray();

        // Return only new items
        return array_values(array_filter(
            $withHashes,
            fn($item) => !isset($existing[$item['content_hash']]),
        ));
    }
}
