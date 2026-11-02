<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('name');
        });

        $profileIds = DB::table('categories')->distinct()->pluck('profile_id');

        foreach ($profileIds as $profileId) {
            $categories = DB::table('categories')
                ->where('profile_id', $profileId)
                ->orderBy('name')
                ->orderBy('id')
                ->pluck('id');

            foreach ($categories as $index => $categoryId) {
                DB::table('categories')
                    ->where('id', $categoryId)
                    ->update(['sort_order' => $index]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
