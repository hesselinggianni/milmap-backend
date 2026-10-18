<?php

namespace App\Http\Controllers;

use App\Services\ServerStatus;
use Illuminate\Http\Request;

class AdminServerStatusController extends Controller
{
    /** GET /api/v1/admin/server-status?fresh=1 — momentopname (60 s gecachet). */
    public function index(Request $request)
    {
        return response()->json(ServerStatus::snapshot($request->boolean('fresh')));
    }
}
