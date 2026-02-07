<?php

namespace App\Http\Middleware;

use App\Services\DataTieringService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'is_admin' => $request->user()->is_admin,
                    'email_verified_at' => $request->user()->email_verified_at,
                    'two_factor_enabled' => ! is_null($request->user()->two_factor_confirmed_at),
                ] : null,
            ],
            'tenant' => $request->user()?->tenant ? [
                'id' => $request->user()->tenant->id,
                'uuid' => $request->user()->tenant->uuid,
            ] : null,
            'viewingAs' => fn () => $this->resolveViewingAs($request),
            'locale' => fn () => app()->getLocale(),
            'appEnv' => config('app.env'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Resolve the "View As" tier override for admin users.
     *
     * Returns the tier value if admin is simulating a lower tier, null otherwise.
     */
    private function resolveViewingAs(Request $request): ?int
    {
        $user = $request->user();
        if (! $user?->is_admin) {
            return null;
        }

        $override = app(DataTieringService::class)->getViewAsOverride($user);

        return $override?->value;
    }
}
