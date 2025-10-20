<?php

namespace App\Exports;

use App\Models\Bill;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class MonthlySummaryExport implements FromView
{
    protected $cashierId;
    protected $month;
    protected $year;
    protected $zone;

    public function __construct($cashierId, $month, $year = null, $zone = null)
    {
        $this->cashierId = $cashierId;
        $this->month = $month;
        $this->year = $year ?? now()->year;
        $this->zone = $zone;
    }

    public function view(): View
    {
        $query = Bill::with('reading.propertyType')
            ->where('cashier_id', $this->cashierId)
            ->whereYear('bill_period_to', $this->year)
            ->whereMonth('bill_period_to', $this->month)
            ->where('payment_method', 'cash');

        if ($this->zone) {
            $query->whereHas('reading', fn($q) => $q->where('zone', $this->zone));
        }

        $bills = $query->get();

        // Group by Rate Classification and Zone
        $summary = $bills->groupBy([
            fn($bill) => $bill->reading->propertyType->name ?? 'Unknown',
            'reading.zone'
        ])->map(function ($zones, $classification) {
            return $zones->map(function ($bills, $zone) {
                $noOfBilled = $bills->count();
                $basicAmount = $bills->sum('amount');
                $scDiscount = $bills->sum('discount');
                $netAmount = $basicAmount - $scDiscount;

                return [
                    'zone' => $zone,
                    'no_of_billed' => $noOfBilled,
                    'cons' => 0,
                    'basic_amount' => $basicAmount,
                    'sc_discount' => $scDiscount,
                    'net_amount_billed' => $netAmount,
                ];
            });
        });

        return view('exports.monthly_summary', [
            'summary' => $summary,
            'month' => $this->month,
            'year' => $this->year,
        ]);
    }
}
