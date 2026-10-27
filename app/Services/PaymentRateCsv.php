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

    public function parse(UploadedFile $file, string $level): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (is_array($header)) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }
            if ($header !== self::HEADER) {
                $this->invalid('Use the exported CSV template with headers: '.implode(', ', self::HEADER).'.');
            }
            $rows = [];
            $seen = [];
            $line = 1;
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if ($cells === [null] || count(array_filter($cells, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if ($line > 201 || count($cells) !== 4) {
                    $this->invalid("Row {$line}: expected four columns and at most 200 rate rows.");
                }
                [$program, $type, $stage, $amount] = array_map('trim', $cells);
                if ($program !== $level || ! in_array($type, self::TYPES, true) || ! in_array($stage, self::STAGES, true)) {
                    $this->invalid("Row {$line}: use the selected program ({$level}) and a supported rate type and defense stage.");
                }
                $key = $type.'|'.$stage;
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
                $this->invalid('Enter at least one numeric amount in the CSV. Blank amounts leave rates unchanged.');
            }

            return $rows;
        } finally {
            fclose($handle);
        }
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
