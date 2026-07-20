<?php

use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);

        // Security response headers on every API response (Milestone 16).
        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        // API-only backend: never redirect an unauthenticated guest to a web
        // `login` route (there isn't one). Returning null makes Authenticate
        // throw a plain AuthenticationException, rendered as a 401 envelope.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Treat every /api/* request as JSON so an unauthenticated hit returns a
        // 401 envelope instead of trying to redirect to a (nonexistent) `login`
        // route — even when the client omits the Accept header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Render every API exception using the standard response envelope so the
        // frontend never sees stack traces (document/phase/11, phase/12 §Error Handling).
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null; // let web routes render normally
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    message: 'The given data was invalid.',
                    errors: $e->errors(),
                    status: 422,
                ),
                $e instanceof AuthenticationException => ApiResponse::error(
                    message: 'Please sign in to continue.',
                    status: 401,
                ),
                $e instanceof AuthorizationException => ApiResponse::error(
                    message: 'You are not allowed to do that.',
                    status: 403,
                ),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    message: 'We couldn\'t find what you were looking for.',
                    status: 404,
                ),
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    message: $e->getMessage() ?: 'Request could not be completed.',
                    status: $e->getStatusCode(),
                ),
                default => ApiResponse::error(
                    message: config('app.debug') ? $e->getMessage() : 'Something went wrong. Please try again.',
                    status: 500,
                ),
            };
        });
    })->create();
