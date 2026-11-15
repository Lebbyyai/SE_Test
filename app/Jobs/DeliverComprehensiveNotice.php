<?php

namespace App\Jobs;

use App\Models\ComprehensiveWorkflow;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class DeliverComprehensiveNotice implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $d = DB::table('comprehensive_deliveries')->find($this->deliveryId);
        if (! $d || $d->status !== 'queued') {
            return;
        }
        try {
            $user = User::findOrFail($d->user_id);
            if ($d->channel === 'email') {
                if (! config('comprehensive.email_enabled') || in_array(config('mail.default'), ['log', 'array'], true)) {
                    DB::table('comprehensive_deliveries')->where('id', $d->id)->update(['status' => 'disabled', 'detail' => 'Email delivery is not configured.', 'updated_at' => now()]);

                    return;
                }
                Mail::raw($d->message."\n\n".url('/comprehensive-workflow'), fn ($m) => $m->to($user->email)->subject('Comprehensive exam progress update'));
            } else {
                $w = ComprehensiveWorkflow::with('application')->findOrFail($d->workflow_id);
                $phone = preg_replace('/[^0-9+]/', '', $w->application->contact_number ?? '');
                if (str_starts_with($phone, '09')) {
                    $phone = '+63'.substr($phone, 1);
                }
                if (! config('comprehensive.sms_enabled') || ! $w->sms_opt_in || ! config('comprehensive.twilio_sid') || ! config('comprehensive.twilio_token') || ! config('comprehensive.twilio_from') || ! preg_match('/^\+639\d{9}$/', $phone)) {
                    DB::table('comprehensive_deliveries')->where('id', $d->id)->update(['status' => 'disabled', 'detail' => 'SMS requires consent, a valid Philippine mobile number, and a configured provider.', 'updated_at' => now()]);

                    return;
                }
                Http::timeout(15)->asForm()->withBasicAuth(config('comprehensive.twilio_sid'), config('comprehensive.twilio_token'))->post('https://api.twilio.com/2010-04-01/Accounts/'.config('comprehensive.twilio_sid').'/Messages.json', ['To' => $phone, 'From' => config('comprehensive.twilio_from'), 'Body' => $d->message])->throw();
            }
            DB::table('comprehensive_deliveries')->where('id', $d->id)->update(['status' => 'sent', 'detail' => 'Accepted by the delivery provider.', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('comprehensive_deliveries')->where('id', $d->id)->update(['status' => 'failed', 'detail' => 'Delivery failed. Ask the administrator to check the delivery provider.', 'updated_at' => now()]);
            report($e);
        }
    }
}
