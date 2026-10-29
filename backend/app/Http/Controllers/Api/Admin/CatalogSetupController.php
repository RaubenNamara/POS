<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Categories, brands, locations and payment methods: small lookup tables. */
class CatalogSetupController extends Controller
{
    public function categories()
    {
        return Category::withCount('products')->orderBy('parent_id')->orderBy('sort_order')->orderBy('name')->get();
    }

    public function saveCategory(Request $request, ?Category $category = null)
    {
        $d = $request->validate([
            'name' => 'required|string|max:120',
            'parent_id' => ['nullable', 'exists:categories,id', Rule::notIn([$category?->id])],
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'is_active' => 'nullable|boolean',
            'image_path' => 'nullable|string|max:255',
        ]);
        if ($category && ! empty($d['parent_id'])) {
            // Refuse cycles: the new parent may not be a descendant of this category.
            $pid = (int) $d['parent_id'];
            while ($pid) {
                if ($pid === $category->id) {
                    throw new BusinessRuleException('A category cannot be moved under its own subcategory', 'cycle');
                }
                $pid = (int) Category::whereKey($pid)->value('parent_id');
            }
        }
        $category ??= new Category;
        $category->fill($d + ['is_active' => true]);
        if (! $category->exists || $category->isDirty('name')) {
            $category->slug = $this->slug(Category::class, $d['name'], $category->id);
        }
        $category->save();

        return response()->json($category, $category->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteCategory(Category $category)
    {
        if ($category->products()->exists() || $category->children()->exists()) {
            throw new BusinessRuleException('Move its products and subcategories first, or deactivate it', 'in_use');
        }
        $category->delete();

        return response()->noContent();
    }

    public function brands()
    {
        return Brand::withCount('products')->orderBy('name')->get();
    }

    public function saveBrand(Request $request, ?Brand $brand = null)
    {
        $d = $request->validate(['name' => 'required|string|max:120']);
        $brand ??= new Brand;
        $brand->name = $d['name'];
        $brand->slug = $this->slug(Brand::class, $d['name'], $brand->id);
        $brand->save();

        return response()->json($brand, $brand->wasRecentlyCreated ? 201 : 200);
    }

    public function locations()
    {
        return Location::orderByDesc('is_default')->orderBy('name')->get();
    }

    public function saveLocation(Request $request, ?Location $location = null)
    {
        $d = $request->validate([
            'name' => 'required|string|max:120',
            'code' => ['required', 'string', 'max:20', Rule::unique('locations', 'code')->ignore($location?->id)],
            'type' => 'required|in:shop,warehouse',
            'address' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);
        $location ??= new Location;
        $location->fill($d)->save();
        Audit::log('location.saved', $location, null, $d);

        return response()->json($location, $location->wasRecentlyCreated ? 201 : 200);
    }

    public function paymentMethods()
    {
        return PaymentMethod::orderBy('sort_order')->get();
    }

    public function savePaymentMethod(Request $request, ?PaymentMethod $method = null)
    {
        $d = $request->validate([
            'name' => 'required|string|max:60',
            'code' => ['required', 'alpha_dash', 'max:30', Rule::unique('payment_methods', 'code')->ignore($method?->id)],
            'type' => 'required|in:cash,mobile_money,bank,card,other',
            'requires_reference' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        $method ??= new PaymentMethod;
        $method->fill($d)->save();
        Audit::log('payment_method.saved', $method, null, $d);

        return response()->json($method, $method->wasRecentlyCreated ? 201 : 200);
    }

    private function slug(string $model, string $name, ?int $ignore): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $i = 2;
        while ($model::where('slug', $slug)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
