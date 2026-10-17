<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KakoFolder extends Model
{
    protected $fillable = ['user_id', 'name'];

    public function items()
    {
        return $this->hasMany(KakoFolderItem::class, 'folder_id');
    }
}
