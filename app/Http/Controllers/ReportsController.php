<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use App\Models\Reading;
use App\Models\Bill;
use App\Models\ConcessionerAccount;
use Carbon\Carbon;
use DB;

class ReportsController extends Controller
{
    /**
     * Show the download options page
     */
    public function downloadFilesIndex()
    {
        $availableReports = [
            'Ageing (Detailed)',
            'Ageing (Summary)',
            'Ageing (Recap)',
            'List of Disconnected Con.',
            'Penalty Report (Detailed)',
            'Penalty Report (Summary)',
            'Franchise Tax Report(Detailed)',
            'Franchise Tax Report(Summary)',
            'Monthly Billing Summary',
            'Billed Con by Category and Size',
            'Consumption by Category & Size',
        ];

        // Fetch all zones ascending for dropdown
        $zones = ConcessionerAccount::select('zone')
            ->distinct()
            ->orderBy('zone', 'asc')
            ->pluck('zone');

        return view('reports.download-index', compact('availableReports', 'zones'));
    }

    /**
     * Generate Excel or CSV files from DB
     */
    public function generateFile(Request $request)
    {
        $request->validate([
            'reports' => 'required|array|min:1',
            'mode' => 'required|in:combined,separate',
            'format' => 'required|in:xlsx,csv',
            'zone' => 'required', // now required for ageing summary
        ]);

        $reports = $request->input('reports', []);
        $mode = $request->input('mode');
        $format = $request->input('format');

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $zone = $request->input('zone');
        $classification = $request->input('classification');

        $dataByReport = $this->fetchReportsFromDb($reports, $startDate, $endDate, $zone, $classification);

        if ($mode === 'separate') {
            $files = [];
            foreach ($dataByReport as $reportName => $rows) {
                $filePath = $this->createFile($reportName, $rows, $format);
                $files[] = $filePath;
            }

            if (count($files) > 1) {
                $zipName = 'reports-' . now()->format('Ymd_His') . '.zip';
                $zipPath = storage_path("app/reports/{$zipName}");
                $zip = new \ZipArchive();
                if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
                    foreach ($files as $f) {
                        $zip->addFile($f, basename($f));
                    }
                    $zip->close();
                }
                return response()->download($zipPath)->deleteFileAfterSend(true);
            }

            return response()->download($files[0])->deleteFileAfterSend(true);
        }

        $spreadsheet = new Spreadsheet();
        $firstSheet = true;

        foreach ($dataByReport as $reportName => $rows) {
            if ($firstSheet) {
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle(substr($reportName, 0, 31));
                $firstSheet = false;
            } else {
                $sheet = $spreadsheet->createSheet();
                $sheet->setTitle(substr($reportName, 0, 31));
            }

            if (!empty($rows)) {
                $headers = array_keys($rows[0]);
                $sheet->fromArray([$headers], null, 'A1');
                $sheet->fromArray($rows, null, 'A2');
            }
        }

        $fileName = 'combined-reports-' . now()->format('Ymd_His') . '.' . $format;
        $filePath = storage_path("app/reports/{$fileName}");

        $writer = $format === 'csv' ? new Csv($spreadsheet) : new Xlsx($spreadsheet);
        $writer->save($filePath);

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

    /**
     * Fetch report data from database
     */
    protected function fetchReportsFromDb(array $reports, $startDate = null, $endDate = null, $zone = null, $classification = null)
    {
        $result = [];

        foreach ($reports as $report) {
            switch ($report) {
                case 'Ageing (Detailed)':
                    $readings = Reading::with(['concessionaire.user', 'bill'])
                        ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                        ->get();

                    $rows = [];
                    foreach ($readings as $reading) {
                        $bill = $reading->bill;
                        if (!$bill) continue;

                        $dueDate = Carbon::parse($bill->due_date);
                        $daysOverdue = $dueDate->diffInDays(now(), false);

                        $current = $daysOverdue <= 0 ? $bill->amount : 0;
                        $oneTo30 = $daysOverdue > 0 && $daysOverdue <= 30 ? $bill->amount : 0;
                        $thirtyOneTo60 = $daysOverdue > 30 && $daysOverdue <= 60 ? $bill->amount : 0;
                        $sixtyOneTo90 = $daysOverdue > 60 && $daysOverdue <= 90 ? $bill->amount : 0;
                        $over90 = $daysOverdue > 90 ? $bill->amount : 0;

                        $rows[] = [
                            'account_number' => $reading->account_no,
                            'name' => optional($reading->concessionaire->user)->name ?? 'N/A',
                            'current' => $current,
                            '1_30' => $oneTo30,
                            '31_60' => $thirtyOneTo60,
                            '61_90' => $sixtyOneTo90,
                            'over_90' => $over90,
                            'total' => $current + $oneTo30 + $thirtyOneTo60 + $sixtyOneTo90 + $over90,
                        ];
                    }

                    $result[$report] = $rows;
                    break;

                case 'Ageing (Summary)':
                // Fetch readings with their bill; limit to one reading per account
                $query = Reading::with('bill')
                    ->select('readings.*')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->whereHas('bill') // ensure only readings with a bill
                    ->orderBy('zone', 'asc')
                    ->get()
                    ->groupBy('zone');

                $rows = [];
                $totals = [
                    'current' => 0,
                    '1_30' => 0,
                    '31_60' => 0,
                    '61_90' => 0,
                    'over_90' => 0,
                    'total' => 0,
                ];

                foreach ($query as $zoneKey => $readings) {
                    $zoneSummary = [
                        'current' => 0,
                        '1_30' => 0,
                        '31_60' => 0,
                        '61_90' => 0,
                        'over_90' => 0,
                    ];

                    // Avoid redundant bill reads per account
                    $uniqueAccounts = $readings->unique('account_no');

                    foreach ($uniqueAccounts as $reading) {
                        $bill = $reading->bill;
                        if (!$bill) continue;

                        $dueDate = Carbon::parse($bill->due_date);
                        $daysOverdue = $dueDate->diffInDays(now(), false);

                        if ($daysOverdue <= 0) {
                            $zoneSummary['current'] += $bill->amount;
                        } elseif ($daysOverdue <= 30) {
                            $zoneSummary['1_30'] += $bill->amount;
                        } elseif ($daysOverdue <= 60) {
                            $zoneSummary['31_60'] += $bill->amount;
                        } elseif ($daysOverdue <= 90) {
                            $zoneSummary['61_90'] += $bill->amount;
                        } else {
                            $zoneSummary['over_90'] += $bill->amount;
                        }
                    }

                    $total = array_sum($zoneSummary);

                    // Update grand totals
                    foreach ($zoneSummary as $key => $value) {
                        $totals[$key] += $value;
                    }
                    $totals['total'] += $total;

                    $rows[] = [
                        'zone' => $zoneKey,
                        'current' => $zoneSummary['current'],
                        '1_30' => $zoneSummary['1_30'],
                        '31_60' => $zoneSummary['31_60'],
                        '61_90' => $zoneSummary['61_90'],
                        'over_90' => $zoneSummary['over_90'],
                        'total' => $total,
                    ];
                }

                usort($rows, fn($a, $b) => (int)$a['zone'] <=> (int)$b['zone']);

                $rows[] = [
                    'zone' => 'TOTAL',
                    'current' => $totals['current'],
                    '1_30' => $totals['1_30'],
                    '31_60' => $totals['31_60'],
                    '61_90' => $totals['61_90'],
                    'over_90' => $totals['over_90'],
                    'total' => $totals['total'],
                ];

                $result[$report] = $rows;
                break;

                case 'Ageing (Recap)':
                $readings = Reading::with(['bill', 'concessionaire.propertyType'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->get();

                $grouped = $readings->groupBy(function($reading) {
                    return optional($reading->concessionaire->propertyType)->name
                        ?? optional($reading->concessionaire)->property_type
                        ?? 'UNCLASSIFIED';
                });

                $rows = [];
                $totals = [
                    'customers' => 0,
                    'current' => 0,
                    '1_30' => 0,
                    '31_60' => 0,
                    '61_90' => 0,
                    'over_90' => 0,
                    'total' => 0,
                ];

                foreach ($grouped as $classification => $groupReadings) {
                    $summary = [
                        'customers' => $groupReadings->unique('account_no')->count(),
                        'current' => 0,
                        '1_30' => 0,
                        '31_60' => 0,
                        '61_90' => 0,
                        'over_90' => 0,
                    ];

                    foreach ($groupReadings->unique('account_no') as $reading) {
                        $bill = $reading->bill;
                        if (!$bill) continue;

                        $dueDate = Carbon::parse($bill->due_date);
                        $daysOverdue = $dueDate->diffInDays(now(), false);

                        if ($daysOverdue <= 0) $summary['current'] += $bill->amount;
                        elseif ($daysOverdue <= 30) $summary['1_30'] += $bill->amount;
                        elseif ($daysOverdue <= 60) $summary['31_60'] += $bill->amount;
                        elseif ($daysOverdue <= 90) $summary['61_90'] += $bill->amount;
                        else $summary['over_90'] += $bill->amount;
                    }

                    $summary['total'] = $summary['current'] + $summary['1_30'] + $summary['31_60'] + $summary['61_90'] + $summary['over_90'];

                    foreach ($totals as $key => $value) {
                        if (isset($summary[$key])) $totals[$key] += $summary[$key];
                    }

                    $rows[] = array_merge(['classification' => $classification], $summary);
                }

                $rows[] = [
                    'classification' => 'GRAND TOTAL',
                    'customers' => $totals['customers'],
                    'current' => $totals['current'],
                    '1_30' => $totals['1_30'],
                    '31_60' => $totals['31_60'],
                    '61_90' => $totals['61_90'],
                    'over_90' => $totals['over_90'],
                    'total' => $totals['total'],
                ];

                $result[$report] = $rows;
                break;

                case 'List of Disconnected Con.':
                $readings = Reading::with([
                        'concessionaire.user',
                        'concessionaire.propertyType',
                        'concessionaire.statusCode',
                        'bill'
                    ])
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->whereHas('concessionaire', fn($q) => $q->where('status', 3))
                    ->get();

                $rows = [];

                foreach ($readings as $reading) {
                    $con = $reading->concessionaire;
                    $lastBill = $reading->bill;

                    $arrears = $lastBill ? $lastBill->amount : 0;
                    $monthInArrears = $lastBill ? now()->diffInMonths(Carbon::parse($lastBill->due_date)) : 0;

                    $rows[] = [
                        'Name of Consumers' => optional($con->user)->name ?? 'N/A',
                        'Zone' => $con->zone ?? 'N/A',
                        'Service Address' => $con->address ?? 'N/A',
                        'Account no.' => $con->account_no ?? 'N/A',
                        'Type' => optional($con->propertyType)->name ?? $con->property_type ?? 'N/A',
                        'Arrears' => $arrears,
                        'Date closed' => $reading->closed_date ?? 'N/A',
                        'Month in arrears' => $monthInArrears,
                    ];
                }

                $rows = collect($rows)->sortBy('Zone')->values()->all();

                $totalArrears = collect($rows)->sum('Arrears');
                $rows[] = [
                    'Name of Consumers' => 'TOTAL',
                    'Zone' => '',
                    'Service Address' => '',
                    'Account no.' => '',
                    'Type' => '',
                    'Arrears' => $totalArrears,
                    'Date closed' => '',
                    'Month in arrears' => '',
                ];

                $result[$report] = $rows;
                break;

                case 'Penalty Report (Detailed)':
                $readings = Reading::with(['bill', 'concessionaire.user'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->get();

                $rows = [];

                $totals = [
                    'Current Penalty' => 0,
                    '1-30 days' => 0,
                    '31-60 days' => 0,
                    '61-90 days' => 0,
                    'Waterbill Total' => 0,
                ];

                foreach ($readings->unique('account_no') as $reading) {
                    $bill = $reading->bill;
                    if (!$bill) continue;

                    $accountNo = $reading->account_no;
                    $name = optional($reading->concessionaire->user)->name ?? 'N/A';
                    $penalty = floatval($bill->penalty ?? 0);
                    $amount = floatval($bill->amount ?? 0);

                    $dueDate = Carbon::parse($bill->due_date);
                    $daysOverdue = $dueDate->diffInDays(now(), false);

                    $buckets = [
                        'Current Penalty' => 0,
                        '1-30 days' => 0,
                        '31-60 days' => 0,
                        '61-90 days' => 0,
                    ];

                    if ($daysOverdue <= 0) {
                        $buckets['Current Penalty'] = $penalty;
                    } elseif ($daysOverdue <= 30) {
                        $buckets['1-30 days'] = $penalty;
                    } elseif ($daysOverdue <= 60) {
                        $buckets['31-60 days'] = $penalty;
                    } elseif ($daysOverdue <= 90) {
                        $buckets['61-90 days'] = $penalty;
                    }

                    $waterbillTotal = array_sum($buckets) + $amount;

                    $rows[] = [
                        'Account Number' => $accountNo,
                        'Name' => $name,
                        'Current Penalty' => $buckets['Current Penalty'],
                        '1-30 days' => $buckets['1-30 days'],
                        '31-60 days' => $buckets['31-60 days'],
                        '61-90 days' => $buckets['61-90 days'],
                        'Waterbill Total' => $waterbillTotal,
                    ];

                    foreach ($buckets as $label => $value) {
                        $totals[$label] += $value;
                    }
                    $totals['Waterbill Total'] += $waterbillTotal;
                }

                $rows[] = [
                    'Account Number' => 'TOTAL',
                    'Name' => '',
                    'Current Penalty' => $totals['Current Penalty'],
                    '1-30 days' => $totals['1-30 days'],
                    '31-60 days' => $totals['31-60 days'],
                    '61-90 days' => $totals['61-90 days'],
                    'Waterbill Total' => $totals['Waterbill Total'],
                ];

                if (!empty($rows)) {
                    $result[$report] = $rows;
                } else {
                    $result[$report] = [['Account Number' => 'No records found']];
                }

                break;

                case 'Penalty Report (Summary)':
                $readings = Reading::with(['bill', 'concessionaire.propertyType'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->get();

                $grouped = $readings->groupBy(function ($reading) {
                    return optional($reading->concessionaire->propertyType)->name
                        ?? optional($reading->concessionaire)->property_type
                        ?? 'UNCLASSIFIED';
                });

                $rows = [];
                $totals = [
                    'Number of Customer' => 0,
                    'Current Penalty' => 0,
                    '1-30 days' => 0,
                    '31-60 days' => 0,
                    '61-90 days' => 0,
                    'Over 90 days' => 0,
                    'Penalty Total' => 0,
                ];

                foreach ($grouped as $classification => $groupReadings) {
                    $summary = [
                        'Number of Customer' => $groupReadings->unique('account_no')->count(),
                        'Current Penalty' => 0,
                        '1-30 days' => 0,
                        '31-60 days' => 0,
                        '61-90 days' => 0,
                        'Over 90 days' => 0,
                    ];

                    foreach ($groupReadings->unique('account_no') as $reading) {
                        $bill = $reading->bill;
                        if (!$bill) continue;

                        $penalty = floatval($bill->penalty ?? 0);
                        if ($penalty <= 0) continue; // skip zero penalties for cleaner output

                        $dueDate = Carbon::parse($bill->due_date);
                        $daysOverdue = $dueDate->diffInDays(now(), false);

                        if ($daysOverdue <= 0) $summary['Current Penalty'] += $penalty;
                        elseif ($daysOverdue <= 30) $summary['1-30 days'] += $penalty;
                        elseif ($daysOverdue <= 60) $summary['31-60 days'] += $penalty;
                        elseif ($daysOverdue <= 90) $summary['61-90 days'] += $penalty;
                        else $summary['Over 90 days'] += $penalty;
                    }

                    $summary['Penalty Total'] = $summary['Current Penalty'] + $summary['1-30 days'] +
                        $summary['31-60 days'] + $summary['61-90 days'] + $summary['Over 90 days'];

                    foreach ($totals as $key => $value) {
                        if (isset($summary[$key])) $totals[$key] += $summary[$key];
                    }

                    $rows[] = array_merge(['Classification' => $classification], $summary);
                }

                // Grand Total row
                $rows[] = [
                    'Classification' => 'GRAND TOTAL',
                    'Number of Customer' => $totals['Number of Customer'],
                    'Current Penalty' => $totals['Current Penalty'],
                    '1-30 days' => $totals['1-30 days'],
                    '31-60 days' => $totals['31-60 days'],
                    '61-90 days' => $totals['61-90 days'],
                    'Over 90 days' => $totals['Over 90 days'],
                    'Penalty Total' => $totals['Penalty Total'],
                ];

                $result[$report] = $rows;
                break;

                case 'Franchise Tax Report(Detailed)':
                $readings = Reading::with(['bill', 'concessionaire.user'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->get();

                $rows = [];

                $totals = [
                    'Current Penalty' => 0,
                    '1-30 days' => 0,
                    '31-60 days' => 0,
                    '61-90 days' => 0,
                    'Franchise Tax Total' => 0,
                ];

                foreach ($readings->unique('account_no') as $reading) {
                    $bill = $reading->bill;
                    if (!$bill) continue;

                    $accountNo = $reading->account_no;
                    $name = optional($reading->concessionaire->user)->name ?? 'N/A';
                    $tax = floatval($bill->tax ?? 0); // 👈 franchise tax only

                    $dueDate = Carbon::parse($bill->due_date);
                    $daysOverdue = $dueDate->diffInDays(now(), false);

                    $buckets = [
                        'Current Penalty' => 0,
                        '1-30 days' => 0,
                        '31-60 days' => 0,
                        '61-90 days' => 0,
                    ];

                    if ($daysOverdue <= 0) {
                        $buckets['Current Penalty'] = $tax;
                    } elseif ($daysOverdue <= 30) {
                        $buckets['1-30 days'] = $tax;
                    } elseif ($daysOverdue <= 60) {
                        $buckets['31-60 days'] = $tax;
                    } elseif ($daysOverdue <= 90) {
                        $buckets['61-90 days'] = $tax;
                    }

                    $franchiseTaxTotal = array_sum($buckets);

                    $rows[] = [
                        'Account Number' => $accountNo,
                        'Name' => $name,
                        'Current Penalty' => $buckets['Current Penalty'],
                        '1-30 days' => $buckets['1-30 days'],
                        '31-60 days' => $buckets['31-60 days'],
                        '61-90 days' => $buckets['61-90 days'],
                        'Franchise Tax Total' => $franchiseTaxTotal,
                    ];

                    foreach ($buckets as $label => $value) {
                        $totals[$label] += $value;
                    }
                    $totals['Franchise Tax Total'] += $franchiseTaxTotal;
                }

                // Add total row
                $rows[] = [
                    'Account Number' => 'TOTAL',
                    'Name' => '',
                    'Current Penalty' => $totals['Current Penalty'],
                    '1-30 days' => $totals['1-30 days'],
                    '31-60 days' => $totals['31-60 days'],
                    '61-90 days' => $totals['61-90 days'],
                    'Franchise Tax Total' => $totals['Franchise Tax Total'],
                ];

                $result[$report] = !empty($rows) ? $rows : [['Account Number' => 'No records found']];

                break;

                case 'Franchise Tax Report(Summary)':
                $readings = Reading::with(['bill', 'concessionaire.propertyType'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->get();

                // Group readings by property type / classification
                $grouped = $readings->groupBy(function($reading) {
                    return optional($reading->concessionaire->propertyType)->name
                        ?? optional($reading->concessionaire)->property_type
                        ?? 'UNCLASSIFIED';
                });

                $rows = [];
                $totals = [
                    'customers' => 0,
                    'current' => 0,
                    '1_30' => 0,
                    '31_60' => 0,
                    '61_90' => 0,
                    'over_90' => 0,
                    'total' => 0,
                ];

                foreach ($grouped as $classification => $groupReadings) {
                    $uniqueAccounts = $groupReadings->unique('account_no');
                    $summary = [
                        'customers' => $uniqueAccounts->count(),
                        'current' => 0,
                        '1_30' => 0,
                        '31_60' => 0,
                        '61_90' => 0,
                        'over_90' => 0,
                    ];

                    foreach ($uniqueAccounts as $reading) {
                        $bill = $reading->bill;
                        if (!$bill) continue;

                        $tax = floatval($bill->tax ?? 0);
                        $dueDate = Carbon::parse($bill->due_date);

                        // Correct overdue calculation: positive = overdue, negative = not yet due
                        $daysOverdue = now()->diffInDays($dueDate, false);

                        if ($daysOverdue < 0) $summary['current'] += $tax;         // Not yet due
                        elseif ($daysOverdue <= 30) $summary['1_30'] += $tax;
                        elseif ($daysOverdue <= 60) $summary['31_60'] += $tax;
                        elseif ($daysOverdue <= 90) $summary['61_90'] += $tax;
                        else $summary['over_90'] += $tax;
                    }

                    $summary['total'] = $summary['current'] + $summary['1_30'] + $summary['31_60'] + $summary['61_90'] + $summary['over_90'];

                    // Add to grand totals
                    $totals['customers'] += $summary['customers'];
                    $totals['current'] += $summary['current'];
                    $totals['1_30'] += $summary['1_30'];
                    $totals['31_60'] += $summary['31_60'];
                    $totals['61_90'] += $summary['61_90'];
                    $totals['over_90'] += $summary['over_90'];
                    $totals['total'] += $summary['total'];

                    $rows[] = [
                        'Classification' => $classification,
                        'Number of Customer' => $summary['customers'],
                        'Current Penalty' => $summary['current'],
                        '1-30 days' => $summary['1_30'],
                        '31-60 days' => $summary['31_60'],
                        '61-90 days' => $summary['61_90'],
                        'Over 90 days' => $summary['over_90'],
                        'Franchise Tax Total' => $summary['total'],
                    ];
                }

                // Add GRAND TOTAL row
                $rows[] = [
                    'Classification' => 'GRAND TOTAL',
                    'Number of Customer' => $totals['customers'],
                    'Current Penalty' => $totals['current'],
                    '1-30 days' => $totals['1_30'],
                    '31-60 days' => $totals['31_60'],
                    '61-90 days' => $totals['61_90'],
                    'Over 90 days' => $totals['over_90'],
                    'Franchise Tax Total' => $totals['total'],
                ];

                $result[$report] = $rows;
                break;

                case 'Monthly Billing Summary':
                $readings = Reading::with(['bill', 'concessionaire'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->orderBy('zone', 'asc')
                    ->get();

                if ($readings->isEmpty()) {
                    $result[$report] = [['Zone' => 'No data found']];
                    break;
                }

                // Group by zone
                $zonesGrouped = $readings->groupBy('zone');

                $rows = [];

                foreach ($zonesGrouped as $zoneKey => $zoneReadings) {
                    // Group by property_type (e.g. RESIDENTIAL/GOVERNMENT 1/2'')
                    $byType = $zoneReadings->groupBy(fn($r) => $r->concessionaire->property_type ?? 'UNCLASSIFIED');

                    // Zone header row
                    $rows[] = ['Zone' => "Zone $zoneKey", 'Connections' => '', 'Usage' => '', 'Water Bills' => '', 'Penalty' => '', 'Total' => ''];

                    $zoneTotals = ['connections' => 0, 'usage' => 0, 'waterBills' => 0, 'penalty' => 0, 'total' => 0];

                    foreach ($byType as $type => $group) {
                        $connections = $group->unique('account_no')->count();
                        $usage = $group->sum(fn($r) => max(0, ($r->present_reading ?? 0) - ($r->previous_reading ?? 0)));

                        $waterBills = $group->sum(fn($r) => floatval(optional($r->bill)->amount ?? 0));
                        $penalty = $group->sum(fn($r) => floatval(optional($r->bill)->penalty ?? 0));
                        $total = $waterBills + $penalty;

                        $zoneTotals['connections'] += $connections;
                        $zoneTotals['usage'] += $usage;
                        $zoneTotals['waterBills'] += $waterBills;
                        $zoneTotals['penalty'] += $penalty;
                        $zoneTotals['total'] += $total;

                        $rows[] = [
                            'Zone' => $type,
                            'Connections' => $connections,
                            'Usage' => $usage,
                            'Water Bills' => number_format($waterBills, 2),
                            'Penalty' => number_format($penalty, 2),
                            'Total' => number_format($total, 2),
                        ];
                    }

                    // Zone total row
                    $rows[] = [
                        'Zone' => "TOTAL Zone $zoneKey",
                        'Connections' => $zoneTotals['connections'],
                        'Usage' => $zoneTotals['usage'],
                        'Water Bills' => number_format($zoneTotals['waterBills'], 2),
                        'Penalty' => number_format($zoneTotals['penalty'], 2),
                        'Total' => number_format($zoneTotals['total'], 2),
                    ];

                    // Empty spacer row
                    $rows[] = ['', '', '', '', '', ''];
                }

                $result[$report] = $rows;
                break;

                case 'Billed Con by Category and Size':
                $readings = Reading::with(['bill', 'concessionaire'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->orderBy('zone', 'asc')
                    ->get();

                if ($readings->isEmpty()) {
                    $result[$report] = [['Classification' => 'No data found']];
                    break;
                }

                // Extract sizes dynamically from property_types table
                $sizes = \App\Models\PropertyTypes::pluck('name')
                    ->map(function ($n) {
                        preg_match('/(\d+ ?\/? ?\d*)"?/', $n, $m);
                        return $m[1] ?? null;
                    })
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->toArray();

                $columns = array_merge(['Classification / Zone'], $sizes, ['Total']);
                $rows = [];

                // Group by zone
                $zonesGrouped = $readings->groupBy('zone');

                // Initialize grand totals
                $grandTotals = array_fill_keys($sizes, 0);
                $grandTotals['Total'] = 0;

                foreach ($zonesGrouped as $zoneKey => $zoneReadings) {
                    // Zone header row
                    $rows[] = array_merge(['Classification / Zone' => "Zone $zoneKey"], array_fill_keys($sizes, ''), ['Total' => '']);

                    // Group by classification (main type, e.g. "RESIDENTIAL/GOVERNMENT")
                    $byClassification = $zoneReadings->groupBy(function ($r) {
                        $type = $r->concessionaire->property_type ?? '';
                        return preg_replace('/\s+\d.*$/', '', $type); // remove size portion
                    });

                    foreach ($byClassification as $classification => $classReadings) {
                        $counts = array_fill_keys($sizes, 0);

                        foreach ($classReadings as $r) {
                            $type = $r->concessionaire->property_type ?? '';
                            preg_match('/(\d+ ?\/? ?\d*)"?/', $type, $m);
                            $size = $m[1] ?? null;

                            if ($size && isset($counts[$size])) {
                                $counts[$size]++;
                            }
                        }

                        $rowTotal = array_sum($counts);
                        foreach ($counts as $size => $count) {
                            $grandTotals[$size] += $count;
                        }
                        $grandTotals['Total'] += $rowTotal;

                        $rows[] = array_merge(
                            ['Classification / Zone' => $classification],
                            $counts,
                            ['Total' => $rowTotal]
                        );
                    }

                    // Blank spacer after each zone
                    $rows[] = array_fill_keys($columns, '');
                }

                // Add GRAND TOTAL row
                $rows[] = array_merge(['Classification' => 'GRAND TOTAL'], $grandTotals);

                $result[$report] = $rows;
                break;

                case 'Consumption by Category & Size':
                $readings = Reading::with(['bill', 'concessionaire'])
                    ->whereHas('bill')
                    ->when($zone !== 'all', fn($q) => $q->where('zone', $zone))
                    ->orderBy('zone', 'asc')
                    ->get();

                if ($readings->isEmpty()) {
                    $result[$report] = [['Classification' => 'No data found']];
                    break;
                }

                // Extract sizes dynamically from property_types table
                $sizes = \App\Models\PropertyTypes::pluck('name')
                    ->map(function ($n) {
                        preg_match('/(\d+ ?\/? ?\d*)"?/', $n, $m);
                        return $m[1] ?? null;
                    })
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->toArray();

                $columns = array_merge(['Classification / Zone'], $sizes, ['Total']);
                $rows = [];

                // Group by zone
                $zonesGrouped = $readings->groupBy('zone');

                // Initialize grand totals
                $grandTotals = array_fill_keys($sizes, 0);
                $grandTotals['Total'] = 0;

                foreach ($zonesGrouped as $zoneKey => $zoneReadings) {
                    // Zone header row
                    $rows[] = array_merge(['Classification / Zone' => "Zone $zoneKey"], array_fill_keys($sizes, ''), ['Total' => '']);

                    // Group by classification (main type, e.g. "RESIDENTIAL/GOVERNMENT")
                    $byClassification = $zoneReadings->groupBy(function ($r) {
                        $type = $r->concessionaire->property_type ?? '';
                        return preg_replace('/\s+\d.*$/', '', $type); // remove size portion
                    });

                    foreach ($byClassification as $classification => $classReadings) {
                        // initialize total cubic meters per size
                        $consumptionTotals = array_fill_keys($sizes, 0);

                        foreach ($classReadings as $r) {
                            $type = $r->concessionaire->property_type ?? '';
                            preg_match('/(\d+ ?\/? ?\d*)"?/', $type, $m);
                            $size = $m[1] ?? null;

                            if ($size && isset($consumptionTotals[$size])) {
                                $consumptionTotals[$size] += $r->consumption ?? 0;
                            }
                        }

                        $rowTotal = array_sum($consumptionTotals);
                        foreach ($consumptionTotals as $size => $value) {
                            $grandTotals[$size] += $value;
                        }
                        $grandTotals['Total'] += $rowTotal;

                        $rows[] = array_merge(
                            ['Classification / Zone' => $classification],
                            array_map(fn($v) => number_format($v, 2), $consumptionTotals),
                            ['Total' => number_format($rowTotal, 2)]
                        );
                    }

                    // Spacer
                    $rows[] = array_fill_keys($columns, '');
                }

                // Add GRAND TOTAL row
                $rows[] = array_merge(
                    ['Classification / Zone' => 'GRAND TOTAL'],
                    array_map(fn($v) => number_format($v, 2), $grandTotals)
                );

                $result[$report] = $rows;
                break;


                default:
                    $result[$report] = [];
                    break;
            }
        }

        return $result;
    }

    protected function createFile($reportName, $rows, $format)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($reportName, 0, 31));

        if (!empty($rows)) {
            $headers = array_keys($rows[0]);
            $sheet->fromArray([$headers], null, 'A1');
            $sheet->fromArray($rows, null, 'A2');
        }

        $fileName = 'report-' . $this->sanitizeFilename($reportName) . '-' . now()->format('Ymd_His') . '.' . $format;
        $filePath = storage_path("app/reports/{$fileName}");

        $writer = $format === 'csv' ? new Csv($spreadsheet) : new Xlsx($spreadsheet);
        $writer->save($filePath);

        return $filePath;
    }

    protected function sanitizeFilename($name)
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', strtolower($name));
    }
}
