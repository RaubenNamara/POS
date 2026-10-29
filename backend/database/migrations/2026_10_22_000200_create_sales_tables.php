<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('address')->nullable();
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->string('type', 20); // cash | mobile_money | bank | card | other
            $table->boolean('requires_reference')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->decimal('opening_float', 15, 2)->default(0);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->decimal('cash_difference', 15, 2)->nullable();
            $table->text('closing_notes')->nullable();
            $table->string('status', 10)->default('open'); // open | closed
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            // Client-generated id: the idempotency key for POS checkout and offline sync.
            $table->uuid('client_uuid')->unique();
            $table->string('number', 40)->unique();
            $table->string('channel', 10)->default('pos'); // pos | online
            // pending (online order not yet delivered) | completed | cancelled
            $table->string('status', 12)->default('completed');
            // online orders only: placed | confirmed | processing | ready | out_for_delivery | delivered | cancelled
            $table->string('fulfillment_status', 20)->nullable();
            $table->string('payment_status', 10)->default('unpaid'); // unpaid | partial | paid | refunded
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // cashier
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('delivery_fee', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance_due', 15, 2)->default(0);
            $table->decimal('change_given', 15, 2)->default(0);
            $table->decimal('cost_total', 15, 2)->default(0);     // COGS of what was sold
            $table->decimal('refunded_total', 15, 2)->default(0); // value reversed by returns (incl. tax)
            $table->decimal('refunded_tax', 15, 2)->default(0);
            $table->decimal('returned_cost_total', 15, 2)->default(0);
            $table->string('delivery_name')->nullable();
            $table->string('delivery_phone', 30)->nullable();
            $table->string('delivery_address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('offline')->default(false);
            $table->timestamp('offline_created_at')->nullable();
            $table->timestamp('completed_at')->nullable(); // revenue date
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'completed_at']);
            $table->index(['channel', 'status', 'created_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['user_id', 'completed_at']);
            $table->index(['shift_id']);
            $table->index(['balance_due']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_bundle')->default(false);
            $table->string('name');   // snapshot at time of sale
            $table->string('sku', 64);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount', 15, 2)->default(0); // line discount (whole line, not per unit)
            $table->decimal('line_total', 15, 2);           // qty*unit_price - discount (excl. added tax)
            $table->decimal('tax', 15, 2)->default(0);      // tax portion of amount (inclusive or added)
            $table->decimal('amount', 15, 2);               // what the customer pays for this line
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('cost_total', 15, 2)->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'sale_id']);
        });

        // For bundle lines: which real products were deducted, at what cost. Lets returns
        // restock exact components and keeps bundle COGS historically correct.
        Schema::create('sale_item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity'); // total units for the whole line
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('cost_total', 15, 2);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10)->default('payment'); // payment | refund
            $table->decimal('amount', 15, 2);                // always positive; kind gives the sign
            $table->string('reference', 100)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->index(['paid_at', 'payment_method_id']);
            $table->index(['shift_id', 'payment_method_id']);
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_pos CHECK (amount > 0)');

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->string('number', 40)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason');
            $table->decimal('refund_total', 15, 2);  // value reversed incl. tax
            $table->decimal('refund_tax', 15, 2)->default(0);
            $table->decimal('cash_refunded', 15, 2); // money actually handed back (≤ refund_total)
            $table->decimal('cost_reversed', 15, 2);
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('amount', 15, 2);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('cost', 15, 2);            // cost share of the returned units
            $table->boolean('restock')->default(true); // false = damaged: not restocked, cost stays in COGS
        });

        Schema::create('offline_conflicts', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->string('reason');
            $table->string('status', 12)->default('open'); // open | resolved | voided
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['offline_conflicts', 'sale_return_items', 'sale_returns', 'payments', 'sale_item_components',
            'sale_items', 'sales', 'shifts', 'payment_methods', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
