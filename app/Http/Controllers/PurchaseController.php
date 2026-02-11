<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function show(float $lat, float $lng): Response
    {
        abort_unless($lat >= 55 && $lat <= 69 && $lng >= 11 && $lng <= 25, 404);

        $deso = DB::selectOne('
            SELECT d.deso_code, d.kommun_name, d.lan_name, d.urbanity_tier
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

        // Build questionnaire config for frontend
        $questionnaireConfig = $this->getQuestionnaireConfig();

        return Inertia::render('purchase/flow', [
            'lat' => $lat,
            'lng' => $lng,
            'address' => $address,
            'kommun_name' => $deso->kommun_name ?? null,
            'lan_name' => $deso->lan_name ?? null,
            'deso_code' => $deso->deso_code ?? null,
            'urbanity_tier' => $deso->urbanity_tier ?? 'urban',
            'score' => $score->score ?? null,
            'stripe_key' => config('stripe.key'),
            'questionnaire_config' => $questionnaireConfig,
        ]);
    }

    /**
     * Get questionnaire configuration formatted for the frontend.
     */
    private function getQuestionnaireConfig(): array
    {
        $priorities = config('questionnaire.priorities', []);

        // Format priority options for frontend (key, label_sv, icon)
        $priorityOptions = collect($priorities)->map(function ($config, $key) {
            return [
                'key' => $key,
                'label_sv' => $config['label_sv'] ?? $key,
                'icon' => $config['icon'] ?? 'circle-question',
            ];
        })->values()->all();

        return [
            'priority_options' => $priorityOptions,
            'max_priorities' => config('questionnaire.max_priorities', 3),
            'walking_distances' => config('questionnaire.walking_distances', []),
            'default_walking_distance' => config('questionnaire.default_walking_distance', 15),
            'labels' => config('questionnaire.labels', []),
        ];
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
            'amount_ore' => 7900,
            'status' => 'pending',
        ]);

        // Dev bypass — skip Stripe when no secret configured
        if (app()->environment('local') && ! config('stripe.secret')) {
            $report->update(['status' => 'completed']);

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
