<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            // draft | ordered | partial | received | cancelled
            $table->string('status', 12)->default('draft');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->decimal('unit_cost', 15, 4);
            $table->timestamps();
        });

        // A goods receipt is also the supplier's invoice (what we owe for what arrived).
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->string('number', 30)->unique();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('supplier_invoice_no', 60)->nullable();
            $table->date('received_date');
            $table->date('due_date')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_id', 'received_date']);
            $table->index('received_date');
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('line_total', 15, 2);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('reference', 100)->nullable();
            $table->date('paid_date');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_id', 'paid_date']);
        });
        DB::statement('ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_amount_pos CHECK (amount > 0)');

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->date('expense_date');
            $table->string('reference', 100)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index(['expense_date', 'voided_at']);
        });
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_pos CHECK (amount > 0)');

        // Running document counters (S-000001, GRN-000001...). Row-locked when incremented.
        Schema::create('sequences', function (Blueprint $table) {
            $table->string('name', 30)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        foreach (['sequences', 'expenses', 'expense_categories', 'supplier_payments', 'goods_receipt_items',
            'goods_receipts', 'purchase_order_items', 'purchase_orders'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
