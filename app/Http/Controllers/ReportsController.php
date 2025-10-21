<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Bill;
use App\Exports\CashierTransactionsExport;
use App\Exports\MonthlySummaryExport;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;

class ReportsController extends Controller
{
    /**
     * Display Daily Summary of Bills
     */
    public function dailySummary(Request $request)
    {
        $date = $request->date ?? now()->toDateString();

        $query = Bill::whereDate('created_at', $date)
                     ->where('payment_method', 'cash'); // show only cash payments

        // If the logged-in user is a cashier, show only their transactions
        if (auth()->user()->user_type === 'cashier') {
            $query->where('cashier_id', auth()->id());
        }

        $bills = $query->with('cashier')->get();

        // You can calculate totals if needed
        $totalCollected = $bills->sum('amount_paid');
        $totalTransactions = $bills->count();

        return view('reports.daily_summary', compact('bills', 'date', 'totalCollected', 'totalTransactions'));
    }

    /**
     * Display Monthly Summary of Bills
     */
    public function monthlySummary(Request $request)
    {
        $month = $request->month ?? now()->month;
        $year = $request->year ?? now()->year;

        $query = Bill::whereYear('created_at', $year)
                     ->whereMonth('created_at', $month)
                     ->where('payment_method', 'cash'); // show only cash payments

        if (auth()->user()->user_type === 'cashier') {
            $query->where('cashier_id', auth()->id());
        }

        $bills = $query->with('cashier')->get();

        $totalCollected = $bills->sum('amount_paid');
        $totalTransactions = $bills->count();

        return view('reports.monthly_summary', compact('bills', 'month', 'year', 'totalCollected', 'totalTransactions'));
    }

    /**
     * Export Cashier Transaction Report to Excel
     */
    public function downloadCashierReport(Request $request)
    {
        $cashierId = auth()->id();
        $start = $request->start_date ?? now()->startOfMonth()->toDateString();
        $end = $request->end_date ?? now()->endOfMonth()->toDateString();

        return Excel::download(
            new CashierTransactionsExport($cashierId, [$start, $end]),
            'cashier-transactions-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

   public function downloadSummary(Request $request)
{
    $type = $request->query('type'); // daily or monthly
    $date = $request->query('date'); // e.g., "2025-10" or "2025-10-19"
    $zone = $request->query('zone'); // e.g., "A1"
    $cashierId = $request->query('cashier') ?? auth()->id();

    if ($type === 'daily') {
        // Convert string date to start/end of day array
        $start = Carbon::parse($date)->startOfDay();
        $end = Carbon::parse($date)->endOfDay();

        return Excel::download(
            new CashierTransactionsExport($cashierId, [$start, $end]),
            "daily-summary-{$date}.xlsx"
        );
    }

    if ($type === 'monthly') {
        if (!$date) {
            $year = now()->year;
            $month = now()->month;
        } else {
            $carbonDate = Carbon::parse($date . '-01'); // ensure valid date for parsing
            $year = $carbonDate->year;
            $month = $carbonDate->month;
        }

        return Excel::download(
            new MonthlySummaryExport($cashierId, $month, $year, $zone),
            "monthly-summary-{$year}-{$month}.xlsx"
        );
    }

    return back()->with('error', 'Invalid summary type selected.');
}

}
