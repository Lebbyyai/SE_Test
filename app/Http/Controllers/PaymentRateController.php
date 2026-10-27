<?php

namespace App\Http\Controllers;

use App\Models\PaymentRate;
use App\Services\PaymentRateCsv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PaymentRateController extends Controller
{
    public function index()
    {
        abort_unless(in_array(auth()->user()?->role, ['Dean', 'Coordinator', 'Administrative Assistant', 'Super Admin']), 403);
        $rates = PaymentRate::all();

        return Inertia::render('dean/payment-rates/Index', [
            'rates' => $rates,
        ]);
    }

    public function update(Request $request)
    {
        abort_unless(in_array($request->user()?->role, ['Dean', 'Super Admin']), 403);
        // Accept payload as { rates: [...] } or { rows: [...] } or raw array body
        $payload = $request->input('rates');
        if ($payload === null) {
            $payload = $request->input('rows', null);
        }
        if ($payload === null) {
            $payload = $request->input('items', null);
        }
        if ($payload === null) {
            $all = $request->all();
            if (is_array($all) && isset($all[0]) && is_array($all[0])) {
                $payload = $all;
            }
        }

        $validated = validator(
            ['rates' => $payload],
            [
                'rates' => ['required', 'array', 'min:1'],
                'rates.*.program_level' => ['required', 'string'],
                'rates.*.type' => ['required', 'string'],
                'rates.*.defense_type' => ['required', 'string'],
                'rates.*.amount' => ['required', 'numeric', 'min:0'],
            ]
        )->validate();

        $rates = $validated['rates'];

        DB::transaction(function () use ($rates) {
            // Upsert each incoming row ONLY; do not delete others
            foreach ($rates as $r) {
                PaymentRate::updateOrCreate(
                    [
                        'program_level' => $r['program_level'],
                        'type' => $r['type'],
                        'defense_type' => $r['defense_type'],
                    ],
                    [
                        'amount' => (float) $r['amount'],
                    ]
                );
            }
        });

        // Return "success" so the frontend refetches and updates without manual refresh
        return response()->json([
            'success' => true,
            // optionally include latest rates if you want to skip the follow-up fetch:
            // 'rates' => PaymentRate::all()->map(fn($r) => [
            //     'program_level' => $r->program_level,
            //     'type' => $r->type,
            //     'defense_type' => $r->defense_type,
            //     'amount' => (float) $r->amount,
            // ])->values(),
        ]);
    }

    public function export(Request $request)
    {
        abort_unless(in_array($request->user()?->role, ['Dean', 'Coordinator', 'Administrative Assistant', 'Super Admin']), 403);
        $validated = $request->validate(['program_level' => ['required', Rule::in(PaymentRateCsv::LEVELS)]]);
        $level = $validated['program_level'];
        $rates = PaymentRate::where('program_level', $level)->orderBy('type')->orderBy('defense_type')->get();

        foreach ($rates as $rate) {
            if (! in_array($rate->type, PaymentRateCsv::TYPES, true) || ! in_array($rate->defense_type, PaymentRateCsv::STAGES, true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['rates' => 'A saved rate has an unsupported label. Correct its type or defense stage before exporting.']);
            }
        }
        $types = array_values(array_filter(PaymentRateCsv::TYPES, fn ($type) => $type !== 'Panel Member' || $rates->contains('type', $type)));

        return response()->streamDownload(function () use ($rates, $types, $level) {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, PaymentRateCsv::HEADER, ',', '"', '');
            foreach ($types as $type) {
                foreach (PaymentRateCsv::STAGES as $stage) {
                    $rate = $rates->first(fn ($rate) => $rate->type === $type && $rate->defense_type === $stage);
                    fputcsv($handle, [$level, $type, $stage, $rate ? number_format($rate->amount, 2, '.', '') : ''], ',', '"', '');
                }
            }
            fclose($handle);
        }, 'payment-rates-'.strtolower($level).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function import(Request $request, PaymentRateCsv $csv)
    {
        abort_unless(in_array($request->user()?->role, ['Dean', 'Super Admin']), 403);
        $validated = $request->validate([
            'program_level' => ['required', Rule::in(PaymentRateCsv::LEVELS)],
            'file' => ['required', 'file', 'max:256', 'extensions:csv', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'],
            'mode' => ['required', Rule::in(['preview', 'apply'])],
        ]);
        $rows = $csv->parse($request->file('file'), $validated['program_level']);
        if ($validated['mode'] === 'preview') {
            return response()->json(['rows' => $csv->preview($rows)]);
        }
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                PaymentRate::updateOrCreate(collect($row)->except('amount')->all(), ['amount' => $row['amount']]);
            }
        });

        return response()->json(['success' => true, 'count' => count($rows), 'rates' => PaymentRate::all()]);
    }

    public function data()
    {
        abort_unless(auth()->check(), 401);
        $rates = PaymentRate::all()->map(function ($r) {
            return [
                'program_level' => $r->program_level,
                'type' => $r->type,
                'defense_type' => $r->defense_type,
                'amount' => (float) $r->amount, // ensure numeric on client
            ];
        })->values();

        return response()->json([
            'rates' => $rates,
        ]);
    }
}
