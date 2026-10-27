<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;

function rateXlsx(array $sheets, bool $formula = false, bool $shared = false): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'rate-xlsx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
    $wb = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $strings = [];
    foreach ($sheets as $i => $records) {
        $number = $i + 1;
        $wb .= '<sheet name="Rates'.$number.'" sheetId="'.$number.'" r:id="rId'.$number.'"/>';
        $rels .= '<Relationship Id="rId'.$number.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$number.'.xml"/>';
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ([['program_level', 'type', 'defense_type', 'amount'], ...$records] as $r => $row) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach ($row as $c => $value) {
                $address = chr(65 + $c).($r + 1);
                if (is_numeric($value)) {
                    $xml .= '<c r="'.$address.'"><v>'.$value.'</v>'.($formula && $r > 0 && $c === 3 ? '<f>1+2</f>' : '').'</c>';
                } elseif ($shared) {
                    $strings[] = $value;
                    $xml .= '<c r="'.$address.'" t="s"><v>'.(count($strings) - 1).'</v></c>';
                } else {
                    $xml .= '<c r="'.$address.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet'.$number.'.xml', $xml.'</sheetData></worksheet>');
    }
    $zip->addFromString('xl/workbook.xml', $wb.'</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels.'</Relationships>');
    if ($shared) {
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($value) => '<si><t>'.htmlspecialchars($value, ENT_XML1).'</t></si>', $strings)).'</sst>');
    }
    $zip->close();
    $file = UploadedFile::fake()->createWithContent('rates.xlsx', file_get_contents($path));
    unlink($path);

    return $file;
}

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create(['role' => 'Dean']));
});

test('JSON lists and rates wrappers detect both programs preview and apply zero', function (bool $wrap) {
    $rows = [
        ['program_level' => 'Masteral', 'type' => 'Adviser', 'defense_type' => 'Final', 'amount' => 2000],
        ['program_level' => 'Doctorate', 'type' => 'REC Fee', 'defense_type' => 'Final', 'amount' => 0],
    ];
    $file = fn () => UploadedFile::fake()->createWithContent('rates.json', json_encode($wrap ? ['rates' => $rows] : $rows));
    $this->postJson('/dean/payment-rates/import', ['file' => $file(), 'mode' => 'preview'])->assertOk()->assertJsonCount(2, 'rows');
    $this->assertDatabaseCount('payment_rates', 0);
    $this->postJson('/dean/payment-rates/import', ['file' => $file(), 'mode' => 'apply'])->assertOk();
    $this->assertDatabaseHas('payment_rates', ['program_level' => 'Masteral', 'amount' => 2000]);
    $this->assertDatabaseHas('payment_rates', ['program_level' => 'Doctorate', 'amount' => 0]);
})->with([false, true]);

test('XLSX inline and shared strings read multiple sheets and keep program identities', function (bool $shared) {
    $sheets = [[['Masteral', 'Adviser', 'Final', 2000]], [['Doctorate', 'Adviser', 'Final', 1000]]];
    $this->postJson('/dean/payment-rates/import', ['file' => rateXlsx($sheets, false, $shared), 'mode' => 'preview'])->assertOk()->assertJsonCount(2, 'rows')->assertJsonPath('rows.1.program_level', 'Doctorate');
    $this->postJson('/dean/payment-rates/import', ['file' => rateXlsx($sheets, false, $shared), 'mode' => 'apply'])->assertOk();
    $this->assertDatabaseCount('payment_rates', 2);
})->with([false, true]);

test('JSON invalid structures values duplicates and syntax cannot write any rates', function (string $content) {
    $this->postJson('/dean/payment-rates/import', ['file' => UploadedFile::fake()->createWithContent('bad.json', $content), 'mode' => 'apply'])->assertUnprocessable();
    $this->assertDatabaseCount('payment_rates', 0);
})->with([
    '{broken',
    '{"rates":"not a list"}',
    '[{"program_level":"Masteral"}]',
    '[{"program_level":"Masteral","type":"Adviser","defense_type":"Final","amount":true}]',
    '[{"program_level":"Masteral","type":"Adviser","defense_type":"Final","amount":{}}]',
    '[{"program_level":"Masteral","type":"Adviser","defense_type":"Final","amount":-1}]',
    '[{"program_level":"Masteral","type":"Adviser","defense_type":"Final","amount":1},{"program_level":"Masteral","type":"Adviser","defense_type":"Final","amount":2}]',
]);

test('XLSX formulas duplicate keys across sheets and malformed workbooks cannot write rates', function () {
    $rows = [['Masteral', 'Adviser', 'Final', 2000]];
    foreach ([rateXlsx([$rows], true), rateXlsx([$rows, $rows]), UploadedFile::fake()->createWithContent('bad.xlsx', 'not a workbook')] as $file) {
        $this->postJson('/dean/payment-rates/import', ['file' => $file, 'mode' => 'apply'])->assertUnprocessable();
    }
    $this->assertDatabaseCount('payment_rates', 0);
});

test('new formats retain authorization for preview and apply', function () {
    $this->actingAs(User::factory()->create(['role' => 'Coordinator']));
    foreach (['preview', 'apply'] as $mode) {
        $this->postJson('/dean/payment-rates/import', ['file' => rateXlsx([[['Masteral', 'Adviser', 'Final', 2000]]]), 'mode' => $mode])->assertForbidden();
    }
});

test('XLSX rejects entity declarations and oversized expanded zip parts', function () {
    foreach ([false, true] as $oversized) {
        $path = tempnam(sys_get_temp_dir(), 'rate-unsafe');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', $oversized ? str_repeat('x', 4194305) : '<!DOCTYPE workbook [<!ENTITY sample SYSTEM "file:///nonexistent">]><workbook/>');
        $zip->close();
        $file = UploadedFile::fake()->createWithContent('unsafe.xlsx', file_get_contents($path));
        unlink($path);
        $this->postJson('/dean/payment-rates/import', ['file' => $file, 'mode' => 'apply'])->assertUnprocessable();
    }
    $this->assertDatabaseCount('payment_rates', 0);
});
