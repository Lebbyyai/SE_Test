<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;
use ZipArchive;

class DefenseRegisterExport
{
    public function matrix(array $items): array
    {
        $dates = collect($items)->pluck('date')->unique()->sort()->values()->all();

        $grid = [];
        foreach ($items as $item) {
            $slot = $item['start'] ? $item['start'].'–'.($item['end'] ?: 'End unspecified') : 'Time unspecified';
            $people = collect($item['participants']);
            $prefix = '#'.$item['id'].' ';
            $warning = empty($item['roster_error']) ? '' : "\nCommittee needs review: ".$item['roster_error'];
            $outcome = $item['academic_outcome'] ?? null;
            if ($outcome) {
                $warning .= "\nAcademic: ".$outcome['completion'].' / '.$outcome['result'].' · '.$outcome['subject'].(! empty($outcome['next_stage']) ? ' · Next stage: '.$outcome['next_stage'] : '').($outcome['current'] ? '' : ' · Needs reconfirmation');
            }
            $lines = [
                'Defense' => $prefix.$item['student'].' — '.$item['defense_type']."\n".$item['title']."\nAdviser: ".($item['adviser'] ?: 'Unassigned')."\n".$slot.' · '.($item['mode'] ?: 'Mode unspecified').' · '.($item['venue'] ?: 'Venue unspecified').$warning,
                'Chair / internal panel' => $people->where('affiliation', 'internal')->map(fn ($p) => $p['recipient_name'].' ('.$p['recipient_role'].')')->implode(', '),
                'External panel' => $people->where('affiliation', 'external')->map(fn ($p) => $p['recipient_name'].' ('.$p['recipient_role'].')')->implode(', '),
                'Unclassified panel' => $people->where('recipient_role', '!=', 'Adviser')->filter(fn ($p) => ! $p['affiliation'])->map(fn ($p) => $p['recipient_name'].' ('.$p['recipient_role'].')')->implode(', '),
            ];
            foreach ($lines as $kind => $text) {
                if ($text !== '') {
                    $grid[$slot][$item['date']][$kind][] = $kind === 'Defense' ? $text : $prefix.$text;
                }
            }
        }

        ksort($grid, SORT_STRING);

        return ['dates' => $dates, 'slots' => array_keys($grid), 'grid' => $grid, 'kinds' => ['Defense', 'Chair / internal panel', 'External panel', 'Unclassified panel']];
    }

    public function xlsx(array $data): string
    {
        $matrix = $this->matrix($data['items']);
        $schedule = [['Graduate School Defense Schedule'], [$data['from'].' to '.$data['to'].' (Philippine time)'], ['Scheduled entries do not establish attendance or payment.'], ['Time / detail', ...array_map(fn ($date) => CarbonImmutable::parse($date), $matrix['dates'])]];
        foreach ($matrix['slots'] as $slot) {
            foreach ($matrix['kinds'] as $kind) {
                $schedule[] = [$slot."\n".$kind, ...array_map(fn ($date) => implode("\n\n", $matrix['grid'][$slot][$date][$kind] ?? []), $matrix['dates'])];
            }
        }
        $details = [['Request ID', 'Date', 'Start', 'End', 'Student', 'School ID', 'Program', 'Title', 'Defense stage', 'Mode', 'Venue', 'Adviser', 'Service status', 'Completed on', 'Defense completion', 'Academic result', 'Research subject', 'Next stage', 'Academic outcome current']];
        $participants = [['Request ID', 'Defense date', 'Student', 'Recipient', 'Role', 'Affiliation', 'Service attendance', 'Completed on']];
        foreach ($data['items'] as $item) {
            $date = CarbonImmutable::parse($item['date']);
            $outcome = $item['academic_outcome'] ?? null;
            $details[] = [$item['id'], $date, $item['start'], $item['end'], $item['student'], $item['school_id'], $item['program'], $item['title'], $item['defense_type'], $item['mode'], $item['venue'], $item['adviser'], $item['service_status'], $item['completed_on'] ? CarbonImmutable::parse($item['completed_on']) : null, $outcome['completion'] ?? '', $outcome['result'] ?? '', $outcome['subject'] ?? '', $outcome['next_stage'] ?? '', $outcome ? ($outcome['current'] ? 'Yes' : 'Needs reconfirmation') : 'Not recorded'];
            foreach ($item['participants'] as $person) {
                $participants[] = [$item['id'], $date, $item['student'], $person['recipient_name'], $person['recipient_role'], $person['recipient_role'] === 'Adviser' ? 'Not applicable' : ($person['affiliation'] ?? 'Unclassified'), $person['attendance'], $item['completed_on'] ? CarbonImmutable::parse($item['completed_on']) : null];
            }
        }
        $sheets = ['Schedule' => $schedule, 'Defense details' => $details, 'Recipient service' => $participants];
        $path = tempnam(sys_get_temp_dir(), 'defense-schedule-');
        if (! $path) {
            throw new RuntimeException('Could not allocate an export file.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('Could not create the schedule workbook.');
        }
        try {
            $ns = 'http://schemas.openxmlformats.org/';
            $content = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="'.$ns.'package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $book = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="'.$ns.'spreadsheetml/2006/main" xmlns:r="'.$ns.'officeDocument/2006/relationships"><sheets>';
            $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="'.$ns.'package/2006/relationships">';
            $index = 1;
            foreach ($sheets as $name => $rows) {
                $content .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $book .= '<sheet name="'.$this->escape($name).'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
                $rels .= '<Relationship Id="rId'.$index.'" Type="'.$ns.'officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
                $zip->addFromString('xl/worksheets/sheet'.$index.'.xml', $this->sheet($rows, $index === 1));
                $index++;
            }
            $rels .= '<Relationship Id="rId4" Type="'.$ns.'officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
            $zip->addFromString('[Content_Types].xml', $content.'</Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="'.$ns.'package/2006/relationships"><Relationship Id="rId1" Type="'.$ns.'officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', $book.'</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
            $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="'.$ns.'spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="mmm d, yyyy"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF333333"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf><xf numFmtId="164" fontId="1" fillId="2" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
            $zip->close();
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($path);
            throw $exception;
        }

        return $path;
    }

    private function sheet(array $rows, bool $matrix): string
    {
        $width = max(2, max(array_map('count', $rows)));
        $header = $matrix ? 4 : 1;
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$header.'" xSplit="1" topLeftCell="B'.($header + 1).'" activePane="bottomRight" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="22" customWidth="1"/><col min="2" max="'.$width.'" width="'.($matrix ? 48 : 26).'" customWidth="1"/></cols><sheetData>';
        foreach ($rows as $offset => $values) {
            $row = $offset + 1;
            $lines = 1;
            foreach ($values as $column => $value) {
                if (is_string($value)) {
                    $lines = max($lines, array_sum(array_map(fn ($line) => max(1, (int) ceil(mb_strlen($line) / ($column === 0 ? 22 : ($matrix ? 48 : 26)))), explode("\n", $value))));
                }
            }
            $height = min(409, max(24, $lines * 15 + 8));
            $xml .= '<row r="'.$row.'" ht="'.$height.'" customHeight="1">';
            foreach ($values as $column => $value) {
                $n = $column + 1;
                $letters = '';
                while ($n) {
                    $letters = chr(65 + (($n - 1) % 26)).$letters;
                    $n = intdiv($n - 1, 26);
                }
                $reference = $letters.$row;
                $style = $row === $header ? 2 : 0;
                if ($value instanceof CarbonImmutable) {
                    $serial = CarbonImmutable::parse($value->toDateString(), 'UTC')->timestamp / 86400 + 25569;
                    $xml .= '<c r="'.$reference.'" s="'.($style === 2 ? 3 : 1).'"><v>'.$serial.'</v></c>';
                } elseif (is_int($value) || is_float($value)) {
                    $xml .= '<c r="'.$reference.'" s="'.$style.'"><v>'.$value.'</v></c>';
                } else {
                    $xml .= '<c r="'.$reference.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->escape((string) $value).'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
