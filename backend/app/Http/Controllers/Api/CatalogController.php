<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\InventoryService;
use App\Support\HomeSections;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Public storefront reads. Responses are small (only the fields a card needs),
 * paginated, cached briefly server-side and marked cacheable for browsers/CDN.
 * The cache key includes a catalog version bumped on every stock movement or product save,
 * so availability is never stale for longer than one request.
 */
class CatalogController extends Controller
{
    private const CARD = ['id', 'type', 'name', 'slug', 'sku', 'selling_price', 'compare_at_price', 'total_stock', 'image_path', 'category_id', 'brand_id', 'is_featured'];

    public function home()
    {
        $data = $this->cached('home', function () {
            $base = fn () => Product::online()->select(self::CARD)->with('bundleItems.product:id,total_stock');
            $tree = $this->categoryTree();

            // One home section per main category (with products), best/featured first.
            $sections = [];
            $section = function (string $key) use ($base) {
                $q = $base();
                $limit = HomeSections::apply($q, $key);

                return $q->limit($limit)->get();
            };
            foreach ($tree as $cat) {
                $items = $section("cat:{$cat['id']}");
                if ($items->count() >= 2) {
                    $sections[] = ['category' => ['name' => $cat['name'], 'slug' => $cat['slug']], 'items' => $this->cards($items)];
                }
            }

            return [
                'settings' => Settings::public(),
                'categories' => $tree,
                'featured' => $this->cards($base()->where('is_featured', true)->latest('id')->limit(12)->get()),
                'deals' => $this->cards($section('deals')),
                'new_arrivals' => $this->cards($section('new')),
                'bundles' => $this->cards($section('packages')),
                'category_sections' => $sections,
                'ads' => \App\Models\Ad::live()->orderBy('sort_order')->orderByDesc('id')
                    ->get(['id', 'title', 'subtitle', 'placement', 'image_path', 'image_mobile_path', 'width', 'height', 'link_url', 'show_text'])
                    ->groupBy('placement')->map->values(),
            ];
        });

        return $this->publicJson($data);
    }

    public function categories()
    {
        return $this->publicJson($this->cached('categories', fn () => $this->categoryTree()));
    }

    public function products(Request $request)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:120',
            'brand' => 'nullable|string|max:120',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'in_stock' => 'nullable|boolean',
            'on_sale' => 'nullable|boolean',
            'type' => 'nullable|in:standard,bundle',
            'sort' => 'nullable|in:relevance,newest,price_asc,price_desc,name',
            'page' => 'nullable|integer|min:1|max:1000',
            'per_page' => 'nullable|integer|min:1|max:48',
        ]);
        $key = 'products:'.md5(json_encode($v));

        return $this->publicJson($this->cached($key, function () use ($v) {
            $q = Product::online()->select(self::CARD)->with('bundleItems.product:id,total_stock')->search($v['q'] ?? null);
            if (! empty($v['category'])) {
                $cat = Category::where('slug', $v['category'])->first();
                $ids = $cat ? $this->descendantIds($cat->id) : [0];
                $q->whereIn('category_id', $ids);
            }
            if (! empty($v['brand'])) {
                $q->whereIn('brand_id', Brand::where('slug', $v['brand'])->pluck('id'));
            }
            if (isset($v['min_price'])) {
                $q->where('selling_price', '>=', $v['min_price']);
            }
            if (isset($v['max_price'])) {
                $q->where('selling_price', '<=', $v['max_price']);
            }
            if (! empty($v['on_sale'])) {
                $q->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'selling_price');
            }
            if (! empty($v['type'])) {
                $q->where('type', $v['type']);
            }
            if (! empty($v['in_stock'])) {
                // Bundles: approximated by "has components"; exact availability is shown on the card.
                $q->where(fn ($w) => $w->where('total_stock', '>', 0)->orWhere('type', 'bundle'));
            }
            match ($v['sort'] ?? (empty($v['q']) ? 'newest' : 'relevance')) {
                'price_asc' => $q->orderBy('selling_price'),
                'price_desc' => $q->orderByDesc('selling_price'),
                'name' => $q->orderBy('name'),
                'newest' => $q->orderByDesc('id'),
                default => $q->orderByDesc('is_featured')->orderByDesc('id'),
            };
            $page = $q->paginate($v['per_page'] ?? 24);
            $brands = Brand::whereIn('id', Product::online()->select('brand_id')->whereNotNull('brand_id')->distinct())->orderBy('name')->get(['id', 'name', 'slug']);

            return [
                'data' => $this->cards($page->getCollection()),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
                'brands' => $brands,
            ];
        }));
    }

    public function product(string $slug)
    {
        return $this->publicJson($this->cached("product:{$slug}", function () use ($slug) {
            $p = Product::online()->where('slug', $slug)
                ->with(['images:id,product_id,path,thumb_path,width,height', 'category:id,name,slug,parent_id', 'brand:id,name,slug',
                    'bundleItems.product:id,name,slug,sku,selling_price,total_stock,image_path'])
                ->firstOrFail();
            $related = Product::online()->select(self::CARD)->with('bundleItems.product:id,total_stock')
                ->where('category_id', $p->category_id)->where('id', '!=', $p->id)->latest('id')->limit(8)->get();
            $inBundles = Product::online()->select(self::CARD)->with('bundleItems.product:id,total_stock')->where('type', 'bundle')
                ->whereIn('id', \App\Models\BundleItem::where('product_id', $p->id)->select('bundle_id'))->limit(4)->get();

            return [
                'product' => [
                    ...$this->card($p),
                    'description' => $p->description,
                    'specifications' => $p->specifications,
                    'model' => $p->model,
                    'images' => $p->images,
                    'category' => $p->category,
                    'brand' => $p->brand,
                    'components' => $p->isBundle() ? $p->bundleItems->map(fn ($b) => [
                        'quantity' => $b->quantity, 'name' => $b->product->name, 'slug' => $b->product->slug,
                        'unit_price' => $b->product->selling_price, 'image_path' => $b->product->image_path,
                    ]) : [],
                    'components_value' => $p->isBundle() ? (string) $p->bundleItems->reduce(fn ($c, $b) => bcadd($c, bcmul((string) $b->product->selling_price, (string) $b->quantity, 2), 2), '0') : null,
                ],
                'related' => $this->cards($related),
                'in_bundles' => $this->cards($inBundles),
            ];
        }));
    }

    // ---------------------------------------------------------------- helpers

    private function cards($products): array
    {
        return $products->map(fn ($p) => $this->card($p))->values()->all();
    }

    private function card(Product $p): array
    {
        $available = $p->isBundle() ? InventoryService::bundleAvailability($p) : max(0, (int) $p->total_stock);

        return [
            'id' => $p->id, 'type' => $p->type, 'name' => $p->name, 'slug' => $p->slug, 'sku' => $p->sku,
            'price' => (string) $p->selling_price,
            'compare_at_price' => $p->compare_at_price !== null ? (string) $p->compare_at_price : null,
            'image' => $p->image_path,
            // Exact counts are not exposed publicly; enough to show "only N left".
            'available' => min($available, 99),
            'in_stock' => $available > 0,
            'category_id' => $p->category_id,
        ];
    }

    private function categoryTree(): array
    {
        $all = Category::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'parent_id', 'name', 'slug', 'image_path']);
        $byParent = $all->groupBy(fn ($c) => $c->parent_id ?? 0);
        $build = function ($pid) use (&$build, $byParent) {
            return ($byParent[$pid] ?? collect())->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'image' => $c->image_path, 'children' => $build($c->id),
            ])->values()->all();
        };

        return $build(0);
    }

    private function descendantIds(int $id): array
    {
        $all = Category::get(['id', 'parent_id'])->groupBy(fn ($c) => $c->parent_id ?? 0);
        $out = [$id];
        $stack = [$id];
        while ($stack) {
            foreach ($all[array_pop($stack)] ?? [] as $c) {
                $out[] = $c->id;
                $stack[] = $c->id;
            }
        }

        return $out;
    }

    private function cached(string $key, \Closure $fn)
    {
        $version = Cache::get('catalog_version', 0);

        return Cache::remember("catalog:{$version}:{$key}", 300, $fn);
    }

    private function publicJson($data)
    {
        // Browsers/CDN may reuse for 30 s and serve stale for 2 min while revalidating.
        return response()->json($data)->header('Cache-Control', 'public, max-age=30, stale-while-revalidate=120');
    }
}
