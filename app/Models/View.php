<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class View extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'list_id'];

    public function list()
    {
        return $this->belongsTo(TaskList::class);
    }

    public function widgets()
    {
        return $this->hasMany(Widget::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }
}
