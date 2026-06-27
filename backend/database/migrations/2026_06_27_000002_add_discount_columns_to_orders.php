<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE discount_type AS ENUM ('fixed', 'percentage', 'none')");

        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('promotion_id')->nullable();
            $table->decimal('discount_value', 12, 2)->default(0.00);
            $table->decimal('discount_total', 12, 2)->default(0.00);

            $table->foreign('promotion_id')->references('id')->on('promotions')->onDelete('set null');
        });

        DB::statement("ALTER TABLE orders ADD COLUMN discount_type discount_type NOT NULL DEFAULT 'none'");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['promotion_id']);
            $table->dropColumn(['promotion_id', 'discount_value', 'discount_total']);
        });

        DB::statement("ALTER TABLE orders DROP COLUMN IF EXISTS discount_type");
        DB::statement("DROP TYPE IF EXISTS discount_type");
    }
};
