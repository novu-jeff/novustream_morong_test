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
use Illuminate\Support\Facades\Http;
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

        // Replace or add this inside the pay() method
        $currentBillId = $data['current_bill']['id'] ?? null;

        if ($currentBillId) {
            // Load the Bill model with its discount relation
            $bill = \App\Models\Bill::with('discount')->find($currentBillId);
            $data['current_bill']['discounts'] = $bill ? $bill->discount : collect();
        }

        $data = $this->meterService::getBill($reference_no);

        if (isset($data['status']) && $data['status'] === 'error') {
            return redirect()->back()->with('alert', [
                'status' => 'error',
                'message' => $data['message']
            ]);
        }

        // ⚙️ Validate reading
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

        // 🧾 Compute arrears stack (from co-dev)
        $arrearsStack = collect();
        $previousUnpaid = (float)($currentBill['previous_unpaid'] ?? 0);
        if ($previousUnpaid > 0) {
            $arrearsMonth = \Carbon\Carbon::parse($currentBill['bill_period_from'])
                ->subMonth()
                ->format('F');
            $arrearsStack[$arrearsMonth] = $previousUnpaid;
        }

        // 🧮 Use dynamic penalty computation (from PaymentBreakdownPenalty)
        $amount = (float)($currentBill['total'] ?? 0);
        $amount_afterDue = (float)($currentBill['amount_after_due'] ?? 0);
        $discount = (float)($currentBill['discount'] ?? 0);
        $tax = (float) ($currentBill['tax'] ?? 0);
        $currentDay = now()->day;

        $penaltyEntry = \App\Models\PaymentBreakdownPenalty::where('due_from', '<=', $currentDay)
            ->where('due_to', '>=', $currentDay)
            ->first();

        // ✅ Always ensure defaults
        $assumedPenalty = 0;
        $assumedAmountAfterDue = $amount_afterDue;

        // 🔹 Try to compute based on dynamic penalty config
        if ($penaltyEntry) {
            $penaltyBase = $amount - $discount;

            if ($penaltyEntry->amount_type === 'percentage') {
                $assumedPenalty = $penaltyBase * floatval($penaltyEntry->amount);
            } elseif ($penaltyEntry->amount_type === 'fixed') {
                $assumedPenalty = floatval($penaltyEntry->amount);
            }
        } else {
            // fallback 10%
            $assumedPenalty = $amount * 0.10;
        }

        $assumedAmountAfterDue = $amount + $previousUnpaid + $tax - $discount;

        $data['current_bill']['assumed_penalty'] = $assumedPenalty;
        $data['current_bill']['assumed_amount_after_due'] = $assumedAmountAfterDue;

        // 💰 Add service fees
        $hitpay_fee = 20;
        $novupay_fee = 10;
        $additional_service_fee = $hitpay_fee + $novupay_fee;

        $final_amount = $assumedAmountAfterDue + $additional_service_fee;

        // 🧾 Build payment payload
        $paymentPayload = [
            'reference_no' => $reference_no,
            'amount' => $final_amount,
            'customer' => [
                'name' => $data['client']['name'] ?? '',
                'account_no' => $data['client']['account_no'] ?? '',
                'address' => $data['client']['address'] ?? '',
            ],
        ];

        // 🔹 Generate HitPay checkout link (your logic)
        $hitpayData = app(\App\Http\Controllers\PaymentController::class)
            ->createHitpayPaymentRequest($reference_no, $paymentPayload);

        if ($hitpayData && !empty($hitpayData['url'])) {
            $url = $hitpayData['url']; // ✅ HitPay checkout link
        } else {
            $url = env('NOVUPAY_URL') . '/payment/merchants/' . $reference_no;
        }

        // 🔹 Generate QR code (HitPay or fallback NovuPay)
        $qr_code = $this->generateService::qr_code($url, 80);

        return view('payments.pay', compact('data', 'reference_no', 'qr_code', 'arrearsStack'));
    }



    private function calculateTotalDue(array $currentBillData, ?array $payload = null, float $fullArrears = 0): array
    {
        // 1. amount -> total
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
        $tax = (float) ($currentBillData['tax'] ?? 0);

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
        // 2. removed arrears
        $totalDue = ($currentBill - $discount) + $dueDatePenalty - $advancePayment + $tax;
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
                'tax' => $tax,
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


    public function processCashPayment(string $reference_no, array $payload) {

        $result = $this->getBill($reference_no, $payload, true);

        if (isset($result['error'])) {
            return redirect()->back()->with('alert', [
                'status' => 'error',
                'message' => $result['error']
            ]);
        }

        $data = $result['data'];
        $now = Carbon::now()->format('Y-m-d H:i:s');
        // 3. amount -> total and removed + previous_unpaid
        $amountPay = (float) $data['current_bill']['total'];
        $change = (float) $payload['payment_amount'] - $amountPay;
        $forAdvancePayment = isset($payload['for_advances']) && $payload['for_advances'];

        $saveChange = ($change != 0 && $forAdvancePayment);

        $currentBill = Bill::find($data['current_bill']['id']);

        if ($currentBill) {
            $currentBill->update([
                'isPaid' => true,
                'amount_paid' => $payload['payment_amount'],
                'change' => $change,
                'payor_name' => $payload['payor'],
                'date_paid' => $now,
                'isChangeForAdvancePayment' => $saveChange,
                'payment_method' => 'cash',
            ]);
        }

        if (!empty($data['unpaid_bills'])) {
            foreach ($data['unpaid_bills'] as $unpaid_bill) {
                $unpaidBill = Bill::find($unpaid_bill['id']);
                if ($unpaidBill) {
                    $unpaidBill->update([
                        'payor_name' => $payload['payor'],
                        'date_paid' => $now,
                        'isPaid' => true,
                        'amount_paid' => $payload['payment_amount'],
                        'change' => $change,
                        'paid_by_reference_no' => $reference_no,
                    ]);
                }
            }
        }

        return redirect()->back()->with('alert', [
            'status' => 'success',
            'message' => 'Bill has been paid'
        ]);
    }

    public function processOnlinePaymentOld(string $reference_no, array $payload)
    {
        $result = $this->getBill($reference_no, $payload, false);

        if (isset($result['error'])) {
            return redirect()->back()->with('alert', [
                'status' => 'error',
                'message' => $result['error']
            ]);
        }

        $url = env('NOVUPAY_URL') . '/payment/merchants/' . $reference_no;

        return redirect()->route('payments.pay', ['reference_no' => $reference_no])->with('alert', [
            'status' => 'success',
            'payment_request' => true,
            'redirect' => $url,
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

        $billData = $result['data']['current_bill'] ?? null;

        if (!$billData) {
            return back()->with('alert', [
                'status' => 'error',
                'message' => 'Missing bill data.'
            ]);
        }

        // ✅ Define $bill model properly
        $bill = \App\Models\Bill::find($billData['id']);
        // dd($result);
        if (!$bill) {
            return back()->with('alert', [
                'status' => 'error',
                'message' => 'Bill not found in database.'
            ]);
        }

        // Prepare HitPay payload
        $amount = number_format((float)$billData['total'], 2, '.', '');
        $discount = number_format((float)$billData['discount'], 2, '.', '');
        $advancePayment = number_format((float)$billData['advances'], 2, '.', '');
        $amount_with_penalty = number_format((float)$billData['total'] + (float)$billData['penalty'], 2, '.', '');
        $hitpay_fee = 20;
        $novupay_fee = 10;
        $additional_service_fee = $hitpay_fee + $novupay_fee;
        $final_amount = $amount + $additional_service_fee - $advancePayment - $discount;

        $payor = $result['data']['client']['name'] ?? ($payload['payor'] ?? 'Customer');
        $email = $result['data']['client']['email'] ?? ($payload['email'] ?? 'jeff@novulutions.com');
        $account_no = $result['data']['client']['account_no'] ?? ($payload['account_no'] ?? '000000');
        $purpose = "Amount Due: PHP {$amount}\nConvenience Fee: PHP {$additional_service_fee}\nAccount #: {$account_no}";


        $hitpayPayload = [
            'amount' => $amount + 30,
            'currency' => 'PHP',
            'email' => $email,
            'purpose' => $purpose,
            'reference_number' => $reference_no,
            'redirect_url' => env('HITPAY_REDIRECT_URL'),
            'webhook' => env('HITPAY_WEBHOOK_URL'),
            'send_email' => true,
            'send_sms' => true,
            'name' => $payor,
            'add_admin_fee' => true,
            'admin_fee' => '15.00',
        ];

        // Send request to HitPay API
        $response = \Http::withHeaders([
            'X-BUSINESS-API-KEY' => env('HITPAY_API_KEY'),
        ])->post(env('HITPAY_API_URL') . '/payment-requests', $hitpayPayload);

        if ($response->failed()) {
            $error = $response->json('message') ?? 'Failed to create HitPay payment.';
            return redirect()->back()->with('alert', [
                'status' => 'error',
                'message' => $error,
            ]);
        }

        $hitpayData = $response->json();

        // dd($hitpayData);

        // ✅ Now update the Bill record with HitPay references
        $bill->update([
            'payment_method' => 'online',
            'initiated_at' => now(),
            'hitpay_reference' => $hitpayData['reference']
                ?? $hitpayData['reference_number']
                ?? null,
            'hitpay_payment_id' => $hitpayData['id'] ?? null,
        ]);


        return redirect()->back()->with('alert', [
            'status' => 'success',
            'payment_request' => true,
            'redirect' => $hitpayData['url'],
        ]);
    }


    public function createHitpayPaymentRequest(string $reference_no, array $payload): ?array
    {
        try {
            $result = $this->getBill($reference_no, $payload, false);

            if (isset($result['error'])) {
                \Log::error('HitPay error: ' . $result['error']);
                return null;
            }

            $billData = $result['data']['current_bill'] ?? null;
            if (!$billData) {
                \Log::error('Missing bill data for HitPay', ['reference_no' => $reference_no]);
                return null;
            }

            $amount = number_format((float)$billData['amount'], 2, '.', '');
            if($amount <= 2000) {
                $hitpay_fee = 20;
            }else {
                $hitpay_fee = ($amount * 0.01);
            }
            $novupay_fee = 10;
            $additional_service_fee = $hitpay_fee + $novupay_fee;

            $final_amount = (float)$amount + $additional_service_fee;

            $payor = $result['data']['client']['name'] ?? ($payload['payor'] ?? 'Sta. Rita Customer');
            $email = $result['data']['client']['email'] ?? ($payload['email'] ?? 'srwdsystem2023@gmail.com');
            $account_no = $result['data']['client']['account_no'] ?? ($payload['account_no'] ?? '000000');

            // 🧾 Purpose formatting
            $purpose = "Amount Due: PHP {$amount}\nConvenience Fee: PHP {$additional_service_fee}\nAccount #: {$account_no}";

            // ⚙️ Default payment methods (include QRPH if allowed)
            // $paymentMethods = ["gcash","gcash_qr","qrph_netbank","upay_bayd","upay_ecpy","upay_instapay","upay_online","upay_pchc","upay_plwn","xpay_card"];
            $paymentMethods = ['gcash', 'qrph_netbank'];
            // dd($final_amount, $paymentMethods);

            // 🚫 If total amount < 800, remove QRPH from payment options
            if ($final_amount < 800) {
                $paymentMethods = array_filter($paymentMethods, fn($m) => $m !== "qrph_netbank");
                \Log::info('Removed QRPH (amount < 800)', [
                    'reference_no' => $reference_no,
                    'amount' => $final_amount
                ]);
            // removed gcash since it is costing us 2.5% unlike qrph which is only 1% or 20php per transaction
            } else {
                $paymentMethods = array_filter($paymentMethods, fn($m) => $m !== "gcash");
            }

            $hitpayPayload = [
                'amount' => $final_amount,
                'currency' => 'PHP',
                'email' => $email,
                'purpose' => $purpose,
                'reference_number' => $reference_no,
                'redirect_url' => env('HITPAY_REDIRECT_URL'),
                'webhook' => env('HITPAY_WEBHOOK_URL'),
                'send_email' => true,
                'send_sms' => true,
                'name' => $payor,
                'add_admin_fee' => true,
                'admin_fee' => '15.00',
                'payment_methods' => array_values($paymentMethods),
            ];

            // dd($hitpayPayload);

            $response = \Http::withHeaders([
                'X-BUSINESS-API-KEY' => env('HITPAY_API_KEY'),
            ])->post(env('HITPAY_API_URL') . '/payment-requests', $hitpayPayload);

            // dd($response->body());
            if ($response->failed()) {
                \Log::error('HitPay API request failed', ['body' => $response->body()]);
                return null;
            }

            $data = $response->json();
            return [
                'id' => $data['id'] ?? null,
                'url' => $data['url'] ?? null,
            ];
        } catch (\Exception $e) {
            \Log::error('createHitpayPaymentRequest exception: ' . $e->getMessage());
            return null;
        }
    }




    public function handleRedirect(Request $request)
    {
        \Log::info('HitPay redirect received in PaymentController', $request->all());

        $status = strtolower($request->query('status'));
        $hitpay_reference = $request->query('reference');

        if (!$hitpay_reference || !$status) {
            abort(404, 'Invalid payment reference.');
        }

        // ✅ Step 1: Verify payment details directly with HitPay API
        $response = \Http::withHeaders([
            'X-BUSINESS-API-KEY' => env('HITPAY_API_KEY'),
        ])->get(env('HITPAY_API_URL') . "/payment-requests/{$hitpay_reference}");

        if ($response->failed()) {
            \Log::error('HitPay verify API failed', ['reference' => $hitpay_reference]);
            return view('payments.failed', [
                'reference' => $hitpay_reference,
                'message' => 'Unable to verify payment from HitPay.',
            ]);
        }

        $payment = $response->json();
        \Log::info('HitPay verified payment', $payment);

        $status = strtolower($payment['status'] ?? $status);
        $reference_number = $payment['reference_number'] ?? null;
        $amount = (float) ($payment['amount'] ?? 0);
        $payor = $payment['name'] ?? 'Unknown';

        // ✅ Step 2: Find your local bill using either HitPay or local reference
        $bill = \App\Models\Bill::where('hitpay_reference', $hitpay_reference)
            ->orWhere('reference_no', $reference_number)
            ->first();

        if (!$bill) {
            \Log::warning("HitPay verify: Bill not found for {$hitpay_reference}");
            return view('payments.failed', [
                'reference' => $hitpay_reference,
                'message' => 'Payment verified, but no matching bill found.',
            ]);
        }

        // ✅ Step 3: Mark bill as paid if HitPay says completed
        if (in_array($status, ['completed', 'succeeded', 'success'])) {
            $bill->update([
                'isPaid' => 1,
                'amount_paid' => $amount,
                'payor_name' => $payor,
                'date_paid' => now(),
                'payment_method' => 'online',
            ]);

            return view('payments.success', [
                'reference' => $bill->reference_no,
                'message' => 'Your payment was verified and marked as paid.',
            ]);
        }

        // ❌ If HitPay says failed/canceled
        return view('payments.failed', [
            'reference' => $bill->reference_no,
            'message' => 'Payment not completed or canceled.',
        ]);
    }




    public function createHitPayPayment(Request $request)
    {
        $reference_no = $request->input('reference_no');
        $amount = $request->input('amount');

        $response = Http::withHeaders([
            'X-BUSINESS-API-KEY' => env('HITPAY_API_KEY'),
            'Content-Type' => 'application/json',
        ])->post(env('HITPAY_API_URL') . '/payment-requests', [
            'amount' => $amount,
            'currency' => 'PHP',
            'reference_number' => $reference_no,
            'redirect_url' => env('HITPAY_REDIRECT_URL'),
            'webhook' => env('HITPAY_WEBHOOK_URL'),
            'name' => 'Bill Payment #' . $reference_no,
            'email' => $request->input('email', 'customer@example.com'),
        ]);

        if ($response->failed()) {
            return back()->with('alert', [
                'status' => 'error',
                'message' => 'Failed to create payment request. Please try again.'
            ]);
        }

        $data = $response->json();
        return response()->json($data);
    }

    public function hitpayCallback(Request $request)
    {
        // HitPay redirects here after payment
        $reference_no = $request->input('reference_number');
        $status = $request->input('status'); // 'completed', 'failed', etc.

        // Update your DB or bill status here
        // Example:
        // Bill::where('reference_no', $reference_no)->update(['status' => $status]);

        return redirect()->route('payments.pay', ['reference_no' => $reference_no])
            ->with('alert', [
                'status' => $status === 'completed' ? 'success' : 'error',
                'message' => "Payment {$status}"
            ]);
    }


    public function hitpayWebhook(Request $request)
    {
        $payload = $request->all();

        Log::info('💳 HitPay Webhook received', $payload);

        if (empty($payload) || !isset($payload['reference_number'])) {
            return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        $reference_no = $payload['reference_number'];
        $payment_status = strtolower($payload['status'] ?? '');
        $payment_amount = (float)($payload['amount'] ?? 0);
        $payor = $payload['customer']['name'] ?? 'Unknown';

        // ✅ Only process successful payments
        if (!in_array($payment_status, ['completed', 'succeeded'])) {
            Log::warning("⚠️ Ignored HitPay payment with status: {$payment_status}");
            return response()->json(['status' => 'ignored', 'message' => 'Payment not completed'], 200);
        }

        try {
            $meterService = new MeterService();
            $result = $meterService->getBill($reference_no, $payload, true);

            if (isset($result['error'])) {
                Log::error("❌ HitPay webhook bill retrieval failed: {$result['error']}");
                return response()->json(['status' => 'error', 'message' => $result['error']], 400);
            }

            $data = $result['data'];
            $now = Carbon::now();

            $amount = (float)($data['current_bill']['amount'] ?? 0);
            $penalty = (float)($data['current_bill']['penalty'] ?? 0);
            $total = $amount + $penalty;

            $change = $payment_amount - $total;
            $forAdvancePayment = !empty($payload['for_advances']);
            $saveChange = ($change > 0 && $forAdvancePayment);

            // 🔹 Update main bill
            $currentBill = Bill::find($data['current_bill']['id']);
            if ($currentBill) {
                $currentBill->update([
                    'isPaid' => true,
                    'amount_paid' => $payment_amount,
                    'change' => $change,
                    'payor_name' => $payor,
                    'date_paid' => $now,
                    'isChangeForAdvancePayment' => $saveChange,
                    'payment_method' => 'hitpay',
                    'payment_reference' => $payload['payment_id'] ?? null,
                ]);
            }

            // 🔹 Optionally mark arrears as paid
            if (!empty($data['unpaid_bills'])) {
                foreach ($data['unpaid_bills'] as $unpaid_bill) {
                    $unpaidBill = Bill::find($unpaid_bill['id']);
                    if ($unpaidBill) {
                        $unpaidBill->update([
                            'isPaid' => true,
                            'amount_paid' => $unpaidBill['amount'] ?? 0,
                            'change' => 0,
                            'payor_name' => $payor,
                            'date_paid' => $now,
                            'paid_by_reference_no' => $reference_no,
                        ]);
                    }
                }
            }

            Log::info("✅ HitPay payment processed successfully for ref {$reference_no}");

            return response()->json(['status' => 'success', 'message' => 'Bill updated successfully'], 200);

        } catch (\Exception $e) {
            Log::error("💥 HitPay Webhook Exception: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
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

            // Update unpaid bills if needed
            if (!empty($bill['unpaid_bills'])) {
                foreach ($bill['unpaid_bills'] as $unpaid_bill) {
                    $unpaidBill = Bill::find($unpaid_bill['id']);
                    if ($unpaidBill) {
                        $unpaidBill->update([
                            'isPaid' => true,
                            'amount_paid' => $payload['amount'],
                            'date_paid' => $now,
                            'paid_by_reference_no' => $reference_no,
                            'payment_method' => 'online',
                        ]);
                    }
                }
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
