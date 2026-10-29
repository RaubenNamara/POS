<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DEVELOPMENT / DEMO DATA ONLY, never run against a live shop.
 *   php artisan db:seed --class=DemoSeeder
 * Opening stock goes through InventoryService so movements, totals and costs are consistent.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'DemoPass123';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder refuses to run in production.');

            return;
        }
        $this->call(DatabaseSeeder::class);

        foreach ([
            ['Demo Owner', 'owner@demo.local', 'owner', '0700000001'],
            ['Demo Manager', 'manager@demo.local', 'manager', '0700000002'],
            ['Demo Cashier', 'cashier@demo.local', 'cashier', '0700000003'],
            ['Demo Cashier Two', 'cashier2@demo.local', 'cashier', '0700000005'],
            ['Demo Stock Keeper', 'stock@demo.local', 'stock_manager', '0700000004'],
            ['Demo Shopper', 'shopper@demo.local', 'customer', '0700000009'],
        ] as [$name, $email, $role, $phone]) {
            $u = User::firstOrNew(['email' => $email]);
            $u->fill(['name' => $name, 'phone' => $phone, 'password' => self::PASSWORD]);
            $u->role = $role;
            $u->save();
            if ($role === 'customer') {
                Customer::firstOrCreate(['user_id' => $u->id], ['name' => $name, 'email' => $email, 'phone' => $phone]);
            }
        }
        Customer::firstOrCreate(['phone' => '0772000111'], ['name' => 'Kato Trading (credit)', 'credit_limit' => 2000000]);
        Customer::firstOrCreate(['phone' => '0752000222'], ['name' => 'Nakato Grace', 'credit_limit' => 0]);

        $tree = [
            'Phones & Tablets' => ['Smartphones', 'Tablets', 'Phone Accessories'],
            'Computing' => ['Laptops', 'Computer Accessories', 'Storage'],
            'Electronics' => ['Televisions', 'Audio', 'Power & Solar'],
            'Home & Kitchen' => ['Small Appliances', 'Cookware'],
            'Health & Beauty' => [],
            'Supermarket' => ['Beverages', 'Household'],
        ];
        $cats = [];
        $i = 0;
        foreach ($tree as $parent => $children) {
            $p = Category::firstOrCreate(['slug' => Str::slug($parent)], ['name' => $parent, 'sort_order' => $i++]);
            $cats[$parent] = $p->id;
            foreach ($children as $j => $child) {
                $cats[$child] = Category::firstOrCreate(['slug' => Str::slug($child)], ['name' => $child, 'parent_id' => $p->id, 'sort_order' => $j])->id;
            }
        }

        $brands = [];
        foreach (['Tecno', 'Samsung', 'Infinix', 'HP', 'Lenovo', 'Dell', 'Oraimo', 'Hisense', 'Sony', 'Ramtons', 'Nivea', 'Generic'] as $b) {
            $brands[$b] = Brand::firstOrCreate(['slug' => Str::slug($b)], ['name' => $b])->id;
        }
        $sup = [
            'Kampala Electronics Wholesale' => Supplier::firstOrCreate(['name' => 'Kampala Electronics Wholesale'], ['phone' => '0414000001', 'contact_person' => 'Okello'])->id,
            'Mukwano Distributors' => Supplier::firstOrCreate(['name' => 'Mukwano Distributors'], ['phone' => '0414000002', 'contact_person' => 'Aisha'])->id,
        ];

        // name, category, brand, model, cost, price, compare_at, stock, min, featured
        $products = [
            ['Tecno Spark 20 Pro 256GB', 'Smartphones', 'Tecno', 'KJ6', 540000, 649000, 720000, 14, 3, true],
            ['Samsung Galaxy A15 128GB', 'Smartphones', 'Samsung', 'SM-A155F', 610000, 735000, null, 9, 3, true],
            ['Infinix Hot 40i 128GB', 'Smartphones', 'Infinix', 'X6528', 395000, 469000, 499000, 20, 4, false],
            ['Samsung Galaxy Tab A9 64GB', 'Tablets', 'Samsung', 'SM-X115', 690000, 829000, null, 5, 2, false],
            ['Oraimo FreePods 4 Earbuds', 'Phone Accessories', 'Oraimo', 'OEB-E06D', 95000, 139000, 159000, 30, 6, true],
            ['Oraimo 20000mAh Power Bank', 'Phone Accessories', 'Oraimo', 'OPB-P206D', 72000, 99000, null, 25, 5, false],
            ['Oraimo 18W Fast Charger', 'Phone Accessories', 'Oraimo', 'OCW-U72', 18000, 29000, null, 60, 10, false],
            ['Tempered Glass Screen Protector', 'Phone Accessories', 'Generic', null, 2500, 10000, null, 200, 30, false],
            ['HP 250 G9 Laptop i5 8GB 512GB SSD', 'Laptops', 'HP', '250 G9', 1850000, 2250000, 2450000, 6, 2, true],
            ['Lenovo IdeaPad 3 Ryzen 5 8GB 256GB', 'Laptops', 'Lenovo', '15ALC6', 1700000, 2050000, null, 4, 2, true],
            ['Dell Latitude 5420 i7 (Refurbished)', 'Laptops', 'Dell', '5420', 1450000, 1890000, null, 3, 1, false],
            ['Wireless Optical Mouse', 'Computer Accessories', 'HP', 'X200', 18000, 35000, null, 40, 8, false],
            ['15.6" Laptop Backpack', 'Computer Accessories', 'Generic', null, 38000, 65000, 80000, 22, 5, false],
            ['USB Keyboard', 'Computer Accessories', 'Dell', 'KB216', 25000, 45000, null, 15, 4, false],
            ['Laptop Cooling Pad', 'Computer Accessories', 'Generic', null, 30000, 55000, null, 0, 3, false],
            ['SanDisk 64GB Flash Disk', 'Storage', 'Generic', 'Ultra Flair', 22000, 38000, null, 50, 10, false],
            ['1TB External Hard Drive', 'Storage', 'Generic', null, 175000, 235000, null, 7, 2, false],
            ['Hisense 43" Smart TV', 'Televisions', 'Hisense', '43A4K', 980000, 1199000, 1350000, 5, 2, true],
            ['Hisense 32" LED TV', 'Televisions', 'Hisense', '32A4', 520000, 640000, null, 8, 2, false],
            ['Sony Bluetooth Speaker', 'Audio', 'Sony', 'SRS-XB13', 160000, 215000, null, 2, 3, false],
            ['Surge Protector Extension (4-way)', 'Power & Solar', 'Generic', null, 22000, 39000, null, 35, 8, false],
            ['100W Solar Panel Kit', 'Power & Solar', 'Generic', null, 310000, 420000, null, 4, 1, false],
            ['Ramtons Electric Kettle 1.7L', 'Small Appliances', 'Ramtons', 'RM/399', 58000, 89000, 99000, 12, 3, false],
            ['Ramtons 2-Slice Toaster', 'Small Appliances', 'Ramtons', 'RM/296', 74000, 110000, null, 6, 2, false],
            ['Non-stick Frying Pan 28cm', 'Cookware', 'Generic', null, 28000, 49000, null, 18, 4, false],
            ['Nivea Body Lotion 400ml', 'Health & Beauty', 'Nivea', null, 16500, 24000, null, 48, 12, false],
            ['Mineral Water 500ml x 24', 'Beverages', 'Generic', null, 14000, 19000, null, 40, 10, false],
            ['Laundry Detergent 2kg', 'Household', 'Generic', null, 19000, 27000, null, 30, 8, false],
        ];

        $loc = Location::where('is_default', true)->first();
        $inventory = app(InventoryService::class);
        $owner = User::where('email', 'owner@demo.local')->first();
        auth()->setUser($owner);

        DB::transaction(function () use ($products, $cats, $brands, $sup, $loc, $inventory) {
            $n = 1;
            foreach ($products as [$name, $cat, $brand, $model, $cost, $price, $compare, $stock, $min, $featured]) {
                $p = Product::firstOrNew(['slug' => Str::slug($name)]);
                if ($p->exists) {
                    continue;
                }
                $p->fill([
                    'name' => $name, 'category_id' => $cats[$cat], 'brand_id' => $brands[$brand], 'model' => $model,
                    'supplier_id' => in_array($cat, ['Beverages', 'Household', 'Health & Beauty'], true) ? $sup['Mukwano Distributors'] : $sup['Kampala Electronics Wholesale'],
                    'selling_price' => $price, 'compare_at_price' => $compare, 'min_stock' => $min, 'is_featured' => $featured,
                    'description' => "{$name}. Genuine product with shop warranty where applicable.",
                    'specifications' => array_values(array_filter([
                        $model ? ['label' => 'Model', 'value' => $model] : null,
                        ['label' => 'Brand', 'value' => $brand],
                        ['label' => 'Warranty', 'value' => in_array($cat, ['Laptops', 'Smartphones', 'Televisions', 'Tablets'], true) ? '12 months' : '3 months'],
                    ])),
                ]);
                $p->sku = 'DM-'.str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
                $p->barcode = '6' . str_pad((string) (100000000000 + $n), 12, '0', STR_PAD_LEFT);
                $p->save();
                if ($stock > 0) {
                    $inventory->move('opening', $loc->id, [['product_id' => $p->id, 'quantity' => $stock, 'unit_cost' => (string) $cost]], $p, 'Opening stock (demo)');
                }
            }

            // A real bundle: the components are the actual stocked products above.
            if (! Product::where('slug', 'complete-laptop-package-hp-250')->exists()) {
                $bundle = new Product([
                    'type' => 'bundle', 'name' => 'Complete Laptop Package (HP 250)', 'category_id' => $cats['Laptops'], 'brand_id' => $brands['HP'],
                    'selling_price' => 2290000, 'compare_at_price' => 2395000, 'is_featured' => true,
                    'description' => 'HP 250 G9 laptop with wireless mouse, laptop backpack and 64GB flash disk. Save versus buying separately.',
                ]);
                $bundle->sku = 'BND-0001';
                $bundle->slug = 'complete-laptop-package-hp-250';
                $bundle->save();
                foreach ([['HP 250 G9 Laptop i5 8GB 512GB SSD', 1], ['Wireless Optical Mouse', 1], ['15.6" Laptop Backpack', 1], ['SanDisk 64GB Flash Disk', 1]] as [$name, $q]) {
                    $bundle->bundleItems()->create(['product_id' => Product::where('slug', Str::slug($name))->value('id'), 'quantity' => $q]);
                }
            }
        });
        $this->command?->info('Demo data ready. Staff logins: owner@demo.local / manager@ / cashier@ / stock@ (password '.self::PASSWORD.')');
    }
}
