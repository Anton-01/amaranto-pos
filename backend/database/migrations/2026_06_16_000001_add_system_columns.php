<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_settings', function (Blueprint $table) {
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('image_url', 500)->nullable()->after('is_active');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('avatar_url', 500)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('global_settings', function (Blueprint $table) {
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['updated_by', 'created_at', 'updated_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'avatar_url']);
        });
    }
};
