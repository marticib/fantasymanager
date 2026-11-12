<?php

use App\Http\Middleware\BumpDataVersion;
use App\Http\Middleware\CacheApiResponse;
use App\Services\FantasyApi\Exceptions\FantasyApiAuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            HandleCors::class,
        ]);
        $middleware->alias([
            'cache.api' => CacheApiResponse::class,
            'bump.data' => BumpDataVersion::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Not 401: that status means "your app login expired" to the frontend
        // and logs the user out. This is the LaLiga session, a different thing.
        $exceptions->render(fn (FantasyApiAuthenticationException $e, Request $request) => $request->is('api/*')
            ? response()->json([
                'code' => 'LALIGA_SESSION_EXPIRED',
                'message' => 'La sessió de LaLiga ha caducat. Torna a connectar el compte a Configuració.',
            ], 409)
            : null);
    })->create();
