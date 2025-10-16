<?php

namespace App\Http\Controllers;

use App\Imports\PreviousBillingImport;
use App\Models\Bill;
use App\Services\GenerateService;
use App\Services\MeterService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Yajra\DataTables\Facades\DataTables;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Maatwebsite\Excel\Excel as ExcelFormat;
use App\Models\PaymentBreakdownPenalty;

class PaymentController extends Controller
{

    public $meterService;
    public $generateService;

    public function __construct(MeterService $meterService,
        GenerateService $generateService) {
        $this->meterService = $meterService;
        $this->generateService = $generateService;
    }

    public function index(Request $request)
    {

        $filter = $request->filter ?? '';

        if (!in_array($filter, ['unpaid', 'paid'], true)) {
            return redirect()->route('payments.index', ['filter' => 'unpaid']);
        }

        $zones = $this->meterService->getZones();
        $zone = $request->zone ?? 'all';

        $entries = $request->entries ?? 10;
        $toSearch = $request->search ?? '';
        $date = $request->date ?? $this->meterService->getLatestReadingMonth();

        $collection = collect($this->meterService::getPayments($filter, $zone, $date, $toSearch))
            ->flatten(2);

        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $currentItems = $collection->slice(($currentPage - 1) * $entries, $entries)->values();

        $data = new LengthAwarePaginator(
            $currentItems,
            $collection->count(),
            $entries,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('payments.index', compact('data', 'entries', 'filter', 'zones', 'zone', 'date', 'toSearch'));
    }

    public function upload(Request $request)
    {
        if ($request->getMethod() !== 'POST') {
            return view('payments.upload');
        }

        if (!$request->hasFile('file')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No file uploaded.',
            ]);
        }

        $file = $request->file('file');

        if (
            !$file->isValid() ||
            $file->getClientOriginalExtension() !== 'xlsx' ||
            $file->getMimeType() !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only Excel (.xlsx) files are allowed.',
            ]);
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheetNames = $spreadsheet->getSheetNames();

        $expectedHeaders = [
            'reference_no', 'account_no', 'billing_from', 'billing_to',
            'previous_reading', 'present_reading', 'consumption', 'penalty',
            'unpaid', 'arrears', 'current_bill', 'amount_paid',
            'date_paid', 'due_date', 'payor_name', 'payment_reference_no',
        ];

        $allMessages = [];
        $importedSheets = [];

        $headingData = (new HeadingRowImport(2))->toArray($file);

        $normalizeHeader = function ($header) {
            $h = (string)$header;
            $h = trim($h);
            $h = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $h));
            $h = preg_replace('/_+/', '_', $h);
            $h = trim($h, '_');
            return $h;
        };

        foreach ($sheetNames as $index => $sheetName) {
            $rawHeadersRow = $headingData[$index][0] ?? [];

            $normalizedHeaders = [];
            foreach ($rawHeadersRow as $h) {
                $n = $normalizeHeader($h);
                if (!empty($n)) {
                    $normalizedHeaders[] = $n;
                }
            }

            if (empty($normalizedHeaders) && !empty($headingData[$index])) {
                foreach ($headingData[$index] as $possibleRow) {
                    if (!empty($possibleRow) && is_array($possibleRow)) {
                        foreach ($possibleRow as $h) {
                            $n = $normalizeHeader($h);
                            if (!empty($n)) {
                                $normalizedHeaders[] = $n;
                            }
                        }
                        if (!empty($normalizedHeaders)) break;
                    }
                }
            }

            $missing = array_values(array_diff($expectedHeaders, $normalizedHeaders));

            if (!empty($missing)) {
                $allMessages[] = [
                    'sheet' => $sheetName,
                    'status' => 'error',
                    'message' => 'Missing headers in sheet.',
                    'missing_headers' => $missing,
                ];
                continue;
            }

            try {
                $importInstance = new PreviousBillingImport($sheetName);

                Excel::import(new class($importInstance, $sheetName) implements \Maatwebsite\Excel\Concerns\WithMultipleSheets {
                    private $importInstance;
                    private $sheetName;

                    public function __construct($importInstance, $sheetName)
                    {
                        $this->importInstance = $importInstance;
                        $this->sheetName = $sheetName;
                    }

                    public function sheets(): array
                    {
                        return [$this->sheetName => $this->importInstance];
                    }
                }, $file);

                $importedSheets[] = $sheetName;

                $failures = $importInstance->failures();
                $failureErrors = [];

                if ($failures->isNotEmpty()) {
                    foreach ($failures as $failure) {
                        $row = $failure->row();
                        foreach ($failure->errors() as $error) {
                            $failureErrors[] = "Row [$row]: $error";
                        }
                    }
                }

                $skippedRows = $importInstance->getSkippedRows();
                $rowCount = $importInstance->getRowCounter();
                $totalImported = max($rowCount - 2 - count($failureErrors) - count($skippedRows), 0);

                if (!empty($failureErrors) || !empty($skippedRows)) {
                    $message = [];
                    if (!empty($failureErrors)) {
                        $message[] = count($failureErrors) . ' skipped due to validation';
                    }
                    if (!empty($skippedRows)) {
                        $message[] = count($skippedRows) . ' skipped due to logic checks';
                    }

                    $allMessages[] = [
                        'sheet' => $sheetName,
                        'status' => 'warning',
                        'message' => "Total of <b>(".number_format($totalImported, 0).")</b> records partially imported. <br>" . implode(', ', $message),
                        'errors' => array_merge($failureErrors, $skippedRows),
                    ];
                } else {
                    $allMessages[] = [
                        'sheet' => $sheetName,
                        'status' => 'success',
                        'message' => "Total of <b>(".number_format($totalImported, 0).")</b> records imported successfully.",
                    ];
                }

            } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
                $failures = $e->failures();
                $messages = [];

                foreach ($failures as $failure) {
                    $row = $failure->row();
                    foreach ($failure->errors() as $error) {
                        $messages[] = "Row [$row]: $error";
                    }
                }

                $allMessages[] = [
                    'sheet' => $sheetName,
                    'status' => 'error',
                    'message' => 'Validation errors found during import.',
                    'errors' => $messages,
                ];
            } catch (\Exception $e) {
                $allMessages[] = [
                    'sheet' => $sheetName,
                    'status' => 'error',
                    'message' => 'An error occurred: ' . $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'status' => 'completed',
            'imported' => $importedSheets,
            'messages' => $allMessages,
        ]);
    }


    public function pay(Request $request, string $reference_no)
    {
        if ($request->getMethod() == 'POST') {
            $payload = $request->all();

            switch ($payload['payment_type']) {
                case 'cash':
                    return $this->processCashPayment($reference_no, $payload);
                case 'online':
                    return $this->processOnlinePayment($reference_no, $payload);
            }
        }


        $data = $this->meterService::getBill($reference_no);

        if (isset($data['status']) && $data['status'] === 'error') {
            return redirect()->back()->with('alert', [
                'status' => 'error',
                'message' => $data['message']
            ]);
        }

        $currentBill = $data['current_bill'] ?? null;

        if (!$currentBill || !isset($currentBill['reading_id'])) {
            return redirect()->back()->with('alert', [
                'status' => 'error',
                'message' => 'No reading found for this bill.'
            ]);
        }

        $reading = \App\Models\Reading::find($currentBill['reading_id']);
        if (!$reading) {
            return redirect()->back()->with('alert', [
                'status' => 'error',
                'message' => 'Reading not found.'
            ]);
        }

        $accountNo = $reading->account_no;

        $arrearsStack = collect();
        $previousUnpaid = (float)($currentBill['previous_unpaid'] ?? 0);

        if ($previousUnpaid > 0) {
            $arrearsMonth = Carbon::parse($currentBill['bill_period_from'])
                ->subMonth()
                ->format('F');
            $arrearsStack[$arrearsMonth] = $previousUnpaid;
        }

        $amount = (float)($currentBill['amount'] ?? 0);
        $discount = (float)($currentBill['discount'] ?? 0);
        $currentDay = now()->day;

        $penaltyEntry = PaymentBreakdownPenalty::where('due_from', '<=', $currentDay)
            ->where('due_to', '>=', $currentDay)
            ->first();

        $assumedPenalty = 0;

        if ($penaltyEntry) {
            $penaltyBase = $amount - $discount;

            if ($penaltyEntry->amount_type === 'percentage') {
                $assumedPenalty = $penaltyBase * floatval($penaltyEntry->amount);
            } elseif ($penaltyEntry->amount_type === 'fixed') {
                $assumedPenalty = floatval($penaltyEntry->amount);
            }
        }

        $assumedAmountAfterDue = $amount + $assumedPenalty + $previousUnpaid;

        $data['current_bill']['assumed_penalty'] = $assumedPenalty;
        $data['current_bill']['assumed_amount_after_due'] = $assumedAmountAfterDue;


        $url = env('NOVUPAY_URL') . '/payment/merchants/' . $reference_no;
        $qr_code = $this->generateService::qr_code($url, 80);

        return view('payments.pay', compact('data', 'reference_no', 'qr_code', 'arrearsStack'));
    }


    private function calculateTotalDue(array $currentBillData, ?array $payload = null, float $fullArrears = 0): array
    {

        $currentBill = (float) ($currentBillData['total'] ?? 0);
        $arrears = $fullArrears ?: (float) ($currentBillData['previous_unpaid'] ?? 0);
        $prevPenalty = (float) ($currentBillData['penalty'] ?? 0);

        $discount = 0;
        if (isset($payload['discount'])) {
            $discount = (float) $payload['discount'];
        } elseif (isset($currentBillData['discount'])) {
            if (is_array($currentBillData['discount'])) {
                $discount = collect($currentBillData['discount'])->sum('amount');
            } else {
                $discount = (float) $currentBillData['discount'];
            }
        }

        $advancePayment = (float) ($currentBillData['advances'] ?? 0);

        $dueDatePenalty = 0;
        $dueDate = $currentBillData['due_date'] ?? null;

        if ($dueDate) {
            $dueDateCarbon = \Carbon\Carbon::parse($dueDate)->timezone('Asia/Manila')->startOfDay();
            $today = \Carbon\Carbon::today('Asia/Manila');

            if ($today->gt($dueDateCarbon)) {
                $daysOverdue = $dueDateCarbon->diffInDays($today);

                $penaltyRule = PaymentBreakdownPenalty::where('due_from', '<=', $daysOverdue)
                    ->where('due_to', '>=', $daysOverdue)
                    ->first();

                if ($penaltyRule) {
                    if ($penaltyRule->amount_type === 'percentage') {
                        $dueDatePenalty = round($currentBill * floatval($penaltyRule->amount), 2);
                    } elseif ($penaltyRule->amount_type === 'fixed') {
                        $dueDatePenalty = round(floatval($penaltyRule->amount), 2);
                    }
                }
            }
        }

        $totalDue = $arrears + ($currentBill - $discount) + $dueDatePenalty - $advancePayment;
        $totalDue = max(0, round($totalDue, 2));

        return [
            'total_due' => $totalDue,
            'breakdown' => [
                'current_bill' => $currentBill,
                'arrears' => $arrears,
                //'previous_penalty' => $prevPenalty, // optional
                'discount' => $discount,
                'advance_payment' => $advancePayment,
                'due_date_penalty' => $dueDatePenalty,
            ],
        ];
    }


    private function getBill(string $reference_no, $payload = null, bool $strictAmount = false)
    {
        $data = $this->meterService::getBill($reference_no);

        if (!$data || !isset($data['current_bill'])) {
            return ['error' => 'Bill not found'];
        }

        $readingId = $data['current_bill']['reading_id'] ?? null;

        if (!$readingId) {
            return ['error' => 'No reading found for this bill.'];
        }

        $reading = \App\Models\Reading::find($readingId);
        if (!$reading) {
            return ['error' => 'Reading not found.'];
        }

        $accountNo = $reading->account_no;

        $unpaidBills = Bill::whereHas('reading', function($query) use ($accountNo) {
            $query->where('account_no', $accountNo);
        })
        ->where('isPaid', 0)
        ->orderBy('bill_period_from')
        ->get();

        $fullArrears = $unpaidBills->sum(fn($b) => $b->previous_unpaid);

        $totalDueResult = $this->calculateTotalDue($data['current_bill'], $payload);
        $totalDue = $totalDueResult['total_due'];
        $breakdown = $totalDueResult['breakdown'];

        if ($strictAmount && $payload) {
            $validator = Validator::make($payload, [
                'payment_amount' => 'required|numeric|gte:' . $totalDue,
            ], [
                'payment_amount.gte' => 'Cash payment is insufficient. Total due is PHP ' . number_format($totalDue, 2)
            ]);

            if ($validator->fails()) {
                return ['error' => $validator->errors()->first()];
            }
        }

        $data['current_bill']['assumed_amount_after_due'] = $totalDue;
        $data['current_bill']['breakdown'] = $breakdown;
        $data['current_bill']['previous_unpaid'] = $fullArrears;

        return [
            'data' => $data,
            'total_due' => $totalDue,
            'breakdown' => $breakdown,
        ];
    }


    public function processCashPayment(string $reference_no, array $payload)
    {
        $result = $this->getBill($reference_no, $payload, true);

        if (isset($result['error'])) {
            return redirect()->back()->with('alert', [
                'status' => 'error',
                'message' => $result['error']
            ]);
        }

        $data = $result['data'];
        $totalDue = $result['total_due'];
        $now = Carbon::now()->format('Y-m-d H:i:s');

        $paymentAmount = (float) $payload['payment_amount'];
        $change = $paymentAmount - $totalDue;

        $forAdvancePayment = isset($payload['for_advances']) && $payload['for_advances'];
        $saveChange = ($change != 0 && $forAdvancePayment);

        $currentBill = Bill::find($data['current_bill']['id']);

        if ($currentBill) {
            $currentBill->update([
                'isPaid' => true,
                'amount_paid' => $paymentAmount,
                'change' => $change,
                'payor_name' => $payload['payor'],
                'date_paid' => $now,
                'isChangeForAdvancePayment' => $saveChange,
                'payment_method' => 'cash',
            ]);
        }

        return redirect()->back()->with('alert', [
            'status' => 'success',
            'message' => 'Bill has been paid'
        ]);
    }


    public function processOnlinePayment(string $reference_no, array $payload)
    {
        $result = $this->getBill($reference_no, $payload, false);

        if (isset($result['error'])) {
            return redirect()->back()->with('alert', [
                'status' => 'error',
                'message' => $result['error']
            ]);
        }

        $data = $result['data'];
        $totalDue = $result['total_due'];

        $currentBill = Bill::find($data['current_bill']['id']);

        if ($currentBill) {
            $currentBill->update([
                'assumed_penalty' => $data['current_bill']['assumed_penalty'] ?? 0,
                'assumed_amount_after_due' => $data['current_bill']['assumed_amount_after_due'] ?? $totalDue,
            ]);
        }

        $url = env('NOVUPAY_URL') . '/payment/merchants/' . $reference_no;
        $qr_code = $this->generateService::qr_code($url, 80);

        return redirect()->route('payments.pay', ['reference_no' => $reference_no])->with([
            'alert' => [
                'status' => 'success',
                'payment_request' => true,
                'redirect' => $url,
            ],
            'total_due' => $totalDue,
            'qr_code' => $qr_code,
        ]);
    }

    public function callback(Request $request, string $reference_no)
    {
        $payload = $request->all();
        $bill = $this->meterService->getBill($reference_no);

        if ($bill) {
            $now = Carbon::now()->format('Y-m-d H:i:s');

            $currentBill = Bill::find($bill['current_bill']['id']);
            if ($currentBill) {
                $currentBill->update([
                    'isPaid' => true,
                    'amount_paid' => $payload['amount'],
                    'date_paid' => $now,
                    'payment_method' => 'online',
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment successful'
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Payment not found'
        ], 404);
    }

    public function datatable($query) {
        return DataTables::of($query)
            ->addIndexColumn()
            ->editColumn('account_no', function($row) {
                return $row->reading->account_no ?? 'N/A';
            })
            ->editColumn('billing_period', function ($row) {
                return ($row->bill_period_from && $row->bill_period_to)
                    ? Carbon::parse($row->bill_period_from)->format('M d, Y') . ' TO ' . Carbon::parse($row->bill_period_to)->format('M d, Y')
                    : 'N/A';
            })
            ->editColumn('bill_date', function ($row) {
                return !empty($row->bill_period_to)
                    ? Carbon::parse($row->bill_period_to)->format('M d, Y')
                    : 'N/A';
            })
            ->editColumn('amount', function ($row) {
                return '₱' . number_format((float)($row->amount ?? 0), 2);
            })
            ->editColumn('due_date', function ($row) {
                return !empty($row->due_date)
                    ? Carbon::parse($row->due_date)->format('M d, Y')
                    : 'N/A';
            })
            ->editColumn('status', function ($row) {
                return $row->isPaid
                    ? '<div class="alert alert-primary mb-0 py-1 px-2 text-center">Paid</div>'
                    : '<div class="alert alert-danger mb-0 py-1 px-2 text-center">Unpaid</div>';
            })
            ->addColumn('actions', function ($row) {
                if(!$row->isPaid) {
                    return '
                    <div class="d-flex align-items-center gap-2">
                        <a href="' . route('payments.pay', ['reference_no' => $row->reference_no]) . '"
                            class="btn btn-primary text-white text-uppercase fw-bold">
                            <i class="bx bx-credit-card-alt" ></i>
                        </a>
                    </div>';
                } else {
                    return
                    '<div class="d-flex align-items-center gap-2">
                        <a target="_blank" href="' . route('reading.show', $row->reference_no) . '"
                            class="btn btn-primary text-white text-uppercase fw-bold"
                            id="show-btn" data-id="' . e($row->id) . '">
                            <i class="bx bx-receipt"></i>
                        </a>
                    </div>';
                }
            })
            ->rawColumns(['status', 'actions'])
            ->make(true);
    }

}
