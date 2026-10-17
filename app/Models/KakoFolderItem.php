<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KakoFolderItem extends Model
{
    protected $fillable = ['user_id', 'folder_id', 'route_assignment_id'];
}
