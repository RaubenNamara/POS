<?php

use Illuminate\Support\Facades\Route;

/*
 * The Vue app (storefront, /admin, /pos) is one static file built into public/spa.html.
 * Every non-API URL returns it so deep links and refreshes work; the HTML itself is never
 * cached (new deploys show up immediately) while hashed /assets/* are cached for a year.
 */
Route::fallback(function () {
    $file = public_path('spa.html');
    abort_unless(is_file($file), 503, 'Front-end not built. Run "npm run build" in /web.');

    return response()->file($file, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-cache, must-revalidate',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ]);
});
