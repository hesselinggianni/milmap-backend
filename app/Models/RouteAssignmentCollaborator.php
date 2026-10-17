<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteAssignmentCollaborator extends Model
{
    protected $fillable = ['route_assignment_id', 'user_id', 'invited_by', 'role', 'status'];

    public function assignment()
    {
        return $this->belongsTo(RouteAssignment::class, 'route_assignment_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
