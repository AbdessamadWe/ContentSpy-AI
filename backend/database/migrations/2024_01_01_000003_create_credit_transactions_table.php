<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // APPEND-ONLY ledger — never UPDATE or DELETE rows
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['purchase', 'plan_grant', 'debit', 'refund', 'expiry', 'adjustment']);
            $table->integer('amount'); // positive = credit, negative = debit
            $table->integer('balance_after'); // snapshot for O(1) balance lookup
            $table->string('action_type', 100)->nullable(); // e.g. 'article_generation_per_1000_words'
            $table->string('action_id', 255)->nullable(); // e.g. article_id, job_id
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('workspace_id');
            $table->index('type');
            $table->index('created_at');
            $table->index('action_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
