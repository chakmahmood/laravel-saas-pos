<?php

use App\Http\Middleware\EnsureCurrentStore;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'current.store' => EnsureCurrentStore::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Every API error carries a stable machine-readable `code` and never
         * leaks internal details (Eloquent class names, model ids, SQL, stack
         * traces, tokens). Only JSON API requests are intercepted; non-JSON
         * requests fall back to the framework default.
         */
        $json = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($json) {
            if (! $json($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Tidak terautentikasi.',
                'code' => 'unauthenticated',
            ], 401);
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) use ($json) {
            if (! $json($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Akses ditolak.',
                'code' => 'forbidden',
            ], 403);
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) use ($json) {
            if (! $json($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Sumber daya tidak ditemukan.',
                'code' => 'not_found',
            ], 404);
        });

        $exceptions->render(function (ValidationException $exception, Request $request) use ($json) {
            if (! $json($request)) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'validation_error',
                'errors' => $exception->errors(),
            ], 422);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) use ($json) {
            if (! $json($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Terlalu banyak permintaan. Silakan coba lagi nanti.',
                'code' => 'too_many_requests',
            ], 429, $exception->getHeaders());
        });
    })->create();
