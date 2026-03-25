<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url', 500)->unique();
            $table->string('niche')->nullable();
            $table->string('language', 10)->default('en');
            $table->enum('connection_type', ['plugin', 'rest_api'])->default('plugin');

            // REST API connection (Option A)
            $table->string('wp_api_url', 500)->nullable();
            $table->string('wp_username')->nullable();
            $table->text('wp_app_password')->nullable(); // stored AES-256 encrypted

            // Plugin connection (Option B — recommended)
            $table->string('plugin_api_key')->nullable()->unique();
            $table->text('plugin_secret')->nullable(); // stored AES-256 encrypted
            $table->string('plugin_version', 20)->nullable();

            // WordPress metadata (fetched via status endpoint)
            $table->string('wp_version', 20)->nullable();
            $table->string('php_version', 20)->nullable();
            $table->enum('connection_status', ['connected', 'disconnected', 'error'])->default('disconnected');
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_post_at')->nullable();

            // AI preferences per site
            $table->string('ai_model_text', 100)->nullable();
            $table->string('ai_model_image', 100)->nullable();
            $table->unsignedInteger('default_author_id')->nullable();

            // Scheduling
            $table->string('timezone', 100)->default('UTC');
            $table->unsignedSmallInteger('max_posts_per_day')->default(10);

            // Workflow
            $table->string('workflow_template', 50)->default('human_in_the_loop');

            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
            $table->index('connection_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
