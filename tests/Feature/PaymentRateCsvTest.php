<?php

use App\Models\PaymentRate;
use App\Models\User;
use Illuminate\Http\UploadedFile;

function rateCsvUpload(string $rows): UploadedFile
{
    return UploadedFile::fake()->createWithContent('rates.csv', "program_level,type,defense_type,amount\n".$rows);
}

function rateImportPayload(string $rows, string $mode = 'apply'): array
{
    return ['program_level' => 'Masteral', 'mode' => $mode, 'file' => rateCsvUpload($rows)];
}

beforeEach(function () {
    $this->withoutVite();
});

test('payment CSV endpoints enforce viewing and editing roles', function () {
    $this->getJson('/dean/payment-rates/export?program_level=Masteral')->assertUnauthorized();
    foreach (['Student', 'Adviser', 'Finance', 'Coordinator', 'Administrative Assistant'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->postJson('/dean/payment-rates/import', rateImportPayload('Masteral,Adviser,Proposal,100'))->assertForbidden();
        $response = $this->get('/dean/payment-rates/export?program_level=Masteral');
        in_array($role, ['Coordinator', 'Administrative Assistant']) ? $response->assertOk() : $response->assertForbidden();
    }
});

test('payment rates export selected program as round trip CSV with decimals', function () {
    PaymentRate::create(['program_level' => 'Masteral', 'type' => 'Adviser', 'defense_type' => 'Proposal', 'amount' => 1234.50]);
    PaymentRate::create(['program_level' => 'Doctorate', 'type' => 'Adviser', 'defense_type' => 'Proposal', 'amount' => 999]);
    $this->actingAs(User::factory()->create(['role' => 'Dean']));
    $export = $this->get('/dean/payment-rates/export?program_level=Masteral')->assertOk();
    $text = $export->streamedContent();
    expect($text)->toContain('program_level,type,defense_type,amount')->toContain('Masteral,Adviser,Proposal,1234.50')->not->toContain('Doctorate');
    $this->postJson('/dean/payment-rates/import', ['program_level' => 'Masteral', 'mode' => 'preview', 'file' => UploadedFile::fake()->createWithContent('export.csv', $text)])->assertOk()->assertJsonPath('rows.0.action', 'Unchanged');
});

test('preview shows additions changes and zero without changing the database', function () {
    $rate = PaymentRate::create(['program_level' => 'Masteral', 'type' => 'Adviser', 'defense_type' => 'Proposal', 'amount' => 50]);
    $this->actingAs(User::factory()->create(['role' => 'Dean']));
    $this->postJson('/dean/payment-rates/import', rateImportPayload("Masteral,Adviser,Proposal,0\nMasteral,External Panel Member,Final,400.25", 'preview'))->assertOk()->assertJsonPath('rows.0.previous_amount', 50)->assertJsonPath('rows.0.action', 'Update')->assertJsonPath('rows.1.action', 'Add');
    expect($rate->fresh()->amount)->toBe(50.0);
    $this->assertDatabaseCount('payment_rates', 1);
});

test('apply updates and adds rates while preserving omitted rows and other programs', function () {
    foreach (['Masteral', 'Doctorate'] as $level) {
        PaymentRate::create(['program_level' => $level, 'type' => 'Adviser', 'defense_type' => 'Proposal', 'amount' => 50]);
    }
    $this->actingAs(User::factory()->create(['role' => 'Super Admin']));
    $this->postJson('/dean/payment-rates/import', rateImportPayload("Masteral,Adviser,Proposal,0\nMasteral,External Panel Chair,Final,250.99"))->assertOk()->assertJsonPath('count', 2);
    $this->assertDatabaseHas('payment_rates', ['program_level' => 'Masteral', 'type' => 'Adviser', 'amount' => 0]);
    $this->assertDatabaseHas('payment_rates', ['program_level' => 'Doctorate', 'type' => 'Adviser', 'amount' => 50]);
    $this->assertDatabaseCount('payment_rates', 3);
});

test('invalid files do not partially apply valid rows', function (string $bad) {
    $rate = PaymentRate::create(['program_level' => 'Masteral', 'type' => 'Adviser', 'defense_type' => 'Proposal', 'amount' => 50]);
    $this->actingAs(User::factory()->create(['role' => 'Dean']));
    $this->postJson('/dean/payment-rates/import', rateImportPayload("Masteral,Adviser,Proposal,100\n".$bad))->assertUnprocessable()->assertJsonValidationErrors('file');
    expect($rate->fresh()->amount)->toBe(50.0);
    $this->assertDatabaseCount('payment_rates', 1);
})->with([
    'negative' => 'Masteral,Adviser,Final,-1',
    'precision' => 'Masteral,Adviser,Final,1.234',
    'overflow' => 'Masteral,Adviser,Final,100000000',
    'formula' => 'Masteral,Adviser,Final,=2+3',
    'unknown type' => 'Masteral,Unknown,Final,100',
    'unknown stage' => 'Masteral,Adviser,Unknown,100',
    'wrong program' => 'Doctorate,Adviser,Final,100',
    'duplicate' => 'Masteral,Adviser,Proposal,200',
    'missing column' => 'Masteral,Adviser,Final',
]);

test('import rejects empty wrong headers oversized and non CSV files', function () {
    $this->actingAs(User::factory()->create(['role' => 'Dean']));
    foreach (['', "type,amount\nAdviser,100"] as $text) {
        $this->postJson('/dean/payment-rates/import', ['program_level' => 'Masteral', 'mode' => 'apply', 'file' => UploadedFile::fake()->createWithContent('rates.csv', $text)])->assertUnprocessable();
    }
    $this->postJson('/dean/payment-rates/import', ['program_level' => 'Masteral', 'mode' => 'apply', 'file' => UploadedFile::fake()->create('rates.csv', 257, 'text/csv')])->assertUnprocessable();
    $this->postJson('/dean/payment-rates/import', ['program_level' => 'Masteral', 'mode' => 'apply', 'file' => UploadedFile::fake()->createWithContent('rates.php', 'not csv')])->assertUnprocessable();
    $this->getJson('/dean/payment-rates/export?program_level=Unknown')->assertUnprocessable();
});

test('empty programs export a usable template and blank amounts preserve existing rates', function () {
    $this->actingAs(User::factory()->create(['role' => 'Dean']));
    $csv = $this->get('/dean/payment-rates/export?program_level=Masteral')->assertOk()->streamedContent();
    expect($csv)->toContain('Masteral,Adviser,Proposal,')->toContain('External Panel Member');
    PaymentRate::create(['program_level' => 'Masteral', 'type' => 'Adviser', 'defense_type' => 'Proposal', 'amount' => 50]);
    $this->postJson('/dean/payment-rates/import', rateImportPayload("Masteral,Adviser,Proposal,\nMasteral,Adviser,Final,100"))->assertOk()->assertJsonPath('count', 1);
    $this->assertDatabaseHas('payment_rates', ['type' => 'Adviser', 'defense_type' => 'Proposal', 'amount' => 50]);
});
