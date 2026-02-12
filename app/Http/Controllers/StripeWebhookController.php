<?php

namespace App\Http\Controllers;

use App\Mail\ReportReady;
use App\Models\Report;
use App\Services\ReportGenerationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;

class StripeWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                config('stripe.webhook_secret')
            );
        } catch (\Exception $e) {
            return response('Invalid signature', 400);
        }

        match ($event->type) {
            'checkout.session.completed' => $this->handleCompleted($event->data->object),
            'checkout.session.expired' => $this->handleExpired($event->data->object),
            default => null,
        };

        return response('OK', 200);
    }

    private function handleCompleted(object $session): void
    {
        $report = Report::where('stripe_session_id', $session->id)->first();
        if (! $report || $report->status === 'completed') {
            return;
        }

        $report->update([
            'status' => 'completed',
            'stripe_payment_intent_id' => $session->payment_intent,
        ]);

        // Generate the full report snapshot
        app(ReportGenerationService::class)->generate($report);

        $email = $report->guest_email ?? $report->user?->email;
        if ($email) {
            Mail::to($email)->send(new ReportReady($report));
        }
    }

    private function handleExpired(object $session): void
    {
        Report::where('stripe_session_id', $session->id)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);
    }
}
