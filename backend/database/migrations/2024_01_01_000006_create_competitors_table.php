<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('domain', 500);

            // Spy method URLs
            $table->string('rss_url', 500)->nullable();
            $table->string('sitemap_url', 500)->nullable();
            $table->string('blog_url', 500)->nullable();
            $table->string('twitter_handle', 100)->nullable();
            $table->string('instagram_handle', 100)->nullable();
            $table->string('semrush_domain', 500)->nullable();

            // Active spy methods: ['rss', 'sitemap', 'html_scraping', 'google_news', 'social_signal', 'keyword_gap', 'serp']
            $table->json('active_methods')->default('[]');
            $table->boolean('auto_spy')->default(false);
            $table->unsignedSmallInteger('auto_spy_interval')->default(60); // minutes

            // Confidence thresholds
            $table->unsignedTinyInteger('confidence_threshold_suggest')->default(50);
            $table->unsignedTinyInteger('confidence_threshold_generate')->default(70);
            $table->unsignedTinyInteger('confidence_threshold_publish')->default(85);

            $table->timestamp('last_scanned_at')->nullable();
            $table->unsignedInteger('total_articles_detected')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('site_id');
            $table->index('workspace_id');
            $table->index('domain');
            $table->index('auto_spy');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitors');
    }
};
