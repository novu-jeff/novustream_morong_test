<?php

namespace App\Exports;

use App\Models\Bill;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class CashierTransactionsExport implements FromView
{
    protected $cashierId;
    protected $dateRange;

    /**
     * Create a new export instance.
     */
    public function __construct($cashierId, $dateRange = [])
    {
        $this->cashierId = $cashierId;
        $this->dateRange = $dateRange;
    }

    /**
     * Return a view for Excel export
     */
    public function view(): View
    {
        $query = Bill::with('cashier')
            ->where('cashier_id', $this->cashierId)
            ->where('payment_method', 'cash'); // Only cash transactions

        if (!empty($this->dateRange)) {
            $query->whereBetween('created_at', $this->dateRange);
        }

        $bills = $query->get();

        return view('exports.cashier_transactions', [
            'bills' => $bills
        ]);
    }
}
