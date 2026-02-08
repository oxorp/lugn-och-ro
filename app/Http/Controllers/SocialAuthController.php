<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function redirect(Request $request): mixed
    {
        if ($request->has('redirect')) {
            session(['url.intended' => $request->query('redirect')]);
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(): mixed
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect('/login')->with('error', 'Google-inloggningen misslyckades. Försök igen.');
        }

        $user = User::where('email', $googleUser->getEmail())->first();

        if ($user) {
            if (! $user->google_id) {
                $user->update([
                    'google_id' => $googleUser->getId(),
                    'avatar_url' => $googleUser->getAvatar(),
                ]);
            }
        } else {
            $user = User::create([
                'email' => $googleUser->getEmail(),
                'name' => $googleUser->getName(),
                'google_id' => $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar(),
                'provider' => 'google',
                'email_verified_at' => now(),
            ]);
        }

        // Claim guest reports
        Report::where('guest_email', $user->email)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);

        Auth::login($user, remember: true);

        return redirect()->intended('/');
    }
}
