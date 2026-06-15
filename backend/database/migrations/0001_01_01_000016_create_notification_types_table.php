<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 100)->unique();
            $table->string('name', 100);
            $table->text('description');
            $table->jsonb('allowed_roles');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_types');
    }
};
