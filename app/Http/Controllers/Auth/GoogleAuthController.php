<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountProfileName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class GoogleAuthController extends Controller
{
    public static function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    public function redirect(): RedirectResponse
    {
        if (! self::configured()) {
            return $this->failure('Google sign-in is currently unavailable. Please use your account credentials.');
        }

        // Socialite stores OAuth state in the session and validates it on callback.
        return Socialite::driver('google')->with([
            'hd' => config('services.google.allowed_domain'),
            'prompt' => 'select_account',
        ])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! self::configured()) {
            return $this->failure('Google sign-in is currently unavailable. Please use your account credentials.');
        }

        if ($request->has('error')) {
            $request->session()->forget('state');

            return $this->failure('Google sign-in was cancelled or denied. You can try again.');
        }

        try {
            // Do not use stateless(): state validation protects the login session.
            $identity = Socialite::driver('google')->user();
            $email = strtolower(trim((string) $identity->getEmail()));
            $googleId = (string) $identity->getId();
            $raw = $identity->getRaw();
            $domain = config('services.google.allowed_domain');

            // The hd request parameter is only a hint. Check Google's actual
            // hosted-domain and verified-email claims before linking an account.
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)
                || ! str_ends_with($email, '@'.$domain)
                || ($raw['email_verified'] ?? false) !== true
                || strtolower((string) ($raw['hd'] ?? '')) !== $domain
                || $googleId === '') {
                return $this->failure('Please sign in with a verified @uic.edu.ph Google account.');
            }

            $user = DB::transaction(function () use ($email, $googleId, $raw) {
                $matches = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->limit(2)->get();
                if ($matches->count() !== 1 || $matches->first()->is_demo_account) {
                    return null;
                }
                $user = $matches->first();
                if (($user->google_id && $user->google_id !== $googleId)
                    || User::where('google_id', $googleId)->whereKeyNot($user->id)->exists()) {
                    return null;
                }

                $names = app(AccountProfileName::class);
                $names->fromGoogle($user, $raw);
                $names->fromAdviserRegistration($user);

                // Preserve the account's password, role, and academic assignments.
                $user->forceFill([
                    'google_id' => $googleId,
                    'google_verified_at' => $user->google_verified_at ?? now(),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                return $user;
            });

            if (! $user) {
                return $this->failure('No matching account is available for this Google sign-in. Please contact your system administrator.');
            }

            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        } catch (InvalidStateException $exception) {
            return $this->failure('Your Google sign-in session expired. Please try again from the login page.');
        } catch (Throwable $exception) {
            // Do not log tokens, authorization codes, or provider response bodies.
            Log::warning('Google sign-in failed.', ['exception_type' => $exception::class]);

            return $this->failure('Google sign-in could not be completed. Please try again or use your account credentials.');
        }
    }

    private function failure(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['google' => $message]);
    }
}
