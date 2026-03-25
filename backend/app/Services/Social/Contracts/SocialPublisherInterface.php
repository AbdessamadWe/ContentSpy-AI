<?php
namespace App\Services\Social\Contracts;

use App\Models\Article;
use App\Models\SocialAccount;

interface SocialPublisherInterface
{
    /**
     * Publish content to the platform.
     * Returns platform-specific post ID and URL.
     */
    public function publish(Article $article, SocialAccount $account, array $options = []): array;

    /**
     * Refresh the OAuth access token if expired.
     */
    public function refreshToken(SocialAccount $account): void;

    /**
     * Platform identifier matching SocialAccount.platform.
     */
    public function platform(): string;
}
