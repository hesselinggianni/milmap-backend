<?php

namespace App\Http\Controllers;

use App\Models\CostEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * AdminCostController — kosten/inkomsten-grootboek voor de admin.
 *
 * Elke post is een inkomst (+) of kost (−), eenmalig of terugkerend
 * (maandelijks/jaarlijks). Het overzicht rekent terugkerende posten om naar een
 * maandbedrag zodat je één netto-per-maand-cijfer ziet. Losstaand van Stripe.
 */
class AdminCostController extends Controller
{
    private const DIRECTIONS = ['income', 'expense'];
    private const RECURRENCES = ['once', 'monthly', 'yearly'];

    public function index()
    {
        $entries = CostEntry::orderByDesc('incurred_on')->orderByDesc('id')->get();

        return response()->json([
            'entries' => $entries,
            'summary' => $this->summary($entries),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateEntry($request);
        $data['created_by'] = Auth::id();

        $entry = CostEntry::create($data);

        return response()->json(['entry' => $entry], 201);
    }

    public function update(Request $request, int $id)
    {
        $entry = CostEntry::findOrFail($id);
        $entry->update($this->validateEntry($request));

        return response()->json(['entry' => $entry]);
    }

    public function destroy(int $id)
    {
        CostEntry::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    private function validateEntry(Request $request): array
    {
        return $request->validate([
            'label'       => ['required', 'string', 'max:200'],
            'direction'   => ['required', 'in:' . implode(',', self::DIRECTIONS)],
            'amount'      => ['required', 'numeric', 'min:0'],
            'recurrence'  => ['required', 'in:' . implode(',', self::RECURRENCES)],
            'category'    => ['nullable', 'string', 'max:100'],
            'incurred_on' => ['required', 'date'],
            'ends_on'     => ['nullable', 'date', 'after_or_equal:incurred_on'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * Maand-genormaliseerd overzicht: terugkerende posten → maandbedrag,
     * eenmalige posten → alleen meegeteld in hun eigen (huidige) maand.
     */
    private function summary($entries): array
    {
        $today = Carbon::today();

        $monthlyIncome = 0.0;   // terugkerend, per maand
        $monthlyExpense = 0.0;
        $onceIncome = 0.0;      // eenmalig, deze kalendermaand
        $onceExpense = 0.0;

        foreach ($entries as $e) {
            $amount = (float) $e->amount;
            $isIncome = $e->direction === 'income';

            if ($e->recurrence === 'once') {
                if ($e->incurred_on && $e->incurred_on->isSameMonth($today)) {
                    $isIncome ? $onceIncome += $amount : $onceExpense += $amount;
                }
                continue;
            }

            // Terugkerend: alleen meetellen als het nu actief is.
            $started = !$e->incurred_on || $e->incurred_on->lte($today);
            $notEnded = !$e->ends_on || $e->ends_on->gte($today);
            if (!$started || !$notEnded) {
                continue;
            }

            $perMonth = $e->recurrence === 'yearly' ? $amount / 12 : $amount;
            $isIncome ? $monthlyIncome += $perMonth : $monthlyExpense += $perMonth;
        }

        $monthlyNet = $monthlyIncome - $monthlyExpense;

        return [
            'monthly_recurring_income'  => round($monthlyIncome, 2),
            'monthly_recurring_expense' => round($monthlyExpense, 2),
            'monthly_recurring_net'     => round($monthlyNet, 2),
            'yearly_recurring_net'      => round($monthlyNet * 12, 2),
            'once_this_month_income'    => round($onceIncome, 2),
            'once_this_month_expense'   => round($onceExpense, 2),
            'net_this_month'            => round($monthlyNet + $onceIncome - $onceExpense, 2),
            'entry_count'               => $entries->count(),
        ];
    }
}
