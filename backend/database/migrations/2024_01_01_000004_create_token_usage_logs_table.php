<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('action_type', 100);
            $table->string('model', 100);
            $table->enum('provider', ['openai', 'anthropic', 'openrouter', 'replicate', 'elevenlabs', 'midjourney', 'dalle', 'stability']);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedSmallInteger('images_count')->default(0);
            $table->unsignedSmallInteger('video_seconds')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0); // micro-precision for small calls
            $table->unsignedInteger('credits_consumed')->default(0);
            $table->unsignedBigInteger('article_id')->nullable();
            $table->string('job_id', 255)->nullable();
            $table->string('request_id', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('workspace_id');
            $table->index('provider');
            $table->index('model');
            $table->index('action_type');
            $table->index('created_at');
            $table->index('article_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_usage_logs');
    }
};
