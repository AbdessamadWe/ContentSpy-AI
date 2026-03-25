<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spy_detections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->enum('method', ['rss', 'html_scraping', 'sitemap', 'google_news', 'social_signal', 'keyword_gap', 'serp']);

            // Detected content
            $table->text('source_url');
            $table->text('title')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('author')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('categories')->nullable();
            $table->json('tags')->nullable();

            // Dedup key: SHA-256 of normalized URL + title
            $table->string('content_hash', 64)->unique();

            // Scoring
            $table->unsignedTinyInteger('opportunity_score')->default(0);
            $table->unsignedTinyInteger('keyword_difficulty')->nullable();
            $table->unsignedInteger('estimated_traffic')->nullable();

            // Raw response data (stored in MongoDB for full detail, summarized here)
            $table->json('raw_data')->nullable();

            $table->enum('status', ['new', 'suggested', 'generating', 'published', 'rejected', 'expired'])->default('new');
            // No FK constraint here — circular dep with content_suggestions; enforced at app level
            $table->unsignedBigInteger('suggestion_id')->nullable()->index();
            $table->unsignedSmallInteger('credits_consumed')->default(0);
            $table->timestamps();

            $table->index('competitor_id');
            $table->index('site_id');
            $table->index('workspace_id');
            $table->index('status');
            $table->index('method');
            $table->index('opportunity_score');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spy_detections');
    }
};
