<?php

use App\Support\Database\PostgresEnum;
use Illuminate\Database\Migrations\Migration;

/**
 * Tipos ENUM nativos de PostgreSQL que usa el esquema base.
 *
 * La creacion pasa por PostgresEnum::createMany, que es idempotente: los tipos
 * SOBREVIVEN a `migrate:fresh` (que solo borra tablas), y un `CREATE TYPE`
 * directo fallaria con "type already exists" en la segunda corrida.
 */
return new class extends Migration
{
    private const ENUMS = [
        'user_status' => ['active', 'suspended'],
        'promotion_type' => ['percentage', 'fixed_amount', 'freebie_100'],
        'stock_movement_type' => ['purchase_input', 'merma_output', 'sale_output', 'adjustment'],
        'stock_movement_reason' => ['expired', 'damaged_spilled', 'internal_consumption', 'theft_loss'],
        'petty_cash_reason' => ['provider_payment', 'supplies_purchase', 'change_delivery', 'emergency'],
        'order_status' => ['completed', 'canceled'],
    ];

    public function up(): void
    {
        PostgresEnum::createMany(self::ENUMS);
    }

    public function down(): void
    {
        PostgresEnum::drop(...array_keys(self::ENUMS));
    }
};
