<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->enum('platform', ['facebook', 'instagram', 'tiktok', 'pinterest']);
            $table->string('platform_account_id');
            $table->string('account_name');
            $table->text('access_token');   // stored AES-256 encrypted
            $table->text('refresh_token')->nullable(); // stored AES-256 encrypted
            $table->timestamp('token_expires_at')->nullable();
            $table->string('page_id')->nullable();    // Facebook page ID
            $table->json('board_ids')->nullable();     // Pinterest board IDs
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('site_id');
            $table->index('workspace_id');
            $table->unique(['site_id', 'platform', 'platform_account_id']);
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->enum('platform', ['facebook', 'instagram', 'tiktok', 'pinterest']);
            $table->enum('post_type', ['image', 'carousel', 'reel', 'video', 'pin', 'idea_pin']);
            $table->text('caption')->nullable();
            $table->text('hashtags')->nullable();
            $table->json('media_urls')->nullable();
            $table->string('video_url', 500)->nullable();
            $table->string('platform_post_id')->nullable();
            $table->enum('status', ['pending', 'generating', 'ready', 'scheduled', 'published', 'failed'])->default('pending');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('credits_consumed')->default(0);
            $table->json('metrics')->nullable();
            $table->timestamp('metrics_updated_at')->nullable();
            $table->timestamps();

            $table->index('article_id');
            $table->index('workspace_id');
            $table->index('platform');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_accounts');
    }
};
