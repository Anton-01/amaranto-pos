<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks the individual items voided by a table cancellation.
     *
     * The order already carries `status = 'canceled'`, but a cancellation must
     * be auditable line by line: which products were on the table and at what
     * moment they stopped counting. Stamping the item instead of deleting it
     * keeps the amounts intact for the forensic trail — a voided consumption is
     * evidence, not garbage.
     *
     * Nullable by design: an item with NULL was never voided.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->timestampTz('canceled_at')->nullable()->after('tax_amount_at_sale');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('canceled_at');
        });
    }
};
