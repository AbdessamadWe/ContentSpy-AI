<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // No FK constraint on detection_id — circular dep; enforced at app level
            $table->unsignedBigInteger('detection_id')->nullable()->index();

            // AI-generated brief
            $table->text('suggested_title');
            $table->text('content_angle')->nullable();
            $table->json('target_keywords')->nullable();
            $table->unsignedSmallInteger('recommended_word_count')->default(1500);
            $table->string('tone', 100)->nullable();
            $table->json('h2_structure')->nullable();

            // Scoring
            $table->unsignedInteger('estimated_traffic')->nullable();
            $table->unsignedTinyInteger('keyword_difficulty')->nullable();
            $table->unsignedTinyInteger('opportunity_score')->default(0);

            $table->enum('status', [
                'pending', 'accepted', 'scheduled', 'generating',
                'generated', 'published', 'rejected', 'expired',
            ])->default('pending');

            $table->timestamp('scheduled_for')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->unsignedBigInteger('article_id')->nullable()->index();

            $table->timestamps();
            $table->timestamp('expires_at')->nullable(); // set to +30 days in model boot

            $table->index('workspace_id');
            $table->index('site_id');
            $table->index('status');
            $table->index('opportunity_score');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_suggestions');
    }
};
