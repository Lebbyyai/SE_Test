<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PanelistHonorariumSpecController extends Controller
{
    public function index()
    {
        abort_unless(in_array(auth()->user()?->role, ['Coordinator', 'Administrative Assistant', 'Dean', 'Super Admin'], true), 403);

        return redirect()->route('dean.payment-rates.index');
    }

    public function update(Request $request)
    {
        abort_unless(in_array($request->user()?->role, ['Dean', 'Super Admin'], true), 403);

        return response()->json(['error' => 'Use the Payment Rates page to update program-specific honorarium rates.'], 410);
    }
}
