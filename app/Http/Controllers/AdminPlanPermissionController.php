<?php

namespace App\Http\Controllers;

use App\Models\PlanPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin-CRUD voor de plan/rechten-matrix ("Plannen & rechten"). Eén enkele
 * GET levert de volledige matrix (plan x module), ontbrekende combinaties
 * worden aangevuld met veilige defaults; PUT slaat de hele matrix in één
 * keer op (bulk upsert).
 */
class AdminPlanPermissionController extends Controller
{
    public function index()
    {
        $existing = PlanPermission::all()->keyBy(fn ($row) => "{$row->plan}:{$row->module}");

        $rows = [];
        foreach (PlanPermission::PLANS as $plan) {
            foreach (PlanPermission::MODULES as $module) {
                $row = $existing->get("{$plan}:{$module}");
                $rows[] = [
                    'plan' => $plan,
                    'module' => $module,
                    'can_view' => $row->can_view ?? true,
                    'can_create' => $row->can_create ?? false,
                    'can_update' => $row->can_update ?? false,
                    'can_delete' => $row->can_delete ?? false,
                ];
            }
        }

        return response()->json([
            'plans' => PlanPermission::PLANS,
            'modules' => PlanPermission::MODULES,
            'permissions' => $rows,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'permissions' => 'required|array',
            'permissions.*.plan' => 'required|string|in:' . implode(',', PlanPermission::PLANS),
            'permissions.*.module' => 'required|string|in:' . implode(',', PlanPermission::MODULES),
            'permissions.*.can_view' => 'required|boolean',
            'permissions.*.can_create' => 'required|boolean',
            'permissions.*.can_update' => 'required|boolean',
            'permissions.*.can_delete' => 'required|boolean',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['permissions'] as $row) {
                PlanPermission::updateOrCreate(
                    ['plan' => $row['plan'], 'module' => $row['module']],
                    [
                        'can_view' => $row['can_view'],
                        'can_create' => $row['can_create'],
                        'can_update' => $row['can_update'],
                        'can_delete' => $row['can_delete'],
                    ]
                );
            }
        });

        return $this->index();
    }
}
