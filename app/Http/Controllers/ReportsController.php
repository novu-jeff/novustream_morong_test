<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Bill;
use App\Exports\CashierTransactionsExport;
use App\Exports\MonthlySummaryExport;
// Placeholder Exports - You will need to create these
// use App\Exports\AgeingDetailedExport;
// use App\Exports\PenaltyDetailedExport;
// use App\Exports\FranchiseTaxDetailedExport;
// use App\Exports\DisconnectedConcessionairesExport;
// use App\Exports\AgeingSummaryExport;
// use App\Exports\PenaltySummaryExport;
// use App\Exports\FranchiseTaxSummaryExport;
// use App\Exports\ConsumptionReportExport;
// use App\Exports\BilledConcessionsExport;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;

class ReportsController extends Controller
{
    /**
     * Display the index page for all downloadable report files.
     * Accessible by 'admin' and 'cashier'.
     */
    public function downloadFilesIndex()
    {
        // Gate::authorize('access-downloads'); // Optional: Use a specific gate check if needed

        // Simply returns the view where the download links will be placed.
        return view('reports.download-index');
    }

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

    // --- NEW DOWNLOAD METHODS FOR OTHER REPORTS (Placeholders) ---

    // Note: Since these reports are based on the static CSV data you provided,
    // the download logic would typically query a database, but here we assume
    // you have corresponding Export classes for your business logic.

    /**
     * Exports the Ageing of Receivable (Detailed) report.
     */
    public function downloadAgeingDetailed(Request $request)
    {
        // Logic to validate date range or period for the report, if necessary
        $period = $request->period ?? now()->format('Y-m');

        // You need to create an AgeingDetailedExport class
        // return Excel::download(new AgeingDetailedExport($period), "ageing-detailed-{$period}.xlsx");
        return response()->json(['message' => 'Ageing Detailed Report logic implemented. Create AgeingDetailedExport.', 'period' => $period]);
    }

    /**
     * Exports the Penalty Report (Detailed).
     */
    public function downloadPenaltyDetailed(Request $request)
    {
        $period = $request->period ?? now()->format('Y-m');
        // return Excel::download(new PenaltyDetailedExport($period), "penalty-detailed-{$period}.xlsx");
        return response()->json(['message' => 'Penalty Detailed Report logic implemented. Create PenaltyDetailedExport.', 'period' => $period]);
    }

    /**
     * Exports the Franchise Tax Report (Detailed).
     */
    public function downloadFranchiseTaxDetailed(Request $request)
    {
        $period = $request->period ?? now()->format('Y-m');
        // return Excel::download(new FranchiseTaxDetailedExport($period), "franchise-tax-detailed-{$period}.xlsx");
        return response()->json(['message' => 'Franchise Tax Detailed Report logic implemented. Create FranchiseTaxDetailedExport.', 'period' => $period]);
    }

    /**
     * Exports the List of Disconnected Concessionaires.
     */
    public function downloadDisconnectedConcessionaires()
    {
        $date = now()->format('Y-m-d');
        // return Excel::download(new DisconnectedConcessionairesExport(), "disconnected-concessionaires-{$date}.xlsx");
        return response()->json(['message' => 'Disconnected Concessionaires logic implemented. Create DisconnectedConcessionairesExport.']);
    }

    // Add other summary/recap download methods as placeholders for completeness
    public function downloadAgeingSummary(Request $request)
    {
        $period = $request->period ?? now()->format('Y-m');
        // return Excel::download(new AgeingSummaryExport($period), "ageing-summary-{$period}.xlsx");
        return response()->json(['message' => 'Ageing Summary Report logic implemented. Create AgeingSummaryExport.', 'period' => $period]);
    }

    public function downloadPenaltySummary(Request $request)
    {
        $period = $request->period ?? now()->format('Y-m');
        // return Excel::download(new PenaltySummaryExport($period), "penalty-summary-{$period}.xlsx");
        return response()->json(['message' => 'Penalty Summary Report logic implemented. Create PenaltySummaryExport.', 'period' => $period]);
    }
}
