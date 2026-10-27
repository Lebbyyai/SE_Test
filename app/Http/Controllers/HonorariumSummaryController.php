<?php

namespace App\Http\Controllers;

use App\Models\DefenseRequest;
use App\Models\ProgramRecord;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HonorariumSummaryController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(in_array($request->user()?->role, ['Administrative Assistant', 'Dean', 'Super Admin']), 403);
        // Add this line at the top:
        \Log::info('HonorariumSummaryController@index CALLED');

        // Get all programs
        $programs = ProgramRecord::all();

        // Get all defense requests with payments
        $defenseRequests = DefenseRequest::with(['honorariumPayments', 'user'])->get();

        // Group payments by program
        $records = [];
        foreach ($programs as $program) {
            $programPayments = [];
            foreach ($defenseRequests as $dr) {
                if ($dr->program === $program->program) {
                    if ($dr->honorariumPayments) {
                        foreach ($dr->honorariumPayments as $payment) {
                            $programPayments[] = [
                                'id' => $payment->id,
                                'school_year' => $dr->created_at ? $dr->created_at->format('Y').'-'.($dr->created_at->format('Y') + 1) : '',
                                'payment_date' => $payment->payment_date ?? '',
                                'defense_status' => $dr->workflow_state_display ?? $dr->workflow_state ?? '',
                                'amount' => $payment->amount ?? '',
                                'panelist_name' => $payment->panelist->name ?? '',
                                'role' => $payment->role ?? '',
                            ];
                        }
                    }
                }
            }
            $records[] = [
                'id' => $program->id,
                'name' => $program->name,
                'program' => $program->program,
                'category' => $program->category,
                'date_edited' => $program->date_edited,
                'payments' => $programPayments,
            ];
        }

        return Inertia::render('honorarium/honorarium-summary/Index', [
            'records' => $records,
        ]);
    }

    public function download(ProgramRecord $record)
    {
        abort_unless(in_array(auth()->user()?->role, ['Administrative Assistant', 'Dean', 'Super Admin']), 403);
        // Get all defense requests for this program
        $defenseRequests = DefenseRequest::where('program', $record->program)->get();

        // Collect all honorarium payments for these defense requests
        $payments = [];
        foreach ($defenseRequests as $dr) {
            foreach ($dr->honorariumPayments as $payment) {
                $payments[] = [
                    'school_year' => $dr->created_at ? $dr->created_at->format('Y').'-'.($dr->created_at->format('Y') + 1) : '',
                    'payment_date' => $payment->payment_date ? $payment->payment_date : '',
                    'defense_status' => $dr->workflow_state_display ?? $dr->workflow_state,
                    'amount' => $payment->amount,
                    'panelist_name' => $payment->panelist?->name ?? '',
                    'role' => $payment->role,
                ];
            }
        }

        $filename = $record->name.'_payments.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($payments) {
            $handle = fopen('php://output', 'w');
            // CSV header
            fputcsv($handle, ['School Year', 'Payment Date', 'Defense Status', 'Panelist', 'Role', 'Amount']);

            foreach ($payments as $payment) {
                fputcsv($handle, [
                    $payment['school_year'],
                    $payment['payment_date'],
                    $payment['defense_status'],
                    $payment['panelist_name'],
                    $payment['role'],
                    $payment['amount'],
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
