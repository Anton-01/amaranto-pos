<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('version');
            $table->boolean('is_active')->default(true);
            $table->string('business_name', 150);
            $table->string('rfc', 20);
            $table->text('address');
            $table->string('phone', 20);
            $table->text('header_message')->nullable();
            $table->text('footer_message')->nullable();
            $table->string('logo_url', 255)->nullable();
            $table->uuid('updated_by');
            $table->timestampTz('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_configs');
    }
};
