<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;

/**
 * The storefront home-page sections, defined once. The storefront builds the home page from
 * these rules and the back office "Home page section" filter uses the very same rules, so
 * staff always see exactly what customers see, in the same order.
 *
 *   deals       Top Deals: "Was" price above the selling price (12 shown)
 *   cat:{id}    one per main category, including its subcategories (12 shown, needs 2+)
 *   packages    Save More with Packages: bundle products (8 shown)
 *   new         New Arrivals: newest products (12 shown)
 */
final class HomeSections
{
    public const LIMITS = ['deals' => 12, 'category' => 12, 'packages' => 8, 'new' => 12];

    /** Restrict and order $q (already limited to online products) for one section; returns the home-page limit. */
    public static function apply(Builder $q, string $section): int
    {
        if ($section === 'deals') {
            $q->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'selling_price')->orderByDesc('updated_at')->orderByDesc('id');

            return self::LIMITS['deals'];
        }
        if ($section === 'packages') {
            $q->where('type', 'bundle')->orderByDesc('id');

            return self::LIMITS['packages'];
        }
        if ($section === 'new') {
            $q->orderByDesc('id');

            return self::LIMITS['new'];
        }
        if (preg_match('/^cat:(\d+)$/', $section, $m)) {
            $q->whereIn('category_id', self::categoryWithChildren((int) $m[1]))
                ->orderByDesc('is_featured')->orderByRaw('total_stock > 0 DESC')->orderByDesc('id');

            return self::LIMITS['category'];
        }
        throw new \InvalidArgumentException("Unknown home section {$section}");
    }

    /** @return list<int> the category and every category below it */
    public static function categoryWithChildren(int $id): array
    {
        $byParent = Category::get(['id', 'parent_id'])->groupBy(fn ($c) => $c->parent_id ?? 0);
        $out = [$id];
        $stack = [$id];
        while ($stack) {
            foreach ($byParent[array_pop($stack)] ?? [] as $c) {
                $out[] = $c->id;
                $stack[] = $c->id;
            }
        }

        return $out;
    }
}
