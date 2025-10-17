<?php

namespace App\Imports;

use App\Models\PropertyTypes;
use App\Models\BaseRate;
use App\Models\Rates;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class RateCodesImport implements
    ToModel,
    WithHeadingRow,
    WithValidation,
    SkipsEmptyRows,
    SkipsOnFailure,
    WithChunkReading
{
    use SkipsFailures;

    protected array $skippedRows = [];
    protected int $rowCounter = 3;
    protected string $sheetName;

    public function __construct(string $sheetName = 'Rate Codes')
    {
        $this->sheetName = $sheetName;
    }

    public function rules(): array
    {
        return [
            'rate_code' => ['required'],
            'name'      => ['required'],
            'rate'      => ['required', 'numeric'],
            '0_10'      => ['required', 'numeric'],
            '11_20'     => ['required', 'numeric'],
            '21_30'     => ['required', 'numeric'],
            '31_40'     => ['required', 'numeric'],
            '41_50'     => ['required', 'numeric'],
            '51_60'     => ['required', 'numeric'],
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'rate_code.required' => 'Missing required field: rate_code',
            'name.required'      => 'Missing required field: name',
            'rate.required'      => 'Missing required field: rate',
            '0_10.required'      => 'Missing required field: 0-10',
            '11_20.required'     => 'Missing required field: 11-20',
            '21_30.required'     => 'Missing required field: 21-30',
            '31_40.required'     => 'Missing required field: 31-40',
            '41_50.required'     => 'Missing required field: 41-50',
            '51_60.required'     => 'Missing required field: 51-60',
        ];
    }

    public function model(array $row)
    {
        $rowNum = $this->rowCounter++;

        $normalized = [];
        foreach ($row as $key => $value) {
            $cleanKey = strtolower(str_replace([' ', '-'], '_', $key));
            $normalized[$cleanKey] = trim($value);
        }
        $row = $normalized;

        try {
            $property = PropertyTypes::updateOrCreate(
                ['rate_code' => $row['rate_code']],
                ['name' => $row['name']]
            );

            $baseRate = BaseRate::updateOrCreate(
                ['property_type_id' => $property->id],
                ['rate' => $row['rate']]
            );

            $runningAmount = 0;
            foreach ($row as $key => $cumCharge) {
                if (preg_match('/^\d+_\d+$/', $key)) {
                    [$from, $to] = explode('_', $key);

                    for ($i = (int) $from; $i <= (int) $to; $i++) {
                        if ($i == 0) {
                            $runningAmount = $baseRate->rate;
                        } else {
                            $runningAmount += $cumCharge;
                        }

                        Rates::updateOrCreate(
                            [
                                'property_types_id' => $property->id,
                                'cu_m'              => $i,
                            ],
                            [
                                'charge' => $cumCharge,
                                'amount' => $runningAmount,
                            ]
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            $this->skippedRows[] = "Row $rowNum skipped (Sheet: {$this->sheetName}): " . $e->getMessage();

            Log::error('Import error in RateCodesImport', [
                'sheet' => $this->sheetName,
                'row'   => $row,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function headingRow(): int
    {
        return 2;
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function getSkippedRows(): array
    {
        return $this->skippedRows;
    }

    public function getRowCounter(): int
    {
        return $this->rowCounter;
    }
}
