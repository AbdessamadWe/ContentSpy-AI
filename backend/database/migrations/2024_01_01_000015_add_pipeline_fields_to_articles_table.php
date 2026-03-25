<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds fields required by the ContentPipelineOrchestrator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // Content angle / metadata from suggestion
            $table->text('content_angle')->nullable()->after('focus_keyword');
            $table->integer('word_count_target')->default(1500)->after('word_count');
            $table->string('tone', 50)->default('informative')->after('word_count_target');

            // Pipeline control
            $table->boolean('generate_images')->default(true)->after('tone');
            $table->boolean('auto_publish')->default(false)->after('generate_images');
            $table->string('credit_reservation', 100)->nullable()->after('auto_publish');
            $table->integer('credits_reserved')->default(0)->after('credit_reservation');
            $table->text('failure_reason')->nullable()->after('credits_reserved');

            // Formatted content (Gutenberg blocks or classic HTML)
            $table->longText('formatted_content')->nullable()->after('content');

            // Categories and tags (synced to WP)
            $table->json('categories')->nullable()->after('target_keywords');
            $table->json('tags')->nullable()->after('categories');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn([
                'content_angle', 'word_count_target', 'tone',
                'generate_images', 'auto_publish', 'credit_reservation',
                'credits_reserved', 'failure_reason', 'formatted_content',
                'categories', 'tags',
            ]);
        });
    }
};
