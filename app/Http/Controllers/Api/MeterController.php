<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillBreakdown;
use App\Models\Reading;
use App\Models\User;
use App\Services\MeterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Zxing\QrReader;

class MeterController extends Controller
{

    public $meterService;

    public function __construct(MeterService $meterService)  {
        $this->meterService = $meterService;
    }

    public function search(Request $request) {

        $validator = Validator::make($request->all(), [
            'option' => 'required|string|in:qr_code,input,upload_image',
            'content' => 'required'
        ], [
            'option.reqiorequired' => 'Option is required',
            'option.string' => 'Option must be a string',
            'option.in' => 'Invalid option selected',
        ]);

        if($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'error' => $validator->errors()
            ], 400);
        }

        $payloads = $request->all();

        $option = $payloads['option'];
        $content = $payloads['content'];

        switch ($option) {
            case 'qr_code':
                $toSearch = $this->decodeQrCodeFile($content);
                break;

            case 'input':
                $toSearch = $this->searchByInput($content);
                break;

            case 'upload_image':
                return response()->json([
                    'status' => 'error',
                    'message' => 'Upload image option is not yet implemented'
                ], 501);

            default:
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid option selected'
                ], 400);
        }

        return $this->find($toSearch);

    }

    private function decodeQrCodeFile($filePath) {

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        try {
            $qrcode = new QrReader($filePath);
            $decodedText = $qrcode->text();

            if (!$decodedText) {
                return response()->json(['error' => 'Unable to decode QR code'], 400);
            }

            return trim($decodedText);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while decoding QR code', 'details' => $e->getMessage()], 500);
        }
    }

    private function searchByInput($input) {
        return trim($input);
    }

    private function find($toSearch) {

        $toSearch = [
            'meter_no' => $toSearch,
        ];

        return $this->meterService->locate($toSearch);
    }

    public function reading(Request $request) {

        $payload = $request->all();

        $validator = Validator::make($payload, [
            'meter_no' => 'required|string',
                function ($attribute, $value, $fail) {
                    if (!DB::table('concessioner_accounts')
                        ->where('meter_serial_no', $value)
                        ->orWhere('account_no', $value)
                        ->exists()) {
                        $fail('The meter no. or account no. does not exist.');
                    }
                },
            'previous_reading' => 'required|integer',
            'present_reading' => 'required|integer|gt:previous_reading',

        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'error' => $validator->errors()
            ], 400);
        }

        DB::beginTransaction();

        try {

            $account = $this->meterService->getAccount($payload['meter_no']);

            $meter_no = $account->meter_serial_no;
            $property_type_id = $account->property_type;

            $present_reading = $payload['present_reading'];

            $computed = $this->meterService->create_breakdown([
                'meter_no' => $meter_no,
                'property_type_id' => $property_type_id,
                'present_reading' => $present_reading
            ]);

            if($computed['status'] == 'error') {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => $computed['message']
                ], 400);
            }

            // READING
            $reading = Reading::create($computed['reading']);

            $computed['bill']['reading_id'] = $reading->id;

            // BILL
            $bill = Bill::create($computed['bill']);

            // BILL BREAKDOWN
            foreach($computed['deductions'] as $deductions) {
                BillBreakdown::create([
                    'bill_id' => $bill->id,
                    'name' => $deductions['name'],
                    'description' => $deductions['description'],
                    'amount' => $deductions['amount']
                ]);
            }

            DB::commit();

            $reference_no = $bill->reference_no;

            $data = $this->meterService->getBill($reference_no);

            $data['url'] = route('reading.show', ['reference_no' => $reference_no]);

            return response()->json([
                'status' => 'success',
                'message' => 'Bill has been created',
                'data' => $data
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error occurred: ' . $e->getMessage()
            ], 500);
        }

    }
}
