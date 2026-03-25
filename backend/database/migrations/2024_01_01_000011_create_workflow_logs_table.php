<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Workflow execution audit log — append-only
        Schema::create('workflow_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->enum('trigger', ['auto_spy', 'manual', 'schedule', 'webhook']);
            $table->string('step', 100); // e.g. 'detect', 'score', 'suggest', 'generate', 'publish'
            $table->enum('status', ['started', 'completed', 'failed', 'skipped']);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->unsignedSmallInteger('credits_consumed')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('site_id');
            $table->index('workspace_id');
            $table->index('created_at');
        });

        // Spy job execution log — one row per scan per competitor per method
        Schema::create('spy_job_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('method', 50);
            $table->enum('status', ['started', 'completed', 'failed']);
            $table->unsignedSmallInteger('new_detections')->default(0);
            $table->unsignedSmallInteger('credits_consumed')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('competitor_id');
            $table->index('workspace_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spy_job_logs');
        Schema::dropIfExists('workflow_logs');
    }
};
