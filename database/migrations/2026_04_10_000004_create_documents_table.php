<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->string('document_type')->nullable();
            $table->string('title')->nullable();
            $table->date('document_date')->nullable();
            $table->string('merchant')->nullable();
            $table->string('reference_number')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->json('extracted_data')->nullable();
            $table->string('suggested_category_name')->nullable();
            $table->boolean('suggests_new_category')->default(false);
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->text('ai_error')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status']);
            $table->index(['profile_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
