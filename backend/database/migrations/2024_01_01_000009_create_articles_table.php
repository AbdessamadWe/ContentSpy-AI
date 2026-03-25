<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('suggestion_id')->nullable()->constrained('content_suggestions')->nullOnDelete();

            // Content
            $table->text('title');
            $table->string('slug', 500)->nullable();
            $table->longText('content')->nullable();
            $table->text('excerpt')->nullable();

            // SEO
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('focus_keyword')->nullable();
            $table->json('target_keywords')->nullable();

            // Generation metadata
            $table->unsignedInteger('word_count')->default(0);
            $table->string('tone', 100)->nullable();
            $table->string('ai_model_text', 100)->nullable();
            $table->string('ai_model_image', 100)->nullable();

            // Images
            $table->string('featured_image_url', 500)->nullable();
            $table->string('featured_image_r2_key', 500)->nullable();
            $table->json('inline_images')->nullable(); // [{url, r2_key, alt_text, position}]

            // Structure
            $table->json('outline')->nullable(); // H2/H3 structure

            // Cost tracking
            $table->unsignedInteger('total_tokens_used')->default(0);
            $table->decimal('total_cost_usd', 10, 4)->default(0);
            $table->unsignedInteger('total_credits_consumed')->default(0);

            // Pipeline state machine
            // Steps: pending → outline → writing → seo → images → review → ready → failed
            $table->enum('generation_status', [
                'pending', 'outline', 'writing', 'seo', 'images', 'review', 'ready', 'failed',
            ])->default('pending');

            // Human review
            $table->enum('review_status', ['pending', 'approved', 'rejected'])->default('pending');

            // Duplicate check
            $table->boolean('duplicate_check_passed')->default(false);
            $table->decimal('duplicate_score', 5, 2)->default(0);

            // WordPress publishing
            $table->unsignedInteger('wp_post_id')->nullable()->index();
            $table->timestamp('wp_published_at')->nullable();
            $table->string('wp_post_url', 500)->nullable();
            $table->enum('publish_status', ['draft', 'scheduled', 'published', 'failed'])->default('draft');
            $table->timestamp('scheduled_for')->nullable();

            $table->timestamps();

            $table->index('workspace_id');
            $table->index('site_id');
            $table->index('generation_status');
            $table->index('publish_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
