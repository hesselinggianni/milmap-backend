<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClothingCategory extends Model
{
    protected $fillable = [
        'key',
        'label',
        'sort_order',
    ];

    public function products()
    {
        return $this->hasMany(ClothingProduct::class, 'category_id')->orderBy('sort_order');
    }
}
