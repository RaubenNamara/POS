<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Audit;
use App\Services\InventoryService;
use App\Services\SaleService;
use App\Support\HomeSections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100', 'category_id' => 'nullable|integer', 'brand_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer', 'type' => 'nullable|in:standard,bundle',
            'stock' => 'nullable|in:low,out,in', 'archived' => 'nullable|boolean', 'on_sale' => 'nullable|boolean',
            'section' => ['nullable', 'regex:/^(deals|packages|new|cat:\d+)$/'],
            'sort' => 'nullable|in:name,newest,stock,price', 'per_page' => 'nullable|integer|min:1|max:200',
        ]);
        $q = Product::query()->search($v['q'] ?? null)
            ->with(['category:id,name', 'brand:id,name', 'bundleItems.product:id,total_stock'])
            ->select(['id', 'type', 'name', 'slug', 'sku', 'barcode', 'model', 'category_id', 'brand_id', 'cost_price', 'selling_price',
                'compare_at_price', 'min_stock', 'total_stock', 'image_path', 'is_active', 'is_featured', 'show_online', 'archived_at']);
        ! empty($v['archived']) ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
        foreach (['brand_id', 'supplier_id', 'type'] as $f) {
            if (! empty($v[$f])) {
                $q->where($f, $v[$f]);
            }
        }
        // A category includes its subcategories (as on the storefront).
        if (! empty($v['category_id'])) {
            $q->whereIn('category_id', HomeSections::categoryWithChildren((int) $v['category_id']));
        }
        // Home page section: exactly the storefront's rules and order (online products only).
        $homeLimit = null;
        if (! empty($v['section'])) {
            $q->where('is_active', true)->where('show_online', true)->whereNull('archived_at');
            $homeLimit = HomeSections::apply($q, $v['section']);
        }
        // "Top Deals" on the storefront = products whose "Was" price is above the selling price.
        if (! empty($v['on_sale'])) {
            $q->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'selling_price');
        }
        match ($v['stock'] ?? null) {
            'out' => $q->where('type', 'standard')->where('total_stock', '<=', 0),
            'low' => $q->where('type', 'standard')->where('total_stock', '>', 0)->whereColumn('total_stock', '<=', 'min_stock'),
            'in' => $q->where('total_stock', '>', 0),
            default => null,
        };
        match (! empty($v['section']) ? 'section' : ($v['sort'] ?? 'newest')) {
            'section' => null, // keep the home page's order
            'name' => $q->orderBy('name'),
            'stock' => $q->orderBy('total_stock'),
            'price' => $q->orderBy('selling_price'),
            default => $q->orderByDesc('id'),
        };
        $page = $q->paginate($v['per_page'] ?? 25);
        $hideCost = ! $this->seesCost($request);
        $page->getCollection()->transform(function ($p) use ($hideCost) {
            $p->available = $p->isBundle() ? InventoryService::bundleAvailability($p) : $p->total_stock;
            unset($p->bundleItems);

            return $hideCost ? $p->makeHidden('cost_price') : $p;
        });

        // Tell the page how many of these customers actually see on the home page.
        return $homeLimit === null ? $page : array_merge($page->toArray(), [
            'home_limit' => $homeLimit,
            'home_visible' => $v['section'] === 'packages' || ! str_starts_with($v['section'], 'cat:') || $page->total() >= 2,
        ]);
    }

    public function show(Product $product)
    {
        $hideCost = ! $this->seesCost(request());
        $product->load(['category:id,name', 'brand:id,name', 'supplier:id,name', 'images',
            'stockLevels.location:id,name,code', 'bundleItems.product:id,name,sku,cost_price,selling_price,total_stock']);
        $product->available = $product->isBundle() ? InventoryService::bundleAvailability($product) : $product->total_stock;
        if ($product->isBundle()) {
            $product->components_cost = (string) $product->bundleItems->reduce(fn ($c, $b) => bcadd($c, bcmul((string) $b->product->cost_price, (string) $b->quantity, 4), 4), '0');
        }
        if ($hideCost) {
            $product->makeHidden(['cost_price', 'components_cost']);
            $product->bundleItems->each(fn ($b) => $b->product?->makeHidden('cost_price'));
        }

        return $product;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $product = DB::transaction(function () use ($data, $request) {
            $p = new Product($this->attrs($data));
            $p->sku = $data['sku'] ?: $this->generateSku($data['name']);
            $p->slug = $this->uniqueSlug($data['name']);
            $p->save();
            $this->syncComponents($p, $data);

            if ($p->type === 'standard' && ! empty($data['opening_quantity'])) {
                $loc = app(SaleService::class)->resolveLocation($data['opening_location_id'] ?? null);
                $this->inventory->move('opening', $loc->id, [[
                    'product_id' => $p->id, 'quantity' => (int) $data['opening_quantity'], 'unit_cost' => (string) ($data['opening_unit_cost'] ?? '0'),
                ]], $p, 'Opening stock');
            }
            Audit::log('product.created', $p, null, $p->only(['sku', 'name', 'selling_price']));

            return $p;
        });

        return response()->json($this->show($product->fresh()), 201);
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validated($request, $product);
        DB::transaction(function () use ($data, $product) {
            $old = $product->only(['name', 'sku', 'barcode', 'selling_price', 'min_stock', 'is_active']);
            $product->fill($this->attrs($data, $product));
            if (! empty($data['sku'])) {
                $product->sku = $data['sku'];
            }
            if ($product->isDirty('name')) {
                $product->slug = $this->uniqueSlug($data['name'], $product->id);
            }
            $product->save();
            $this->syncComponents($product, $data);
            Audit::log('product.updated', $product, $old, $product->only(array_keys($old)));
        });

        return $this->show($product->fresh());
    }

    public function archive(Product $product)
    {
        $product->update(['archived_at' => now(), 'is_active' => false]);
        Audit::log('product.archived', $product);

        return response()->noContent();
    }

    public function restore(Product $product)
    {
        $product->update(['archived_at' => null, 'is_active' => true]);
        Audit::log('product.restored', $product);

        return response()->noContent();
    }

    /** Hard delete only for mistakes that never moved stock or appeared in a sale/purchase. */
    public function destroy(Product $product)
    {
        $used = $product->movements()->exists() || DB::table('sale_items')->where('product_id', $product->id)->exists()
            || DB::table('bundle_items')->where('product_id', $product->id)->exists()
            || DB::table('purchase_order_items')->where('product_id', $product->id)->exists();
        if ($used) {
            throw new BusinessRuleException('This product has history; archive it instead of deleting', 'has_history');
        }
        DB::transaction(function () use ($product) {
            foreach ($product->images as $img) {
                Storage::disk('public')->delete([$img->path, $img->thumb_path]);
            }
            Audit::log('product.deleted', $product, $product->only(['sku', 'name']));
            $product->delete();
        });

        return response()->noContent();
    }

    /**
     * Images arrive already resized to WebP in the browser (large ~1000px + thumb ~320px),
     * which keeps uploads small on slow links and needs no image extension on the server.
     */
    public function uploadImage(Request $request, Product $product)
    {
        $request->validate([
            'image' => 'required|file|mimes:webp,jpg,jpeg,png,avif|max:3072',
            'thumb' => 'nullable|file|mimes:webp,jpg,jpeg,png,avif|max:512',
            'width' => 'nullable|integer|max:4000', 'height' => 'nullable|integer|max:4000',
        ]);
        $dir = 'products/'.$product->id;
        $path = $request->file('image')->store($dir, 'public');
        $thumb = $request->hasFile('thumb') ? $request->file('thumb')->store($dir, 'public') : $path;
        $img = $product->images()->create([
            'path' => $path, 'thumb_path' => $thumb,
            'width' => $request->integer('width') ?: null, 'height' => $request->integer('height') ?: null,
            'sort_order' => (int) $product->images()->max('sort_order') + 1,
        ]);
        if (! $product->image_path) {
            $product->update(['image_path' => $thumb]);
        }

        return response()->json($img, 201);
    }

    public function deleteImage(Product $product, ProductImage $image)
    {
        abort_unless($image->product_id === $product->id, 404);
        Storage::disk('public')->delete(array_unique([$image->path, $image->thumb_path]));
        $image->delete();
        if ($product->image_path === $image->thumb_path) {
            $product->update(['image_path' => $product->images()->value('thumb_path')]);
        }

        return response()->noContent();
    }

    public function setPrimaryImage(Product $product, ProductImage $image)
    {
        abort_unless($image->product_id === $product->id, 404);
        $product->update(['image_path' => $image->thumb_path]);

        return response()->noContent();
    }

    /** Barcode/SKU lookup used by scanners in stock receiving and the POS. */
    public function lookup(Request $request)
    {
        $code = trim((string) $request->query('code'));
        $p = Product::where('barcode', $code)->orWhere('sku', $code)->first(['id', 'type', 'name', 'sku', 'barcode', 'selling_price', 'cost_price', 'total_stock']);
        if ($p && ! $this->seesCost($request)) {
            $p->makeHidden('cost_price');
        }

        return $p ? response()->json($p) : response()->json(['message' => 'No product with that code'], 404);
    }

    // ---------------------------------------------------------------- helpers

    /** Cost prices are for people who buy stock or see profits, not for cashiers. */
    private function seesCost(Request $request): bool
    {
        $u = $request->user();

        return $u && ($u->can('reports.financial') || $u->can('purchases.receive'));
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $id = $product?->id;

        return $request->validate([
            'type' => [$product ? 'sometimes' : 'nullable', 'in:standard,bundle'],
            'name' => 'required|string|max:200',
            'sku' => ['nullable', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products', 'barcode')->ignore($id)],
            'model' => 'nullable|string|max:120',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'description' => 'nullable|string|max:20000',
            'specifications' => 'nullable|array|max:60',
            'specifications.*.label' => 'required|string|max:80',
            'specifications.*.value' => 'required|string|max:300',
            'selling_price' => 'required|numeric|min:0|max:9999999999999',
            'compare_at_price' => 'nullable|numeric|min:0|max:9999999999999',
            'min_stock' => 'nullable|integer|min:0|max:1000000',
            'is_active' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'show_online' => 'nullable|boolean',
            'opening_quantity' => $product ? 'prohibited' : 'nullable|integer|min:0|max:10000000',
            'opening_unit_cost' => $product ? 'prohibited' : 'nullable|required_with:opening_quantity|numeric|min:0',
            'opening_location_id' => 'nullable|exists:locations,id',
            'components' => 'nullable|array|max:50',
            'components.*.product_id' => 'required|integer|exists:products,id',
            'components.*.quantity' => 'required|integer|min:1|max:10000',
        ]);
    }

    private function attrs(array $d, ?Product $existing = null): array
    {
        $a = [
            'name' => $d['name'],
            'barcode' => $d['barcode'] ?? null ?: null,
            'model' => $d['model'] ?? null,
            'category_id' => $d['category_id'] ?? null,
            'brand_id' => $d['brand_id'] ?? null,
            'supplier_id' => $d['supplier_id'] ?? null,
            'description' => $d['description'] ?? null,
            'specifications' => $d['specifications'] ?? null,
            'selling_price' => $d['selling_price'],
            'compare_at_price' => $d['compare_at_price'] ?? null,
            'min_stock' => $d['min_stock'] ?? 0,
            'is_active' => $d['is_active'] ?? true,
            'is_featured' => $d['is_featured'] ?? false,
            'show_online' => $d['show_online'] ?? true,
        ];
        if (! $existing) {
            $a['type'] = $d['type'] ?? 'standard';
        }

        return $a;
    }

    private function syncComponents(Product $p, array $d): void
    {
        if (! $p->isBundle()) {
            if (! empty($d['components'])) {
                throw new BusinessRuleException('Only bundles have components', 'invalid_bundle');
            }

            return;
        }
        if (! array_key_exists('components', $d)) {
            return;
        }
        $components = collect($d['components'] ?? [])->groupBy('product_id')->map(fn ($g) => $g->sum('quantity'));
        if ($components->isEmpty()) {
            throw new BusinessRuleException('A bundle needs at least one component', 'invalid_bundle');
        }
        $types = Product::whereIn('id', $components->keys())->pluck('type', 'id');
        foreach ($components as $pid => $qty) {
            if ($pid == $p->id || ($types[$pid] ?? null) !== 'standard') {
                throw new BusinessRuleException('Bundle components must be ordinary products', 'invalid_bundle');
            }
        }
        $p->bundleItems()->delete();
        foreach ($components as $pid => $qty) {
            $p->bundleItems()->create(['product_id' => $pid, 'quantity' => $qty]);
        }
    }

    private function uniqueSlug(string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $i = 2;
        while (Product::where('slug', $slug)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function generateSku(string $name): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3)) ?: 'PRD';
        do {
            $sku = $prefix.'-'.strtoupper(Str::random(6));
        } while (Product::where('sku', $sku)->exists());

        return $sku;
    }
}
