<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClothingProduct extends Model
{
    protected $fillable = [
        'category_id',
        'key',
        'name',
        'color',
        'swatch',
        'price',
        'sizes',
        'image_path',
        'active',
        'sort_order',
    ];

    protected $casts = [
        'sizes'    => 'array',
        'price'    => 'decimal:2',
        'active'   => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(ClothingCategory::class, 'category_id');
    }

    /** JSON-vorm zoals de shop/catalogus die al verwachtte, plus category-key. */
    public function toCatalogArray(): array
    {
        return [
            'key'      => $this->key,
            'name'     => $this->name,
            'price'    => (float) $this->price,
            'color'    => $this->color,
            'swatch'   => $this->swatch,
            'sizes'    => $this->sizes,
            'category' => $this->category?->key,
            'image'    => $this->image_path ? asset('storage/' . $this->image_path) : null,
        ];
    }
}
