<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Same-origin SPA uses Sanctum's cookie session (CSRF-protected); mobile apps use bearer tokens.
        $middleware->statefulApi();
        // The advert click counter only increments a number (and is rate-limited); first-time
        // visitors have no CSRF cookie yet, so it is exempt.
        $middleware->validateCsrfTokens(except: ['api/shop/ads/*/click']);
        $middleware->throttleApi('api');
        $middleware->prependToGroup('api', \App\Http\Middleware\ServerTiming::class);
        $middleware->alias(['staff' => \App\Http\Middleware\EnsureStaff::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
