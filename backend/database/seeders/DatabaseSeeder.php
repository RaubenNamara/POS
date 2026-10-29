<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Production-safe base data only: a location, payment methods, expense categories and the
 * owner account. No products: the catalog is entered by the shop (or DemoSeeder in dev).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        DB::table('locations')->insertOrIgnore([
            ['name' => 'Main Shop', 'code' => 'MAIN', 'type' => 'shop', 'is_active' => 1, 'is_default' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $methods = [
            ['Cash', 'cash', 'cash', 0], ['MTN Mobile Money', 'mtn_momo', 'mobile_money', 1], ['Airtel Money', 'airtel_money', 'mobile_money', 1],
            ['Bank transfer', 'bank', 'bank', 1], ['Card', 'card', 'card', 1],
        ];
        foreach ($methods as $i => [$name, $code, $type, $ref]) {
            DB::table('payment_methods')->insertOrIgnore(['name' => $name, 'code' => $code, 'type' => $type, 'requires_reference' => $ref,
                'is_active' => 1, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (['Rent', 'Salaries', 'Electricity', 'Water', 'Internet & airtime', 'Transport', 'Repairs', 'Marketing', 'Taxes & licences', 'Bank charges', 'Other'] as $c) {
            DB::table('expense_categories')->insertOrIgnore(['name' => $c, 'created_at' => $now, 'updated_at' => $now]);
        }

        $email = env('SHOP_OWNER_EMAIL', 'owner@shop.local');
        if (! User::where('email', $email)->exists()) {
            $password = env('SHOP_OWNER_PASSWORD') ?: Str::password(14, symbols: false);
            $u = new User(['name' => env('SHOP_OWNER_NAME', 'Shop Owner'), 'email' => $email, 'password' => $password]);
            $u->role = 'owner';
            $u->save();
            if (! env('SHOP_OWNER_PASSWORD')) {
                $this->command?->warn("Owner account created: {$email} / {$password}  (change it after first login)");
            }
        }
    }
}
