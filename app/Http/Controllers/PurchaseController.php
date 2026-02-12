<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Services\ReportGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function __construct(
        private ReportGenerationService $generator,
    ) {}

    public function storePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => 'required|array',
            'preferences.priorities' => 'nullable|array',
            'preferences.priorities.*' => 'string',
            'preferences.walking_distance_minutes' => 'nullable|integer|min:1|max:60',
            'preferences.has_car' => 'nullable|boolean',
            'lat' => 'required|numeric|min:55|max:69',
            'lng' => 'required|numeric|min:11|max:25',
        ]);

        session([
            'purchase.preferences' => $validated['preferences'],
            'purchase.lat' => $validated['lat'],
            'purchase.lng' => $validated['lng'],
        ]);

        return response()->json(['status' => 'ok']);
    }

    public function show(float $lat, float $lng): Response
    {
        abort_unless($lat >= 55 && $lat <= 69 && $lng >= 11 && $lng <= 25, 404);

        $deso = DB::selectOne('
            SELECT d.deso_code, d.kommun_name, d.lan_name
            FROM deso_areas d
            WHERE ST_Contains(d.geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
            LIMIT 1
        ', [$lng, $lat]);

        $score = null;
        if ($deso) {
            $score = DB::selectOne('
                SELECT score FROM composite_scores
                WHERE deso_code = ? ORDER BY year DESC LIMIT 1
            ', [$deso->deso_code]);
        }

        $address = $this->reverseGeocode($lat, $lng);

        // Check for restored preferences from session (after OAuth redirect)
        $restoredPreferences = null;
        $sessionLat = session('purchase.lat');
        $sessionLng = session('purchase.lng');

        // Only restore if coordinates match (to avoid wrong location data)
        if (session('purchase.preferences') !== null &&
            $sessionLat !== null && $sessionLng !== null &&
            abs((float) $sessionLat - $lat) < 0.0001 &&
            abs((float) $sessionLng - $lng) < 0.0001) {
            $restoredPreferences = session('purchase.preferences');

            // Clear session data after reading to prevent stale data
            session()->forget(['purchase.preferences', 'purchase.lat', 'purchase.lng']);
        }

        // Determine urbanity tier for questionnaire
        $urbanityTier = 'semi_urban';
        if ($deso) {
            $desoArea = \App\Models\DesoArea::where('deso_code', $deso->deso_code)->first();
            $urbanityTier = $desoArea?->urbanity_tier ?? 'semi_urban';
        }

        return Inertia::render('purchase/flow', [
            'lat' => $lat,
            'lng' => $lng,
            'address' => $address,
            'kommun_name' => $deso->kommun_name ?? null,
            'lan_name' => $deso->lan_name ?? null,
            'deso_code' => $deso->deso_code ?? null,
            'score' => $score->score ?? null,
            'stripe_key' => config('stripe.key'),
            'restored_preferences' => $restoredPreferences,
            'urbanity_tier' => $urbanityTier,
            'questionnaire_config' => [
                'priority_options' => collect(config('questionnaire.priorities'))->map(fn ($p, $key) => [
                    'key' => $key,
                    'label_sv' => $p['label_sv'],
                    'icon' => $p['icon'],
                ])->values()->toArray(),
                'max_priorities' => config('questionnaire.max_priorities'),
                'walking_distances' => config('questionnaire.walking_distances'),
                'default_walking_distance' => config('questionnaire.default_walking_distance'),
                'labels' => config('questionnaire.labels'),
            ],
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $rules = [
            'lat' => 'required|numeric|min:55|max:69',
            'lng' => 'required|numeric|min:11|max:25',
            'address' => 'nullable|string|max:500',
            'deso_code' => 'nullable|string|max:10',
            'kommun_name' => 'nullable|string|max:100',
            'lan_name' => 'nullable|string|max:100',
            'score' => 'nullable|numeric',
            'email' => 'nullable|email',
            'preferences' => 'nullable|array',
            'preferences.priorities' => 'nullable|array',
            'preferences.priorities.*' => 'string',
            'preferences.walking_distance_minutes' => 'nullable|integer|min:1|max:60',
            'preferences.has_car' => 'nullable|boolean',
        ];

        if (! auth()->check()) {
            $rules['email'] = 'required|email';
        }

        $validated = $request->validate($rules);

        $email = auth()->check()
            ? auth()->user()->email
            : $validated['email'];

        // Create report in pending state
        $report = Report::create([
            'uuid' => Str::uuid(),
            'user_id' => auth()->id(),
            'guest_email' => auth()->check() ? null : $email,
            'lat' => $validated['lat'],
            'lng' => $validated['lng'],
            'address' => $validated['address'] ?? null,
            'kommun_name' => $validated['kommun_name'] ?? null,
            'lan_name' => $validated['lan_name'] ?? null,
            'deso_code' => $validated['deso_code'] ?? null,
            'score' => $validated['score'] ?? null,
            'preferences' => $validated['preferences'] ?? null,
            'amount_ore' => 7900,
            'status' => 'pending',
        ]);

        // Dev bypass — skip Stripe when no secret configured
        if (app()->environment('local') && ! config('stripe.secret')) {
            $report->update(['status' => 'completed']);
            $this->generator->generate($report);

            return response()->json([
                'checkout_url' => "/reports/{$report->uuid}",
                'dev_mode' => true,
            ]);
        }

        \Stripe\Stripe::setApiKey(config('stripe.secret'));

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'customer_email' => $email,
            'line_items' => [[
                'price' => config('stripe.price_id'),
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => route('purchase.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('purchase.cancel').'?session_id={CHECKOUT_SESSION_ID}',
            'metadata' => [
                'lat' => $validated['lat'],
                'lng' => $validated['lng'],
                'deso_code' => $validated['deso_code'],
                'user_id' => auth()->id(),
            ],
            'locale' => 'sv',
            'expires_at' => now()->addMinutes(30)->timestamp,
        ]);

        $report->update(['stripe_session_id' => $session->id]);

        return response()->json([
            'checkout_url' => $session->url,
        ]);
    }

    public function success(Request $request): mixed
    {
        $sessionId = $request->query('session_id');
        if (! $sessionId) {
            return redirect('/');
        }

        $report = Report::where('stripe_session_id', $sessionId)->first();
        if (! $report) {
            return redirect('/');
        }

        if ($report->status === 'completed') {
            return redirect("/reports/{$report->uuid}");
        }

        return Inertia::render('purchase/processing', [
            'session_id' => $sessionId,
            'report_uuid' => $report->uuid,
            'address' => $report->address,
            'lat' => (float) $report->lat,
            'lng' => (float) $report->lng,
        ]);
    }

    public function cancel(Request $request): mixed
    {
        $sessionId = $request->query('session_id');

        if ($sessionId) {
            Report::where('stripe_session_id', $sessionId)
                ->where('status', 'pending')
                ->update(['status' => 'expired']);
        }

        $report = $sessionId ? Report::where('stripe_session_id', $sessionId)->first() : null;
        if ($report) {
            return redirect("/explore/{$report->lat},{$report->lng}");
        }

        return redirect('/');
    }

    public function status(string $sessionId): JsonResponse
    {
        $report = Report::where('stripe_session_id', $sessionId)->first();

        if (! $report) {
            return response()->json(['status' => 'unknown'], 404);
        }

        // Fallback: if webhook hasn't arrived yet, check Stripe directly
        if ($report->status === 'pending' && config('stripe.secret')) {
            $this->checkStripeSession($report);
            $report->refresh();
        }

        return response()->json([
            'status' => $report->status,
            'report_uuid' => $report->uuid,
        ]);
    }

    private function checkStripeSession(Report $report): void
    {
        try {
            \Stripe\Stripe::setApiKey(config('stripe.secret'));
            $session = \Stripe\Checkout\Session::retrieve($report->stripe_session_id);

            if ($session->payment_status === 'paid' && $report->status === 'pending') {
                $report->update([
                    'status' => 'completed',
                    'stripe_payment_intent_id' => $session->payment_intent,
                ]);

                // Generate the full report snapshot if not already done
                if (! $report->area_indicators) {
                    $this->generator->generate($report);
                }

                $email = $report->guest_email ?? $report->user?->email;
                if ($email) {
                    \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\ReportReady($report));
                }
            }
        } catch (\Exception) {
            // Stripe check failed — keep polling, webhook may still arrive
        }
    }

    private function reverseGeocode(float $lat, float $lng): ?string
    {
        try {
            $response = Http::timeout(3)->get('https://photon.komoot.io/reverse', [
                'lat' => $lat,
                'lon' => $lng,
            ]);
            $props = $response->json('features.0.properties');
            if (! $props) {
                return null;
            }

            return collect([$props['street'] ?? null, $props['housenumber'] ?? null, $props['city'] ?? null])
                ->filter()->implode(', ') ?: null;
        } catch (\Exception) {
            return null;
        }
    }
}
