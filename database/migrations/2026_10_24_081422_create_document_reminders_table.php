<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('date_role', 32);
            $table->date('source_date');
            $table->date('remind_on');
            $table->string('status', 32)->default('pending');
            $table->string('channel', 32)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'date_role']);
            $table->index(['status', 'remind_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_reminders');
    }
};
