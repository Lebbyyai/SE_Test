<?php

namespace App\Services;

use App\Models\PaymentRate;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class PaymentRateCsv
{
    public const HEADER = ['program_level', 'type', 'defense_type', 'amount'];

    public const LEVELS = ['Masteral', 'Doctorate'];

    public const STAGES = ['Proposal', 'Pre-final', 'Final'];

    public const TYPES = ['Adviser', 'Panel Chair', 'Panel Member', 'Panel Member 1', 'Panel Member 2', 'Panel Member 3', 'Panel Member 4', 'External Panel Chair', 'External Panel Member', 'REC Fee', 'School Share'];

    public function parse(UploadedFile $file): array
    {
        $rows = [];
        $seen = [];
        $records = app(PaymentRateFileReader::class)->rows($file);
        foreach ($records as $index => $cells) {
            $line = $index + 2;
            if ($cells === [null] || count(array_filter($cells, fn ($value) => $value !== null && $value !== '')) === 0) {
                continue;
            }
            if ($index >= 200 || count($cells) !== 4 || count(array_filter($cells, fn ($value) => $value !== null && ! is_string($value) && ! is_int($value) && ! is_float($value)))) {
                $this->invalid("Row {$line}: expected four text or numeric values and at most 200 rate rows.");
            }
            $cells = array_map(fn ($value) => $value === null ? '' : (string) $value, $cells);
            [$program, $type, $stage, $amount] = array_map('trim', $cells);
            if (! in_array($program, self::LEVELS, true)) {
                $this->invalid("Row {$line}: unknown program '{$program}'. Use Masteral or Doctorate.");
            }
            if (! in_array($type, self::TYPES, true)) {
                $this->invalid("Row {$line}: unknown rate type '{$type}'. Use a rate type from the exported template.");
            }
            if (! in_array($stage, self::STAGES, true)) {
                $this->invalid("Row {$line}: unknown defense stage '{$stage}'. Use Proposal, Pre-final or Final. Correct the row and check for duplicate rates before importing.");
            }
            $key = $program.'|'.$type.'|'.$stage;
            if (isset($seen[$key])) {
                $this->invalid("Row {$line}: duplicate rate for {$type}, {$stage}.");
            }
            $seen[$key] = true;
            if ($amount === '') {
                continue;
            }
            if (! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $amount)) {
                $this->invalid("Row {$line}: amount must be from 0 to 99999999.99, with at most two decimal places and no currency signs or commas.");
            }
            $rows[] = ['program_level' => $program, 'type' => $type, 'defense_type' => $stage, 'amount' => $amount];
        }
        if ($rows === []) {
            $this->invalid('Enter at least one numeric amount in the file. Blank amounts leave rates unchanged.');
        }

        return $rows;
    }

    public function preview(array $rows): array
    {
        return array_map(function ($row) {
            $current = PaymentRate::where('program_level', $row['program_level'])->where('type', $row['type'])->where('defense_type', $row['defense_type'])->first();

            return $row + ['previous_amount' => $current?->amount, 'action' => $current === null ? 'Add' : ((float) $current->amount === (float) $row['amount'] ? 'Unchanged' : 'Update')];
        }, $rows);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
