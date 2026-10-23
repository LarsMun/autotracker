<?php

namespace App\Http\Middleware;

use App\Monitoring\Monitor;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'flash' => fn () => $request->session()->get('flash'),
            // On every page, so a problem is never missed.
            'problems' => fn () => $request->user() ? app(Monitor::class)->problems() : [],
            'user' => fn () => $request->user()?->only('name'),
        ];
    }
}
