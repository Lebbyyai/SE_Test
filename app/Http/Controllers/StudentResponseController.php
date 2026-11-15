<?php

namespace App\Http\Controllers;

use App\Models\StudentResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentResponseController extends Controller
{
    public function deanIndex(Request $request): Response
    {
        abort_unless($request->user()->role === 'Dean', 403);

        return Inertia::render('dean/responses/Index', [
            'responses' => StudentResponse::query()
                ->whereNotNull('evaluated_at')
                ->select([
                    'id',
                    'family_name',
                    'first_name',
                    'program',
                    'contact_number',
                    'email_address',
                    'id_number',
                    'evaluated_at',
                ])
                ->latest()
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    public function evaluate(Request $request, StudentResponse $studentResponse)
    {
        abort_unless($request->user()->role === 'Administrative Assistant', 403);

        if (! $studentResponse->evaluated_at) {
            $studentResponse->forceFill([
                'evaluated_at' => now(),
                'evaluated_by' => $request->user()->id,
            ])->save();
        }

        return to_route('coordinator.compre-exam.index')->with('success', 'Student response marked as evaluated.');
    }
}
