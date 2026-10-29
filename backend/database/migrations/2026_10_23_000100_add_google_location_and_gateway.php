<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Google sign-in, customer location / delivery zones, and online payment (Flutterwave).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('google_id', 64)->nullable()->unique()->after('email');
            $t->string('avatar_url')->nullable()->after('google_id');
            $t->string('password')->nullable()->change(); // Google-only customers have no password
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->decimal('latitude', 10, 7)->nullable()->after('address');
            $t->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $t->string('location_label')->nullable()->after('longitude'); // human address from Google
            $t->string('district', 80)->nullable()->after('location_label');
            // metro = Kampala/Wakiso (pay on delivery allowed) | outside | unknown
            $t->string('delivery_zone', 10)->nullable()->after('district');
            $t->timestamp('location_checked_at')->nullable()->after('delivery_zone');
        });

        Schema::table('sales', function (Blueprint $t) {
            // cod = pay on delivery, online = gateway, pickup = pay at the shop
            $t->string('payment_option', 10)->nullable()->after('payment_status');
            $t->decimal('delivery_lat', 10, 7)->nullable()->after('delivery_address');
            $t->decimal('delivery_lng', 10, 7)->nullable()->after('delivery_lat');
            $t->string('delivery_zone', 10)->nullable()->after('delivery_lng');
            $t->string('delivery_district', 80)->nullable()->after('delivery_zone');
            // Unpaid online-payment orders release their stock after this time.
            $t->timestamp('payment_expires_at')->nullable()->after('cancelled_at');
            $t->index(['payment_option', 'status', 'payment_expires_at'], 'sales_gateway_expiry');
        });

        Schema::create('gateway_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained()->restrictOnDelete();
            $t->string('provider', 20)->default('flutterwave');
            $t->string('tx_ref', 64)->unique();                   // our reference sent to the gateway
            $t->string('provider_tx_id', 64)->nullable()->unique(); // gateway's transaction id (once paid)
            $t->decimal('amount', 15, 2);
            $t->string('currency', 3);
            // initiated | successful | failed | mismatch (paid amount/currency differs: needs review)
            $t->string('status', 12)->default('initiated');
            $t->string('checkout_url', 500)->nullable();
            $t->json('provider_response')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->index(['sale_id', 'status']);
        });

        DB::table('payment_methods')->insertOrIgnore([
            'name' => 'Online (Flutterwave)', 'code' => 'flutterwave', 'type' => 'other', 'requires_reference' => 1,
            'is_active' => 1, 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('payment_methods')->where('code', 'flutterwave')->whereNotExists(fn ($q) => $q->from('payments')->whereColumn('payments.payment_method_id', 'payment_methods.id'))->delete();
        Schema::dropIfExists('gateway_transactions');
        Schema::table('sales', function (Blueprint $t) {
            $t->dropIndex('sales_gateway_expiry');
            $t->dropColumn(['payment_option', 'delivery_lat', 'delivery_lng', 'delivery_zone', 'delivery_district', 'payment_expires_at']);
        });
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude', 'location_label', 'district', 'delivery_zone', 'location_checked_at']));
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['google_id', 'avatar_url']);
        });
    }
};
