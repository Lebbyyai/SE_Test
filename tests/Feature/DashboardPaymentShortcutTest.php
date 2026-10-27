<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('legacy dashboard payment link redirects students to the working payment page', function () {
    $this->withoutVite();
    $student = User::factory()->create(['role' => 'Student']);
    $this->actingAs($student)->get('/payments')->assertRedirect('/payment');
    $this->get('/payment')->assertOk()->assertInertia(fn (Assert $page) => $page->component('payment/Index')->where('canSubmit', false)->where('payment', null));
});

test('dashboard payment shortcut still requires authentication', function () {
    $this->get('/payments')->assertRedirect('/login');
});
