<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->string('merchant_normalized')->nullable();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['profile_id', 'document_type', 'merchant_normalized'], 'filing_rules_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filing_rules');
    }
};
