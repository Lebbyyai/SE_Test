<?php

use App\Models\ProgramRecord;
use App\Services\StudentProgressService;
use Inertia\Testing\AssertableInertia as Assert;

it('offers every published graduate curriculum without requiring existing students', function () {
    $programs = collect(config('graduate_programs'));
    expect($programs)->toHaveCount(35)
        ->and($programs->where('category', 'Doctorate'))->toHaveCount(10)
        ->and($programs->where('category', 'Masters'))->toHaveCount(25)
        ->and($programs->pluck('code')->unique())->toHaveCount(35);

    $this->withoutVite()->get('/register')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('auth/register')->has('programs', 35));

    $catalog = collect(app(StudentProgressService::class)->programCatalog());
    expect($catalog->pluck('code')->all())->toContain('MIT', 'MIS', 'MBA-T', 'MBA-NT', 'MBA-HP', 'MC', 'MPM-PM', 'MSMT');

    ProgramRecord::forceCreate(['name' => 'Legacy MIT label', 'program' => 'MIT', 'category' => 'Masters', 'date_edited' => '2026-11-24', 'time_last_opened' => '00:00:00']);
    ProgramRecord::forceCreate(['name' => 'Legacy program', 'program' => 'LEGACY', 'category' => 'Masters', 'date_edited' => '2026-11-24', 'time_last_opened' => '00:00:00']);
    $catalog = collect(app(StudentProgressService::class)->programCatalog());
    expect($catalog->where('code', 'MIT'))->toHaveCount(1)
        ->and($catalog->firstWhere('code', 'MIT')['name'])->toBe('Master in Information Technology')
        ->and($catalog->pluck('code')->all())->toContain('LEGACY');
});
