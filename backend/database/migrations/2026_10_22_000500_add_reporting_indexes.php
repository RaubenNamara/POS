<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Measured on 60k sales / 150k lines: profit and best-seller aggregates were reading whole
 * rows (250 ms warm, seconds cold on a small buffer pool). Covering indexes let them read
 * only these narrow index pages. last_sold_at turns "slow moving stock" from a scan of all
 * sales history into an indexed range read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $t) {
            $t->index(['status', 'completed_at', 'subtotal', 'discount_total', 'tax_total', 'delivery_fee', 'total', 'cost_total'], 'sales_pl_cover');
            $t->dropIndex(['status', 'completed_at']);
        });
        Schema::table('sale_items', function (Blueprint $t) {
            $t->index(['sale_id', 'product_id', 'quantity', 'returned_quantity', 'amount', 'tax', 'cost_total'], 'sale_items_report_cover');
        });
        Schema::table('products', function (Blueprint $t) {
            $t->timestamp('last_sold_at')->nullable()->after('total_stock');
            $t->index(['type', 'archived_at', 'last_sold_at', 'total_stock'], 'products_slow_moving');
        });
        // Backfill from existing history (one pass).
        DB::statement('UPDATE products p JOIN (
                SELECT i.product_id, MAX(s.completed_at) AS last_sold FROM sale_items i JOIN sales s ON s.id = i.sale_id
                WHERE s.status = "completed" AND i.product_id IS NOT NULL GROUP BY i.product_id
            ) x ON x.product_id = p.id SET p.last_sold_at = x.last_sold');
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->index(['product_id', 'location_id', 'quantity'], 'stock_movements_balance_cover');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropIndex('stock_movements_balance_cover'));
        Schema::table('products', function (Blueprint $t) {
            $t->dropIndex('products_slow_moving');
            $t->dropColumn('last_sold_at');
        });
        Schema::table('sale_items', fn (Blueprint $t) => $t->dropIndex('sale_items_report_cover'));
        Schema::table('sales', function (Blueprint $t) {
            $t->index(['status', 'completed_at']);
            $t->dropIndex('sales_pl_cover');
        });
    }
};
