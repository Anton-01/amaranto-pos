<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->jsonb('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_settings');
    }
};
