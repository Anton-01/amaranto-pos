<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('email', 150)->unique();
            $table->string('password', 255);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('password_restored_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->string('deletion_reason', 255)->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('deleted_by')->references('id')->on('users')->onDelete('set null');
        });

        DB::statement("ALTER TABLE users ADD COLUMN status user_status NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
