<?php

namespace App\Imports;

use App\Models\SeniorDiscount;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class SCDiscountImport extends DefaultValueBinder implements
    ToModel,
    WithHeadingRow,
    WithValidation,
    SkipsEmptyRows,
    SkipsOnFailure,
    WithChunkReading,
    WithCustomValueBinder,
    WithCalculatedFormulas
{
    use SkipsFailures;

    protected array $skippedRows = [];
    protected int $rowCounter = 3;
    protected int $inserted = 0;
    protected int $updated = 0;

    public function bindValue(Cell $cell, $value)
    {
        if ($cell->getColumn() === 'A') {
            // Force account number to be treated as string to preserve leading zeros
            $cell->setValueExplicit($value, DataType::TYPE_STRING);
            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function rules(): array
    {
        return [
            'account_no' => ['required'],
            'type' => ['required'],
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'account_no.required' => 'Missing required field: account_no',
            'type.required' => 'Missing required field: type',
        ];
    }

    public function model(array $row)
    {
        $rowNum = $this->rowCounter++;
        $row = array_map('trim', $row);

        try {
            $accountNo = $this->sanitizeAccountNo($row['account_no'] ?? null);
            $idNo = $row['id_no'] ?? null;
            $effectiveDate = $this->parseDate($row['effectivity_date'] ?? null);
            $expiredDate = $this->parseDate($row['expired_date'] ?? null);
            $type = $row['discount_type_id'] ?? 1;

            if (!$accountNo) {
                $this->skippedRows[] = "Row $rowNum skipped: Missing required account_no.";
                return null;
            }

            $existing = SeniorDiscount::where('account_no', $accountNo)->first();

            if ($existing) {
                $existing->update([
                    'id_no' => $idNo,
                    'effective_date' => $effectiveDate,
                    'expired_date' => $expiredDate,
                    'discount_type_id' => $type,
                ]);

                $this->updated++;
                return null;
            }

            $this->inserted++;
            return new SeniorDiscount([
                'account_no' => $accountNo,
                'id_no' => $idNo,
                'effective_date' => $effectiveDate,
                'expired_date' => $expiredDate,
                'discount_type_id' => $type,
            ]);
        } catch (\Exception $e) {
            $this->skippedRows[] = "Row $rowNum skipped: Exception - " . $e->getMessage();
            Log::error('Import error in Senior Citizen Discount Sheet', [
                'error' => $e->getMessage(),
                'row'   => $row,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    private function sanitizeAccountNo(?string $accountNo): ?string
    {
        if (!$accountNo) return null;

        $accountNo = trim($accountNo);
        // Remove any dashes/hyphens, then pad to same length (10 digits assumed)
        $cleaned = str_replace(['-', ' '], '', $accountNo);
        return str_pad($cleaned, 10, '0', STR_PAD_LEFT);
    }

    private function parseDate($value)
    {
        if (!$value) {
            return null;
        }
        if (is_numeric($value)) {
            return Date::excelToDateTimeObject($value)->format('Y-m-d');
        }
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    public function headingRow(): int
    {
        return 2;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function getSkippedRows(): array
    {
        return $this->skippedRows;
    }

    public function getRowCounter(): int
    {
        return $this->rowCounter;
    }

    public function getInsertedCount(): int
    {
        return $this->inserted;
    }

    public function getUpdatedCount(): int
    {
        return $this->updated;
    }
}
