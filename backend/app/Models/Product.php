<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $guarded = ['id', 'total_stock', 'cost_price'];

    protected $casts = [
        'specifications' => 'array',
        'cost_price' => 'decimal:4',
        'selling_price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'show_online' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /** Components when this product is a bundle. */
    public function bundleItems(): HasMany
    {
        return $this->hasMany(BundleItem::class, 'bundle_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isBundle(): bool
    {
        return $this->type === 'bundle';
    }

    public function scopeSellable(Builder $q): Builder
    {
        return $q->where('is_active', true)->whereNull('archived_at');
    }

    public function scopeOnline(Builder $q): Builder
    {
        return $q->sellable()->where('show_online', true);
    }

    /**
     * Name / SKU / barcode / model search. Exact barcode or SKU hits come first so a
     * scanner always lands on the right product; FULLTEXT handles words, LIKE handles
     * short fragments FULLTEXT ignores.
     */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.addcslashes($term, '%_\\').'%';
        $words = array_filter(preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $term)), fn ($w) => mb_strlen($w) >= 3);

        return $q->where(function (Builder $w) use ($term, $like, $words) {
            $w->where('sku', $term)->orWhere('barcode', $term)
                ->orWhere('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('model', 'like', $like);
            if ($words) {
                $w->orWhereRaw('MATCH(name, model, sku, barcode) AGAINST (? IN BOOLEAN MODE)', [implode(' ', array_map(fn ($x) => "+{$x}*", $words))]);
            }
        })->orderByRaw('(sku = ? OR barcode = ?) DESC', [$term, $term]);
    }
}
