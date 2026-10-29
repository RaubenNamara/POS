<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('type', 20)->default('shop'); // shop | warehouse
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('image_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['parent_id', 'is_active', 'sort_order']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            // Amount owed to the supplier before this system started tracking.
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10)->default('standard'); // standard | bundle
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('model', 120)->nullable();
            $table->text('description')->nullable();
            $table->json('specifications')->nullable();
            // Weighted-average unit cost across all locations. 4 dp so averaging doesn't drift.
            $table->decimal('cost_price', 15, 4)->default(0);
            $table->decimal('selling_price', 15, 2)->default(0);
            // Optional "was" price shown struck-through on the storefront.
            $table->decimal('compare_at_price', 15, 2)->nullable();
            $table->unsignedInteger('min_stock')->default(0);
            // Denormalised sum of stock_levels.quantity, kept in step inside the same
            // transaction as every movement so listings never need a SUM/JOIN.
            $table->integer('total_stock')->default(0);
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('show_online')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'show_online', 'archived_at', 'category_id']);
            $table->index(['is_featured', 'created_at']);
            $table->index('name');
            $table->index('total_stock');
        });
        DB::statement('ALTER TABLE products ADD FULLTEXT products_search_ft (name, model, sku, barcode)');

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');        // large (~1000px) WebP
            $table->string('thumb_path');  // ~320px WebP for grids
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['bundle_id', 'product_id']);
        });

        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'location_id']);
            $table->index(['location_id', 'quantity']);
        });
        // The database itself refuses negative stock, a last line of defence behind the service checks.
        DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_qty_nonneg CHECK (quantity >= 0)');

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            // opening | purchase | sale | sale_return | adjustment | damaged | missing |
            // order_cancel | transfer_in | transfer_out | count
            $table->string('type', 20);
            $table->integer('quantity');          // signed: + in, - out
            $table->integer('balance_after');     // location balance after this movement
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('reference');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('notifications_feed', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30); // low_stock | out_of_stock | offline_conflict | online_order
            $table->string('title');
            $table->string('body')->nullable();
            $table->nullableMorphs('subject');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['notifications_feed', 'audit_logs', 'stock_movements', 'stock_levels', 'bundle_items',
            'product_images', 'products', 'suppliers', 'brands', 'categories', 'locations', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
