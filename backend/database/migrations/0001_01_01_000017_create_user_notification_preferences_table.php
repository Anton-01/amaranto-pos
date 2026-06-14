<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('notification_type_id');
            $table->string('channel', 50);
            $table->boolean('is_enabled')->default(true);

            $table->primary(['user_id', 'notification_type_id', 'channel']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('notification_type_id')->references('id')->on('notification_types')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
    }
};
