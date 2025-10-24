<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

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
            'Penalty Report(Detailed)',
            'Penalty Report(Summary)',
            'Franchise Tax Report(Detailed)',
            'Franchise Tax Report(Summary)',
            'Monthly Billing Summary',
            'Billed Con by Category and Size',
            'Consumption by Category & Size',
        ];

        return view('reports.download-index', compact('availableReports'));
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
                // Create ZIP if multiple
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

    protected function fetchReportsFromDb(array $reports, $startDate = null, $endDate = null, $zone = null, $classification = null)
    {
        $result = [];

        foreach ($reports as $report) {
            switch ($report) {
                case 'Ageing (Detailed)':
                    $rows = \App\Models\Bill::select(
                            'reference_no',
                            'payor_name',
                            'bill_period_from',
                            'bill_period_to',
                            'previous_unpaid',
                            'amount',
                            'penalty',
                            'amount_after_due',
                            'isPaid',
                            'due_date'
                        )
                        ->when($startDate && $endDate, fn($q) => $q->whereBetween('bill_period_from', [$startDate, $endDate]))
                        ->when($zone, fn($q) => $q->where('zone', $zone))
                        ->when($classification, fn($q) => $q->where('classification', $classification))
                        ->get()
                        ->toArray();
                    break;

                case 'Ageing (Summary)':
                    $rows = \App\Models\Bill::selectRaw('payor_name, SUM(amount) as total_amount, SUM(penalty) as total_penalty, SUM(amount_after_due) as total_due')
                        ->when($startDate && $endDate, fn($q) => $q->whereBetween('bill_period_from', [$startDate, $endDate]))
                        ->groupBy('payor_name')
                        ->get()
                        ->toArray();
                    break;

                case 'Penalty Report(Detailed)':
                    $rows = \App\Models\Bill::select(
                            'reference_no',
                            'payor_name',
                            'penalty',
                            'due_date',
                            'isPaid'
                        )
                        ->where('hasPenalty', true)
                        ->when($startDate && $endDate, fn($q) => $q->whereBetween('bill_period_from', [$startDate, $endDate]))
                        ->get()
                        ->toArray();
                    break;

                case 'Monthly Billing Summary':
                    $rows = \App\Models\Bill::selectRaw('DATE_FORMAT(bill_period_from, "%Y-%m") as month, SUM(amount) as total_amount, SUM(penalty) as total_penalty')
                        ->when($startDate && $endDate, fn($q) => $q->whereBetween('bill_period_from', [$startDate, $endDate]))
                        ->groupByRaw('DATE_FORMAT(bill_period_from, "%Y-%m")')
                        ->get()
                        ->toArray();
                    break;
                default:
                    $rows = [];
                    break;
            }

            $result[$report] = $rows;
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
