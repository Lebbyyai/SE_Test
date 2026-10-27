<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class PaymentRateFileReader
{
    public function rows(UploadedFile $file): array
    {
        return match (strtolower($file->getClientOriginalExtension())) {
            'csv' => $this->csv($file),
            'json' => $this->json($file),
            'xlsx' => $this->xlsx($file),
            default => $this->fail('Choose a CSV, JSON or XLSX file.'),
        };
    }

    private function csv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        try {
            $rows = [];
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $rows[] = $row;
                if (count($rows) > 202) {
                    $this->fail('Use at most 200 rate rows.');
                }
            }

            return $this->table($rows);
        } finally {
            fclose($handle);
        }
    }

    private function json(UploadedFile $file): array
    {
        try {
            $data = json_decode(ltrim(file_get_contents($file->getRealPath()), "\xEF\xBB\xBF"), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail('The JSON file is invalid. Use an array of rate objects or an object with a rates array.');
        }
        if (is_array($data) && ! array_is_list($data)) {
            $data = $data['rates'] ?? null;
        }
        if (! is_array($data) || ! array_is_list($data) || count($data) > 200) {
            $this->fail('Use a JSON array of at most 200 rate objects, optionally inside a rates property.');
        }
        $rows = [];
        foreach ($data as $i => $item) {
            if (! is_array($item) || array_diff(PaymentRateCsv::HEADER, array_keys($item))) {
                $this->fail('JSON entry '.($i + 1).': include program_level, type, defense_type and amount.');
            }
            $rows[] = array_map(fn ($key) => $item[$key], PaymentRateCsv::HEADER);
        }

        return $rows;
    }

    private function xlsx(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            $this->fail('The XLSX file is damaged or is not an Excel workbook.');
        }
        try {
            $size = 0;
            if ($zip->numFiles > 200) {
                $this->fail('The workbook contains too many parts. Use a simple rate table.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $part = $zip->statIndex($i);
                $size += $part['size'];
                if ($part['size'] > 4194304 || $size > 8388608) {
                    $this->fail('The expanded workbook is too large. Use a simple rate table.');
                }
            }
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $targets = [];
            foreach ($rels->children() as $rel) {
                if ((string) $rel['TargetMode'] !== 'External') {
                    $targets[(string) $rel['Id']] = (string) $rel['Target'];
                }
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($this->xml($zip, 'xl/sharedStrings.xml')->children() as $si) {
                    $strings[] = implode('', array_map(fn ($text) => (string) $text, $si->xpath('.//*[local-name()="t"]')));
                }
            }
            $all = [];
            foreach ($workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') as $sheet) {
                $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $target = $targets[(string) $attributes['id']] ?? null;
                if ($target === null) {
                    $this->fail('A worksheet link is missing or external. Save the file as a standard XLSX workbook.');
                }
                $parts = [];
                foreach (explode('/', str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target) as $segment) {
                    if ($segment === '..') {
                        array_pop($parts);
                    } elseif ($segment !== '.' && $segment !== '') {
                        $parts[] = $segment;
                    }
                }
                $xml = $this->xml($zip, implode('/', $parts));
                $rows = [];
                foreach ($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                    $values = ['', '', '', ''];
                    foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                        if ($cell->xpath('./*[local-name()="f"]')) {
                            $this->fail('Formulas are not accepted. Paste the rate values into the workbook before importing.');
                        }
                        if (! preg_match('/^([A-Z]+)[0-9]+$/', (string) $cell['r'], $match)) {
                            $this->fail('A worksheet cell has an invalid address.');
                        }
                        $column = 0;
                        foreach (str_split($match[1]) as $letter) {
                            $column = $column * 26 + ord($letter) - 64;
                        }
                        $valueNodes = $cell->xpath('./*[local-name()="v"]');
                        $value = isset($valueNodes[0]) ? (string) $valueNodes[0] : '';
                        $type = (string) $cell['t'];
                        if ($type === 's') {
                            if (! ctype_digit($value) || ! array_key_exists((int) $value, $strings)) {
                                $this->fail('The workbook has an invalid shared string.');
                            }
                            $value = $strings[(int) $value];
                        } elseif ($type === 'inlineStr') {
                            $value = implode('', array_map(fn ($text) => (string) $text, $cell->xpath('.//*[local-name()="t"]')));
                        } elseif (! in_array($type, ['', 'n', 'str'], true)) {
                            $this->fail('Use text labels and numeric amounts in the workbook.');
                        }
                        if ($column > 4) {
                            if ($value !== '') {
                                $this->fail('Each worksheet must contain only the four rate columns.');
                            }
                        } else {
                            $values[$column - 1] = $value;
                        }
                    }
                    if (count(array_filter($values, fn ($value) => $value !== ''))) {
                        $rows[] = $values;
                    }
                    if (count($rows) > 201) {
                        $this->fail('Use at most 200 rate rows.');
                    }
                }
                if ($rows !== []) {
                    $all = [...$all, ...$this->table($rows)];
                }
                if (count($all) > 200) {
                    $this->fail('Use at most 200 rate rows across the workbook.');
                }
            }

            return $all;
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $path): SimpleXMLElement
    {
        $data = $zip->getFromName($path);
        if ($data === false || stripos($data, '<!DOCTYPE') !== false || stripos($data, '<!ENTITY') !== false) {
            $this->fail('The XLSX workbook contains a missing or unsupported XML part.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($data, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($xml === false) {
            $this->fail('The XLSX workbook contains invalid XML.');
        }

        return $xml;
    }

    private function table(array $rows): array
    {
        $header = array_shift($rows);
        if (is_array($header)) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        }
        if ($header !== PaymentRateCsv::HEADER) {
            $this->fail('Use these four column headings: '.implode(', ', PaymentRateCsv::HEADER).'. Each nonempty XLSX worksheet must start with this header.');
        }

        return $rows;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
