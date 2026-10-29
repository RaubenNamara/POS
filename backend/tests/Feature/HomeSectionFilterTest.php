<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HomeSectionFilterTest extends TestCase
{
    public function test_back_office_section_filter_matches_the_home_page_exactly(): void
    {
        $computing = Category::create(['name' => 'Computing', 'slug' => 'computing']);
        $laptops = Category::create(['name' => 'Laptops', 'slug' => 'laptops', 'parent_id' => $computing->id]);
        $this->product('100', '1000', 5, ['name' => 'Mouse', 'category_id' => $computing->id]);
        $this->product('100', '2000', 5, ['name' => 'Laptop A', 'category_id' => $laptops->id, 'compare_at_price' => '2500']);
        $this->product('100', '3000', 0, ['name' => 'Laptop B', 'category_id' => $laptops->id, 'is_featured' => true]);
        $this->product('100', '900', 5, ['name' => 'Hidden', 'category_id' => $laptops->id, 'show_online' => false, 'compare_at_price' => '1000']);
        $this->bundle([[$this->product('10', '50', 9, ['name' => 'Bag'])->fresh(), 1]], '60');

        Cache::flush();
        $home = $this->getJson('/api/shop/home')->assertOk()->json();
        $owner = $this->staff('owner');
        $names = fn (string $section) => array_column($this->actingAs($owner)->getJson('/api/admin/products?section='.urlencode($section))->assertOk()->json('data'), 'name');

        $this->assertSame(array_column($home['deals'], 'name'), $names('deals'));
        $this->assertSame(['Laptop A'], $names('deals'), 'hidden-online products never count');
        $this->assertSame(array_column($home['category_sections'][0]['items'], 'name'), $names("cat:{$computing->id}"));
        $this->assertSame(['Laptop B', 'Laptop A', 'Mouse'], $names("cat:{$computing->id}"), 'featured first, subcategories included');
        $this->assertSame(array_column($home['bundles'], 'name'), $names('packages'));
        $this->assertSame(array_column($home['new_arrivals'], 'name'), $names('new'));

        $this->actingAs($owner)->getJson('/api/admin/products?section=deals')->assertJsonPath('home_limit', 12);
        // Category filter now includes subcategories too.
        $this->assertCount(4, $this->actingAs($owner)->getJson("/api/admin/products?category_id={$computing->id}")->json('data'));
    }
}
