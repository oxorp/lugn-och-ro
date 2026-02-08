<?php

namespace App\Http\Controllers;

use App\Mail\MyReportsAccess;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class MyReportsController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if (auth()->check()) {
            $reports = Report::where('user_id', auth()->id())
                ->whereIn('status', ['completed', 'paid'])
                ->orderByDesc('created_at')
                ->get();

            return Inertia::render('reports/my-reports', [
                'reports' => $reports->map(fn (Report $r) => $this->formatReport($r)),
                'email' => auth()->user()->email,
            ]);
        }

        if ($request->hasValidSignature() && $request->query('email')) {
            $reports = Report::where('guest_email', $request->query('email'))
                ->whereIn('status', ['completed', 'paid'])
                ->orderByDesc('created_at')
                ->get();

            return Inertia::render('reports/my-reports', [
                'reports' => $reports->map(fn (Report $r) => $this->formatReport($r)),
                'email' => $request->query('email'),
                'guest' => true,
            ]);
        }

        return Inertia::render('reports/request-access');
    }

    public function requestAccess(Request $request): RedirectResponse
    {
        $request->validate(['email' => 'required|email']);

        $url = URL::temporarySignedRoute(
            'my-reports',
            now()->addHours(24),
            ['email' => $request->email]
        );

        Mail::to($request->email)->send(new MyReportsAccess($url));

        return back()->with('status', 'sent');
    }

    /** @return array<string, mixed> */
    private function formatReport(Report $report): array
    {
        return [
            'uuid' => $report->uuid,
            'address' => $report->address,
            'kommun_name' => $report->kommun_name,
            'score' => $report->score ? (float) $report->score : null,
            'created_at' => $report->created_at->toISOString(),
        ];
    }
}
