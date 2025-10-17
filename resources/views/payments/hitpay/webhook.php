<?php

use App\Models\Bill;
use App\Services\MeterService;
use Illuminate\Support\Carbon;

// Make sure to include Laravel bootstrap if this file is outside the framework context
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Parse webhook payload
$payload = json_decode(file_get_contents('php://input'), true);

// Log for debugging (optional)
\Log::info('HitPay Webhook received', $payload);

if (!$payload || !isset($payload['reference_number'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
    exit;
}

$reference_no = $payload['reference_number'];
$payment_status = strtolower($payload['status'] ?? '');
$payment_amount = (float)($payload['amount'] ?? 0);
$payor = $payload['customer']['name'] ?? 'Unknown';

// Only proceed if payment was successful
if ($payment_status !== 'completed' && $payment_status !== 'succeeded') {
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'message' => 'Payment not completed']);
    exit;
}

try {
    // Example: mimic processCashPayment logic
    $meterService = new MeterService();
    $result = $meterService->getBill($reference_no, $payload, true);

    if (isset($result['error'])) {
        \Log::error('HitPay webhook bill retrieval failed: ' . $result['error']);
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $result['error']]);
        exit;
    }

    $data = $result['data'];
    $now = Carbon::now()->format('Y-m-d H:i:s');

    $amount = (float)$data['current_bill']['amount'] + (float)$data['current_bill']['penalty'];
    $change = $payment_amount - $amount;
    $forAdvancePayment = isset($payload['for_advances']) && $payload['for_advances'];
    $saveChange = ($change != 0 && $forAdvancePayment);

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

    // Update other unpaid bills (if applicable)
    if (!empty($data['unpaid_bills'])) {
        foreach ($data['unpaid_bills'] as $unpaid_bill) {
            $unpaidBill = Bill::find($unpaid_bill['id']);
            if ($unpaidBill) {
                $unpaidBill->update([
                    'isPaid' => true,
                    'amount_paid' => $payment_amount,
                    'change' => $change,
                    'payor_name' => $payor,
                    'date_paid' => $now,
                    'paid_by_reference_no' => $reference_no,
                ]);
            }
        }
    }

    // Respond to HitPay
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Bill updated successfully']);
} catch (Exception $e) {
    \Log::error('HitPay Webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
