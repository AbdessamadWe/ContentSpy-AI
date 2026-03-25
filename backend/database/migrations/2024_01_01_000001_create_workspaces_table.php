<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('plan', ['starter', 'pro', 'agency'])->default('starter');
            $table->timestamp('plan_expires_at')->nullable();
            $table->integer('credits_balance')->default(0);
            $table->integer('credits_reserved')->default(0); // in-flight reservations
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable();
            $table->boolean('white_label')->default(false);
            $table->string('custom_domain')->nullable();
            $table->string('custom_logo', 500)->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
