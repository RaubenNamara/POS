<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];

    protected $casts = [
        'tax' => 'decimal:2',
        'amount' => 'decimal:2',
        'cost' => 'decimal:2',
        'restock' => 'boolean',
    ];

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
