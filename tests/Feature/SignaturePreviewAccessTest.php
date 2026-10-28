<?php

use App\Models\User;
use App\Models\UserSignature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->withoutVite();
});

test('saved signature preview is available to its owner without a public storage link', function () {
    $owner = User::factory()->create(['role' => 'Adviser']);
    $response = $this->actingAs($owner)->post('/api/signatures', [
        'image' => UploadedFile::fake()->image('signature.png', 200, 80),
    ], ['Accept' => 'application/json'])->assertOk();
    $this->get('/api/signatures/'.$response->json('id').'/image')->assertOk()
        ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('another account cannot preview a signature even when it knows the identifier', function () {
    $owner = User::factory()->create();
    $signature = UserSignature::create(['user_id' => $owner->id, 'image_path' => 'signatures/owned.png', 'active' => true]);
    Storage::disk('public')->put($signature->image_path, 'Private signature fixture');
    $this->actingAs(User::factory()->create(['role' => 'Dean']))
        ->get('/api/signatures/'.$signature->id.'/image')->assertForbidden();
});

test('signature preview requires login', function () {
    $signature = UserSignature::create(['user_id' => User::factory()->create()->id, 'image_path' => 'signatures/owned.png']);
    $this->get('/api/signatures/'.$signature->id.'/image')->assertRedirect('/login');
});

test('missing signature image returns not found without changing active status', function () {
    $owner = User::factory()->create();
    $signature = UserSignature::create(['user_id' => $owner->id, 'image_path' => 'signatures/missing.png', 'active' => true]);
    $this->actingAs($owner)->get('/api/signatures/'.$signature->id.'/image')->assertNotFound();
    expect((bool) $signature->fresh()->active)->toBeTrue();
});
