<?php

namespace App\Imports;

use App\Models\User;
use App\Models\UserAccounts;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ConcessionaireImport implements
    ToModel,
    WithHeadingRow,
    WithValidation,
    SkipsEmptyRows,
    SkipsOnFailure,
    WithChunkReading
{
    use SkipsFailures;

    protected $skippedRows = [];
    protected $rowCounter = 3;

    public function rules(): array
    {
        return [
            'account_no' => [
                function ($attribute, $value, $fail) {
                    if (empty($value)) {
                        $fail("Missing required field: account no");
                        return;
                    }

                    if (DB::table('concessioner_accounts')->where('account_no', $value)->exists()) {
                        $fail("account no `{$value}` has already been taken");
                    }
                }
            ],
            'name' => 'required',
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'name.required' => 'Missing required field: name',
            'zone.required' => 'Missing required field: zone',
        ];
    }

    public function model(array $row)
    {
        $rowNum = $this->rowCounter++;
        $row = array_map('trim', $row);

        try {
            $user = User::create([
                'name'       => $row['name'],
                'contact_no' => $row['contact_no'] ?? null,
            ]);

            if ($user) {
                $property_type = $this->getPropertyType($row['rate_code']);

                $zone = null;
                if (!empty($row['account_no'])) {
                    $cleanAccountNo = preg_replace('/\s+/', '', $row['account_no']);
                    if (preg_match('/^(\d{3})/', $cleanAccountNo, $matches)) {
                        $zone = $matches[1];
                    }
                }

                $date_connected = null;
                if (isset($row['date_connected']) && $row['date_connected'] !== '') {
                    if (is_numeric($row['date_connected'])) {
                        $date_connected = Carbon::instance(
                            \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row['date_connected'])
                        )->format('Y-m-d');
                    } else {
                        $timestamp = strtotime($row['date_connected']);
                        $date_connected = $timestamp !== false ? Carbon::createFromTimestamp($timestamp)->format('Y-m-d') : null;
                    }
                }

                UserAccounts::create([
                    'user_id'         => $user->id,
                    'zone'            => $zone, // ✅ use extracted zone
                    'account_no'      => $row['account_no'] ?? null,
                    'address'         => $row['address'] ?? null,
                    'property_type'   => $property_type,
                    'rate_code'       => $row['rate_code'] ?? null,
                    'status'          => $row['status'] ?? null,
                    'meter_brand'     => $row['meter_brand'] ?? null,
                    'meter_serial_no' => $row['meter_serial_no'] ?? null,
                    'sc_no'           => $row['sc_no'] ?? null,
                    'date_connected'  => $date_connected,
                    'sequence_no'     => $row['sequence_no'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Import error in Concessionaire Informations Sheet', [
                'error' => $e->getMessage(),
                'row'   => $row,
                'trace' => $e->getTraceAsString(),
            ]);

            $this->skippedRows[] = "Row $rowNum skipped: Exception - " . $e->getMessage();
            return null;
        }
    }

    public function validateRow(array $row, $index)
    {
        if ($this->isRowEmpty($row)) {
            return true;
        }
        return null;
    }

    public function getPropertyType($rate_code)
    {
        $types = [
            1  => 'Residential/Government 1/2"',
            2  => 'Residential/Government 3/4"',
            3  => 'Residential/Government 1"',
            4  => 'Residential/Government 1 1/2"',
            5  => 'Residential/Government 2"',
            6  => 'Residential/Government 3"',
            7  => 'Residential/Government 4"',
            8  => 'Residential/Government 6"',
            9  => 'Residential/Government 8"',
            10 => 'Residential/Government 10"',
            11 => 'Commercial/Industrial 1/2"',
            12 => 'Commercial/Industrial 3/4"',
            13 => 'Commercial/Industrial 1"',
            14 => 'Commercial/Industrial 1 1/2"',
            15 => 'Commercial/Industrial 2"',
            16 => 'Commercial/Industrial 3"',
            17 => 'Commercial/Industrial 4"',
            18 => 'Commercial/Industrial 6"',
            19 => 'Commercial/Industrial 8"',
            20 => 'Commercial/Industrial 10"',
            21 => 'Commercial A 1/2"',
            22 => 'Commercial A 3/4"',
            23 => 'Commercial A 1"',
            24 => 'Commercial A 1 1/2"',
            25 => 'Commercial A 2"',
            26 => 'Commercial A 3"',
            27 => 'Commercial A 4"',
            28 => 'Commercial A 6"',
            29 => 'Commercial A 8"',
            30 => 'Commercial A 10"',
            31 => 'Commercial B 1/2"',
            32 => 'Commercial B 3/4"',
            33 => 'Commercial B 1"',
            34 => 'Commercial B 1 1/2"',
            35 => 'Commercial B 2"',
            36 => 'Commercial B 3"',
            37 => 'Commercial B 4"',
            38 => 'Commercial B 6"',
            39 => 'Commercial B 8"',
            40 => 'Commercial B 10"',
            41 => 'Commercial C 1/2"',
            42 => 'Commercial C 3/4"',
            43 => 'Commercial C 1"',
            44 => 'Commercial C 1 1/2"',
            45 => 'Commercial C 2"',
            46 => 'Commercial C 3"',
            47 => 'Commercial C 4"',
            48 => 'Commercial C 6"',
            49 => 'Commercial C 8"',
            50 => 'Commercial C 10"',
            51 => 'Bulk/Wholesale 1/2"',
            52 => 'Bulk/Wholesale 3/4"',
            53 => 'Bulk/Wholesale 1"',
            54 => 'Bulk/Wholesale 1 1/2"',
            55 => 'Bulk/Wholesale 2"',
            56 => 'Bulk/Wholesale 3"',
            57 => 'Bulk/Wholesale 4"',
            58 => 'Bulk/Wholesale 6"',
            59 => 'Bulk/Wholesale 8"',
            60 => 'Bulk/Wholesale 10"',
        ];

        // $types = [
        //     "ZONE 1"	=> "POBLACION",
        //     "ZONE 2"	=> "POBLACION",
        //     "ZONE 3 BOOK 1" =>	"BARITAN",
        //     'ZONE 3 BOOK 2'	=> "BARITAN",
        //     "ZONE 4 BOOK 1" =>	"BARITAN",
        //     "ZONE 4 BOOK 2" =>	"BARITAN",
        //     "ZONE 5" =>	"BARITAN",
        //     "ZONE 6" =>	"POBLACION",
        //     "ZONE 7 BOOK 1" =>	"HILLTOP SABANG",
        //     "ZONE 7 BOOK 2" =>	"IBAYO SABANG",
        //     "ZONE 8" =>	"PANIBATUHAN POBLACION",
        //     "ZONE 9" =>	"CROSSING NAGBALAYONG",
        //     "ZONE 10" =>	"NAGBALAYONG",
        //     "ZONE 11 BOOK 1" =>	"SABANG PROPER",
        //     "ZONE 11 BOOK 2" =>	"RELOCATION, BACKDOOR SABANG",
        //     "ZONE 11 BOOK 3" =>	"PAG-ASA SABANG",
        //     "ZONE 11 BOOK 4" =>	"NAGWAWA SABANG",
        //     "ZONE 12 BOOK 1" =>	"MABAYO PROPER",
        //     "ZONE 12 BOOK 2" =>	"LAPLAP MABAYO",
        //     "ZONE 13" =>	"MINANGA MABAYO",
        // ];

        return $types[(int) $rate_code] ?? null;
    }

    public function headingRow(): int
    {
        return 2;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function getSkippedRows()
    {
        return $this->skippedRows;
    }

    public function getRowCounter()
    {
        return $this->rowCounter;
    }

}
