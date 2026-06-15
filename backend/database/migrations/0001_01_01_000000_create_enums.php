<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE user_status AS ENUM ('active', 'suspended')");
        DB::statement("CREATE TYPE payment_method AS ENUM ('efectivo', 'tarjeta', 'transferencia')");
        DB::statement("CREATE TYPE promotion_type AS ENUM ('percentage', 'fixed_amount', 'freebie_100')");
        DB::statement("CREATE TYPE stock_movement_type AS ENUM ('purchase_input', 'merma_output', 'sale_output', 'adjustment')");
        DB::statement("CREATE TYPE stock_movement_reason AS ENUM ('expired', 'damaged_spilled', 'internal_consumption', 'theft_loss')");
        DB::statement("CREATE TYPE petty_cash_reason AS ENUM ('provider_payment', 'supplies_purchase', 'change_delivery', 'emergency')");
    }

    public function down(): void
    {
        DB::statement('DROP TYPE IF EXISTS petty_cash_reason');
        DB::statement('DROP TYPE IF EXISTS stock_movement_reason');
        DB::statement('DROP TYPE IF EXISTS stock_movement_type');
        DB::statement('DROP TYPE IF EXISTS promotion_type');
        DB::statement('DROP TYPE IF EXISTS payment_method');
        DB::statement('DROP TYPE IF EXISTS user_status');
    }
};
